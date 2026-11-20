<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AiFeatureGate;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Read-only, tenant-scoped home for the existing governed AI workflows.
 * It does not approve, schedule, or execute any action.
 */
final class AiOperationsController extends Controller
{
    public function index(
        Request $request,
        CompanyContextResolver $resolver,
        CommercialPermission $permissions,
        AiFeatureGate $featureGate,
    ): View {
        $actorId = (int) $request->user()->id;
        $context = $resolver->forActor(userId: $actorId);
        $permissions->assert('ai.use', $context, $actorId);

        $companyId = $context->companyId;
        $enabled = $featureGate->isEnabledForCompany($companyId);
        $foundation = $enabled && (bool) config('ai_actions.foundation_enabled', false);
        $scheduling = $enabled && (bool) config('ai_infrastructure.scheduling.enabled', false);
        $canApprove = $permissions->allows('ai.action.approve', $context, $actorId);
        $summary = ['pending' => 0, 'completed' => 0, 'attention' => 0];
        $recent = collect();
        $rules = collect();

        if ($foundation && Schema::hasTable('ai_action_proposals')) {
            $base = DB::table('ai_action_proposals')
                ->where('company_id', $companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId);
            if (!$canApprove) {
                $base->where('actor_id', $actorId);
            }
            $totals = (clone $base)->selectRaw(
                "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                 SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                 SUM(CASE WHEN status = 'requires_reconciliation' THEN 1 ELSE 0 END) AS reconciliation_count"
            )->first();
            $summary['pending'] = (int) ($totals->pending_count ?? 0);
            $summary['completed'] = (int) ($totals->completed_count ?? 0);
            $summary['attention'] = (int) ($totals->reconciliation_count ?? 0);
            $recent = (clone $base)
                ->whereIn('status', ['pending', 'approved', 'completed', 'requires_reconciliation'])
                ->select(['id', 'action_key', 'status', 'risk_tier', 'updated_at'])
                ->orderByDesc('updated_at')->limit(8)->get();
        }

        if ($scheduling && Schema::hasTable('ai_scheduled_rules')) {
            // Schedules are owned by an actor, even for a company approver.
            $rules = DB::table('ai_scheduled_rules')
                ->where('company_id', $companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->where('actor_id', $actorId)
                ->where('is_active', true)->where('expires_at', '>', now('UTC'))
                ->select(['id', 'name', 'next_run_at', 'prepare_purchase_order'])
                ->orderBy('next_run_at')->limit(5)->get();
        }

        return view('backend.ai.operations', [
            'enabled' => $enabled,
            'foundation' => $foundation,
            'scheduling' => $scheduling,
            'canApprove' => $canApprove,
            'summary' => $summary,
            'recent' => $recent,
            'rules' => $rules,
            'context' => $context,
        ]);
    }
}
