<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ActionPreviewResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create/version reviewed proposals. Never executes an ERP business command.
 * Company/branch/FY are server-generated; LLM/user inputs are schema-validated.
 */
final class ActionProposalService
{
    public function __construct(private readonly ActionSecurity $security) {}

    public function create(AiPrincipal $principal, string $key, array $slots, ?string $threadId = null): array
    {
        $definition = $this->security->definition($key, $principal);
        $validated = $this->security->validate($definition, $slots);
        $preview = $this->preview($definition, $validated, $this->security->context($principal));
        $now = now('UTC');

        $count = DB::table('ai_action_proposals')
            ->where('actor_id', $principal->actorId)
            ->where('company_id', $principal->companyId)
            ->whereIn('status', ['pending', 'approved', 'executing'])
            ->count();
        if ($count >= (int) config('ai_actions.max_pending_per_actor', 25)) {
            throw ValidationException::withMessages(['action' => 'Too many pending proposals.']);
        }

        $id = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $id,
            'actor_id' => $principal->actorId,
            'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId,
            'thread_id' => $threadId,
            'action_type' => $definition->key,
            'action_key' => $definition->key,
            'action_title' => $definition->businessModule,
            'risk_tier' => $definition->riskTier,
            'version' => 1,
            'payload_json' => CanonicalActionPayload::json($preview->payload),
            'preview_json' => CanonicalActionPayload::json($preview->preview),
            'payload_hash' => CanonicalActionPayload::hash($preview->payload),
            'source_state_hash' => $preview->sourceStateHash,
            'status' => 'pending',
            'expires_at' => $now->copy()->addMinutes((int) config('ai_actions.proposal_ttl_minutes', 30)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->getOwned($principal, $id);
    }

    /**
     * A change never mutates an approved command. It creates a newer version,
     * invalidates the old approval and requires a new preview and approval.
     */
    public function applyChangeRequest(
        AiPrincipal $principal,
        string $id,
        array $changes,
        int $expectedVersion,
    ): array {
        $old = $this->getOwned($principal, $id);
        if ((int) $old['version'] !== $expectedVersion || !in_array($old['status'], ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['proposal' => 'The proposal changed or cannot be edited.']);
        }

        $definition = $this->security->definition($old['action_key'], $principal);
        $base = json_decode($old['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $validated = $this->security->validate($definition, array_replace($base, $changes));
        $preview = $this->preview($definition, $validated, $this->security->context($principal));
        $updated = DB::table('ai_action_proposals')
            ->where('id', $id)
            ->where('actor_id', $principal->actorId)
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('version', $expectedVersion)
            ->whereIn('status', ['pending', 'approved'])
            ->where('expires_at', '>', now('UTC'))
            ->update([
                'version' => $expectedVersion + 1,
                'payload_json' => CanonicalActionPayload::json($preview->payload),
                'preview_json' => CanonicalActionPayload::json($preview->preview),
                'payload_hash' => CanonicalActionPayload::hash($preview->payload),
                'source_state_hash' => $preview->sourceStateHash,
                'status' => 'pending',
                'expires_at' => now('UTC')->addMinutes((int) config('ai_actions.proposal_ttl_minutes', 30)),
                'updated_at' => now('UTC'),
            ]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['proposal' => 'The proposal changed; reload the latest preview.']);
        }

        return $this->getOwned($principal, $id);
    }

    public function getOwned(AiPrincipal $principal, string $id): array
    {
        $this->security->assertEnabled($principal);
        $this->security->context($principal);
        $row = DB::table('ai_action_proposals')
            ->where('id', $id)
            ->where('actor_id', $principal->actorId)
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->whereNotNull('action_key')
            ->whereNotNull('payload_hash')
            ->first();

        if (!$row) {
            throw new AuthorizationException('Action proposal not accessible.');
        }

        $this->security->definition($row->action_key, $principal);
        return (array) $row;
    }

    public function reject(AiPrincipal $principal, string $id, int $expectedVersion): void
    {
        $this->getOwned($principal, $id);
        $updated = DB::table('ai_action_proposals')
            ->where('id', $id)->where('actor_id', $principal->actorId)
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('version', $expectedVersion)
            ->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['proposal' => 'This proposal is no longer cancellable.']);
        }
    }

    private function preview($definition, array $validated, $context): ActionPreviewResult
    {
        $adapter = app($definition->previewAdapterClass);
        $result = $adapter->preview($definition, $validated, $context);
        if (!($result instanceof ActionPreviewResult)
            || !preg_match('/^[a-f0-9]{64}$/D', $result->sourceStateHash)) {
            throw new \LogicException('Action preview is missing its source-state hash.');
        }
        return $result;
    }
}
