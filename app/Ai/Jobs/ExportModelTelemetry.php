<?php

declare(strict_types=1);

namespace App\Ai\Jobs;

use App\Ai\Telemetry\LangfuseTelemetrySink;
use App\Ai\Telemetry\ModelTelemetry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExportModelTelemetry implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;
    public int $timeout = 10;

    public function __construct(public readonly ModelTelemetry $event)
    {
        $this->onConnection(config('ai_infrastructure.queue_connection', 'redis'))->onQueue('ai-telemetry')->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(LangfuseTelemetrySink $sink): void
    {
        $sink->send($this->event);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('AI telemetry export exhausted bounded retries.', ['trace_id' => $this->event->traceId,
            'exception_type' => $exception ? $exception::class : null]);
    }
}
