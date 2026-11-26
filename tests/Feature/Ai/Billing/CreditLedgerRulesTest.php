<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Billing;

use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\AiCreditReconciliationService;
use App\Ai\Billing\Services\AiTopupService;
use App\Ai\Billing\Services\CreditAccountResolver;
use App\Ai\Billing\Services\ModelCostCalculator;
use App\Ai\Billing\Services\MonthlyCreditGrantService;
use App\Ai\DTOs\AiPrincipal;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class CreditLedgerRulesTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private User $user;
    private AiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('erp_ai.enabled', true);
        config()->set('ai_credits.enabled', true);

        $this->company = Company::create([
            'legal_name' => 'Ledger Rules Co ' . uniqid(),
            'trade_name' => 'RulesCo',
            'code' => 'RULE-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->user = User::create([
            'name' => 'Rule Tester ' . uniqid(),
            'email' => 'rule_' . uniqid() . '@example.com',
            'phone' => '99999' . rand(10000, 99999),
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->principal = new AiPrincipal(
            actorId: (int) $this->user->id,
            companyId: (int) $this->company->id,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['*'],
            requestId: (string) Str::uuid(),
            effectiveRole: 'Admin'
        );
    }

    /**
     * CR-01 & CR-02: First activation creates one period and exactly one grant; concurrent workers deduplicate.
     */
    public function test_cr_01_and_cr_02_idempotent_period_and_grant_initialization(): void
    {
        $resolver = app(CreditAccountResolver::class);
        $grantService = app(MonthlyCreditGrantService::class);

        $account = $resolver->resolve($this->company->id);

        // Call twice simulating two workers initializing simultaneously
        $period1 = $grantService->resolveCurrentPeriod($account->id);
        $period2 = $grantService->resolveCurrentPeriod($account->id);

        $this->assertEquals($period1->id, $period2->id);

        $periodCount = DB::table('ai_credit_periods')->where('account_id', $account->id)->count();
        $this->assertEquals(1, $periodCount);

        $grantsCount = DB::table('ai_credit_ledger')
            ->where('account_id', $account->id)
            ->where('kind', 'included_grant')
            ->count();
        $this->assertEquals(1, $grantsCount);
    }

    /**
     * CR-03 & CR-04: Month rollover expires prior unused balance and issues a fresh period.
     */
    public function test_cr_03_and_cr_04_monthly_period_rollover_and_expiry(): void
    {
        $resolver = app(CreditAccountResolver::class);
        $grantService = app(MonthlyCreditGrantService::class);
        $account = $resolver->resolve($this->company->id);

        // Previous month: October 2026
        $october = Carbon::parse('2026-10-15 12:00:00', 'UTC');
        $octPeriod = $grantService->resolveCurrentPeriod($account->id, $october);

        $this->assertEquals('active', $octPeriod->status);

        // Now move to November 2026
        $november = Carbon::parse('2026-11-05 12:00:00', 'UTC');
        $novPeriod = $grantService->resolveCurrentPeriod($account->id, $november);

        $this->assertNotEquals($octPeriod->id, $novPeriod->id);

        // October period is now closed
        $closedOct = DB::table('ai_credit_periods')->where('id', $octPeriod->id)->first();
        $this->assertEquals('closed', $closedOct->status);

        // Verify expiry ledger entry for unspent October credits
        $expiryEntry = DB::table('ai_credit_ledger')
            ->where('account_id', $account->id)
            ->where('period_id', $octPeriod->id)
            ->where('kind', 'expiry')
            ->first();

        $this->assertNotNull($expiryEntry);
        $this->assertLessThan(0, $expiryEntry->amount_credits);
    }

    /**
     * CR-05 & CR-08: Preflight reservation holds max credits, charges actual, and releases unused hold.
     */
    public function test_cr_05_and_cr_08_reservation_charges_actual_and_releases_difference(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $balanceBefore = $creditMeter->balance($this->company->id);

        $attemptId = 'att_' . Str::uuid();
        $res = $creditMeter->reserve(
            $this->principal,
            'run_cr_05',
            'op_cr_05',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_cr_05_' . Str::uuid(),
            100 // Hold 100 credits
        );

        $balanceDuring = $creditMeter->balance($this->company->id);
        $this->assertEquals($balanceBefore->reservedCredits + 100, $balanceDuring->reservedCredits);
        $this->assertEquals($balanceBefore->availableCredits - 100, $balanceDuring->availableCredits);

        // Provider completes using only 12 credits
        $settlement = $creditMeter->settle(
            $res->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            120, // tokens that cost ~12 credits
            40,
            isChargeable: true
        );

        $balanceAfter = $creditMeter->balance($this->company->id);

        // Reserved hold is back to baseline (released remaining 88 credits)
        $this->assertEquals($balanceBefore->reservedCredits, $balanceAfter->reservedCredits);
        // Spent increased by exactly debited amount
        $this->assertEquals($balanceBefore->spentCredits + $settlement->creditsDebited, $balanceAfter->spentCredits);
        // Available is baseline - debited
        $this->assertEquals($balanceBefore->availableCredits - $settlement->creditsDebited, $balanceAfter->availableCredits);
    }

    /**
     * CR-06: Preflight reservation fails closed when estimated cost exceeds available balance.
     */
    public function test_cr_06_insufficient_credits_rejects_before_provider_dispatch(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $balance = $creditMeter->balance($this->company->id);

        // Drain balance
        DB::table('ai_credit_periods')->where('id', $balance->periodId)->update([
            'spent_credits' => $balance->includedGranted + $balance->topupsGranted,
            'reserved_credits' => 0,
        ]);

        $this->expectException(AiCreditException::class);
        $creditMeter->reserve(
            $this->principal,
            'run_fail',
            'op_fail',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_cr_06',
            50
        );
    }

    /**
     * CR-09: Uncertain provider outcome flags reconcile_required without zero-cost assumption.
     */
    public function test_cr_09_uncertain_timeout_flags_reconcile_required(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $res = $creditMeter->reserve(
            $this->principal,
            'run_timeout',
            'op_timeout',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_cr_09_' . Str::uuid(),
            50
        );

        $attemptId = 'att_timeout_' . Str::uuid();

        // Settle with unknown/timeout outcome
        $settlement = $creditMeter->settle(
            $res->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            0,
            0,
            isChargeable: true,
            reconcileReason: 'GatewayTimeoutException'
        );

        $this->assertEquals('reconcile_required', $settlement->status);

        $reservationRow = DB::table('ai_credit_reservations')->where('id', $res->id)->first();
        $this->assertEquals('reconcile_required', $reservationRow->status);
    }

    /**
     * CR-10: Provider 429 release frees held credits safely without phantom debit.
     */
    public function test_cr_10_provider_429_release_frees_held_credits(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $balanceBefore = $creditMeter->balance($this->company->id);

        $res = $creditMeter->reserve(
            $this->principal,
            'run_429',
            'op_429',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_cr_10_' . Str::uuid(),
            80
        );

        // Abort/release unsent before execution
        $creditMeter->releaseUnsent($res->id, 'provider_429_rate_limited');

        $balanceAfter = $creditMeter->balance($this->company->id);

        $this->assertEquals($balanceBefore->reservedCredits, $balanceAfter->reservedCredits);
        $this->assertEquals($balanceBefore->availableCredits, $balanceAfter->availableCredits);

        $resRow = DB::table('ai_credit_reservations')->where('id', $res->id)->first();
        $this->assertEquals('released', $resRow->status);
    }

    /**
     * CR-12: Repeated reservation with same idempotency key returns prior reservation without double hold.
     */
    public function test_cr_12_same_idempotency_key_returns_existing_reservation(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $idempKey = 'idemp_unique_' . Str::uuid();

        $res1 = $creditMeter->reserve(
            $this->principal,
            'run_idemp',
            'op_idemp',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            $idempKey,
            30
        );

        $balance1 = $creditMeter->balance($this->company->id);

        $res2 = $creditMeter->reserve(
            $this->principal,
            'run_idemp',
            'op_idemp',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            $idempKey,
            30
        );

        $balance2 = $creditMeter->balance($this->company->id);

        $this->assertEquals($res1->id, $res2->id);
        $this->assertEquals($balance1->reservedCredits, $balance2->reservedCredits);
    }

    /**
     * CR-17: Refund/chargeback creates compensating entries and safely updates period balance.
     */
    public function test_cr_17_refund_creates_compensating_ledger_entries(): void
    {
        $topupService = app(AiTopupService::class);
        $creditMeter = app(AiCreditMeter::class);

        $order = $topupService->createOrder($this->company->id, 'PACK-5K', 'topup_order_cr17_' . Str::uuid());
        $topupService->recordPaymentSuccess($order->id, 'pay_17', 'evt_17', 'razorpay', ['status' => 'captured', 'amount' => $order->amount_minor_inr + $order->tax_minor_inr,
                    'currency' => 'INR', 'order_id' => $order->provider_order_id, 'id' => 'pay_17']);

        $balancePaid = $creditMeter->balance($this->company->id);

        // Now process refund
        $topupService->processRefund($order->id, 'ref_17_abc');

        $balanceRefunded = $creditMeter->balance($this->company->id);

        $this->assertEquals($balancePaid->topupsGranted - 5000, $balanceRefunded->topupsGranted);

        $refundLedger = DB::table('ai_credit_ledger')
            ->where('source_id', 'ref_17_abc')
            ->where('kind', 'refund')
            ->first();

        $this->assertNotNull($refundLedger);
        $this->assertEquals(-5000, $refundLedger->amount_credits);
    }

    /**
     * CR-20: Reconciliation service detects discrepancies between period totals and ledger rows.
     */
    public function test_cr_20_reconciliation_service_audits_ledger_integrity(): void
    {
        $reconciliation = app(AiCreditReconciliationService::class);
        $creditMeter = app(AiCreditMeter::class);

        $balance = $creditMeter->balance($this->company->id);
        $audit = $reconciliation->reconcilePeriod($balance->periodId);

        $this->assertTrue($audit['is_reconciled']);
        $this->assertEmpty($audit['discrepancies']);

        // Deliberately tamper with period counters to test detection
        DB::table('ai_credit_periods')->where('id', $balance->periodId)->update([
            'spent_credits' => 999999,
        ]);

        $tamperedAudit = $reconciliation->reconcilePeriod($balance->periodId);
        $this->assertFalse($tamperedAudit['is_reconciled']);
        $this->assertNotEmpty($tamperedAudit['discrepancies']);
    }

    /**
     * CR-26: Overspend prevention - Actual usage exceeding held reservation debits the FULL amount.
     * Prevents silent undercharging or unmetered LLM spend.
     */
    public function test_settlement_when_actual_usage_exceeds_held_reservation_debits_full_amount(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $settlementService = app(\App\Ai\Billing\Services\AiCreditSettlementService::class);

        // Preflight hold 10 credits
        $reservation = $creditMeter->reserve(
            $this->principal,
            (string) Str::uuid(),
            'test.overage_settlement',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'res_overage_' . Str::uuid(),
            10
        );

        $this->assertSame(10, $reservation->remainingHeldCredits);

        // Settle with large usage that costs 35 credits
        $attemptId = 'att_overage_' . Str::uuid();
        $settlement = $settlementService->settle(
            $reservation->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            inputTokens: 100000, // Large token count
            outputTokens: 25000,
            isChargeable: true
        );

        $this->assertGreaterThan(10, $settlement->creditsDebited);
        $this->assertSame('settled_with_overage', $settlement->status);

        // Assert ledger has the full debited amount
        $ledgerEntry = DB::table('ai_credit_ledger')
            ->where('provider_attempt_id', $attemptId)
            ->first();

        $this->assertNotNull($ledgerEntry);
        $this->assertSame(-$settlement->creditsDebited, (int) $ledgerEntry->amount_credits);

        // Assert reservation has 0 remaining held credits and status settled_with_overage
        $updatedRes = DB::table('ai_credit_reservations')->where('id', $reservation->id)->first();
        $this->assertSame(0, (int) $updatedRes->remaining_held_credits);
        $this->assertSame('settled_with_overage', $updatedRes->status);
    }

    /**
     * SEC-17: Missing model pricing must fail closed (422 RATE_CARD_MISSING), never falling back to generic rates.
     */
    public function test_sec_17_missing_rate_card_fails_closed_with_rate_card_missing_exception(): void
    {
        $calculator = app(ModelCostCalculator::class);

        $this->expectException(AiCreditException::class);
        $this->expectExceptionMessage('No billable rate card configured for model [unconfigured-model-999]');

        $calculator->calculate(
            provider: 'nvidia',
            model: 'unconfigured-model-999',
            inputTokens: 1000,
            outputTokens: 500
        );
    }

    /**
     * Overspend prevention: Preflight estimate exceeding configured maximum credit threshold fails closed.
     */
    public function test_preflight_estimate_exceeding_max_credits_per_request_throws_request_exceeds_credit_limit(): void
    {
        $calculator = app(ModelCostCalculator::class);
        config()->set('ai_credits.max_credits_per_request', 100);

        $this->expectException(AiCreditException::class);
        $this->expectExceptionMessage('exceeds the maximum configured per-request threshold [100]');

        $calculator->estimatePreflightCredits(
            provider: 'nvidia',
            model: 'nvidia/nemotron-3.5-lightning-30b-a3b',
            estimatedInputTokens: 500000,
            maxOutputTokens: 200000
        );
    }
}
