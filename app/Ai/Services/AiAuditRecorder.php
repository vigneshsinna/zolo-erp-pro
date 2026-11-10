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

        // In database mode, this will persist to ai_tool_invocations table.
    }
}

