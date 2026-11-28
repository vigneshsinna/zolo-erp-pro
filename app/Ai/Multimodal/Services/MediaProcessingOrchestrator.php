<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Conversation\ActiveWorkflowTaskResolver;
use App\Ai\Conversation\SupplierSetupConversation;
use App\Ai\Conversation\VoiceIntentResolver;
use App\Ai\Conversation\VoiceWorkflowReply;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\BatchRecordResolutionService;
use App\Ai\Services\ClarificationResponseBuilder;
use App\Ai\Services\InputCompletenessEvaluator;
use App\Ai\Services\WorkflowTaskStateService;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Models\AiMediaProcessingTask;
use App\Policies\AiMediaAssetPolicy;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Worker-side orchestrator advancing a leased AiMediaProcessingTask through
 * preprocess -> extract -> resolve -> validate -> prepare with re-authorization,
 * cancellation checks, deadline enforcement, and crash-safe idempotency.
 */
final class MediaProcessingOrchestrator
{
    public function __construct(
        private readonly MediaProcessingTaskService $taskService,
        private readonly MediaIngestionService $ingestionService,
        private readonly MediaExtractionService $extractionService,
        private readonly NormalizedInputBuilder $normalizedBuilder,
        private readonly AiFeatureGate $featureGate,
        private readonly AiPrincipalFactory $principalFactory,
        private readonly CompanyContextResolver $contextResolver,
        private readonly CommercialPermission $permissions,
        private readonly AiMediaAssetPolicy $assetPolicy,
        private readonly ActionCapabilityRegistry $capabilityRegistry,
        private readonly ActionRequirementsRegistry $requirementsRegistry,
        private readonly WorkflowTaskStateService $workflowTasks,
        private readonly BatchRecordResolutionService $batchResolver,
        private readonly InputCompletenessEvaluator $evaluator,
        private readonly ClarificationResponseBuilder $clarificationBuilder,
        private readonly ActionProposalService $proposalService,
    ) {}

    public function process(AiMediaProcessingTask $task): void
    {
        $leaseToken = (string) $task->lease_token;
        if ($leaseToken === '') {
            return;
        }

        try {
            $principal = $this->reauthorizeBeforeStage($task, $leaseToken);
            if ($principal === null) {
                return;
            }

            $asset = $this->loadValidAsset($task, $leaseToken);
            if ($asset === null) {
                return;
            }

            // Stage 2: Preprocess
            $asset = $this->runPreprocessStage($task, $leaseToken, $asset);
            if ($this->shouldStop($task, $leaseToken)) {
                return;
            }

            // Stage 3: Extract
            $extraction = $this->runExtractStage($principal, $task, $leaseToken, $asset);
            if ($extraction === null || $this->shouldStop($task, $leaseToken)) {
                return;
            }

            // Partial extraction guard: never auto-advance incomplete pages to posting review
            $normalizedPreview = is_array($extraction->normalized_input_json) ? $extraction->normalized_input_json : [];
            $warnings = is_array($normalizedPreview['warnings'] ?? null) ? $normalizedPreview['warnings'] : [];
            $hasIncompleteCoverage = false;
            foreach ($warnings as $w) {
                if (($w['code'] ?? '') === 'incomplete_document_coverage' || str_contains((string) $w, 'Partial extraction:')) {
                    $hasIncompleteCoverage = true;
                    break;
                }
            }
            if ($hasIncompleteCoverage) {
                $stages = $this->setStage($task->stages_json, 'extract', 'needs_input', [
                    'completed_at_utc' => now('UTC')->toIso8601String(),
                    'detail' => 'Partial pages extracted; review required',
                ]);
                $this->taskService->transitionWithLease($task, $leaseToken, [
                    'state' => 'needs_confirmation',
                    'current_stage' => 'extract',
                    'stages_json' => $stages,
                    'result_ref_json' => [
                        'status' => 'incomplete_extraction',
                        'message' => 'Some document pages could not be extracted. Review the partial extraction carefully before proceeding.',
                    ],
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => now('UTC'),
                ], 'partial_extraction_review', 'extract');

                return;
            }

            // Re-authorize before resolve/validate/prepare
            $principal = $this->reauthorizeBeforeStage($task, $leaseToken);
            if ($principal === null) {
                return;
            }

            // Voice & supplier photo special review paths
            if ($this->handleConversationMediaBranches($principal, $task, $leaseToken, $asset, $extraction)) {
                return;
            }

            // Stage 4: Resolve
            [$normalized, $shouldPauseDecision] = $this->runResolveStage($principal, $task, $leaseToken, $asset, $extraction);
            if ($shouldPauseDecision || $normalized === null || $this->shouldStop($task, $leaseToken)) {
                return;
            }

            // Stage 5: Validate
            [$wfTask, $evaluation, $shouldPauseValidation] = $this->runValidateStage($principal, $task, $leaseToken, $asset, $normalized);
            if ($shouldPauseValidation || $wfTask === null || $evaluation === null || $this->shouldStop($task, $leaseToken)) {
                return;
            }

            // Re-check cancellation & write scope immediately before final proposal staging
            $principal = $this->reauthorizeBeforeStage($task, $leaseToken);
            if ($principal === null) {
                return;
            }

            // Stage 6: Prepare
            $this->runPrepareStage($principal, $task, $leaseToken, $asset, $extraction, $normalized, $wfTask, $evaluation->validatedSlots);
        } catch (MultimodalException $e) {
            if ($e->errorCode === 'TASK_LEASE_LOST') {
                return;
            }
            $this->handleTaskFailure($task->fresh() ?? $task, $leaseToken, $e->errorCode, $e->getMessage(), $e->details);
        } catch (Throwable $e) {
            $this->handleTaskFailure(
                $task->fresh() ?? $task,
                $leaseToken,
                str_contains($e->getMessage(), 'quota') || $e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException
                    ? 'RATE_LIMITED'
                    : 'MEDIA_PROCESSING_FAILED',
                $e->getMessage() ?: 'Media processing failed safely.',
                ['retryable' => ! ($e instanceof \Illuminate\Auth\Access\AuthorizationException)],
            );
        }
    }

