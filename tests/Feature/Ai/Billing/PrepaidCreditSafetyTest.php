<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Billing;

use App\Ai\Billing\DTOs\CreditSettlement;
use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\AiCreditReconciliationService;
use App\Ai\Billing\Services\AiCreditReservationService;
use App\Ai\Billing\Services\AiCreditSettlementService;
use App\Ai\Billing\Services\ModelCostCalculator;
use App\Ai\DTOs\AiPrincipal;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class PrepaidCreditSafetyTest extends TestCase
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
            'legal_name' => 'Prepaid Safety Co ' . uniqid(),
            'trade_name' => 'SafetyCo',
            'code' => 'SAFE-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->user = User::create([
            'name' => 'Safety Tester ' . uniqid(),
            'email' => 'safe_' . uniqid() . '@example.com',
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
     * BILL-01: Wallet 10 available, reserve 10, actual 35.
     * Customer debit <= 10; excess written to ai_provider_cost_variances; period marked reconcile_required; new dispatches blocked.
     */
    public function test_bill_01_insufficient_funds_on_overage_caps_customer_debit_and_records_variance(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $settlementService = app(AiCreditSettlementService::class);

        // Adjust period so exactly 10 credits are available
        $balance = $creditMeter->balance($this->company->id);
        DB::table('ai_credit_periods')->where('id', $balance->periodId)->update([
            'included_granted' => 10,
            'topups_granted' => 0,
            'spent_credits' => 0,
            'reserved_credits' => 0,
            'refunded_credits' => 0,
            'reconcile_required' => 0,
        ]);

        // Preflight hold 10 credits
        $reservation = $creditMeter->reserve(
            $this->principal,
            (string) Str::uuid(),
            'bill01.test',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_bill01_' . Str::uuid(),
            10
        );

        $this->assertSame(10, $reservation->remainingHeldCredits);

        // Settle with actual usage that costs 35 credits (10 held + 25 overage)
        $attemptId = 'att_bill01_' . Str::uuid();
        $settlement = $settlementService->settle(
            $reservation->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            inputTokens: 10000,
            outputTokens: 3570,
            isChargeable: true
        );

        // Customer must be debited ONLY what was available (10), never going negative!
        $this->assertSame(10, $settlement->creditsDebited);
        $this->assertSame('reconcile_required', $settlement->status);

        // Check customer ledger
        $ledgerEntry = DB::table('ai_credit_ledger')
            ->where('provider_attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertSame(-10, (int) $ledgerEntry->amount_credits);

        // Check provider cost variance record
        $variance = DB::table('ai_provider_cost_variances')
            ->where('provider_attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($variance, 'Variance record must be created in ai_provider_cost_variances');
        $this->assertSame(10, (int) $variance->customer_funded_credits);
        $this->assertSame(25, (int) $variance->unfunded_credits);
        $this->assertSame('unfunded_overage', $variance->incident_status);

        // Check period balance invariants
        $postBalance = $creditMeter->balance($this->company->id);
        $this->assertSame(0, $postBalance->availableCredits);
        $this->assertSame(0, $postBalance->rawAvailableCredits);
        $this->assertFalse($postBalance->isNegativeBreach);
        $this->assertTrue($postBalance->reconcileRequired);

        // New reservations MUST be rejected while reconcile_required is true
        $this->expectException(AiCreditException::class);
        $this->expectExceptionMessage('AI dispatch is paused pending credit reconciliation');
        $creditMeter->reserve(
            $this->principal,
            (string) Str::uuid(),
            'bill01.blocked',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_bill01_blocked_' . Str::uuid(),
            5
        );
    }

    /**
     * BILL-02: Wallet 100 available, reserve 10, actual 35.
     * Atomically fund extra 25, debit full 35, no negative balance, release remaining 65.
     */
    public function test_bill_02_sufficient_funds_on_overage_settles_full_amount_and_keeps_balance_positive(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $settlementService = app(AiCreditSettlementService::class);

        // Adjust period so 100 credits are available
        $balance = $creditMeter->balance($this->company->id);
        DB::table('ai_credit_periods')->where('id', $balance->periodId)->update([
            'included_granted' => 100,
            'topups_granted' => 0,
            'spent_credits' => 0,
            'reserved_credits' => 0,
            'refunded_credits' => 0,
            'reconcile_required' => 0,
        ]);

        $reservation = $creditMeter->reserve(
            $this->principal,
            (string) Str::uuid(),
            'bill02.test',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_bill02_' . Str::uuid(),
            10
        );

        $attemptId = 'att_bill02_' . Str::uuid();
        $settlement = $settlementService->settle(
            $reservation->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            inputTokens: 10000,
            outputTokens: 3570,
            isChargeable: true
        );

        $this->assertSame(35, $settlement->creditsDebited);
        $this->assertSame('settled_with_overage', $settlement->status);

        // No variance record created when fully funded by customer
        $variance = DB::table('ai_provider_cost_variances')
            ->where('provider_attempt_id', $attemptId)
            ->first();
        $this->assertNull($variance);

        $postBalance = $creditMeter->balance($this->company->id);
        $this->assertSame(65, $postBalance->availableCredits);
        $this->assertSame(65, $postBalance->rawAvailableCredits);
        $this->assertFalse($postBalance->isNegativeBreach);
    }

    /**
     * BILL-06: Idempotent settlement replaying the same attempt ID returns original debit without double charging.
     */
    public function test_bill_06_duplicate_settlement_attempt_is_idempotent(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $settlementService = app(AiCreditSettlementService::class);

        $reservation = $creditMeter->reserve(
            $this->principal,
            (string) Str::uuid(),
            'bill06.test',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_bill06_' . Str::uuid(),
            10
        );

        $attemptId = 'att_bill06_' . Str::uuid();
        $settle1 = $settlementService->settle(
            $reservation->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            inputTokens: 2000,
            outputTokens: 500,
            isChargeable: true
        );

        // Replay with identical attempt ID
        $settle2 = $settlementService->settle(
            $reservation->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            inputTokens: 2000,
            outputTokens: 500,
            isChargeable: true
        );

        $this->assertSame($settle1->ledgerEntryId, $settle2->ledgerEntryId);
        $this->assertSame($settle1->creditsDebited, $settle2->creditsDebited);

        // Verify exactly one ledger row in DB
        $count = DB::table('ai_credit_ledger')
            ->where('provider_attempt_id', $attemptId)
            ->count();
        $this->assertSame(1, $count);
    }

    /**
     * BILL-07: In-flight dispatch timeout marks unknown_billability and flags reconcile_required without freeing held credits.
     */
    public function test_bill_07_in_flight_timeout_does_not_free_held_credits(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $reconciliation = app(AiCreditReconciliationService::class);

        $reservation = $creditMeter->reserve(
            $this->principal,
            (string) Str::uuid(),
            'bill07.test',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_bill07_' . Str::uuid(),
            20
        );

        // Create an in-flight model invocation linked to this reservation
        DB::table('ai_model_invocations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'run_id' => (string) Str::uuid(),
            'reservation_id' => $reservation->id,
            'provider' => 'nvidia',
            'model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
            'status' => 'running', // in-flight
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        // Manually backdate the reservation expiration
        DB::table('ai_credit_reservations')
            ->where('id', $reservation->id)
            ->update(['expires_at' => now()->subMinutes(5)]);

        // Run sweep
        $result = $reconciliation->sweepExpiredReservations();

        // Must NOT release the held credits (0 released)
        $this->assertSame(0, $result['released_credits']);

        // Reservation marked unknown_billability
        $resAfter = DB::table('ai_credit_reservations')->where('id', $reservation->id)->first();
        $this->assertSame('unknown_billability', $resAfter->status);

        // Period flagged as requiring reconciliation
        $periodAfter = DB::table('ai_credit_periods')->where('id', $reservation->periodId)->first();
        $this->assertTrue((bool) $periodAfter->reconcile_required);
    }

    /**
     * BILL-09: Reusing same idempotency key with different payload returns 409 IDEMPOTENCY_CONFLICT.
     */
    public function test_bill_09_idempotency_key_payload_conflict_throws_409(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $key = 'idemp_conflict_' . Str::uuid();

        // First call with 10 credits
        $creditMeter->reserve(
            $this->principal,
            'run_alpha',
            'op.first',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            $key,
            10
        );

        // Second call with same key but different max credits (50 instead of 10)
        $this->expectException(AiCreditException::class);
        $this->expectExceptionMessage('Idempotency conflict for key');

        $creditMeter->reserve(
            $this->principal,
            'run_beta',
            'op.second',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            $key,
            50
        );
    }

    /**
     * BILL-10: Exact decimal ceiling without binary float casting across precision boundaries.
     */
    public function test_bill_10_exact_decimal_ceil_boundary_values(): void
    {
        $this->assertSame(0, ModelCostCalculator::decimalCeilToInt('0'));
        $this->assertSame(0, ModelCostCalculator::decimalCeilToInt('0.00000000'));
        $this->assertSame(1, ModelCostCalculator::decimalCeilToInt('0.00000001'));
        $this->assertSame(12, ModelCostCalculator::decimalCeilToInt('12.00000000'));
        $this->assertSame(13, ModelCostCalculator::decimalCeilToInt('12.00000001'));
        $this->assertSame(13, ModelCostCalculator::decimalCeilToInt('12.99999999'));
        $this->assertSame(100, ModelCostCalculator::decimalCeilToInt('100.0'));
        $this->assertSame(101, ModelCostCalculator::decimalCeilToInt('100.0000000001'));
    }

    /**
     * Test-only endpoint POST /__test/ai/credit-reserve-settle performs atomic reservation and settlement.
     */
    public function test_concurrent_reserve_settle_endpoint_succeeds_in_testing_env(): void
    {
        $response = $this->postJson('/__test/ai/credit-reserve-settle', [
            'idempotency_key' => 'idemp_endpoint_' . Str::uuid(),
            'max_credits' => 10,
            'actual_credits' => 8,
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'settlement' => [
                'status' => 'settled',
                'credits_debited' => 8,
                'credits_released' => 2,
            ],
        ]);
    }
}
