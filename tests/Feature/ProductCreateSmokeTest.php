<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductCreateSmokeTest extends TestCase
{
    use DatabaseTransactions;
    public function test_product_create_page_renders_cleanly(): void
    {
        $user = User::first();
        $company = Company::first();

        $response = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->get('/products/create');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('product-single-screen-card', $content);
        $this->assertStringContainsString('data-product-usage="composite"', $content);
        $this->assertStringContainsString('name="product_usage" value="purchase"', $content);
        $this->assertStringContainsString('name="product_usage" value="sale"', $content);
        $this->assertStringContainsString('name="product_usage" value="composite"', $content);
        $this->assertStringContainsString('zolo-product-grid', $content);
        $this->assertStringContainsString('product-extended-details-container', $content);
        $this->assertStringContainsString('id="variant-section"', $content);
        $this->assertStringContainsString('id="initial-stock-section"', $content);
        $this->assertStringContainsString('id="diffPrice-section"', $content);
        $this->assertStringContainsString('id="promo-container"', $content);
        $this->assertStringContainsString('id="combo"', $content);
    }

    public function test_product_create_form_only_posts_fields_the_products_table_has(): void
    {
        $user = User::first();
        $company = Company::first();

        $content = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->get('/products/create')->getContent();

        // An addon-only field (e.g. tags) rendered without its addon makes every save fail on the insert.
        preg_match_all('/name="([a-z_]+)/', $content, $names);
        $posted = array_intersect(array_unique($names[1]), (new Product)->getFillable());
        $this->assertSame([], array_values(array_diff($posted, Schema::getColumnListing('products'))));
    }

    public function test_product_edit_page_uses_the_same_single_screen_layout_as_create(): void
    {
        $user = User::first();
        $company = Company::first();
        $product = Product::where('company_id', $company->id)->where('is_active', true)->firstOrFail();

        $content = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->get('/products/'.$product->id.'/edit')->assertOk()->getContent();

        $this->assertStringContainsString('product-single-screen-card', $content);
        $this->assertStringContainsString('zolo-product-grid', $content);
        $this->assertStringContainsString('zolo-inventory-stripe', $content);
        $this->assertStringContainsString('data-product-usage="'.e($product->product_usage ?? 'composite').'"', $content);
        $this->assertStringNotContainsString('data-shell-steps', $content);
        $this->assertStringContainsString('value="'.e($product->name).'"', $content);
        preg_match_all('/name="([a-z_]+)/', $content, $names);
        $posted = array_intersect(array_unique($names[1]), (new Product)->getFillable());
        $this->assertSame([], array_values(array_diff($posted, Schema::getColumnListing('products'))));
    }

    public function test_product_create_and_edit_pages_render_defensive_file_and_tinymce_guards(): void
    {
        $user = User::first();
        $company = Company::where('code', 'LENZ')->first() ?? Company::first();

        // Check Create page under optical profile
        $createContent = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->get('/products/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("var fileEl = $('#file')[0];", $createContent);
        $this->assertStringContainsString("if (typeof tinyMCE !== 'undefined')", $createContent);

        $unit = Unit::where('company_id', $company->id)->first()
            ?? Unit::forceCreate(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true, 'company_id' => $company->id]);
        $category = Category::where('company_id', $company->id)->first()
            ?? Category::forceCreate(['name' => 'General Lens', 'company_id' => $company->id, 'is_active' => true]);

        // Check Edit page
        $product = Product::withoutGlobalScope('request_company')->where('company_id', $company->id)->first()
            ?? Product::forceCreate([
                'name' => 'Defensive Guard Check Lens',
                'code' => 'DEF-GUARD-01',
                'type' => 'standard',
                'barcode_symbology' => 'C128',
                'cost' => 100,
                'price' => 200,
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'company_id' => $company->id,
                'is_active' => true,
            ]);

        $editContent = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->get('/products/' . $product->id . '/edit')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("var fileEl = $('#file')[0];", $editContent);
        $this->assertStringContainsString("if (typeof tinyMCE !== 'undefined')", $editContent);
    }

    public function test_product_store_with_optical_specs_persists_via_products_store(): void
    {
        $user = User::first();
        $company = Company::where('code', 'LENZ')->first() ?? Company::first();
        $unit = Unit::where('company_id', $company->id)->first()
            ?? Unit::forceCreate(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true, 'company_id' => $company->id]);
        $category = Category::where('company_id', $company->id)->first()
            ?? Category::forceCreate(['name' => 'General Lens', 'company_id' => $company->id, 'is_active' => true]);
        $pt = ProductType::firstOrCreate(['name' => 'D. Bifocal'], ['is_active' => true]);

        $code = 'OPT-STORE-' . rand(10000, 99999);

        $response = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post(route('products.store'), [
                'name' => 'Optical Progressive Test Lens',
                'code' => $code,
                'barcode_symbology' => 'C128',
                'type' => 'standard',
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'cost' => 150,
                'price' => 250,
                'profit_margin' => 25,
                'profit_margin_type' => 'percentage',
                'product_type_id' => $pt->id,
                'base' => '2.00',
                'addition' => '+0.75',
                'lr' => 'Pair',
                'dia' => '65',
                'is_optical' => 1,
                'product_usage' => 'composite',
            ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'code' => $code,
            'company_id' => $company->id,
            'product_type_id' => $pt->id,
            'base' => '2.00',
            'addition' => '+0.75',
            'lr' => 'Pair',
            'dia' => '65',
            'is_active' => 1,
        ]);
    }
}
