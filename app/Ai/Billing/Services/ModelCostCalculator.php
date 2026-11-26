<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\Exceptions\AiCreditException;
use Illuminate\Support\Facades\DB;

class ModelCostCalculator
{
    /**
     * Compute exact chargeable cost in minor INR and debited credits for a provider attempt.
     *
     * @return array{
     *     credits: int,
     *     cost_minor_inr: int,
     *     usd_cost: float,
     *     rate_card_version: string,
     *     fx_rate_version: string,
     *     fx_rate: float
     * }
     */
    public function calculate(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $imagesCount = 0,
        int $audioSeconds = 0,
        ?string $rateCardVersion = null,
        ?string $fxRateVersion = null
    ): array {
        $policyVersion = $rateCardVersion ?: config('ai_credits.credit_policy_version', 'cost_v1');
        $fxVersion = $fxRateVersion ?: config('ai_credits.default_fx_version', 'fx_2026_11');

        $inputPrice = $this->resolveUnitPrice($policyVersion, $provider, $model, 'input_token');
        $outputPrice = $this->resolveUnitPrice($policyVersion, $provider, $model, 'output_token');
        $imagePrice = $this->resolveUnitPrice($policyVersion, $provider, $model, 'image', $imagesCount > 0);
        $audioPrice = $this->resolveUnitPrice($policyVersion, $provider, $model, 'audio_second', $audioSeconds > 0);

        $fxRate = $this->resolveFxRate($fxVersion);

        // Compute total USD using bcmath or high precision
        $inputUsd = bcmul((string) $inputTokens, $inputPrice, 10);
        $outputUsd = bcmul((string) $outputTokens, $outputPrice, 10);
        $imageUsd = bcmul((string) $imagesCount, $imagePrice, 10);
        $audioUsd = bcmul((string) $audioSeconds, $audioPrice, 10);

        $totalUsd = bcadd(bcadd($inputUsd, $outputUsd, 10), bcadd($imageUsd, $audioUsd, 10), 10);

        // Convert USD to INR using exact decimal arithmetic
        $totalInr = bcmul($totalUsd, (string) $fxRate, 8);

        // Convert INR to paise (minor units) with mathematical half-up rounding (no float precision loss)
        $paiseDecimal = bcmul($totalInr, '100', 6);
        $costMinorInr = (int) bcadd($paiseDecimal, '0.5', 0);

        // 1 credit = 1 paisa (₹0.01). Debited credits = ceil(cost_in_inr / 0.01) = ceil(cost_in_paise)
        // Exact decimal ceil without intermediate binary float casting
        $creditsDecimal = bcmul($totalInr, '100', 8);
        $credits = self::decimalCeilToInt($creditsDecimal);

        if ($credits === 0 && ($inputTokens > 0 || $outputTokens > 0 || $imagesCount > 0)) {
            $credits = 1;
            $costMinorInr = max(1, $costMinorInr);
        }

        return [
            'credits' => $credits,
            'cost_minor_inr' => $costMinorInr,
            'usd_cost' => (float) $totalUsd,
            'rate_card_version' => $policyVersion,
            'fx_rate_version' => $fxVersion,
            'fx_rate' => $fxRate,
        ];
    }

    /**
     * Exact decimal ceiling without binary float casting.
     */
    public static function decimalCeilToInt(string $decimalStr): int
    {
        $trimmed = trim($decimalStr);
        if ($trimmed === '' || $trimmed === '0') {
            return 0;
        }

        if (! str_contains($trimmed, '.')) {
            return (int) $trimmed;
        }

        [$intPart, $fracPart] = explode('.', $trimmed, 2);
        $cleanFrac = rtrim($fracPart, '0');

        if ($cleanFrac === '') {
            return (int) $intPart;
        }

        return (int) bcadd($intPart, '1', 0);
    }

    /**
     * Compute conservative maximum charge and enforce per-request safety bounds.
     *
     * @return array{
     *     credits: int,
     *     cost_minor_inr: int,
     *     usd_cost: float,
     *     rate_card_version: string,
     *     fx_rate_version: string,
     *     fx_rate: float
     * }
     */
    public function estimateMaximumCharge(
        string $provider,
        string $model,
        int $estimatedInputTokens,
        int $maxOutputTokens,
        int $imagesCount = 0,
        int $audioSeconds = 0,
        ?string $rateCardVersion = null,
        ?string $fxRateVersion = null
    ): array {
        $calc = $this->calculate(
            $provider,
            $model,
            $estimatedInputTokens,
            $maxOutputTokens,
            $imagesCount,
            $audioSeconds,
            $rateCardVersion,
            $fxRateVersion
        );

        $configuredMax = (int) config('ai_credits.max_credits_per_request', 5000);

        if ($calc['credits'] > $configuredMax) {
            throw AiCreditException::requestExceedsCreditLimit($calc['credits'], $configuredMax);
        }

        return $calc;
    }

    /**
     * Estimate worst-case credits needed for preflight reservation.
     */
    public function estimatePreflightCredits(
        string $provider,
        string $model,
        int $estimatedInputTokens,
        int $maxOutputTokens,
        int $imagesCount = 0,
        ?string $rateCardVersion = null
    ): int {
        $estimate = $this->estimateMaximumCharge(
            $provider,
            $model,
            $estimatedInputTokens,
            $maxOutputTokens,
            $imagesCount,
            0,
            $rateCardVersion
        );

        return max(1, $estimate['credits']);
    }

    private function resolveUnitPrice(
        string $version,
        string $provider,
        string $model,
        string $unit,
        bool $isRequired = true
    ): string {
        $dbRate = DB::table('ai_model_rate_cards')
            ->where('version', $version)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('billable_unit', $unit)
            ->value('unit_price_usd_decimal');

        if ($dbRate !== null) {
            return (string) $dbRate;
        }

        // Check configured rate cards for this specific provider and model.
        // Avoid using dot-notation on $model because model names may contain dots (e.g. 3.5).
        $providerCards = config("ai_credits.default_rate_cards.{$version}.{$provider}");
        if (is_array($providerCards) && isset($providerCards[$model][$unit])) {
            return (string) $providerCards[$model][$unit];
        }

        // If unit is optional (e.g. image price for a text-only model when 0 images are passed)
        if (!$isRequired) {
            return '0.00000000';
        }

        // Fail-closed security rule (SEC-17): Never silently fall back to generic rates.
        throw AiCreditException::rateCardMissing($provider, $model, $unit);
    }

    private function resolveFxRate(string $version): float
    {
        $dbFx = DB::table('ai_fx_rates')
            ->where('version', $version)
            ->where('source', 'USD_INR')
            ->orderByDesc('date')
            ->value('rate_decimal');

        if ($dbFx !== null) {
            return (float) $dbFx;
        }

        return (float) config('ai_credits.default_fx_rate_usd_inr', 84.5000);
    }
}
