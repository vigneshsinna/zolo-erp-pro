<?php

namespace Tests\Feature;

use App\Models\ProductAlias;
use App\Services\Commercial\ProductAliasService;
use App\Services\Commercial\ProductQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialTestCase;

class ProductAliasTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_19_000014_create_product_aliases.php'))->up();
    }

    private function alias(int $product, string $name, string $type = 'company', ?int $party = null): ProductAlias
    {
        return app(ProductAliasService::class)->create(['product_id' => $product, 'name' => $name,
            'party_type' => $type, 'party_id' => $party], $this->context(), 1);
    }

    public function test_unicode_space_case_normalization_is_idempotent_without_collapsing_product_identity(): void
    {
        $a = $this->stock(); $b = $this->stock();
        $first = $this->alias($a->id, "  CAFÉ\u{00a0} Cotton  ");
        $same = $this->alias($a->id, 'café cotton');
        $this->assertSame($first->id, $same->id);
        $this->assertSame('café cotton', $first->normalized_name);
        $this->assertNotSame($first->id, $this->alias($b->id, 'café cotton')->id);
        $this->assertSame(1, (int) $first->created_by);
        $this->assertNotNull($first->created_at);
        $this->assertSame('café cotton', ProductAliasService::normalize(" ＣＡＦＥ\u{0301} Cotton "));
    }

    public function test_exact_code_then_selected_party_then_common_alias_ranking_preserves_ambiguous_matches(): void
    {
        $code = $this->stock()->forceFill(['code' => 'Cloth']); $code->save();
        $party = $this->stock(); $commonA = $this->stock(); $commonB = $this->stock();
        $this->alias($party->id, 'Cloth', 'customer', 1);
        $this->alias($commonA->id, 'Cloth'); $this->alias($commonB->id, 'Cloth');
        $matches = app(ProductQueryService::class)->search('Cloth', $this->context(), 'customer', 1);
        $this->assertSame([$code->id, $party->id], array_slice(array_column($matches, 'id'), 0, 2));
        $this->assertSame([0, 1, 2, 2], array_column($matches, 'match_rank'));
        $this->assertCount(4, array_unique(array_column($matches, 'id')));
    }

    public function test_party_aliases_do_not_leak_to_another_party_kind_or_company(): void
    {
        $a = $this->stock(); $common = $this->stock();
        $this->alias($a->id, 'Party cloth', 'customer', 1);
        $this->alias($common->id, 'Common cloth');
        DB::table('customers')->insert(['id' => 2, 'company_id' => $this->company->id, 'name' => 'Other customer']);
        DB::table('product_aliases')->insert(['company_id' => $this->other->id, 'product_id' => $a->id, 'party_type' => 'company',
            'party_id' => 0, 'name' => 'Foreign cloth', 'normalized_name' => 'foreign cloth', 'created_by' => 1]);
        $service = app(ProductQueryService::class);
        $this->assertCount(1, $service->search('Party cloth', $this->context(), 'customer', 1));
        $this->assertSame([], $service->search('Party cloth', $this->context(), 'customer', 2));
        $this->assertSame([], $service->search('Party cloth', $this->context(), 'supplier', 1));
        $this->assertSame([], $service->search('Party cloth', $this->context()));
        $this->assertSame([], $service->search('Foreign cloth', $this->context()));
        $this->assertSame([$common->id], array_column($service->search('Common cloth', $this->context()), 'id'));
    }

    public function test_literal_wildcards_and_alias_ownership_are_validated_at_the_boundary(): void
    {
        $a = $this->stock(); $b = $this->stock();
        $this->alias($a->id, '50%_cotton'); $this->alias($b->id, '500Xcotton');
        $this->assertSame([$a->id], array_column(app(ProductQueryService::class)->search('50%_', $this->context()), 'id'));
        DB::table('customers')->insert(['id' => 9, 'company_id' => $this->other->id, 'name' => 'Foreign']);
        $this->postJson('/product-aliases', ['product_id' => $a->id, 'name' => 'Foreign', 'party_type' => 'customer', 'party_id' => 9])->assertUnprocessable();
        DB::table('products')->where('id', $b->id)->update(['company_id' => $this->other->id]);
        $this->postJson('/product-aliases', ['product_id' => $b->id, 'name' => 'Foreign', 'party_type' => 'company'])->assertUnprocessable();
        $this->getJson('/commercial/sale/search/products?q=cloth&party_id=9')->assertUnprocessable();
    }

    public function test_manager_requires_product_permissions_and_cannot_delete_another_company_alias(): void
    {
        $item = $this->stock(); $alias = $this->alias($item->id, 'Managed');
        $this->get('/product-aliases')->assertOk()->assertSee('Alternate product names')->assertSee('Managed');
        DB::table('product_aliases')->where('id', $alias->id)->update(['company_id' => $this->other->id]);
        $this->deleteJson('/product-aliases/'.$alias->id)->assertNotFound();
        Schema::create('permissions', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        Schema::create('role_has_permissions', function (Blueprint $t) { $t->unsignedInteger('role_id'); $t->unsignedInteger('permission_id'); });
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', 1)->update(['role_id_override' => 4]);
        $this->getJson('/product-aliases')->assertForbidden();
        $this->postJson('/product-aliases', ['product_id' => $item->id, 'name' => 'Denied', 'party_type' => 'company'])->assertForbidden();
        $this->deleteJson('/product-aliases/'.$alias->id)->assertForbidden();
    }

    public function test_commercial_search_preserves_existing_projection_and_hides_cost_without_permission(): void
    {
        $item = $this->stock(); $this->alias($item->id, 'Search alias');
        $this->getJson('/commercial/sale/search/products?q=Search%20alias')->assertOk()
            ->assertJsonPath('data.0.id', $item->id)->assertJsonPath('data.0.match_rank', 2);
        Schema::create('permissions', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        Schema::create('role_has_permissions', function (Blueprint $t) { $t->unsignedInteger('role_id'); $t->unsignedInteger('permission_id'); });
        DB::table('permissions')->insert(['id' => 1, 'name' => 'sales-add']);
        DB::table('role_has_permissions')->insert(['role_id' => 4, 'permission_id' => 1]);
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', 1)->update(['role_id_override' => 4]);
        $response = $this->getJson('/commercial/sale/search/products?q=Search%20alias')->assertOk();
        $this->assertArrayNotHasKey('cost', $response->json('data.0'));
        $this->assertArrayHasKey('unit_id', $response->json('data.0'));
        $this->assertArrayHasKey('is_variant', $response->json('data.0'));
    }

    public function test_both_actual_grids_use_shared_lookup_and_do_not_find_the_first_alias_on_enter(): void
    {
        foreach (['sale', 'purchase'] as $kind) {
            $view = file_get_contents(resource_path('views/backend/'.$kind.'/index.blade.php'));
            $this->assertSame(4, substr_count($view, "allProducts, '$kind', matchesProductMode"));
            $this->assertSame(2, substr_count($view, 'CommercialProductLookup.exact(matches, val)'));
            $this->assertStringContainsString('autoFocus: false', $view);
        }
    }

    private function posCatalog(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['is_diffPrice', 'is_embeded'] as $field) $table->boolean($field)->default(false);
            $table->text('product_list')->nullable(); $table->text('qty_list')->nullable();
        });
        if (!Schema::hasColumn('product_warehouse', 'imei_number')) Schema::table('product_warehouse', fn (Blueprint $table) => $table->text('imei_number')->nullable());
        if (!Schema::hasTable('product_batches')) Schema::create('product_batches', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('product_id'); $table->string('batch_no'); $table->date('expired_date')->nullable();
        });
        if (!Schema::hasTable('product_variants')) Schema::create('product_variants', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedBigInteger('company_id'); $table->unsignedInteger('product_id');
            $table->unsignedInteger('variant_id'); $table->string('item_code'); $table->double('additional_price')->default(0); $table->double('qty')->default(0);
        });
    }

    public function test_pos_alias_lookup_keeps_selected_warehouse_stock_and_price(): void
    {
        $this->posCatalog();
        $item = $this->stock(17)->forceFill(['is_diffPrice' => true, 'price' => 100]); $item->save();
        DB::table('product_warehouse')->where('product_id', $item->id)->where('warehouse_id', 1)->update(['price' => 42]);
        $this->alias($item->id, 'POS cloth', 'customer', 1);
        $this->getJson('/sales/search/1/'.base64_encode('POS cloth').'?customer_id=1')->assertOk()
            ->assertJsonPath('0.id', $item->id)->assertJsonPath('0.price', 42)->assertJsonPath('0.qty', 17);
        $this->getJson('/sales/search/1/'.base64_encode('POS cloth'))->assertOk()->assertExactJson([]);
    }

    public function test_pos_variant_alias_uses_the_same_warehouse_price_as_exact_variant_code(): void
    {
        $this->posCatalog();
        $item = $this->stock(17)->forceFill(['is_diffPrice' => true, 'price' => 100, 'is_variant' => true]); $item->save();
        DB::table('product_variants')->insert(['company_id' => $this->company->id, 'product_id' => $item->id,
            'variant_id' => 1, 'item_code' => 'VAR-ALIAS', 'additional_price' => 7]);
        DB::table('product_warehouse')->where('product_id', $item->id)->where('warehouse_id', 1)->update(['price' => 42, 'variant_id' => 1]);
        $this->alias($item->id, 'Variant cloth', 'customer', 1);
        foreach (['VAR-ALIAS', 'Variant cloth'] as $search) $this->getJson('/sales/search/1/'.base64_encode($search).'?customer_id=1')
            ->assertOk()->assertJsonPath('0.code', 'VAR-ALIAS')->assertJsonPath('0.price', 42)->assertJsonPath('0.qty', 17);
    }

    public function test_pos_ambiguous_alias_keeps_second_product_after_twenty_lots_of_first_product(): void
    {
        $this->posCatalog();
        $a = $this->stock(); $b = $this->stock();
        foreach ([$a, $b] as $product) $this->alias($product->id, 'Many lots');
        for ($lot = 1; $lot <= 25; $lot++) DB::table('product_warehouse')->insert(['company_id' => $this->company->id,
            'product_id' => $a->id, 'warehouse_id' => 1, 'qty' => 1, 'product_batch_id' => $lot]);
        $response = $this->getJson('/sales/search/1/'.base64_encode('Many lots'))->assertOk();
        $this->assertSame([$a->id, $b->id], array_column($response->json(), 'id'));
    }

    public function test_full_length_alias_is_searchable_in_normal_grid_endpoint(): void
    {
        $item = $this->stock(); $name = str_repeat('Fabric', 25);
        $this->alias($item->id, $name);
        $this->getJson('/commercial/sale/search/products?q='.urlencode($name))->assertOk()->assertJsonPath('data.0.id', $item->id);
    }

    public function test_pos_templates_reject_stale_customer_warehouse_and_query_results_before_autoselection(): void
    {
        foreach (['edit', 'pos'] as $view) {
            $source = file_get_contents(resource_path('views/backend/sale/'.$view.'.blade.php'));
            $this->assertStringContainsString('String(warehouse_id) === warehouse', $source);
            $this->assertStringContainsString('sequence === productSearchSequence', $source);
            $this->assertStringContainsString("String(\$input.val() || '').trim() === term", $source);
            $this->assertStringContainsString('if (!currentProductSearch(lookupSequence, lookupCustomer, lookupWarehouse, lookupTerm)) return;', $source);
        }
    }
}
