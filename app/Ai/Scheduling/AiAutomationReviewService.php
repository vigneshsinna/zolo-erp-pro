<?php

declare(strict_types=1);

namespace App\Ai\Scheduling;

use App\Ai\Services\AiFeatureGate;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * One immutable, scoped human assessment per completed scheduled run.
 * Savings are explicitly reviewer estimates, not bookable financial gains.
 */
final class AiAutomationReviewService
{
    public function __construct(
        private readonly CompanyContextResolver $resolver,
        private readonly CommercialPermission $permissions,
        private readonly AiFeatureGate $featureGate,
    ) {}

    public function record(CompanyContext $context, int $actorId, array $input): array
    {
        $this->resolver->forActor($context, $actorId);
        $this->permissions->assert('ai.use', $context, $actorId);
        $this->permissions->assert('ai.action.approve', $context, $actorId);
        if (!$this->featureGate->isEnabledForCompany($context->companyId)
            || !config('ai_infrastructure.scheduling.enabled', false)) {
            throw new AuthorizationException('Automation reviews are unavailable.');
        }
        if (array_diff(array_keys($input), ['run_id', 'outcome', 'minutes_saved', 'hourly_rate_rupees', 'notes']) !== []) {
            throw ValidationException::withMessages(['review' => 'Unsupported review fields.']);
        }
        $data = Validator::make($input, [
            'run_id' => ['required', 'uuid'],
            'outcome' => ['required', 'in:correct,incorrect'],
            'minutes_saved' => ['required', 'integer', 'min:0', 'max:240'],
            'hourly_rate_rupees' => ['required', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $minutes = (int) $data['minutes_saved'];
        $rate = (int) $data['hourly_rate_rupees'] * 100;
        $notes = trim((string) ($data['notes'] ?? ''));
        if ($data['outcome'] === 'incorrect' && ($minutes !== 0 || $notes === '')) {
            throw ValidationException::withMessages([
                'review' => 'Incorrect outcomes require a reason and cannot claim time savings.',
            ]);
        }
        $gross = intdiv($minutes * $rate + 30, 60);
        return DB::transaction(function () use ($context, $actorId, $data, $minutes, $rate, $notes, $gross): array {
            $company = DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->first();
            if (!$company || $company->status !== 'active') {
                throw new AuthorizationException('Company unavailable.');
            }
            // Runs do not have branch/year columns; only their owning rules do.
            $run = DB::table('ai_schedule_runs as run')
                ->join('ai_scheduled_rules as rule', function ($join): void {
                    $join->on('rule.id', '=', 'run.rule_id')
                        ->on('rule.company_id', '=', 'run.company_id')
                        ->on('rule.actor_id', '=', 'run.actor_id');
                })
                ->where('run.id', $data['run_id'])
                ->where('run.company_id', $context->companyId)
                ->where('rule.branch_id', $context->branchId)
                ->where('rule.financial_year_id', $context->financialYearId)
                ->where('run.status', 'completed')->select(['run.id'])->first();
            if (!$run) {
                throw new AuthorizationException('Only completed runs in the active company, branch and FY may be reviewed.');
            }
            $existing = DB::table('ai_automation_reviews')->where('company_id', $context->companyId)
                ->where('run_id', $run->id)->first();
            if ($existing) {
                throw ValidationException::withMessages(['review' => 'This run is already reviewed. Reviews are immutable.']);
            }
            $id = (string) Str::uuid();
            DB::table('ai_automation_reviews')->insert([
                'id' => $id, 'run_id' => $run->id,
                'company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId, 'reviewer_id' => $actorId,
                'outcome' => $data['outcome'], 'minutes_saved' => $minutes,
                'hourly_rate_minor' => $rate, 'estimated_gross_savings_minor' => $gross,
                'notes' => $notes === '' ? null : $notes,
                'created_at' => now('UTC'),
            ]);
            return ['id' => $id, 'run_id' => $run->id, 'outcome' => $data['outcome'],
                'estimated_gross_savings_minor' => $gross];
        }, 3);
    }
}
