<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Conversation\VoiceIntentResolver;
use App\Ai\Conversation\VoiceWorkflowReply;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\IndustryContextResolver;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\WorkflowTaskStateService;
use App\Jobs\Ai\ProcessMediaTaskJob;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Models\AiMediaProcessingEvent;
use App\Models\AiMediaProcessingTask;
use App\Policies\AiMediaAssetPolicy;
use App\Services\Platform\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Manages durable async media preparation tasks, atomic lease claims, CAS transitions,
 * idempotent admission, cancellation, retries, and sanitized snapshot presentation.
 */
final class MediaProcessingTaskService
{
    public const STAGE_ORDER = ['upload', 'preprocess', 'extract', 'resolve', 'validate', 'prepare'];

    public function __construct(
        private readonly AiFeatureGate $featureGate,
        private readonly AiMediaAssetPolicy $assetPolicy,
        private readonly MediaIngestionService $ingestionService,
        private readonly MediaQueueConfigValidator $queueValidator,
        private readonly WorkflowTaskStateService $workflowTasks,
        private readonly ActionProposalService $proposalService,
    ) {}

    /**
     * Admit a new async media preparation task or replay an identical in-scope submission.
     *
     * @param array<string, mixed> $params
     * @return array{task: AiMediaProcessingTask, replayed: bool}
     */
    public function admit(
        AiPrincipal $principal,
        ?UploadedFile $file,
        ?string $mediaId,
        array $params = [],
    ): array {
        if (! (bool) config('ai_multimodal.async.enabled', false)) {
            throw MultimodalException::asyncDisabled();
        }

        if (! $this->featureGate->isMultimodalEnabledForCompany($principal->companyId)) {
            throw MultimodalException::featureDisabled();
        }

        $queueSpec = $this->queueValidator->assertAsyncQueueReady();

        $threadId = isset($params['thread_id']) && is_string($params['thread_id']) && $params['thread_id'] !== ''
            ? $params['thread_id']
            : null;
        $workflowTaskId = isset($params['workflow_task_id']) && is_string($params['workflow_task_id']) && $params['workflow_task_id'] !== ''
            ? $params['workflow_task_id']
            : null;
        $expectedTaskRevision = isset($params['expected_task_revision']) ? (int) $params['expected_task_revision'] : null;
        $companionText = isset($params['companion_text']) && is_string($params['companion_text']) && trim($params['companion_text']) !== ''
            ? trim($params['companion_text'])
            : null;
        $requestedActionKey = isset($params['action_key']) && is_string($params['action_key']) && trim($params['action_key']) !== ''
            ? trim($params['action_key'])
            : null;
        $resolutionMode = isset($params['resolution_mode']) && is_string($params['resolution_mode']) && trim($params['resolution_mode']) !== ''
            ? trim($params['resolution_mode'])
            : null;
        $forceReextract = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($workflowTaskId !== null && config('erp_ai.conversation_enabled', false)) {
            if ($threadId === null || $expectedTaskRevision === null || $expectedTaskRevision < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'workflow_task_id' => 'Owned thread_id and expected_task_revision are required for workflow media replies.',
                ]);
            }
            $voiceReplyTask = app(VoiceWorkflowReply::class)->assertTask($principal, $threadId, $workflowTaskId);
            if ((int) $voiceReplyTask['revision'] !== $expectedTaskRevision) {
                throw new ConflictHttpException('Draft changed. Review the current draft before recording a reply.');
            }
        }

        $clientRequestId = $this->normalizeClientRequestId($params['client_request_id'] ?? null);
        $fileSha256 = null;
        if ($file !== null) {
            $realPath = $file->getRealPath();
            $fileSha256 = is_string($realPath) && is_file($realPath) ? (hash_file('sha256', $realPath) ?: '') : '';
        } elseif ($mediaId !== null) {
            $existingAsset = $this->loadOwnedAsset($principal, $mediaId);
            $fileSha256 = (string) $existingAsset->sha256;
        } else {
            throw MultimodalException::mediaNotFound('Provide either an uploaded file or a valid media_id to prepare.');
        }

        $requestHash = $this->computeRequestHash([
            'sha256' => $fileSha256,
            'media_id' => $file === null ? $mediaId : null,
            'thread_id' => $threadId,
            'workflow_task_id' => $workflowTaskId,
            'expected_task_revision' => $expectedTaskRevision,
            'companion_text' => $companionText ?? '',
            'action_key' => $requestedActionKey ?? '',
            'resolution_mode' => $resolutionMode ?? '',
            'force' => $forceReextract,
        ]);

        // Fast replay check before touching media storage or quotas
        $existingTask = $this->findByClientRequestId($principal, $clientRequestId);
        if ($existingTask !== null) {
            if (! hash_equals($existingTask->request_hash, $requestHash)) {
                throw MultimodalException::idempotencyConflict('Client request key was previously used with a different file or instruction.');
            }

            return ['task' => $existingTask, 'replayed' => true];
        }

        $asset = $file !== null
            ? $this->ingestionService->admitRaw(
                principal: $principal,
                file: $file,
                declaredMime: $file->getClientMimeType(),
                originalName: $file->getClientOriginalName(),
                idempotencyKey: $clientRequestId,
                threadId: $threadId,
                workflowTaskId: $workflowTaskId,
            )
            : $this->loadOwnedAsset($principal, (string) $mediaId);

        if ($file === null && ! $this->assetPolicy->canStageWriteAction($principal, $asset)) {
            throw MultimodalException::crossTenantForbidden('Cross-branch or cross-financial-year document reuse is forbidden for write actions.');
        }
        if ($asset->isExpired()) {
            throw MultimodalException::mediaExpired();
        }

        $isOptical = $this->resolveIsOpticalProfile($principal);
        $alreadyPreprocessed = ! empty($asset->derivatives_json) && $asset->state !== 'uploaded';
        $nowUtc = now('UTC');
        $deadlineAt = $nowUtc->copy()->addSeconds(max(120, (int) config('ai_multimodal.async.task_deadline_seconds', 600)));

        $stages = $this->buildInitialStages($nowUtc->toIso8601String(), $alreadyPreprocessed);
        $initialStage = $alreadyPreprocessed ? 'extract' : 'preprocess';
        $taskId = (string) Str::uuid();

        try {
            $task = DB::transaction(function () use (
                $principal,
                $asset,
                $taskId,
                $threadId,
                $workflowTaskId,
                $expectedTaskRevision,
                $clientRequestId,
                $requestHash,
                $requestedActionKey,
                $companionText,
                $resolutionMode,
                $forceReextract,
                $isOptical,
                $initialStage,
                $stages,
                $deadlineAt,
                $nowUtc,
            ): AiMediaProcessingTask {
                $lockedReplay = AiMediaProcessingTask::query()
                    ->where('company_id', $principal->companyId)
                    ->where('branch_id', $principal->branchId)
                    ->where('financial_year_id', $principal->financialYearId)
                    ->where('actor_id', $principal->actorId)
                    ->where('client_request_id', $clientRequestId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedReplay !== null) {
                    if (! hash_equals($lockedReplay->request_hash, $requestHash)) {
                        throw MultimodalException::idempotencyConflict('Client request key was previously used with a different file or instruction.');
                    }

                    return $lockedReplay;
                }

                $created = AiMediaProcessingTask::query()->create([
                    'id' => $taskId,
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                    'actor_id' => $principal->actorId,
                    'thread_id' => $threadId ?? $asset->thread_id,
                    'workflow_task_id' => $workflowTaskId,
                    'expected_task_revision' => $expectedTaskRevision,
                    'media_id' => $asset->id,
                    'client_request_id' => $clientRequestId,
                    'request_hash' => $requestHash,
                    'requested_action_key' => $requestedActionKey,
                    'companion_text_encrypted' => AiMediaProcessingTask::encryptCompanionText($companionText),
                    'resolution_mode' => $resolutionMode,
                    'force_reextract' => $forceReextract,
                    'is_optical_profile' => $isOptical,
                    'source_kind' => $asset->source_kind,
                    'state' => 'queued',
                    'current_stage' => $initialStage,
                    'state_version' => 1,
                    'stages_json' => $stages,
                    'deadline_at' => $deadlineAt,
                    'correlation_id' => $principal->requestId,
                    'preparation_idempotency_key' => 'prep_' . $taskId,
                ]);

                AiMediaProcessingEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'task_id' => $created->id,
                    'company_id' => $principal->companyId,
                    'state_version' => 1,
                    'stage' => 'upload',
                    'event_type' => 'task_admitted',
                    'from_state' => null,
                    'to_state' => 'queued',
                    'meta_json' => ['source_kind' => $asset->source_kind],
                    'occurred_at_utc' => $nowUtc,
                ]);

                return $created;
            }, 3);
        } catch (QueryException $qe) {
            // Handle concurrent unique constraint collision gracefully
            $concurrent = $this->findByClientRequestId($principal, $clientRequestId);
            if ($concurrent !== null) {
                if (! hash_equals($concurrent->request_hash, $requestHash)) {
                    throw MultimodalException::idempotencyConflict('Client request key was previously used with a different file or instruction.');
                }

                return ['task' => $concurrent, 'replayed' => true];
            }
            throw $qe;
        }

        $replayed = $task->id !== $taskId;
        if (! $replayed) {
            $this->dispatchTaskAfterCommit($task->id, $queueSpec['connection'], $queueSpec['queue']);
        }

        return ['task' => $task->fresh(), 'replayed' => $replayed];
    }

    public function dispatchTaskAfterCommit(string $taskId, ?string $connection = null, ?string $queue = null, int $delaySeconds = 0): void
    {
        $conn = $connection ?: $this->queueValidator->resolveConnection();
        $q = $queue ?: $this->queueValidator->resolveQueueName();

        $enqueue = static function () use ($taskId, $conn, $q, $delaySeconds): void {
            try {
                $pending = ProcessMediaTaskJob::dispatch($taskId)->onConnection($conn)->onQueue($q);
                if ($delaySeconds > 0) {
                    $pending->delay(now('UTC')->addSeconds($delaySeconds));
                }
                AiMediaProcessingTask::query()
                    ->where('id', $taskId)
                    ->whereNull('dispatched_at')
                    ->update(['dispatched_at' => now('UTC')]);
            } catch (Throwable) {
                // Outbox recovery sweeper picks up queued tasks where dispatched_at remains null.
            }
        };

        $testBaselineLevel = app()->runningUnitTests() ? 1 : 0;
        if (DB::transactionLevel() > $testBaselineLevel) {
            DB::afterCommit($enqueue);
        } else {
            $enqueue();
        }
    }

    public function findOwnedTask(AiPrincipal $principal, string $taskId): AiMediaProcessingTask
    {
        $task = AiMediaProcessingTask::query()->find($taskId);
        if ($task === null) {
            throw MultimodalException::taskNotFound();
        }

        if ((int) $task->company_id !== $principal->companyId
            || (int) $task->branch_id !== $principal->branchId
            || (int) $task->financial_year_id !== $principal->financialYearId
            || (int) $task->actor_id !== $principal->actorId) {
            throw MultimodalException::crossTenantForbidden('Media processing task does not belong to the active workspace or user.');
        }

        return $task;
    }

    public function findByClientRequestId(AiPrincipal $principal, string $clientRequestId): ?AiMediaProcessingTask
    {
        return AiMediaProcessingTask::query()
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('actor_id', $principal->actorId)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    /**
     * Scoped listing for conversation history restoration or lost admission lookup.
     *
     * @return Collection<int, AiMediaProcessingTask>
     */
    public function listForScope(
        AiPrincipal $principal,
        ?string $threadId = null,
        ?string $clientRequestId = null,
        int $limit = 20,
    ): Collection {
        if ($threadId === null && $clientRequestId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'thread_id' => 'Provide thread_id or client_request_id to query scoped processing tasks.',
            ]);
        }

        $query = AiMediaProcessingTask::query()
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('actor_id', $principal->actorId)
            ->orderByDesc('created_at')
            ->limit(max(1, min(50, $limit)));

        if ($clientRequestId !== null) {
            $query->where('client_request_id', substr(trim($clientRequestId), 0, 120));
        }
        if ($threadId !== null) {
            $query->where('thread_id', $threadId);
        }

        return $query->get();
    }

    /**
     * Atomic lease acquisition via conditional UPDATE. Duplicate deliveries return null.
     */
    public function claimLease(string $taskId, string $workerOwner): ?AiMediaProcessingTask
    {
        $nowUtc = now('UTC');
        $leaseTtl = max(60, (int) config('ai_multimodal.async.lease_ttl_seconds', 210));
        $leaseToken = (string) Str::uuid();
        $leaseExpiresAt = $nowUtc->copy()->addSeconds($leaseTtl);

        $existing = AiMediaProcessingTask::query()->find($taskId);
        if ($existing === null) {
            return null;
        }

        $affected = AiMediaProcessingTask::query()
            ->where('id', $taskId)
            ->whereIn('state', ['queued', 'retry_scheduled'])
            ->where(function ($q) use ($nowUtc): void {
                $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $nowUtc);
            })
            ->where(function ($q) use ($nowUtc): void {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $nowUtc);
            })
            ->update([
                'state' => 'processing',
                'lease_owner' => substr($workerOwner, 0, 120),
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpiresAt,
                'heartbeat_at' => $nowUtc,
                'started_at' => $existing->started_at ?? $nowUtc,
                'state_version' => DB::raw('state_version + 1'),
                'updated_at' => $nowUtc,
            ]);

        if ($affected !== 1) {
            return null;
        }

        $claimed = AiMediaProcessingTask::query()->find($taskId);
        if ($claimed === null) {
            return null;
        }

        AiMediaProcessingEvent::query()->create([
            'id' => (string) Str::uuid(),
            'task_id' => $claimed->id,
            'company_id' => $claimed->company_id,
            'state_version' => $claimed->state_version,
            'stage' => $claimed->current_stage,
            'event_type' => 'lease_claimed',
            'from_state' => $existing->state,
            'to_state' => 'processing',
            'meta_json' => ['lease_owner' => substr($workerOwner, 0, 120)],
            'occurred_at_utc' => $nowUtc,
        ]);

        return $claimed;
    }

    /**
     * Fenced CAS transition requiring matching lease_token and state_version.
     *
     * @param array<string, mixed> $updates
     * @param array<string, mixed> $meta
     */
    public function transitionWithLease(
        AiMediaProcessingTask $task,
        string $leaseToken,
        array $updates,
        string $eventType,
        string $stage,
        array $meta = [],
    ): AiMediaProcessingTask {
        $nowUtc = now('UTC');
        $fromState = $task->state;
        $expectedVersion = (int) $task->state_version;
        $nextVersion = $expectedVersion + 1;

        $dbUpdates = [];
        foreach ($updates as $col => $val) {
            if (in_array($col, ['stages_json', 'page_checkpoints_json', 'result_ref_json'], true) && is_array($val)) {
                $dbUpdates[$col] = json_encode($val, JSON_THROW_ON_ERROR);
            } else {
                $dbUpdates[$col] = $val;
            }
        }
        $dbUpdates['state_version'] = $nextVersion;
        $dbUpdates['heartbeat_at'] = $nowUtc;
        $dbUpdates['updated_at'] = $nowUtc;

        $affected = AiMediaProcessingTask::query()
            ->where('id', $task->id)
            ->where('lease_token', $leaseToken)
            ->where('state_version', $expectedVersion)
            ->update($dbUpdates);

        if ($affected !== 1) {
            throw MultimodalException::taskLeaseLost();
        }

        $fresh = $task->fresh();
        if ($fresh === null) {
            throw MultimodalException::taskNotFound();
        }

        AiMediaProcessingEvent::query()->create([
            'id' => (string) Str::uuid(),
            'task_id' => $fresh->id,
            'company_id' => $fresh->company_id,
            'state_version' => $fresh->state_version,
            'stage' => $stage,
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $fresh->state,
            'error_code' => $fresh->error_code,
            'meta_json' => $meta !== [] ? $meta : null,
            'occurred_at_utc' => $nowUtc,
        ]);

        return $fresh;
    }

    public function heartbeatLease(AiMediaProcessingTask $task, string $leaseToken): void
    {
        $nowUtc = now('UTC');
        $leaseTtl = max(60, (int) config('ai_multimodal.async.lease_ttl_seconds', 210));
        AiMediaProcessingTask::query()
            ->where('id', $task->id)
            ->where('lease_token', $leaseToken)
            ->where('state', 'processing')
            ->update([
                'heartbeat_at' => $nowUtc,
                'lease_expires_at' => $nowUtc->copy()->addSeconds($leaseTtl),
                'updated_at' => $nowUtc,
            ]);
    }

    public function requestCancel(AiPrincipal $principal, string $taskId, int $expectedVersion): AiMediaProcessingTask
    {
        return DB::transaction(function () use ($principal, $taskId, $expectedVersion): AiMediaProcessingTask {
            $task = AiMediaProcessingTask::query()->lockForUpdate()->find($taskId);
            if ($task === null) {
                throw MultimodalException::taskNotFound();
            }
            if ((int) $task->company_id !== $principal->companyId
                || (int) $task->branch_id !== $principal->branchId
                || (int) $task->financial_year_id !== $principal->financialYearId
                || (int) $task->actor_id !== $principal->actorId) {
                throw MultimodalException::crossTenantForbidden();
            }

            if ($task->state === 'cancelled' || $task->state === 'cancel_requested') {
                return $task;
            }

            if ((int) $task->state_version !== $expectedVersion) {
                throw MultimodalException::taskRevisionConflict((int) $task->state_version, $expectedVersion);
            }

            if ($task->isTerminal()) {
                throw MultimodalException::taskNotRetryable('Task has already finished and cannot be cancelled.');
            }

            $nowUtc = now('UTC');
            $fromState = $task->state;
            $nextState = in_array($fromState, ['queued', 'retry_scheduled'], true) ? 'cancelled' : 'cancel_requested';

            $updates = [
                'state' => $nextState,
                'cancel_requested_at' => $nowUtc,
                'state_version' => (int) $task->state_version + 1,
                'updated_at' => $nowUtc,
            ];
            if ($nextState === 'cancelled') {
                $updates['lease_owner'] = null;
                $updates['lease_token'] = null;
                $updates['lease_expires_at'] = null;
                $updates['completed_at'] = $nowUtc;
            }

            AiMediaProcessingTask::query()->where('id', $task->id)->update($updates);
            $fresh = $task->fresh();

            AiMediaProcessingEvent::query()->create([
                'id' => (string) Str::uuid(),
                'task_id' => $fresh->id,
                'company_id' => $fresh->company_id,
                'state_version' => $fresh->state_version,
                'stage' => $fresh->current_stage,
                'event_type' => $nextState === 'cancelled' ? 'cancelled' : 'cancel_requested',
                'from_state' => $fromState,
                'to_state' => $nextState,
                'occurred_at_utc' => $nowUtc,
            ]);

            return $fresh;
        }, 3);
    }

    public function retryTask(
        AiPrincipal $principal,
        string $taskId,
        int $expectedVersion,
        string $operationKey,
        bool $forceReextract = false,
    ): AiMediaProcessingTask {
        if (! (bool) config('ai_multimodal.async.enabled', false)) {
            throw MultimodalException::asyncDisabled();
        }
        $queueSpec = $this->queueValidator->assertAsyncQueueReady();

        $task = DB::transaction(function () use ($principal, $taskId, $expectedVersion, $operationKey, $forceReextract): AiMediaProcessingTask {
            $task = AiMediaProcessingTask::query()->lockForUpdate()->find($taskId);
            if ($task === null) {
                throw MultimodalException::taskNotFound();
            }
            if ((int) $task->company_id !== $principal->companyId
                || (int) $task->branch_id !== $principal->branchId
                || (int) $task->financial_year_id !== $principal->financialYearId
                || (int) $task->actor_id !== $principal->actorId) {
                throw MultimodalException::crossTenantForbidden();
            }

            if ((int) $task->state_version !== $expectedVersion) {
                throw MultimodalException::taskRevisionConflict($expectedVersion, (int) $task->state_version);
            }

            if (! in_array($task->state, ['failed', 'expired', 'cancelled', 'retry_scheduled'], true)) {
                throw MultimodalException::taskNotRetryable("Task in state [{$task->state}] cannot be retried.");
            }

            if ($task->state === 'failed' && ! $task->is_retryable && ! $forceReextract) {
                throw MultimodalException::taskNotRetryable($task->error_message ?: 'This failure is non-retryable without fixing configuration or input.');
            }

            $asset = $this->loadOwnedAsset($principal, $task->media_id);
            if ($asset->isExpired()) {
                throw MultimodalException::mediaExpired();
            }

            $nowUtc = now('UTC');
            $deadlineAt = $nowUtc->copy()->addSeconds(max(120, (int) config('ai_multimodal.async.task_deadline_seconds', 600)));
            $stages = is_array($task->stages_json) ? $task->stages_json : $this->buildInitialStages($nowUtc->toIso8601String(), ! empty($asset->derivatives_json));

            $resumeStage = (! empty($asset->derivatives_json) && $asset->state !== 'uploaded') ? 'extract' : 'preprocess';
            foreach (self::STAGE_ORDER as $stageKey) {
                if ($stageKey === 'upload') {
                    continue;
                }
                if ($stageKey === 'preprocess' && $resumeStage !== 'preprocess' && ($stages['preprocess']['state'] ?? '') === 'completed') {
                    continue;
                }
                $stages[$stageKey] = [
                    'state' => 'pending',
                    'started_at_utc' => null,
                    'completed_at_utc' => null,
                    'error_code' => null,
                    'detail' => null,
                ];
            }

            $fromState = $task->state;
            $updates = [
                'state' => 'queued',
                'current_stage' => $resumeStage,
                'stages_json' => json_encode($stages, JSON_THROW_ON_ERROR),
                'force_reextract' => $forceReextract,
                'page_checkpoints_json' => $forceReextract ? null : ($task->page_checkpoints_json ? json_encode($task->page_checkpoints_json, JSON_THROW_ON_ERROR) : null),
                'error_code' => null,
                'error_message' => null,
                'failed_stage' => null,
                'is_retryable' => false,
                'next_retry_at' => null,
                'cancel_requested_at' => null,
                'completed_at' => null,
                'dispatched_at' => null,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'deadline_at' => $deadlineAt,
                'state_version' => (int) $task->state_version + 1,
                'updated_at' => $nowUtc,
            ];

            AiMediaProcessingTask::query()->where('id', $task->id)->update($updates);
            $fresh = $task->fresh();

            AiMediaProcessingEvent::query()->create([
                'id' => (string) Str::uuid(),
                'task_id' => $fresh->id,
                'company_id' => $fresh->company_id,
                'state_version' => $fresh->state_version,
                'stage' => $resumeStage,
                'event_type' => 'manual_retry_requested',
                'from_state' => $fromState,
                'to_state' => 'queued',
                'meta_json' => ['operation_key' => substr($operationKey, 0, 80), 'force_reextract' => $forceReextract],
                'occurred_at_utc' => $nowUtc,
            ]);

            return $fresh;
        }, 3);

        $this->dispatchTaskAfterCommit($task->id, $queueSpec['connection'], $queueSpec['queue']);

        return $task;
    }

    /**
     * Build sanitized snapshot DTO for GET/POST status endpoints without leaking secrets or raw binaries.
     *
     * @return array<string, mixed>
     */
    public function presentSnapshot(AiPrincipal $principal, AiMediaProcessingTask $task, bool $includeResult = true): array
    {
        $asset = $task->mediaAsset;
        $actionKey = $task->resolved_action_key ?: $task->requested_action_key;
        $isVoice = $task->source_kind === 'audio';

        $model = null;
        if (! empty($task->model_key)) {
            $model = [
                'provider' => $task->model_provider ?: (string) config('ai_multimodal.provider', 'nvidia'),
                'model_key' => $task->model_key,
                'display_name' => $this->formatModelDisplayName((string) $task->model_key, $task->model_provider),
            ];
        }

        $stageLabels = $this->buildStageLabels($actionKey, $task->is_optical_profile, $isVoice);
        $rawStages = is_array($task->stages_json) ? $task->stages_json : [];
        $stages = [];
        foreach (self::STAGE_ORDER as $key) {
            $st = is_array($rawStages[$key] ?? null) ? $rawStages[$key] : ['state' => 'pending'];
            $stages[] = [
                'key' => $key,
                'label' => $stageLabels[$key] ?? ucfirst($key),
                'state' => (string) ($st['state'] ?? 'pending'),
                'detail' => isset($st['detail']) ? (string) $st['detail'] : null,
                'page_progress' => $st['page_progress'] ?? null,
                'started_at' => $st['started_at_utc'] ?? null,
                'completed_at' => $st['completed_at_utc'] ?? null,
                'error_code' => $st['error_code'] ?? null,
            ];
        }

        $workerHealth = $this->queueValidator->workerHeartbeatStatus();

        $requiresNewModelCall = $task->extraction_id === null;
        $error = null;
        if ($task->error_code !== null || in_array($task->state, ['failed', 'expired', 'retry_scheduled'], true)) {
            $error = [
                'code' => $task->error_code ?: ($task->state === 'expired' ? 'TASK_DEADLINE_EXCEEDED' : 'MEDIA_PROCESSING_FAILED'),
                'message' => $task->error_message ?: 'Document processing could not be completed.',
                'failed_stage' => $task->failed_stage ?: $task->current_stage,
                'retryable' => (bool) $task->is_retryable,
                'correlation_id' => $task->correlation_id,
                'requires_new_model_call' => $requiresNewModelCall,
                'saved_media_available' => $asset !== null && ! $asset->isPurged() && ! $asset->isExpired(),
            ];
        }

        $result = null;
        if ($includeResult && $task->isAwaitingHuman()) {
            $result = $this->reconstructTerminalResult($principal, $task, $asset);
        }

        return [
            'processing_task_id' => $task->id,
            'task_id' => $task->id,
            'workflow_task_id' => $task->workflow_task_id,
            'media_id' => $task->media_id,
            'thread_id' => $task->thread_id,
            'client_request_id' => $task->client_request_id,
            'state' => $task->state,
            'state_version' => (int) $task->state_version,
            'stage' => $task->current_stage,
            'status_url' => route('ai.media.processing-tasks.show', ['taskId' => $task->id], false),
            'header_title' => $this->resolveHeaderTitle($actionKey, $task->state, $isVoice),
            'file_name' => $asset?->original_file_name_sanitized ?? 'document',
            'file_size' => $asset ? (int) $asset->size_bytes : 0,
            'source_kind' => $task->source_kind,
            'page_count' => $asset?->page_count,
            'preview_url' => $asset && ! $asset->isPurged() ? route('ai.media.preview', ['id' => $asset->id], false) : null,
            'is_optical_profile' => (bool) $task->is_optical_profile,
            'resolved_action_key' => $actionKey,
            'model' => $model,
            'stages' => $stages,
            'worker_health' => $workerHealth,
            'retryable' => (bool) $task->is_retryable || in_array($task->state, ['expired', 'cancelled'], true),
            'cancellable' => $task->isCancellable(),
            'attempt_count' => (int) $task->attempt_count,
            'rate_limit_deferrals' => (int) $task->rate_limit_deferrals,
            'next_retry_at' => $task->next_retry_at?->toIso8601String(),
            'deadline_at' => $task->deadline_at?->toIso8601String(),
            'poll_after_ms' => (int) config('ai_multimodal.async.poll_after_ms', 2000),
            'created_at' => $task->created_at?->setTimezone('UTC')->toIso8601String(),
            'started_at' => $task->started_at?->setTimezone('UTC')->toIso8601String(),
            'completed_at' => $task->completed_at?->setTimezone('UTC')->toIso8601String(),
            'correlation_id' => $task->correlation_id,
            'error' => $error,
            'result' => $result,
        ];
    }

    /**
     * Reconstruct existing review/clarification/decision payload from persisted DB records.
     *
     * @return ?array<string, mixed>
     */
    private function reconstructTerminalResult(AiPrincipal $principal, AiMediaProcessingTask $task, ?AiMediaAsset $asset): ?array
    {
        if ($asset === null) {
            return null;
        }

        $ref = is_array($task->result_ref_json) ? $task->result_ref_json : [];
        $extraction = $task->extraction_id ? AiMediaExtraction::query()->find($task->extraction_id) : $asset->latestExtraction;

        $mediaDto = $this->presentAssetDto($asset);
        $extractionDto = $extraction ? $this->presentExtractionDto($extraction) : null;
        $status = (string) ($ref['status'] ?? '');

        if ($status === 'voice_intent_review_ready') {
            $transcript = (string) ($extraction?->extracted_json['transcript_text'] ?? '');
            $intent = $ref['intent_key'] ?? app(VoiceIntentResolver::class)->resolve($transcript);

            return [
                'status' => 'voice_intent_review_ready',
                'thread_id' => $task->thread_id,
                'media' => $mediaDto,
                'extraction' => $extractionDto,
                'intent_key' => $intent,
                'message' => $ref['message'] ?? 'Review the transcription before starting an ERP task.',
            ];
        }

        if (in_array($status, ['voice_reply_ready', 'supplier_photo_review_ready'], true)) {
            $wfTask = $task->workflow_task_id ? $this->safeLoadWorkflowTask($principal, $task->workflow_task_id) : null;

            return [
                'status' => $status,
                'task_id' => $task->workflow_task_id,
                'task_revision' => (int) ($wfTask['revision'] ?? ($ref['task_revision'] ?? 1)),
                'thread_id' => $task->thread_id,
                'media' => $mediaDto,
                'extraction' => $extractionDto,
                'message' => $ref['message'] ?? null,
            ];
        }

        if (in_array($status, ['needs_intent_confirmation', 'source_role_conflict', 'needs_source_confirmation', 'active_task_conflict', 'readonly_summary'], true)) {
            return [
                'status' => $status,
                'media_id' => $asset->id,
                'media' => $mediaDto,
                'extraction_id' => $extraction?->id,
                'extraction' => $extractionDto,
                'action_key' => $ref['action_key'] ?? $task->resolved_action_key,
                'decision' => $ref['decision'] ?? null,
                'normalized_input' => $ref['normalized_input'] ?? ($extraction?->normalized_input_json),
                'message' => $ref['message'] ?? null,
            ];
        }

        if ($status === 'incomplete_extraction') {
            return [
                'status' => 'incomplete_extraction',
                'media_id' => $asset->id,
                'media' => $mediaDto,
                'extraction_id' => $extraction?->id,
                'extraction' => $extractionDto,
                'message' => $ref['message'] ?? 'Some document pages could not be extracted. Review the extracted pages carefully before proceeding.',
            ];
        }

        $actionKey = (string) ($ref['action_key'] ?? $task->resolved_action_key ?? '');
        $wfTask = $task->workflow_task_id ? $this->safeLoadWorkflowTask($principal, $task->workflow_task_id) : null;
        if ($wfTask !== null) {
            foreach (['slots_json' => 'slots', 'resolution_json' => 'resolution', 'issues_json' => 'issues', 'provenance_json' => 'provenance'] as $src => $dest) {
                if (isset($wfTask[$src]) && is_string($wfTask[$src])) {
                    $wfTask[$dest] = json_decode($wfTask[$src], true) ?? [];
                }
            }
        }

        $proposalDto = null;
        if (! empty($task->proposal_id)) {
            try {
                $proposal = $this->proposalService->getOwned($principal, (string) $task->proposal_id);
                $preview = json_decode((string) ($proposal['preview_json'] ?? '{}'), true) ?? [];
                $proposalDto = [
                    'proposal_id' => (string) $proposal['id'],
                    'version' => (int) ($proposal['version'] ?? 1),
                    'action_key' => (string) $proposal['action_key'],
                    'status' => (string) ($proposal['status'] ?? 'pending'),
                    'preview' => $preview,
                ];
            } catch (Throwable) {
                $proposalDto = $ref['proposal'] ?? null;
            }
        }

        return [
            'status' => $status !== '' ? $status : ($proposalDto !== null ? 'proposal_created' : 'needs_input'),
            'media_id' => $asset->id,
            'media' => $mediaDto,
            'extraction_id' => $extraction?->id,
            'extraction' => $extractionDto,
            'action_key' => $actionKey !== '' ? $actionKey : null,
            'task_id' => $task->workflow_task_id,
            'task' => $wfTask,
            'proposal' => $proposalDto,
            'clarification' => $ref['clarification'] ?? null,
            'normalized_input' => $ref['normalized_input'] ?? ($extraction?->normalized_input_json),
            'message' => $ref['message'] ?? null,
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function safeLoadWorkflowTask(AiPrincipal $principal, string $taskId): ?array
    {
        try {
            return $this->workflowTasks->getTask($principal, $taskId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAssetDto(AiMediaAsset $asset): array
    {
        $pages = [];
        foreach ((array) $asset->derivatives_json as $idx => $deriv) {
            $pageNum = (int) ($deriv['page'] ?? ($idx + 1));
            $pages[] = [
                'page' => $pageNum,
                'mime' => $deriv['mime'] ?? $asset->canonical_mime,
                'width' => $deriv['width'] ?? null,
                'height' => $deriv['height'] ?? null,
                'preview_url' => route('ai.media.preview', ['id' => $asset->id, 'page' => $pageNum], false),
            ];
        }

        return [
            'id' => $asset->id,
            'source_kind' => $asset->source_kind,
            'file_name' => $asset->original_file_name_sanitized,
            'canonical_mime' => $asset->canonical_mime,
            'size_bytes' => (int) $asset->size_bytes,
            'sha256' => $asset->sha256,
            'page_count' => $asset->page_count,
            'duration_seconds' => $asset->duration_seconds,
            'state' => $asset->state,
            'last_error_code' => $asset->last_error_code,
            'expires_at' => $asset->expires_at?->toIso8601String(),
            'workflow_task_id' => $asset->workflow_task_id,
            'preview_url' => route('ai.media.preview', ['id' => $asset->id], false),
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentExtractionDto(AiMediaExtraction $extraction): array
    {
        return [
            'id' => $extraction->id,
            'media_id' => $extraction->media_id,
            'revision' => (int) $extraction->revision,
            'model_provider' => $extraction->model_provider,
            'model_key' => $extraction->model_key,
            'prompt_version' => $extraction->prompt_version,
            'schema_version' => $extraction->extraction_schema_version,
            'confirmation_status' => $extraction->confirmation_status,
            'confirmed_at' => $extraction->confirmed_at?->toIso8601String(),
            'extracted' => $extraction->extracted_json,
            'normalized_input' => $extraction->normalized_input_json,
            'source_evidence' => $extraction->source_evidence_json,
        ];
    }

    private function loadOwnedAsset(AiPrincipal $principal, string $id): AiMediaAsset
    {
        $asset = AiMediaAsset::query()->where('id', $id)->first();
        if ($asset === null) {
            throw MultimodalException::mediaNotFound();
        }
        if (! $this->assetPolicy->canAccessPrincipal($principal, $asset)) {
            throw MultimodalException::crossTenantForbidden();
        }
        if ($asset->isPurged()) {
            throw MultimodalException::mediaNotFound();
        }

        return $asset;
    }

    private function normalizeClientRequestId(mixed $raw): string
    {
        if (is_string($raw) && trim($raw) !== '') {
            return substr(trim($raw), 0, 120);
        }

        return (string) Str::uuid();
    }

    /**
     * @param array<string, mixed> $components
     */
    private function computeRequestHash(array $components): string
    {
        return hash('sha256', json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function resolveIsOpticalProfile(AiPrincipal $principal): bool
    {
        try {
            $ctx = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);

            return app(IndustryContextResolver::class)->isOptical($ctx);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildInitialStages(string $nowUtcIso, bool $alreadyPreprocessed): array
    {
        $stages = [];
        foreach (self::STAGE_ORDER as $key) {
            $stages[$key] = [
                'state' => 'pending',
                'started_at_utc' => null,
                'completed_at_utc' => null,
                'error_code' => null,
                'detail' => null,
            ];
        }

        $stages['upload'] = [
            'state' => 'completed',
            'started_at_utc' => $nowUtcIso,
            'completed_at_utc' => $nowUtcIso,
            'error_code' => null,
            'detail' => 'Private attachment stored',
        ];

        if ($alreadyPreprocessed) {
            $stages['preprocess'] = [
                'state' => 'completed',
                'started_at_utc' => $nowUtcIso,
                'completed_at_utc' => $nowUtcIso,
                'error_code' => null,
                'detail' => 'Existing optimized file reused',
            ];
        }

        return $stages;
    }

    private function resolveHeaderTitle(?string $actionKey, string $state, bool $isVoice): string
    {
        if ($isVoice) {
            return $state === 'needs_confirmation' ? 'Voice transcript ready for review' : 'Preparing your voice instruction';
        }

        return match ($actionKey) {
            'sales_invoice.create.v1' => 'Preparing your sales invoice',
            'sales_order.create.v1' => 'Preparing your sales order',
            'purchase_bill.create.v1' => 'Preparing your purchase bill',
            'purchase_order.create.v1' => 'Preparing your purchase order',
            'delivery_challan.create.v1' => 'Preparing your delivery challan',
            'quotation.create.v1' => 'Preparing your quotation',
            'query_readonly' => 'Summarizing uploaded document',
            default => 'Preparing your document',
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildStageLabels(?string $actionKey, bool $isOptical, bool $isVoice): array
    {
        if ($isVoice) {
            return [
                'upload' => 'Voice note uploaded',
                'preprocess' => 'Audio normalized and validated',
                'extract' => 'Transcribing voice instruction',
                'resolve' => 'Checking workflow context',
                'validate' => 'Checking draft compatibility',
                'prepare' => 'Preparing transcript review',
            ];
        }

        $itemNoun = $isOptical ? 'lenses' : 'products';
        $isSales = in_array($actionKey, ['sales_invoice.create.v1', 'sales_order.create.v1', 'delivery_challan.create.v1', 'quotation.create.v1'], true);
        $isPurchase = in_array($actionKey, ['purchase_bill.create.v1', 'purchase_order.create.v1'], true);

        $resolveLabel = 'Resolving party and ' . $itemNoun;
        if ($isSales) {
            $resolveLabel = 'Resolving customer and ' . $itemNoun;
        } elseif ($isPurchase) {
            $resolveLabel = 'Resolving supplier and ' . $itemNoun;
        }

        $prepareLabel = match ($actionKey) {
            'sales_invoice.create.v1' => 'Preparing sales invoice review',
            'sales_order.create.v1' => 'Preparing sales order review',
            'purchase_bill.create.v1' => 'Preparing purchase bill review',
            'purchase_order.create.v1' => 'Preparing purchase order review',
            default => 'Preparing ERP draft review',
        };

        return [
            'upload' => 'Attachment uploaded',
            'preprocess' => 'File optimized and validated',
            'extract' => $isOptical ? 'Extracting optical document details' : 'Extracting document details',
            'resolve' => $resolveLabel,
            'validate' => 'Checking stock, GST and pricing',
            'prepare' => $prepareLabel,
        ];
    }

    private function formatModelDisplayName(string $modelKey, ?string $provider): string
    {
        $clean = strtolower($modelKey);
        if (str_contains($clean, 'nemotron-3-nano-omni') || str_contains($clean, 'nano-omni')) {
            return 'Nemotron Omni';
        }
        if (str_contains($clean, 'nemotron-3.5-lightning') || str_contains($clean, 'lightning')) {
            return 'Nemotron 3.5 Lightning';
        }

        $parts = explode('/', $modelKey);

        return ucwords(str_replace(['-', '_'], ' ', end($parts) ?: ($provider ?: 'Perception')));
    }
}
