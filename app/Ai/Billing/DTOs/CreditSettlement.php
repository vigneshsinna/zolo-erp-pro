<?php

declare(strict_types=1);

namespace App\Ai\Billing\DTOs;

readonly class CreditSettlement
{
    public function __construct(
        public string $reservationId,
        public string $providerAttemptId,
        public string $accountId,
        public string $periodId,
        public int $creditsDebited,
        public int $creditsReleased,
        public int $costMinorInr,
        public string $rateCardVersion,
        public string $fxRateVersion,
        public string $status, // 'settled', 'reconcile_required', 'exempt'
        public ?string $ledgerEntryId = null,
    ) {}

    public function toArray(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'provider_attempt_id' => $this->providerAttemptId,
            'account_id' => $this->accountId,
            'period_id' => $this->periodId,
            'credits_debited' => $this->creditsDebited,
            'credits_released' => $this->creditsReleased,
            'cost_minor_inr' => $this->costMinorInr,
            'rate_card_version' => $this->rateCardVersion,
            'fx_rate_version' => $this->fxRateVersion,
            'status' => $this->status,
            'ledger_entry_id' => $this->ledgerEntryId,
        ];
    }
}
