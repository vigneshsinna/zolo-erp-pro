<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\Exceptions\AiCreditException;
use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

class AiTopupService
{
    public function __construct(
        protected CreditAccountResolver $accountResolver,
        protected MonthlyCreditGrantService $periodGrantService
    ) {}

    /**
     * Create a server-priced pending top-up order.
     */
    public function createOrder(
        AiPrincipal|int $principalOrCompanyId,
        string $packSku,
        string $idempotencyKey,
        string $paymentProvider = 'razorpay'
    ): stdClass {
        $packs = config('ai_credits.packs', []);
        if (! isset($packs[$packSku]) || ! ($packs[$packSku]['is_active'] ?? false)) {
            throw new InvalidArgumentException("Top-up pack SKU [{$packSku}] is invalid or unavailable.");
        }

        $pack = $packs[$packSku];
        $account = $this->accountResolver->resolve($principalOrCompanyId);

        return DB::transaction(function () use ($account, $pack, $idempotencyKey, $paymentProvider): stdClass {
            $existing = DB::table('ai_credit_topup_orders')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $orderId = (string) Str::uuid();
            $totalMinorInr = (int) ($pack['price_minor_inr'] + $pack['tax_minor_inr']);
            $providerOrderId = $this->generateProviderOrderId(
                paymentProvider: $paymentProvider,
                totalMinorInr: $totalMinorInr,
                currency: $pack['currency'] ?? 'INR',
                receiptId: 'rcpt_' . substr($orderId, 0, 12),
                notes: [
                    'account_id' => $account->id,
                    'pack_sku' => $pack['sku'],
                    'credits' => $pack['credits'],
                ]
            );

            DB::table('ai_credit_topup_orders')->insert([
                'id' => $orderId,
                'account_id' => $account->id,
                'pack_sku' => $pack['sku'],
                'credits' => $pack['credits'],
                'amount_minor_inr' => $pack['price_minor_inr'],
                'tax_minor_inr' => $pack['tax_minor_inr'],
                'billing_cycle' => 'one_time',
                'payment_provider' => $paymentProvider,
                'provider_order_id' => $providerOrderId,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('ai_credit_topup_orders')->where('id', $orderId)->first();
        });
    }

    /**
     * Atomically process a verified payment success event and grant credits.
     * Guaranteed idempotent across repeated webhook callbacks.
     */
    public function recordPaymentSuccess(
        string $orderId,
        string $providerPaymentId,
        string $eventId,
        string $paymentProvider = 'razorpay',
        array $rawPayload = []
    ): stdClass {
        $payloadHash = hash('sha256', json_encode($rawPayload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $orderId,
            $providerPaymentId,
            $eventId,
            $paymentProvider,
            $payloadHash,
            $rawPayload
        ): stdClass {
            $order = DB::table('ai_credit_topup_orders')
                ->where('payment_provider', $paymentProvider)
                ->where(fn ($query) => $query->where('id', $orderId)->orWhere('provider_order_id', $orderId))
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new InvalidArgumentException('Top-up order does not exist for this provider.');
            }

            $payment = $rawPayload['payload']['payment']['entity'] ?? $rawPayload;
            if (! is_array($payment) || ($payment['status'] ?? null) !== 'captured') {
                throw new InvalidArgumentException('Payment must be captured.');
            }

            $expectedTotal = (int) ($order->amount_minor_inr + $order->tax_minor_inr);
            if (($payment['amount'] ?? null) !== $expectedTotal) {
                throw new InvalidArgumentException('Payment amount must match the full order total.');
            }
            if (($payment['currency'] ?? null) !== 'INR') {
                throw new InvalidArgumentException('Payment currency must be INR.');
            }
            if (! $order->provider_order_id || ($payment['order_id'] ?? null) !== $order->provider_order_id) {
                throw new InvalidArgumentException('Payment order identity does not match.');
            }
            if ($providerPaymentId === '' || ($payment['id'] ?? $payment['payment_id'] ?? null) !== $providerPaymentId
                || $eventId === '' || strlen($eventId) > 255 || strlen($providerPaymentId) > 255) {
                throw new InvalidArgumentException('Payment or event identity is invalid.');
            }

            $existingEvent = DB::table('ai_credit_payment_events')
                ->where('payment_provider', $paymentProvider)
                ->where('event_id', $eventId)
                ->first();
            if ($existingEvent && (! hash_equals($existingEvent->payload_hash, $payloadHash)
                || $order->provider_payment_id !== $providerPaymentId)) {
                throw new InvalidArgumentException('Payment event identity was already used.');
            }

            if (in_array($order->status, ['paid', 'refunded'], true) && $order->provider_payment_id === $providerPaymentId) {
                return $order;
            }
            if ($order->status !== 'pending') {
                throw new InvalidArgumentException('Top-up order is not pending.');
            }

            // Record payment event
            $inserted = DB::table('ai_credit_payment_events')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'account_id' => $order->account_id,
                'payment_provider' => $paymentProvider,
                'event_id' => $eventId,
                'event_type' => 'payment.captured',
                'payload_hash' => $payloadHash,
                'status' => 'processed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted !== 1) {
                throw new InvalidArgumentException('Payment event identity was already used.');
            }

            $period = $this->periodGrantService->resolveCurrentPeriod($order->account_id);

            // Post top-up grant to immutable ledger
            $ledgerId = (string) Str::uuid();
            $idempotencyKey = "topup_grant_{$order->id}_{$providerPaymentId}";

            DB::table('ai_credit_ledger')->insert([
                'id' => $ledgerId,
                'account_id' => $order->account_id,
                'period_id' => $period->id,
                'kind' => 'topup_grant',
                'amount_credits' => (int) $order->credits,
                'currency' => 'INR',
                'cost_minor_inr' => (int) $order->amount_minor_inr,
                'source_type' => 'topup_order',
                'source_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            // Increment topups_granted on active period
            DB::table('ai_credit_periods')
                ->where('id', $period->id)
                ->increment('topups_granted', (int) $order->credits);

            // Update order status
            DB::table('ai_credit_topup_orders')
                ->where('id', $order->id)
                ->update([
                    'status' => 'paid',
                    'provider_payment_id' => $providerPaymentId,
                    'credit_grant_ledger_id' => $ledgerId,
                    'updated_at' => now(),
                ]);

            return DB::table('ai_credit_topup_orders')->where('id', $order->id)->first();
        });
    }

    /**
     * Handle payment failure or user cancellation.
     */
    public function recordPaymentFailure(string $orderId, string $reason = 'failed'): void
    {
        DB::table('ai_credit_topup_orders')
            ->where('id', $orderId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
    }

    /**
     * Process refund or chargeback with compensating ledger entry.
     */
    public function processRefund(string $orderId, string $refundId, string $reason = 'requested_refund'): void
    {
        DB::transaction(function () use ($orderId, $refundId, $reason): void {
            $order = DB::table('ai_credit_topup_orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order || $order->status !== 'paid') {
                return;
            }

            $period = $this->periodGrantService->resolveCurrentPeriod($order->account_id);

            // Compensating reversal entry
            $ledgerId = (string) Str::uuid();
            $idempotencyKey = "refund_{$order->id}_{$refundId}";

            DB::table('ai_credit_ledger')->insert([
                'id' => $ledgerId,
                'account_id' => $order->account_id,
                'period_id' => $period->id,
                'kind' => 'refund',
                'amount_credits' => -(int) $order->credits,
                'currency' => 'INR',
                'cost_minor_inr' => -(int) $order->amount_minor_inr,
                'source_type' => 'refund',
                'source_id' => $refundId,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            // Deduct from period counters
            DB::table('ai_credit_periods')
                ->where('id', $period->id)
                ->update([
                    'topups_granted' => DB::raw("GREATEST(0, topups_granted - {$order->credits})"),
                    'updated_at' => now(),
                ]);

            DB::table('ai_credit_topup_orders')
                ->where('id', $order->id)
                ->update([
                    'status' => 'refunded',
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Verify payment webhook HMAC signature.
     * Enforces non-empty secret; fails closed on missing secret or production dummy keys.
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature, ?string $secret = null): bool
    {
        $signingSecret = $secret !== null ? $secret : (string) config('ai_credits.webhook_secret', '');
        if (empty($signingSecret) || empty($signature)) {
            return false;
        }

        // In production, reject known test/fallback keys
        $isProduction = method_exists(app(), 'environment') ? app()->environment('production') : false;
        if ($isProduction && in_array($signingSecret, ['whsec_zolo_credits_secret_key_2026', 'dummy', 'secret'], true)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $signingSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Resolve Razorpay key and secret from configuration, ExternalService, or GeneralSetting.
     *
     * @return array{key: ?string, secret: ?string}
     */
    protected function resolveRazorpayCredentials(): array
    {
        $key = config('services.razorpay.key') ?: env('RAZORPAY_KEY');
        $secret = config('services.razorpay.secret') ?: env('RAZORPAY_SECRET');

        if (! empty($key) && ! empty($secret)) {
            return ['key' => (string) $key, 'secret' => (string) $secret];
        }

        try {
            $service = DB::table('external_services')->where('name', 'Razorpay')->first();
            if ($service && ! empty($service->details)) {
                $cred = explode(';', $service->details);
                if (isset($cred[1])) {
                    [$k, $s] = explode(',', $cred[1]);
                    return ['key' => trim($k), 'secret' => trim($s)];
                }
            }
        } catch (\Throwable) {}

        try {
            $general = DB::table('general_settings')->first();
            if ($general && ! empty($general->razorpay_key) && ! empty($general->razorpay_secret)) {
                return ['key' => trim($general->razorpay_key), 'secret' => trim($general->razorpay_secret)];
            }
        } catch (\Throwable) {}

        return ['key' => null, 'secret' => null];
    }

    /**
     * Create an authoritative payment order on the payment provider (e.g. Razorpay).
     *
     * @param array<string, mixed> $notes
     */
    protected function generateProviderOrderId(
        string $paymentProvider,
        int $totalMinorInr,
        string $currency,
        string $receiptId,
        array $notes = []
    ): string {
        if ($paymentProvider === 'razorpay') {
            $creds = $this->resolveRazorpayCredentials();
            if (! empty($creds['key']) && ! empty($creds['secret'])) {
                try {
                    $api = new \Razorpay\Api\Api($creds['key'], $creds['secret']);
                    $order = $api->order->create([
                        'receipt' => $receiptId,
                        'amount' => $totalMinorInr,
                        'currency' => $currency,
                        'notes' => $notes,
                    ]);

                    return (string) $order['id'];
                } catch (\Throwable $e) {
                    $isProduction = method_exists(app(), 'environment') ? app()->environment('production') : false;
                    if ($isProduction) {
                        throw new \RuntimeException('Razorpay order creation failed: ' . $e->getMessage(), 0, $e);
                    }
                }
            } else {
                $isProduction = method_exists(app(), 'environment') ? app()->environment('production') : false;
                if ($isProduction) {
                    throw new \RuntimeException('Payment provider [razorpay] credentials are required in production.');
                }
            }
        }

        return 'order_' . Str::random(16);
    }
}
