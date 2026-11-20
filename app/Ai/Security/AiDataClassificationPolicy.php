<?php

declare(strict_types=1);

namespace App\Ai\Security;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Task T06: Data Privacy, Classification and Request-Boundary Sanitization Policy.
 *
 * Governs outbound model payloads, telemetry boundaries, audit logs, and error responses.
 * Strictly strips credentials, masks sensitive PII and financial identifiers,
 * neutralizes prompt-injection attempts, and ensures tax-critical business fields
 * (GSTIN, HSN/SAC, invoice numbers, amounts) are faithfully preserved for ERP reconciliation.
 */
class AiDataClassificationPolicy
{
    /**
     * Data classification tiers.
     */
    public const CLASS_CREDENTIALS = 'credentials';
    public const CLASS_FINANCIAL_IDENTIFIERS = 'financial_identifiers';
    public const CLASS_BUSINESS_TAX_FIELDS = 'business_tax_fields';
    public const CLASS_PERSONAL_DETAILS = 'personal_details';
    public const CLASS_TECHNICAL_TELEMETRY = 'technical_telemetry';

    /**
     * Keys whose values must always be redacted in audit records, payloads, and telemetry.
     *
     * @var array<int, string>
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
        'auth_token',
        'authorization',
        'private_key',
        'client_secret',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        'ssn',
        'aadhaar',
        'bank_account_number',
        'account_number',
    ];

    /**
     * Keys that represent authoritative business tax / ledger fields and must NEVER be redacted.
     *
     * @var array<int, string>
     */
    protected array $preservedTaxKeys = [
        'gstin',
        'supplier_gstin',
        'buyer_gstin',
        'vat_number',
        'tax_number',
        'hsn_code',
        'hsn_sac',
        'sku_or_hsn',
        'invoice_number',
        'invoice_no',
        'supplier_invoice_no',
        'total_amount',
        'tax_amount',
        'subtotal',
        'line_total',
    ];

    /**
     * Common prompt injection and instruction hijack signatures.
     *
     * @var array<int, string>
     */
    protected array $injectionPatterns = [
        '/(?:ignore|disregard|forget)\s+(?:all\s+)?(?:previous|prior|above)\s+(?:instructions|prompts|rules|commands)/i',
        '/(?:you are now in|switch to)\s+(?:developer|unrestricted|god|admin|jailbreak)\s+mode/i',
        '/(?:system\s+override|admin\s+override)\s*[:=]/i',
        '/(?:execute|perform)\s+(?:unauthorized|arbitrary|unapproved)\s+(?:payment|transfer|write|mutation|delete)/i',
        '/bypass\s+(?:authorization|permission|approval|security)\s+(?:checks|gates)/i',
    ];

    /**
     * Sanitize outbound text prompts before transmission to external AI model providers.
     * Strips credentials, masks credit cards and Aadhaar, while preserving GSTIN and HSN codes.
     */
    public function sanitizeOutboundPrompt(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        // 1. Mask Bearer and auth tokens: Bearer eyJ... or Bearer [alphanumeric]{16,}
        $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]{16,}/i', 'Bearer [REDACTED_TOKEN]', $text);

        // 2. Mask embedded API keys, passwords, secrets: key=secret or "api_key": "secret"
        $sanitized = preg_replace(
            '/(?:api_key|api-key|secret|password|access_token|private_key)\s*[:=]\s*["\']?[A-Za-z0-9_\-\.]{8,}["\']?/i',
            '$0 [REDACTED_SECRET]',
            (string) $sanitized
        );
        $sanitized = preg_replace(
            '/((?:api_key|api-key|secret|password|access_token|private_key)\s*[:=]\s*["\']?)[A-Za-z0-9_\-\.]{8,}(["\']?)/i',
            '${1}[REDACTED_SECRET]${2}',
            (string) $sanitized
        );

        // 3. Mask private connection URLs with embedded credentials (e.g. mysql://user:pass@host)
        $sanitized = preg_replace(
            '/(https?|mysql|postgres|redis):\/\/[^:\s\/]+:[^@\s\/]+@/i',
            '${1}://[REDACTED_USER]:[REDACTED_PASS]@',
            (string) $sanitized
        );

        // 4. Mask Credit Card numbers (16 digits with optional spaces/dashes: 4 groups of 4)
        $sanitized = preg_replace(
            '/\b(?:\d{4}[ -]?){3}\d{4}\b/',
            '[REDACTED_CARD]',
            (string) $sanitized
        );

        // 5. Mask Aadhaar numbers (12 digits in 3 groups of 4: 0000 0000 0000)
        $sanitized = preg_replace(
            '/\b\d{4}\s\d{4}\s\d{4}\b/',
            '[REDACTED_AADHAAR]',
            (string) $sanitized
        );

