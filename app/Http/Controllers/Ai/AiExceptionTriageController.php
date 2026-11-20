<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AiExceptionClassifier;
use App\Ai\Services\AiFeatureGate;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AiExceptionTriageController extends Controller
{
    public function index(
        Request $request,
        CompanyContextResolver $resolver,
        CommercialPermission $permissions,
        AiFeatureGate $featureGate,
        AiExceptionClassifier $classifier,
    ): View {
        $actorId = (int) $request->user()->id;
        $context = $resolver->forActor(userId: $actorId);
        $permissions->assert('ai.use', $context, $actorId);
        $enabled = (bool) config('ai_actions.foundation_enabled', false)
            && $featureGate->isEnabledForCompany($context->companyId);
        $canApprove = $permissions->allows('ai.action.approve', $context, $actorId);
        $items = collect();

        if ($enabled) {
            $query = DB::table('ai_action_proposals')
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->whereNotNull('action_key')
                ->whereNotNull('payload_hash')
                ->whereIn('status', ['pending', 'requires_reconciliation']);
            if (!$canApprove) $query->where('actor_id', $actorId);

            $now = Carbon::now('UTC');
            $items = $query->orderByDesc('updated_at')->limit(100)->get()
                ->map(function (object $proposal) use ($classifier, $now): array {
                    $triage = $classifier->classify($proposal, $now);
                    return [
                        'id' => $proposal->id,
                        'key' => $proposal->action_key,
                        'status' => $proposal->status,
                        'risk' => $proposal->risk_tier,
                        'priority' => $triage['priority'],
                        'issues' => $triage['issues'],
                        'updated_at' => $proposal->updated_at,
                    ];
                });
        }

        return view('backend.ai.exceptions', [
            'enabled' => $enabled, 'canApprove' => $canApprove, 'items' => $items,
        ]);
    }
}
