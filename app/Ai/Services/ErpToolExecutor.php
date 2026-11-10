<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Authoritative gateway interceptor executing all ERP AI tools.
 * Enforces:
 *  1. Principal revalidation from persistence/tenancy
 *  2. Tool discovery check
 *  3. Global & per-company AI feature enablement & read-only guard
 *  4. Authorization check against principal allowlist & handler capabilities
 *  5. JSON schema input validation & parameter bounding
 *  6. Safe domain execution with internal exception redaction
 *  7. Telemetry & redacted audit persistence
 */
class ErpToolExecutor
{
    private array $toolAttempts = [];

    public function __construct(
        protected AiToolManifest $manifest,
        protected AiPrincipalAuthorizerInterface $authorizer,
        protected AiAuditRecorder $auditRecorder,
        protected AiFeatureGate $featureGate,
        protected int $maxToolsPerRun = 3,
    ) {}

    /**
     * Execute a tool safely through the authorization, feature gate, and audit guard.
     */
    public function execute(
        string $runId,
        AiPrincipal $principal,
        string $toolKey,
        array $rawInput
    ): ErpToolResult {
        $startTime = hrtime(true);

        // 1. Live Principal Revalidation
        $principal = $this->authorizer->refresh($principal);
        if (!$principal->isActive) {
            $result = ErpToolResult::denied($toolKey, 'Principal is inactive, deleted, or unauthorized.', 'AI_PRINCIPAL_INACTIVE');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 2. Tool existence
        if (!$this->manifest->has($toolKey)) {
            $result = ErpToolResult::denied($toolKey, "Tool [{$toolKey}] is not recognized.", 'AI_TOOL_NOT_FOUND');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        $handler = $this->manifest->get($toolKey);

        if ($handler->risk() !== 'read_only') {
            $result = ErpToolResult::denied($toolKey, 'Copilot accepts read-only tools.', 'AI_READ_ONLY_MODE');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 3. Feature gate & Emergency killswitch
        if (!$this->featureGate->isEnabledForCompany($principal->companyId)) {
            $result = ErpToolResult::denied($toolKey, 'AI capabilities are disabled for this company.', 'AI_FEATURE_DISABLED');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 4. Authorize principal against tool key and handler permissions
        if (!$this->authorizer->authorize($principal, $toolKey, $handler->requiredPermissions())) {
            $result = ErpToolResult::denied($toolKey, "Principal is not authorized to execute tool [{$toolKey}].", 'AI_TOOL_FORBIDDEN');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        $attempts = $this->toolAttempts[$runId] ?? 0;
        if ($attempts >= $this->maxToolsPerRun) {
            $result = ErpToolResult::denied($toolKey, 'The tool limit for this request was reached.', 'AI_TOOL_LIMIT_EXCEEDED');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }
        $this->toolAttempts[$runId] = $attempts + 1;

        // 5. Input Schema Validation
        $validationError = $this->validateInputSchema($handler->schema(), $rawInput);
        if ($validationError !== null) {
            $result = ErpToolResult::denied($toolKey, "Input validation failed: {$validationError}", 'AI_INPUT_VALIDATION_FAILED');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 6. Execute domain service with safe error redaction
        try {
            if (!$handler->authorize($principal, $rawInput)) {
                $result = ErpToolResult::denied($toolKey, 'Access denied to the requested operation.', 'AI_TOOL_FORBIDDEN');
            } else {
                $result = $handler->execute($principal, $rawInput);
            }
        } catch (AuthorizationException) {
            $result = ErpToolResult::denied($toolKey, 'Access denied to the requested operation.', 'AI_TOOL_FORBIDDEN');
        } catch (ValidationException $e) {
            $periodError = $e->errors()['period'][0] ?? null;
            $result = ErpToolResult::denied(
                $toolKey,
                $periodError ?? 'The ERP rejected the requested parameters.',
                $periodError === null ? 'AI_INPUT_VALIDATION_FAILED' : 'INVALID_FINANCIAL_YEAR_DATE'
            );
        } catch (Throwable $e) {
            if (function_exists('app') && app()->bound('log')) {
                Log::error('ERP tool execution failed.', [
                    'exception_type' => $e::class,
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'run_id' => $runId,
                    'tool_key' => $toolKey,
                ]);
            }

            $result = ErpToolResult::error(
                $toolKey,
                "An internal domain error occurred while executing tool [{$toolKey}].",
                'AI_DOMAIN_EXECUTION_FAILURE'
            );
        }

        // 7. Audit & Telemetry
        $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);

        return $result;
    }

    /**
     * Validate raw input against tool handler declared JSON schema.
     */
    private function validateInputSchema(array $schema, array $input): ?string
    {
        $properties = (array) ($schema['properties'] ?? []);
        if (array_diff_key($input, $properties) !== []) {
            return 'Unexpected tool parameters.';
        }

        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $input)) {
                return "Missing required parameter [{$key}].";
            }
        }

        foreach ($input as $key => $value) {
            $field = $properties[$key];
            $valid = match ($field['type']) {
                'integer' => is_int($value)
                    && $value >= ($field['minimum'] ?? PHP_INT_MIN)
                    && $value <= ($field['maximum'] ?? PHP_INT_MAX),
                'string' => is_string($value)
                    && mb_strlen($value) <= ($field['maxLength'] ?? 200)
                    && (($field['format'] ?? null) !== 'date' || validator(['date' => $value], ['date' => 'date_format:Y-m-d'])->passes()),
                'boolean' => is_bool($value),
                default => false,
            };

            if (!$valid || (isset($field['enum']) && !in_array($value, $field['enum'], true))) {
                return "Invalid parameter [{$key}].";
            }
        }

        return null;
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


