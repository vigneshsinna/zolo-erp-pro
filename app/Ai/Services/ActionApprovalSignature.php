<?php

declare(strict_types=1);

namespace App\Ai\Services;

use RuntimeException;

/**
 * A domain-separated HMAC over a canonical, immutable approval envelope.
 * App key is used only as keying material and is never returned to clients.
 */
final class ActionApprovalSignature
{
    public function sign(array $envelope): string
    {
        return hash_hmac('sha256', CanonicalActionPayload::json($envelope), $this->key());
    }

    public function matches(array $envelope, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals($this->sign($envelope), $signature);
    }

    private function key(): string
    {
        $config = config('app.key');
        if (!is_string($config) || $config === '') {
            throw new RuntimeException('APP_KEY must be configured for AI approvals.');
        }

        if (str_starts_with($config, 'base64:')) {
            $decoded = base64_decode(substr($config, 7), true);
            if ($decoded === false || strlen($decoded) < 32) {
                throw new RuntimeException('APP_KEY has an invalid key format.');
            }
            $config = $decoded;
        }

        return hash_hmac('sha256', 'zoloerp-ai-action-approvals-v1', $config, true);
    }
}
