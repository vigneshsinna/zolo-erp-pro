<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Multimodal\Exceptions\MultimodalException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Validates dedicated async media queue configuration (never allowing silent sync fallback)
 * and tracks/reads worker heartbeat health.
 */
final class MediaQueueConfigValidator
{
    public function resolveConnection(): string
    {
        return trim((string) config('ai_multimodal.async.queue_connection', 'ai_media_database'));
    }

    public function resolveQueueName(): string
    {
        $name = trim((string) config('ai_multimodal.async.queue_name', 'ai-perception'));

        return $name !== '' ? $name : 'ai-perception';
    }

    /**
     * Assert that async media queue configuration is non-sync and retry_after exceeds job timeout.
     *
     * @return array{connection: string, driver: string, queue: string, retry_after: int, job_timeout: int}
     */
    public function assertAsyncQueueReady(): array
    {
        $connection = $this->resolveConnection();
        if ($connection === '') {
            throw MultimodalException::asyncQueueUnavailable('Async media queue connection is not configured.');
        }

        $connConfig = config("queue.connections.{$connection}");
        if (! is_array($connConfig)) {
            throw MultimodalException::asyncQueueUnavailable("Async media queue connection [{$connection}] is not defined in config/queue.php.");
        }

        $driver = strtolower(trim((string) ($connConfig['driver'] ?? '')));
        if ($driver === '' || $driver === 'sync' || $driver === 'null') {
            throw MultimodalException::asyncQueueUnavailable("Async media preparation cannot run on [{$driver}] queue driver; configure an asynchronous connection.");
        }

        $jobTimeout = max(30, (int) config('ai_multimodal.async.job_timeout_seconds', 180));
        $retryAfter = isset($connConfig['retry_after']) ? (int) $connConfig['retry_after'] : 0;

        if (in_array($driver, ['database', 'redis', 'beanstalkd'], true) && $retryAfter <= $jobTimeout) {
            throw MultimodalException::asyncQueueUnavailable(
                "Queue connection [{$connection}] retry_after ({$retryAfter}s) must exceed job_timeout_seconds ({$jobTimeout}s)."
            );
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'queue' => $this->resolveQueueName(),
            'retry_after' => $retryAfter,
            'job_timeout' => $jobTimeout,
        ];
    }

    public function recordWorkerHeartbeat(?string $connection = null, ?string $queue = null, ?string $workerId = null): void
    {
        $conn = $connection ?: $this->resolveConnection();
        $q = $queue ?: $this->resolveQueueName();
        $ttl = max(30, (int) config('ai_multimodal.async.worker_heartbeat_ttl_seconds', 120));

        try {
            Cache::put($this->heartbeatCacheKey($conn, $q), [
                'last_seen_at' => now('UTC')->toIso8601String(),
                'worker_id' => $workerId ?: (gethostname() . ':' . getmypid()),
                'connection' => $conn,
                'queue' => $q,
            ], now('UTC')->addSeconds($ttl * 3));
        } catch (Throwable) {
            // Best-effort heartbeat telemetry; never crash worker processing.
        }
    }

    /**
     * @return array{healthy: bool, stale: bool, last_seen_at: ?string, age_seconds: ?int}
     */
    public function workerHeartbeatStatus(?string $connection = null, ?string $queue = null): array
    {
        $conn = $connection ?: $this->resolveConnection();
        $q = $queue ?: $this->resolveQueueName();
        $ttl = max(30, (int) config('ai_multimodal.async.worker_heartbeat_ttl_seconds', 120));

        try {
            $payload = Cache::get($this->heartbeatCacheKey($conn, $q));
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload) || empty($payload['last_seen_at'])) {
            return [
                'healthy' => false,
                'stale' => true,
                'last_seen_at' => null,
                'age_seconds' => null,
            ];
        }

        try {
            $lastSeen = CarbonImmutable::parse((string) $payload['last_seen_at'], 'UTC');
            $ageSeconds = max(0, (int) $lastSeen->diffInSeconds(now('UTC'), false));
            $healthy = $ageSeconds <= $ttl;

            return [
                'healthy' => $healthy,
                'stale' => ! $healthy,
                'last_seen_at' => $lastSeen->toIso8601String(),
                'age_seconds' => $ageSeconds,
            ];
        } catch (Throwable) {
            return [
                'healthy' => false,
                'stale' => true,
                'last_seen_at' => null,
                'age_seconds' => null,
            ];
        }
    }

    private function heartbeatCacheKey(string $connection, string $queue): string
    {
        return "ai:media_worker:heartbeat:{$connection}:{$queue}";
    }
}
