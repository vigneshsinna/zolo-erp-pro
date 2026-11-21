<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Read-only, tenant-authorized metadata aggregation. No prompt text or raw invoice data. */
final class BusinessWorkflowMetrics
{
    public function __construct(private readonly ActionSecurity $security) {}

    public function summary(AiPrincipal $principal): array
    {
        if (!config('ai_business_knowledge.enabled', false)
            || !config('ai_business_knowledge.planner_enabled', false)) {
            throw new AuthorizationException('Business workflow metrics are disabled.');
        }
        $this->security->assertEnabled($principal);
        $this->security->context($principal);
        $base = DB::table('ai_business_workflows')->where([
            'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId,
            'actor_id' => $principal->actorId,
        ]);
        $rows = (clone $base)->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status')->all();
        $stepRows = DB::table('ai_business_workflow_steps as s')
            ->join('ai_business_workflows as w', 's.workflow_id', '=', 'w.id')
            ->where('w.company_id', $principal->companyId)
            ->where('w.branch_id', $principal->branchId)
            ->where('w.financial_year_id', $principal->financialYearId)
            ->where('w.actor_id', $principal->actorId)
            ->selectRaw('s.status, COUNT(*) as total')->groupBy('s.status')
            ->pluck('total', 's.status')->all();
        $executionsBase = DB::table('ai_action_executions as e')
            ->join('ai_action_proposals as p', 'p.id', '=', 'e.proposal_id')
            ->where('p.company_id', $principal->companyId)
            ->where('p.branch_id', $principal->branchId)
            ->where('p.financial_year_id', $principal->financialYearId)
            ->where('p.actor_id', $principal->actorId);

        $completedActions = (int) (clone $executionsBase)->where('e.status', 'completed')->count();
        $failedActions = (int) (clone $executionsBase)->where('e.status', 'failed')->count();

        $rejectedProposals = (int) DB::table('ai_action_proposals')
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('actor_id', $principal->actorId)
            ->where('status', 'rejected')->count();

        $exceptionCategories = (clone $executionsBase)->where('e.status', 'failed')
            ->selectRaw('COALESCE(e.domain_service, "general") as category, COUNT(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category')->all();

        $avgLatencyMs = (float) ((clone $executionsBase)->where('e.status', 'completed')
            ->whereNotNull('e.duration_ms')->avg('e.duration_ms') ?? 0.0);
        $maxLatencyMs = (int) ((clone $executionsBase)->where('e.status', 'completed')
            ->whereNotNull('e.duration_ms')->max('e.duration_ms') ?? 0);

        $reconciledSteps = (int) ($stepRows['reconciled'] ?? 0) + (int) ($stepRows['failed'] ?? 0);
        $completedSteps = (int) ($stepRows['completed'] ?? 0);
        $totalEvaluatedSteps = $reconciledSteps + $completedSteps;
        $recoveryRate = $totalEvaluatedSteps > 0
            ? round(($completedSteps / $totalEvaluatedSteps) * 100, 2)
            : 100.0;

        // Authoritative Model Telemetry & Centralized Credit Ledger Joins
        $hasModelInvocations = \Illuminate\Support\Facades\Schema::hasTable('ai_model_invocations');
        $hasCreditLedger = \Illuminate\Support\Facades\Schema::hasTable('ai_credit_ledger');

        $modelTokens = 0;
        $modelInvocationsCount = 0;
        if ($hasModelInvocations) {
            $invocations = DB::table('ai_model_invocations')
                ->where('company_id', $principal->companyId);
            $modelTokens = (int) ($invocations->sum(DB::raw('COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)')) ?? 0);
            $modelInvocationsCount = (int) $invocations->count();
        }

        $creditsDebited = 0;
        $costMinorInr = 0;
        if ($hasCreditLedger) {
            $ledgerDebits = DB::table('ai_credit_ledger')
                ->where('company_id', $principal->companyId)
                ->where('actor_id', $principal->actorId)
                ->where('kind', 'usage_debit');
            $creditsDebited = abs((int) ($ledgerDebits->sum('amount_credits') ?? 0));
            $costMinorInr = (int) ($ledgerDebits->sum('cost_minor_inr') ?? 0);
        }

        $perStepCostCredits = $completedSteps > 0 ? round($creditsDebited / $completedSteps, 4) : 0.0;

        return [
            'scope' => 'current_actor_company_branch_fy',
            'workflow_status_counts' => array_map('intval', $rows),
            'step_status_counts' => array_map('intval', $stepRows),
            'completed_erp_actions' => $completedActions,
            'failed_erp_actions' => $failedActions,
            'rejected_proposals' => $rejectedProposals,
            'exception_categories' => array_map('intval', $exceptionCategories),
            'recovery_rate' => $recoveryRate,
            'execution_latency_ms' => [
                'avg_duration_ms' => round($avgLatencyMs, 2),
                'max_duration_ms' => $maxLatencyMs,
            ],
            'model_tokens' => $modelTokens,
            'model_credits' => $creditsDebited,
            'model_cost_minor_inr' => $costMinorInr,
            'model_invocations_count' => $modelInvocationsCount,
            'per_step_cost_credits' => $perStepCostCredits,
            'deterministic_actions_model_usage' => 0,
            'deterministic_actions_credits' => 0,
            'model_usage_source' => 'authoritative ai_credit_ledger and ai_model_invocations; zero cost for deterministic steps',
            'as_of' => now('UTC')->toIso8601String(),
        ];
    }
}
