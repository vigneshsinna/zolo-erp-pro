<?php

declare(strict_types=1);

namespace App\Ai\Scheduling;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

final class RunScheduledRule implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId, public readonly int $companyId)
    {
        $this->onConnection(config('ai_infrastructure.queue_connection', 'redis'))->onQueue('ai-scheduled')->afterCommit();
    }

    public function handle(ScheduledRuleRunner $runner): void
    {
        $runner->run($this->companyId, $this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ScheduledRuleRunner::class)->fail($this->companyId, $this->runId, 'AI_SCHEDULE_WORKER_FAILED');
    }
}
