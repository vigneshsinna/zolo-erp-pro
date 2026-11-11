<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AiFeatureGate;
use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use App\Models\User;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Enterprise Action Review Center & Operational Approval Desk.
 * Allows authorized managers and operators to inspect, review, approve,
 * or reject AI-generated operational proposals with full cryptographic provenance.
 */
final class ActionReviewCenterController extends Controller
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

        $enabled = (bool) config('ai_actions.foundation_enabled', false)
            && $featureGate->isEnabledForCompany($context->companyId);

        $canApprove = $permissions->allows('ai.action.approve', $context, $actorId)
            || $permissions->allows('purchases-add', $context, $actorId)
            || (int) $request->user()->role_id === 1;

        $scope = $canApprove ? (string) $request->query('scope', 'all') : 'mine';
        $statusFilter = (string) $request->query('status', 'all');

        $proposals = collect();
        $counts = (object) [
            'total' => 0,
            'pending_count' => 0,
            'completed_count' => 0,
            'cancelled_count' => 0,
        ];
        $currencySymbol = '₹';

        if ($enabled) {
            $currency = GeneralSetting::first()?->currency;
            if ($currency) {
                $currencySymbol = $currency;
            }

            $baseQuery = DB::table('ai_action_proposals')
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->whereNotNull('action_key')
                ->whereNotNull('payload_hash');

            if ($scope === 'mine') {
                $baseQuery->where('actor_id', $actorId);
            }

            $countsRaw = (clone $baseQuery)->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
            ")->first();

            if ($countsRaw) {
                $counts = (object) [
                    'total' => (int) ($countsRaw->total ?? 0),
                    'pending_count' => (int) ($countsRaw->pending_count ?? 0),
                    'completed_count' => (int) ($countsRaw->completed_count ?? 0),
                    'cancelled_count' => (int) ($countsRaw->cancelled_count ?? 0),
                ];
            }

            $query = (clone $baseQuery);
            if (in_array($statusFilter, ['pending', 'completed', 'cancelled'], true)) {
                $query->where('status', $statusFilter);
            }

            $rawProposals = $query->orderByDesc('updated_at')
                ->limit(50)
                ->get();

            $actorIds = $rawProposals->pluck('actor_id')->unique()->all();
            $actors = User::whereIn('id', $actorIds)->pluck('name', 'id');

            $proposalIds = $rawProposals->pluck('id')->all();
            $executions = DB::table('ai_action_executions')
                ->whereIn('proposal_id', $proposalIds)
                ->where('company_id', $context->companyId)
                ->get()
                ->keyBy('proposal_id');

            $proposals = $rawProposals->map(function ($row) use ($actors, $executions) {
                $preview = json_decode((string) ($row->preview_json ?? '{}'), true) ?? [];
                $execution = $executions[$row->id] ?? null;
                $receipt = $execution ? json_decode((string) ($execution->execution_receipt_json ?? '{}'), true) : [];

                return (object) [
                    'id' => $row->id,
                    'actor_id' => $row->actor_id,
                    'actor_name' => $actors[$row->actor_id] ?? 'User #' . $row->actor_id,
                    'action_key' => $row->action_key,
                    'action_title' => $row->action_title,
                    'risk_tier' => $row->risk_tier,
                    'version' => (int) $row->version,
                    'status' => $row->status,
                    'payload_hash' => $row->payload_hash,
                    'source_state_hash' => $row->source_state_hash,
                    'preview' => $preview,
                    'supplier' => $preview['supplier'] ?? null,
                    'warehouse' => $preview['warehouse'] ?? null,
                    'date' => $preview['date'] ?? null,
                    'items' => $preview['items'] ?? [],
                    'totals' => $preview['totals'] ?? [],
                    'tax_breakdown' => $preview['tax_breakdown'] ?? [],
                    'accounting_effects' => $preview['accounting_effects'] ?? [],
                    'warnings' => $preview['warnings'] ?? [],
                    'execution' => $execution,
                    'receipt' => $receipt,
                    'reference_no' => $receipt['details']['reference_no'] ?? null,
                    'purchase_id' => $receipt['details']['purchase_id'] ?? null,
                    'expires_at' => $row->expires_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            });
        }

        return view('backend.ai.action_review_center', [
            'enabled' => $enabled,
            'canApprove' => $canApprove,
            'scope' => $scope,
            'statusFilter' => $statusFilter,
            'counts' => $counts,
            'proposals' => $proposals,
            'currencySymbol' => $currencySymbol,
            'companyId' => $context->companyId,
            'branchId' => $context->branchId,
            'financialYearId' => $context->financialYearId,
        ]);
    }
}
