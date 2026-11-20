<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Registries\ActionCapabilityRegistry;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores administrator preferences only. It never approves or executes proposals.
 * The effective execution policy remains human-approval-only until a separate gate ships.
 */
final class AutonomyPolicyService
{
    public function __construct(
        private readonly CompanyContextResolver $resolver,
        private readonly CommercialPermission $permissions,
        private readonly AiFeatureGate $featureGate,
        private readonly ActionCapabilityRegistry $registry,
    ) {}

    public function assertAdministrator(CompanyContext $context, int $actorId): void
    {
        $this->resolver->forActor($context, $actorId);
        $this->permissions->assert('ai.use', $context, $actorId);
        $this->permissions->assert('ai.action.approve', $context, $actorId);

        if (!$this->resolver->canManageFinancialYears($actorId, $context->companyId)
            || !config('ai_autonomy.policy_management_enabled', false)
            || !config('ai_actions.foundation_enabled', false)
            || !$this->featureGate->isEnabledForCompany($context->companyId)) {
            throw new AuthorizationException('AI autonomy policy administration is unavailable.');
        }
    }

    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->registry->all() as $definition) {
            $definitions[] = [
                'key' => $definition->key,
                'risk_tier' => $definition->riskTier,
                'levels' => $this->allowedLevels($definition->riskTier),
            ];
        }
        usort($definitions, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        return $definitions;
    }

    public function stored(int $companyId): array
    {
        return DB::table('ai_autonomy_policies')->where('company_id', $companyId)
            ->get()->keyBy('action_key')->map(static fn ($value) => (array) $value)->all();
    }

    /**
     * Separate requested policy from effective authorization.
     * No caller can interpret a policy row as a signed human approval.
     */
    public function evaluate(CompanyContext $context, string $actionKey): array
    {
        $definition = $this->registry->get($actionKey);
        $stored = DB::table('ai_autonomy_policies')->where('company_id', $context->companyId)
            ->where('action_key', $actionKey)->first();
        return [
            'action_key' => $actionKey,
            'risk_tier' => $definition->riskTier,
            'requested_level' => $stored?->requested_level ?? 'draft',
            'effective_execution_authorization' => 'signed_human_approval',
            'automatic_execution_allowed' => false,
            'policy_version' => $stored?->version ?? 0,
            'reason' => 'Autonomous ERP posting is not enabled by the policy foundation.',
        ];
    }

    public function save(CompanyContext $context, int $actorId, string $actionKey, array $input): array
    {
        $this->assertAdministrator($context, $actorId);
        if (!$this->registry->has($actionKey)) {
            throw ValidationException::withMessages(['action' => 'Action is not registered.']);
        }
        $definition = $this->registry->get($actionKey);
        $allowed = $this->allowedLevels($definition->riskTier);
        $level = (string) ($input['requested_level'] ?? '');
        if (!in_array($level, $allowed, true)) {
            throw ValidationException::withMessages(['requested_level' => 'This autonomy level is not permitted for the selected risk tier.']);
        }

        $rupees = filter_var($input['max_amount_rupees'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000000]]);
        $daily = filter_var($input['daily_action_limit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $expected = filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($rupees === false || $daily === false || $expected === false) {
            throw ValidationException::withMessages(['policy' => 'A valid whole-rupee cap, daily limit and policy version are required.']);
        }
        if ($level === 'auto_under_limits' && $rupees < 1) {
            throw ValidationException::withMessages(['max_amount_rupees' => 'Set a positive amount cap for a requested automatic level.']);
        }

        return DB::transaction(function () use ($context, $actorId, $actionKey, $level, $rupees, $daily, $expected): array {
            // Serialize policy updates for a company, including competing initial inserts.
            $company = DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->first();
            if (!$company || $company->status !== 'active') {
                throw new AuthorizationException('Company is not active.');
            }
            $prior = DB::table('ai_autonomy_policies')->where('company_id', $context->companyId)
                ->where('action_key', $actionKey)->lockForUpdate()->first();
            if (($prior ? (int) $prior->version : 0) !== $expected) {
                throw ValidationException::withMessages(['expected_version' => 'Policy changed; reload the latest version.']);
            }
            $next = [
                'requested_level' => $level,
                'max_amount_minor' => $rupees * 100,
                'daily_action_limit' => $daily,
                'version' => $expected + 1,
            ];
            if ($prior) {
                DB::table('ai_autonomy_policies')->where('id', $prior->id)->update($next + [
                    'updated_by' => $actorId, 'updated_at' => now('UTC'),
                ]);
            } else {
                DB::table('ai_autonomy_policies')->insert($next + [
                    'company_id' => $context->companyId, 'action_key' => $actionKey,
                    'created_by' => $actorId, 'updated_by' => $actorId,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            $priorSnapshot = $prior ? [
                'requested_level' => $prior->requested_level,
                'max_amount_minor' => (int) $prior->max_amount_minor,
                'daily_action_limit' => (int) $prior->daily_action_limit,
                'version' => (int) $prior->version,
            ] : null;
            $event = [$context->companyId, $actionKey, $actorId, $expected + 1, $priorSnapshot, $next];
            DB::table('ai_autonomy_policy_events')->insert([
                'company_id' => $context->companyId,
                'action_key' => $actionKey,
                'policy_version' => $expected + 1,
                'actor_id' => $actorId,
                'previous_json' => $priorSnapshot === null ? null : json_encode($priorSnapshot, JSON_THROW_ON_ERROR),
                'current_json' => json_encode($next, JSON_THROW_ON_ERROR),
                'event_hash' => hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)),
                'created_at' => now('UTC'),
            ]);
            return $next + ['action_key' => $actionKey, 'automatic_execution_allowed' => false];
        });
    }

    private function allowedLevels(string $riskTier): array
    {
        // These are stored preferences, not executable permissions.
        // Financial/restricted actions cannot request autonomous posting.
        return match ($riskTier) {
            'r1_draft' => ['suggest', 'draft', 'auto_under_limits', 'fully_auto'],
            'r2_operational' => ['suggest', 'draft', 'auto_under_limits'],
            default => ['suggest', 'draft'],
        };
    }
}
