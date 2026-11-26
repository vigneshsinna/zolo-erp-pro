<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\OpticalsMasterSeeder;
use Database\Seeders\ProductTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductTypeAndOpticalSpecsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_product_type_seeder_populates_lenzbreeze_lens_catalog(): void
    {
        (new ProductTypeSeeder())->run();

        $this->assertDatabaseHas('product_types', [
            'name' => 'KBF-3C-HMC-180°',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('product_types', [
            'name' => 'Progressive-Xtra Short',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('product_types', [
            'name' => 'DUP',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('product_types', [
            'name' => 'Others',
            'is_active' => true,
        ]);
    }

    public function test_product_can_persist_and_relate_to_product_type(): void
    {
        $pt = ProductType::firstOrCreate(['name' => 'KBF-3C-HMC-180°'], ['is_active' => true]);
        $unit = Unit::firstOrCreate(['unit_code' => 'PRS'], ['unit_name' => 'Pairs', 'is_active' => true]);
        $category = Category::firstOrCreate(['name' => 'Single Vision Lenses'], ['is_active' => true]);

        $product = Product::forceCreate([
            'name' => 'Test Progressive RX Lens',
            'code' => 'TEST-RX-PRO-01',
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 200,
            'price' => 450,
            'base' => '4.00',
            'addition' => '+1.50',
            'lr' => 'Pair',
            'dia' => '70',
            'product_type_id' => $pt->id,
            'is_active' => true,
        ]);

        $this->assertEquals($pt->id, $product->product_type_id);
        $this->assertInstanceOf(ProductType::class, $product->productType);
        $this->assertEquals('KBF-3C-HMC-180°', $product->productType->name);
    }

    public function test_product_type_ajax_creation_endpoint(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->actingAs($user);

        $uniqueName = 'Custom-Special-Lens-' . uniqid();
        $response = $this->postJson(route('product_types.store'), [
            'name' => $uniqueName,
            'ajax' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'name' => $uniqueName,
        ]);

        $this->assertDatabaseHas('product_types', [
            'name' => $uniqueName,
            'is_active' => true,
        ]);
    }

    public function test_optical_product_quick_store_with_specs(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->actingAs($user);

        $company = Company::find(6) ?? Company::first();
        $this->session(['company_id' => $company->id]);

        $pt = ProductType::firstOrCreate(['name' => 'Progressive HD Short', 'company_id' => $company->id], ['is_active' => true]);
        $unit = Unit::firstOrCreate(['unit_code' => 'PRS', 'company_id' => $company->id], ['unit_name' => 'Pairs', 'is_active' => true]);
        $category = Category::where('company_id', $company->id)->first()
            ?? Category::forceCreate(['name' => 'Progressive Lenses', 'company_id' => $company->id, 'is_active' => true]);

        $code = 'LB-TEST-' . rand(1000, 9999);
        $response = $this->postJson(route('products.quick-store'), [
            'name' => 'LB Progressive HD Short 70mm',
            'code' => $code,
            'type' => 'standard',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'cost' => 220,
            'price' => 550,
            'product_type_id' => $pt->id,
            'base' => '3.00',
            'addition' => '+2.00',
            'lr' => 'Pair',
            'dia' => '70',
            'is_optical' => 1,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'code' => $code,
            'product_type_id' => $pt->id,
            'base' => '3.00',
            'addition' => '+2.00',
            'dia' => '70',
        ]);
    }

    public function test_optical_product_edit_view_renders_brands_and_categories_for_company(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->actingAs($user);

        $company = Company::where('code', 'LENZ')->first() ?? Company::find(6) ?? Company::first();
        $branch = $company->branches()->first();
        $fy = $company->fiscalYears()->first();

        $brand = \App\Models\Brand::firstOrCreate(['company_id' => $company->id, 'title' => 'LenzBreeze'], ['is_active' => true]);
        $cat1 = \App\Models\Category::firstOrCreate(['company_id' => $company->id, 'name' => 'Progressive Lenses (PAL)'], ['is_active' => true]);
        $cat2 = \App\Models\Category::firstOrCreate(['company_id' => $company->id, 'name' => 'Bifocal & Kryptok'], ['is_active' => true]);

        $product = Product::withoutGlobalScope('request_company')
            ->where('company_id', $company->id)
            ->whereNotNull('brand_id')
            ->whereNotNull('category_id')
            ->first();

        if (!$product) {
            (new OpticalsMasterSeeder())->seedCompany($company);
            $product = Product::withoutGlobalScope('request_company')
                ->where('company_id', $company->id)
                ->whereNotNull('brand_id')
                ->whereNotNull('category_id')
                ->first();
        }
        if (!$product) {
            $unit = \App\Models\Unit::firstOrCreate(['company_id' => $company->id, 'unit_code' => 'PRS'], ['unit_name' => 'Pairs', 'operator' => '*', 'operation_value' => 1, 'is_active' => 1]);
            $product = Product::forceCreate([
                'name' => 'LB Progressive Test Lens',
                'code' => 'LB-TEST-PROG-' . mt_rand(1000, 9999),
                'type' => 'standard',
                'barcode_symbology' => 'C128',
                'brand_id' => $brand->id,
                'category_id' => $cat1->id,
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'cost' => 100,
                'price' => 200,
                'company_id' => $company->id,
                'is_active' => true,
            ]);
        }

        $this->assertNotNull($product, 'Expected at least one product with brand and category.');

        $response = $this->withSession([
            'company_id' => $company->id,
            'branch_id' => $branch ? $branch->id : 1,
            'financial_year_id' => $fy ? $fy->id : 1,
        ])->get(route('products.edit', $product->id));

        $response->assertStatus(200);
        $response->assertSee('LenzBreeze');
        $response->assertSee('Progressive Lenses (PAL)');
        $response->assertSee('Bifocal & Kryptok');
        $response->assertSee('value="' . $product->brand_id . '"', false);
        $response->assertSee('value="' . $product->category_id . '"', false);
    }

    public function test_optical_product_forms_guard_optional_file_and_store_succeeds(): void
    {
        foreach (['backend/product/create.blade.php', 'backend/product/edit.blade.php'] as $relativePath) {
            $source = file_get_contents(resource_path('views/' . $relativePath));
            $this->assertNotFalse($source);
            $this->assertStringNotContainsString("$('#file')[0].files", $source, "$relativePath must guard missing #file input in Optical profile.");
            $this->assertStringContainsString("document.getElementById('file')", $source);
            $this->assertStringContainsString("typeof tinyMCE !== 'undefined'", $source);
            $this->assertStringContainsString('restoreSubmit();', $source);
        }

        $user = User::where('role_id', 1)->first() ?? User::first() ?? User::factory()->create(['role_id' => 1, 'is_active' => true, 'is_deleted' => false]);
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'products-add', 'guard_name' => 'web']);
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['id' => $user->role_id], ['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user);

        $company = Company::create([
            'legal_name' => 'Optic Test Save Ltd',
            'trade_name' => 'Optic Test Save',
            'code' => 'OPT-SAVE-' . rand(100, 999),
            'status' => 'active',
            'country_code' => 'IN',
        ]);
        $branch = $company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch']);
        $fy = \App\Models\Accounting\FiscalYear::create([
            'company_id' => $company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $company->users()->syncWithoutDetaching([$user->id => ['is_default' => true]]);
        \Illuminate\Support\Facades\DB::table('company_user_branches')->updateOrInsert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);
        \Illuminate\Support\Facades\DB::table('company_industry_settings')->updateOrInsert(
            ['company_id' => $company->id],
            ['profile_key' => 'opticals', 'subtype' => 'lens_manufacturing', 'settings_json' => '{}', 'updated_by' => $user->id]
        );

        $pt = ProductType::firstOrCreate(['name' => 'Progressive Lenses (PAL)'], ['company_id' => $company->id, 'is_active' => true]);
        $unit = Unit::forceCreate(['unit_code' => 'PRS', 'unit_name' => 'Pairs', 'company_id' => $company->id, 'is_active' => true]);
        $category = Category::forceCreate(['name' => 'Optical Lenses', 'company_id' => $company->id, 'is_active' => true]);

        $session = [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'financial_year_id' => $fy->id,
        ];

        $createPage = $this->withSession($session)->get(route('products.create'));
        $this->assertSame(200, $createPage->getStatusCode(), 'Redirected to: ' . (string) $createPage->headers->get('Location'));
        $createPage->assertDontSee("$('#file')[0].files", false);
        $createPage->assertSee("document.getElementById('file')", false);

        $code = 'OPT-SAVE-' . rand(1000, 9999);
        $storeResponse = $this->withSession($session)->post(route('products.store'), [
            'name' => 'Optical PAL Lens 70mm',
            'code' => $code,
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 50,
            'profit_margin_type' => 'percentage',
            'profit_margin' => 300,
            'price' => 200,
            'product_type_id' => $pt->id,
            'is_optical' => 1,
            'base' => '2.00',
            'addition' => '+1.00',
            'lr' => 'Pair',
            'dia' => '70',
        ]);

        $storeResponse->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'code' => $code,
            'name' => 'Optical PAL Lens 70mm',
        ]);
    }
}
