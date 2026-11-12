<?php

declare(strict_types=1);

namespace App\Ai\Telemetry;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Jobs\ExportModelTelemetry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelemetryDispatcher
{
    public function recordModel(
        AiPrincipal $principal, int $step, string $provider, string $model, string $status,
        int $input, int $output, int $durationMs, ?int $attempts = null,
    ): void {
        if (!config('ai_infrastructure.langfuse.enabled', false)) {
            return;
        }
        try {
            $connection = config('ai_infrastructure.queue_connection', 'redis');
            if (config("queue.connections.{$connection}.driver") === 'sync') {
                throw new \LogicException('External telemetry requires an isolated asynchronous queue.');
            }
            Bus::dispatch(new ExportModelTelemetry(ModelTelemetry::fromInvocation($principal, $step, $provider, $model, $status, $input, $output, $durationMs, $attempts)));
        } catch (Throwable $error) {
            // Business audit and provider responses remain independent of optional external telemetry.
            Log::warning('AI telemetry export could not be queued.', ['exception_type' => $error::class]);
        }
    }
}
