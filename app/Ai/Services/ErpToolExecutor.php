<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use Illuminate\Support\Facades\Log;
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
    public function __construct(
        protected AiToolManifest $manifest,
        protected AiPrincipalAuthorizerInterface $authorizer,
        protected AiAuditRecorder $auditRecorder,
        protected ?AiFeatureGate $featureGate = null
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

        // 3. Feature gate & Emergency killswitch
        $gate = $this->featureGate ?? (function_exists('app') && app()->bound(AiFeatureGate::class) ? app(AiFeatureGate::class) : null);
        if ($gate !== null) {
            if (!$gate->isEnabledForCompany($principal->companyId)) {
                $result = ErpToolResult::denied($toolKey, "AI capabilities are disabled for company ID [{$principal->companyId}].", 'AI_FEATURE_DISABLED');
                $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
                return $result;
            }

            // Enforce read-only policy
            if ($gate->isReadOnly($principal->companyId) && $handler->risk() !== 'read_only') {
                $result = ErpToolResult::denied($toolKey, 'System is operating in read-only AI mode; mutating actions are blocked.', 'AI_READ_ONLY_MODE');
                $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
                return $result;
            }
        }

        // 4. Authorize principal against tool key and handler permissions
        if (!$this->authorizer->authorize($principal, $toolKey, $handler->requiredPermissions())
            || !$handler->authorize($principal, $rawInput)) {
            $result = ErpToolResult::denied($toolKey, "Principal is not authorized to execute tool [{$toolKey}].", 'AI_TOOL_FORBIDDEN');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 5. Input Schema Validation
        $validationError = $this->validateInputSchema($handler->schema(), $rawInput);
        if ($validationError !== null) {
            $result = ErpToolResult::denied($toolKey, "Input validation failed: {$validationError}", 'AI_INPUT_VALIDATION_FAILED');
            $this->recordTelemetry($runId, $toolKey, $rawInput, $result, $startTime);
            return $result;
        }

        // 6. Execute domain service with safe error redaction
        try {
            $result = $handler->execute($principal, $rawInput);
        } catch (Throwable $e) {
            if (function_exists('app') && app()->bound('log')) {
                Log::error('ERP tool execution failed.', [
                    'exception_type' => $e::class,
                    'message' => $e->getMessage(),
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
    protected function validateInputSchema(array $schema, array $input): ?string
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        $allowAdditional = $schema['additionalProperties'] ?? true;

        // 1. Required fields
        foreach ($required as $reqField) {
            if (!array_key_exists($reqField, $input)) {
                return "Missing required parameter [{$reqField}].";
            }
        }

        // 2. Disallow extra properties if additionalProperties is false
        if (!$allowAdditional) {
            foreach ($input as $key => $val) {
                if (!array_key_exists($key, $properties)) {
                    return "Unexpected parameter [{$key}].";
                }
            }
        }

        // 3. Validate types and constraints
        foreach ($input as $key => $val) {
            if (!isset($properties[$key])) {
                continue;
            }

            if ($val === null) {
                if (in_array($key, $required, true)) {
                    return "Parameter [{$key}] cannot be null.";
                }
                continue;
            }

            $propSchema = $properties[$key];
            $expectedType = $propSchema['type'] ?? null;

            if ($expectedType === 'integer') {
                if (!is_int($val) && (!is_string($val) || !ctype_digit(ltrim((string)$val, '-')))) {
                    return "Parameter [{$key}] must be an integer.";
                }
                $intVal = (int) $val;
                if (isset($propSchema['minimum']) && $intVal < $propSchema['minimum']) {
                    return "Parameter [{$key}] must be at least {$propSchema['minimum']}.";
                }
                if (isset($propSchema['maximum']) && $intVal > $propSchema['maximum']) {
                    return "Parameter [{$key}] must not exceed {$propSchema['maximum']}.";
                }
            } elseif ($expectedType === 'string') {
                if (!is_string($val)) {
                    return "Parameter [{$key}] must be a string.";
                }
                if (isset($propSchema['format']) && $propSchema['format'] === 'date') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                        return "Parameter [{$key}] must be a valid date in YYYY-MM-DD format.";
                    }
                }
            } elseif ($expectedType === 'boolean') {
                if (!is_bool($val)) {
                    return "Parameter [{$key}] must be a boolean.";
                }
            } elseif ($expectedType === 'array') {
                if (!is_array($val)) {
                    return "Parameter [{$key}] must be an array.";
                }
            }

            if (isset($propSchema['enum']) && is_array($propSchema['enum'])) {
                if (!in_array($val, $propSchema['enum'], true)) {
                    return "Parameter [{$key}] contains an invalid value.";
                }
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


