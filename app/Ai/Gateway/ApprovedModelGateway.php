<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Gatekeeper mediating model provider selection, token limits,
 * daily usage quotas, and fail-safe circuit breaker rules.
 */
class ApprovedModelGateway
{
    /**
     * @var array<string, array<string>>
     */
    protected array $allowedModels = [
        'openai' => ['gpt-4o-mini', 'gpt-4o'],
        'gemini' => ['gemini-1.5-flash', 'gemini-2.0-flash'],
        'anthropic' => ['claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022'],
    ];

    public function __construct(
        protected AiFeatureGate $featureGate
    ) {}

    /**
     * Resolve the approved provider and model key for an execution context,
     * enforcing company enablement and user/company quota limits.
     *
     * @return array{provider: string, model: string}
     */
    public function resolveApprovedModel(AiPrincipal $principal, ?string $preferredProvider = null): array
    {
        if (!$this->featureGate->isEnabledForCompany($principal->companyId)) {
            throw new RuntimeException("AI is disabled for company ID [{$principal->companyId}].");
        }

        // Enforce quota limits
        $this->assertQuotaAvailable($principal);

        $provider = $preferredProvider ?? config('erp_ai.provider', 'openai');

        if (!array_key_exists($provider, $this->allowedModels)) {
            throw new InvalidArgumentException("Provider [{$provider}] is not in the approved AI gateway allowlist.");
        }

        $defaultModel = config('erp_ai.model', $this->allowedModels[$provider][0]);

        if (!in_array($defaultModel, $this->allowedModels[$provider], true)) {
            $defaultModel = $this->allowedModels[$provider][0];
        }

        return [
            'provider' => $provider,
            'model' => $defaultModel,
        ];
    }

    /**
     * Check if a specific provider and model pair is permitted.
     */
    public function isApproved(string $provider, string $model): bool
    {
        return isset($this->allowedModels[$provider]) && in_array($model, $this->allowedModels[$provider], true);
    }

    /**
     * Check daily request and token quotas for actor and company.
     *
     * @throws RuntimeException
     */
    public function assertQuotaAvailable(AiPrincipal $principal): void
    {
        if (!function_exists('app') || !app()->bound('db')) {
            return;
        }

        try {
            if (!Schema::hasTable('ai_usage_counters')) {
                return;
            }

            $today = now()->toDateString();
            $maxDailyRequests = (int) config('erp_ai.daily_user_requests', 50);

            // 1. User daily request limit check
            $userCounter = DB::table('ai_usage_counters')
                ->where('company_id', $principal->companyId)
                ->where('user_id', $principal->actorId)
                ->where('date', $today)
                ->first();

            if ($userCounter && $userCounter->request_count >= $maxDailyRequests) {
                throw new RuntimeException("Daily user AI request quota exceeded ({$userCounter->request_count} / {$maxDailyRequests}).");
            }

            // 2. Company daily token limit check
            $companyTokenLimit = (int) config('erp_ai.daily_company_tokens', 100000);
            if (Schema::hasTable('ai_feature_settings')) {
                $setting = DB::table('ai_feature_settings')->where('company_id', $principal->companyId)->first();
                if ($setting && $setting->daily_token_limit > 0) {
                    $companyTokenLimit = (int) $setting->daily_token_limit;
                }
            }

            $totalTokensToday = (int) DB::table('ai_usage_counters')
                ->where('company_id', $principal->companyId)
                ->where('date', $today)
                ->sum(DB::raw('input_tokens + output_tokens'));

            if ($totalTokensToday >= $companyTokenLimit) {
                throw new RuntimeException("Daily company AI token quota exceeded ({$totalTokensToday} / {$companyTokenLimit}).");
            }
        } catch (RuntimeException $re) {
            throw $re;
        } catch (Throwable) {
            // Fail open on telemetry read errors to avoid blocking business operations
        }
    }

    /**
     * Record and increment usage counters atomically.
     */
    public function recordUsage(
        AiPrincipal $principal,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $costMinor = 0
    ): void {
        if (!function_exists('app') || !app()->bound('db')) {
            return;
        }

        try {
            if (!Schema::hasTable('ai_usage_counters')) {
                return;
            }

            $today = now()->toDateString();

            DB::statement("
                INSERT INTO ai_usage_counters (company_id, user_id, date, request_count, input_tokens, output_tokens, estimated_cost_minor, created_at, updated_at)
                VALUES (?, ?, ?, 1, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    request_count = request_count + 1,
                    input_tokens = input_tokens + VALUES(input_tokens),
                    output_tokens = output_tokens + VALUES(output_tokens),
                    estimated_cost_minor = estimated_cost_minor + VALUES(estimated_cost_minor),
                    updated_at = NOW()
            ", [
                $principal->companyId,
                $principal->actorId,
                $today,
                $inputTokens,
                $outputTokens,
                $costMinor,
            ]);
        } catch (Throwable) {
            // Non-blocking telemetry
        }
    }
}