        // 6. Explicitly preserve Indian GSTINs (15 characters: 2 digits + 5 alpha + 4 digits + 1 alpha + 1 alpha/digit + Z + 1 alpha/digit)
        // GSTINs are unaffected because they do not match CC (16 digits) or Aadhaar (12 digits with spaces).

        return (string) $sanitized;
    }

    /**
     * Sanitize an array of Laravel AI Messages before dispatching to model.
     *
     * @param iterable<Message> $messages
     * @return array<int, Message>
     */
    public function sanitizeMessages(iterable $messages): array
    {
        $sanitized = [];

        foreach ($messages as $msg) {
            if ($msg instanceof Message) {
                $cleanContent = $msg->content !== null
                    ? $this->sanitizeOutboundPrompt($msg->content)
                    : null;

                if ($msg instanceof UserMessage) {
                    $sanitized[] = new UserMessage($cleanContent ?? '', $msg->attachments);
                } elseif ($msg instanceof AssistantMessage) {
                    $sanitized[] = new AssistantMessage($cleanContent ?? '', $msg->toolCalls);
                } else {
                    $sanitized[] = new Message($msg->role, $cleanContent);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Inspect untrusted text (e.g., OCR extracted bill text or companion user input)
     * for prompt injection attempts, script tags, or instruction hijacks.
     *
     * @return array{is_suspicious: bool, sanitized: string, flags: array<int, string>}
     */
    public function inspectUntrustedInput(string $text): array
    {
        $flags = [];
        $isSuspicious = false;

        // Check for script or javascript tags
        if (preg_match('/<script[\s\S]*?>[\s\S]*?<\/script>/i', $text) || preg_match('/javascript\s*:/i', $text)) {
            $flags[] = 'unsafe_markup_detected';
            $isSuspicious = true;
        }

        // Check for prompt injection signatures
        foreach ($this->injectionPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $flags[] = 'prompt_injection_pattern: ' . substr($matches[0], 0, 50);
                $isSuspicious = true;
            }
        }

        // Neutralize markup
        $sanitized = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Apply prompt sanitization
        $sanitized = $this->sanitizeOutboundPrompt($sanitized);

        // If suspicious, wrap in untrusted data framing to protect model context
        if ($isSuspicious) {
            $sanitized = "[UNTRUSTED_CONTENT_LITERAL_DATA: " . $sanitized . " :END_UNTRUSTED_CONTENT]";
        }

        return [
            'is_suspicious' => $isSuspicious,
            'sanitized' => $sanitized,
            'flags' => $flags,
        ];
    }

    /**
     * Recursively redacts sensitive fields in payloads (for audit logs, telemetry, error dumps).
     *
     * @param array<string, mixed> $payload
     * @param array<int, string> $preserveKeys
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $payload, array $preserveKeys = []): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // If key is explicitly preserved (e.g., GSTIN or HSN), preserve its value
            if (in_array($lowerKey, $preserveKeys, true) || in_array($lowerKey, $this->preservedTaxKeys, true)) {
                $sanitized[$key] = $value;
                continue;
            }

            // Check if key is sensitive
            if (in_array($lowerKey, $this->redactedKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value, $preserveKeys);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeOutboundPrompt($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Produces a privacy-safe error response without exposing internal DB queries,
     * credentials, file paths, or stack traces to end users or API consumers.
     *
     * @return array{error: array{code: string, message: string, correlation_id: string, details?: array}}
     */
    public function toSafeErrorResponse(Throwable $e, ?string $correlationId = null): array
    {
        $correlation = $correlationId ?? (string) Str::uuid();

        // Safe client exceptions (validation, 404, 403, 401, 429)
        if ($e instanceof ValidationException) {
            return [
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'The given data was invalid.',
                    'correlation_id' => $correlation,
                    'details' => $e->errors(),
                ],
            ];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                422 => 'UNPROCESSABLE_ENTITY',
                429 => 'RATE_LIMIT_EXCEEDED',
                default => 'HTTP_ERROR_' . $status,
            };

            return [
                'error' => [
                    'code' => $code,
                    'message' => $this->sanitizeOutboundPrompt($e->getMessage() ?: 'An error occurred.'),
                    'correlation_id' => $correlation,
                ],
            ];
        }

        // Generic / Database / Internal exceptions: NEVER leak SQL or stack traces
        return [
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'An unexpected internal error occurred. Please reference the correlation ID when contacting support.',
                'correlation_id' => $correlation,
            ],
        ];
    }
}
