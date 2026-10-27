<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\{DeliveryChallan, GoodsReceivedNote, Purchase, Sale};
use App\Services\Commercial\{CommercialApplicationService, CommercialReversalService, OrderLifecycleService, ReturnService};
use App\Services\Documents\DocumentRenderingService;
use App\Services\ERP\PaymentService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\JobWork\JobWorkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\{CreatesOperationsFixtures, ReturnsDocumentTestCase};

/**
 * One textile business, start to finish, through every document type: PO → bill, supplier GRN → bill, supplier payment,
 * job work DC → GRN (accepted / rejected / loss, grey → dyed) → service bill (job worker's own name) → corrected bill,
 * SO → invoice, customer DC → invoice, customer payment, credit note, debit note, an edited (replaced) invoice.
 * After every stage the books must agree with the documents: journals balance, the stock account equals the stock
 * ledger, payables / receivables equal bill-wise open items, and GST ledgers equal the tax on the documents.
 */
class TextileEndToEndLedgerTest extends ReturnsDocumentTestCase
{
    use CreatesOperationsFixtures;

    private int $fabricTax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOperationsFixtures();
        (require database_path('migrations/2026_10_16_000005_job_work_textile_process_route.php'))->up();
        (require database_path('migrations/2026_10_24_000003_textile_trade_compliance_features.php'))->up();
        if (!Schema::hasColumn('warehouses', 'is_active')) Schema::table('warehouses', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        (require database_path('migrations/2026_10_13_000004_create_optech_dc_and_grn_tables.php'))->up();
        (require database_path('migrations/2026_10_17_000007_post_material_documents_physically.php'))->up();
        foreach ([['GRNI', 'liability', 'goods_received_not_invoiced'], ['GDNI', 'asset', 'goods_delivered_not_invoiced'],
            ['VAR', 'expense', 'inventory_variance']] as [$code, $type, $subType]) {
            if (!DB::table('chart_of_accounts')->where('company_id', $this->company->id)->where('code', $code)->exists()) {
                \App\Models\Accounting\ChartOfAccount::forceCreate(['company_id' => $this->company->id, 'code' => $code, 'name' => $subType, 'type' => $type, 'sub_type' => $subType]);
            }
        }
        app(\App\Services\Accounting\SemanticAccountResolver::class)->seedCompany($this->company->id);
        $this->fabricTax = DB::table('tax_categories')->insertGetId(['company_id' => $this->company->id, 'name' => 'Textile fabric 5%', 'supply_type' => 'taxable', 'input_credit_allowed' => true]);
        DB::table('tax_rates')->insert(['company_id' => $this->company->id, 'tax_category_id' => $this->fabricTax, 'rate' => 5, 'effective_from' => '2026-01-01']);
        foreach ([['5208', 'hsn'], ['998821', 'sac']] as [$code, $kind]) {
            DB::table('hsn_sac_codes')->insert(['company_id' => $this->company->id, 'code' => $code, 'kind' => $kind, 'description' => $code]);
        }
    }

    private function fabric(string $name): Product
    {
        $product = $this->material(0, 0, ['tax_category_id' => $this->fabricTax, 'hsn_code' => '5208']);
        $product->forceFill(['name' => $name])->save();
        return $product;
    }

