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

    /**
     * Record a structured operational metric event (latency, error, rate limit, candidate ambiguity, etc.)
     * Uses pseudonymized hashes for tenant and actor identifiers.
     *
     * @param array<string, mixed> $payload
     */
    public function recordEvent(string $event, array $payload = [], ?AiPrincipal $principal = null): void
    {
        $salt = (string) config('app.key', 'zolo_telemetry_salt');
        $hash = static fn (string $v): string => hash_hmac('sha256', $v, $salt);

        $traceId = $principal?->requestId
            ?? request()?->attributes->get('erp.correlation_id')
            ?? request()?->header('X-Correlation-ID')
            ?? request()?->header('X-Request-ID')
            ?? 'untracked';

        $structured = [
            'trace_id' => $traceId,
            'event' => $event,
            'timestamp' => now('UTC')->toIso8601String(),
            'company_hash' => $principal ? substr($hash('company:' . $principal->companyId), 0, 16) : null,
            'actor_hash' => $principal ? substr($hash('actor:' . $principal->actorId), 0, 16) : null,
            'duration_ms' => $payload['duration_ms'] ?? null,
            'status' => $payload['status'] ?? 'ok',
            'error_code' => $payload['error_code'] ?? null,
            'metadata' => array_diff_key($payload, array_flip(['duration_ms', 'status', 'error_code'])),
        ];

        Log::info('ai.metrics', $structured);
    }

    public function recordLatency(string $operation, int $durationMs, ?AiPrincipal $principal = null, array $meta = []): void
    {
        $this->recordEvent('ai.latency', array_merge($meta, [
            'operation' => $operation,
            'duration_ms' => $durationMs,
        ]), $principal);
    }

    public function recordRateLimit(string $provider, ?AiPrincipal $principal = null): void
    {
        $this->recordEvent('ai.rate_limit', [
            'provider' => $provider,
            'status' => 'throttled',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
        ], $principal);
    }

    public function recordCandidateAmbiguity(string $field, int $candidateCount, ?AiPrincipal $principal = null): void
    {
        $this->recordEvent('ai.candidate_ambiguity', [
            'field' => $field,
            'candidate_count' => $candidateCount,
            'status' => 'ambiguous',
        ], $principal);
    }

    public function recordApprovalDecision(string $actionKey, string $proposalId, string $status, ?AiPrincipal $principal = null): void
    {
        $this->recordEvent('ai.proposal.' . $status, [
            'action_key' => $actionKey,
            'proposal_id' => $proposalId,
            'status' => $status,
        ], $principal);
    }

    public function recordIdempotentRecovery(string $scope, string $idempotencyKey, ?AiPrincipal $principal = null): void
    {
        $this->recordEvent('ai.idempotent_recovery', [
            'scope' => $scope,
            'idempotency_key' => $idempotencyKey,
            'status' => 'recovered',
        ], $principal);
    }
}
