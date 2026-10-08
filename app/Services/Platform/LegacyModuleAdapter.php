<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LegacyModuleAdapter
{
    public const MODULES = [
        'manufacturing' => 'manufacturing.production',
        'water_logistics' => 'operations.water_logistics',
        'cafe_bakery' => 'operations.cafe_bakery',
        'repair' => 'service.repair',
        'project_management' => 'operations.projects',
        'project' => 'operations.projects',
        'restaurant' => 'operations.restaurant',
        'installment_plans' => 'sales.installment_plans',
        'catalogue_qr' => 'sales.catalogue_qr',
        'damage_stock' => 'inventory.damage_stock',
        'exchange' => 'sales.exchange',
        'ecommerce' => 'sales.ecommerce',
        'woocommerce' => 'sales.woocommerce',
        'api' => 'integrations.api',
        'OptechJobWork' => 'operations.job_work',
        'OptechPrinting' => 'printing.dot_matrix',
        'OptechGST' => 'core.gst',
        'OptechAccounting' => 'core.accounting',
    ];

    public function keys(?string $modules): array
    {
        $keys = [];
        foreach (explode(',', $modules ?? '') as $module) {
            $key = self::MODULES[trim($module)] ?? null;
            if ($key) {
                $this->includeDependencies($key, $keys);
            }
        }
        return array_keys($keys);
    }

    public function forCompany(int $companyId): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first();
        $settings = json_decode($company?->settings_json ?? '{}', true) ?: [];
        if (array_key_exists('legacy_modules', $settings)) {
            return $this->keys($settings['legacy_modules']);
        }
        // Global legacy settings belong only to the company created by the backfill.
        if ($company?->code !== 'DEFAULT' || !Schema::hasTable('general_settings')) {
            return [];
        }
        return $this->keys(DB::table('general_settings')->orderByDesc('id')->value('modules'));
    }

    private function includeDependencies(string $key, array &$keys): void
    {
        if (isset($keys[$key])) {
            return;
        }
        $keys[$key] = true;
        foreach (CapabilityCatalog::DEFINITIONS[$key][1] as $dependency) {
            $this->includeDependencies($dependency, $keys);
        }
    }
}
