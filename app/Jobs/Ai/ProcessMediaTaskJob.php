<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Multimodal\Services\MediaProcessingOrchestrator;
use App\Ai\Multimodal\Services\MediaProcessingTaskService;
use App\Ai\Multimodal\Services\MediaQueueConfigValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dedicated async worker job for media preprocessing, perception extraction, and ERP draft preparation.
 * Retry policy is owned by MediaProcessingOrchestrator ($tries = 1 prevents retry multiplication).
 */
final class ProcessMediaTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $taskId)
    {
        $this->timeout = max(30, (int) config('ai_multimodal.async.job_timeout_seconds', 180));
    }

    public function handle(
        MediaProcessingTaskService $taskService,
        MediaProcessingOrchestrator $orchestrator,
        MediaQueueConfigValidator $queueValidator,
    ): void {
        $queueValidator->recordWorkerHeartbeat();

        $workerOwner = (gethostname() ?: 'worker') . ':' . getmypid();
        $claimed = $taskService->claimLease($this->taskId, $workerOwner);
        if ($claimed === null) {
            return;
        }

        $orchestrator->process($claimed);
    }
}
