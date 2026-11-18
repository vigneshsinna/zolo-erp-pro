<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Billing\Contracts\AiCreditMeterInterface;
use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\AiTopupService;
use App\Ai\Billing\Services\CreditAccountResolver;
use App\Ai\Billing\Services\ModelCostCalculator;
use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AiCreditController extends Controller
{
    public function __construct(
        protected AiCreditMeter $creditMeter,
        protected AiTopupService $topupService,
        protected CreditAccountResolver $accountResolver,
        protected ModelCostCalculator $calculator,
        protected CompanyContextResolver $contextResolver,
        protected AiPrincipalAuthorizerInterface $authorizer
    ) {}

    /**
     * GET /api/v1/ai/credits/balance
     * Authoritative wallet balance, media page capacity and period dates.
     */
    public function balance(Request $request): JsonResponse
    {
        $context = $this->contextResolver->forActor($request->attributes->get(CompanyContext::class), (int) Auth::id());
        $balance = $this->creditMeter->balance($context->companyId);

        return response()->json([
            'success' => true,
            'data' => $balance->toArray(),
        ]);
    }

    /**
     * GET /api/v1/ai/credits/usage
     * Paginated audit log of usage debits, grants, and attempt records.
     */
    public function usage(Request $request): JsonResponse
    {
        $context = $this->contextResolver->forActor($request->attributes->get(CompanyContext::class), (int) Auth::id());
        $account = $this->accountResolver->resolve($context->companyId);

        $currentUser = Auth::user();
        $canViewAll = $currentUser?->hasRole('Admin')
            || $currentUser?->hasRole('Super Admin')
            || $currentUser?->can('accounting.reports.view');

        $query = DB::table('ai_credit_ledger')
            ->where('account_id', $account->id)
            ->where('company_id', $context->companyId);

        // SEC-01: Non-admin users can ONLY view their own credit history
        if (! $canViewAll) {
            $query->where('actor_id', $currentUser?->id ?? 0);
        } elseif ($request->filled('user_id')) {
            $query->where('actor_id', (int) $request->input('user_id'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $entries = $query->orderByDesc('occurred_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $entries->items(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
                'per_page' => $entries->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/ai/credits/packs
     * List approved credit top-up pack SKUs, pricing and GST breakdown.
     */
    public function packs(): JsonResponse
    {
        $allPacks = config('ai_credits.packs', []);
        $activePacks = array_values(array_filter($allPacks, fn (array $p) => $p['is_active'] ?? false));

        return response()->json([
            'success' => true,
            'currency' => 'INR',
            'policy_version' => config('ai_credits.credit_policy_version', 'cost_v1'),
            'credit_value_paisa' => 1,
            'packs' => $activePacks,
            'terms' => 'Credits are valid for model execution. Unused credits expire at end of current billing month.',
        ]);
    }

    /**
     * POST /api/v1/ai/credits/topup-orders
     * Create a server-priced pending top-up order. Requires Idempotency-Key.
     */
    public function createTopupOrder(Request $request): JsonResponse
    {
        $context = $this->contextResolver->forActor($request->attributes->get(CompanyContext::class), (int) Auth::id());

        $request->validate([
            'pack_sku' => 'required|string',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: (string) $request->input('idempotency_key');
        if (empty($idempotencyKey)) {
            return response()->json([
                'success' => false,
                'error' => 'Idempotency-Key header is required for top-up orders.',
            ], 422);
        }

        $packSku = (string) $request->input('pack_sku');
        $paymentProvider = (string) $request->input('payment_provider', 'razorpay');

        $order = $this->topupService->createOrder(
            $context->companyId,
            $packSku,
            $idempotencyKey,
            $paymentProvider
        );

        return response()->json([
            'success' => true,
            'order' => $order,
        ], 201);
    }

    /**
     * GET /api/v1/ai/credits/estimate
     * Conservative advisory credit estimate for action/model/file size.
     */
    public function estimate(Request $request): JsonResponse
    {
        $provider = (string) $request->input('provider', 'nvidia');
        $model = (string) $request->input('model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        $inputBytes = (int) $request->input('estimated_input_bytes', 1024);
        $outputTokens = (int) $request->input('max_output_tokens', 1536);
        $imagesCount = (int) $request->input('images_count', 0);

        $inputTokens = (int) ceil($inputBytes / 4);

        $credits = $this->calculator->estimatePreflightCredits(
            $provider,
            $model,
            $inputTokens,
            $outputTokens,
            $imagesCount
        );

        return response()->json([
            'success' => true,
            'estimated_credits' => $credits,
            'estimated_inr' => round($credits * 0.01, 2),
            'currency' => 'INR',
            'is_advisory' => true,
        ]);
    }

    /**
     * POST /api/v1/billing/webhooks/{provider}
     * Signature-verified payment provider callback. Grants credits atomically.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = (string) (
            $request->header('X-Razorpay-Signature')
            ?: $request->header('X-Webhook-Signature')
            ?: $request->header('X-Signature', '')
        );

        // SEC-12: Forged webhooks rejected without grant
        if (! $this->topupService->verifyWebhookSignature($rawPayload, $signature)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid webhook signature.',
            ], 403);
        }

        $payload = $request->json()->all();
        $paymentEntity = $payload['payload']['payment']['entity'] ?? $payload;
        $orderId = $paymentEntity['order_id'] ?? null;
        $paymentId = $paymentEntity['id'] ?? $paymentEntity['payment_id'] ?? null;
        $eventId = $payload['event_id'] ?? $payload['id'] ?? $paymentId;

        if (! is_string($orderId) || $orderId === '' || ! is_string($paymentId) || $paymentId === ''
            || ! is_string($eventId) || $eventId === '') {
            return response()->json(['success' => false, 'error' => 'Invalid order, payment or event identity in webhook payload.'], 422);
        }

        try {
            $order = $this->topupService->recordPaymentSuccess(
                $orderId,
                $paymentId,
                $eventId,
                $provider,
                $payload
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'status' => $order->status,
        ]);
    }

    /**
     * POST /__test/ai/credit-reserve-settle
     * Testing-only endpoint to atomically reserve and settle credits for concurrent load testing.
     * Strictly disabled in non-testing environments (P0-03).
     */
    public function testReserveSettle(Request $request): JsonResponse
    {
        if (! app()->environment('testing')) {
            abort(404);
        }

        $validated = $request->validate([
            'idempotency_key' => 'required|string',
            'max_credits' => 'nullable|integer|min:1',
            'actual_credits' => 'nullable|integer|min:0',
            'fake_provider_delay_ms' => 'nullable|integer|min:0|max:1000',
            'company_id' => 'nullable|integer',
            'actor_id' => 'nullable|integer',
        ]);

        $companyId = (int) ($validated['company_id'] ?? Auth::user()?->company_id ?? 1);
        $actorId = (int) ($validated['actor_id'] ?? Auth::id() ?? 1);

        $principal = new \App\Ai\DTOs\AiPrincipal(
            actorId: $actorId,
            companyId: $companyId,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['*'],
            requestId: (string) \Illuminate\Support\Str::uuid(),
            effectiveRole: 'Admin'
        );

        $idempotencyKey = (string) $validated['idempotency_key'];
        $maxCredits = (int) ($validated['max_credits'] ?? 10);
        $actualCredits = (int) ($validated['actual_credits'] ?? min($maxCredits, 8));
        $delayMs = (int) ($validated['fake_provider_delay_ms'] ?? 0);

        try {
            // 1. Reserve preflight credits atomically
            $reservation = $this->creditMeter->reserve(
                $principal,
                $principal->requestId,
                'test.concurrent_settle',
                'nvidia',
                'nvidia/nemotron-3.5-lightning-30b-a3b',
                $idempotencyKey,
                $maxCredits
            );

            // 2. Simulate provider delay if requested
            if ($delayMs > 0) {
                usleep(min(100000, $delayMs * 1000));
            }

            // 3. Atomically settle credits
            // Compute input token count that cleanly produces exactly $actualCredits
            $fx = (float) config('ai_credits.default_fx_rate_usd_inr', 84.50);
            $inputTokens = $actualCredits > 0
                ? (int) floor(($actualCredits * 0.01 / $fx) / 0.00000020)
                : 0;

            $settlement = $this->creditMeter->settlementService()->settle(
                $reservation->id,
                'test_att_' . $idempotencyKey,
                'nvidia',
                'nvidia/nemotron-3.5-lightning-30b-a3b',
                inputTokens: $inputTokens,
                outputTokens: 0,
                isChargeable: $actualCredits > 0
            );

            $balance = $this->creditMeter->balance($companyId);

            return response()->json([
                'success' => true,
                'reservation_id' => $reservation->id,
                'credits_debited' => $settlement->creditsDebited,
                'settlement' => [
                    'status' => $settlement->status,
                    'credits_debited' => $settlement->creditsDebited,
                    'credits_released' => $settlement->creditsReleased,
                ],
                'balance' => $balance->toArray(),
            ], 200);

        } catch (\App\Ai\Billing\Exceptions\AiCreditException $e) {
            return response()->json([
                'success' => false,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details,
            ], $e->getStatusCode());
        }
    }
}
