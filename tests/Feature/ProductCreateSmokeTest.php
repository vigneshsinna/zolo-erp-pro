<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductCreateSmokeTest extends TestCase
{
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
        $this->assertStringNotContainsString('data-shell-steps', $content);
        $this->assertStringContainsString('value="'.e($product->name).'"', $content);
        preg_match_all('/name="([a-z_]+)/', $content, $names);
        $posted = array_intersect(array_unique($names[1]), (new Product)->getFillable());
        $this->assertSame([], array_values(array_diff($posted, Schema::getColumnListing('products'))));
    }
}
