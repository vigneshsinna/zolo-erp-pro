<?php

declare(strict_types=1);

namespace App\Ai\Billing\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiCreditException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        private readonly int $statusCode = 402,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-AI-Error-Code' => $this->errorCode,
        ];
    }

    public static function creditsExhausted(int $available, int $required): self
    {
        return new self(
            'CREDITS_EXHAUSTED',
            "Insufficient AI credits: available [{$available}] credits, required [{$required}] credits for this operation. Please top up your subscription pool.",
            402,
            ['available_credits' => $available, 'required_credits' => $required]
        );
    }

    public static function mediaPagesExhausted(int $limit): self
    {
        return new self(
            'MEDIA_PAGES_EXHAUSTED',
            "Monthly document processing limit of [{$limit}] pages has been reached for this billing period.",
            429,
            ['limit' => $limit]
        );
    }

    public static function accountFrozen(string $reason = 'Account is frozen'): self
    {
        return new self(
            'ACCOUNT_FROZEN',
            $reason,
            403
        );
    }

    public static function subscriptionRequired(string $reason = 'A verified active subscription is required to access AI services.'): self
    {
        return new self(
            'SUBSCRIPTION_REQUIRED',
            $reason,
            402
        );
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self(
            'IDEMPOTENCY_CONFLICT',
            "Idempotency conflict for key [{$key}]. The submitted payload differs from the original request.",
            409
        );
    }

    public static function rateCardMissing(string $provider, string $model, string $unit): self
    {
        return new self(
            'RATE_CARD_MISSING',
            "No billable rate card configured for model [{$model}] under provider [{$provider}] (unit: {$unit}). Unmetered model calls are strictly forbidden (SEC-17).",
            422,
            ['provider' => $provider, 'model' => $model, 'unit' => $unit]
        );
    }

    public static function requestExceedsCreditLimit(int $estimated, int $limit): self
    {
        return new self(
            'REQUEST_EXCEEDS_CREDIT_LIMIT',
            "Estimated operation cost [{$estimated}] credits exceeds the maximum configured per-request threshold [{$limit}] credits.",
            422,
            ['estimated_credits' => $estimated, 'max_limit' => $limit]
        );
    }
}
