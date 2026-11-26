<?php

declare(strict_types=1);

namespace App\Ai\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Feature flag and emergency killswitch manager for AI capabilities.
 * Enforces global AI_ENABLED killswitch and per-company ai_feature_settings records.
 */
class AiFeatureGate
{
    public function __construct(
        protected bool $enabledGlobally = false,
        protected ?bool $readOnly = null,
    ) {}

    /**
     * Check if AI is enabled for a given company.
     */
    public function isEnabledForCompany(int $companyId): bool
    {
        if (!$this->enabledGlobally) {
            return false;
        }

        try {
            $setting = DB::table('ai_feature_settings')
                ->where('company_id', $companyId)
                ->first();

            if ($setting !== null) {
                return (bool) $setting->enabled;
            }

            // By default, companies require an explicit enabled record in ai_feature_settings
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if AI operations are restricted to read-only for a given company.
     */
    public function isReadOnly(?int $companyId = null): bool
    {
        if ($companyId !== null && function_exists('app') && app()->bound('db')) {
            try {
                $setting = DB::table('ai_feature_settings')
                    ->where('company_id', $companyId)
                    ->first();

                if ($setting !== null) {
                    return (bool) $setting->read_only;
                }
            } catch (Throwable) {
                // fallback
            }
        }

        if ($this->readOnly !== null) {
            return $this->readOnly;
        }

        try {
            if (function_exists('config')) {
                return !config('ai_actions.foundation_enabled', false);
            }
        } catch (Throwable) {
            // fallback for isolated unit tests without config facade
        }

        return true;
    }

    /**
     * Check if multimodal perception (image/PDF/audio) is enabled for a company.
     * Enforces both global AI_MULTIMODAL_ENABLED killswitch and per-company setting.
     */
    public function isMultimodalEnabledForCompany(int $companyId): bool
    {
        if (! (bool) config('ai_multimodal.enabled', false)) {
            return false;
        }

        if (! $this->isEnabledForCompany($companyId)) {
            return false;
        }

        try {
            $setting = DB::table('ai_feature_settings')
                ->where('company_id', $companyId)
                ->first();

            return $setting !== null && (bool) ($setting->multimodal_enabled ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Update or initialize multimodal feature toggle for a company.
     */
    public function setCompanyMultimodal(int $companyId, bool $multimodalEnabled, ?int $dailyMediaLimit = null): void
    {
        if (! function_exists('app') || ! app()->bound('db')) {
            return;
        }

        $payload = [
            'multimodal_enabled' => $multimodalEnabled,
            'updated_at' => now(),
        ];

        if ($dailyMediaLimit !== null) {
            $payload['daily_media_upload_limit'] = max(1, $dailyMediaLimit);
        }

        DB::table('ai_feature_settings')->updateOrInsert(
            ['company_id' => $companyId],
            $payload
        );
    }

    /**
     * Update or initialize feature settings for a specific company.
     */
    public function setCompanySetting(int $companyId, bool $enabled, bool $readOnly = true, array $extra = []): void
    {
        if (!function_exists('app') || !app()->bound('db')) {
            return;
        }

        DB::table('ai_feature_settings')->updateOrInsert(
            ['company_id' => $companyId],
            array_merge([
                'enabled' => $enabled,
                'read_only' => $readOnly,
                'updated_at' => now(),
            ], $extra)
        );
    }

    /**
     * Provision AI feature settings for a newly created company using the installation policy.
     *
     * If the installation has AI enabled, initializes the company with AI enabled,
     * restricted to authorized roles, with execution disabled (read_only = true)
     * until explicitly configured by an administrator.
     *
     * @return array<string, mixed>
     */
    public function provisionCompanyDefaults(int $companyId, ?int $actorId = null): array
    {
        if (!function_exists('app') || !app()->bound('db') || !Schema::hasTable('ai_feature_settings')) {
            return [];
        }

        $inherit = (bool) config('erp_ai.provisioning_policy.inherit_installation_enabled', true);
        $isInstallationAiEnabled = (bool) config('erp_ai.enabled', false);
        $enabled = $inherit && $isInstallationAiEnabled;

        $isInstallationMultimodalEnabled = $enabled && (bool) config('ai_multimodal.enabled', false);
        $readOnly = (bool) config('erp_ai.provisioning_policy.default_read_only', true);
        $allowedAgents = (array) config('erp_ai.provisioning_policy.default_agents', ['erp_copilot']);

        $settings = [
            'enabled' => $enabled,
            'read_only' => $readOnly,
            'multimodal_enabled' => $isInstallationMultimodalEnabled,
            'provider_key' => (string) config('erp_ai.provider', 'nvidia'),
            'allowed_agent_keys' => json_encode($allowedAgents, JSON_THROW_ON_ERROR),
            'daily_token_limit' => max(1000, (int) config('erp_ai.daily_company_tokens', 100000)),
            'daily_media_upload_limit' => max(1, (int) config('ai_multimodal.daily_company_uploads', 100)),
            'monthly_budget_minor' => 0,
            'retention_days' => 30,
            'updated_by' => $actorId,
            'updated_at' => now(),
            'created_at' => now(),
        ];

        DB::table('ai_feature_settings')->updateOrInsert(
            ['company_id' => $companyId],
            $settings
        );

        return $settings;
    }
}


