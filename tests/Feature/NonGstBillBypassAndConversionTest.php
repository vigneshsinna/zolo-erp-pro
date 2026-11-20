<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Commercial\PurchaseApplicationService;
use App\Services\Commercial\PurchaseCommand;
use App\Services\Commercial\SaleApplicationService;
use App\Services\Commercial\SaleCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommercialTestCase;

class NonGstBillBypassAndConversionTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_10_000006_add_lot_number_to_commercial_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_optical_prescription_columns_to_product_sales_table.php'))->up();
        (require database_path('migrations/2026_11_11_000002_add_optical_columns_to_products_and_sales_tables.php'))->up();
        if (!\Illuminate\Support\Facades\Schema::hasColumn('sales', 'is_non_gst')) {
            \Illuminate\Support\Facades\Schema::table('sales', fn ($t) => $t->boolean('is_non_gst')->default(false));
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('purchases', 'is_non_gst')) {
            \Illuminate\Support\Facades\Schema::table('purchases', fn ($t) => $t->boolean('is_non_gst')->default(false));
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('gst_transaction_projections')) {
            (require database_path('migrations/2026_10_06_000001_create_tax_compliance_contracts.php'))->up();
        }
    }

    public function test_non_gst_sale_bypasses_all_gst_checks_and_sets_zero_tax(): void
    {
        $context = $this->context();
        $product = $this->stock(50, 100);
        $customer = Customer::forCompany($context)->first();

        // Customer and company have NO GST registrations / state codes
        $data = [
            'customer_id' => $customer->id,
            'warehouse_id' => 1,
            'is_non_gst' => 1,
            'bill_type' => 'non_gst',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                    'net_unit_price' => 100,
                    'sale_unit_id' => 1,
                    'discount' => 0,
                ],
            ],
            'created_at' => '2026-10-03',
        ];

        $sale = app(SaleApplicationService::class)->create(new SaleCommand($data, 'non-gst-sale-1', 1, $context));

        $this->assertNotNull($sale);
        $this->assertEquals(0, (float) $sale->total_tax);
        $this->assertEquals(200, (float) $sale->grand_total);
        $this->assertTrue((bool) ($sale->is_non_gst ?? false));
        $this->assertTrue((bool) ($sale->attributes_json['is_non_gst'] ?? false));
        $this->assertSame('non-gst-v1', $sale->tax_snapshot_json['version'] ?? null);
        $this->assertTrue((bool) ($sale->tax_snapshot_json['is_non_gst'] ?? false));

        // Projections table must NOT record non-GST sales
        $this->assertSame(0, DB::table('gst_transaction_projections')
            ->where('company_id', $context->companyId)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->count());
    }

    public function test_non_gst_purchase_bypasses_all_gst_checks(): void
    {
        $context = $this->context();
        $product = $this->stock(0, 50);
        $supplier = Supplier::forCompany($context)->first();

        $data = [
            'supplier_id' => $supplier->id,
            'warehouse_id' => 1,
            'is_non_gst' => 1,
            'bill_type' => 'non_gst',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 5,
                    'net_unit_cost' => 50,
                    'purchase_unit_id' => 1,
                    'discount' => 0,
                ],
            ],
            'created_at' => '2026-10-03',
        ];

        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($data, 'non-gst-purch-1', 1, $context));

        $this->assertNotNull($purchase);
        $this->assertEquals(0, (float) $purchase->total_tax);
        $this->assertEquals(250, (float) $purchase->grand_total);
        $this->assertTrue((bool) ($purchase->is_non_gst ?? false));
        $this->assertTrue((bool) ($purchase->attributes_json['is_non_gst'] ?? false));
    }

    public function test_non_gst_sale_can_be_converted_to_gst_bill(): void
    {
        $context = $this->context();
        $product = $this->stock(50, 100);
        $customer = Customer::forCompany($context)->first();

        // 1. Create initial Non-GST Bill
        $data = [
            'customer_id' => $customer->id,
            'warehouse_id' => 1,
            'is_non_gst' => 1,
            'bill_type' => 'non_gst',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                    'net_unit_price' => 100,
                    'sale_unit_id' => 1,
                ],
            ],
            'created_at' => '2026-10-03',
        ];

        $sale = app(SaleApplicationService::class)->create(new SaleCommand($data, 'conv-test-1', 1, $context));
        $this->assertEquals(0, (float) $sale->total_tax);
        $this->assertEquals(200, (float) $sale->grand_total);

        // 2. Configure compliance environment for GST
        config(['compliance.enabled' => true]);
        $this->company->update(['country_code' => 'IN', 'state_code' => '33']);
        DB::table('tax_registrations')->insert([
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'gstin' => '33AAACC1206D1ZN',
            'state_code' => '33',
            'legal_name' => 'Test Company',
            'registration_type' => 'regular',
            'status' => 'active',
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('party_tax_profiles')->insert([
            'company_id' => $context->companyId,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'state_code' => '33',
            'gstin' => null,
            'registration_type' => 'unregistered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catId = DB::table('tax_categories')->insertGetId([
            'company_id' => $context->companyId,
            'name' => 'GST 5%',
            'supply_type' => 'taxable',
            'input_credit_allowed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tax_rates')->insert([
            'company_id' => $context->companyId,
            'tax_category_id' => $catId,
            'rate' => 5.00,
            'cess_rate' => 0.00,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hsn_sac_codes')->insert([
            'company_id' => $context->companyId,
            'code' => '5208',
            'kind' => 'hsn',
            'description' => 'Cotton fabric',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product->forceFill([
            'tax_category_id' => $catId,
            'hsn_code' => '5208',
        ])->save();

        foreach (['output_tax_cgst', 'output_tax_sgst', 'output_tax_igst', 'output_tax_cess', 'input_tax_cgst', 'input_tax_sgst', 'input_tax_igst', 'input_tax_cess'] as $role) {
            $type = str_starts_with($role, 'output') ? 'liability' : 'asset';
            $account = \App\Models\Accounting\ChartOfAccount::forceCreate(['company_id' => $this->company->id, 'code' => strtoupper($role), 'name' => $role, 'type' => $type, 'sub_type' => $role]);
            DB::table('semantic_account_mappings')->updateOrInsert(['company_id' => $this->company->id, 'semantic_role' => $role],
                ['account_id' => $account->id, 'is_active' => true, 'account_code' => $account->code, 'account_name' => $role, 'category' => 'core']);
        }

        // 3. Convert Non-GST Bill to GST Bill
        $converted = app(CommercialApplicationService::class)->convertToGst('sale', $sale->id, $context, 1);

        $this->assertFalse((bool) ($converted->is_non_gst ?? false));
        $this->assertSame('gst', $converted->attributes_json['bill_type'] ?? null);
        $this->assertNotNull($converted->attributes_json['converted_to_gst_at'] ?? null);
        
        // 5% of 200 = 10.00 tax
        $this->assertEquals(10.00, (float) $converted->total_tax);
        $this->assertEquals(210.00, (float) $converted->grand_total);
        $this->assertSame('gst-domestic-v1', $converted->tax_snapshot_json['version']);

        // Check line item snapshot
        $line = $converted->productSales->first();
        $this->assertEquals(5.00, (float) $line->tax_rate);
        $this->assertEquals(10.00, (float) $line->tax);
        $this->assertEquals(210.00, (float) $line->total);

        // GST Projections recorded
        $projection = DB::table('gst_transaction_projections')
            ->where('company_id', $context->companyId)
            ->where('source_type', 'sale')
            ->where('source_id', $converted->id)
            ->first();
        $this->assertNotNull($projection);
    }

    public function test_converting_an_already_gst_bill_throws_validation_exception(): void
    {
        $context = $this->context();
        $product = $this->stock(50, 100);
        $customer = Customer::forCompany($context)->first();

        // Create standard GST sale (is_non_gst false)
        config(['compliance.enabled' => false]);
        $data = [
            'customer_id' => $customer->id,
            'warehouse_id' => 1,
            'is_non_gst' => 0,
            'bill_type' => 'gst',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'net_unit_price' => 100,
                    'sale_unit_id' => 1,
                ],
            ],
            'created_at' => '2026-10-03',
        ];

        $sale = app(SaleApplicationService::class)->create(new SaleCommand($data, 'already-gst-1', 1, $context));

        $this->expectException(ValidationException::class);
        app(CommercialApplicationService::class)->convertToGst('sale', $sale->id, $context, 1);
    }
}
