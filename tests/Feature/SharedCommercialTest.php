<?php

namespace Tests\Feature;

use App\Events\CommercialDocumentPosted;
use App\Models\Accounting\JournalEntry;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\Commercial\CommercialDraftService;
use App\Services\Commercial\CommercialReversalService;
use App\Services\Commercial\PurchaseApplicationService;
use App\Services\Commercial\PurchaseCommand;
use App\Services\Commercial\PurchaseReceiptService;
use App\Services\Commercial\SaleApplicationService;
use App\Services\Commercial\SaleCommand;
use App\Services\Commercial\SalePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommercialTestCase;

class SharedCommercialTest extends CommercialTestCase
{
    public function test_legacy_purchase_payment_filters_use_amounts_for_due_partial_and_paid_documents(): void
    {
        $product = $this->stock();
        $purchases = [];
        foreach ([0, 4, 10] as $paid) {
            $purchases[] = app(PurchaseApplicationService::class)->create(new PurchaseCommand(
                $this->purchaseData($product, ['paid_amount' => $paid]), 'purchase-payment-'.$paid, 1, $this->context()));
        }

        $this->assertEquals([2, 3, 4], array_map(fn ($purchase) => $purchase->payment_status, $purchases));
        $this->assertEquals([$purchases[0]->id, $purchases[1]->id],
            \App\Models\Purchase::withLegacyPaymentStatus(1)->orderBy('id')->pluck('id')->all());
        $this->assertEquals([$purchases[2]->id], \App\Models\Purchase::withLegacyPaymentStatus(2)->pluck('id')->all());
    }

    private function sale(array $data, string $key = 'test-sale'): Sale
    {
        return app(SaleApplicationService::class)->create(new SaleCommand($data, $key, 1, $this->context()));
    }

    public function test_duplicate_request_and_effect_post_return_original_without_extra_effects(): void
    {
        Event::fake([CommercialDocumentPosted::class]);
        $product = $this->stock();
        $data = $this->saleData($product, ['paid_amount' => 5]);
        $sale = $this->sale($data);
        $retry = $this->sale($data);
        app(SalePostingService::class)->post($sale, $this->context(), 1);
        $this->assertSame($sale->id, $retry->id);
        $this->assertEquals(18, $product->fresh()->qty);
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertEquals(15, DB::table('account_open_items')->sum('open_amount'));
        Event::assertDispatchedTimes(CommercialDocumentPosted::class, 1);
    }

    public function test_retry_after_period_lock_returns_original_but_changed_payload_is_rejected(): void
    {
        $data = $this->saleData($this->stock());
        $sale = $this->sale($data);
        $this->year->update(['lock_date' => '2026-10-03']);
        $this->assertSame($sale->id, $this->sale($data)->id);
        $this->expectException(ValidationException::class);
        $this->sale($data + ['sale_note' => 'Changed']);
    }

    public function test_web_api_and_pos_use_equivalent_postings(): void
    {
        $product = $this->stock(40);
        $data = $this->saleData($product);
        $api = $this->postJson('/api/v1/sales', $data, ['Idempotency-Key' => 'api-sale'])->assertCreated()->json('data.id');
        $web = $this->postJson('/sales', $data, ['Idempotency-Key' => 'web-sale'])->assertCreated()->json('data.id');
        $pos = $this->postJson('/sales', $data + ['pos' => 1], ['Idempotency-Key' => 'pos-sale'])->assertOk()->json();
        $effects = function ($id) {
            $journal = JournalEntry::where('reference_type', 'sale')->where('reference_id', $id)->firstOrFail();
            return [DB::table('stock_movement_lines')->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')
                ->where('stock_movements.source_id', $id)->get(['qty_base', 'value'])->toArray(),
                $journal->items()->get(['chart_of_account_id', 'debit', 'credit', 'partner_type', 'partner_id'])->toArray(),
                DB::table('account_open_items')->where('source_type', 'sale')->where('source_id', $id)->get(['original_amount', 'open_amount'])->toArray()];
        };
        $this->assertEquals($effects($api), $effects($web));
        $this->assertEquals($effects($api), $effects($pos));
        $this->postJson('/api/v1/sales', $data, ['Idempotency-Key' => 'api-sale'])->assertCreated()->assertJsonPath('data.id', $api);
    }

