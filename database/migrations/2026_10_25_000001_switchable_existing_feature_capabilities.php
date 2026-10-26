<?php

use Database\Seeders\CapabilitySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM, POS, gift cards, coupons, scheduling and the stock-by-lot report become company capabilities.
 * Forward backfill so nothing disappears: every existing company keeps HRM, POS, gift cards, coupons and scheduling
 * (all were available before). Stock by lot follows the business profile: on for textile, FMCG and timber.
 * Only companies without an explicit choice are written; fresh companies get these from their profile preset.
 */
return new class extends Migration
{
    private const KEEP_FOR_EXISTING = ['hrm.enabled', 'sales.pos', 'sales.gift_cards', 'sales.coupons', 'operations.scheduling'];
    private const LOT_REPORT_PROFILES = ['textile', 'fmcg', 'timber'];

    public function up(): void
    {
        if (!Schema::hasTable('capabilities') || !Schema::hasTable('company_capabilities')) {
            return;
        }
        (new CapabilitySeeder)->run();
        $ids = DB::table('capabilities')->pluck('id', 'key');
        $profiles = Schema::hasTable('company_industry_settings')
            ? DB::table('company_industry_settings')->pluck('profile_key', 'company_id') : collect();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $states = array_fill_keys(self::KEEP_FOR_EXISTING, true)
                + ['inventory.lot_report' => in_array($profiles[$companyId] ?? null, self::LOT_REPORT_PROFILES, true)];
            foreach ($states as $key => $enabled) {
                DB::table('company_capabilities')->insertOrIgnore([
                    'company_id' => $companyId, 'capability_id' => $ids[$key], 'enabled' => $enabled,
                    'enabled_at' => $enabled ? now() : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        app()->bound('cache') && app('cache')->flush();
    }

    public function down(): void
    {
        if (!Schema::hasTable('capabilities')) {
            return;
        }
        $ids = DB::table('capabilities')->whereIn('key', [...self::KEEP_FOR_EXISTING, 'inventory.lot_report'])->pluck('id');
        if (Schema::hasTable('company_capabilities')) DB::table('company_capabilities')->whereIn('capability_id', $ids)->delete();
        if (Schema::hasTable('business_profile_capabilities')) DB::table('business_profile_capabilities')->whereIn('capability_id', $ids)->delete();
        DB::table('capabilities')->whereIn('id', $ids)->delete();
    }
};
