<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Infrastructure\McpClientGrant;
use App\Ai\Services\ActionPreparationService;
use App\Ai\Services\AiAuditRecorder;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Common channel-aware capability dispatcher.
 * Re-authorizes every invocation independently and routes strictly by operation:
 *  - read    -> ErpToolExecutor (read-only preserved)
 *  - prepare -> ActionPreparationService (draft/proposal only)
 *  - status  -> ProposalStatusReader (scoped read-only status)
 * Never approves, posts, sends, or reverses ERP records.
 */
final class CapabilityDispatcher
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
        private readonly CapabilityExposurePolicy $policy,
        private readonly CapabilitySchemaCompiler $compiler,
        private readonly ErpToolExecutor $readExecutor,
        private readonly ActionPreparationService $preparation,
        private readonly ProposalStatusReader $statusReader,
        private readonly AiAuditRecorder $auditRecorder,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function dispatch(
        AiPrincipal $principal,
        string $channel,
        string $capabilityKey,
        string $operation,
        array $arguments,
        ?McpClientGrant $grant = null,
        ?string $runId = null,
    ): array {
        $start = hrtime(true);
        $effectiveRunId = $runId ?: ($principal->requestId !== '' ? $principal->requestId : (string) Str::uuid());

        if (!in_array($operation, CapabilityIntegrityValidator::VALID_OPERATIONS, true)) {
            $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'denied', 'CAPABILITY_OPERATION_FORBIDDEN', $start);
            throw new AuthorizationException('Requested capability operation is not permitted.');
        }

        $descriptor = $this->catalog->find($capabilityKey, $operation);
        if (!$descriptor || !$this->policy->authorize($principal, $channel, $descriptor, $grant)) {
            $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'denied', 'CAPABILITY_UNAUTHORIZED', $start);
            throw new AuthorizationException('Capability is unavailable or unauthorized.');
        }

        if ($operation === 'read') {
            $this->compiler->assertNoAuthorityFields($arguments);
            $result = $this->readExecutor->execute($effectiveRunId, $principal, $capabilityKey, $arguments);
            return $result->toArray();
        }

        if ($operation === 'prepare') {
            $normalized = $this->compiler->normalizeAndValidateDraftInput($descriptor, $arguments, $channel);
            $taskId = isset($normalized['task_id']) && is_string($normalized['task_id']) && $normalized['task_id'] !== ''
                ? $normalized['task_id']
                : null;
            $expectedRevision = isset($normalized['expected_revision']) ? (int) $normalized['expected_revision'] : null;
            unset($normalized['task_id'], $normalized['expected_revision']);

            try {
                $outcome = $this->preparation->prepare(
                    principal: $principal,
                    key: $capabilityKey,
                    inputs: $normalized,
                    taskId: $taskId,
                    expectedRevision: $expectedRevision,
                    sourceChannel: $channel,
                    sourceClientBindingId: $grant?->bindingId,
                    threadId: $principal->threadId,
                );
                $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'authorized', null, $start);

                return $outcome;
            } catch (Throwable $e) {
                $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'failed', $e::class, $start);
                throw $e;
            }
        }

        // operation === 'status'
        $this->compiler->assertNoAuthorityFields($arguments);
        $allowedStatusKeys = ['proposal_id', 'task_id'];
        if (array_diff(array_keys($arguments), $allowedStatusKeys) !== []) {
            $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'denied', 'INVALID_STATUS_PARAMS', $start);
            throw new AuthorizationException('Unexpected parameters for status lookup.');
        }

        try {
            $status = $this->statusReader->read(
                $principal,
                isset($arguments['proposal_id']) ? (string) $arguments['proposal_id'] : null,
                isset($arguments['task_id']) ? (string) $arguments['task_id'] : null,
            );
            $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'authorized', null, $start);

            return $status;
        } catch (Throwable $e) {
            $this->recordAudit($effectiveRunId, $capabilityKey, $channel, $operation, 'denied', $e::class, $start);
            throw $e;
        }
    }

    private function recordAudit(
        string $runId,
        string $capabilityKey,
        string $channel,
        string $operation,
        string $decision,
        ?string $errorCode,
        int|float $start,
    ): void {
        if ($operation === 'read') {
            return;
        }

        try {
            if (!function_exists('app') || !app()->bound('db') || !Schema::hasTable('ai_tool_invocations')) {
                return;
            }
            $durationMs = (int) round((hrtime(true) - $start) / 1e6);
            $meta = $this->auditRecorder->redact([
                'channel' => $channel,
                'operation' => $operation,
                'catalog_version' => $this->catalog->versionHash(),
            ]);

            DB::table('ai_tool_invocations')->insert([
                'id' => (string) Str::uuid(),
                'run_id' => $runId,
                'tool_key' => $capabilityKey,
                'schema_version' => 'v1',
                'scope_hash' => hash('sha256', json_encode($meta, JSON_THROW_ON_ERROR)),
                'input_redacted' => json_encode($meta, JSON_THROW_ON_ERROR),
                'output_ref' => null,
                'authorization_decision' => $decision === 'authorized' ? 'authorized' : 'denied',
                'status' => $decision === 'authorized' ? 'ok' : ($decision === 'denied' ? 'denied' : 'error'),
                'duration_ms' => max(0, $durationMs),
                'error_code' => $errorCode ? Str::limit(class_basename($errorCode), 80, '') : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit persistence failure must not leak internal state or crash caller.
        }
    }
}
