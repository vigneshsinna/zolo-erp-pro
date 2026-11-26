<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\DTOs\CreditReservation;
use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiCreditReservationService
{
    public function __construct(
        protected CreditAccountResolver $accountResolver,
        protected MonthlyCreditGrantService $periodGrantService,
        protected ModelCostCalculator $calculator
    ) {}

    /**
     * Atomically preflight-hold credits before provider dispatch.
     * Enforces fail-closed balance check and idempotency.
     */
    public function reserve(
        AiPrincipal $principal,
        string $runId,
        string $operationKey,
        string $provider,
        string $model,
        string $idempotencyKey,
        int $estimatedMaxCredits
    ): CreditReservation {
        if (! (bool) config('ai_credits.enabled', true)) {
            if (app()->environment('production')) {
                throw new AiCreditException('CREDIT_METERING_DISABLED', 'AI credit metering is disabled in configuration. Commercial model dispatch is blocked in production.', 403);
            }

            // In non-production testing/development, return zero-charge stub reservation
            return new CreditReservation(
                id: (string) Str::uuid(),
                accountId: 'disabled',
                periodId: 'disabled',
                companyId: $principal->companyId,
                actorId: $principal->actorId,
                runId: $runId,
                operationKey: $operationKey,
                requestedModel: $model,
                estimatedCredits: 0,
                remainingHeldCredits: 0,
                status: 'settled',
                idempotencyKey: $idempotencyKey,
                expiresAt: now()->addHours(1)->toDateTimeString(),
            );
        }

        $account = $this->accountResolver->resolve($principal);
        if ($account->status === 'frozen') {
            throw AiCreditException::accountFrozen('This subscription AI credit account has been suspended or frozen.');
        }
        if ($account->status === 'unsubscribed') {
            throw AiCreditException::subscriptionRequired('A verified active or prepaid subscription is required to access AI services.');
        }
        if ($account->status === 'expired') {
            throw AiCreditException::subscriptionRequired('Your AI subscription has expired. Please renew your plan to continue using AI services.');
        }

        $period = $this->periodGrantService->resolveCurrentPeriod($account->id);

        $ttlMinutes = (int) config('ai_credits.reservation_ttl_minutes', 10);
        $expiresAt = now()->addMinutes($ttlMinutes);

        $requestDigest = hash('sha256', json_encode([
            $account->id,
            $period->id,
            $runId,
            $operationKey,
            $provider,
            $model,
            $estimatedMaxCredits,
        ]));

        return DB::transaction(function () use (
            $principal,
            $account,
            $period,
            $runId,
            $operationKey,
            $model,
            $idempotencyKey,
            $requestDigest,
            $estimatedMaxCredits,
            $expiresAt
        ): CreditReservation {
            // Check for existing reservation under this idempotency key
            $existing = DB::table('ai_credit_reservations')
                ->where('account_id', $account->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                // P1-08 / PR-02: Bound payload digest verification
                if ($existing->request_digest && $existing->request_digest !== $requestDigest) {
                    throw AiCreditException::idempotencyConflict($idempotencyKey);
                }

                return new CreditReservation(
                    id: $existing->id,
                    accountId: $existing->account_id,
                    periodId: $existing->period_id,
                    companyId: (int) $existing->company_id,
                    actorId: (int) $existing->actor_id,
                    runId: $existing->run_id,
                    operationKey: $existing->operation_key,
                    requestedModel: $existing->requested_model,
                    estimatedCredits: (int) $existing->estimated_credits,
                    remainingHeldCredits: (int) $existing->remaining_held_credits,
                    status: $existing->status,
                    idempotencyKey: $existing->idempotency_key,
                    expiresAt: (string) $existing->expires_at,
                );
            }

            // Lock period row
            $periodRow = DB::table('ai_credit_periods')
                ->where('id', $period->id)
                ->lockForUpdate()
                ->first();

            // P1-08: Reject new reservations if period requires reconciliation or is frozen
            if ($periodRow->reconcile_required) {
                throw AiCreditException::accountFrozen('AI dispatch is paused pending credit reconciliation for this billing period.');
            }

            $totalGranted = (int) ($periodRow->included_granted + $periodRow->topups_granted + $periodRow->refunded_credits);
            $totalCommitted = (int) ($periodRow->spent_credits + $periodRow->reserved_credits);
            $available = $totalGranted - $totalCommitted;

            $maxAllowedOverage = (int) config('ai_credits.grace_overage_credits', 0);
            if (($available + $maxAllowedOverage) < $estimatedMaxCredits) {
                throw AiCreditException::creditsExhausted(max(0, $available), $estimatedMaxCredits);
            }

            $reservationId = (string) Str::uuid();

            DB::table('ai_credit_reservations')->insert([
                'id' => $reservationId,
                'account_id' => $account->id,
                'period_id' => $periodRow->id,
                'company_id' => $principal->companyId,
                'actor_id' => $principal->actorId,
                'run_id' => $runId,
                'operation_key' => $operationKey,
                'requested_model' => $model,
                'estimated_credits' => $estimatedMaxCredits,
                'remaining_held_credits' => $estimatedMaxCredits,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
                'request_digest' => $requestDigest,
                'expires_at' => $expiresAt->toDateTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_credit_periods')
                ->where('id', $periodRow->id)
                ->increment('reserved_credits', $estimatedMaxCredits);

            return new CreditReservation(
                id: $reservationId,
                accountId: $account->id,
                periodId: $periodRow->id,
                companyId: $principal->companyId,
                actorId: $principal->actorId,
                runId: $runId,
                operationKey: $operationKey,
                requestedModel: $model,
                estimatedCredits: $estimatedMaxCredits,
                remainingHeldCredits: $estimatedMaxCredits,
                status: 'active',
                idempotencyKey: $idempotencyKey,
                expiresAt: $expiresAt->toDateTimeString(),
            );
        }, 3);
    }
}
