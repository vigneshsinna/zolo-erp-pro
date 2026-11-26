<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\Contracts\SubscriptionEntitlementResolverInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class MonthlyCreditGrantService
{
    public function __construct(
        protected ?SubscriptionEntitlementResolverInterface $entitlementResolver = null
    ) {
        $this->entitlementResolver ??= app(SubscriptionEntitlementResolverInterface::class);
    }

    /**
     * Resolve or idempotently create the active monthly period and grant initial monthly allowance.
     */
    public function resolveCurrentPeriod(string $accountId, ?Carbon $asOf = null): stdClass
    {
        $now = $asOf ? $asOf->copy()->utc() : Carbon::now('UTC');
        $startOfPeriod = $now->copy()->startOfMonth();
        $endOfPeriod = $now->copy()->endOfMonth();

        $startStr = $startOfPeriod->toDateTimeString();
        $endStr = $endOfPeriod->toDateTimeString();

        $existing = DB::table('ai_credit_periods')
            ->where('account_id', $accountId)
            ->where('period_start_utc', '<=', $now->toDateTimeString())
            ->where('period_end_utc', '>=', $now->toDateTimeString())
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($accountId, $startStr, $endStr, $startOfPeriod, $now): stdClass {
            // Lock and re-check inside transaction
            $existing = DB::table('ai_credit_periods')
                ->where('account_id', $accountId)
                ->where('period_start_utc', '<=', $now->toDateTimeString())
                ->where('period_end_utc', '>=', $now->toDateTimeString())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Close prior active periods whose end date has passed
            $stalePeriods = DB::table('ai_credit_periods')
                ->where('account_id', $accountId)
                ->where('period_end_utc', '<', $startStr)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($stalePeriods as $stale) {
                DB::table('ai_credit_periods')->where('id', $stale->id)->update([
                    'status' => 'closed',
                    'updated_at' => now(),
                ]);

                // Record expiry entry for any remaining balance in stale period
                $unspent = max(0, ($stale->included_granted + $stale->topups_granted) - ($stale->spent_credits + $stale->reserved_credits));
                if ($unspent > 0) {
                    $expiryKey = "expiry_{$stale->id}";
                    DB::table('ai_credit_ledger')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'account_id' => $accountId,
                        'period_id' => $stale->id,
                        'kind' => 'expiry',
                        'amount_credits' => -$unspent,
                        'currency' => 'INR',
                        'idempotency_key' => $expiryKey,
                        'occurred_at' => $stale->period_end_utc,
                        'created_at' => now(),
                    ]);
                }
            }

            $periodId = (string) Str::uuid();
            $entitlement = $this->entitlementResolver->resolveForAccount($accountId, $now);
            $included = $entitlement->allowsAiUsage() ? $entitlement->includedMonthlyCredits : 0;
            $subscriptionVersion = $entitlement->planSku ?: config('ai_credits.credit_policy_version', 'cost_v1');

            DB::table('ai_credit_periods')->insert([
                'id' => $periodId,
                'account_id' => $accountId,
                'period_start_utc' => $startStr,
                'period_end_utc' => $endStr,
                'included_granted' => $included,
                'topups_granted' => 0,
                'spent_credits' => 0,
                'reserved_credits' => 0,
                'refunded_credits' => 0,
                'status' => 'active',
                'subscription_version' => $subscriptionVersion,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Only record ledger entry if included credits were actually granted (> 0)
            if ($included > 0) {
                $grantKey = 'grant_included_' . $accountId . '_' . $startOfPeriod->format('Y_m');
                DB::table('ai_credit_ledger')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'account_id' => $accountId,
                    'period_id' => $periodId,
                    'kind' => 'included_grant',
                    'amount_credits' => $included,
                    'currency' => 'INR',
                    'rate_card_version' => config('ai_credits.credit_policy_version', 'cost_v1'),
                    'idempotency_key' => $grantKey,
                    'occurred_at' => $startStr,
                    'created_at' => now(),
                ]);
            }

            return DB::table('ai_credit_periods')->where('id', $periodId)->first();
        });
    }
}
