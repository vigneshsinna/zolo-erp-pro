<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class AiCreditReconciliationService
{
    /**
     * Reconcile all expired active reservations and release stranded holds.
     *
     * @return array{reconciled_count: int, released_credits: int}
     */
    public function sweepExpiredReservations(): array
    {
        $now = now()->toDateTimeString();

        $expired = DB::table('ai_credit_reservations')
            ->where('status', 'active')
            ->where('expires_at', '<', $now)
            ->get();

        $count = 0;
        $totalReleased = 0;

        foreach ($expired as $res) {
            DB::transaction(function () use ($res, &$count, &$totalReleased): void {
                $locked = DB::table('ai_credit_reservations')
                    ->where('id', $res->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return;
                }

                $held = (int) $locked->remaining_held_credits;

                // P1-09: Check whether an in-flight model invocation exists for this reservation
                $linkedInvocation = DB::table('ai_model_invocations')
                    ->where('reservation_id', $locked->id)
                    ->first();

                if ($linkedInvocation && in_array($linkedInvocation->status, ['running', 'pending', null], true)) {
                    // In-flight dispatch: Provider billability is uncertain.
                    // DO NOT release held credits to avoid uncontrolled free retries before provider billing is confirmed.
                    DB::table('ai_credit_reservations')
                        ->where('id', $locked->id)
                        ->update([
                            'status' => 'unknown_billability',
                            'updated_at' => now(),
                        ]);

                    DB::table('ai_credit_periods')
                        ->where('id', $locked->period_id)
                        ->update([
                            'reconcile_required' => 1,
                            'updated_at' => now(),
                        ]);

                    $count++;
                    return;
                }

                // Never dispatched or cancelled: safely release held credits
                if ($held > 0) {
                    DB::table('ai_credit_periods')
                        ->where('id', $locked->period_id)
                        ->update([
                            'reserved_credits' => DB::raw("GREATEST(0, reserved_credits - {$held})"),
                            'updated_at' => now(),
                        ]);
                    $totalReleased += $held;
                }

                DB::table('ai_credit_reservations')
                    ->where('id', $locked->id)
                    ->update([
                        'remaining_held_credits' => 0,
                        'status' => 'released',
                        'updated_at' => now(),
                    ]);

                $count++;
            }, 3);
        }

        return [
            'reconciled_count' => $count,
            'released_credits' => $totalReleased,
        ];
    }

    /**
     * Audit period aggregate counters against immutable ledger rows.
     *
     * @return array{
     *     period_id: string,
     *     account_id: string,
     *     is_reconciled: bool,
     *     period_spent: int,
     *     ledger_spent: int,
     *     period_included: int,
     *     ledger_included: int,
     *     period_topups: int,
     *     ledger_topups: int,
     *     discrepancies: array<string>
     * }
     */
    public function reconcilePeriod(string $periodId): array
    {
        $period = DB::table('ai_credit_periods')->where('id', $periodId)->first();
        if (! $period) {
            return [
                'period_id' => $periodId,
                'account_id' => '',
                'is_reconciled' => false,
                'period_spent' => 0,
                'ledger_spent' => 0,
                'period_included' => 0,
                'ledger_included' => 0,
                'period_topups' => 0,
                'ledger_topups' => 0,
                'discrepancies' => ['Period not found'],
            ];
        }

        $ledgerEntries = DB::table('ai_credit_ledger')->where('period_id', $periodId)->get();

        $ledgerSpent = 0;
        $ledgerIncluded = 0;
        $ledgerTopups = 0;

        foreach ($ledgerEntries as $entry) {
            match ($entry->kind) {
                'usage_debit' => $ledgerSpent += abs((int) $entry->amount_credits),
                'included_grant' => $ledgerIncluded += (int) $entry->amount_credits,
                'topup_grant' => $ledgerTopups += (int) $entry->amount_credits,
                default => null,
            };
        }

        $discrepancies = [];
        if ((int) $period->spent_credits !== $ledgerSpent) {
            $discrepancies[] = "Spent mismatch: period has [{$period->spent_credits}], ledger has [{$ledgerSpent}]";
        }
        if ((int) $period->included_granted !== $ledgerIncluded) {
            $discrepancies[] = "Included grant mismatch: period has [{$period->included_granted}], ledger has [{$ledgerIncluded}]";
        }
        if ((int) $period->topups_granted !== $ledgerTopups) {
            $discrepancies[] = "Top-ups mismatch: period has [{$period->topups_granted}], ledger has [{$ledgerTopups}]";
        }

        return [
            'period_id' => $periodId,
            'account_id' => $period->account_id,
            'is_reconciled' => empty($discrepancies),
            'period_spent' => (int) $period->spent_credits,
            'ledger_spent' => $ledgerSpent,
            'period_included' => (int) $period->included_granted,
            'ledger_included' => $ledgerIncluded,
            'period_topups' => (int) $period->topups_granted,
            'ledger_topups' => $ledgerTopups,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Audit an entire credit account across all its historical periods and active balances.
     *
     * @return array{
     *     account_id: string,
     *     is_reconciled: bool,
     *     periods_audited: int,
     *     total_spent: int,
     *     total_granted: int,
     *     discrepancies: array<string>
     * }
     */
    public function reconcileAccount(string $accountId): array
    {
        $periods = DB::table('ai_credit_periods')->where('account_id', $accountId)->get();

        $allDiscrepancies = [];
        $totalSpent = 0;
        $totalGranted = 0;

        foreach ($periods as $p) {
            $periodAudit = $this->reconcilePeriod($p->id);
            if (! $periodAudit['is_reconciled']) {
                $allDiscrepancies = array_merge($allDiscrepancies, $periodAudit['discrepancies']);
            }
            $totalSpent += $periodAudit['ledger_spent'];
            $totalGranted += ($periodAudit['ledger_included'] + $periodAudit['ledger_topups']);
        }

        return [
            'account_id' => $accountId,
            'is_reconciled' => empty($allDiscrepancies),
            'periods_audited' => $periods->count(),
            'total_spent' => $totalSpent,
            'total_granted' => $totalGranted,
            'discrepancies' => $allDiscrepancies,
        ];
    }

    /**
     * Audit a single provider attempt against linked reservation, invocation, and ledger records.
     *
     * @return array{
     *     attempt_id: string,
     *     is_matched: bool,
     *     invocation_found: bool,
     *     reservation_found: bool,
     *     ledger_found: bool,
     *     credits_debited: int,
     *     discrepancies: array<string>
     * }
     */
    public function reconcileAttempt(string $attemptId): array
    {
        $invocation = DB::table('ai_model_invocations')
            ->where('provider_attempt_id', $attemptId)
            ->first();

        $reservation = null;
        if ($invocation && !empty($invocation->reservation_id)) {
            $reservation = DB::table('ai_credit_reservations')
                ->where('id', $invocation->reservation_id)
                ->first();
        }

        $ledgerEntry = DB::table('ai_credit_ledger')
            ->where('provider_attempt_id', $attemptId)
            ->orWhere('idempotency_key', 'like', "%{$attemptId}%")
            ->first();

        $discrepancies = [];

        if (!$invocation && !$reservation && !$ledgerEntry) {
            $discrepancies[] = "No records found matching provider attempt ID [{$attemptId}]";
        }

        if ($invocation && $invocation->status === 'completed' && !$ledgerEntry && ($invocation->credits_debited ?? 0) > 0) {
            $discrepancies[] = "Model invocation was completed with credits debited, but no ledger row was found.";
        }

        return [
            'attempt_id' => $attemptId,
            'is_matched' => empty($discrepancies) && ($invocation !== null || $ledgerEntry !== null),
            'invocation_found' => $invocation !== null,
            'reservation_found' => $reservation !== null,
            'ledger_found' => $ledgerEntry !== null,
            'credits_debited' => (int) ($ledgerEntry ? abs((int) $ledgerEntry->amount_credits) : ($invocation->credits_debited ?? 0)),
            'discrepancies' => $discrepancies,
        ];
    }
}
