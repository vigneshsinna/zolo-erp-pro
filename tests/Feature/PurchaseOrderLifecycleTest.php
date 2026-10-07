<?php

namespace Tests\Feature;

use App\Services\Platform\DocumentShortcutRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommercialTestCase;

/**
 * Evidence for the Ctrl+F12 decision. A Purchase Order is purchases.status = 4 on the normal purchase form.
 * These tests pin what is proven; the gaps that keep the shortcut unassigned are listed in documents/DOCUMENT_ENTRY_CONSOLIDATION.md.
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

    public function test_an_order_cannot_take_payment(): void
    {
        $this->postJson('/purchases', $this->legacyOrder(['paid_amount' => 5, 'paying_method' => 'Cash', 'account_id' => 1]))->assertStatus(422);
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_a_receipt_links_only_to_an_active_order_for_the_same_supplier_and_warehouse(): void
    {
        $order = $this->postJson('/purchases', $this->legacyOrder())->assertCreated()->json('data');
        $bill = $this->legacyOrder(['status' => 1, 'purchase_order_id' => $order['id']]);
        $this->postJson('/purchases', $bill)->assertCreated();
        $this->assertSame(1, DB::table('stock_movements')->count(), 'the receipt, not the order, moved stock');

        $this->postJson('/purchases', $this->legacyOrder(['status' => 1, 'purchase_order_id' => $order['id'] + 100]))->assertStatus(404);
        $this->postJson('/commercial/purchase/'.$order['id'].'/reverse', ['business_date' => '2026-10-03', 'reason' => 'Order cancelled'])->assertOk();
        $this->postJson('/purchases', $this->legacyOrder(['status' => 1, 'purchase_order_id' => $order['id']]))->assertStatus(422);
    }

    /** Characterisation of an OPEN gap (see the consolidation document): nothing closes an order once it is received against. */
    public function test_known_gap_an_order_has_no_fulfilled_state_and_shares_the_bill_number_series(): void
    {
        $order = $this->postJson('/purchases', $this->legacyOrder())->assertCreated()->json('data');
        $first = $this->postJson('/purchases', $this->legacyOrder(['status' => 1, 'purchase_order_id' => $order['id']]))->assertCreated()->json('data');
        $second = $this->postJson('/purchases', $this->legacyOrder(['status' => 1, 'purchase_order_id' => $order['id']]))->assertCreated()->json('data');
        $this->assertSame(4, (int) DB::table('purchases')->where('id', $order['id'])->value('status'), 'the order stays open after two receipts');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertMatchesRegularExpression('/-PUR-/', DB::table('purchases')->where('id', $order['id'])->value('reference_no'), 'orders draw from the purchase bill series');
    }

    public function test_the_order_shortcut_stays_unassigned_until_the_open_lifecycle_gaps_are_closed(): void
    {
        $this->assertFalse(config('documents.purchase_order_shortcut'));
        $registry = app(DocumentShortcutRegistry::class);
        $this->assertNotContains('purchase-order', array_column($registry->forUser(fn () => true, true), 'id'));
    }
}
