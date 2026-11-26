<?php

declare(strict_types=1);

namespace App\Ai\Billing\DTOs;

readonly class CreditBalance
{
    public function __construct(
        public string $accountId,
        public string $periodId,
        public int $includedGranted,
        public int $topupsGranted,
        public int $spentCredits,
        public int $reservedCredits,
        public int $refundedCredits,
        public int $availableCredits,
        public string $periodStartUtc,
        public string $periodEndUtc,
        public int $mediaPagesRemaining,
        public string $policyVersion,
        public string $status,
        public int $rawAvailableCredits = 0,
        public bool $isNegativeBreach = false,
        public bool $reconcileRequired = false,
    ) {}

    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'period_id' => $this->periodId,
            'included_granted' => $this->includedGranted,
            'topups_granted' => $this->topupsGranted,
            'spent_credits' => $this->spentCredits,
            'reserved_credits' => $this->reservedCredits,
            'refunded_credits' => $this->refundedCredits,
            'available_credits' => $this->availableCredits,
            'raw_available_credits' => $this->rawAvailableCredits,
            'is_negative_breach' => $this->isNegativeBreach,
            'reconcile_required' => $this->reconcileRequired,
            'period_start_utc' => $this->periodStartUtc,
            'period_end_utc' => $this->periodEndUtc,
            'media_pages_remaining' => $this->mediaPagesRemaining,
            'policy_version' => $this->policyVersion,
            'status' => $this->status,
        ];
    }
}
