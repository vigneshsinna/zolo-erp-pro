<?php

declare(strict_types=1);

namespace App\Ai\Billing\Contracts;

use App\Ai\Billing\DTOs\CreditBalance;
use App\Ai\Billing\DTOs\CreditReservation;
use App\Ai\Billing\DTOs\CreditSettlement;
use App\Ai\DTOs\AiPrincipal;

interface AiCreditMeterInterface
{
    public function reserve(
        AiPrincipal $principal,
        string $runId,
        string $operationKey,
        string $provider,
        string $model,
        string $idempotencyKey,
        int $estimatedMaxCredits
    ): CreditReservation;

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
        ?string $reconcileReason = null,
    ): CreditSettlement;

    public function releaseUnsent(
        string $reservationId,
        string $reason = 'request_cancelled'
    ): void;

    public function balance(AiPrincipal|int $principalOrCompanyId): CreditBalance;
}