    private function balance(string $subType): float
    {
        return round((float) DB::table('journal_items as i')->join('journal_entries as e', 'e.id', '=', 'i.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'i.chart_of_account_id')->where('e.company_id', $this->company->id)
            ->where('a.sub_type', $subType)->sum(DB::raw('i.debit - i.credit')), 2);
    }

    /** Bill-wise open amount of one side (positive = owed to us for customers, owed by us for suppliers). */
    private function openItems(string $subType): float
    {
        return round((float) DB::table('account_open_items as o')->join('chart_of_accounts as a', 'a.id', '=', 'o.account_id')
            ->where('o.company_id', $this->company->id)->where('a.sub_type', $subType)->sum('o.open_amount'), 2);
    }

    /** Tax on the documents that are in force (posted, not reversed), notes reduce it. */
    private function documentTax(string $kind): float
    {
        $bills = $kind === 'sale'
            ? DB::table('sales')->where('company_id', $this->company->id)->whereNotNull('posted_at')->whereNull('reversed_at')->sum(DB::raw('total_tax + order_tax'))
            : DB::table('purchases')->where('company_id', $this->company->id)->whereNotNull('posted_at')->whereNull('reversed_at')->sum(DB::raw('total_tax + order_tax'));
        $notes = DB::table($kind === 'sale' ? 'returns' : 'return_purchases')->where('company_id', $this->company->id)->whereNotNull('posted_at')
            ->sum(Schema::hasColumn($kind === 'sale' ? 'returns' : 'return_purchases', 'total_tax') ? 'total_tax' : DB::raw('0'));
        return round((float) $bills - (float) $notes, 2);
    }

    private function assertBooksAgree(string $stage): void
    {
        $debit = round((float) DB::table('journal_items')->sum('debit'), 2); $credit = round((float) DB::table('journal_items')->sum('credit'), 2);
        $this->assertEquals($debit, $credit, "$stage: every journal balances");
        $unbalanced = DB::table('journal_items')->groupBy('journal_entry_id')->havingRaw('ROUND(SUM(debit) - SUM(credit), 4) <> 0')->pluck('journal_entry_id')->all();
        $this->assertSame([], $unbalanced, "$stage: no single journal is unbalanced");
        $this->assertSame([], app(InventoryReconciliationService::class)->differences(), "$stage: stock balances match the stock ledger");
        $stock = round((float) DB::table('stock_movement_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('p.company_id', $this->company->id)->sum('l.value'), 2);
        $this->assertEquals($stock, $this->balance('inventory'), "$stage: stock account equals the value of goods in the stock ledger");
        $this->assertEquals($this->balance('accounts_receivable'), $this->openItems('accounts_receivable'), "$stage: receivables equal open customer bills");
        $this->assertEquals(-$this->balance('accounts_payable'), $this->openItems('accounts_payable'), "$stage: payables (a credit balance) equal open supplier bills");
        $output = -($this->balance('output_tax_cgst') + $this->balance('output_tax_sgst') + $this->balance('output_tax_igst'));
        $input = $this->balance('input_tax_cgst') + $this->balance('input_tax_sgst') + $this->balance('input_tax_igst');
        $this->assertEquals($this->documentTax('sale'), $output, "$stage: output GST equals tax on sales less credit notes");
        $this->assertEquals($this->documentTax('purchase'), $input, "$stage: input GST equals tax on purchases less debit notes");
    }

    public function test_a_textile_business_from_purchase_order_to_customer_payment_keeps_every_ledger_in_step(): void
    {
        $grey = $this->fabric('3200 cc Grey'); $dyed = $this->fabric('3200 cc Dyed');
        $charge = $this->material(0, 0, ['tax_category_id' => $this->fabricTax, 'hsn_code' => '998821', 'type' => 'service']);
        DB::table('product_warehouse')->where('product_id', $charge->id)->update(['qty' => 0]);
        $commercial = app(CommercialApplicationService::class);
        $line = fn (Product $p, float $qty, float $rate, string $field = 'net_unit_cost') => ['product_id' => $p->id, 'qty' => $qty, $field => $rate];

        // 1. Purchase order, then the bill against it (PO date first: a bill may not precede its order).
        $po = $commercial->create('purchase', ['supplier_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03', 'status' => 4,
            'items' => [$line($grey, 300, 40)]], 'e2e-po', 1, $this->context());
        $this->assertNull($po->posted_at, 'an order posts nothing');
        $this->assertBooksAgree('purchase order');
        $commercial->create('purchase', ['supplier_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03', 'purchase_order_id' => $po->id,
            'items' => [$line($grey, 300, 40)]], 'e2e-po-bill', 1, $this->context());
        $this->assertEquals(12000, $this->balance('inventory'));
        $this->assertEquals(-12600, $this->balance('accounts_payable'), '12,000 + 5% GST owed to the mill');
        $this->assertBooksAgree('bill against PO');

        // 2. Supplier GRN moves the goods first (accrued), the bill settles it later.
        $grnId = $this->postJson('/goods-received-notes', ['grn_date' => '2026-10-03', 'supplier_id' => 1, 'warehouse_id' => 1,
            'unit_id' => [1], 'product_id' => [$grey->id], 'qty' => [100], 'cost' => [40]])->assertCreated()->json('grn.id');
        $grn = GoodsReceivedNote::findOrFail($grnId);
        $this->assertEquals(400, (float) $grey->fresh()->qty);
        $this->assertBooksAgree('supplier GRN');
        $commercial->create('purchase', ['supplier_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03', 'goods_received_note_ids' => [$grn->id],
            'items' => [$line($grey, 100, 40) + ['material_sources' => [['document_id' => $grn->id, 'item_id' => $grn->items()->first()->id, 'qty_base' => 100]]]]],
            'e2e-grn-bill', 1, $this->context());
        $this->assertEquals(0, $this->balance('goods_received_not_invoiced'), 'the bill clears the GRN accrual');
        $this->assertEquals(400, (float) $grey->fresh()->qty, 'the bill does not receive the same goods twice');
        $this->assertBooksAgree('bill against GRN');

        // 3. Part payment to the mill.
        $millBill = Purchase::where('supplier_id', 1)->whereNotNull('posted_at')->orderBy('id')->firstOrFail();
        app(PaymentService::class)->addPayment($millBill, ['amount' => 5000, 'account_id' => 1, 'paying_method' => 'Cash',
            'business_date' => '2026-10-03', 'idempotency_key' => 'e2e-pay-mill'], 1, $this->context());
        $this->assertBooksAgree('supplier payment');

        // 4. Job work: 400 m grey out, 380 m back as dyed, 10 m rejected to quarantine, 10 m lost.
        $job = app(JobWorkService::class);
        $process = $job->configureProcess(['code' => 'DYE', 'name' => 'Dyeing', 'max_loss_percent' => 5, 'conversion' => true], $this->context(), 1);
        $order = $job->order(['job_worker_party_id' => 1, 'process_type_id' => $process, 'business_date' => '2026-10-03'], 'e2e-jwo', $this->context(), 1);
        $dc = $job->dispatch($order->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03', 'lines' => [['product_id' => $grey->id, 'qty' => 400]]], 'e2e-jwdc', $this->context(), 1);
        $this->assertEquals(400, (float) $grey->fresh()->qty, 'material at the job worker is still our stock');
        $this->assertBooksAgree('job work DC');
        $dcLine = DB::table('job_work_dispatch_lines')->where('dispatch_id', $dc->id)->first();
        $receipt = $job->receive($dc->id, ['warehouse_id' => 1, 'rejected_warehouse_id' => 2, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $dcLine->id, 'accepted_qty' => 380, 'rejected_qty' => 10, 'loss_qty' => 10]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 380, 'cost_weight' => 1]]], 'e2e-jwgrn', $this->context(), 1);
        $this->assertEquals(380, (float) $dyed->fresh()->qty);
        $this->assertEquals(400, $this->balance('inventory_loss'), '10 m lost at 40 = 400 written off');
        $this->assertEquals(10, (float) DB::table('product_warehouse')->where('product_id', $grey->id)->where('warehouse_id', 2)->sum('qty'), 'rejects in quarantine');
        $this->assertBooksAgree('job work GRN');

        // 5. The job worker bills under their own name for the cloth; a wrong rate is then corrected (reverse + rebook).
        $wrong = $job->serviceBill($receipt->id, ['business_date' => '2026-10-03', 'their_names' => [$grey->id => 'GRD'],
            'items' => [['product_id' => $charge->id, 'qty' => 380, 'net_unit_cost' => 10]]], 'e2e-service', $this->context(), 1)->service_purchase_id;
        $this->assertStringContainsString('Their name: GRD for 3200 cc Grey', Purchase::findOrFail($wrong)->note);
        $this->assertEquals('3200 cc Grey', $grey->fresh()->name, 'the job worker\'s name never renames our product');
        $this->assertBooksAgree('service bill');
        $fixed = $job->rebill($receipt->id, ['business_date' => '2026-10-03', 'bill_id' => $wrong, 'reason' => 'Agreed rate is 8',
            'items' => [['product_id' => $charge->id, 'qty' => 380, 'net_unit_cost' => 8]]], 'e2e-service-fixed', $this->context(), 1)->service_purchase_id;
        $this->assertEquals(380 * 40 + 380 * 8, round((float) DB::table('stock_movement_lines')->where('product_id', $dyed->id)->sum('value'), 2),
            'dyed cost = grey cost + corrected processing charge');
        $this->assertNotNull(Purchase::findOrFail($wrong)->reversed_at);
        $this->assertBooksAgree('corrected service bill');

        // 5b. Of the 10 m rejects in quarantine: 6 m go back to the dyer for rework (and return dyed), 4 m go back to the mill.
        $rework = $job->rework($receipt->id, ['warehouse_id' => 2, 'business_date' => '2026-10-03', 'lines' => [['product_id' => $grey->id, 'qty' => 6]]],
            'e2e-rework', $this->context(), 1);
        $this->assertEquals(4, (float) DB::table('product_warehouse')->where('product_id', $grey->id)->where('warehouse_id', 2)->sum('qty'));
        $reworkLine = DB::table('job_work_dispatch_lines')->where('dispatch_id', $rework->id)->first();
        $job->receive($rework->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $reworkLine->id, 'accepted_qty' => 6, 'rejected_qty' => 0, 'loss_qty' => 0]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 6, 'cost_weight' => 1]]], 'e2e-rework-grn', $this->context(), 1);
        $this->assertEquals(386, (float) $dyed->fresh()->qty);
        $this->assertBooksAgree('rework of rejects');
        $poBill = Purchase::where('supplier_id', 1)->whereNotNull('posted_at')->whereNull('reversed_at')->orderBy('id')->firstOrFail();
        $debit = app(ReturnService::class)->create('purchase', $poBill->id, ['business_date' => '2026-10-04', 'reason' => 'Rejected at dyeing', 'note_type' => 'debit',
            'adjustment_type' => 'quantity', 'items' => [['line_id' => $poBill->productPurchases->first()->id, 'qty' => 4, 'warehouse_id' => 2]]], 'e2e-debit', $this->context(), 1);
        app(ReturnService::class)->approve('purchase', $debit->id, $this->context(), 1);
        $this->assertEquals(0, (float) DB::table('product_warehouse')->where('product_id', $grey->id)->where('warehouse_id', 2)->sum('qty'), 'quarantine emptied');
        $this->assertBooksAgree('debit note from quarantine');

        // 6. Sales order → invoice; customer DC → invoice; part payment.
        $so = app(OrderLifecycleService::class)->createSale(['customer_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03',
            'items' => [$line($dyed, 200, 100, 'net_unit_price')]], 'e2e-so', $this->context(), 1);
        $this->assertBooksAgree('sales order');
        $invoice = $commercial->create('sale', ['customer_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03', 'sales_order_id' => $so->id,
            'items' => [$line($dyed, 200, 100, 'net_unit_price')]], 'e2e-so-invoice', 1, $this->context());
        $this->assertEquals(-1000, $this->balance('output_tax_cgst') + $this->balance('output_tax_sgst'), '20,000 sale at 5%: 500 CGST + 500 SGST');
        $this->assertBooksAgree('invoice against SO');
        $dcId = $this->postJson('/delivery-challans', ['challan_date' => '2026-10-03', 'customer_id' => 1, 'warehouse_id' => 1, 'purpose' => 'approval',
            'unit_id' => [1], 'product_id' => [$dyed->id], 'qty' => [50], 'rate' => [100]])->assertCreated()->json('challan.id');
        $customerDc = DeliveryChallan::findOrFail($dcId);
        $this->assertBooksAgree('customer DC');
        $commercial->create('sale', ['customer_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-03', 'delivery_challan_ids' => [$customerDc->id],
            'items' => [$line($dyed, 50, 100, 'net_unit_price') + ['material_sources' => [['document_id' => $customerDc->id, 'item_id' => $customerDc->items()->first()->id, 'qty_base' => 50]]]]],
            'e2e-dc-invoice', 1, $this->context());
        $this->assertEquals(136, (float) $dyed->fresh()->qty, '386 - 200 - 50');
        $this->assertBooksAgree('invoice against customer DC');
        app(PaymentService::class)->addPayment($invoice, ['amount' => 10000, 'account_id' => 1, 'paying_method' => 'Cash',
            'business_date' => '2026-10-03', 'idempotency_key' => 'e2e-receipt'], 1, $this->context());
        $this->assertBooksAgree('customer payment');

        // 7. Credit note: 20 m back to stock. Debit note: the 10 m rejects go back to the mill from quarantine.
        $credit = app(ReturnService::class)->create('sale', $invoice->id, ['business_date' => '2026-10-04', 'reason' => 'Shade mismatch', 'note_type' => 'credit',
            'adjustment_type' => 'quantity', 'items' => [['line_id' => $invoice->productSales->first()->id, 'qty' => 20, 'restock_warehouse_id' => 1]]], 'e2e-credit', $this->context(), 1);
        app(ReturnService::class)->approve('sale', $credit->id, $this->context(), 1);
        $this->assertEquals(156, (float) $dyed->fresh()->qty);
        $this->assertBooksAgree('credit note');

        // 8. An invoice is edited after posting: reversed with its reason and replaced.
        $toEdit = Sale::whereNotNull('posted_at')->whereNull('reversed_at')->orderByDesc('id')->firstOrFail();
        app(CommercialReversalService::class)->replace($toEdit, ['customer_id' => 1, 'warehouse_id' => 1, 'business_date' => '2026-10-04',
            'items' => [$line($dyed, 50, 110, 'net_unit_price')]], 'e2e-replace', '2026-10-04', 'Rate agreed at 110', $this->context());
        $this->assertBooksAgree('edited invoice');

        // 9. The customer's invoice names our product, never the job worker's "GRD".
        $print = app(DocumentRenderingService::class)->dto('sale', $invoice->id, $this->context());
        $this->assertSame('3200 cc Dyed', $print['lines'][0]['name']);
    }
}
