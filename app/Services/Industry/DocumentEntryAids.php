<?php

namespace App\Services\Industry;

use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UI assistance appropriate for the company's industry profile on the normal Sales / Purchase pages.
 * These are entry aids, not a module: the document page itself is always available and an aid is only ever
 * switched off by an explicit false in the profile's `entry_aids` setting.
 */
final class DocumentEntryAids
{
    public const KEYS = ['previous_rates', 'outstanding', 'clone_invoice', 'inline_party', 'inline_item', 'tracking'];

    /** @return array{aids: array<string, bool>, tracking: array} */
    public function forContext(?CompanyContext $context, string $kind): array
    {
        $profile = ['profile' => 'general_trading', 'settings' => ['quantity_scale' => 4]];
        if ($context && config('operations.enabled') && Schema::hasTable('company_industry_settings')) {
            $profile = app(IndustryProfileService::class)->settings($context);
        }
        $aids = array_fill_keys(self::KEYS, true);
        foreach (($profile['settings']['entry_aids'] ?? []) as $key => $value) {
            if (is_string($key) && array_key_exists($key, $aids) && is_bool($value)) {
                $aids[$key] = $value;
            }
        }
        $dimensions = $context && config('operations.enabled') && app(CapabilityService::class)->enabled('inventory.dimension_tracking', $context);
        $schemes = [];
        if ($context && $kind === 'sale' && $profile['profile'] === 'fmcg' && Schema::hasTable('sales_quantity_schemes')) {
            $schemes = DB::table('sales_quantity_schemes')->where('company_id', $context->companyId)->where('is_active', true)
                ->get(['id', 'name', 'product_id', 'buy_qty', 'free_qty'])->all();
        }
        return ['aids' => $aids, 'tracking' => [
            'profile' => $profile['profile'], 'dimensions' => $dimensions,
            'dimension_unit' => $profile['settings']['dimension_unit'] ?? 'mm', 'schemes' => $schemes,
        ]];
    }
}
