<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Gateway;

use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Multimodal\DTOs\PerceptionResult;
use App\Ai\Multimodal\Enums\ModelPurpose;
use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ProviderGatewayRateLimitAndSettlementTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private User $user;
    private AiPrincipal $principal;
    private ApprovedModelGateway $gateway;
    private AiCreditMeter $creditMeter;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        RateLimiter::clear('ai:rpm:openai');

        config()->set('erp_ai.enabled', true);
        config()->set('ai_credits.enabled', true);
        config()->set('erp_ai.nvidia_rpm_limit', 35);
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');

        $this->company = Company::create([
            'legal_name' => 'Gateway Test Corp ' . uniqid(),
            'trade_name' => 'GatewayCorp',
            'code' => 'GTW-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->user = User::create([
            'name' => 'Gateway Tester ' . uniqid(),
            'email' => 'gtw_' . uniqid() . '@example.com',
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

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 500000,
        ]);

        $this->gateway = app(ApprovedModelGateway::class);
        $this->creditMeter = app(AiCreditMeter::class);
    }

    /**
     * Test 1: Gateway strictly enforces 35-RPM rate limit for NVIDIA provider.
     * The first 35 requests succeed; the 36th call throws a 429 TooManyRequestsHttpException.
     */
    public function test_gateway_strictly_enforces_35_rpm_rate_limit_for_nvidia_provider(): void
    {
        RateLimiter::clear('ai:rpm:nvidia');

        // First 35 requests must succeed
        for ($i = 1; $i <= 35; $i++) {
            $this->gateway->enforceProviderRateLimit('nvidia');
        }

        $usage = $this->gateway->getProviderRpmUsage('nvidia');
        $this->assertSame(35, $usage['current']);
        $this->assertSame(0, $usage['remaining']);
        $this->assertSame('limited', $usage['status']);

        // The 36th request must throw 429
        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('AI provider request limit reached (35 requests per minute)');

        $this->gateway->enforceProviderRateLimit('nvidia');
    }

    /**
     * Test 2: When downstream provider fails, credit reservation hold is released with ZERO ledger debits.
     */
    public function test_provider_failure_releases_credit_reservation_hold_with_zero_ledger_debit(): void
    {
        $initialBalance = $this->creditMeter->balance($this->principal);
        $this->assertGreaterThan(0, $initialBalance->availableCredits);

        $mediaId = (string) Str::uuid();
        $date = now()->toDateString();

        $thrown = false;
        try {
            $this->gateway->admitPerceptionDispatch(
                $this->principal,
                $date,
                ModelPurpose::PerceptionVision,
                4096,
                function (string $provider, string $model): PerceptionResult {
                    throw new RuntimeException('Downstream NVIDIA NIM connection timeout (HTTP 504 Gateway Timeout)');
                },
                $mediaId
            );
        } catch (RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('NVIDIA NIM connection timeout', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException was not thrown');

        // Verify credit reservation was released
        $reservation = DB::table('ai_credit_reservations')
            ->where('company_id', $this->company->id)
            ->where('operation_key', 'perception.extract')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($reservation);
        $this->assertSame('released', $reservation->status);
        $this->assertSame(0, (int) $reservation->remaining_held_credits);

        // Verify ZERO debits in credit ledger for this reservation
        $debitEntries = DB::table('ai_credit_ledger')
            ->where('company_id', $this->company->id)
            ->where('kind', 'usage_debit')
            ->count();
        $this->assertSame(0, $debitEntries, 'Ledger debit must be 0 upon provider failure');

        // Balance must remain unchanged
        $postBalance = $this->creditMeter->balance($this->principal);
        $this->assertSame($initialBalance->availableCredits, $postBalance->availableCredits);

        // Invocation record must show failure with 0 credits debited
        $invocation = DB::table('ai_model_invocations')
            ->where('company_id', $this->company->id)
            ->where('reservation_id', $reservation->id)
            ->first();

        $this->assertNotNull($invocation);
        $this->assertSame('failed', $invocation->status);
        $this->assertSame(0, (int) $invocation->credits_debited);
    }

    /**
     * Test 3: Provider success settles atomically and debits ledger exactly once.
     */
    public function test_provider_success_settles_atomically_and_debits_ledger_exactly_once(): void
    {
        $initialBalance = $this->creditMeter->balance($this->principal);
        $mediaId = (string) Str::uuid();
        $date = now()->toDateString();

        $result = $this->gateway->admitPerceptionDispatch(
            $this->principal,
            $date,
            ModelPurpose::PerceptionVision,
            4096,
            function (string $provider, string $model): PerceptionResult {
                return new PerceptionResult(
                    rawText: 'Raw OCR text here',
                    parsedJson: [
                        'invoice_number' => 'INV-TEST-001',
                        'supplier_name' => 'Test Supplier',
                        'total_amount' => 1500.00,
                    ],
                    provider: $provider,
                    model: $model,
                    purpose: ModelPurpose::PerceptionVision,
                    inputTokens: 1250,
                    outputTokens: 420,
                    durationMs: 380,
                    httpAttempts: 1
                );
            },
            $mediaId
        );

        $this->assertSame('INV-TEST-001', $result->parsedJson['invoice_number']);

        // Verify reservation is settled
        $reservation = DB::table('ai_credit_reservations')
            ->where('company_id', $this->company->id)
            ->where('operation_key', 'perception.extract')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($reservation);
        $this->assertSame('settled', $reservation->status);
        $this->assertSame(0, (int) $reservation->remaining_held_credits);

        // Verify EXACTLY ONE debit entry in ledger for this attempt
        $ledgerEntries = DB::table('ai_credit_ledger')
            ->where('company_id', $this->company->id)
            ->where('kind', 'usage_debit')
            ->get();

        $this->assertCount(1, $ledgerEntries);
        $debit = $ledgerEntries->first();
        $this->assertLessThan(0, (int) $debit->amount_credits); // Negative for debit

        // Invocation record must show completed and exact matching credits debited
        $invocation = DB::table('ai_model_invocations')
            ->where('company_id', $this->company->id)
            ->where('reservation_id', $reservation->id)
            ->first();

        $this->assertNotNull($invocation);
        $this->assertSame('completed', $invocation->status);
        $this->assertSame(abs((int) $debit->amount_credits), (int) $invocation->credits_debited);

        // Idempotent duplicate settlement call must return existing settlement without creating second debit
        $secondSettlement = $this->creditMeter->settle(
            $reservation->id,
            $invocation->provider_attempt_id,
            $this->gateway->resolveApprovedPerceptionModel()['provider'],
            $this->gateway->resolveApprovedPerceptionModel()['model'],
            1250,
            420,
            imagesCount: 1,
            isChargeable: true
        );

        $this->assertSame(abs((int) $debit->amount_credits), $secondSettlement->creditsDebited);
        $this->assertSame(1, DB::table('ai_credit_ledger')->where('kind', 'usage_debit')->count());
    }

    /**
     * Test 4: Provider transient error cleans up hold upon exhaustion.
     */
    public function test_provider_transient_error_cleans_up_on_exhaustion(): void
    {
        $date = now()->toDateString();

        try {
            $this->gateway->admitPerceptionDispatch(
                $this->principal,
                $date,
                ModelPurpose::PerceptionVision,
                2048,
                function (string $provider, string $model): PerceptionResult {
                    throw new RuntimeException('Connection reset by peer after 3 retries');
                }
            );
        } catch (RuntimeException $e) {
            // Expected
        }

        // Verify that NO dangling 'active' held reservations remain
        $danglingHolds = DB::table('ai_credit_reservations')
            ->where('company_id', $this->company->id)
            ->where('status', 'active')
            ->count();

        $this->assertSame(0, $danglingHolds, 'All reservations must be resolved (released/settled), no dangling active holds.');
    }
}
