<?php

namespace Tests\Feature;

use App\Services\Platform\DocumentShortcutRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommercialTestCase;

/**
 * Evidence for the Ctrl+F12 decision. A Purchase Order is purchases.status = 4 on the normal purchase form.
 * These checks, allocation tests and MySQL races provide evidence for the shortcut gate.
 */
class PurchaseOrderLifecycleTest extends CommercialTestCase
{
    private function legacyOrder(array $overrides = []): array
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        return $overrides + ['supplier_id' => 1, 'warehouse_id' => 1, 'created_at' => '2026-10-03', 'status' => 4,
            'product_id' => [$product->id], 'qty' => [2], 'purchase_unit' => ['PCS'], 'net_unit_cost' => [10],
            'idempotency_key' => 'order-'.uniqid()];
    }

    public function test_the_normal_form_saves_an_order_without_stock_accounting_open_items_or_payment(): void
    {
        $before = ['stock' => DB::table('stock_movements')->count(), 'journal' => DB::table('journal_entries')->count(), 'open' => DB::table('account_open_items')->count()];
        $response = $this->postJson('/purchases', $this->legacyOrder())->assertCreated();
        $order = DB::table('purchases')->find($response->json('data.id'));
        $this->assertSame(4, (int) $order->status);
        $this->assertNotEmpty($order->reference_no, 'an order is allocated a document number');
        $this->assertNull($order->posted_at, 'an order is never posted');
        $this->assertSame($before, ['stock' => DB::table('stock_movements')->count(), 'journal' => DB::table('journal_entries')->count(), 'open' => DB::table('account_open_items')->count()]);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertEquals(100, DB::table('products')->where('id', $this->legacyOrder()['product_id'][0])->value('qty'), 'ordered stock is not received');
    }

    public function test_an_untouched_order_can_be_edited_in_full_and_deleted(): void
    {
        $order = $this->postJson('/purchases', $this->legacyOrder())->assertCreated()->json('data');
        $row = fn ($id) => collect($this->getJson('/commercial/orders/purchase?status=all')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertTrue($row($order['id'])['untouched'], 'nothing received or billed yet');
        $edited = $this->putJson('/commercial/purchase/'.$order['id'], $this->legacyOrder(['qty' => [7], 'business_date' => '2026-10-03', 'reason' => 'Supplier revised quantity']))
            ->assertCreated()->json('data');
        $this->assertSame(4, (int) DB::table('purchases')->where('id', $edited['id'])->value('status'), 'a full edit stays an order');
        $this->assertNotNull(DB::table('purchases')->where('id', $order['id'])->value('reversed_at'), 'the old version is kept as reversed history');
        $this->postJson('/commercial/purchase/'.$edited['id'].'/reverse', ['business_date' => '2026-10-02', 'reason' => 'Too early'])
            ->assertStatus(422)->assertJsonValidationErrors('business_date');
        $this->postJson('/commercial/purchase/'.$edited['id'].'/reverse', ['business_date' => '2026-10-03', 'reason' => 'Order dropped'])->assertOk();
        $this->assertTrue($row($edited['id'])['reversed']);
    }

    public function test_an_order_cannot_take_payment(): void
    {
        $this->postJson('/purchases', $this->legacyOrder(['paid_amount' => 5, 'paying_method' => 'Cash', 'account_id' => 1]))->assertStatus(422);
        $this->assertSame(0, DB::table('purchases')->count());
    }

    /** The same item as an earlier payload, with its own retry key. */
    private function again(array $base, array $overrides): array
    {
        return $overrides + ['idempotency_key' => 'order-'.uniqid()] + $base;
    }

    public function test_a_receipt_links_only_to_an_active_order_for_the_same_supplier_and_warehouse(): void
    {
        $base = $this->legacyOrder(['qty' => [4]]);
        $order = $this->postJson('/purchases', $base)->assertCreated()->json('data');
        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'], 'qty' => [2]]))->assertCreated();
        $this->assertSame(1, DB::table('stock_movements')->count(), 'the receipt, not the order, moved stock');

        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'] + 100]))->assertStatus(404);
        $this->postJson('/purchases', $this->legacyOrder(['status' => 1, 'purchase_order_id' => $order['id']]))->assertStatus(422); // item not on the order
        $this->postJson('/commercial/purchase/'.$order['id'].'/reverse', ['business_date' => '2026-10-03', 'reason' => 'Order cancelled'])->assertUnprocessable();
        $this->postJson('/commercial/orders/purchase/'.$order['id'].'/cancel', ['business_date' => '2026-10-03', 'reason' => 'Remainder cancelled'])->assertOk();
        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'], 'qty' => [1]]))->assertStatus(422);
    }

    /** Production matrix: an order has its own number series, cannot be over-received and closes once fully received. */
    public function test_an_order_has_its_own_series_closes_when_fully_received_and_refuses_over_receipt(): void
    {
        $base = $this->legacyOrder(['qty' => [3]]);
        $order = $this->postJson('/purchases', $base)->assertCreated()->json('data');
        $fulfilled = fn () => json_decode((string) DB::table('purchases')->where('id', $order['id'])->value('attributes_json'), true)['fulfilled_at'] ?? null;
        $this->assertMatchesRegularExpression('/-PO-/', DB::table('purchases')->where('id', $order['id'])->value('reference_no'), 'orders draw from the order series');
        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'], 'qty' => [4]]))->assertStatus(422);
        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'], 'qty' => [2]]))->assertCreated();
        $this->assertNull($fulfilled(), 'partly received orders stay open');
        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'], 'qty' => [1]]))->assertCreated();
        $this->assertNotNull($fulfilled(), 'fully received orders close');
        $this->postJson('/purchases', $this->again($base, ['status' => 1, 'purchase_order_id' => $order['id'], 'qty' => [1]]))->assertStatus(422);
    }

    public function test_order_shortcut_requires_enabled_gate_and_installed_lifecycle(): void
    {
        config(['documents.purchase_order_shortcut' => false]);
        $registry = app(DocumentShortcutRegistry::class);
        $this->assertNotContains('purchase-order', array_column($registry->forUser(fn () => true, true), 'id'));
        config(['documents.purchase_order_shortcut' => true]);
        $this->assertContains('purchase-order', array_column($registry->forUser(fn () => true, true), 'id'));
        \Illuminate\Support\Facades\Schema::drop('commercial_order_events');
        $this->assertNotContains('purchase-order', array_column($registry->forUser(fn () => true, true), 'id'));
    }
}
