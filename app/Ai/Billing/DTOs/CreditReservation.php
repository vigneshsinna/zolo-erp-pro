<?php

declare(strict_types=1);

namespace App\Ai\Billing\DTOs;

readonly class CreditReservation
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $periodId,
        public int $companyId,
        public int $actorId,
        public string $runId,
        public string $operationKey,
        public string $requestedModel,
        public int $estimatedCredits,
        public int $remainingHeldCredits,
        public string $status,
        public string $idempotencyKey,
        public string $expiresAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'period_id' => $this->periodId,
            'company_id' => $this->companyId,
            'actor_id' => $this->actorId,
            'run_id' => $this->runId,
            'operation_key' => $this->operationKey,
            'requested_model' => $this->requestedModel,
            'estimated_credits' => $this->estimatedCredits,
            'remaining_held_credits' => $this->remainingHeldCredits,
            'status' => $this->status,
            'idempotency_key' => $this->idempotencyKey,
            'expires_at' => $this->expiresAt,
        ];
    }
}
