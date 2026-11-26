<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\Contracts\AiCreditMeterInterface;
use App\Ai\Billing\DTOs\CreditBalance;
use App\Ai\Billing\DTOs\CreditReservation;
use App\Ai\Billing\DTOs\CreditSettlement;
use App\Ai\DTOs\AiPrincipal;

class AiCreditMeter implements AiCreditMeterInterface
{
    public function __construct(
        protected AiCreditReservationService $reservationService,
        protected AiCreditSettlementService $settlementService,
        protected ModelCostCalculator $calculator
    ) {}

    public function reserve(
        AiPrincipal $principal,
        string $runId,
        string $operationKey,
        string $provider,
        string $model,
        string $idempotencyKey,
        int $estimatedMaxCredits
    ): CreditReservation {
        return $this->reservationService->reserve(
            $principal,
            $runId,
            $operationKey,
            $provider,
            $model,
            $idempotencyKey,
            $estimatedMaxCredits
        );
    }

    public function settle(
        string $reservationId,
        string $providerAttemptId,
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $imagesCount = 0,
        int $audioSeconds = 0,
        bool $isChargeable = true,
        ?string $reconcileReason = null
    ): CreditSettlement {
        return $this->settlementService->settle(
            $reservationId,
            $providerAttemptId,
            $provider,
            $model,
            $inputTokens,
            $outputTokens,
            $imagesCount,
            $audioSeconds,
            $isChargeable,
            $reconcileReason
        );
    }

    public function releaseUnsent(string $reservationId, string $reason = 'request_cancelled'): void
    {
        $this->settlementService->releaseUnsent($reservationId, $reason);
    }

    public function balance(AiPrincipal|int $principalOrCompanyId): CreditBalance
    {
        return $this->settlementService->balance($principalOrCompanyId);
    }

    public function calculator(): ModelCostCalculator
    {
        return $this->calculator;
    }

    public function settlementService(): AiCreditSettlementService
    {
        return $this->settlementService;
    }

    public function reservationService(): AiCreditReservationService
    {
        return $this->reservationService;
    }
}
