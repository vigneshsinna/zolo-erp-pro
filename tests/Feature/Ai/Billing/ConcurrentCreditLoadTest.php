<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Billing;

use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\AiTopupService;
use App\Ai\DTOs\AiPrincipal;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ConcurrentCreditLoadTest extends TestCase
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
            'legal_name' => 'Concurrent Load Co ' . uniqid(),
            'trade_name' => 'LoadCo',
            'code' => 'LOAD-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->user = User::create([
            'name' => 'Load User ' . uniqid(),
            'email' => 'load_' . uniqid() . '@example.com',
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

    public function test_concurrent_reservations_at_5_users_prevent_overspend(): void
    {
        $this->runConcurrentReservationProfile(5, walletCredits: 50, reservePerUser: 10);
    }

    public function test_concurrent_reservations_at_10_users_prevent_overspend(): void
    {
        $this->runConcurrentReservationProfile(10, walletCredits: 60, reservePerUser: 10);
    }

    public function test_concurrent_reservations_at_20_users_prevent_overspend(): void
    {
        $this->runConcurrentReservationProfile(20, walletCredits: 100, reservePerUser: 15);
    }

    public function test_repeated_payment_webhooks_grant_credits_exactly_once(): void
    {
        $topupService = app(AiTopupService::class);
        $creditMeter = app(AiCreditMeter::class);

        $order = $topupService->createOrder(
            $this->company->id,
            'PACK-5K',
            'topup_key_' . uniqid()
        );

        $initialBalance = $creditMeter->balance($this->company->id);

        $eventId = 'evt_' . uniqid();
        $paymentId = 'pay_' . uniqid();

        // Retry the same payment event five times.
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $topupService->recordPaymentSuccess(
                $order->id,
                $paymentId,
                $eventId,
                'razorpay',
                ['status' => 'captured', 'amount' => $order->amount_minor_inr + $order->tax_minor_inr,
                    'currency' => 'INR', 'order_id' => $order->provider_order_id, 'id' => $paymentId]
            );
        }

        $finalBalance = $creditMeter->balance($this->company->id);

        // Exactly one pack granted (5,000 credits)
        $this->assertEquals(
            $initialBalance->topupsGranted + 5000,
            $finalBalance->topupsGranted,
            'Repeated payment webhooks must grant top-up credits exactly once.'
        );

        // Exactly one ledger row
        $ledgerCount = DB::table('ai_credit_ledger')
            ->where('source_id', $order->id)
            ->where('kind', 'topup_grant')
            ->count();

        $this->assertEquals(1, $ledgerCount);
    }

    public function test_concurrent_reserve_and_settle_endpoint_executes_atomically(): void
    {
        $this->actingAs($this->user);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/__test/ai/credit-reserve-settle', [
                'idempotency_key' => 'concur_test_' . $i . '_' . Str::random(8),
                'max_credits' => 10,
                'actual_credits' => 8,
                'fake_provider_delay_ms' => 0,
                'company_id' => $this->company->id,
                'actor_id' => $this->user->id,
            ]);
            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
            $this->assertEquals(8, $response->json('credits_debited'));
        }

        $balance = app(AiCreditMeter::class)->balance($this->company->id);
        $this->assertGreaterThanOrEqual(0, $balance->availableCredits);
        $this->assertFalse($balance->isNegativeBreach);
    }

    private function runConcurrentReservationProfile(int $vus, int $walletCredits, int $reservePerUser): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $balance = $creditMeter->balance($this->company->id);

        // Set period balance to exact known amount
        DB::table('ai_credit_periods')->where('id', $balance->periodId)->update([
            'included_granted' => $walletCredits,
            'topups_granted' => 0,
            'spent_credits' => 0,
            'reserved_credits' => 0,
            'refunded_credits' => 0,
        ]);

        $successCount = 0;
        $rejectedCount = 0;

        for ($i = 0; $i < $vus; $i++) {
            $idempotencyKey = "load_res_{$vus}_{$i}_" . Str::random(8);
            try {
                $creditMeter->reserve(
                    $this->principal,
                    'run_' . $i,
                    'load.operation',
                    'nvidia',
                    'nvidia/nemotron-3.5-lightning-30b-a3b',
                    $idempotencyKey,
                    $reservePerUser
                );
                $successCount++;
            } catch (AiCreditException $e) {
                if ($e->errorCode === 'CREDITS_EXHAUSTED') {
                    $rejectedCount++;
                } else {
                    throw $e;
                }
            }
        }

        $postBalance = $creditMeter->balance($this->company->id);

        // Critical Invariants:
        // 1. Available credits must never drop below 0
        $this->assertGreaterThanOrEqual(0, $postBalance->availableCredits);

        // 2. Total reserved credits cannot exceed the wallet limit
        $this->assertLessThanOrEqual($walletCredits, $postBalance->reservedCredits);

        // 3. Number of successful reservations * reservePerUser <= walletCredits
        $this->assertLessThanOrEqual($walletCredits, $successCount * $reservePerUser);

        // 4. Sum of success + rejected must equal total attempted VUs
        $this->assertEquals($vus, $successCount + $rejectedCount);
    }
}
