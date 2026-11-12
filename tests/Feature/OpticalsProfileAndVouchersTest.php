<?php

namespace Tests\Feature;

use App\Models\Biller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Product_Sale;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Industry\IndustryCatalog;
use App\Services\Platform\CapabilityCatalog;
use Database\Seeders\OpticalsMasterSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpticalsProfileAndVouchersTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Company $company;
    protected Warehouse $warehouse;
    protected Customer $customer;
    protected Biller $biller;
    protected $branch;
    protected $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::first() ?? User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'phone' => '9876543210',
            'password' => bcrypt('password'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->actingAs($this->user);

        $this->company = Company::where('code', 'LENZ')->first() ?? Company::first();

        if ($this->company) {
            if (!$this->company->users()->where('users.id', $this->user->id)->exists()) {
                $this->company->users()->attach($this->user->id, ['is_default' => true]);
            }
            $this->branch = $this->company->branches()->first() ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch']);
            DB::table('company_user_branches')->updateOrInsert(
                ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $this->branch->id],
                ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $this->branch->id]
            );

            $this->warehouse = Warehouse::where('company_id', $this->company->id)->first() ?? Warehouse::create([
                'name' => 'Lenz Central Lab',
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
                'address' => '42 Lab Road, Chennai',
                'phone' => '9876543210',
                'is_active' => true,
            ]);

            $this->customer = Customer::where('company_id', $this->company->id)->first() ?? Customer::create([
                'name' => 'Optic Vision Care',
                'company_id' => $this->company->id,
                'customer_group_id' => 1,
                'phone_number' => '9876500000',
                'address' => '10 Mount Road',
                'city' => 'Chennai',
                'state' => 'Tamil Nadu',
                'postal_code' => '600002',
                'country' => 'India',
                'is_active' => true,
            ]);

            $this->biller = Biller::where('company_id', $this->company->id)->first() ?? Biller::create([
                'name' => 'LenzBreeze Optical Lab',
                'company_id' => $this->company->id,
                'company_name' => 'LenzBreeze Optical Lab Pvt Ltd',
                'phone_number' => '9876543210',
                'address' => '42 Lab Road',
                'city' => 'Chennai',
                'state' => 'Tamil Nadu',
                'is_active' => true,
            ]);

            $this->year = \App\Models\Accounting\FiscalYear::where('company_id', $this->company->id)->first() ?? \App\Models\Accounting\FiscalYear::create([
                'company_id' => $this->company->id,
                'name' => 'FY 2026',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open'
            ]);

            $this->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
                'financial_year_id' => $this->year->id,
            ]);
        }
    }

    public function test_opticals_profile_and_subtypes_catalog_registration(): void
    {
        $this->assertArrayHasKey('opticals', CapabilityCatalog::PROFILES);
        $this->assertSame('Opticals & Eyewear', CapabilityCatalog::PROFILES['opticals'][0]);

        $this->assertArrayHasKey('opticals', IndustryCatalog::SETTINGS);
        $this->assertSame('PRS', IndustryCatalog::SETTINGS['opticals']['uqc']);
        $this->assertSame(2, IndustryCatalog::SETTINGS['opticals']['quantity_scale']);

        $this->assertArrayHasKey('opticals', IndustryCatalog::SUBTYPES);
        $this->assertArrayHasKey('lens_manufacturing', IndustryCatalog::SUBTYPES['opticals']);
        $this->assertArrayHasKey('optical_retail', IndustryCatalog::SUBTYPES['opticals']);

        $this->assertArrayHasKey('PRS', IndustryCatalog::UNITS);
        $this->assertArrayHasKey('opticals', IndustryCatalog::ATTRIBUTES);
        $this->assertArrayHasKey('lens_material', IndustryCatalog::ATTRIBUTES['opticals']);
        $this->assertArrayHasKey('base_curve', IndustryCatalog::ATTRIBUTES['opticals']);
    }

    public function test_opticals_seeder_creates_units_brands_categories_and_sale_types(): void
    {
        (new OpticalsMasterSeeder)->seedCompany($this->company);

        $prsUnit = Unit::where('company_id', $this->company->id)->where('unit_code', 'PRS')->first();
        $this->assertNotNull($prsUnit);

        $optLensSaleType = SaleType::where('company_id', $this->company->id)->where('name', '12%-GST (Optical Lenses)')->first();
        $this->assertNotNull($optLensSaleType);
        $this->assertEquals(12.00, (float) $optLensSaleType->tax_rate);
        $this->assertSame('local', $optLensSaleType->tax_nature);

        $framesSaleType = SaleType::where('company_id', $this->company->id)->where('name', '18%-GST (Frames & Fitting)')->first();
        $this->assertNotNull($framesSaleType);
        $this->assertEquals(18.00, (float) $framesSaleType->tax_rate);

        $series = DB::table('document_series')->where('company_id', $this->company->id)->where('document_type', 'lab_receipt')->first();
        $this->assertNotNull($series);
        $this->assertSame('LAB-', $series->prefix);
    }

    public function test_product_sale_prescription_columns_persist_and_retrieve(): void
    {
        (new OpticalsMasterSeeder)->seedCompany($this->company);

        $unit = Unit::where('company_id', $this->company->id)->where('unit_code', 'PRS')->first() ?? Unit::first();
        $category = \App\Models\Category::where('company_id', $this->company->id)->first() ?? \App\Models\Category::first();
        $product = Product::withoutGlobalScope('request_company')->where('company_id', $this->company->id)->first() ?? Product::forceCreate([
            'name' => 'Test Prescription Lens',
            'code' => 'TEST-LENS-01',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category ? $category->id : 1,
            'unit_id' => $unit->id,
            'cost' => 500,
            'price' => 1000,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $sale = Sale::forceCreate([
            'reference_no' => 'SRX-TEST-' . mt_rand(1000, 9999),
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 120,
            'total_price' => 1000,
            'grand_total' => 1120,
            'sale_status' => 1,
            'payment_status' => 4,
            'company_id' => $this->company->id,
            'sale_note' => 'Order No: CUST-OPT-8891',
        ]);

        $ps = Product_Sale::forceCreate([
            'company_id' => $this->company->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => 1,
            'sale_unit_id' => $unit->id,
            'net_unit_price' => 1000,
            'discount' => 0,
            'tax_rate' => 12,
            'tax' => 120,
            'total' => 1120,
            'sph' => '-2.50',
            'cyl' => '-0.75',
            'axis' => '90',
            'base' => '4.00',
            'addition' => '+2.00',
            'lr' => 'R',
            'dia' => '70',
        ]);

        $retrieved = Product_Sale::findOrFail($ps->id);
        $this->assertSame('-2.50', $retrieved->sph);
        $this->assertSame('-0.75', $retrieved->cyl);
        $this->assertSame('90', $retrieved->axis);
        $this->assertSame('4.00', $retrieved->base);
        $this->assertSame('+2.00', $retrieved->addition);
        $this->assertSame('R', $retrieved->lr);
        $this->assertSame('70', $retrieved->dia);
    }

    public function test_lab_receipt_renders_html_with_qr_and_lens_power_grid(): void
    {
        (new OpticalsMasterSeeder)->seedCompany($this->company);

        $unit = Unit::where('company_id', $this->company->id)->where('unit_code', 'PRS')->first() ?? Unit::first();
        $product = Product::withoutGlobalScope('request_company')->where('company_id', $this->company->id)->first();

        $sale = Sale::forceCreate([
            'reference_no' => 'LAB-TEST-' . mt_rand(1000, 9999),
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 120,
            'total_price' => 1000,
            'grand_total' => 1120,
            'sale_status' => 1,
            'payment_status' => 4,
            'company_id' => $this->company->id,
            'sale_note' => 'Order No: CUST-OPT-8891',
        ]);

        Product_Sale::forceCreate([
            'company_id' => $this->company->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => 1,
            'sale_unit_id' => $unit->id,
            'net_unit_price' => 1000,
            'discount' => 0,
            'tax_rate' => 12,
            'tax' => 120,
            'total' => 1120,
            'sph' => '-3.25',
            'cyl' => '-1.00',
            'axis' => '180',
            'base' => '6.00',
            'addition' => '+1.75',
            'lr' => 'Pair',
            'dia' => '65',
        ]);

        $response = $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->year->id,
        ])->withHeader('X-Company-ID', (string) $this->company->id)
          ->get(route('sale.lab_receipt', $sale->id));
        $response->assertStatus(200);
        $response->assertSee('Optical Rx Lab Slip');
        $response->assertSee('-3.25');
        $response->assertSee('-1.00');
        $response->assertSee('180');
        $response->assertSee('+1.75');
        $response->assertSee('CUST-OPT-8891');
        $response->assertSee('data:image/png;base64,');
    }

    public function test_packing_slip_renders_a6_shipping_slip_with_barcode(): void
    {
        (new OpticalsMasterSeeder)->seedCompany($this->company);

        $unit = Unit::where('company_id', $this->company->id)->where('unit_code', 'PRS')->first() ?? Unit::first();
        $product = Product::withoutGlobalScope('request_company')->where('company_id', $this->company->id)->first();

        $sale = Sale::forceCreate([
            'reference_no' => 'PS-TEST-' . mt_rand(1000, 9999),
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 120,
            'total_price' => 1000,
            'grand_total' => 1120,
            'sale_status' => 1,
            'payment_status' => 4,
            'company_id' => $this->company->id,
            'sale_note' => 'Deliver by express courier',
        ]);

        Product_Sale::forceCreate([
            'company_id' => $this->company->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => 2,
            'sale_unit_id' => $unit->id,
            'net_unit_price' => 1000,
            'discount' => 0,
            'tax_rate' => 12,
            'tax' => 120,
            'total' => 1120,
            'sph' => '-1.50',
            'cyl' => '0.00',
            'axis' => '0',
            'base' => '4.00',
            'addition' => '+1.50',
            'lr' => 'L',
            'dia' => '70',
        ]);

        $response = $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->year->id,
        ])->withHeader('X-Company-ID', (string) $this->company->id)
          ->get(route('sale.packing_slip', $sale->id));
        $response->assertStatus(200);
        $response->assertSee('Packing Slip');
        $response->assertSee('Standard A6 Dispatch Packing Slip');
        $response->assertSee('-1.50');
        $response->assertSee('Deliver To:');
        $response->assertSee('data:image/png;base64,');
    }

    public function test_optical_product_attributes_and_sales_order_warranty_persistence(): void
    {
        (new OpticalsMasterSeeder)->seedCompany($this->company);

        $unit = Unit::where('company_id', $this->company->id)->where('unit_code', 'PRS')->first() ?? Unit::first();
        $category = \App\Models\Category::where('company_id', $this->company->id)->first() ?? \App\Models\Category::first();

        // 1. Verify Product Optical Specifications persistence
        $product = Product::forceCreate([
            'name' => 'LB-617 Progressive Rx Lens',
            'code' => 'LB-617-PRO-' . mt_rand(100, 999),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category ? $category->id : 1,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 150,
            'price' => 225,
            'base' => '1.00',
            'addition' => '0.750',
            'lr' => 'R',
            'dia' => '70',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $retrievedProduct = Product::withoutGlobalScope('request_company')->findOrFail($product->id);
        $this->assertSame('1.00', $retrievedProduct->base);
        $this->assertSame('0.750', $retrievedProduct->addition);
        $this->assertSame('R', $retrievedProduct->lr);
        $this->assertSame('70', $retrievedProduct->dia);

        // 2. Verify Sale Optical End User Warranty & Charges persistence
        $sale = Sale::forceCreate([
            'reference_no' => 'SO-OPT-' . mt_rand(1000, 9999),
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 27,
            'total_price' => 225,
            'grand_total' => 302, // 225 + 27 tax + 50 fitting
            'sale_status' => 1,
            'payment_status' => 4,
            'company_id' => $this->company->id,
            'end_user_name' => 'Johnathan Doe',
            'end_user_phone' => '9876543210',
            'end_user_email' => 'john.doe@example.com',
            'end_user_address' => '12 Marina Bay, Chennai',
            'fitting_charge' => 50.00,
            'tinting_cost' => 0.00,
            'customer_order_no' => 'CUST-ORD-5542',
        ]);

        $retrievedSale = Sale::withoutGlobalScope('request_company')->findOrFail($sale->id);
        $this->assertSame('Johnathan Doe', $retrievedSale->end_user_name);
        $this->assertSame('9876543210', $retrievedSale->end_user_phone);
        $this->assertSame('john.doe@example.com', $retrievedSale->end_user_email);
        $this->assertSame('12 Marina Bay, Chennai', $retrievedSale->end_user_address);
        $this->assertEquals(50.00, (float) $retrievedSale->fitting_charge);
        $this->assertSame('CUST-ORD-5542', $retrievedSale->customer_order_no);

        // 3. Verify Product Create View renders optical toggle and optical section
        $createViewResponse = $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->year->id,
        ])->get(route('products.create'));

        $createViewResponse->assertStatus(200);
        $createViewResponse->assertSee('Optical / Lens Rx');
        $createViewResponse->assertSee('id="optical-section"', false);
        $createViewResponse->assertSee('id="is-optical"', false);
    }

    /**
     * Test that Company 6 has authentic LenzBreeze imported demo products, stock, and customers.
     */
    public function test_lenzbreeze_demo_products_and_customers_imported_for_company(): void
    {
        $company = Company::where('code', 'LENZ')->first() ?? $this->company;

        $productCount = Product::withoutGlobalScope('request_company')
            ->where('company_id', $company->id)
            ->count();

        $this->assertGreaterThanOrEqual(100, $productCount, 'Expected at least 100 imported optical products for LenzBreeze.');

        // Verify Progressive and Bifocal products exist with optical parameters
        $pal = Product::withoutGlobalScope('request_company')
            ->where('company_id', $company->id)
            ->where('code', 'like', 'LB-%')
            ->first();

        $this->assertNotNull($pal);
        $this->assertNotEmpty($pal->base);
        $this->assertNotEmpty($pal->addition);
        $this->assertGreaterThan(0, (float) $pal->cost);
        $this->assertGreaterThan(0, (float) $pal->price);

        $bifocal = Product::withoutGlobalScope('request_company')
            ->where('company_id', $company->id)
            ->where('code', 'like', 'LBS-%')
            ->first();

        $this->assertNotNull($bifocal);
        $this->assertNotEmpty($bifocal->base);
        $this->assertNotEmpty($bifocal->addition);
        $this->assertGreaterThan(0, (float) $bifocal->cost);
        $this->assertGreaterThan(0, (float) $bifocal->price);

        // Verify optical customers exist
        $custCount = Customer::withoutGlobalScope('request_company')
            ->where('company_id', $company->id)
            ->count();

        $this->assertGreaterThanOrEqual(10, $custCount, 'Expected authentic optical customers for LenzBreeze.');
    }
}

