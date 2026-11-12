<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\Platform\CompanyContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuickEditProductModalTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mysql.database' => 'salepro_db']);
        DB::purge('mysql');
        DB::reconnect('mysql');
        config(['commercial.enabled' => true]);
        putenv('ERP_OPTIONAL_ACTIVATION_READY=true');
        $_ENV['ERP_OPTIONAL_ACTIVATION_READY'] = 'true';
        Cache::flush();

        $this->adminUser = User::first();
    }

    public function test_voucher_pages_render_hover_edit_and_shared_modal_hooks(): void
    {
        $this->actingAs($this->adminUser);

        foreach (['/purchases', '/sales', '/goods-received-notes'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('id="quick-edit-product-id"', $html, $url);
            $this->assertStringContainsString('data-quick-edit-base=', $html, $url);
            $this->assertStringContainsString('quick-product-modal.js', $html, $url);
        }

        $purchaseHtml = $this->get('/purchases')->assertOk()->getContent();
        $this->assertStringContainsString('btn-quick-edit-product', $purchaseHtml);
        $this->assertStringContainsString('zoloSyncUpdatedProductInGrid', $purchaseHtml);

        $saleHtml = $this->get('/sales')->assertOk()->getContent();
        $this->assertStringContainsString('btn-quick-edit-product', $saleHtml);
        $this->assertStringContainsString('zoloSyncUpdatedProductInGrid', $saleHtml);
    }

    public function test_quick_edit_fetches_existing_product_and_quick_update_persists_changes(): void
    {
        $unit = Unit::where('company_id', 1)->first() ?? Unit::first();
        $category = Category::where('company_id', 1)->first() ?? Category::first();
        $tax5 = Tax::where('company_id', 1)->where('rate', 5)->first();
        $tax18 = Tax::where('company_id', 1)->where('rate', 18)->first();
        $code = 'QE-' . mt_rand(10000, 99999);

        $product = Product::forceCreate([
            'company_id' => 1,
            'name' => 'Cotton &amp; Linen Yarn',
            'code' => $code,
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $category?->id ?? 1,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 120.00,
            'price' => 150.00,
            'profit_margin_type' => 'percentage',
            'profit_margin' => 25.00,
            'tax_id' => $tax5?->id,
            'hsn_code' => '5205',
            'product_usage' => 'composite',
            'is_active' => 1,
        ]);

        // Fetch existing product for popup prefill
        $fetchRes = $this->actingAs($this->adminUser)
            ->getJson("/products/{$product->id}/quick-edit")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.name', 'Cotton & Linen Yarn')
            ->assertJsonPath('product.code', $code)
            ->assertJsonPath('product.hsn_code', '5205')
            ->assertJsonPath('product.cost', 120)
            ->assertJsonPath('product.price', 150);

        // Update keeping the exact same code and changing name, cost, price, tax & promotion
        $updateRes = $this->actingAs($this->adminUser)
            ->postJson("/products/{$product->id}/quick-update", [
                'name' => 'Updated Combed Yarn 40s',
                'code' => $code,
                'type' => 'standard',
                'barcode_symbology' => 'C128',
                'category_id' => $category?->id ?? 1,
                'unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'product_usage' => 'purchase',
                'cost' => 145.50,
                'price' => 195.00,
                'profit_margin_type' => 'percentage',
                'tax_id' => $tax18?->id,
                'hsn_code' => '520611',
                'tax_method' => 1,
                'is_batch' => 1,
                'promotion' => 1,
                'promotion_price' => 180.00,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.name', 'Updated Combed Yarn 40s')
            ->assertJsonPath('product.code', $code)
            ->assertJsonPath('product.cost', 145.5)
            ->assertJsonPath('product.price', 195)
            ->assertJsonPath('product.hsn_code', '520611');

        $fresh = $product->fresh();
        $this->assertSame('Updated Combed Yarn 40s', $fresh->name);
        $this->assertSame('520611', $fresh->hsn_code);
        $this->assertEquals(145.50, (float) $fresh->cost);
        $this->assertEquals(195.00, (float) $fresh->price);
        $this->assertSame(1, (int) $fresh->is_batch);
        $this->assertSame(1, (int) $fresh->promotion);
        $this->assertSame('purchase', $fresh->product_usage);
    }

    public function test_quick_update_rejects_duplicate_code_and_blocks_foreign_company_product(): void
    {
        $unit = Unit::where('company_id', 1)->first() ?? Unit::first();
        $category = Category::where('company_id', 1)->first() ?? Category::first();

        $p1 = Product::forceCreate([
            'company_id' => 1,
            'name' => 'Item One',
            'code' => 'DUP-1-' . mt_rand(1000, 9999),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $category?->id ?? 1,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 10,
            'price' => 20,
            'is_active' => 1,
        ]);

        $p2 = Product::forceCreate([
            'company_id' => 1,
            'name' => 'Item Two',
            'code' => 'DUP-2-' . mt_rand(1000, 9999),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $category?->id ?? 1,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 15,
            'price' => 25,
            'is_active' => 1,
        ]);

        // Duplicate code check
        $this->actingAs($this->adminUser)
            ->postJson("/products/{$p2->id}/quick-update", [
                'name' => 'Item Two Edited',
                'code' => $p1->code,
                'type' => 'standard',
                'unit_id' => $unit->id,
                'cost' => 15,
                'price' => 25,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // Cross-company product cannot be fetched or edited under Company 1 context
        $foreignProduct = Product::withoutGlobalScopes()->where('company_id', 4)->first();
        if ($foreignProduct) {
            $this->actingAs($this->adminUser)
                ->getJson("/products/{$foreignProduct->id}/quick-edit")
                ->assertStatus(404);
        }
    }
}
