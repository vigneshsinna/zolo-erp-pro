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
        protected bool $readOnlyGlobally = true
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
    public function isReadOnly(int $companyId): bool
    {
        return true;
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


