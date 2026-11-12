<?php

declare(strict_types=1);

namespace App\Ai\Telemetry;

use App\Ai\DTOs\AiPrincipal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Only approved technical fields can cross the external telemetry boundary. */
final readonly class ModelTelemetry
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public string $actorHash,
        public string $companyHash,
        public string $provider,
        public string $model,
        public string $status,
        public int $inputTokens,
        public int $outputTokens,
        public int $httpAttempts,
        public int $durationMs,
        public string $endTimeUnixNano,
        public ?string $estimatedCostUsd = null,
    ) {}

    public static function fromInvocation(
        AiPrincipal $principal, int $step, string $provider, string $model, string $status,
        int $input, int $output, int $durationMs, ?int $attempts = null,
    ): self {
        $salt = (string) config('app.key');
        if ($salt === '') {
            throw new \LogicException('Telemetry pseudonymization key is required.');
        }
        $hash = static fn (string $value): string => hash_hmac('sha256', $value, $salt);
        $rates = config("ai_infrastructure.langfuse.model_rates.{$provider}", []);
        $rate = $rates[$model] ?? null;
        $cost = null;
        if (is_array($rate) && isset($rate['input'], $rate['output'])) {
            $cost = (string) BigDecimal::of((string) $rate['input'])->multipliedBy(max(0, $input))
                ->plus(BigDecimal::of((string) $rate['output'])->multipliedBy(max(0, $output)))
                ->dividedBy(1000000, 8, RoundingMode::HalfUp);
        }
        // The model/provider are configuration-derived, never copied from prompts or errors.
        return new self(
            traceId: substr($hash('run:'.($principal->requestId ?? 'untracked')), 0, 32),
            spanId: substr($hash('step:'.($principal->requestId ?? 'untracked').':'.$step), 0, 16),
            actorHash: $hash('actor:'.$principal->actorId), companyHash: $hash('company:'.$principal->companyId),
            provider: mb_substr($provider, 0, 80), model: mb_substr($model, 0, 120),
            status: in_array($status, ['completed', 'failed'], true) ? $status : 'unknown',
            inputTokens: max(0, $input), outputTokens: max(0, $output), httpAttempts: max(0, $attempts ?? 0),
            durationMs: max(0, min(3600000, $durationMs)),
            endTimeUnixNano: (string) (int) round(microtime(true) * 1000000).'000', estimatedCostUsd: $cost,
        );
    }
}
