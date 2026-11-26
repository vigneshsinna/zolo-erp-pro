<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Human reversal *requests*, never stock, bill, or journal reversals.
 * Domain-owned compensating transactions will require separate explicit adapters.
 */
final class AiActionReversalRequestService
{
    public function __construct(
        private readonly CompanyContextResolver $resolver,
        private readonly CommercialPermission $permissions,
        private readonly AiFeatureGate $featureGate,
    ) {}

    public function authorize(CompanyContext $context, int $actorId): void
    {
        $this->resolver->forActor($context, $actorId);
        $this->permissions->assert('ai.use', $context, $actorId);
        $this->permissions->assert('ai.action.approve', $context, $actorId);
        if (!config('ai_actions.foundation_enabled', false)
            || !$this->featureGate->isEnabledForCompany($context->companyId)) {
            throw new AuthorizationException('AI reversal review is unavailable.');
        }
    }

    public function list(CompanyContext $context, int $actorId): array
    {
        $this->authorize($context, $actorId);
        return DB::table('ai_action_reversal_requests')
            ->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)
            ->orderByDesc('created_at')->limit(50)->get()->map(static fn ($row) => (array) $row)->all();
    }

    public function request(
        CompanyContext $context,
        int $actorId,
        string $executionId,
        string $reason,
    ): array {
        $this->authorize($context, $actorId);
        $reason = trim($reason);
        if (!Str::isUuid($executionId) || mb_strlen($reason) < 12 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reversal' => 'Provide a valid execution ID and reason (12–500 characters).']);
        }

        return DB::transaction(function () use ($context, $actorId, $executionId, $reason): array {
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->first();
            $execution = DB::table('ai_action_executions')
                ->where('id', $executionId)->where('company_id', $context->companyId)
                ->where('status', 'completed')->lockForUpdate()->first();
            if (!$execution) throw new AuthorizationException('Completed execution is not accessible.');
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $execution->proposal_id)
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->where('status', 'completed')->first();
            if (!$proposal) throw new AuthorizationException('Execution source is outside this company, branch or year.');

            $existing = DB::table('ai_action_reversal_requests')->where('company_id', $context->companyId)
                ->where('execution_id', $executionId)->first();
            if ($existing) return (array) $existing;

            $receipt = json_decode((string) $execution->result_json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($receipt) || empty($receipt['resource_type']) || empty($receipt['resource_id'])) {
                throw ValidationException::withMessages(['reversal' => 'Execution receipt needs manual reconciliation before reversal review.']);
            }
            $id = (string) Str::uuid();
            DB::table('ai_action_reversal_requests')->insert([
                'id' => $id, 'company_id' => $context->companyId,
                'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
                'execution_id' => $executionId, 'proposal_id' => $proposal->id,
                'requested_by' => $actorId, 'status' => 'pending_manual_review',
                'reason' => $reason, 'execution_receipt_hash' => CanonicalActionPayload::hash($receipt),
                'created_at' => now('UTC'),
            ]);

            return (array) DB::table('ai_action_reversal_requests')->where('id', $id)->first();
        });
    }
}