    private function runPreprocessStage(AiMediaProcessingTask &$task, string $leaseToken, AiMediaAsset $asset): AiMediaAsset
    {
        $stages = $task->stages_json;
        if (($stages['preprocess']['state'] ?? 'pending') === 'completed' && ! empty($asset->derivatives_json)) {
            return $asset;
        }

        $stages = $this->setStage($stages, 'preprocess', 'running', [
            'started_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Sanitizing and optimizing attachment',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'current_stage' => 'preprocess',
            'stages_json' => $stages,
        ], 'stage_started', 'preprocess');

        $asset = $this->ingestionService->preprocessAdmittedAsset($asset, (bool) $task->is_optical_profile);

        $stages = $this->setStage($task->stages_json, 'preprocess', 'completed', [
            'completed_at_utc' => now('UTC')->toIso8601String(),
            'detail' => $asset->page_count && $asset->page_count > 1
                ? "{$asset->page_count} pages verified"
                : 'File sanitized and verified',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'stages_json' => $stages,
        ], 'stage_completed', 'preprocess');

        return $asset;
    }

    private function runExtractStage(AiPrincipal $principal, AiMediaProcessingTask &$task, string $leaseToken, AiMediaAsset $asset): ?AiMediaExtraction
    {
        if (! $task->force_reextract) {
            $reusable = AiMediaExtraction::query()
                ->where('company_id', $principal->companyId)
                ->where('media_id', $asset->id)
                ->whereNull('error_code')
                ->orderByDesc('revision')
                ->first();

            if ($reusable !== null) {
                $stages = $this->setStage($task->stages_json, 'extract', 'completed', [
                    'started_at_utc' => now('UTC')->toIso8601String(),
                    'completed_at_utc' => now('UTC')->toIso8601String(),
                    'detail' => 'Reused verified extraction',
                ]);
                $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                    'current_stage' => 'extract',
                    'extraction_id' => $reusable->id,
                    'extraction_revision' => (int) $reusable->revision,
                    'model_provider' => $reusable->model_provider,
                    'model_key' => $reusable->model_key,
                    'stages_json' => $stages,
                ], 'extraction_reused', 'extract');

                return $reusable;
            }
        }

        $approvedProvider = (string) config('ai_multimodal.provider', 'nvidia');
        $approvedModel = (string) config('ai_multimodal.model', '');
        $totalPages = max(1, (int) ($asset->page_count ?? count((array) $asset->derivatives_json)));

        $stages = $this->setStage($task->stages_json, 'extract', 'running', [
            'started_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Processing document',
            'page_progress' => $totalPages > 1 ? ['completed' => count((array) $task->page_checkpoints_json), 'total' => $totalPages] : null,
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'current_stage' => 'extract',
            'model_provider' => $approvedProvider,
            'model_key' => $approvedModel,
            'stages_json' => $stages,
        ], 'stage_started', 'extract');

        $extraction = $this->extractionService->extractForTask(
            principal: $principal,
            asset: $asset,
            task: $task,
            onPageCheckpoint: function (int $pageNum, array $cpData) use (&$task, $leaseToken, $totalPages): void {
                $checkpoints = is_array($task->page_checkpoints_json) ? $task->page_checkpoints_json : [];
                $checkpoints[(string) $pageNum] = $cpData;
                $completedCount = count($checkpoints);
                $stages = $this->setStage($task->stages_json, 'extract', 'running', [
                    'page_progress' => ['completed' => $completedCount, 'total' => $totalPages],
                    'detail' => $totalPages > 1 ? "Extracted page {$completedCount} of {$totalPages}" : 'Document extracted',
                ]);
                $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                    'page_checkpoints_json' => $checkpoints,
                    'stages_json' => $stages,
                ], 'page_checkpointed', 'extract', ['page' => $pageNum]);
            },
            beforePageDispatch: function () use (&$task, $leaseToken): void {
                $this->assertNotCancelledOrExpired($task, $leaseToken);
                $this->taskService->heartbeatLease($task, $leaseToken);
            },
        );

        $stages = $this->setStage($task->stages_json, 'extract', 'completed', [
            'completed_at_utc' => now('UTC')->toIso8601String(),
            'detail' => $totalPages > 1 ? "{$totalPages} of {$totalPages} pages extracted" : 'Details extracted',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'extraction_id' => $extraction->id,
            'extraction_revision' => (int) $extraction->revision,
            'model_provider' => $extraction->model_provider,
            'model_key' => $extraction->model_key,
            'force_reextract' => false,
            'stages_json' => $stages,
        ], 'stage_completed', 'extract');

        return $extraction;
    }

    private function handleConversationMediaBranches(
        AiPrincipal $principal,
        AiMediaProcessingTask &$task,
        string $leaseToken,
        AiMediaAsset $asset,
        AiMediaExtraction $extraction,
    ): bool {
        if (! config('erp_ai.conversation_enabled', false)) {
            return false;
        }

        if ($task->workflow_task_id !== null) {
            $voiceReplyTask = app(VoiceWorkflowReply::class)->assertTask($principal, (string) $task->thread_id, (string) $task->workflow_task_id);
            if ($task->expected_task_revision !== null && (int) $voiceReplyTask['revision'] !== (int) $task->expected_task_revision) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Draft changed before media reply finished.');
            }

            $isSupplierPhoto = $voiceReplyTask['action_key'] === SupplierSetupConversation::KEY && $asset->source_kind !== 'audio';
            if ($isSupplierPhoto) {
                if ($asset->thread_id !== $task->thread_id || $asset->workflow_task_id !== $voiceReplyTask['id']
                    || ! $this->assetPolicy->canStageWriteAction($principal, $asset)) {
                    throw new \Illuminate\Auth\Access\AuthorizationException('Photo does not belong to the supplier draft.');
                }
                $status = 'supplier_photo_review_ready';
                $msg = 'Review the supplier photo details before applying them to the draft.';
            } else {
                app(VoiceWorkflowReply::class)->assertAsset($principal, $asset, (string) $task->thread_id, (string) $voiceReplyTask['id']);
                $status = 'voice_reply_ready';
                $msg = 'Review and confirm the transcript, then send it as an answer to this draft.';
            }

            $stages = $this->setStage($task->stages_json, 'resolve', 'completed', ['completed_at_utc' => now('UTC')->toIso8601String(), 'detail' => 'Matched active conversation draft']);
            $stages = $this->setStage($stages, 'validate', 'skipped', ['detail' => 'Not applicable before user confirmation']);
            $stages = $this->setStage($stages, 'prepare', 'skipped', ['detail' => 'Awaiting user transcript confirmation']);

            $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'needs_confirmation',
                'current_stage' => 'resolve',
                'stages_json' => $stages,
                'result_ref_json' => [
                    'status' => $status,
                    'task_revision' => (int) $voiceReplyTask['revision'],
                    'message' => $msg,
                ],
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'conversation_review_ready', 'resolve');

            return true;
        }

        if ($asset->source_kind === 'audio') {
            app(VoiceWorkflowReply::class)->assertInitialAsset($principal, $asset, (string) $task->thread_id);
            $transcript = (string) ($extraction->extracted_json['transcript_text'] ?? '');
            $intent = app(VoiceIntentResolver::class)->resolve($transcript);

            $stages = $this->setStage($task->stages_json, 'resolve', 'completed', ['completed_at_utc' => now('UTC')->toIso8601String(), 'detail' => 'Transcript intent evaluated']);
            $stages = $this->setStage($stages, 'validate', 'skipped', ['detail' => 'Not applicable before transcript confirmation']);
            $stages = $this->setStage($stages, 'prepare', 'skipped', ['detail' => 'Awaiting user transcript confirmation']);

            $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'needs_confirmation',
                'current_stage' => 'resolve',
                'stages_json' => $stages,
                'result_ref_json' => [
                    'status' => 'voice_intent_review_ready',
                    'intent_key' => $intent,
                    'message' => $intent === 'purchase_order.create.v1'
                        ? 'Review and confirm the transcription to start a Purchase Order conversation.'
                        : 'Review the transcription and specify the intended transaction. Nothing was staged.',
                ],
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'voice_intent_review_ready', 'resolve');

            return true;
        }

        return false;
    }

    /**
     * @return array{0: ?\App\Ai\Multimodal\DTOs\NormalizedInputV1, 1: bool}
     */
    private function runResolveStage(
        AiPrincipal $principal,
        AiMediaProcessingTask &$task,
        string $leaseToken,
        AiMediaAsset $asset,
        AiMediaExtraction $extraction,
    ): array {
        $stages = $this->setStage($task->stages_json, 'resolve', 'running', [
            'started_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Evaluating transaction type and candidates',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'current_stage' => 'resolve',
            'stages_json' => $stages,
        ], 'stage_started', 'resolve');

        $effectiveThreadId = $task->thread_id ?? $asset->thread_id;
        $resolutionMode = $task->resolution_mode;
        $overrideActionKey = $task->requested_action_key;

        $activeTaskAction = null;
        if ($effectiveThreadId !== null && $resolutionMode !== 'start_new_task') {
            $activeTasks = app(ActiveWorkflowTaskResolver::class)
                ->tasks($principal, $effectiveThreadId)
                ->whereIn('status', ['collecting', 'ready', 'proposal_created', 'awaiting_master'])
                ->where('expires_at', '>', now('UTC'))
                ->get(['action_key']);
            if ($activeTasks->count() === 1) {
                $activeTaskAction = (string) $activeTasks->first()->action_key;
            }
        }

        $resaleConfirmed = $resolutionMode === 'use_as_resale_reference';
        if ($resolutionMode === 'record_purchase_instead' && $overrideActionKey === null) {
            $overrideActionKey = 'purchase_bill.create.v1';
        } elseif ($resolutionMode === 'record_sales_instead' && $overrideActionKey === null) {
            $overrideActionKey = 'sales_invoice.create.v1';
        } elseif ($resaleConfirmed && $overrideActionKey === null) {
            $overrideActionKey = 'sales_invoice.create.v1';
        }

        $normalized = $this->normalizedBuilder->build(
            principal: $principal,
            asset: $asset,
            extracted: (array) $extraction->extracted_json,
            extractionId: $extraction->id,
            extractionRevision: (int) $extraction->revision,
            modelKey: (string) $extraction->model_key,
            companionText: $task->getCompanionText(),
            overrideIntentKey: $overrideActionKey,
            activeTaskAction: $activeTaskAction,
            resaleReferenceConfirmed: $resaleConfirmed,
        );

        $decision = $normalized->intentDecision ?? [];
        $decisionStatus = (string) ($decision['decision_status'] ?? 'ready');
        $reasonCode = (string) ($decision['reason_code'] ?? '');
        $actionKey = $normalized->intentKey;

        if ($decisionStatus === 'blocked') {
            throw MultimodalException::extractionFailed(
                'INVALID_ACTION_OVERRIDE',
                (string) ($decision['message'] ?? 'Requested transaction override is not permitted.'),
                422,
                ['retryable' => false],
            );
        }

        if (in_array($decisionStatus, ['needs_intent_confirmation', 'needs_source_confirmation', 'active_task_conflict'], true)
            || ($decisionStatus === 'source_role_conflict' && $reasonCode !== 'DOCUMENT_FOREIGN_COMPANY_MISMATCH')) {
            $stages = $this->setStage($task->stages_json, 'resolve', 'needs_input', [
                'completed_at_utc' => now('UTC')->toIso8601String(),
                'detail' => 'Confirmation required',
            ]);
            $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'needs_confirmation',
                'current_stage' => 'resolve',
                'resolved_action_key' => $actionKey,
                'stages_json' => $stages,
                'result_ref_json' => [
                    'status' => $decisionStatus,
                    'action_key' => $actionKey,
                    'decision' => $decision,
                    'normalized_input' => $normalized->toArray(),
                    'message' => (string) ($decision['message'] ?? 'Confirmation is required before staging an ERP draft.'),
                ],
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'confirmation_required', 'resolve');

            return [$normalized, true];
        }

        if ($normalized->conflicts !== []) {
            $firstConflict = $normalized->conflicts[0]['message'] ?? 'Conflicting values detected between document and instruction.';
            throw MultimodalException::extractionFailed('NORMALIZED_INPUT_CONFLICT', $firstConflict, 422, ['retryable' => false]);
        }

        if ($actionKey === null || $actionKey === 'query_readonly') {
            $stages = $this->setStage($task->stages_json, 'resolve', 'completed', ['completed_at_utc' => now('UTC')->toIso8601String(), 'detail' => 'Read-only summary prepared']);
            $stages = $this->setStage($stages, 'validate', 'skipped', ['detail' => 'Not applicable for read-only summary']);
            $stages = $this->setStage($stages, 'prepare', 'skipped', ['detail' => 'No ERP write action requested']);
            $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'review_ready',
                'current_stage' => 'resolve',
                'resolved_action_key' => 'query_readonly',
                'stages_json' => $stages,
                'result_ref_json' => [
                    'status' => 'readonly_summary',
                    'action_key' => 'query_readonly',
                    'normalized_input' => $normalized->toArray(),
                    'message' => 'Extraction confirmed for read-only analysis; no commercial workflow task was staged.',
                ],
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'readonly_summary_ready', 'resolve');

            return [$normalized, true];
        }

        $stages = $this->setStage($task->stages_json, 'resolve', 'completed', [
            'completed_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Transaction route and entity candidates resolved',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'resolved_action_key' => $actionKey,
            'stages_json' => $stages,
        ], 'stage_completed', 'resolve');

        return [$normalized, false];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?\App\Ai\DTOs\InputEvaluationResult, 2: bool}
     */
    private function runValidateStage(
        AiPrincipal $principal,
        AiMediaProcessingTask &$task,
        string $leaseToken,
        AiMediaAsset $asset,
        \App\Ai\Multimodal\DTOs\NormalizedInputV1 $normalized,
    ): array {
        $actionKey = (string) $normalized->intentKey;
        if (! $this->capabilityRegistry->has($actionKey) || ! $normalized->isActionable) {
            throw MultimodalException::capabilityUnavailable(
                $actionKey,
                $normalized->capabilityUnavailableReason ?? "Action capability [{$actionKey}] is not registered for staging."
            );
        }

        if (! $this->assetPolicy->canStageWriteAction($principal, $asset)) {
            throw MultimodalException::crossTenantForbidden('Cross-branch or cross-financial-year document reuse is forbidden for write actions.');
        }

        $stages = $this->setStage($task->stages_json, 'validate', 'running', [
            'started_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Checking ERP party, stock, GST and pricing',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'current_stage' => 'validate',
            'stages_json' => $stages,
        ], 'stage_started', 'validate');

        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            mode: 'company',
            threadId: $task->thread_id ?? $asset->thread_id ?? $principal->requestId,
        );

        $provider = $this->requirementsRegistry->get($actionKey);
        $slots = $normalized->slots;

        // Reuse existing workflow task if a prior crashed worker already created it
        $wfTask = null;
        if (! empty($task->workflow_task_id)) {
            try {
                $wfTask = $this->workflowTasks->getTask($principal, (string) $task->workflow_task_id);
            } catch (Throwable) {
                $wfTask = null;
            }
        }

        if ($wfTask === null) {
            $wfTask = $this->workflowTasks->createTask(
                context: $taskContext,
                actionKey: $actionKey,
                initialSlots: $slots,
                provenance: $normalized->sourceProvenance,
            );
            $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                'workflow_task_id' => (string) $wfTask['id'],
            ], 'workflow_task_linked', 'validate');
        }

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        if ($evaluation->hasBlockingIssues()) {
            $updatedTask = $this->workflowTasks->updateTaskState(
                principal: $principal,
                taskId: (string) $wfTask['id'],
                expectedRevision: (int) $wfTask['revision'],
                slots: $slots,
                resolution: ['resolved_ids' => $batchResult->resolvedIds],
                issues: $evaluation->issuesArray(),
                status: 'collecting',
            );

            $asset->update(['workflow_task_id' => (string) $wfTask['id']]);
            $clarification = $this->clarificationBuilder->build($updatedTask, $evaluation);

            $stages = $this->setStage($task->stages_json, 'validate', 'needs_input', [
                'completed_at_utc' => now('UTC')->toIso8601String(),
                'detail' => 'Missing or ambiguous ERP fields require your input',
            ]);
            $task = $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'needs_input',
                'current_stage' => 'validate',
                'stages_json' => $stages,
                'result_ref_json' => [
                    'status' => 'needs_input',
                    'action_key' => $actionKey,
                    'clarification' => $clarification,
                    'normalized_input' => $normalized->toArray(),
                ],
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'needs_user_input', 'validate');

            return [$updatedTask, $evaluation, true];
        }

        $stages = $this->setStage($task->stages_json, 'validate', 'completed', [
            'completed_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'ERP checks passed',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'stages_json' => $stages,
        ], 'stage_completed', 'validate');

        return [$wfTask, $evaluation, false];
    }

    /**
     * @param array<string, mixed> $wfTask
     * @param array<string, mixed> $validatedSlots
     */
    private function runPrepareStage(
        AiPrincipal $principal,
        AiMediaProcessingTask &$task,
        string $leaseToken,
        AiMediaAsset $asset,
        AiMediaExtraction $extraction,
        \App\Ai\Multimodal\DTOs\NormalizedInputV1 $normalized,
        array $wfTask,
        array $validatedSlots,
    ): void {
        $actionKey = (string) $normalized->intentKey;
        $stages = $this->setStage($task->stages_json, 'prepare', 'running', [
            'started_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Staging draft for human review',
        ]);
        $task = $this->taskService->transitionWithLease($task, $leaseToken, [
            'current_stage' => 'prepare',
            'stages_json' => $stages,
        ], 'stage_started', 'prepare');

        if (! $extraction->isPreparedOrConfirmed()) {
            $extraction->update([
                'confirmation_status' => 'prepared',
                'confirmed_by' => $principal->actorId,
                'confirmed_at' => now('UTC'),
            ]);
        }

        // Crash-recovery idempotency: reuse proposal if already created for this task
        $proposal = null;
        if (! empty($task->proposal_id)) {
            try {
                $proposal = $this->proposalService->getOwned($principal, (string) $task->proposal_id);
            } catch (Throwable) {
                $proposal = null;
            }
        }
        if ($proposal === null && ! empty($wfTask['linked_proposal_id'])) {
            try {
                $proposal = $this->proposalService->getOwned($principal, (string) $wfTask['linked_proposal_id']);
            } catch (Throwable) {
                $proposal = null;
            }
        }

        if ($proposal === null) {
            $proposal = $this->proposalService->create(
                $principal,
                $actionKey,
                $validatedSlots,
                $task->thread_id ?? $asset->thread_id ?? $principal->requestId,
            );
            $this->workflowTasks->linkProposal((string) $wfTask['id'], (string) $proposal['id']);
        }

        $asset->update(['workflow_task_id' => (string) $wfTask['id']]);
        $preview = json_decode((string) ($proposal['preview_json'] ?? '{}'), true) ?? [];

        $stages = $this->setStage($task->stages_json, 'prepare', 'completed', [
            'completed_at_utc' => now('UTC')->toIso8601String(),
            'detail' => 'Draft ready for human review',
        ]);

        $this->taskService->transitionWithLease($task, $leaseToken, [
            'state' => 'review_ready',
            'current_stage' => 'prepare',
            'proposal_id' => (string) $proposal['id'],
            'proposal_version' => (int) ($proposal['version'] ?? 1),
            'stages_json' => $stages,
            'result_ref_json' => [
                'status' => 'proposal_created',
                'action_key' => $actionKey,
                'proposal' => [
                    'proposal_id' => (string) $proposal['id'],
                    'version' => (int) ($proposal['version'] ?? 1),
                    'action_key' => $actionKey,
                    'preview' => $preview,
                ],
                'normalized_input' => $normalized->toArray(),
            ],
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'completed_at' => now('UTC'),
        ], 'review_ready', 'prepare');
    }

    private function reauthorizeBeforeStage(AiMediaProcessingTask &$task, string $leaseToken): ?AiPrincipal
    {
        if ($this->shouldStop($task, $leaseToken)) {
            return null;
        }

        if (! (bool) config('ai_multimodal.async.enabled', false)
            || ! $this->featureGate->isMultimodalEnabledForCompany((int) $task->company_id)) {
            throw MultimodalException::featureDisabled('Multimodal AI was disabled for this company while queued.');
        }

        $principal = $this->principalFactory->createForUser(
            userId: (int) $task->actor_id,
            companyId: (int) $task->company_id,
            branchId: (int) $task->branch_id,
            financialYearId: (int) $task->financial_year_id,
            requestId: $task->correlation_id,
        );

        if (! $principal->isActive) {
            throw MultimodalException::permissionDenied('Actor account is inactive.');
        }

        $context = $this->contextResolver->resolve(
            userId: (int) $task->actor_id,
            companyId: (int) $task->company_id,
            branchId: (int) $task->branch_id,
            financialYearId: (int) $task->financial_year_id,
        );

        if (! $this->permissions->allows('ai.use', $context, (int) $task->actor_id)) {
            throw MultimodalException::permissionDenied('AI usage permission was revoked.');
        }

        return $principal;
    }

    private function loadValidAsset(AiMediaProcessingTask $task, string $leaseToken): ?AiMediaAsset
    {
        $asset = AiMediaAsset::query()
            ->where('company_id', $task->company_id)
            ->where('actor_id', $task->actor_id)
            ->find($task->media_id);

        if ($asset === null || $asset->isPurged()) {
            throw MultimodalException::mediaNotFound('Source media asset is no longer available.');
        }
        if ($asset->isExpired()) {
            throw MultimodalException::mediaExpired('Source media asset expired before processing finished.');
        }

        return $asset;
    }

    private function shouldStop(AiMediaProcessingTask &$task, string $leaseToken): bool
    {
        $fresh = $task->fresh();
        if ($fresh === null) {
            return true;
        }
        $task = $fresh;

        if ($task->state === 'cancel_requested' || $task->cancel_requested_at !== null) {
            $stages = $this->setStage($task->stages_json, $task->current_stage, 'failed', [
                'error_code' => 'TASK_CANCELLED',
                'detail' => 'Cancelled by user',
            ]);
            $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'cancelled',
                'stages_json' => $stages,
                'error_code' => 'TASK_CANCELLED',
                'error_message' => 'Processing cancelled by user.',
                'is_retryable' => true,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'cancelled', $task->current_stage);

            return true;
        }

        if ($task->deadline_at !== null && $task->deadline_at->isPast()) {
            $stages = $this->setStage($task->stages_json, $task->current_stage, 'failed', [
                'error_code' => 'TASK_DEADLINE_EXCEEDED',
                'detail' => 'Processing deadline exceeded',
            ]);
            $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'expired',
                'stages_json' => $stages,
                'error_code' => 'TASK_DEADLINE_EXCEEDED',
                'error_message' => 'Processing exceeded the allowed deadline. You can retry with the saved attachment.',
                'failed_stage' => $task->current_stage,
                'is_retryable' => true,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now('UTC'),
            ], 'deadline_exceeded', $task->current_stage);

            return true;
        }

        return false;
    }

    private function assertNotCancelledOrExpired(AiMediaProcessingTask &$task, string $leaseToken): void
    {
        if ($this->shouldStop($task, $leaseToken)) {
            throw MultimodalException::taskLeaseLost();
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function handleTaskFailure(
        AiMediaProcessingTask $task,
        string $leaseToken,
        string $errorCode,
        string $message,
        array $details = [],
    ): void {
        if ($task->isTerminal()) {
            return;
        }

        $failedStage = $task->current_stage ?: 'extract';
        $requestSent = ! empty($details['request_sent']);
        $nowUtc = now('UTC');

        // 429 rate-limit deferral with Retry-After within absolute deadline
        if ($errorCode === 'RATE_LIMITED') {
            $deferrals = (int) $task->rate_limit_deferrals + 1;
            $maxDeferrals = max(1, (int) config('ai_multimodal.async.max_rate_limit_deferrals', 5));
            $retryAfter = max(5, min(300, (int) ($details['retry_after'] ?? 30)));
            $nextRetryAt = $nowUtc->copy()->addSeconds($retryAfter);

            if ($deferrals <= $maxDeferrals && ($task->deadline_at === null || $nextRetryAt->lt($task->deadline_at))) {
                $stages = $this->setStage($task->stages_json, $failedStage, 'retry_wait', [
                    'error_code' => $errorCode,
                    'detail' => "Rate limit reached; retrying in {$retryAfter}s",
                ]);
                $this->taskService->transitionWithLease($task, $leaseToken, [
                    'state' => 'retry_scheduled',
                    'rate_limit_deferrals' => $deferrals,
                    'next_retry_at' => $nextRetryAt,
                    'error_code' => $errorCode,
                    'error_message' => 'AI provider is busy. Automatic retry scheduled.',
                    'failed_stage' => $failedStage,
                    'is_retryable' => true,
                    'stages_json' => $stages,
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ], 'rate_limit_deferred', $failedStage, ['retry_after' => $retryAfter, 'deferrals' => $deferrals]);

                $this->taskService->dispatchTaskAfterCommit($task->id, delaySeconds: $retryAfter);

                return;
            }
        }

        // Transient pre-send connection/5xx errors may schedule delayed backoff within attempt budget
        $transientUnsent = ! $requestSent && in_array($errorCode, ['PROVIDER_CONNECTION_FAILED', 'PERCEPTION_PROVIDER_UNAVAILABLE', 'PROVIDER_TIMEOUT'], true);
        if ($transientUnsent) {
            $nextAttempt = (int) $task->attempt_count + 1;
            $maxAttempts = max(1, (int) config('ai_multimodal.async.max_provider_attempts_per_page', 2));
            if ($nextAttempt < $maxAttempts) {
                $schedule = (array) config('ai_multimodal.async.retry_backoff_seconds', [15, 30, 60]);
                $baseDelay = (int) ($schedule[min($nextAttempt - 1, count($schedule) - 1)] ?? 30);
                $delay = max(1, $baseDelay + random_int(0, 5));
                $nextRetryAt = $nowUtc->copy()->addSeconds($delay);

                if ($task->deadline_at === null || $nextRetryAt->lt($task->deadline_at)) {
                    $stages = $this->setStage($task->stages_json, $failedStage, 'retry_wait', [
                        'error_code' => $errorCode,
                        'detail' => "Temporary provider issue; retrying in {$delay}s",
                    ]);
                    $this->taskService->transitionWithLease($task, $leaseToken, [
                        'state' => 'retry_scheduled',
                        'attempt_count' => $nextAttempt,
                        'next_retry_at' => $nextRetryAt,
                        'error_code' => $errorCode,
                        'error_message' => 'Temporary provider interruption. Automatic retry scheduled.',
                        'failed_stage' => $failedStage,
                        'is_retryable' => true,
                        'stages_json' => $stages,
                        'lease_owner' => null,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                    ], 'transient_retry_scheduled', $failedStage, ['delay' => $delay, 'attempt' => $nextAttempt]);

                    $this->taskService->dispatchTaskAfterCommit($task->id, delaySeconds: $delay);

                    return;
                }
            }
        }

        // Non-retryable terminal errors vs explicit user retryable failures (including uncertain post-send timeouts)
        $nonRetryableCodes = [
            'PROVIDER_HTTP_400',
            'PROVIDER_AUTH_FAILED',
            'PROVIDER_SSL_ERROR',
            'MULTIMODAL_DISABLED',
            'PERMISSION_DENIED',
            'CROSS_TENANT_FORBIDDEN',
            'MEDIA_NOT_FOUND',
            'MEDIA_EXPIRED',
            'CAPABILITY_UNAVAILABLE',
            'INVALID_ACTION_OVERRIDE',
            'NORMALIZED_INPUT_CONFLICT',
        ];
        $isRetryable = isset($details['is_retryable'])
            ? (bool) $details['is_retryable']
            : (isset($details['retryable']) ? (bool) $details['retryable'] : ! in_array($errorCode, $nonRetryableCodes, true));

        $friendlyMessage = $requestSent && $errorCode === 'PROVIDER_TIMEOUT'
            ? 'The perception provider timed out after receiving the request. Review credit status or click Retry Extraction to re-run safely.'
            : substr($message, 0, 480);

        $stages = $this->setStage($task->stages_json, $failedStage, 'failed', [
            'error_code' => $errorCode,
            'detail' => $friendlyMessage,
        ]);

        try {
            $this->taskService->transitionWithLease($task, $leaseToken, [
                'state' => 'failed',
                'attempt_count' => (int) $task->attempt_count + ($failedStage === 'extract' ? 1 : 0),
                'error_code' => $errorCode,
                'error_message' => $friendlyMessage,
                'failed_stage' => $failedStage,
                'is_retryable' => $isRetryable,
                'stages_json' => $stages,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => $nowUtc,
            ], 'task_failed', $failedStage, ['request_sent' => $requestSent]);
        } catch (Throwable) {
            // Lease already lost to sweeper/another worker
        }
    }

    /**
     * @param array<string, mixed> $stages
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function setStage(array $stages, string $stageKey, string $state, array $patch = []): array
    {
        $current = is_array($stages[$stageKey] ?? null) ? $stages[$stageKey] : [];
        $stages[$stageKey] = array_merge($current, ['state' => $state], $patch);

        return $stages;
    }
}
