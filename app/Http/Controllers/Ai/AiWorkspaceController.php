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
        $isMultimodalEnabled = $isAiEnabled && $this->featureGate->isMultimodalEnabledForCompany($context->companyId);

        return view('backend.ai.workspace', [
            'company' => $company,
            'context' => $context,
            'isAiEnabled' => $isAiEnabled,
            'isMultimodalEnabled' => $isMultimodalEnabled,
            'multimodalLimits' => [
                'max_image_bytes' => (int) config('ai_multimodal.max_input_bytes', 10_485_760),
                'max_pdf_bytes' => (int) config('ai_multimodal.max_pdf_bytes', 20_971_520),
                'max_pdf_pages' => (int) config('ai_multimodal.max_pdf_pages', 5),
                'max_audio_bytes' => (int) config('ai_multimodal.max_audio_bytes', 20_971_520),
                'max_audio_seconds' => (int) config('ai_multimodal.max_audio_seconds', 120),
            ],
            'canUseCopilot' => $isAiEnabled && $principal->permittedToolNames !== [],
            'isReadOnly' => $isReadOnly,
            'isAdmin' => in_array((int) ($user->role_id ?? 0), [1, 2], true),
            'model' => $isAiEnabled ? $this->modelGateway->resolveApprovedModel()['model'] : null,
        ]);
    }
}
