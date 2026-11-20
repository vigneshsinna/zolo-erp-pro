<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Billing;

use App\Ai\Billing\DTOs\SubscriptionEntitlement;
use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\Billing\Services\AiTopupService;
use App\Ai\Billing\Services\CreditAccountResolver;
use App\Ai\Billing\Services\MonthlyCreditGrantService;
use Tests\TestCase;

final class SubscriptionAndPaymentHardeningTest extends TestCase
{
    public function test_subscription_entitlement_evaluates_active_and_renewal_statuses(): void
    {
        $active = new SubscriptionEntitlement(
            isActive: true,
            subscriptionId: 'sub_live_123',
            planSku: 'pro',
            includedMonthlyCredits: 20000,
            monthlyMediaPages: 100,
            maxConcurrentRuns: 5,
            renewalStatus: 'active'
        );
        $this->assertTrue($active->allowsAiUsage());

        $trial = new SubscriptionEntitlement(
            isActive: true,
            subscriptionId: 'sub_trial_123',
            planSku: 'free_trial',
            includedMonthlyCredits: 2000,
            monthlyMediaPages: 10,
            maxConcurrentRuns: 1,
            renewalStatus: 'trial'
        );
        $this->assertTrue($trial->allowsAiUsage());

        $expired = new SubscriptionEntitlement(
            isActive: false,
            subscriptionId: 'sub_exp_123',
            planSku: 'pro',
            includedMonthlyCredits: 0,
            monthlyMediaPages: 0,
            maxConcurrentRuns: 0,
            renewalStatus: 'expired'
        );
        $this->assertFalse($expired->allowsAiUsage());

        $unsubscribed = new SubscriptionEntitlement(
            isActive: false,
            subscriptionId: null,
            planSku: 'none',
            includedMonthlyCredits: 0,
            monthlyMediaPages: 0,
            maxConcurrentRuns: 0,
            renewalStatus: 'unsubscribed'
        );
        $this->assertFalse($unsubscribed->allowsAiUsage());
    }

    public function test_ai_credit_exception_supports_subscription_required(): void
    {
        $ex = AiCreditException::subscriptionRequired('No active subscription found.');
        $this->assertSame('SUBSCRIPTION_REQUIRED', $ex->errorCode);
        $this->assertSame(402, $ex->getStatusCode());
        $this->assertSame('No active subscription found.', $ex->getMessage());
        $this->assertSame('SUBSCRIPTION_REQUIRED', $ex->getHeaders()['X-AI-Error-Code']);
    }

    public function test_webhook_signature_verification_fails_closed_when_secret_is_empty(): void
    {
        $resolver = $this->createStub(CreditAccountResolver::class);
        $grantService = $this->createStub(MonthlyCreditGrantService::class);
        $topupService = new AiTopupService($resolver, $grantService);

        $payload = json_encode(['event' => 'payment.captured']);
        $signature = hash_hmac('sha256', $payload, 'secret');

        // With empty secret, must return false
        $this->assertFalse($topupService->verifyWebhookSignature($payload, $signature, secret: ''));
        $this->assertFalse($topupService->verifyWebhookSignature($payload, '', secret: 'secret'));
    }

    public function test_webhook_signature_verification_succeeds_with_valid_hmac_sha256(): void
    {
        $resolver = $this->createStub(CreditAccountResolver::class);
        $grantService = $this->createStub(MonthlyCreditGrantService::class);
        $topupService = new AiTopupService($resolver, $grantService);

        $payload = json_encode(['order_id' => 'order_12345', 'amount' => 23482]);
        $secret = 'whsec_secure_random_key_9876543210';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($topupService->verifyWebhookSignature($payload, $signature, secret: $secret));
        $this->assertFalse($topupService->verifyWebhookSignature($payload, 'forged_signature', secret: $secret));
    }

    public function test_multimodal_configuration_defaults_to_false_for_direct_purchase_bill(): void
    {
        $this->assertFalse($this->withoutEnvironment('AI_MULTIMODAL_ALLOW_DIRECT_PURCHASE_BILL',
            fn () => (require config_path('ai_multimodal.php'))['allow_direct_purchase_bill']));
    }

    public function test_credits_configuration_includes_plan_catalog_and_require_subscription_flag(): void
    {
        $config = require __DIR__ . '/../../../../config/ai_credits.php';
        $this->assertArrayHasKey('require_subscription', $config);
        $this->assertIsBool($config['require_subscription']);
        $this->assertArrayHasKey('plans', $config);
        $this->assertArrayHasKey('starter', $config['plans']);
        $this->assertArrayHasKey('pro', $config['plans']);
        $this->assertArrayHasKey('enterprise', $config['plans']);
        $this->assertSame(20000, $config['plans']['pro']['included_monthly_credits']);
        $this->assertSame(5000, $config['plans']['starter']['included_monthly_credits']);
        $this->assertSame(100000, $config['plans']['enterprise']['included_monthly_credits']);
        $this->assertSame('', $this->withoutEnvironment('AI_CREDITS_WEBHOOK_SECRET',
            fn () => (require config_path('ai_credits.php'))['webhook_secret']));
    }

    private function withoutEnvironment(string $name, callable $read): mixed
    {
        $process = getenv($name);
        $env = $_ENV[$name] ?? null;
        $server = $_SERVER[$name] ?? null;
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        try {
            return $read();
        } finally {
            if ($process !== false) {
                putenv($name.'='.$process);
            }
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }
    }
}
