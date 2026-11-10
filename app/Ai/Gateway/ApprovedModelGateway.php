<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\TextUsage;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ApprovedModelGateway
{
    protected array $allowedModels = [
        'openai' => ['gpt-4o-mini', 'gpt-4o'],
        'nvidia' => ['deepseek-ai/deepseek-v4.1-flash'],
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
        // Count UTF-8 bytes as an input allowance and add room for SDK/provider framing and tool schemas.
        $inputAllowance = 1024 + strlen($step->instructions ?? '') + strlen(json_encode(
            $step->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $outputLimit = 1024;
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

        // Keep the reservation until the quota day ends if the provider fails without reporting usage.
        return $next($step->withMaxTokens($outputLimit))->then(
            fn (StepResponse $response) => $this->settleStep($principal, $date, $reserved, $response->usage)
        );
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