    public function test_legacy_parallel_arrays_convert_units_and_reject_client_totals(): void
    {
        $product = $this->stock();
        $data = ['customer_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03',
            'product_id' => [$product->id], 'qty' => [2], 'sale_unit' => ['PCS'], 'net_unit_price' => [10], 'subtotal' => [20]];
        $this->postJson('/sales', $data, ['Idempotency-Key' => 'legacy-sale'])->assertCreated();
        $this->postJson('/sales', $data + ['grand_total' => 1], ['Idempotency-Key' => 'bad-total'])->assertUnprocessable();
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_missing_mapping_rolls_back_document_number_stock_and_retry_key(): void
    {
        $product = $this->stock();
        DB::table('semantic_account_mappings')->where('company_id', $this->company->id)->where('semantic_role', 'sales')->update(['account_id' => null]);
        try { $this->sale($this->saleData($product)); $this->fail('Missing account accepted.'); } catch (ValidationException $e) {}
        foreach (['sales', 'stock_movements', 'journal_entries', 'idempotency_keys', 'document_number_reservations'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertEquals(20, $product->fresh()->qty);
    }

    public function test_credit_limit_requires_permission_and_records_override(): void
    {
        DB::table('customers')->where('id', 1)->update(['credit_limit' => 10, 'credit_days' => 30]);
        $data = $this->saleData($this->stock());
        $this->postJson('/commercial/sale', $data, ['Idempotency-Key' => 'credit-rejected'])->assertUnprocessable();
        $this->postJson('/commercial/sale', $data + ['credit_override_reason' => 'Approved exception'], ['Idempotency-Key' => 'credit-override'])->assertCreated();
        $this->assertSame(1, DB::table('commercial_audit_events')->where('event', 'credit_override')->count());
        $this->assertSame('2026-11-02', substr(DB::table('account_open_items')->value('due_date'), 0, 10));
    }

    public function test_partial_purchase_landed_cost_and_later_receipt_reconcile_without_duplicate_ap(): void
    {
        $product = $this->stock(0);
        $data = $this->purchaseData($product, ['status' => 2, 'shipping_cost' => 2, 'paid_amount' => 4,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'net_unit_cost' => 5, 'received_qty' => 1]]]);
        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($data, 'partial', 1, $this->context()));
        $this->assertEquals(6, DB::table('stock_movement_lines')->sum('value'));
        $this->assertEquals(8, DB::table('account_open_items')->sum('open_amount'));
        $receipt = ['business_date' => '2026-10-04', 'items' => [['line_id' => $purchase->productPurchases->first()->id, 'qty' => 1]]];
        app(PurchaseReceiptService::class)->receive($purchase, $receipt, 'receipt', $this->context());
        app(PurchaseReceiptService::class)->receive($purchase, $receipt, 'receipt', $this->context());
        $this->assertEquals(12, DB::table('stock_movement_lines')->sum('value'));
        $this->assertEquals(8, DB::table('account_open_items')->sum('open_amount'));
        $this->assertSame(2, DB::table('journal_entries')->count());
        $this->assertEquals(2, $product->fresh()->qty);
        $this->assertEquals(0, \App\Models\Accounting\ChartOfAccount::where('sub_type', 'goods_in_transit')->value('current_balance'));
    }

    public function test_service_purchase_does_not_move_stock_and_cost_update_is_opt_in(): void
    {
        $product = $this->stock(0); $product->update(['type' => 'service']);
        $data = $this->purchaseData($product, ['items' => [['product_id' => $product->id, 'qty' => 2, 'net_unit_cost' => 8]]]);
        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($data, 'service', 1, $this->context()));
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertEquals(16, DB::table('account_open_items')->sum('open_amount'));
        $this->assertEquals(5, $product->fresh()->cost);
        $this->assertNotNull($purchase->posted_at);
    }

    public function test_reversal_and_replacement_preserve_history_and_reverse_stock_and_open_items(): void
    {
        $product = $this->stock();
        $sale = $this->sale($this->saleData($product));
        $replacement = app(CommercialReversalService::class)->replace($sale, $this->saleData($product, ['items' => [['product_id' => $product->id,
            'qty' => 1, 'net_unit_price' => 10]]]), 'replacement', '2026-10-04', 'Correct quantity', $this->context());
        $this->assertEquals(19, $product->fresh()->qty);
        $this->assertSame($sale->id, $replacement->replaces_id);
        $this->assertNotNull($sale->fresh()->reversed_at);
        $this->assertSame(2, DB::table('sales')->count());
        $this->assertSame(3, DB::table('journal_entries')->count());
        $this->assertEquals(10, DB::table('account_open_items')->sum('open_amount'));
        $this->expectException(\LogicException::class);
        $replacement->update(['grand_total' => 1]);
    }

    public function test_drafts_are_isolated_versioned_and_have_no_effects(): void
    {
        $service = app(CommercialDraftService::class);
        $draft = $service->save('sale', $this->snapshot('sale'), null, 0, $this->context(), 1)['draft'];
        $changed = $service->save('sale', $this->snapshot('sale', ['note' => 'Changed']), $draft->id, 1, $this->context(), 1)['draft'];
        $this->assertSame(2, $changed->version);
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        $service->save('sale', $this->snapshot('sale'), $draft->id, 1, $this->context(), 1);
    }

    public function test_gated_routes_foreign_party_and_closed_period_fail_without_writes(): void
    {
        $product = $this->stock();
        config(['commercial.enabled' => false]);
        $this->getJson('/commercial/sale/entry')->assertStatus(503);
        config(['commercial.enabled' => true]);
        DB::table('customers')->where('id', 1)->update(['company_id' => $this->other->id]);
        $this->postJson('/commercial/sale', $this->saleData($product), ['Idempotency-Key' => 'foreign'])->assertUnprocessable();
        DB::table('customers')->where('id', 1)->update(['company_id' => $this->company->id]);
        $this->year->update(['is_closed' => true]);
        $this->postJson('/commercial/sale', $this->saleData($product), ['Idempotency-Key' => 'closed'])->assertUnprocessable();
        $this->assertSame(0, DB::table('sales')->count());
    }
}
