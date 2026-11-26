<?php

declare(strict_types=1);

namespace App\Ai\Billing\DTOs;

use Carbon\CarbonInterface;

final readonly class SubscriptionEntitlement
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $isActive,
        public ?string $subscriptionId,
        public string $planSku,
        public int $includedMonthlyCredits,
        public int $monthlyMediaPages,
        public int $maxConcurrentRuns,
        public string $renewalStatus, // 'active', 'prepaid', 'trial', 'expired', 'unsubscribed', 'cancelled', 'inactive'
        public ?CarbonInterface $expiresAt = null,
        public array $metadata = []
    ) {}

    public function allowsAiUsage(): bool
    {
        return $this->isActive && in_array($this->renewalStatus, ['active', 'prepaid', 'trial'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_active' => $this->isActive,
            'subscription_id' => $this->subscriptionId,
            'plan_sku' => $this->planSku,
            'included_monthly_credits' => $this->includedMonthlyCredits,
            'monthly_media_pages' => $this->monthlyMediaPages,
            'max_concurrent_runs' => $this->maxConcurrentRuns,
            'renewal_status' => $this->renewalStatus,
            'expires_at' => $this->expiresAt?->toISOString(),
            'metadata' => $this->metadata,
        ];
    }
}
