<?php

namespace Tests\Feature;

use App\Models\Inventory\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Platform\CapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Seeded MySQL proof through the full middleware stack. Every legacy stock writer posts authoritative movements
 * (Sale, Purchase, Return and ReturnPurchase joined the Phase 4c writers): one applied movement per request,
 * projections that agree with the ledger, atomic numbers from the company series, and no leaked transaction level.
 * The class name is historical; the shadow map is empty.
 */
class LegacyStockShadowWebTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $main;

    private int $branchStore;

    /** @var array<string, array{id: int, code: string}> */
    private array $items = [];

    private array $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::findOrFail(1);
        $this->main = 1;
        $this->branchStore = DB::table('warehouses')->insertGetId(
            ['name' => 'Cutover second store', 'is_active' => 1, 'company_id' => 1, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()]
            + array_intersect_key((array) DB::table('warehouses')->find(1), array_flip(['phone', 'email', 'address'])),
        );
        $this->items['a'] = $this->item('CUT-A', [$this->main => 20]);
        $this->items['b'] = $this->item('CUT-B', [$this->main => 5, $this->branchStore => 3]);
        $this->items['c'] = $this->item('CUT-C', [$this->branchStore => 3]);
        $this->items['d'] = $this->item('CUT-D', [$this->main => 10], 'BATCH-D');
        $this->artisan('erp:stock-opening', ['--date' => now()->toDateString()])->assertSuccessful();
        $this->baseline = $this->differences();
        // Numbering needs a financial year that covers today.
        $today = now()->toDateString();
        if (!DB::table('fiscal_years')->where('company_id', 1)->where('start_date', '<=', $today)->where('end_date', '>=', $today)->exists()) {
            DB::table('fiscal_years')->insert([
                'company_id' => 1, 'name' => 'Cutover fixture FY', 'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(), 'is_closed' => 0, 'status' => 'open',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        config(['without_stock' => 'no']);
        DB::table('general_settings')->update(['without_stock' => 'no']);
    }

    public function test_web_sale_store_issues_stock_once_and_numbers_the_sale_from_the_company_series(): void
    {
        $a = $this->items['a'];
        $level = DB::transactionLevel();
        [$response, $movement] = $this->stockRequest('sales.store', fn () => $this->post(route('sales.store'), $this->salePayload($a, 2)));

        $response->assertOk();
        $this->assertSame($level, DB::transactionLevel(), 'the request must not leak a transaction level');
        $sale = DB::table('sales')->orderByDesc('id')->first();
        $this->assertMovement($movement, 'issue', 'legacy:sales', $sale->id, [[$a['id'], $this->main, -2]]);
        $this->assertSame(18.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertStringStartsWith('ERP-SAL-', $sale->reference_no);
        $this->assertSame(1, $sale->company_id);
        $this->assertSame(1, DB::table('document_number_reservations')->where('formatted_number', $sale->reference_no)
            ->where('status', 'assigned')->where('source_id', $sale->id)->count());
    }

    public function test_two_sales_get_distinct_consecutive_numbers(): void
    {
        $a = $this->items['a'];
        $first = DB::table('sales')->find($this->ajaxSale($a, 1)->json());
        $second = DB::table('sales')->find($this->ajaxSale($a, 1)->json());

        $this->assertNotSame($first->reference_no, $second->reference_no);
        $series = DB::table('document_series')->where('company_id', 1)->where('document_type', 'sale')->where('is_default', 1)->first();
        $this->assertGreaterThanOrEqual(3, $series->next_number);
    }

    public function test_pos_ajax_sale_store_and_destroy_post_one_issue_and_one_reversal(): void
    {
        $a = $this->items['a'];
        [$response, $issue] = $this->stockRequest('sales.store (ajax)', fn () => $this->ajaxSale($a, 3));
        $saleId = (int) $response->json();
        $this->assertMovement($issue, 'issue', 'legacy:sales', $saleId, [[$a['id'], $this->main, -3]]);

        [$deleted, $reversal] = $this->stockRequest('sales.destroy', fn () => $this->delete(route('sales.destroy', $saleId)));
        $deleted->assertRedirect();
        $this->assertMovement($reversal, 'reversal', 'legacy:sales', $saleId, [[$a['id'], $this->main, 3]]);
        $this->assertSame(20.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertSame('reversed', StockMovement::find($issue->id)->status);
    }

    public function test_sale_update_reverses_the_old_issue_and_issues_the_new_lines(): void
    {
        $a = $this->items['a'];
        $saleId = (int) $this->ajaxSale($a, 3)->json();
        $this->assertSame(17.0, $this->warehouseQty($a['id'], $this->main));
        $last = StockMovement::max('id');

        $payload = $this->salePayload($a, 5) + ['pos' => 0, 'created_at' => now()->format('Y-m-d H:i:s')];
        $this->actingAs($this->admin)->put(route('sales.update', $saleId), $payload)->assertRedirect(url('sales'));

        $this->assertSame(15.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertSame(['reversal', 'issue'], StockMovement::where('id', '>', $last)->orderBy('id')->pluck('movement_type')->all());
        $this->assertSame([], $this->newDifferences());
    }

    public function test_sale_over_available_stock_is_blocked_and_leaves_no_sale_or_number(): void
    {
        $a = $this->items['a'];
        $sales = DB::table('sales')->count();
        $reservations = DB::table('document_number_reservations')->count();
        $before = $this->snapshot();

        $response = $this->actingAs($this->admin)->post(route('sales.store'), $this->salePayload($a, 99) + ['pos' => 1],
            ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertRedirect();
        $this->assertStringContainsString('Insufficient stock', (string) session('not_permitted'));

        $this->assertSame($sales, DB::table('sales')->count());
        $this->assertSame($reservations, DB::table('document_number_reservations')->count());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_pre_cutover_sale_is_deleted_by_restoring_its_persisted_lines(): void
    {
        $a = $this->items['a'];
        $saleId = (int) $this->ajaxSale($a, 4)->json();
        // Make it look like a sale from before the cutover: its stock history is only a shadow record.
        StockMovement::where('source_type', 'legacy:sales')->where('source_id', $saleId)
            ->update(['projection_mode' => 'shadow', 'source_type' => 'legacy:sales.store']);
        $this->assertSame(16.0, $this->warehouseQty($a['id'], $this->main));

        $this->actingAs($this->admin)->delete(route('sales.destroy', $saleId))->assertRedirect();

        $this->assertSame(20.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertTrue(StockMovement::where('source_type', 'legacy:sales:historical-reversal')->where('source_id', $saleId)->exists());
    }

    public function test_batch_sale_destroy_restores_the_batch_projection(): void
    {
        $d = $this->items['d'];
        $saleId = (int) $this->ajaxSale($d, 2, $d['batch_id'])->json();
        $this->assertSame(8.0, (float) DB::table('product_batches')->where('id', $d['batch_id'])->value('qty'));

        $this->actingAs($this->admin)->delete(route('sales.destroy', $saleId))->assertRedirect();

        $this->assertSame(10.0, $this->warehouseQty($d['id'], $this->main));
        $this->assertSame(10.0, (float) DB::table('product_batches')->where('id', $d['batch_id'])->value('qty'));
        $this->assertSame([], $this->newDifferences());
    }

    public function test_sale_from_a_warehouse_without_a_stock_row_is_blocked_by_policy(): void
    {
        $c = $this->items['c'];
        $before = $this->snapshot();

        $response = $this->actingAs($this->admin)->post(route('sales.store'), $this->salePayload($c, 2) + ['pos' => 1],
            ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertRedirect();
        $this->assertStringContainsString('Insufficient stock', (string) session('not_permitted'));

        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->newDifferences());
    }

    public function test_purchase_store_posts_one_receipt_and_numbers_it(): void
    {
        $b = $this->items['b'];
        [$response, $movement] = $this->stockRequest('purchases.store', fn () => $this->post(route('purchases.store'), $this->purchasePayload([[$b, 4, 'piece']])));

        $response->assertRedirect(url('purchases'));
        $purchase = DB::table('purchases')->orderByDesc('id')->first();
        $this->assertMovement($movement, 'receipt', 'legacy:purchases', $purchase->id, [[$b['id'], $this->main, 4]]);
        $this->assertSame(9.0, $this->warehouseQty($b['id'], $this->main));
        $this->assertStringStartsWith('ERP-PUR-', $purchase->reference_no);
        $this->assertSame(1, $purchase->company_id);
    }

    public function test_purchase_that_rolls_back_posts_nothing_and_consumes_no_number(): void
    {
        $a = $this->items['a'];
        $b = $this->items['b'];
        $before = $this->snapshot();
        $last = StockMovement::max('id');
        $reservations = DB::table('document_number_reservations')->count();
        $this->actingAs($this->admin)
            ->post(route('purchases.store'), $this->purchasePayload([[$a, 2, 'piece'], [$b, 1, 'no such unit']]))
            ->assertRedirect(route('purchases.index', ['new' => 1]));

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($last, StockMovement::max('id'));
        $this->assertSame($reservations, DB::table('document_number_reservations')->count());
        $this->assertSame($this->baseline, $this->differences());
    }

    public function test_purchase_destroy_takes_back_what_it_received(): void
    {
        $b = $this->items['b'];
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->purchasePayload([[$b, 4, 'piece']]))->assertRedirect(url('purchases'));
        $purchase = DB::table('purchases')->orderByDesc('id')->first();
        $this->assertSame(9.0, $this->warehouseQty($b['id'], $this->main));

        [$response, $reversal] = $this->stockRequest('purchases.destroy', fn () => $this->delete(route('purchases.destroy', $purchase->id)));

        $response->assertRedirect();
        $this->assertMovement($reversal, 'reversal', 'legacy:purchases', $purchase->id, [[$b['id'], $this->main, -4]]);
        $this->assertSame(5.0, $this->warehouseQty($b['id'], $this->main));
    }

    public function test_sale_return_store_posts_one_receipt_and_destroy_reverses_it(): void
    {
        $a = $this->items['a'];
        $saleId = (int) $this->ajaxSale($a, 3)->json();
        $line = DB::table('product_sales')->where('sale_id', $saleId)->first();
        [$response, $movement] = $this->stockRequest('return-sale.store', fn () => $this->post(route('return-sale.store'), [
            'sale_id' => $saleId, 'customer_id' => 1, 'warehouse_id' => $this->main, 'biller_id' => 1, 'account_id' => 1,
            'currency_id' => 1, 'exchange_rate' => 1, 'item' => 1, 'total_qty' => 1, 'total_sale_discount' => 0, 'change_sale_status' => 0, 'total_tax' => 0,
            'total_price' => 10, 'order_tax_rate' => 0, 'order_tax' => 0, 'grand_total' => 10, 'return_note' => 'cutover proof',
            'is_return' => [$line->id], 'product_sale_id' => [$line->id], 'product_id' => [$a['id']], 'product_code' => [$a['code']],
            'qty' => [1], 'sale_unit' => ['piece'], 'net_unit_price' => [10], 'discount' => [0], 'tax_rate' => [0], 'tax' => [0],
            'subtotal' => [10], 'imei_number' => [''], 'product_batch_id' => [''], 'product_price' => [10],
        ]));

        $response->assertRedirect();
        $return = DB::table('returns')->orderByDesc('id')->first();
        $this->assertMovement($movement, 'receipt', 'legacy:returns', $return->id, [[$a['id'], $this->main, 1]]);
        $this->assertSame(18.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertStringStartsWith('ERP-SCN-', $return->reference_no);

        [$deleted, $reversal] = $this->stockRequest('return-sale.destroy', fn () => $this->delete(route('return-sale.destroy', $return->id)));
        $deleted->assertRedirect();
        $this->assertMovement($reversal, 'reversal', 'legacy:returns', $return->id, [[$a['id'], $this->main, -1]]);
        $this->assertSame(17.0, $this->warehouseQty($a['id'], $this->main));
    }

    public function test_sale_return_restores_stock_at_the_original_sale_cost(): void
    {
        $a = $this->items['a'];
        $saleId = (int) $this->ajaxSale($a, 3)->json();
        $issueCost = (float) StockMovement::where('source_type', 'legacy:sales')->where('source_id', $saleId)->firstOrFail()->lines()->value('unit_cost');
        // A dearer receipt moves the weighted average, which a plain return would otherwise pick up.
        $payload = $this->purchasePayload([[$a, 10, 'piece']]);
        $payload['net_unit_cost'] = [20];
        $payload['unit_cost'] = [20];
        $this->actingAs($this->admin)->post(route('purchases.store'), $payload)->assertRedirect(url('purchases'));
        $line = DB::table('product_sales')->where('sale_id', $saleId)->first();

        $this->actingAs($this->admin)->post(route('return-sale.store'), [
            'sale_id' => $saleId, 'customer_id' => 1, 'warehouse_id' => $this->main, 'biller_id' => 1, 'account_id' => 1,
            'currency_id' => 1, 'exchange_rate' => 1, 'item' => 1, 'total_qty' => 1, 'total_sale_discount' => 0, 'change_sale_status' => 0, 'total_tax' => 0,
            'total_price' => 10, 'order_tax_rate' => 0, 'order_tax' => 0, 'grand_total' => 10, 'return_note' => 'cost proof',
            'is_return' => [$line->id], 'product_sale_id' => [$line->id], 'product_id' => [$a['id']], 'product_code' => [$a['code']],
            'qty' => [1], 'sale_unit' => ['piece'], 'net_unit_price' => [10], 'discount' => [0], 'tax_rate' => [0], 'tax' => [0],
            'subtotal' => [10], 'imei_number' => [''], 'product_batch_id' => [''], 'product_price' => [10],
        ])->assertRedirect();

        $return = DB::table('returns')->orderByDesc('id')->first();
        $receipt = StockMovement::where('source_type', 'legacy:returns')->where('source_id', $return->id)->firstOrFail();
        $this->assertEqualsWithDelta($issueCost, (float) $receipt->lines()->value('unit_cost'), 0.0001);
    }

    public function test_purchase_return_store_posts_one_issue_and_destroy_puts_it_back(): void
    {
        $b = $this->items['b'];
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->purchasePayload([[$b, 4, 'piece']]))->assertRedirect(url('purchases'));
        $purchase = DB::table('purchases')->orderByDesc('id')->first();
        $line = DB::table('product_purchases')->where('purchase_id', $purchase->id)->first();
        [$response, $movement] = $this->stockRequest('return-purchase.store', fn () => $this->post(route('return-purchase.store'), [
            'purchase_id' => $purchase->id, 'supplier_id' => 1, 'warehouse_id' => $this->main, 'account_id' => 1,
            'currency_id' => 1, 'exchange_rate' => 1, 'item' => 1, 'total_qty' => 2, 'total_discount' => 0, 'total_tax' => 0,
            'total_cost' => 8, 'order_tax_rate' => 0, 'order_tax' => 0, 'grand_total' => 8, 'return_note' => 'cutover proof',
            'is_return' => [$line->id], 'product_purchase_id' => [$line->id], 'product_id' => [$b['id']], 'product_code' => [$b['code']],
            'qty' => [2], 'purchase_unit' => ['piece'], 'net_unit_cost' => [4], 'discount' => [0], 'tax_rate' => [0], 'tax' => [0],
            'subtotal' => [8], 'imei_number' => [''], 'product_batch_id' => [''],
        ]));

        $response->assertRedirect();
        $return = DB::table('return_purchases')->orderByDesc('id')->first();
        $this->assertMovement($movement, 'issue', 'legacy:return_purchases', $return->id, [[$b['id'], $this->main, -2]]);
        $this->assertSame(7.0, $this->warehouseQty($b['id'], $this->main));
        $this->assertStringStartsWith('ERP-PDN-', $return->reference_no);

        [$deleted, $reversal] = $this->stockRequest('return-purchase.destroy', fn () => $this->delete(route('return-purchase.destroy', $return->id)));
        $deleted->assertRedirect();
        $this->assertMovement($reversal, 'reversal', 'legacy:return_purchases', $return->id, [[$b['id'], $this->main, 2]]);
        $this->assertSame(9.0, $this->warehouseQty($b['id'], $this->main));
    }

    public function test_purchase_update_replaces_the_receipt_and_selection_delete_takes_it_back(): void
    {
        $b = $this->items['b'];
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->purchasePayload([[$b, 4, 'piece']]))->assertRedirect(url('purchases'));
        $purchase = DB::table('purchases')->orderByDesc('id')->first();
        $last = StockMovement::max('id');

        $payload = $this->purchasePayload([[$b, 6, 'piece']]) + ['created_at' => now()->format('d-m-Y'), 'product_variant_id' => []];
        $this->actingAs($this->admin)->put(route('purchases.update', $purchase->id), $payload)->assertRedirect(url('purchases'));

        $this->assertSame(11.0, $this->warehouseQty($b['id'], $this->main));
        $this->assertSame(['reversal', 'receipt'], StockMovement::where('id', '>', $last)->orderBy('id')->pluck('movement_type')->all());
        $this->assertSame([], $this->newDifferences());

        $this->actingAs($this->admin)->post(url('purchases/deletebyselection'), ['purchaseIdArray' => [$purchase->id]])->assertOk();
        $this->assertSame(5.0, $this->warehouseQty($b['id'], $this->main));
        $this->assertSame([], $this->newDifferences());
    }

    public function test_sale_selection_delete_restores_each_sale(): void
    {
        $a = $this->items['a'];
        $one = (int) $this->ajaxSale($a, 2)->json();
        $two = (int) $this->ajaxSale($a, 3)->json();
        $this->assertSame(15.0, $this->warehouseQty($a['id'], $this->main));

        $this->actingAs($this->admin)->post(url('sales/deletebyselection'), ['saleIdArray' => [$one, $two]])->assertOk();

        $this->assertSame(20.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertSame([], $this->newDifferences());
    }

    public function test_csv_imports_post_stock_and_use_company_numbers(): void
    {
        $a = $this->items['a'];
        $unit = DB::table('units')->where('unit_name', 'piece')->value('unit_code');
        $csv = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('lines.csv',
            "code,qty,unit,price,discount,tax\n{$a['code']},2,{$unit},10,0,No Tax\n");

        $sale = $this->actingAs($this->admin)->post(route('sale.import'), [
            'file' => $csv(), 'customer_id' => 1, 'warehouse_id' => $this->main, 'biller_id' => 1, 'sale_status' => 1, 'payment_status' => 1,
            'order_tax_rate' => 0, 'order_discount' => 0, 'shipping_cost' => 0, 'currency_id' => 1, 'exchange_rate' => 1,
            'total_qty' => 0, 'total_discount' => 0, 'total_tax' => 0, 'total_price' => 0, 'order_tax' => 0, 'grand_total' => 0, 'item' => 0, 'paid_amount' => 0,
        ]);
        $sale->assertRedirect(url('sales'));
        $this->assertSame(18.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertStringStartsWith('ERP-SAL-', DB::table('sales')->orderByDesc('id')->value('reference_no'));

        $purchase = $this->actingAs($this->admin)->post(route('purchase.import'), [
            'file' => $csv(), 'supplier_id' => 1, 'warehouse_id' => $this->main, 'status' => 1, 'payment_status' => 1,
            'order_tax_rate' => 0, 'order_discount' => 0, 'shipping_cost' => 0, 'currency_id' => 1, 'exchange_rate' => 1,
            'item' => 0, 'total_qty' => 0, 'total_discount' => 0, 'total_tax' => 0, 'total_cost' => 0, 'order_tax' => 0, 'grand_total' => 0, 'paid_amount' => 0,
        ]);
        $this->assertSame(url('purchases'), $purchase->headers->get('Location'), $this->describe($purchase));
        $this->assertSame(20.0, $this->warehouseQty($a['id'], $this->main));
        $this->assertStringStartsWith('ERP-PUR-', DB::table('purchases')->orderByDesc('id')->value('reference_no'));
        $this->assertSame([], $this->newDifferences());
    }

    public function test_income_and_money_transfer_use_company_series_and_are_owned(): void
    {
        $account = (int) DB::table('accounts')->value('id');
        $category = DB::table('income_categories')->value('id') ?? DB::table('income_categories')->insertGetId(['name' => 'Cutover income', 'code' => 'CI', 'is_active' => 1]);
        foreach ([1, 2] as $i) {
            $this->actingAs($this->admin)->post(route('incomes.store'), [
                'income_category_id' => $category, 'warehouse_id' => $this->main, 'account_id' => $account, 'amount' => 5 * $i, 'note' => 'series proof',
            ])->assertRedirect();
        }
        $incomes = DB::table('incomes')->orderByDesc('id')->limit(2)->get();
        $this->assertNotSame($incomes[0]->reference_no, $incomes[1]->reference_no);
        $this->assertStringStartsWith('ERP-INC-', $incomes[0]->reference_no);
        $this->assertSame(1, (int) $incomes[0]->company_id);

        $this->actingAs($this->admin)->post(route('money-transfers.store'), ['from_account_id' => $account, 'to_account_id' => $account, 'amount' => 1])->assertRedirect();
        $transfer = DB::table('money_transfers')->orderByDesc('id')->first();
        $this->assertStringStartsWith('ERP-MTR-', $transfer->reference_no);
        $this->assertSame(1, (int) $transfer->company_id);
    }

    public function test_completed_transfer_store_records_one_applied_transfer(): void
    {
        $b = $this->items['b'];
        [$response, $movement] = $this->stockRequest('transfers.store', fn () => $this->post(route('transfers.store'), $this->transferPayload($b, 2, 1)));

        $response->assertRedirect();
        $transfer = DB::table('transfers')->orderByDesc('id')->first();
        $this->assertMovement($movement, 'transfer', 'legacy:transfers', $transfer->id,
            [[$b['id'], $this->main, -2], [$b['id'], $this->branchStore, 2]]);
        $this->assertSame(3.0, $this->warehouseQty($b['id'], $this->main));
        $this->assertSame(5.0, $this->warehouseQty($b['id'], $this->branchStore));
    }

    public function test_pending_transfer_completed_by_change_status_records_its_stock_once(): void
    {
        $b = $this->items['b'];
        $pending = $this->actingAs($this->admin)->post(route('transfers.store'), $this->transferPayload($b, 2, 2));
        $pending->assertRedirect();
        $transfer = DB::table('transfers')->orderByDesc('id')->first();
        $afterStore = $this->snapshot();
        $last = StockMovement::max('id');

        [$response, $movement] = $this->stockRequest('transfers.changeStatus',
            fn () => $this->put(route('transfers.changeStatus', $transfer->id), ['status' => 1]));

        $response->assertRedirect();
        $this->assertGreaterThan($last, $movement->id);
        $this->assertSame('legacy:transfers', $movement->source_type);
        $this->assertSame('applied', $movement->projection_mode);
        $this->assertSame($transfer->id, (int) $movement->source_id);
        $this->assertNotEquals($afterStore, $this->snapshot(), 'changeStatus must move stock');
        $this->assertSame(5.0, $this->warehouseQty($b['id'], $this->main) + $this->warehouseQty($b['id'], $this->branchStore) - 3.0);
    }

    public function test_adjustment_store_records_one_signed_applied_adjustment(): void
    {
        $a = $this->items['a'];
        $b = $this->items['b'];
        [$response, $movement] = $this->stockRequest('qty_adjustment.store', fn () => $this->post(route('qty_adjustment.store'), [
            'warehouse_id' => $this->main, 'item' => 2, 'total_qty' => 3, 'note' => 'cutover proof',
            'product_id' => [$a['id'], $b['id']], 'product_code' => [$a['code'], $b['code']], 'qty' => [2, 1],
            'action' => ['-', '+'], 'unit_cost' => [4, 4],
        ]));

        $response->assertRedirect();
        $adjustment = DB::table('adjustments')->orderByDesc('id')->first();
        $this->assertMovement($movement, 'adjustment', 'legacy:adjustments', $adjustment->id,
            [[$a['id'], $this->main, -2], [$b['id'], $this->main, 1]]);
    }

    public function test_cafe_raw_material_consumption_posts_applied_issue_without_new_reconciliation_differences(): void
    {
        $a = $this->items['a'];
        $this->enableCafeForFixtureCompany();
        $last = StockMovement::max('id');
        $response = $this->actingAs($this->admin)->post(route('cafe.raw-material.store'), [
            'warehouse_id' => $this->main, 'product_id' => $a['id'], 'opening_stock' => 20, 'consumed_today' => 1.5,
        ]);
        $response->assertRedirect();
        $log = DB::table('cafe_raw_materials')->where('product_id', $a['id'])->sole();
        $movement = StockMovement::where('id', '>', $last)->sole();
        $this->assertMovement($movement, 'issue', 'legacy:cafe_raw_materials', $log->id,
            [[$a['id'], $this->main, -1.5]]);
        $this->assertSame(18.5, $this->warehouseQty($a['id'], $this->main));
        $this->assertSame([], $this->newDifferences());
    }

    /**
     * Fixture-only activation. Optional capabilities are hard-gated off until Phase 1 acceptance
     * (CapabilityCatalog::OPTIONAL_ACTIVATION_READY), so this route is unreachable in production today; the test
     * opens that gate through CapabilityService's protected seam and writes the capability rows directly.
     */
    private function enableCafeForFixtureCompany(): void
    {
        $this->app->instance(CapabilityService::class, new class extends CapabilityService {
            protected function optionalActivationReady(): bool
            {
                return true;
            }
        });
        $capabilities = DB::table('capabilities')->whereIn('key', ['core.sales', 'core.inventory', 'operations.cafe_bakery'])->pluck('id', 'key');
        $this->assertCount(3, $capabilities, 'capability catalog must be seeded');
        foreach ($capabilities as $id) {
            DB::table('company_capabilities')->updateOrInsert(['company_id' => 1, 'capability_id' => $id],
                ['enabled' => 1, 'config_json' => '[]', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        cache()->flush();
    }

    private function ajaxSale(array $item, float $qty, ?int $batchId = null): TestResponse
    {
        $payload = $this->salePayload($item, $qty);
        $payload['product_batch_id'] = [$batchId ?? ''];

        return $this->actingAs($this->admin)->post(route('sales.store'), $payload + ['pos' => 1],
            ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    }

    /** @param list<array{0: array, 1: float, 2: string}> $lines [item, received qty, purchase unit name] */
    private function purchasePayload(array $lines): array
    {
        $column = fn (callable $value) => array_map($value, $lines);
        $total = array_sum($column(fn ($l) => $l[1] * 4));

        return [
            'warehouse_id' => $this->main, 'supplier_id' => 1, 'status' => 1, 'currency_id' => 1, 'exchange_rate' => 1,
            'item' => count($lines), 'total_qty' => array_sum($column(fn ($l) => $l[1])), 'total_discount' => 0, 'total_tax' => 0,
            'total_cost' => $total, 'order_tax_rate' => 0, 'order_tax' => 0, 'order_discount' => 0, 'shipping_cost' => 0,
            'grand_total' => $total, 'paid_amount' => 0, 'payment_status' => 1, 'note' => 'cutover proof',
            'product_id' => $column(fn ($l) => $l[0]['id']), 'product_code' => $column(fn ($l) => $l[0]['code']),
            'qty' => $column(fn ($l) => $l[1]), 'recieved' => $column(fn ($l) => $l[1]), 'purchase_unit' => $column(fn ($l) => $l[2]),
            'unit_cost' => $column(fn () => 4), 'net_unit_cost' => $column(fn () => 4), 'net_unit_margin' => $column(fn () => 0),
            'net_unit_margin_type' => $column(fn () => 'percentage'), 'net_unit_price' => $column(fn () => 10),
            'discount' => $column(fn () => 0), 'tax_rate' => $column(fn () => 0), 'tax' => $column(fn () => 0),
            'subtotal' => $column(fn ($l) => $l[1] * 4), 'imei_number' => $column(fn () => ''),
            'batch_no' => $column(fn () => null), 'expired_date' => $column(fn () => null),
        ];
    }

    private function transferPayload(array $item, float $qty, int $status): array
    {
        return [
            'from_warehouse_id' => $this->main, 'to_warehouse_id' => $this->branchStore, 'status' => $status,
            'item' => 1, 'total_qty' => $qty, 'total_tax' => 0, 'total_cost' => $qty * 4, 'shipping_cost' => 0,
            'grand_total' => $qty * 4, 'note' => 'cutover proof',
            'product_id' => [$item['id']], 'product_code' => [$item['code']], 'qty' => [$qty], 'purchase_unit' => ['piece'],
            'net_unit_cost' => [4], 'tax_rate' => [0], 'tax' => [0], 'subtotal' => [$qty * 4], 'imei_number' => [''],
            'product_batch_id' => [''],
        ];
    }

    private function salePayload(array $item, float $qty, float $price = 10): array
    {
        $total = $qty * $price;

        return [
            'customer_id' => 1, 'warehouse_id' => $this->main, 'biller_id' => 1, 'currency_id' => 1, 'exchange_rate' => 1,
            'item' => 1, 'total_qty' => $qty, 'total_discount' => 0, 'total_tax' => 0, 'total_price' => $total,
            'order_tax_rate' => 0, 'order_tax' => 0, 'order_discount' => 0, 'order_discount_type' => 'Flat',
            'order_discount_value' => 0, 'shipping_cost' => 0, 'grand_total' => $total,
            'sale_status' => 1, 'payment_status' => 1, 'paid_amount' => 0, 'paying_amount' => 0, 'paid_by_id' => ['1'],
            'coupon_active' => 0, 'draft' => 0, 'sale_note' => 'cutover proof', 'staff_note' => null,
            'product_id' => [$item['id']], 'product_code' => [$item['code']], 'qty' => [$qty],
            'sale_unit' => ['piece'], 'net_unit_price' => [$price], 'discount' => [0], 'tax_rate' => [0],
            'tax' => [0], 'subtotal' => [$total], 'imei_number' => [''], 'product_batch_id' => [''],
        ];
    }

    /**
     * Runs the request and asserts exactly one new stock movement, projections that agree with the ledger,
     * and no reconciliation difference beyond the seed baseline. Returns the response and the movement.
     *
     * @return array{0: TestResponse, 1: StockMovement}
     */
    private function stockRequest(string $label, callable $request): array
    {
        $before = StockMovement::max('id') ?? 0;
        $this->actingAs($this->admin);
        $response = $request();
        $context = $label.' '.$this->describe($response);
        $movements = StockMovement::where('id', '>', $before)->get();
        $this->assertCount(1, $movements, "$context: must record exactly one movement");
        $this->assertSame('applied', $movements->first()->projection_mode, "$context: stock must be applied, not shadowed");
        $this->assertSame([], $this->newDifferences(), "$context introduced reconciliation differences");

        return [$response, $movements->first()->load('lines')];
    }

    private function assertMovement(StockMovement $movement, string $type, string $source, int $sourceId, array $lines): void
    {
        $this->assertSame('applied', $movement->projection_mode);
        $this->assertSame($type, $movement->movement_type);
        $this->assertSame($source, $movement->source_type);
        $this->assertSame($sourceId, (int) $movement->source_id);
        $actual = $movement->lines->map(fn ($l) => [(int) $l->product_id, (int) $l->warehouse_id, (float) $l->qty_base])
            ->sortBy(fn ($l) => $l[0] * 1000 + $l[1])->values()->all();
        $expected = collect($lines)->map(fn ($l) => [$l[0], $l[1], (float) $l[2]])->sortBy(fn ($l) => $l[0] * 1000 + $l[1])->values()->all();
        $this->assertSame($expected, $actual);
    }

    /** Response status plus flashed errors, for failure messages. */
    private function describe(TestResponse $response): string
    {
        $flash = array_filter([
            'not_permitted' => session('not_permitted'), 'error' => session('error'),
            'errors' => session('errors')?->getBag('default')->all(),
        ]);

        return "[HTTP {$response->getStatusCode()} ".json_encode($flash).' '.$response->headers->get('Location').']';
    }

    /** A consistent legacy item; with $batchNo every warehouse row belongs to one unexpired batch. */
    private function item(string $code, array $stock, ?string $batchNo = null): array
    {
        $row = (array) DB::table('products')->find(5);
        unset($row['id']);
        $id = DB::table('products')->insertGetId(array_merge($row, [
            'name' => 'Cutover proof '.$code, 'code' => $code, 'qty' => array_sum($stock), 'cost' => 4,
            'type' => 'standard', 'is_variant' => null, 'is_batch' => $batchNo ? 1 : null, 'is_imei' => null, 'is_active' => 1,
        ]));
        $batchId = $batchNo === null ? null : DB::table('product_batches')->insertGetId([
            'product_id' => $id, 'batch_no' => $batchNo, 'expired_date' => now()->addYear()->toDateString(),
            'qty' => array_sum($stock), 'company_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($stock as $warehouse => $qty) {
            DB::table('product_warehouse')->insert([
                'product_id' => $id, 'warehouse_id' => $warehouse, 'qty' => $qty, 'company_id' => 1,
                'product_batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['id' => $id, 'code' => $code, 'batch_id' => $batchId];
    }

    /** Reconciliation differences that are not in the seed baseline. */
    private function newDifferences(): array
    {
        return array_values(array_filter($this->differences(), fn ($diff) => !in_array($diff, $this->baseline, true)));
    }

    private function warehouseQty(int $productId, int $warehouseId): float
    {
        return (float) DB::table('product_warehouse')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->sum('qty');
    }

    private function snapshot(): array
    {
        $ids = array_column($this->items, 'id');

        return [
            'products' => DB::table('products')->whereIn('id', $ids)->orderBy('id')->pluck('qty', 'id')->map(fn ($q) => round((float) $q, 4))->all(),
            'warehouses' => DB::table('product_warehouse')->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('warehouse_id')
                ->get(['product_id', 'warehouse_id', 'variant_id', 'product_batch_id', 'qty', 'imei_number'])
                ->map(fn ($r) => [(int) $r->product_id, (int) $r->warehouse_id, $r->variant_id, $r->product_batch_id, round((float) $r->qty, 4), $r->imei_number])->all(),
        ];
    }

    private function differences(): array
    {
        return app(InventoryReconciliationService::class)->differences();
    }
}
