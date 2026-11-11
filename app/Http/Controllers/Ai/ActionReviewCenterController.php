<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AiFeatureGate;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Initial read-only action review desk. Does not approve, execute, or expose
 * tenant data until the Action Foundation is explicitly enabled.
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

        $proposals = collect();
        if ($enabled) {
            $proposals = DB::table('ai_action_proposals')
                ->where('actor_id', $actorId)
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->whereNotNull('action_key')
                ->whereNotNull('payload_hash')
                ->orderByDesc('updated_at')
                ->limit(40)
                ->get(['id', 'action_key', 'action_title', 'risk_tier', 'version', 'status', 'created_at', 'updated_at', 'expires_at']);
        }

        return view('backend.ai.action_review_center', [
            'enabled' => $enabled,
            'proposals' => $proposals,
            'companyId' => $context->companyId,
            'branchId' => $context->branchId,
            'financialYearId' => $context->financialYearId,
        ]);
    }
}
