<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\AiPurchaseProposalEditService;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AiPurchaseCopilotEditorController extends Controller
{
    public function open(
        Request $request,
        string $id,
        CompanyContextResolver $resolver,
        AiPrincipalFactory $factory,
        AiPurchaseProposalEditService $service,
    ): RedirectResponse {
        abort_unless(Str::isUuid($id), 404);
        $actorId = (int) $request->user()->id;
        $scope = $resolver->forActor(userId: $actorId);
        $principal = $factory->createForUser(
            $actorId, $scope->companyId, $scope->branchId, $scope->financialYearId,
        );
        $link = $service->open($principal, $id);
        return redirect()->route('ai.workspace', [
            'edit_thread' => $link['thread_id'], 'edit_task' => $link['task_id'],
        ]);
    }
}
