<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\CommercialTestCase;

/** Sales orders saved from the Sales Orders tab are SalesOrder documents, editable in full and deletable until fulfilled. */
class SalesOrderLifecycleTest extends CommercialTestCase
{
    private function order(array $extra = []): array
    {
        return $this->saleData($this->stock(100), $extra + ['promised_date' => '2026-10-05', 'idempotency_key' => 'so-'.uniqid()]);
    }

    public function test_the_orders_tab_saves_a_sales_order_not_a_pending_bill(): void
    {
        $sales = DB::table('sales')->count();
        $order = $this->postJson('/commercial/orders/sale', $this->order())->assertCreated()->json('data');
        $this->assertSame('open', DB::table('sales_orders')->where('id', $order['id'])->value('status'));
        $this->assertSame('2026-10-05', substr((string) DB::table('sales_orders')->where('id', $order['id'])->value('promised_date'), 0, 10), 'Delivery Date is the promised date');
        $this->assertSame($sales, DB::table('sales')->count(), 'no bill is created');
        $row = collect($this->getJson('/commercial/orders/sale')->assertOk()->json('data'))->firstWhere('id', $order['id']);
        $this->assertTrue($row['untouched']);
    }

    public function test_an_untouched_sales_order_is_replaced_in_full_and_the_old_one_cancelled(): void
    {
        $old = $this->postJson('/commercial/orders/sale', $this->order())->assertCreated()->json('data');
        $this->postJson('/commercial/orders/sale', $this->order(['replaces_order_id' => $old['id']]))->assertStatus(422)->assertJsonValidationErrors('reason');
        $edit = $this->order(['replaces_order_id' => $old['id'], 'reason' => 'Customer wants 5']);
        $edit['items'][0]['qty'] = 5;
        $new = $this->postJson('/commercial/orders/sale', $edit)->assertCreated()->json('data');
        $this->assertNotEquals($old['id'], $new['id']);
        $this->assertSame('cancelled', DB::table('sales_orders')->where('id', $old['id'])->value('status'));
        $this->assertEquals(5, DB::table('commercial_order_items')->where('order_kind', 'sale')->where('order_id', $new['id'])->value('qty'));
        $this->assertSame($new['id'], $this->postJson('/commercial/orders/sale', $edit)->assertCreated()->json('data.id'), 'a resubmitted edit changes nothing');
        // Delete = cancel in full with a reason; it leaves the open list.
        $this->postJson('/commercial/orders/sale/'.$new['id'].'/cancel', ['business_date' => '2026-10-03', 'reason' => 'Order dropped'])->assertOk();
        $this->assertNull(collect($this->getJson('/commercial/orders/sale')->json('data'))->firstWhere('id', $new['id']));
    }
}
