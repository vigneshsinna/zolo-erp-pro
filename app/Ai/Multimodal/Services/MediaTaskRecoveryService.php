<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Billing\Services\AiCreditReconciliationService;
use App\Models\AiMediaProcessingEvent;
use App\Models\AiMediaProcessingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconciles stranded outbox dispatches, expired worker leases, crashed provider attempts,
 * task deadlines, and stale credit reservations.
 */
final class MediaTaskRecoveryService
{
    public function __construct(
        private readonly MediaProcessingTaskService $taskService,
        private readonly AiCreditReconciliationService $creditReconciliation,
    ) {}

    /**
     * @return array{redispatched: int, leases_recovered: int, expired: int, credits_reconciled: int}
     */
    public function reconcile(): array
    {
        $nowUtc = now('UTC');
        $outboxCutoff = $nowUtc->copy()->subSeconds(max(5, (int) config('ai_multimodal.async.outbox_dispatch_grace_seconds', 15)));

        $redispatched = 0;
        $leasesRecovered = 0;
        $expired = 0;

        // 1. Outbox recovery: queued tasks committed without dispatched_at
        $undispatched = AiMediaProcessingTask::query()
            ->where('state', 'queued')
            ->whereNull('dispatched_at')
            ->where('created_at', '<=', $outboxCutoff)
            ->limit(100)
            ->get();

        foreach ($undispatched as $task) {
            if ($task->deadline_at !== null && $task->deadline_at->isPast()) {
                $this->expireTask($task, $nowUtc);
                $expired++;
                continue;
            }
            $this->taskService->dispatchTaskAfterCommit($task->id);
            $redispatched++;
        }

        // 2. Stale lease recovery (worker killed or timed out)
        $staleTasks = AiMediaProcessingTask::query()
            ->whereIn('state', ['processing', 'cancel_requested'])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $nowUtc)
            ->limit(100)
            ->get();

        foreach ($staleTasks as $stale) {
            $recovered = DB::transaction(function () use ($stale, $nowUtc, &$expired): bool {
                $locked = AiMediaProcessingTask::query()->lockForUpdate()->find($stale->id);
                if ($locked === null || ! in_array($locked->state, ['processing', 'cancel_requested'], true)) {
                    return false;
                }
                if ($locked->lease_expires_at === null || $locked->lease_expires_at->isFuture()) {
                    return false;
                }

                // Mark any in-flight 'sent' attempts as uncertain and hold their reservations for reconciliation
                $sentAttempts = DB::table('ai_media_provider_attempts')
                    ->where('task_id', $locked->id)
                    ->where('status', 'sent')
                    ->get();

                $hasUncertainAttempt = false;
                foreach ($sentAttempts as $att) {
                    $hasUncertainAttempt = true;
                    DB::table('ai_media_provider_attempts')
                        ->where('id', $att->id)
                        ->update(['status' => 'uncertain', 'updated_at' => $nowUtc]);

                    if (! empty($att->reservation_id)) {
                        DB::table('ai_credit_reservations')
                            ->where('id', $att->reservation_id)
                            ->where('status', 'active')
                            ->update(['status' => 'unknown_billability', 'updated_at' => now()]);
                    }
                }

                if ($locked->state === 'cancel_requested' || $locked->cancel_requested_at !== null) {
                    $this->finalizeRecoveredTask($locked, 'cancelled', 'TASK_CANCELLED', 'Processing cancelled by user.', true, 'task_cancelled_on_recovery', $nowUtc);
                    return true;
                }

                // Crash after proposal already committed -> complete cleanly without duplicate proposal
                if (! empty($locked->proposal_id)) {
                    $stages = is_array($locked->stages_json) ? $locked->stages_json : [];
                    $stages['prepare'] = array_merge($stages['prepare'] ?? [], [
                        'state' => 'completed',
                        'completed_at_utc' => $nowUtc->toIso8601String(),
                        'detail' => 'Recovered persisted draft',
                    ]);
                    AiMediaProcessingTask::query()->where('id', $locked->id)->update([
                        'state' => 'review_ready',
                        'current_stage' => 'prepare',
                        'stages_json' => json_encode($stages, JSON_THROW_ON_ERROR),
                        'lease_owner' => null,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'completed_at' => $nowUtc,
                        'state_version' => (int) $locked->state_version + 1,
                        'updated_at' => $nowUtc,
                    ]);
                    $this->recordRecoveryEvent($locked, 'processing', 'review_ready', 'recovered_persisted_proposal', $nowUtc);
                    return true;
                }

                if ($locked->deadline_at !== null && $locked->deadline_at->isPast()) {
                    $this->finalizeRecoveredTask($locked, 'expired', 'TASK_DEADLINE_EXCEEDED', 'Processing deadline exceeded.', true, 'deadline_exceeded', $nowUtc);
                    $expired++;
                    return true;
                }

                if ($hasUncertainAttempt) {
                    $this->finalizeRecoveredTask(
                        $locked,
                        'failed',
                        'WORKER_CRASHED_PROVIDER_UNCERTAIN',
                        'Worker interrupted while waiting on perception provider. Verify usage or click Retry Extraction.',
                        true,
                        'worker_crashed_uncertain_attempt',
                        $nowUtc,
                    );
                    return true;
                }

                // Safe to re-queue; already extracted pages/records will be reused idempotently
                AiMediaProcessingTask::query()->where('id', $locked->id)->update([
                    'state' => 'queued',
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'dispatched_at' => null,
                    'state_version' => (int) $locked->state_version + 1,
                    'updated_at' => $nowUtc,
                ]);
                $this->recordRecoveryEvent($locked, 'processing', 'queued', 'stale_lease_requeued', $nowUtc);

                return true;
            }, 3);

            if ($recovered) {
                $leasesRecovered++;
                $fresh = $stale->fresh();
                if ($fresh && $fresh->state === 'queued') {
                    $this->taskService->dispatchTaskAfterCommit($fresh->id);
                }
            }
        }

