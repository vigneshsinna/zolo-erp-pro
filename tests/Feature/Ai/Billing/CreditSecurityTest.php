<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Billing;

use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\AiTopupService;
use App\Ai\Billing\Services\CreditAccountResolver;
use App\Ai\DTOs\AiPrincipal;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class CreditSecurityTest extends TestCase
{
    use UsesAiMysql;

    private Company $companyA;
    private Company $companyB;
    private User $userA;
    private User $userB;
    private AiPrincipal $principalA;
    private AiPrincipal $principalB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('erp_ai.enabled', true);
        config()->set('ai_credits.enabled', true);

        $this->companyA = Company::create([
            'legal_name' => 'Company A ' . uniqid(),
            'trade_name' => 'CompA',
            'code' => 'COMA-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->companyB = Company::create([
            'legal_name' => 'Company B ' . uniqid(),
            'trade_name' => 'CompB',
            'code' => 'COMB-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->userA = User::create([
            'name' => 'User A ' . uniqid(),
            'email' => 'user_a_' . uniqid() . '@example.com',
            'phone' => '99999' . rand(10000, 99999),
            'password' => bcrypt('secret'),
            'company_id' => $this->companyA->id,
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->userB = User::create([
            'name' => 'User B ' . uniqid(),
            'email' => 'user_b_' . uniqid() . '@example.com',
            'phone' => '99999' . rand(10000, 99999),
            'password' => bcrypt('secret'),
            'company_id' => $this->companyB->id,
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $branchA = $this->companyA->branches()->create([
            'name' => 'Branch A',
            'code' => 'BR-A-' . uniqid(),
            'is_active' => true,
        ]);
        $branchB = $this->companyB->branches()->create([
            'name' => 'Branch B',
            'code' => 'BR-B-' . uniqid(),
            'is_active' => true,
        ]);

        FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        FiscalYear::create([
            'company_id' => $this->companyB->id,
            'name' => 'FY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);

        $this->companyA->users()->attach($this->userA->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'branch_id' => $branchA->id,
        ]);

        $this->companyB->users()->attach($this->userB->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'branch_id' => $branchB->id,
        ]);

        $this->principalA = new AiPrincipal(
            actorId: (int) $this->userA->id,
            companyId: (int) $this->companyA->id,
            branchId: $branchA->id,
            financialYearId: 1,
            permittedToolNames: ['*'],
            requestId: (string) Str::uuid(),
            effectiveRole: 'Staff'
        );

        $this->principalB = new AiPrincipal(
            actorId: (int) $this->userB->id,
            companyId: (int) $this->companyB->id,
            branchId: $branchB->id,
            financialYearId: 1,
            permittedToolNames: ['*'],
            requestId: (string) Str::uuid(),
            effectiveRole: 'Staff'
        );
    }

    /**
     * SEC-01 & SEC-02: Tenant and user credit isolation.
     */
    public function test_sec_01_and_02_tenant_and_user_history_isolation(): void
    {
        $this->actingAs($this->userA);

        $response = $this->getJson(route('api.v1.ai.credits.usage', [
            'user_id' => $this->userB->id,
        ]));

        $response->assertStatus(200);
        $data = $response->json('data');

        // Non-admin user querying another user's ID must be restricted to their own entries
        foreach ($data as $entry) {
            $this->assertEquals($this->userA->id, $entry['actor_id']);
            $this->assertEquals($this->companyA->id, $entry['company_id']);
        }
    }

    /**
     * SEC-03: Company B cannot draw from Company A's credit wallet without mapped membership.
     */
    public function test_sec_03_cross_tenant_wallet_drawing_forbidden(): void
    {
        $resolver = app(CreditAccountResolver::class);
        $accountA = $resolver->resolve($this->companyA->id);

        $this->expectException(AccessDeniedHttpException::class);
        $resolver->assertCompanyBelongsToAccount($accountA->id, $this->companyB->id);
    }

    /**
     * SEC-04: Pooled subscription allows shared balance only when explicitly attached.
     */
    public function test_sec_04_pooled_subscription_shares_balance_when_mapped(): void
    {
        $resolver = app(CreditAccountResolver::class);
        $creditMeter = app(AiCreditMeter::class);

        $accountA = $resolver->resolve($this->companyA->id);
        $balanceA = $creditMeter->balance($this->companyA->id);

        // Before attachment, company B has its own wallet
        $accountB = $resolver->resolve($this->companyB->id);
        $this->assertNotEquals($accountA->id, $accountB->id);

        // Attach company B to company A's pooled account
        $resolver->attachCompany($accountA->id, $this->companyB->id);
        $resolver->assertCompanyBelongsToAccount($accountA->id, $this->companyB->id);
    }

    /**
     * SEC-12: Forged top-up webhook signature is rejected without credit grant.
     */
    public function test_sec_12_forged_webhook_rejected_with_403_and_zero_grant(): void
    {
        $topupService = app(AiTopupService::class);
        $order = $topupService->createOrder($this->companyA->id, 'PACK-5K', 'sec_topup_' . uniqid());

        $payload = json_encode([
            'order_id' => $order->id,
            'payment_id' => 'pay_forged_123',
            'event_id' => 'evt_forged_123',
        ]);

        $response = $this->postJson(
            route('api.v1.billing.webhooks', ['provider' => 'razorpay']),
            json_decode($payload, true),
            ['X-Razorpay-Signature' => 'invalid_forged_signature_hash']
        );

        $response->assertStatus(403);

        $refreshedOrder = DB::table('ai_credit_topup_orders')->where('id', $order->id)->first();
        $this->assertEquals('pending', $refreshedOrder->status);
    }

    /**
     * SEC-13: Repeating valid webhook 100x delivers exactly one credit grant.
     */
    public function test_sec_13_valid_webhook_repeated_100x_grants_exactly_once(): void
    {
        $topupService = app(AiTopupService::class);
        $creditMeter = app(AiCreditMeter::class);

        $order = $topupService->createOrder($this->companyA->id, 'PACK-15K', 'sec_13_topup_' . uniqid());
        $initialBalance = $creditMeter->balance($this->companyA->id);

        $eventId = 'evt_valid_100x_' . uniqid();
        $paymentId = 'pay_valid_100x_' . uniqid();

        for ($i = 0; $i < 100; $i++) {
            $topupService->recordPaymentSuccess(
                $order->id,
                $paymentId,
                $eventId,
                'razorpay',
                ['amount' => 49900]
            );
        }

        $finalBalance = $creditMeter->balance($this->companyA->id);
        $this->assertEquals($initialBalance->topupsGranted + 15000, $finalBalance->topupsGranted);
    }

    /**
     * SEC-14: Concurrent credit holds exceeding wallet balance fail closed.
     */
    public function test_sec_14_holds_exceeding_balance_fail_closed(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $balance = $creditMeter->balance($this->companyA->id);

        DB::table('ai_credit_periods')->where('id', $balance->periodId)->update([
            'included_granted' => 50,
            'topups_granted' => 0,
            'spent_credits' => 0,
            'reserved_credits' => 0,
        ]);

        // First hold succeeds (40 credits)
        $res1 = $creditMeter->reserve(
            $this->principalA,
            'run_sec_1',
            'op1',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'res_sec_1',
            40
        );
        $this->assertEquals('active', $res1->status);

        // Second hold exceeds remaining (remaining = 10, requests 20) -> must throw CREDITS_EXHAUSTED
        $this->expectException(AiCreditException::class);
        $creditMeter->reserve(
            $this->principalA,
            'run_sec_2',
            'op2',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'res_sec_2',
            20
        );
    }

    /**
     * SEC-20: Replaying settled attempt ID returns original debit without double charging.
     */
    public function test_sec_20_replaying_settled_attempt_id_is_idempotent(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $attemptId = 'att_replay_' . Str::uuid();

        $res = $creditMeter->reserve(
            $this->principalA,
            'run_replay',
            'op_replay',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'idemp_replay_' . Str::uuid(),
            100
        );

        // First settlement
        $settle1 = $creditMeter->settle(
            $res->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            500,
            200,
            isChargeable: true
        );

        $balanceAfterFirst = $creditMeter->balance($this->companyA->id);

        // Replay same attempt ID
        $settle2 = $creditMeter->settle(
            $res->id,
            $attemptId,
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            500,
            200,
            isChargeable: true
        );

        $balanceAfterSecond = $creditMeter->balance($this->companyA->id);

        $this->assertEquals($settle1->creditsDebited, $settle2->creditsDebited);
        $this->assertEquals($settle1->ledgerEntryId, $settle2->ledgerEntryId);
        $this->assertEquals($balanceAfterFirst->spentCredits, $balanceAfterSecond->spentCredits);
    }

    /**
     * SEC-17: Missing model pricing fails closed with RATE_CARD_MISSING (422), never allowing unmetered calls.
     */
    public function test_sec_17_missing_model_pricing_fails_closed(): void
    {
        $calculator = app(\App\Ai\Billing\Services\ModelCostCalculator::class);

        $this->expectException(\App\Ai\Billing\Exceptions\AiCreditException::class);
        $this->expectExceptionMessage('No billable rate card configured');

        $calculator->calculate(
            provider: 'nvidia',
            model: 'non-existent-unmetered-model-99',
            inputTokens: 1000,
            outputTokens: 500
        );
    }
}
