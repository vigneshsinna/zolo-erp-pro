<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales/Purchase entry is no longer a separately enabled module, so its two capability flags are retired:
 *   - sales.fast_counter, purchases.fast_entry are removed (company overrides, profile presets, then the capability rows,
 *     in foreign-key order);
 *   - the industry setting `fast_entry` (a list) becomes `entry_aids` (a map of booleans). When both exist, values already in
 *     `entry_aids` win and only missing keys are copied from `fast_entry`.
 * Idempotent: safe on a fresh install, a partially migrated database, and when rows are already gone.
 */
return new class extends Migration {
    private const RETIRED = ['sales.fast_counter', 'purchases.fast_entry'];
    private const AIDS = ['previous_rates', 'outstanding', 'clone_invoice', 'inline_party', 'inline_item', 'tracking'];
    /** Old list entries and the aids they switch on. */
    private const LEGACY_MAP = [
        'previous_rates' => ['previous_rates'], 'pending_bills' => ['outstanding'], 'outstanding' => ['outstanding'],
        'inline_masters' => ['inline_party', 'inline_item'], 'copy_invoice' => ['clone_invoice'],
    ];

    public function up(): void
    {
        $this->retireCapabilities();
        $this->renameIndustrySetting();
    }

    private function retireCapabilities(): void
    {
        if (!Schema::hasTable('capabilities')) {
            return;
        }
        $ids = DB::table('capabilities')->whereIn('key', self::RETIRED)->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        DB::transaction(function () use ($ids) {
            if (Schema::hasTable('company_capabilities')) {
                DB::table('company_capabilities')->whereIn('capability_id', $ids)->delete();
            }
            if (Schema::hasTable('business_profile_capabilities')) {
                DB::table('business_profile_capabilities')->whereIn('capability_id', $ids)->delete();
            }
            DB::table('capabilities')->whereIn('id', $ids)->delete();
        });
    }

    private function renameIndustrySetting(): void
    {
        if (!Schema::hasTable('company_industry_settings')) {
            return;
        }
        foreach (DB::table('company_industry_settings')->get(['id', 'settings_json']) as $row) {
            $settings = json_decode((string) $row->settings_json, true);
            if (!is_array($settings) || !array_key_exists('fast_entry', $settings)) {
                continue;
            }
            $legacy = $settings['fast_entry'];
            $converted = [];
            if (is_array($legacy)) {
                foreach ($legacy as $key => $value) {
                    if (is_string($key) && in_array($key, self::AIDS, true) && is_bool($value)) {
                        $converted[$key] = $value;                       // already map-shaped
                    } elseif (is_string($value) && isset(self::LEGACY_MAP[$value])) {
                        foreach (self::LEGACY_MAP[$value] as $aid) {     // list-shaped
                            $converted[$aid] = true;
                        }
                    }
                }
            }
            $existing = is_array($settings['entry_aids'] ?? null) ? $settings['entry_aids'] : [];
            $settings['entry_aids'] = $existing + $converted;            // existing values win; missing keys are copied
            unset($settings['fast_entry']);
            DB::table('company_industry_settings')->where('id', $row->id)
                ->update(['settings_json' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Retired flags are not restored: the entry screen they gated no longer exists.
    }
};
