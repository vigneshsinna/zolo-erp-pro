<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use Throwable;

/**
 * Authoritative gateway interceptor executing all ERP AI tools.
 * Enforces:
 *  1. Tool discovery check
 *  2. Principal authorization re-validation
 *  3. Schema validation
 *  4. Domain execution
 *  5. Telemetry & redacted audit recording
 */
class ErpToolExecutor
{
    public function __construct(
        protected AiToolManifest $manifest,
        protected AiPrincipalAuthorizerInterface $authorizer,
        protected AiAuditRecorder $auditRecorder
    ) {}

    /**
     * Execute a tool safely through the authorization and audit guard.
     */
    public function execute(
        string $runId,
        AiPrincipal $principal,
        string $toolKey,
        array $rawInput
    ): ErpToolResult {
        $startTime = hrtime(true);

        // 1. Tool existence
        if (!$this->manifest->has($toolKey)) {
            $result = ErpToolResult::denied($toolKey, "Tool [{$toolKey}] is not recognized.", 'AI_TOOL_NOT_FOUND');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        $handler = $this->manifest->get($toolKey);

        // 2. Authorize principal
        if (!$this->authorizer->authorize($principal, $toolKey) || !$handler->authorize($principal, $rawInput)) {
            $result = ErpToolResult::denied($toolKey, "Principal is not authorized to execute tool [{$toolKey}].", 'AI_TOOL_FORBIDDEN');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 3. Execute domain service
        try {
            $result = $handler->execute($principal, $rawInput);
        } catch (Throwable $e) {
            $result = ErpToolResult::error($toolKey, 'Domain service execution failed: ' . $e->getMessage(), 'AI_DOMAIN_EXECUTION_FAILURE');
        }

        // 4. Audit & Telemetry
        $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);

        return $result;
    }

    /**
     * Helper to measure duration and log audit safely.
     */
    protected function recordTelemetry(
        string $runId,
        string $toolKey,
        array $rawInput,
        ErpToolResult $result,
        int|float $startTime
    ): void {
        $endTime = hrtime(true);
        $durationMs = (int) round(($endTime - $startTime) / 1e6);

        $this->auditRecorder->recordInvocation(
            runId: $runId,
            toolKey: $toolKey,
            input: $rawInput,
            result: $result,
            durationMs: $durationMs
        );
    }
}

