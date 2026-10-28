<?php

namespace Tests\Feature;

use App\Models\{Customer, Supplier};
use App\Services\Commercial\{PurchaseApplicationService, PurchaseCommand, SaleApplicationService, SaleCommand};
use App\Services\Tax\GstSetupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\Support\ReturnsDocumentTestCase;

class GstSetupTest extends ReturnsDocumentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['suppliers', 'customers'] as $table) {
            if (!Schema::hasColumn($table, 'state')) Schema::table($table, fn (Blueprint $t) => $t->string('state')->nullable());
        }
        if (!Schema::hasColumn('taxes', 'company_id')) Schema::table('taxes', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable());
    }

    private function gst(): GstSetupService
    {
        return app(GstSetupService::class);
    }

    private function category(string $name): int
    {
        return (int) DB::table('tax_categories')->where('company_id', $this->company->id)->where('name', $name)->value('id');
    }

    public function test_install_defaults_is_idempotent_maps_legacy_rates_and_gst_ledgers(): void
    {
        DB::table('taxes')->insert([['id' => 7, 'name' => 'GST 18', 'rate' => 18, 'is_active' => 1, 'company_id' => $this->company->id],
            ['id' => 8, 'name' => 'VAT 10%', 'rate' => 10, 'is_active' => 1, 'company_id' => $this->company->id]]);
        $mapped = $this->stock(); $mapped->forceFill(['tax_id' => 7])->save();
        $vat = $this->stock(); $vat->forceFill(['tax_id' => 8])->save();
        DB::table('semantic_account_mappings')->where('company_id', $this->company->id)->where('semantic_role', 'input_tax_igst')->update(['account_id' => null]);

        $this->assertSame(10, $this->gst()->installDefaults($this->context(), 1)['categories']);
        $this->assertSame(0, $this->gst()->installDefaults($this->context(), 1)['categories']);
        $this->assertSame($this->category('GST 18%'), (int) $mapped->fresh()->tax_category_id);
        $this->assertNull($vat->fresh()->tax_category_id, 'a VAT rate without a GST slab stays for review');
        $this->assertEquals(40, DB::table('tax_rates')->where('tax_category_id', $this->category('GST 40%'))->value('rate'));
        $this->assertSame('nil_rated', DB::table('tax_categories')->where('id', $this->category('GST 0% (Nil rated)'))->value('supply_type'));
        $account = DB::table('semantic_account_mappings')->where('company_id', $this->company->id)->where('semantic_role', 'input_tax_igst')->value('account_id');
        $this->assertSame('input_tax_igst', DB::table('chart_of_accounts')->where('id', $account)->value('sub_type'));
    }

    public function test_missing_gst_ledgers_are_created_and_mapped(): void
    {
        DB::table('semantic_account_mappings')->where('company_id', $this->company->id)->where('semantic_role', 'like', '%_tax_%')->delete();
        DB::table('chart_of_accounts')->where('company_id', $this->company->id)->where('sub_type', 'like', '%_tax_%')->update(['sub_type' => 'retired']);
        $this->gst()->ensureLedgers($this->company->id);
        foreach (GstSetupService::LEDGERS as $role => [$code]) {
            $account = DB::table('semantic_account_mappings as m')->join('chart_of_accounts as a', 'a.id', '=', 'm.account_id')
                ->where('m.company_id', $this->company->id)->where('m.semantic_role', $role)->first(['a.code', 'a.sub_type']);
            $this->assertSame([$code, $role], [$account->code, $account->sub_type]);
        }
    }

    public function test_rate_change_closes_the_open_period_and_applies_from_its_date(): void
    {
        $product = $this->gstProduct();
        $this->gst()->changeRate($product->tax_category_id, 5, 0, '2026-10-02', $this->context(), 1);
        $this->assertSame('2026-10-01', DB::table('tax_rates')->where('tax_category_id', $product->tax_category_id)->where('rate', 18)->value('effective_to'));
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($product), 'after-change', 1, $this->context()));
        $this->assertEquals(5, $sale->productSales->first()->tax_snapshot_json['rate']);
        $this->expectException(ValidationException::class);
        $this->gst()->changeRate($product->tax_category_id, 12, 0, '2026-10-02', $this->context(), 1);
    }

    public function test_party_masters_drive_gst_profiles_and_the_igst_split(): void
    {
        DB::table('party_tax_profiles')->delete();
        $supplier = Supplier::findOrFail(1);
        $supplier->forceFill(['vat_number' => $this->gstin('29')])->save();
        $profile = DB::table('party_tax_profiles')->where('party_type', 'supplier')->where('party_id', 1)->first();
        $this->assertSame(['29', 'regular'], [$profile->state_code, $profile->registration_type]);

        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($this->gstProduct()), 'igst', 1, $this->context()));
        $snapshot = $purchase->productPurchases->first()->tax_snapshot_json;
        $this->assertEquals([0, 0, 1.8], [$snapshot['cgst'], $snapshot['sgst'], $snapshot['igst']]);

        Customer::findOrFail(1)->forceFill(['state' => 'Tamil Nadu'])->save();
        $this->assertSame(['33', 'unregistered'], [DB::table('party_tax_profiles')->where('party_type', 'customer')->value('state_code'),
            DB::table('party_tax_profiles')->where('party_type', 'customer')->value('registration_type')]);

        DB::table('party_tax_profiles')->where('party_type', 'customer')->delete();
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($this->gstProduct()), 'lazy-profile', 1, $this->context()));
        $this->assertEquals(1.8, $sale->productSales->first()->tax_snapshot_json['cgst'], 'a missing profile is derived from the party master');
        $this->assertSame(1, DB::table('party_tax_profiles')->where('party_type', 'customer')->count());
    }

    public function test_product_assignment_catalogs_hsn_and_rejects_a_goods_code_for_services(): void
    {
        $product = $this->stock();
        $category = (int) DB::table('tax_categories')->value('id');
        $this->gst()->assignProduct($product->id, $category, '3004', $this->context(), 1);
        $this->assertSame('hsn', DB::table('hsn_sac_codes')->where('code', '3004')->value('kind'));
        $this->assertEquals(18, $this->gst()->rateFor($product->fresh())['rate']);
        $product->forceFill(['type' => 'service'])->save();
        try {
            $this->gst()->assignProduct($product->id, $category, '3004', $this->context(), 1);
            $this->fail('A goods HSN must not be accepted for a service.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('SAC', $error->getMessage());
        }
    }

    public function test_missing_gst_details_name_the_product(): void
    {
        $product = $this->stock();
        try {
            app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($product), 'no-rate', 1, $this->context()));
            $this->fail('A product without a GST rate must not post under GST.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($product->name, $error->getMessage());
        }
    }

    public function test_settings_page_install_products_and_rate_forms(): void
    {
        $this->get('/settings/gst')->assertOk()->assertSee('HSN / SAC codes')->assertSee('Domestic goods');
        $this->post('/settings/gst/install')->assertSessionHasNoErrors()->assertRedirect();
        $product = $this->stock();
        $this->post('/settings/gst/products', ['products' => [$product->id => ['tax_category_id' => $this->category('GST 12%'), 'hsn_code' => '1001']]])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->category('GST 12%'), (int) $product->fresh()->tax_category_id);
        $this->post('/settings/gst/rate', ['id' => $this->category('GST 12%'), 'rate' => 5, 'cess_rate' => 0, 'effective_from' => '2026-11-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('tax_rates')->where('tax_category_id', $this->category('GST 12%'))->count());
        $this->post('/settings/gst/products', ['products' => [$product->id => ['tax_category_id' => $this->category('GST 12%'), 'hsn_code' => '12']]])
            ->assertSessionHasErrors('hsn_code');
        $this->get('/settings/gst?products=all#products')->assertOk()->assertSee($product->name);
        $this->get('/settings/gst')->assertOk()->assertSee($product->name, false);
        $this->get('/settings/gst?products=missing')->assertOk()->assertDontSee('<strong>'.$product->name.'</strong>', false);
    }

    public function test_gst_rates_and_codes_can_be_edited_and_deleted_when_unused(): void
    {
        $this->post('/settings/gst/install')->assertSessionHasNoErrors();
        $product = $this->stock();
        $used = $this->category('GST 12%'); $unused = $this->category('GST 40%');
        $this->post('/settings/gst/products', ['products' => [$product->id => ['tax_category_id' => $used, 'hsn_code' => '1001']]])->assertSessionHasNoErrors();

        $this->post('/settings/gst/rename', ['id' => $unused, 'name' => 'GST 40% luxury', 'input_credit_allowed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('GST 40% luxury', DB::table('tax_categories')->where('id', $unused)->value('name'));
        $this->post('/settings/gst/category-delete', ['id' => $used])->assertSessionHasErrors('id');
        $this->post('/settings/gst/category-delete', ['id' => $unused])->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('tax_categories')->where('id', $unused)->exists());
        $this->assertFalse(DB::table('tax_rates')->where('tax_category_id', $unused)->exists());

        $this->post('/settings/gst/code', ['code' => '1001', 'kind' => 'hsn', 'description' => 'Wheat'])->assertSessionHasNoErrors();
        $this->assertSame('Wheat', DB::table('hsn_sac_codes')->where('company_id', $this->company->id)->where('code', '1001')->value('description'));
        $this->post('/settings/gst/code', ['code' => '1001', 'kind' => 'sac', 'description' => 'Wheat'])->assertSessionHasErrors();
        $this->post('/settings/gst/code-delete', ['code' => '1001'])->assertSessionHasErrors('code');
        $this->post('/settings/gst/code', ['code' => '998314', 'kind' => 'sac', 'description' => 'IT services'])->assertSessionHasNoErrors();
        $this->post('/settings/gst/code-delete', ['code' => '998314'])->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('hsn_sac_codes')->where('code', '998314')->exists());
    }

    public function test_unregistered_company_bills_without_gst_and_keeps_supplier_gst_in_cost(): void
    {
        (require database_path('migrations/2026_10_20_000001_allow_unregistered_company_tax_registration.php'))->up();
        DB::table('tax_registrations')->delete();
        $register = fn (array $data) => $this->gst()->registerCompany($data + ['legal_name' => 'Sweet Soil', 'address' => 'Erode',
            'effective_from' => '2026-01-01'], $this->context(), 1);
        foreach ([['registration_type' => 'unregistered', 'gstin' => $this->gstin('33')], ['registration_type' => 'regular', 'gstin' => '']] as $invalid) {
            try { $register($invalid); $this->fail('GSTIN and type must agree.'); } catch (ValidationException $error) { $this->assertArrayHasKey('gstin', $error->errors()); }
        }
        $register(['registration_type' => 'unregistered', 'state_code' => '33']);
        $this->assertSame([null, '33', 'unregistered'], [DB::table('tax_registrations')->value('gstin'),
            DB::table('tax_registrations')->value('state_code'), DB::table('tax_registrations')->value('registration_type')]);
        $this->assertTrue($this->gst()->enabled($this->context()));

        $product = $this->gstProduct();
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($product), 'unregistered-sale', 1, $this->context()));
        $line = $sale->productSales->first()->tax_snapshot_json;
        $this->assertEquals([0, 0, 0, 0], [$line['rate'], $line['cgst'], $line['sgst'], $line['igst']], 'an unregistered seller charges no GST');
        $this->assertNull($sale->tax_snapshot_json['registration_gstin']);

        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($product), 'unregistered-buy', 1, $this->context()));
        $line = $purchase->productPurchases->first()->tax_snapshot_json;
        $this->assertEquals(0.9, $line['sgst'], 'a registered supplier still charges GST');
        $this->assertFalse($line['input_credit_allowed'], 'an unregistered buyer cannot claim it');

        // Taking a GSTIN later closes the unregistered period the day before.
        $register(['registration_type' => 'regular', 'gstin' => $this->gstin('33'), 'effective_from' => '2026-11-01']);
        $this->assertSame('2026-10-31', DB::table('tax_registrations')->whereNull('gstin')->value('effective_to'));
    }

    public function test_entry_config_and_legacy_form_preview_use_server_gst(): void
    {
        $config = $this->gst()->entryConfig($this->context());
        $this->assertSame('33', $config['company_state']);
        $product = $this->gstProduct();
        // Browser estimates (tax 999) are ignored under GST; the server determines the line.
        $this->postJson('/commercial/purchase/preview', ['supplier_id' => 1, 'business_date' => '2026-10-03', 'product_id' => [$product->id],
            'qty' => [2], 'net_unit_cost' => [5], 'tax' => [999], 'subtotal' => [999]])
            ->assertOk()->assertJsonPath('data.items.0.tax', 1.8)->assertJsonPath('data.items.0._tax_snapshot.sgst', 0.9);
        DB::table('tax_registrations')->update(['effective_to' => '2026-01-31']);
        $this->assertNull($this->gst()->entryConfig($this->context()));
    }

    public function test_line_gst_may_be_changed_to_another_company_slab_and_keeps_the_product_rate(): void
    {
        $product = $this->gstProduct();
        // A second slab (5%) in its own category, copied from the fixture's 18% one.
        $category = (array) DB::table('tax_categories')->where('id', $product->tax_category_id)->first();
        unset($category['id']); $category['name'] = 'GST 5% test';
        $categoryId = DB::table('tax_categories')->insertGetId($category);
        $rate = (array) DB::table('tax_rates')->where('tax_category_id', $product->tax_category_id)->first();
        unset($rate['id']); DB::table('tax_rates')->insert(['tax_category_id' => $categoryId, 'rate' => 5] + $rate);
        $this->assertContains(5.0, $this->gst()->entryConfig($this->context())['slabs']);

        $line = ['product_id' => $product->id, 'qty' => 2, 'net_unit_cost' => 5, 'purchase_unit_id' => $product->purchase_unit_id];
        $request = fn (array $extra) => $this->postJson('/commercial/purchase/preview', ['supplier_id' => 1, 'business_date' => '2026-10-03', 'items' => [$line + $extra]]);
        $request(['tax_rate_override' => 5])->assertOk()->assertJsonPath('data.items.0.tax', 0.5)
            ->assertJsonPath('data.items.0._tax_snapshot.rate', 5)->assertJsonPath('data.items.0._tax_snapshot.product_rate', 18);
        $request([])->assertOk()->assertJsonPath('data.items.0.tax', 1.8)->assertJsonPath('data.items.0._tax_snapshot.product_rate', 18);
        $request(['tax_rate_override' => 7])->assertStatus(422)->assertJsonFragment(['GST 7% is not one of your GST rates (Settings › GST).']);
    }
}
