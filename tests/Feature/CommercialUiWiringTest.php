<?php

namespace Tests\Feature;

use App\Models\DeliveryChallan;
use App\Models\GoodsReceivedNote;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialTestCase;

class CommercialUiWiringTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_13_000004_create_optech_dc_and_grn_tables.php'))->up();
        Schema::table('warehouses', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        DB::table('units')->update(['unit_code' => 'PC']);
        $this->withoutMiddleware(\App\Http\Middleware\RequireCapability::class);
    }

    public static function kinds(): array { return [['sale'], ['purchase']]; }

    #[DataProvider('kinds')]
    public function test_command_center_catalog_includes_service_items(string $kind): void
    {
        $product = $this->stock(0)->forceFill(['type' => 'service', 'unit_id' => 1, 'is_variant' => false]);
        $product->save();
        $controller = $kind === 'sale' ? app(\App\Http\Controllers\SaleController::class) : app(\App\Http\Controllers\PurchaseController::class);
        $item = $controller->productWithoutVariant()->firstWhere('id', $product->id);
        $this->assertNotNull($item);
        $this->assertSame('service', $item->type);
    }

    #[DataProvider('kinds')]
    public function test_paid_document_details_and_replacement_retry_preserve_history(string $kind): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $data = $kind === 'sale' ? $this->saleData($product) : $this->purchaseData($product);
        $data += ['paid_amount' => 5, 'paying_method' => 'Cash', 'account_id' => 1];
        $path = $kind === 'sale' ? '/sales' : '/purchases';
        $id = $this->postJson($path, $data + ['idempotency_key' => 'paid-original'])->assertCreated()->json('data.id');
        $this->getJson($path.'/'.$id.($kind === 'sale' ? '/json' : ''))->assertOk()
            ->assertJsonPath($kind.'.payments.0.paying_method', 'Cash')
            ->assertJsonPath($kind.'.payments.0.account_id', 1);
        $replacementData = $data + ['reason' => 'Correct original entry', 'idempotency_key' => 'paid-replacement'];
        $replacement = $this->putJson($path.'/'.$id, $replacementData)->assertCreated()->json('data.id');
        $this->putJson($path.'/'.$id, $replacementData)->assertCreated()->assertJsonPath('data.id', $replacement);
        $this->putJson($path.'/'.$id, array_replace($replacementData, ['idempotency_key' => 'another-replacement']))->assertUnprocessable();
        $this->assertDatabaseCount($kind === 'sale' ? 'sales' : 'purchases', 2);
    }

    private function material(string $kind, array $extra = []): array
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        return $extra + [
            $kind === 'sale' ? 'customer_id' : 'supplier_id' => 1,
            $kind === 'sale' ? 'challan_date' : 'grn_date' => '2026-10-03',
            'warehouse_id' => 1, 'product_id' => [$product->id], 'unit_id' => [1],
            'qty' => [2], $kind === 'sale' ? 'rate' : 'cost' => [10], 'tax_rate' => [5],
            'total' => [999], 'grand_total' => 999,
        ];
    }

    #[DataProvider('kinds')]
    public function test_material_rows_validate_and_calculate_totals_on_store_and_update(string $kind): void
    {
        $path = $kind === 'sale' ? '/delivery-challans' : '/goods-received-notes';
        $key = $kind === 'sale' ? 'challan' : 'grn';
        $data = $this->material($kind);
        $response = $this->postJson($path, $data)->assertCreated();
        $id = $response->json($key.'.id');
        $this->assertEquals(21, $response->json($key.'.grand_total'));
        $this->getJson($path.'/'.$id)->assertOk()->assertJsonPath('items.0.unit_id', 1)->assertJsonPath('items.0.qty', 2);
        $this->putJson($path.'/'.$id, array_replace($data, ['qty' => [3]]))->assertOk()
            ->assertJsonPath($key.'.grand_total', 31.5);
        $this->putJson($path.'/'.$id, array_replace($data, ['product_id' => [0]]))->assertUnprocessable();
        $this->getJson($path.'/'.$id)->assertOk()->assertJsonPath('items.0.qty', 3);
        $this->get($path.'/'.$id)->assertRedirect($path.'?edit='.$id);
    }

    #[DataProvider('kinds')]
    public function test_material_conversion_uses_shared_posting_and_rejects_a_second_invoice(string $kind): void
    {
        $model = $kind === 'sale' ? DeliveryChallan::class : GoodsReceivedNote::class;
        $field = $kind === 'sale' ? 'delivery_challan_id' : 'goods_received_note_id';
        $source = $model::forceCreate([
            'company_id' => $this->company->id, 'warehouse_id' => 1, 'status' => 'pending',
            $kind === 'sale' ? 'customer_id' : 'supplier_id' => 1,
            $kind === 'sale' ? 'challan_no' : 'grn_no' => 'SOURCE-1',
            $kind === 'sale' ? 'challan_date' : 'grn_date' => '2026-10-03',
        ]);
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $data = ($kind === 'sale' ? $this->saleData($product) : $this->purchaseData($product)) + [$field => $source->id, 'idempotency_key' => 'conversion-1'];
        $path = $kind === 'sale' ? '/sales' : '/purchases';
        $response = $this->postJson($path, $data)->assertCreated();
        $this->assertSame('converted_to_'.$kind, $source->fresh()->status);
        $this->assertSame($response->json('data.id'), $source->fresh()->{$kind.'_id'});
        $this->postJson($path, $data)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
        $this->postJson($path, array_replace($data, ['idempotency_key' => 'conversion-2']))->assertUnprocessable();
        $this->assertDatabaseCount($kind === 'sale' ? 'sales' : 'purchases', 1);
        $materialPath = $kind === 'sale' ? '/delivery-challans/' : '/goods-received-notes/';
        $this->putJson($materialPath.$source->id, $this->material($kind))->assertUnprocessable();
        $this->deleteJson($materialPath.$source->id)->assertUnprocessable();
    }

    #[DataProvider('kinds')]
    public function test_review_accepts_command_center_arrays_and_unit_codes(string $kind): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $data = [
            $kind === 'sale' ? 'customer_id' : 'supplier_id' => 1, 'warehouse_id' => 1, 'created_at' => '2026-10-03',
            'product_id' => [$product->id], 'qty' => [2], $kind.'_unit' => ['PC'],
            $kind === 'sale' ? 'net_unit_price' : 'net_unit_cost' => [10],
        ];
        $this->postJson('/commercial/'.$kind.'/preview', $data)->assertOk()->assertJsonPath('data.grand_total', 20);
    }

    #[DataProvider('kinds')]
    public function test_reviewed_zero_tax_lines_can_be_saved(string $kind): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $data = $kind === 'sale' ? $this->saleData($product) : $this->purchaseData($product);
        $data['items'][0][$kind === 'sale' ? 'net_unit_price' : 'net_unit_cost'] = 1.2345;
        $data += ['transport_name' => 'Audit transport', 'bale_no' => 'BALE-1', 'lr_no' => 'LR-1'];
        $draft = $this->postJson('/commercial/'.$kind.'/drafts', ['version' => 0, 'payload' => $this->snapshot($kind, ['note' => 'unfinished'])])->assertOk()->json('data');
        $data['draft_id'] = $draft['id'];
        $preview = $this->postJson('/commercial/'.$kind.'/preview', $data)->assertOk()->json('data');
        $response = $this->postJson($kind === 'sale' ? '/sales' : '/purchases', array_replace($data, $preview, ['idempotency_key' => 'reviewed-zero']));
        $this->assertSame(201, $response->status(), $response->getContent());
        $response->assertJsonPath('data.transport_name', 'Audit transport')->assertJsonPath('data.attributes_json.bale_no', 'BALE-1');
        $id = $response->json('data.id');
        $this->getJson($kind === 'sale' ? '/sales/'.$id.'/json' : '/purchases/'.$id)->assertOk()
            ->assertJsonPath('items.0.'.($kind === 'sale' ? 'net_unit_price' : 'net_unit_cost'), 1.2345)
            ->assertJsonPath($kind.'.payments', []);
        $this->assertDatabaseMissing('sale_drafts', ['id' => $draft['id']]);
    }
}
