<?php

declare(strict_types=1);

namespace App\Ai\Scheduling;

use App\Ai\Services\AiPrincipalFactory;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScheduledRuleController
{
    public function index(Request $request, ScheduledRuleService $service): JsonResponse
    {
        return response()->json(['rules' => $service->list($this->principal($request))]);
    }

    public function store(Request $request, ScheduledRuleService $service): JsonResponse
    {
        return response()->json(['rule' => $service->create($this->principal($request), $request->all())], 201);
    }

    public function cancel(Request $request, string $id, ScheduledRuleService $service): JsonResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:1']);
        $service->cancel($this->principal($request), $id, $input['revision']);
        return response()->json(['status' => 'cancelled']);
    }

    public function runs(Request $request, string $id, ScheduledRuleService $service): JsonResponse
    {
        return response()->json(['runs' => $service->runs($this->principal($request), $id)]);
    }

    private function principal(Request $request): \App\Ai\DTOs\AiPrincipal
    {
        $context = $request->attributes->get(CompanyContext::class);
        abort_unless($context && $request->user(), 403);
        return app(AiPrincipalFactory::class)->createForUser($request->user()->id, $context->companyId, $context->branchId, $context->financialYearId);
    }
}