        // 3. Deadline expiry for queued / retry_scheduled tasks
        $overdue = AiMediaProcessingTask::query()
            ->whereIn('state', ['queued', 'retry_scheduled'])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', $nowUtc)
            ->limit(100)
            ->get();

        foreach ($overdue as $task) {
            if ($this->expireTask($task, $nowUtc)) {
                $expired++;
            }
        }

        // 4. Credit reservation reconciliation
        $creditSweep = $this->creditReconciliation->sweepExpiredReservations();

        return [
            'redispatched' => $redispatched,
            'leases_recovered' => $leasesRecovered,
            'expired' => $expired,
            'credits_reconciled' => (int) ($creditSweep['reconciled_count'] ?? 0),
        ];
    }

    private function expireTask(AiMediaProcessingTask $task, \Carbon\CarbonInterface $nowUtc): bool
    {
        return DB::transaction(function () use ($task, $nowUtc): bool {
            $locked = AiMediaProcessingTask::query()->lockForUpdate()->find($task->id);
            if ($locked === null || ! in_array($locked->state, ['queued', 'retry_scheduled'], true)) {
                return false;
            }
            $this->finalizeRecoveredTask($locked, 'expired', 'TASK_DEADLINE_EXCEEDED', 'Processing exceeded the allowed deadline.', true, 'deadline_exceeded', $nowUtc);

            return true;
        }, 3);
    }

    private function finalizeRecoveredTask(
        AiMediaProcessingTask $locked,
        string $toState,
        string $errorCode,
        string $errorMessage,
        bool $isRetryable,
        string $eventType,
        \Carbon\CarbonInterface $nowUtc,
    ): void {
        $fromState = $locked->state;
        $nextVer = (int) $locked->state_version + 1;
        $stages = is_array($locked->stages_json) ? $locked->stages_json : [];
        $stageKey = $locked->current_stage ?: 'extract';
        $stages[$stageKey] = array_merge($stages[$stageKey] ?? [], [
            'state' => 'failed',
            'error_code' => $errorCode,
            'detail' => $errorMessage,
        ]);

        AiMediaProcessingTask::query()->where('id', $locked->id)->update([
            'state' => $toState,
            'stages_json' => json_encode($stages, JSON_THROW_ON_ERROR),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'failed_stage' => $stageKey,
            'is_retryable' => $isRetryable,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'completed_at' => $nowUtc,
            'state_version' => $nextVer,
            'updated_at' => $nowUtc,
        ]);

        AiMediaProcessingEvent::query()->create([
            'id' => (string) Str::uuid(),
            'task_id' => $locked->id,
            'company_id' => $locked->company_id,
            'state_version' => $nextVer,
            'stage' => $stageKey,
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $toState,
            'error_code' => $errorCode,
            'occurred_at_utc' => $nowUtc,
        ]);
    }

    private function recordRecoveryEvent(
        AiMediaProcessingTask $locked,
        string $fromState,
        string $toState,
        string $eventType,
        \Carbon\CarbonInterface $nowUtc,
    ): void {
        AiMediaProcessingEvent::query()->create([
            'id' => (string) Str::uuid(),
            'task_id' => $locked->id,
            'company_id' => $locked->company_id,
            'state_version' => (int) $locked->state_version + 1,
            'stage' => $locked->current_stage ?: 'extract',
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $toState,
            'occurred_at_utc' => $nowUtc,
        ]);
    }
}
