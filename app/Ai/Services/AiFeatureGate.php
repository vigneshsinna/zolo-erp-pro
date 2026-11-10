<?php

declare(strict_types=1);

namespace App\Ai\Services;

/**
 * Feature flag and emergency killswitch manager for AI capabilities.
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

        // In database mode, checks ai_feature_settings for the company
        return true;
    }

    /**
     * Check if AI operations are restricted to read-only for a given company.
     */
    public function isReadOnly(int $companyId): bool
    {
        return $this->readOnlyGlobally;
    }
}

