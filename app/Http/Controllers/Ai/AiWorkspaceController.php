<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiWorkspaceController extends Controller
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected AiPrincipalFactory $principalFactory,
        protected AiFeatureGate $featureGate,
        protected ApprovedModelGateway $modelGateway,
    ) {}

    /**
     * Display the conversational AI Operating Desk.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $context = $this->contextResolver->forActor(userId: $user->id);
        $company = Company::findOrFail($context->companyId);

        $principal = $this->principalFactory->createForUser(
            userId: $user->id,
            companyId: $context->companyId,
            branchId: $context->branchId,
            financialYearId: $context->financialYearId
        );

        $isAiEnabled = $this->featureGate->isEnabledForCompany($context->companyId);
        $isReadOnly = $this->featureGate->isReadOnly();

        return view('backend.ai.workspace', [
            'company' => $company,
            'context' => $context,
            'isAiEnabled' => $isAiEnabled,
            'canUseCopilot' => $isAiEnabled && $principal->permittedToolNames !== [],
            'isReadOnly' => $isReadOnly,
            'isAdmin' => in_array((int) ($user->role_id ?? 0), [1, 2], true),
            'model' => $isAiEnabled ? $this->modelGateway->resolveApprovedModel()['model'] : null,
        ]);
    }
}
