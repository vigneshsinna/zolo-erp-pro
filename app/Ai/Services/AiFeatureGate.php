<?php

declare(strict_types=1);

namespace App\Ai\Services;

use Illuminate\Support\Facades\DB;
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
}


