<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\ErpToolResult;

/**
 * Audit recorder providing field-level redaction of PII, credentials,
 * and sensitive secrets before persisting execution telemetry.
 */
class AiAuditRecorder
{
    /**
     * Keys whose values must be masked in audit records.
     */
    protected array $redactedKeys = [
        'password',
        'password_confirmation',
        'api_key',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'bearer',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'pan',
        'aadhaar',
    ];

    /**
     * Redact sensitive fields recursively.
     */
    public function redact(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $lowerKey = strtolower((string)$key);

            if (in_array($lowerKey, $this->redactedKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->redact($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Record tool invocation.
     */
    public function recordInvocation(
        string $runId,
        string $toolKey,
        array $input,
        ErpToolResult $result,
        int $durationMs
    ): void {
        // Redacts input and output references
        $redactedInput = $this->redact($input);

        if (function_exists('app') && app()->bound('db')) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('ai_tool_invocations')) {
                    $decision = ($result->status === 'denied') ? 'denied' : 'authorized';
                    $scopeHash = !empty($result->scope) ? hash('sha256', json_encode($result->scope)) : null;
                    $outputRef = !empty($result->sourceRefs) ? (string) $result->sourceRefs[0] : null;

                    \Illuminate\Support\Facades\DB::table('ai_tool_invocations')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'run_id' => $runId,
                        'tool_key' => $toolKey,
                        'schema_version' => 'v1',
                        'scope_hash' => $scopeHash,
                        'input_redacted' => json_encode($redactedInput),
                        'output_ref' => $outputRef,
                        'authorization_decision' => $decision,
                        'status' => $result->status,
                        'duration_ms' => $durationMs,
                        'error_code' => $result->errorCode,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                if (app()->bound('log')) {
                    \Illuminate\Support\Facades\Log::warning('Failed to persist AI tool audit log: ' . $e->getMessage(), [
                        'run_id' => $runId,
                        'tool_key' => $toolKey,
                    ]);
                }
            }
        }
    }
}

