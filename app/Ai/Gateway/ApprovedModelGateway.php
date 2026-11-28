<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use App\Ai\Billing\Contracts\AiCreditMeterInterface;
use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\DTOs\PerceptionResult;
use App\Ai\Multimodal\Enums\ModelPurpose;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Services\AiFeatureGate;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\TextUsage;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class ApprovedModelGateway
{
    private ?string $dispatchProvider = null;
    private int $dispatchAttempts = 0;

    protected array $allowedModels = [
        'openai' => ['gpt-4o-mini', 'gpt-4o'],
        'nvidia' => [
            'deepseek-ai/deepseek-v4.1-flash',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            'nvidia/nemotron-3.5-bolt-e4b',
            'nvidia/nemotron-3.5-super-vl-120b-a12b',
        ],
        'deepseek' => [
            'deepseek-ai/deepseek-v4.1-flash',
            'deepseek-chat',
            'deepseek-reasoner',
            'deepseek-flash',
            'deepseek-v4-pro',
        ],
        'openai-compatible' => ['deepseek-ai/deepseek-v4.1-flash'],
        'gemini' => ['gemini-1.5-flash', 'gemini-2.0-flash'],
        'anthropic' => ['claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022'],
    ];

    /**
     * Dedicated perception models strictly isolated from reasoning/tool-orchestration models.
     *
     * @var array<string, array<int, string>>
     */
    protected array $allowedPerceptionModels = [
        'nvidia' => [
            'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
        ],
    ];

    protected AiCreditMeter $creditMeter;

    public function __construct(protected AiFeatureGate $featureGate, ?AiCreditMeter $creditMeter = null)
    {
        $this->creditMeter = $creditMeter ?? app(AiCreditMeter::class);
    }

    /** @return array{provider: string, model: string} */
    public function resolveApprovedModel(): array
    {
        $provider = config('erp_ai.provider', 'openai');
        if (!isset($this->allowedModels[$provider])) {
            throw new InvalidArgumentException('AI provider is outside the approved allowlist.');
        }

        $model = config('erp_ai.model');

        return [
            'provider' => $provider,
            'model' => $this->isApproved($provider, $model ?? '') ? $model : $this->allowedModels[$provider][0],
        ];
    }

    public function isApproved(string $provider, string $model): bool
    {
        return in_array($model, $this->allowedModels[$provider] ?? [], true);
    }

    /** @return array{provider: string, model: string} */
    public function resolveApprovedPerceptionModel(): array
    {
        $provider = (string) config('ai_multimodal.provider', 'nvidia');
        if (! isset($this->allowedPerceptionModels[$provider])) {
            throw new InvalidArgumentException('Multimodal perception provider is outside the approved allowlist.');
        }

        $model = (string) config('ai_multimodal.model', '');
        if ($model === '' || ! $this->isApprovedPerceptionModel($provider, $model)) {
            throw new InvalidArgumentException('Multimodal perception model is outside the approved allowlist.');
        }

        return [
            'provider' => $provider,
            'model' => $model,
        ];
    }

    public function isApprovedPerceptionModel(string $provider, string $model): bool
    {
        return in_array($model, $this->allowedPerceptionModels[$provider] ?? [], true);
    }

    /**
     * Enforce strict separation between reasoning/tool models and perception-only models.
     */
    public function assertPurposeAllowsModel(ModelPurpose $purpose, string $provider, string $model): void
    {
        if ($purpose->isPerception()) {
            if (! $this->isApprovedPerceptionModel($provider, $model)) {
                throw new InvalidArgumentException("Model [{$model}] is not approved for perception purpose [{$purpose->value}].");
            }

            return;
        }

        if (! $this->isApproved($provider, $model)) {
            throw new InvalidArgumentException("Model [{$model}] is not approved for reasoning purpose.");
        }
    }

    /**
     * Atomically reserve a daily company media upload slot spanning creation.
     */
    public function reserveMediaUpload(AiPrincipal $principal, string $date): MediaUploadReservation
    {
        if (! (bool) config('ai_multimodal.enabled', false)) {
            throw MultimodalException::featureDisabled();
        }

        $reserveKey = "ai:media_reserved:{$principal->companyId}:{$date}";
        $lockKey = "ai:media_lock:{$principal->companyId}:{$date}";

        $lock = Cache::lock($lockKey, 10);
        $acquired = $lock->block(5);
        if (! $acquired) {
            throw MultimodalException::dailyLimitExceeded(100);
        }

        try {
            $setting = DB::table('ai_feature_settings')->where('company_id', $principal->companyId)->first();
            if (! (bool) ($setting->enabled ?? false) || ! $this->featureGate->isEnabledForCompany($principal->companyId)) {
                throw new ServiceUnavailableHttpException(message: 'AI capabilities are disabled for this company.');
            }
            if (! (bool) ($setting->multimodal_enabled ?? false)) {
                throw MultimodalException::featureDisabled();
            }

            $dailyLimit = min(
                (int) config('ai_multimodal.daily_company_uploads', 100),
                max(1, (int) ($setting->daily_media_upload_limit ?? 100))
            );

            $todayCount = DB::table('ai_media_assets')
                ->where('company_id', $principal->companyId)
                ->whereDate('created_at', $date)
                ->count();

            $reservedCount = (int) Cache::get($reserveKey, 0);

            if (($todayCount + $reservedCount) >= $dailyLimit) {
                throw MultimodalException::dailyLimitExceeded($dailyLimit);
            }

            Cache::put($reserveKey, $reservedCount + 1, now()->addMinutes(2));
        } finally {
            $lock->release();
        }

        return new MediaUploadReservation(
            companyId: $principal->companyId,
            date: $date,
            onRelease: function () use ($reserveKey, $lockKey): void {
                $releaseLock = Cache::lock($lockKey, 10);
                if ($releaseLock->block(5)) {
                    try {
                        $curr = (int) Cache::get($reserveKey, 0);
                        if ($curr > 1) {
                            Cache::put($reserveKey, $curr - 1, now()->addMinutes(2));
                        } else {
                            Cache::forget($reserveKey);
                        }
                    } finally {
                        $releaseLock->release();
                    }
                }
            }
        );
    }

    /**
     * Enforce company multimodal enablement and daily company media upload quota.
     */
    public function admitMediaUpload(AiPrincipal $principal, string $date): void
    {
        $reservation = $this->reserveMediaUpload($principal, $date);
        $reservation->release();
    }

    /**
     * Reserve tokens, enforce the shared NVIDIA RPM bucket, execute perception, and meter usage.
     *
     * @param Closure(string, string): PerceptionResult $callback
     * @param array<string, mixed> $options
     */
    public function admitPerceptionDispatch(
        AiPrincipal $principal,
        string $date,
        ModelPurpose $purpose,
        int $estimatedInputBytes,
        Closure $callback,
        ?string $mediaId = null,
        array $options = [],
    ): PerceptionResult {
        if (! $purpose->isPerception()) {
            throw new InvalidArgumentException('admitPerceptionDispatch only accepts perception purposes.');
        }

        if (! (bool) config('ai_multimodal.enabled', false)) {
            throw MultimodalException::featureDisabled();
        }

        ['provider' => $provider, 'model' => $model] = $this->resolveApprovedPerceptionModel();
        $this->assertPurposeAllowsModel($purpose, $provider, $model);

        $outputLimit = (int) config('ai_multimodal.max_extraction_tokens', 4096);
        $reserved = max(1024, (int) ceil($estimatedInputBytes / 4)) + $outputLimit;

        DB::transaction(function () use ($principal, $date, $reserved): void {
            $setting = $this->lockCompany($principal);
            if (! (bool) ($setting->multimodal_enabled ?? false)) {
                throw MultimodalException::featureDisabled();
            }

            if ($this->remainingTokens($principal, $date, $setting) < $reserved) {
                throw new TooManyRequestsHttpException(message: 'Daily company AI token quota cannot cover the next perception step.');
            }

            DB::table('ai_usage_counters')->insertOrIgnore([
                'company_id' => $principal->companyId,
                'user_id' => $principal->actorId,
                'date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($this->counter($principal, $date)->increment('reserved_tokens', $reserved) !== 1) {
                throw new ServiceUnavailableHttpException(message: 'AI usage accounting is unavailable.');
            }
        }, 3);

        // Preflight credit metering reservation
        $inputTokensEstimate = max(1024, (int) ceil($estimatedInputBytes / 4));
        $estimatedCredits = $this->creditMeter->calculator()->estimatePreflightCredits(
            $provider,
            $model,
            $inputTokensEstimate,
            $outputLimit,
            imagesCount: 1
        );
        $attemptId = isset($options['provider_attempt_id']) && is_string($options['provider_attempt_id']) && $options['provider_attempt_id'] !== ''
            ? $options['provider_attempt_id']
            : ('att_' . Str::uuid());
        $idempotencyKey = isset($options['reservation_idempotency_key']) && is_string($options['reservation_idempotency_key']) && $options['reservation_idempotency_key'] !== ''
            ? $options['reservation_idempotency_key']
            : ('res_perc_' . ($mediaId ?: Str::uuid()) . '_' . $attemptId);
        $mediaTaskId = isset($options['media_task_id']) && is_string($options['media_task_id']) ? $options['media_task_id'] : null;
        $pageNumber = max(1, (int) ($options['page_number'] ?? 1));
        $attemptNumber = max(1, (int) ($options['attempt_number'] ?? 1));

        $creditReservation = $this->creditMeter->reserve(
            $principal,
            $principal->requestId,
            'perception.extract',
            $provider,
            $model,
            $idempotencyKey,
            $estimatedCredits
        );

        $invocationRowId = (string) Str::uuid();
        $attemptRowId = null;

        try {
            $this->enforceProviderRateLimit($provider);

            if ($mediaTaskId !== null && $mediaId !== null) {
                $attemptRowId = $this->upsertMediaProviderAttempt(
                    taskId: $mediaTaskId,
                    companyId: $principal->companyId,
                    mediaId: $mediaId,
                    pageNumber: $pageNumber,
                    attemptNumber: $attemptNumber,
                    provider: $provider,
                    model: $model,
                    providerAttemptId: $attemptId,
                    reservationId: $creditReservation->id,
                );
            }

            $this->insertRunningModelInvocation(
                id: $invocationRowId,
                principal: $principal,
                provider: $provider,
                model: $model,
                purpose: $purpose->value,
                mediaId: $mediaId,
                providerAttemptId: $attemptId,
                reservationId: $creditReservation->id,
            );
        } catch (Throwable $preflightError) {
            $this->settleStep($principal, $date, $reserved, new TextUsage());
            $this->creditMeter->releaseUnsent($creditReservation->id, 'perception_preflight_aborted');
            if ($attemptRowId !== null) {
                $this->updateMediaProviderAttempt($attemptRowId, [
                    'status' => $preflightError instanceof TooManyRequestsHttpException ? 'retry_scheduled' : 'failed',
                    'error_code' => $preflightError instanceof TooManyRequestsHttpException ? 'RATE_LIMITED' : class_basename($preflightError),
                    'finished_at' => now('UTC'),
                ]);
            }
            throw $preflightError;
        }

        $start = microtime(true);

        try {
            $result = $callback($provider, $model, $attemptId);
            $durationMs = max(1, $result->durationMs > 0 ? $result->durationMs : (int) round((microtime(true) - $start) * 1000));

            $this->settleStep(
                $principal,
                $date,
                $reserved,
                new TextUsage($result->inputTokens, $result->outputTokens)
            );

            // Settle actual credit debit
            $settlement = $this->creditMeter->settle(
                $creditReservation->id,
                $attemptId,
                $provider,
                $model,
                $result->inputTokens,
                $result->outputTokens,
                imagesCount: 1,
                isChargeable: true
            );

            $this->finalizeModelInvocation(
                id: $invocationRowId,
                principal: $principal,
                provider: $provider,
                model: $model,
                status: 'completed',
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
                durationMs: $durationMs,
                errorMessage: null,
                httpAttempts: $result->httpAttempts,
                billingAccountId: $settlement->accountId,
                chargeStatus: $settlement->status,
                rateCardVersion: $settlement->rateCardVersion,
                fxRateVersion: $settlement->fxRateVersion,
                chargeMinorInr: $settlement->costMinorInr,
                creditsDebited: $settlement->creditsDebited,
            );

            if ($attemptRowId !== null) {
                $this->updateMediaProviderAttempt($attemptRowId, [
                    'status' => 'responded',
                    'provider_request_id' => $result->providerRequestId,
                    'http_status' => 200,
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                    'duration_ms' => $durationMs,
                    'finished_at' => now('UTC'),
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            $this->settleStep($principal, $date, $reserved, new TextUsage());

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $errorCode = $e instanceof MultimodalException ? $e->errorCode : class_basename($e);
            $errorMessage = $errorCode . ': ' . substr($e->getMessage(), 0, 200);
            $httpAttempts = ($e instanceof MultimodalException && isset($e->details['http_attempts']))
                ? max(0, (int) $e->details['http_attempts'])
                : 1;
            $requestSent = $e instanceof MultimodalException && ! empty($e->details['request_sent']);
            $isUncertain = $requestSent && $e instanceof MultimodalException && in_array($e->errorCode, [
                'PROVIDER_TIMEOUT',
                'PERCEPTION_PROVIDER_UNAVAILABLE',
                'PROVIDER_CONNECTION_FAILED',
            ], true);

            if ($isUncertain) {
                if ((bool) config('ai_credits.enabled', true)) {
                    DB::table('ai_credit_reservations')
                        ->where('id', $creditReservation->id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'unknown_billability',
                            'updated_at' => now(),
                        ]);
                }
                $chargeStatus = 'unknown_billability';
                $invStatus = 'uncertain';
                $attStatus = 'uncertain';
            } else {
                $this->creditMeter->releaseUnsent($creditReservation->id, 'perception_' . strtolower($errorCode));
                $chargeStatus = 'released';
                $invStatus = 'failed';
                $attStatus = $errorCode === 'RATE_LIMITED' ? 'retry_scheduled' : 'failed';
            }

            $this->finalizeModelInvocation(
                id: $invocationRowId,
                principal: $principal,
                provider: $provider,
                model: $model,
                status: $invStatus,
                inputTokens: 0,
                outputTokens: 0,
                durationMs: $durationMs,
                errorMessage: $errorMessage,
                httpAttempts: $httpAttempts,
                billingAccountId: null,
                chargeStatus: $chargeStatus,
                rateCardVersion: null,
                fxRateVersion: null,
                chargeMinorInr: null,
                creditsDebited: 0,
            );

            if ($attemptRowId !== null) {
                $this->updateMediaProviderAttempt($attemptRowId, [
                    'status' => $attStatus,
                    'error_code' => $errorCode,
                    'http_status' => $e instanceof MultimodalException ? $e->httpStatus : null,
                    'duration_ms' => $durationMs,
                    'finished_at' => now('UTC'),
                ]);
            }

            throw $e;
        }
    }

    private function upsertMediaProviderAttempt(
        string $taskId,
        int $companyId,
        string $mediaId,
        int $pageNumber,
        int $attemptNumber,
        string $provider,
        string $model,
        string $providerAttemptId,
        string $reservationId,
    ): string {
        $existing = DB::table('ai_media_provider_attempts')
            ->where('task_id', $taskId)
            ->where('page_number', $pageNumber)
            ->where('attempt_number', $attemptNumber)
            ->first(['id']);

        if ($existing) {
            DB::table('ai_media_provider_attempts')
                ->where('id', $existing->id)
                ->update([
                    'provider_attempt_id' => $providerAttemptId,
                    'reservation_id' => $reservationId,
                    'status' => 'admitted',
                    'updated_at' => now('UTC'),
                ]);

            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('ai_media_provider_attempts')->insert([
            'id' => $id,
            'task_id' => $taskId,
            'company_id' => $companyId,
            'media_id' => $mediaId,
            'page_number' => $pageNumber,
            'attempt_number' => $attemptNumber,
            'provider' => $provider,
            'model_key' => $model,
            'provider_attempt_id' => $providerAttemptId,
            'reservation_id' => $reservationId,
            'status' => 'admitted',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateMediaProviderAttempt(string $id, array $fields): void
    {
        try {
            $fields['updated_at'] = now('UTC');
            DB::table('ai_media_provider_attempts')->where('id', $id)->update($fields);
        } catch (Throwable) {
            // Best-effort telemetry update
        }
    }

    private function insertRunningModelInvocation(
        string $id,
        AiPrincipal $principal,
        string $provider,
        string $model,
        string $purpose,
        ?string $mediaId,
        string $providerAttemptId,
        string $reservationId,
    ): void {
        try {
            DB::table('ai_model_invocations')->insert([
                'id' => $id,
                'run_id' => $principal->requestId,
                'company_id' => $principal->companyId,
                'step_number' => 1,
                'provider' => $provider,
                'model' => $model,
                'model_purpose' => $purpose,
                'media_id' => $mediaId,
                'status' => 'running',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'duration_ms' => 0,
                'provider_attempt_id' => $providerAttemptId,
                'reservation_id' => $reservationId,
                'charge_status' => 'reserved',
                'credits_debited' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Isolated unit tests without DB schema ignore
        }
    }

    private function finalizeModelInvocation(
        string $id,
        AiPrincipal $principal,
        string $provider,
        string $model,
        string $status,
        int $inputTokens,
        int $outputTokens,
        int $durationMs,
        ?string $errorMessage,
        ?int $httpAttempts,
        ?string $billingAccountId,
        ?string $chargeStatus,
        ?string $rateCardVersion,
        ?string $fxRateVersion,
        ?int $chargeMinorInr,
        ?int $creditsDebited,
    ): void {
        try {
            $updated = DB::table('ai_model_invocations')
                ->where('id', $id)
                ->update([
                    'status' => $status,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'duration_ms' => $durationMs,
                    'error_message' => $errorMessage,
                    'http_attempts' => $httpAttempts,
                    'billing_account_id' => $billingAccountId,
                    'charge_status' => $chargeStatus,
                    'rate_card_version' => $rateCardVersion,
                    'fx_rate_version' => $fxRateVersion,
                    'charge_minor_inr' => $chargeMinorInr,
                    'credits_debited' => $creditsDebited,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                $this->recordModelInvocation(
                    $principal,
                    1,
                    $provider,
                    $model,
                    $status,
                    $inputTokens,
                    $outputTokens,
                    $durationMs,
                    $errorMessage,
                    httpAttempts: $httpAttempts,
                    billingAccountId: $billingAccountId,
                    chargeStatus: $chargeStatus,
                    rateCardVersion: $rateCardVersion,
                    fxRateVersion: $fxRateVersion,
                    chargeMinorInr: $chargeMinorInr,
                    creditsDebited: $creditsDebited,
                );
                return;
            }

            app(\App\Ai\Telemetry\TelemetryDispatcher::class)->recordModel(
                $principal,
                1,
                $provider,
                $model,
                $status,
                $inputTokens,
                $outputTokens,
                $durationMs,
                $httpAttempts,
            );
        } catch (Throwable $e) {
            Log::warning('Failed to finalize AI model invocation telemetry', [
                'run_id' => $principal->requestId,
                'exception_type' => $e::class,
            ]);
        }
    }

    /** Call inside the transaction that creates the run. */
    public function admitRequest(AiPrincipal $principal, string $date, string $threadId): void
    {
        $setting = $this->lockCompany($principal);
        $counter = $this->counter($principal, $date);

        if (($counter->lockForUpdate()->value('request_count') ?? 0) >= config('erp_ai.daily_user_requests')) {
            throw new TooManyRequestsHttpException(message: 'Daily user AI request quota exceeded.');
        }
        if ($this->remainingTokens($principal, $date, $setting) <= 0) {
            throw new TooManyRequestsHttpException(message: 'Daily company AI token quota exceeded.');
        }
        if (DB::table('ai_runs')->where('company_id', $principal->companyId)->where('thread_id', $threadId)
            ->where('status', 'running')->where('started_at', '>', now()->subMinutes(10))->lockForUpdate()->first()) {
            throw new TooManyRequestsHttpException(message: 'This conversation already has a request in progress.');
        }

        DB::table('ai_usage_counters')->insertOrIgnore([
            'company_id' => $principal->companyId,
            'user_id' => $principal->actorId,
            'date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $counter->increment('request_count', 1, ['updated_at' => now()]);
    }

    public function budgetStep(AiPrincipal $principal, string $date, PendingStep $step, Closure $next): StepResult
    {
        $provider = $step->provider ?: config('erp_ai.provider', 'nvidia');
        $model = $step->model ?: config('erp_ai.model', '');
        // Count UTF-8 bytes as an input allowance and add room for SDK/provider framing and tool schemas.
        $inputAllowance = 1024 + strlen($step->instructions ?? '') + strlen(json_encode(
            $step->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $outputLimit = (int) config('erp_ai.max_output_tokens', 1536);
        $reserved = $inputAllowance + $outputLimit;

        DB::transaction(function () use ($principal, $date, $reserved): void {
            $setting = $this->lockCompany($principal);
            if ($this->remainingTokens($principal, $date, $setting) < $reserved) {
                throw new TooManyRequestsHttpException(message: 'Daily company AI token quota cannot cover the next model step.');
            }

            DB::table('ai_usage_counters')->insertOrIgnore([
                'company_id' => $principal->companyId,
                'user_id' => $principal->actorId,
                'date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($this->counter($principal, $date)->increment('reserved_tokens', $reserved) !== 1) {
                throw new ServiceUnavailableHttpException(message: 'AI usage accounting is unavailable.');
            }
        }, 3);

        $start = microtime(true);
        $stepNumber = $step->number + 1;

        // Preflight credit metering reservation
        $inputTokensEstimate = (int) ceil($inputAllowance / 4);
        $estimatedCredits = $this->creditMeter->calculator()->estimatePreflightCredits(
            $provider,
            $model,
            $inputTokensEstimate,
            $outputLimit
        );
        $attemptId = 'att_' . Str::uuid();
        $idempotencyKey = 'res_step_' . $principal->requestId . '_' . $stepNumber . '_' . $attemptId;
        $creditReservation = $this->creditMeter->reserve(
            $principal,
            $principal->requestId,
            'reasoning.step',
            $provider,
            $model,
            $idempotencyKey,
            $estimatedCredits
        );

        $this->dispatchProvider = $provider;
        $this->dispatchAttempts = 0;
        $usageSettled = false;
        $complete = function (StepResponse $response) use ($principal, $date, $reserved, $provider, $model, $stepNumber, $start, $creditReservation, $attemptId, &$usageSettled): void {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->settleStep($principal, $date, $reserved, $response->usage);
            $usageSettled = true;
            $settlement = $this->creditMeter->settle($creditReservation->id, $attemptId, $provider, $model,
                $response->usage->inputTokens, $response->usage->outputTokens, isChargeable: true);
            $this->recordModelInvocation($principal, $stepNumber, $provider, $model, 'completed',
                $response->usage->inputTokens, $response->usage->outputTokens, $durationMs,
                httpAttempts: $this->dispatchAttempts, providerAttemptId: $attemptId, reservationId: $creditReservation->id,
                billingAccountId: $settlement->accountId, chargeStatus: $settlement->status,
                rateCardVersion: $settlement->rateCardVersion, fxRateVersion: $settlement->fxRateVersion,
                chargeMinorInr: $settlement->costMinorInr, creditsDebited: $settlement->creditsDebited);
        };
        $fail = function (Throwable $error) use ($principal, $date, $reserved, $provider, $model, $stepNumber, $start, $creditReservation, $attemptId, &$usageSettled): void {
            if ($usageSettled) return;
            $usageSettled = true;
            if ($this->dispatchAttempts === 0) {
                $this->settleStep($principal, $date, $reserved, new TextUsage());
                $this->creditMeter->releaseUnsent($creditReservation->id, 'dispatch_aborted');
                return;
            }
            $settlement = $this->creditMeter->settle($creditReservation->id, $attemptId, $provider, $model, 0, 0,
                isChargeable: true, reconcileReason: class_basename($error));
            $this->recordModelInvocation($principal, $stepNumber, $provider, $model, 'failed', 0, 0,
                (int) round((microtime(true) - $start) * 1000), class_basename($error), $this->dispatchAttempts,
                providerAttemptId: $attemptId, reservationId: $creditReservation->id,
                billingAccountId: $settlement->accountId, chargeStatus: $settlement->status, creditsDebited: $settlement->creditsDebited);
        };
        try {
            // Task T06: Sanitize outbound messages and instructions at the last trusted boundary
            if (function_exists('app') && app()->bound(\App\Ai\Security\AiDataClassificationPolicy::class)) {
                $sanitizer = app(\App\Ai\Security\AiDataClassificationPolicy::class);
                $cleanMessages = $sanitizer->sanitizeMessages($step->messages);
                $cleanInstructions = $step->instructions !== null ? $sanitizer->sanitizeOutboundPrompt($step->instructions) : null;
                $step = $step->withMessages($cleanMessages)->withInstructions($cleanInstructions);
            }

            // Keep the reservation until the quota day ends if the provider fails without reporting usage.
            $result = $next($step->withMaxTokens($outputLimit));
            if ($result->stopped() && $this->dispatchAttempts === 0) {
                $this->settleStep($principal, $date, $reserved, new TextUsage());
                $this->creditMeter->releaseUnsent($creditReservation->id, 'step_stopped');
                return $result;
            }
            if ($result->streamed()) {
                $attemptsBeforeIteration = $this->dispatchAttempts;
                $source = (function () use ($result, $provider, $attemptsBeforeIteration, $complete, $fail): \Generator {
                    $this->dispatchProvider = $provider;
                    $this->dispatchAttempts = $attemptsBeforeIteration;
                    try {
                        yield from $result;
                        $response = $result->response();
                        if (!$response) throw new \RuntimeException('Provider stream ended without usage metadata.');
                        $complete($response);
                        return $response;
                    } catch (Throwable $error) {
                        $fail($error);
                        throw $error;
                    } finally {
                        $this->dispatchProvider = null;
                    }
                })();
                return new StepResult($source, $result->step, $result->context, $result->startedAt);
            }
            return $result->then($complete);
        } catch (Throwable $e) {
            $fail($e);
            throw $e;
        } finally {
            $this->dispatchProvider = null;
        }
    }

    /** Count each SDK HTTP dispatch, including a retry inside a model step. */
    public function admitProviderDispatch(?string $url = null): void
    {
        if ($this->dispatchProvider === null) {
            return;
        }

        if ($url !== null && ! $this->isProviderRequestUrl($this->dispatchProvider, $url)) {
            return;
        }

        $this->enforceProviderRateLimit($this->dispatchProvider);
        $this->dispatchAttempts++;
    }

    public function isProviderRequestUrl(string $provider, string $url): bool
    {
        $configuredUrl = config("ai.providers.{$provider}.url");
        if (! empty($configuredUrl)) {
            if (str_starts_with($url, rtrim((string) $configuredUrl, '/'))) {
                return true;
            }

            $configuredHost = parse_url((string) $configuredUrl, PHP_URL_HOST);
            $targetHost = parse_url($url, PHP_URL_HOST);
            if ($configuredHost && $targetHost && strcasecmp($configuredHost, $targetHost) === 0) {
                return true;
            }
        }

        $knownHosts = match ($provider) {
            'nvidia', 'deepseek' => ['integrate.api.nvidia.com', 'api.nvidia.com'],
            'openai' => ['api.openai.com'],
            'anthropic' => ['api.anthropic.com'],
            'gemini' => ['generativelanguage.googleapis.com'],
            'groq' => ['api.groq.com'],
            'mistral' => ['api.mistral.ai'],
            'cohere' => ['api.cohere.ai', 'api.cohere.com'],
            default => [],
        };

        $targetHost = parse_url($url, PHP_URL_HOST);
        if ($targetHost && in_array(strtolower($targetHost), $knownHosts, true)) {
            return true;
        }

        return false;
    }

    public function enforceProviderRateLimit(string $provider): void
    {
        $limit = $this->getProviderRpmLimit($provider);
        if ($limit <= 0) {
            return;
        }

        $key = $this->providerBucket($provider);
        Cache::store(config('cache.limiter'))->lock($key . ':lock', 5)->block(2, function () use ($key, $limit): void {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $seconds = RateLimiter::availableIn($key);
                throw new TooManyRequestsHttpException($seconds, "AI provider request limit reached ({$limit} requests per minute). Please retry in {$seconds} seconds.");
            }

            RateLimiter::hit($key, 60);
        });
    }

    public function getProviderRpmLimit(string $provider): int
    {
        return match ($provider) {
            'nvidia', 'deepseek', 'openai-compatible' => (int) config('erp_ai.nvidia_rpm_limit', 35),
            default => (int) config('erp_ai.provider_rpm_limit', 60),
        };
    }

    /**
     * @return array{provider: string, limit: int, current: int, remaining: int, status: string, window_seconds: int, reset_in_seconds: int}
     */
    public function getProviderRpmUsage(?string $provider = null): array
    {
        $provider = $provider ?? config('erp_ai.provider', 'nvidia');
        $limit = $this->getProviderRpmLimit($provider);
        $key = $this->providerBucket($provider);
        $attempts = RateLimiter::attempts($key);
        $remaining = max(0, $limit - $attempts);

        return [
            'provider' => $provider,
            'limit' => $limit,
            'current' => $attempts,
            'remaining' => $remaining,
            'status' => $limit <= 0 ? 'disabled' : ($attempts >= $limit ? 'limited' : ($attempts >= $limit * 0.8 ? 'warning' : 'healthy')),
            'window_seconds' => 60,
            'reset_in_seconds' => RateLimiter::availableIn($key),
        ];
    }

    private function providerBucket(string $provider): string
    {
        return 'ai:rpm:' . (in_array($provider, ['deepseek', 'openai-compatible'], true) ? 'nvidia' : $provider);
    }

    public function recordModelInvocation(
        AiPrincipal $principal,
        int $stepNumber,
        string $provider,
        string $model,
        string $status,
        int $inputTokens,
        int $outputTokens,
        int $durationMs,
        ?string $errorMessage = null,
        ?int $httpAttempts = null,
        ?string $modelPurpose = null,
        ?string $mediaId = null,
        ?string $providerAttemptId = null,
        ?string $reservationId = null,
        ?string $billingAccountId = null,
        ?string $chargeStatus = null,
        ?string $rateCardVersion = null,
        ?string $fxRateVersion = null,
        ?int $chargeMinorInr = null,
        ?int $creditsDebited = null,
    ): void {
        try {
            DB::table('ai_model_invocations')->insert([
                'id' => (string) Str::uuid(),
                'run_id' => $principal->requestId,
                'company_id' => $principal->companyId,
                'step_number' => $stepNumber,
                'provider' => $provider,
                'model' => $model,
                'model_purpose' => $modelPurpose,
                'media_id' => $mediaId,
                'status' => $status,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'duration_ms' => $durationMs,
                'error_message' => $errorMessage,
                'http_attempts' => $httpAttempts,
                'provider_attempt_id' => $providerAttemptId,
                'reservation_id' => $reservationId,
                'billing_account_id' => $billingAccountId,
                'charge_status' => $chargeStatus,
                'rate_card_version' => $rateCardVersion,
                'fx_rate_version' => $fxRateVersion,
                'charge_minor_inr' => $chargeMinorInr,
                'credits_debited' => $creditsDebited,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(\App\Ai\Telemetry\TelemetryDispatcher::class)->recordModel(
                $principal, $stepNumber, $provider, $model, $status, $inputTokens, $outputTokens, $durationMs, $httpAttempts,
            );
        } catch (Throwable $e) {
            Log::warning('Failed to record AI model invocation telemetry', [
                'run_id' => $principal->requestId,
                'exception_type' => $e::class,
            ]);
        }
    }

    private function settleStep(AiPrincipal $principal, string $date, int $reserved, TextUsage $usage): void
    {
        DB::transaction(function () use ($principal, $date, $reserved, $usage): void {
            // Settle metered usage even if the company has since switched off AI.
            DB::table('ai_feature_settings')->where('company_id', $principal->companyId)->lockForUpdate()->first();

            $this->counter($principal, $date)->update([
                'input_tokens' => DB::raw('input_tokens + ' . $usage->inputTokens),
                'output_tokens' => DB::raw('output_tokens + ' . $usage->outputTokens),
                'reserved_tokens' => DB::raw('reserved_tokens - ' . $reserved),
                'updated_at' => now(),
            ]);
            DB::table('ai_runs')->where('company_id', $principal->companyId)->where('id', $principal->requestId)->update([
                'input_tokens' => DB::raw('input_tokens + ' . $usage->inputTokens),
                'output_tokens' => DB::raw('output_tokens + ' . $usage->outputTokens),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function lockCompany(AiPrincipal $principal): stdClass
    {
        $setting = DB::table('ai_feature_settings')->where('company_id', $principal->companyId)->lockForUpdate()->first();
        if (!$setting?->enabled || !$this->featureGate->isEnabledForCompany($principal->companyId)) {
            throw new ServiceUnavailableHttpException(message: 'AI capabilities are disabled for this company.');
        }

        return $setting;
    }

    private function remainingTokens(AiPrincipal $principal, string $date, stdClass $setting): int
    {
        $limit = min(config('erp_ai.daily_company_tokens'), (int) $setting->daily_token_limit);
        $used = DB::table('ai_usage_counters')->where('company_id', $principal->companyId)->where('date', $date)
            ->lockForUpdate()->get(['input_tokens', 'output_tokens', 'reserved_tokens'])
            ->sum(fn (stdClass $counter) => $counter->input_tokens + $counter->output_tokens + $counter->reserved_tokens);

        return $limit - $used;
    }

    private function counter(AiPrincipal $principal, string $date): Builder
    {
        return DB::table('ai_usage_counters')->where('company_id', $principal->companyId)
            ->where('user_id', $principal->actorId)->where('date', $date);
    }
}
