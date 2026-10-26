<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
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
}
