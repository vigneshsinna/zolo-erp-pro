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
        return [
            'scope' => 'current_actor_company_branch_fy',
            'workflow_status_counts' => array_map('intval', $rows),
            'step_status_counts' => array_map('intval', $stepRows),
            'completed_erp_actions' => (int) DB::table('ai_action_executions as e')
                ->join('ai_action_proposals as p', 'p.id', '=', 'e.proposal_id')
                ->where('p.company_id', $principal->companyId)
                ->where('p.branch_id', $principal->branchId)
                ->where('p.financial_year_id', $principal->financialYearId)
                ->where('p.actor_id', $principal->actorId)
                ->where('e.status', 'completed')->count(),
            'model_tokens' => null, 'model_credits' => null,
            'model_usage_source' => 'existing AI credit metering and provider telemetry; never estimated from plan count',
            'as_of' => now('UTC')->toIso8601String(),
        ];
    }
}
