<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\BatchRecordResolutionService;
use App\Ai\Services\ClarificationResponseBuilder;
use App\Ai\Services\InputCompletenessEvaluator;
use App\Ai\Services\WorkflowTaskStateService;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Policies\AiMediaAssetPolicy;
use App\Services\Platform\CompanyContext;

/**
 * Hands off confirmed multimodal normalized inputs into the existing Phase 2.1/2.2
 * Unified Clarification, Dependent Master Creation, and Human-Approved Proposal pipeline.
 */
final class NormalizedInputWorkflowBridge
{
    public function __construct(
        protected AiMediaAssetPolicy $policy,
        protected NormalizedInputBuilder $normalizedBuilder,
        protected ActionCapabilityRegistry $capabilityRegistry,
        protected ActionRequirementsRegistry $requirementsRegistry,
        protected WorkflowTaskStateService $taskState,
        protected BatchRecordResolutionService $batchResolver,
        protected InputCompletenessEvaluator $evaluator,
        protected ClarificationResponseBuilder $clarificationBuilder,
        protected ActionProposalService $proposalService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handoff(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        AiMediaExtraction $extraction,
        ?string $companionText = null,
        ?string $overrideActionKey = null,
        ?string $threadId = null,
        ?AiMediaAsset $companionAsset = null,
        ?AiMediaExtraction $companionExtraction = null,
    ): array {
        if (! $this->policy->canAccessPrincipal($principal, $asset)) {
            throw MultimodalException::crossTenantForbidden();
        }
        if ($companionAsset !== null && ! $this->policy->canAccessPrincipal($principal, $companionAsset)) {
            throw MultimodalException::crossTenantForbidden();
        }
        if ($asset->isPurged()) {
            throw MultimodalException::mediaNotFound();
        }
        if ($asset->isExpired()) {
            throw MultimodalException::mediaExpired();
        }
        if (! $extraction->isConfirmed()) {
            throw MultimodalException::extractionFailed(
                'EXTRACTION_CONFIRMATION_REQUIRED',
                'Review and confirm extracted fields before staging an ERP workflow proposal.'
            );
        }
        if ($companionExtraction !== null && ! $companionExtraction->isConfirmed()) {
            throw MultimodalException::extractionFailed(
                'EXTRACTION_CONFIRMATION_REQUIRED',
                'Companion media extraction must be confirmed before cross-modal handoff.'
            );
        }

        $companionExtracted = $companionExtraction !== null
            ? (array) $companionExtraction->extracted_json
            : null;

        $normalized = $this->normalizedBuilder->build(
            principal: $principal,
            asset: $asset,
            extracted: (array) $extraction->extracted_json,
            extractionId: $extraction->id,
            extractionRevision: (int) $extraction->revision,
            modelKey: (string) $extraction->model_key,
            companionText: $companionText,
            companionExtracted: $companionExtracted,
            overrideIntentKey: $overrideActionKey,
        );

        if ($normalized->conflicts !== []) {
            $firstConflict = $normalized->conflicts[0]['message'] ?? 'Conflicting values detected between document and instruction.';
            throw MultimodalException::extractionFailed('NORMALIZED_INPUT_CONFLICT', $firstConflict);
        }

        $actionKey = $normalized->intentKey;
        if ($actionKey === null || $actionKey === 'query_readonly') {
            return [
                'status' => 'readonly_summary',
                'media_id' => $asset->id,
                'extraction_id' => $extraction->id,
                'normalized_input' => $normalized->toArray(),
                'message' => 'Extraction confirmed for read-only analysis; no commercial workflow task was staged.',
            ];
        }

        if (! $this->capabilityRegistry->has($actionKey) || ! $normalized->isActionable) {
            throw MultimodalException::capabilityUnavailable(
                $actionKey,
                $normalized->capabilityUnavailableReason ?? "Action capability [{$actionKey}] is not registered for staging."
            );
        }

        $context = new CompanyContext(
            $principal->companyId,
            $principal->branchId,
            $principal->financialYearId
        );

        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            mode: 'company',
            threadId: $threadId ?? $asset->thread_id ?? $principal->requestId,
        );

        $provider = $this->requirementsRegistry->get($actionKey);
        $slots = $normalized->slots;

        $task = $this->taskState->createTask(
            context: $taskContext,
            actionKey: $actionKey,
            initialSlots: $slots,
            provenance: $normalized->sourceProvenance,
        );

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        if ($evaluation->hasBlockingIssues()) {
            $updatedTask = $this->taskState->updateTaskState(
                principal: $principal,
                taskId: (string) $task['id'],
                expectedRevision: (int) $task['revision'],
                slots: $slots,
                resolution: ['resolved_ids' => $batchResult->resolvedIds],
                issues: $evaluation->issuesArray(),
                status: 'collecting',
            );

            $asset->update(['workflow_task_id' => (string) $task['id']]);
            $clarification = $this->clarificationBuilder->build($updatedTask, $evaluation);

            return [
                'status' => 'needs_input',
                'media_id' => $asset->id,
                'extraction_id' => $extraction->id,
                'action_key' => $actionKey,
                'task' => $updatedTask,
                'clarification' => $clarification,
                'normalized_input' => $normalized->toArray(),
            ];
        }

        $proposal = $this->proposalService->create(
            $principal,
            $actionKey,
            $evaluation->validatedSlots,
            $threadId ?? $asset->thread_id ?? $principal->requestId,
        );

        $this->taskState->linkProposal((string) $task['id'], (string) $proposal['id']);
        $asset->update(['workflow_task_id' => (string) $task['id']]);

        $preview = json_decode((string) ($proposal['preview_json'] ?? '{}'), true) ?? [];

        return [
            'status' => 'proposal_created',
            'media_id' => $asset->id,
            'extraction_id' => $extraction->id,
            'action_key' => $actionKey,
            'task_id' => (string) $task['id'],
            'proposal' => [
                'proposal_id' => (string) $proposal['id'],
                'version' => (int) ($proposal['version'] ?? 1),
                'action_key' => $actionKey,
                'preview' => $preview,
            ],
            'normalized_input' => $normalized->toArray(),
        ];
    }
}

