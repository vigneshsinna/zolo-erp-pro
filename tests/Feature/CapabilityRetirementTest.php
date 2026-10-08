<?php

namespace Tests\Feature;

use App\Services\Platform\CapabilityCatalog;
use Database\Seeders\CapabilitySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialTestCase;

/** sales.fast_counter / purchases.fast_entry are gone: no module gate, no stored rows, no entry routes. */
class CapabilityRetirementTest extends CommercialTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000002_retire_fast_entry_capabilities.php');
    }

    private function legacyRows(): array
    {
        $ids = [];
        foreach (['sales.fast_counter' => 'Fast counter', 'purchases.fast_entry' => 'Fast purchase'] as $key => $name) {
            $ids[$key] = DB::table('capabilities')->insertGetId(['key' => $key, 'name' => $name, 'group_key' => explode('.', $key)[0],
                'is_core' => false, 'dependencies_json' => json_encode([])]);
            DB::table('company_capabilities')->insert(['company_id' => $this->company->id, 'capability_id' => $ids[$key], 'enabled' => true,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('business_profile_capabilities')->insert(['profile_id' => DB::table('business_profiles')->value('id'), 'capability_id' => $ids[$key], 'default_enabled' => true]);
        }
        return $ids;
    }

    public function test_the_catalog_the_seeder_and_the_routes_no_longer_know_the_flags(): void
    {
        $retired = ['sales.fast_counter', 'purchases.fast_entry'];
        $this->assertSame([], array_intersect($retired, array_keys(CapabilityCatalog::DEFINITIONS)));
        foreach (CapabilityCatalog::PROFILES as [$name, $optional]) {
            $this->assertSame([], array_intersect($retired, $optional), $name.' preset');
        }
        (new CapabilitySeeder)->run();
        $this->assertSame(0, DB::table('capabilities')->whereIn('key', $retired)->count(), 'a fresh install never creates them');
        $this->assertNull(Route::getRoutes()->getByName('commercial.sale.entry'));
        $this->assertNull(Route::getRoutes()->getByName('commercial.purchase.entry'));
        $this->getJson('/commercial/sale/entry')->assertNotFound();
        $this->getJson('/commercial/purchase/entry')->assertNotFound();
    }

    public function test_the_migration_removes_company_profile_and_capability_rows_in_foreign_key_order_and_is_idempotent(): void
    {
        $ids = $this->legacyRows();
        $kept = DB::table('capabilities')->where('key', 'core.sales')->value('id');
        $this->migration()->up();
        $this->assertSame(0, DB::table('capabilities')->whereIn('key', array_keys($ids))->count());
        $this->assertSame(0, DB::table('company_capabilities')->whereIn('capability_id', $ids)->count());
        $this->assertSame(0, DB::table('business_profile_capabilities')->whereIn('capability_id', $ids)->count());
        $this->assertNotNull(DB::table('capabilities')->find($kept), 'other capabilities are untouched');
        $this->migration()->up();
        $this->assertSame(0, DB::table('capabilities')->whereIn('key', array_keys($ids))->count());
    }

    public function test_the_migration_resumes_when_only_some_rows_were_removed(): void
    {
        $ids = $this->legacyRows();
        DB::table('company_capabilities')->whereIn('capability_id', $ids)->delete();
        $this->migration()->up();
        $this->assertSame(0, DB::table('capabilities')->whereIn('key', array_keys($ids))->count());
    }

    public function test_fast_entry_settings_become_entry_aids_without_losing_anything(): void
    {
        Schema::create('company_industry_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id')->unique(); $table->string('profile_key', 40); $table->string('subtype', 40)->nullable();
            $table->json('settings_json'); $table->unsignedInteger('updated_by'); $table->timestamps();
        });
        $row = fn (array $settings, int $company) => DB::table('company_industry_settings')->insertGetId(['company_id' => $company, 'profile_key' => 'textile',
            'subtype' => 'wholesale', 'settings_json' => json_encode($settings), 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $list = $row(['quantity_scale' => 3, 'labels' => ['dispatch' => 'Material DC'],
            'fast_entry' => ['previous_rates', 'pending_bills', 'outstanding', 'inline_masters', 'copy_invoice']], $this->company->id);
        $both = $row(['fast_entry' => ['previous_rates', 'copy_invoice'], 'entry_aids' => ['previous_rates' => false, 'tracking' => true]], $this->other->id);
        $untouched = DB::table('company_industry_settings')->insertGetId(['company_id' => 99, 'profile_key' => 'general_trading', 'subtype' => 'trading',
            'settings_json' => json_encode(['quantity_scale' => 4]), 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();
        $read = fn (int $id) => json_decode(DB::table('company_industry_settings')->where('id', $id)->value('settings_json'), true);

        $converted = $read($list);
        $this->assertArrayNotHasKey('fast_entry', $converted);
        $this->assertEquals(['previous_rates' => true, 'outstanding' => true, 'inline_party' => true, 'inline_item' => true, 'clone_invoice' => true], $converted['entry_aids']);
        $this->assertSame(3, $converted['quantity_scale']);
        $this->assertSame(['dispatch' => 'Material DC'], $converted['labels'], 'unrelated keys are preserved');

        $merged = $read($both);
        $this->assertFalse($merged['entry_aids']['previous_rates'], 'existing entry_aids values win');
        $this->assertTrue($merged['entry_aids']['tracking']);
        $this->assertTrue($merged['entry_aids']['clone_invoice'], 'missing keys are copied from fast_entry');
        $this->assertArrayNotHasKey('fast_entry', $merged);

        $this->assertSame(['quantity_scale' => 4], $read($untouched));
        $this->migration()->up();
        $this->assertSame($converted, $read($list), 'running twice changes nothing');
    }

    public function test_a_database_without_the_optional_tables_migrates_cleanly(): void
    {
        $this->assertFalse(Schema::hasTable('company_industry_settings'));
        $this->migration()->up();
        $this->addToAssertionCount(1);
    }

    public function test_normal_sales_and_purchase_posting_is_not_gated_by_the_retired_flags(): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $this->assertSame(0, DB::table('capabilities')->whereIn('key', ['sales.fast_counter', 'purchases.fast_entry'])->count());
        $this->postJson('/sales', $this->saleData($product) + ['idempotency_key' => 'ungated-sale'])->assertCreated();
        $this->postJson('/purchases', $this->purchaseData($product) + ['idempotency_key' => 'ungated-purchase'])->assertCreated();
    }
}
