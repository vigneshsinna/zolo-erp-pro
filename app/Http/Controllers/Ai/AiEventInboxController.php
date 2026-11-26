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

final class AiEventInboxController extends Controller
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
        $enabled = (bool) config('ai_events.internal_schedule_ingestion_enabled', false)
            && $featureGate->isEnabledForCompany($context->companyId);
        $canApprove = $permissions->allows('ai.action.approve', $context, $actorId);
        $events = collect();
        if ($enabled) {
            $query = DB::table('ai_event_inbox')->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId);
            if (!$canApprove) $query->where('actor_id', $actorId);
            $events = $query->orderByDesc('occurred_at')->limit(100)->get();
        }
        return view('backend.ai.events', [
            'enabled' => $enabled, 'events' => $events, 'canApprove' => $canApprove,
        ]);
    }
}
