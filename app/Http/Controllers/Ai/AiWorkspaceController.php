<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\WorkflowTaskStateService;
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
        $isReadOnly = $this->featureGate->isReadOnly($context->companyId);
        $approvedModel = $isAiEnabled ? $this->modelGateway->resolveApprovedModel() : null;
        $isMultimodalEnabled = $isAiEnabled && $this->featureGate->isMultimodalEnabledForCompany($context->companyId);

        // Contextual proposal editing must never trust a thread/task ID from
        // query parameters without verifying the active actor, company and FY.
        $editContext = null;
        if (config('erp_ai.conversation_enabled', false)
            && $request->filled('edit_thread') && $request->filled('edit_task')) {
            $taskId = (string) $request->query('edit_task');
            $threadId = (string) $request->query('edit_thread');
            if (!\Illuminate\Support\Str::isUuid($taskId) || !\Illuminate\Support\Str::isUuid($threadId)) abort(404);
            $task = app(WorkflowTaskStateService::class)->getTask($principal, $taskId);
            if ($task['thread_id'] !== $threadId
                || !in_array($task['action_key'], ['purchase_order.create.v1', 'purchase_bill.create.v1'], true)
                || $task['expires_at'] <= now('UTC')->toDateTimeString()
                || !$task['linked_proposal_id']) abort(404);
            $ownedThread = \Illuminate\Support\Facades\DB::table('ai_thread_scopes')
                ->where('id', $threadId)->where('user_id', $principal->actorId)
                ->where('company_id', $principal->companyId)->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)->exists();
            if (!$ownedThread) abort(404);
            $proposal = app(ActionProposalService::class)->getOwned($principal, $task['linked_proposal_id']);
            if (!in_array($proposal['status'], ['pending', 'approved', 'editing'], true)
                || $proposal['action_key'] !== $task['action_key']) abort(404);
            $editContext = [
                'thread_id' => $threadId, 'task_id' => $taskId,
                'task_revision' => (int) $task['revision'],
                'action_key' => $task['action_key'],
                'proposal_id' => $proposal['id'], 'version' => (int) $proposal['version'],
                'preview' => json_decode((string) $proposal['preview_json'], true),
            ];
        }

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
            'streamingEnabled' => (bool) config('erp_ai.streaming_enabled', false),
            'purchaseEditContext' => $editContext,
            'isAdmin' => in_array((int) ($user->role_id ?? 0), [1, 2], true),
            'model' => $approvedModel['model'] ?? null,
            'provider' => $approvedModel['provider'] ?? null,
        ]);
    }
}
