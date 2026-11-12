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

        $pt = ProductType::firstOrCreate(['name' => 'Progressive HD Short'], ['is_active' => true]);
        $unit = Unit::firstOrCreate(['unit_code' => 'PRS'], ['unit_name' => 'Pairs', 'is_active' => true]);
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

        $product = Product::withoutGlobalScope('request_company')
            ->where('company_id', $company->id)
            ->whereNotNull('brand_id')
            ->whereNotNull('category_id')
            ->first();

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
}
