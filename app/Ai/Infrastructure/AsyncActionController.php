<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiPrincipalFactory;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AsyncActionController
{
    public function execute(Request $request, string $id, AsyncActionDispatch $dispatch): JsonResponse
    {
        $input = $request->validate(['version' => 'required|integer|min:1']);
        return response()->json($dispatch->dispatch($this->principal($request), $id, $input['version']), 202);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $principal = $this->principal($request);
        $proposal = app(ActionProposalService::class)->getOwned($principal, $id);
        $execution = DB::table('ai_action_executions')->where('company_id', $principal->companyId)
            ->where('proposal_id', $id)->where('version', $proposal['version'])->first();
        return response()->json(['proposal_id' => $id, 'version' => (int) $proposal['version'],
            'status' => $execution?->status ?? $proposal['status'],
            'receipt' => $execution?->status === 'completed' ? json_decode($execution->result_json, true, flags: JSON_THROW_ON_ERROR) : null]);
    }

    private function principal(Request $request): \App\Ai\DTOs\AiPrincipal
    {
        $context = $request->attributes->get(CompanyContext::class);
        abort_unless($context && $request->user(), 403);
        return app(AiPrincipalFactory::class)->createForUser($request->user()->id, $context->companyId, $context->branchId, $context->financialYearId);
    }
}
