<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

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

    public function __construct(protected AiFeatureGate $featureGate) {}

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
     */
    public function admitPerceptionDispatch(
        AiPrincipal $principal,
        string $date,
        ModelPurpose $purpose,
        int $estimatedInputBytes,
        Closure $callback,
        ?string $mediaId = null,
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

        $start = microtime(true);

        try {
            $this->enforceProviderRateLimit($provider);

            $result = $callback($provider, $model);
            $durationMs = max(1, $result->durationMs > 0 ? $result->durationMs : (int) round((microtime(true) - $start) * 1000));

            $this->settleStep(
                $principal,
                $date,
                $reserved,
                new TextUsage($result->inputTokens, $result->outputTokens)
            );
            $this->recordModelInvocation(
                $principal,
                1,
                $provider,
                $model,
                'completed',
                $result->inputTokens,
                $result->outputTokens,
                $durationMs,
                httpAttempts: $result->httpAttempts,
                modelPurpose: $purpose->value,
                mediaId: $mediaId,
            );

            return $result;
        } catch (Throwable $e) {
            $this->settleStep($principal, $date, $reserved, new TextUsage());
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $errorCode = $e instanceof MultimodalException ? $e->errorCode : class_basename($e);
            $errorMessage = $errorCode . ': ' . substr($e->getMessage(), 0, 200);

            $this->recordModelInvocation(
                $principal,
                1,
                $provider,
                $model,
                'failed',
                0,
                0,
                $durationMs,
                $errorMessage,
                httpAttempts: 1,
                modelPurpose: $purpose->value,
                mediaId: $mediaId,
            );

            throw $e;
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

            if ($this->counter($principal, $date)->increment('reserved_tokens', $reserved) !== 1) {
                throw new ServiceUnavailableHttpException(message: 'AI usage accounting is unavailable.');
            }
        }, 3);

        $start = microtime(true);
        $stepNumber = $step->number + 1;

        $this->dispatchProvider = $provider;
        $this->dispatchAttempts = 0;
        try {
            // Keep the reservation until the quota day ends if the provider fails without reporting usage.
            $result = $next($step->withMaxTokens($outputLimit));
            $attempts = $this->dispatchAttempts;
            if ($result->stopped() && $attempts === 0) {
                $this->settleStep($principal, $date, $reserved, new TextUsage());
                return $result;
            }
            return $result->then(
                function (StepResponse $response) use ($principal, $date, $reserved, $provider, $model, $stepNumber, $start, $attempts): void {
                    $durationMs = (int) round((microtime(true) - $start) * 1000);
                    $this->settleStep($principal, $date, $reserved, $response->usage);
                    $this->recordModelInvocation(
                        $principal,
                        $stepNumber,
                        $provider,
                        $model,
                        'completed',
                        $response->usage->inputTokens,
                        $response->usage->outputTokens,
                        $durationMs,
                        httpAttempts: $attempts,
                    );
                }
            );
        } catch (Throwable $e) {
            if ($this->dispatchAttempts === 0) {
                $this->settleStep($principal, $date, $reserved, new TextUsage());
                throw $e;
            }
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->recordModelInvocation(
                $principal,
                $stepNumber,
                $provider,
                $model,
                'failed',
                0,
                0,
                $durationMs,
                class_basename($e),
                $this->dispatchAttempts,
            );
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
