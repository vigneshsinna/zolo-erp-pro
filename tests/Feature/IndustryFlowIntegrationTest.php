<?php

namespace Tests\Feature;

use App\Models\Inventory\{StockDimension, StockIdentity, ProductUomConversion};
use App\Services\Commercial\{PurchaseApplicationService, PurchaseCommand, SaleApplicationService, SaleCommand};
use App\Services\Documents\DocumentRenderingService;
use App\Services\Industry\{FmcgInventoryService, IndustryProfileService, IndustryStockQueries};
use App\Services\Inventory\{InventoryMovementService, InventoryReconciliationService, StockLine, StockMovementCommand};
use App\Services\JobWork\JobWorkService;
use Illuminate\Support\Facades\DB;
use Tests\Support\{CreatesOperationsFixtures, ReturnsDocumentTestCase};

/** Full shared commercial/GST/document stack with profile extensions, not another posting implementation. */
class IndustryFlowIntegrationTest extends ReturnsDocumentTestCase
{
    use CreatesOperationsFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpOperationsFixtures(); }

    public function test_grey_fabric_purchase_job_work_service_credit_sale_partial_receipt_and_print_reconcile(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'manufacturing', $this->context(), 1);
        DB::table('units')->where('id', 1)->update(['unit_name' => 'MTR']);
        $fabric = $this->gstProduct(); $fabric->forceFill(['qty' => 0, 'unit_id' => 1])->save();
        DB::table('product_warehouse')->where('product_id', $fabric->id)->update(['qty' => 0]);
        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($fabric, [
            'items' => [['product_id' => $fabric->id, 'qty' => 500, 'net_unit_cost' => 2]]]), 'grey-purchase', 1, $this->context()));
        $this->assertEquals(1180, $purchase->grand_total); $this->assertEquals(500, $fabric->fresh()->qty);
        [, $dispatch, $line] = $this->job($fabric);
        $job = app(JobWorkService::class);
        $receipt = $job->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03', 'lines' => [
            ['dispatch_line_id' => $line->id, 'accepted_qty' => 480, 'rejected_qty' => 0, 'loss_qty' => 20]]], 'grey-return', $this->context(), 1);
        $service = $this->gstProduct('service'); $service->forceFill(['qty' => 0])->save();
        DB::table('product_warehouse')->where('product_id', $service->id)->update(['qty' => 0]);
        $billId = $job->serviceBill($receipt->id, ['business_date' => '2026-10-03', 'items' => [
            ['product_id' => $service->id, 'qty' => 1, 'net_unit_cost' => 25]]], 'grey-service', $this->context(), 1)->service_purchase_id;
        // The job worker's bill prints as a job work service bill naming its GRN; the GRN prints its own receipt note.
        $bill = app(DocumentRenderingService::class)->dto('purchase', $billId, $this->context());
        $this->assertSame('Job work service bill', $bill['title']);
        $this->assertStringContainsString('GRN '.$receipt->reference_no, $bill['source_number']);
        $this->assertArrayNotHasKey('bale_no', $bill['transport'] ?? []);
        $this->get('/operations/receipt/'.$receipt->id.'/print')->assertOk()->assertSee('Job Work GRN')->assertSee($receipt->reference_no)->assertSee($dispatch->reference_no);
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($fabric, [
            'items' => [['product_id' => $fabric->id, 'qty' => 480, 'net_unit_price' => 3]],
            'transport_name' => 'Fixture carrier', 'lr_number' => 'LR-100', 'lr_date' => '2026-10-03', 'bale_count' => 5, 'bundle_count' => 10,
        ]), 'grey-sale', 1, $this->context()));
        app(\App\Services\ERP\PaymentService::class)->addPayment($sale, ['amount' => 200, 'account_id' => 1,
            'paying_method' => 'Cash', 'business_date' => '2026-10-03', 'idempotency_key' => 'grey-payment'], 1, $this->context());
        $this->assertEquals(1499, DB::table('account_open_items')->where('source_type', 'sale')->where('source_id', $sale->id)->sum('open_amount'));
        $this->assertEquals(0, $fabric->fresh()->qty); $this->assertSame([], $job->pending($this->context(), 1));
        $print = app(DocumentRenderingService::class); $document = $print->dto('sale', $sale->id, $this->context());
        $text = $print->fixedWidth($document, $print->profile('sale', $this->context(), null, 'dot_matrix'));
        $this->assertCount(68, explode("\r\n", $text)); $this->assertStringContainsString('Qty 480.000 Rate', $text);
        $this->assertStringContainsString('Bale count: 5', $text); $this->assertStringContainsString('LR-100', $text);
        app(IndustryProfileService::class)->apply('general_trading', 'trading', $this->context(), 1);
        $this->assertSame($document, $print->dto('sale', $sale->id, $this->context()));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_job_work_charge_becomes_dyed_fabric_cost_with_lot_trace_cogs_split_and_bill_reversal(): void
    {
        (require database_path('migrations/2026_10_16_000005_job_work_textile_process_route.php'))->up();
        $category = DB::table('tax_categories')->value('id');
        $grey = $this->material(0, 80, ['tax_category_id' => $category, 'hsn_code' => '1001']);
        $dyed = $this->material(0, 0, ['tax_category_id' => $category, 'hsn_code' => '1001']);
        $charge = $this->gstProduct('service'); $charge->forceFill(['qty' => 0, 'tax_category_id' => $category])->save();
        DB::table('product_warehouse')->where('product_id', $charge->id)->update(['qty' => 0]);
        app(InventoryMovementService::class)->receive(new StockMovementCommand(date: '2026-10-03', warehouseId: 1, context: $this->context(), userId: 1,
            lines: [new StockLine(productId: $grey->id, qty: 1000, unitCost: 80, batch: ['batch_no' => 'GREY-L1'])]));
        $job = app(JobWorkService::class);
        $process = $job->configureProcess(['code' => 'GREY-DYE', 'name' => 'Dyeing - grey to dyed fabric', 'max_loss_percent' => 5, 'conversion' => true,
            'output_product_id' => $dyed->id, 'service_product_id' => $charge->id, 'service_rate' => 15], $this->context(), 1);
        $saved = DB::table('process_types')->where('id', $process)->first(['output_product_id', 'service_product_id', 'service_rate']);
        $this->assertSame([$dyed->id, $charge->id, 15.0], [(int) $saved->output_product_id, (int) $saved->service_product_id, (float) $saved->service_rate]);
        $average = fn ($product) => round(DB::table('stock_movement_lines')->where('product_id', $product->id)->sum('value')
            / max(0.0001, DB::table('stock_movement_lines')->where('product_id', $product->id)->sum('qty_base')), 4);
        $cycle = function (float $sent, float $accepted, string $key) use ($job, $process, $grey, $dyed) {
            $order = $job->order(['job_worker_party_id' => 1, 'process_type_id' => $process, 'business_date' => '2026-10-03'], $key.'-order', $this->context(), 1);
            $dispatch = $job->dispatch($order->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03', 'transport' => ['vehicle_number' => 'TN39 AB 1234'],
                'lines' => [['product_id' => $grey->id, 'qty' => $sent, 'batch' => ['batch_no' => 'GREY-L1']]]], $key.'-dispatch', $this->context(), 1);
            $line = DB::table('job_work_dispatch_lines')->where('dispatch_id', $dispatch->id)->first();
            return $job->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
                'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => $accepted, 'rejected_qty' => 0, 'loss_qty' => $sent - $accepted]],
                'outputs' => [['product_id' => $dyed->id, 'qty' => $accepted, 'cost_weight' => 1, 'batch' => ['batch_no' => 'RB-'.$key]]]], $key.'-receipt', $this->context(), 1);
        };
        $bill = fn ($receipt, float $qty, string $key) => $job->serviceBill($receipt->id, ['business_date' => '2026-10-03',
            'items' => [['product_id' => $charge->id, 'qty' => $qty, 'net_unit_cost' => 15]]], $key, $this->context(), 1);

        // 1. Bill before any sale: the whole charge goes into the dyed fabric (₹80 grey + ₹15 dyeing = ₹95 per metre).
        $receipt = $cycle(500, 490, 'A');
        $this->assertSame(['input' => ['GREY-L1'], 'output' => ['RB-A']], $receipt->fresh()->details_json['lots']);
        $this->assertSame(80.0, $average($dyed));
        $bill($receipt, 490, 'bill-A');
        $this->assertSame(95.0, $average($dyed));
        $cost = $receipt->fresh()->details_json['processing_cost'];
        $this->assertEquals([7350, 7350, 0, 15, 80], [$cost['amount'], $cost['to_stock'], $cost['to_cogs'], $cost['per_unit'], $cost['material_per_unit']]);
        $journal = fn (string $subType) => (float) DB::table('journal_items as i')->join('journal_entries as j', 'j.id', '=', 'i.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'i.chart_of_account_id')->where('j.reference_type', 'purchase')
            ->where('j.reference_id', $receipt->fresh()->service_purchase_id)->where('a.sub_type', $subType)->sum('i.debit');
        $this->assertEquals([7350, 0], [$journal('inventory'), $journal('operating_expense')], 'The bill debits stock, not expense.');
        $this->get('/operations/job-work/'.DB::table('job_work_dispatches')->where('id', $receipt->dispatch_id)->value('job_work_order_id'))->assertOk()
            ->assertSee('Lots GREY-L1 → RB-A', false)->assertSee('material ₹80.00 + processing ₹15.00 = ₹95.00 per unit', false)->assertSee('Job worker bill:', false);
        $this->getJson('/operations/inventory/lots?product_id='.$dyed->id.'&warehouse_id=1')->assertOk()->assertJsonPath('data.0.batch_no', 'RB-A')->assertJsonPath('data.0.qty', fn ($qty) => (float) $qty === 490.0);

        // 2. Half of a second lot is sold before the dyer bills: that half of the charge goes to cost of goods sold.
        $second = $cycle(400, 400, 'B');
        app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($dyed, ['items' => [
            ['product_id' => $dyed->id, 'qty' => 490, 'net_unit_price' => 150, 'product_batch_id' => DB::table('product_batches')->where('batch_no', 'RB-A')->value('id')],
            ['product_id' => $dyed->id, 'qty' => 200, 'net_unit_price' => 150, 'product_batch_id' => DB::table('product_batches')->where('batch_no', 'RB-B')->value('id')]]]), 'dyed-sale', 1, $this->context()));
        $purchase = \App\Models\Purchase::findOrFail($bill($second, 400, 'bill-B')->fresh()->service_purchase_id);
        $cost = $second->fresh()->details_json['processing_cost'];
        $this->assertEquals([6000, 3000, 3000], [$cost['amount'], $cost['to_stock'], $cost['to_cogs']], '200 of 400 dyed metres were still in stock.');

        // 3. Reversing the dyer's bill removes the revaluation with it.
        $before = DB::table('stock_movement_lines')->where('product_id', $dyed->id)->sum('value');
        app(\App\Services\Commercial\CommercialReversalService::class)->reverse($purchase, '2026-10-03', 'Billed twice', $this->context());
        $this->assertEquals(round($before - 3000, 4), round(DB::table('stock_movement_lines')->where('product_id', $dyed->id)->sum('value'), 4));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_job_work_challan_prints_gst_delivery_challan_details_not_internal_ids(): void
    {
        (require database_path('migrations/2026_10_16_000005_job_work_textile_process_route.php'))->up();
        DB::table('suppliers')->where('id', 1)->update(['name' => 'Ravi', 'company_name' => 'Bluewave Dyeing Works', 'address' => '5, Dyeing Unit Street',
            'city' => 'Tiruppur', 'vat_number' => $this->gstin('33')]);
        $grey = $this->material(100, 80, ['tax_category_id' => DB::table('tax_categories')->value('id'), 'hsn_code' => '5208', 'name' => 'Grey Cotton Fabric 60s']);
        [$order, $dispatch] = $this->job($grey, 100, true);
        DB::table('job_work_dispatches')->where('id', $dispatch->id)->update(['details_json' => json_encode(['transport' => ['vehicle_number' => 'TN39 AB 1234', 'eway_bill_no' => '1810 2233 4455']])]);
        $html = $this->get('/operations/dispatch/'.$dispatch->id.'/print')->assertOk()->getContent();
        foreach (['Delivery Challan', 'Rule 55(1)(c)', 'Bluewave Dyeing Works', '5, Dyeing Unit Street', $this->gstin('33'), 'Grey Cotton Fabric 60s', '5208',
            '8,000.00', 'Eight Thousand Rupees Only', 'Sent for job work - Processing', 'TN39 AB 1234', '1810 2233 4455', 'Section 143',
            'Original for consignee (job worker)', 'Duplicate for transporter', 'Triplicate for consignor', 'Authorised Signatory', $dispatch->reference_no] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        $this->assertStringNotContainsString('Job worker #', $html);
        $this->get('/operations/job-work/'.$order->id)->assertOk()->assertSee('Print job work challan (GST)')->assertSee('Lot / batch no.')->assertSee('E-way bill no.');
        $this->get('/operations/job-work')->assertOk()->assertSee('Edit an existing process')->assertSee('Processing charge item');
    }

    public function test_presets_add_units_switch_process_types_and_name_shared_screens(): void
    {
        $profiles = app(IndustryProfileService::class);
        $active = fn () => DB::table('process_types')->where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->pluck('code')->all();
        $profiles->apply('textile', 'manufacturing', $this->context(), 1);
        $this->assertTrue(DB::table('units')->where('company_id', $this->company->id)->where('unit_code', 'MTR')->exists(), 'Textile adds its meter unit.');
        $this->assertSame(['DYEING', 'FINISHING', 'GREY-DYE', 'PRINTING'], $active());
        request()->attributes->set(\App\Services\Platform\CompanyContext::class, $this->context());
        $this->assertSame(['Godown', 'Material DC', 'Job Work GRN'], [IndustryProfileService::label('warehouse', 'Warehouse'),
            IndustryProfileService::label('dispatch', 'Send material'), IndustryProfileService::label('receipt', 'Receive material')]);

        $profiles->apply('fmcg', 'distribution', $this->context(), 1);
        $this->assertSame(['REPACKING'], $active(), 'Textile presets stop being offered after the switch.');
        request()->attributes->remove('industry_labels');
        $this->assertSame('Warehouse', IndustryProfileService::label('warehouse', 'Warehouse'));

        $units = DB::table('units')->where('company_id', $this->company->id)->count();
        $profiles->apply('textile', 'manufacturing', $this->context(), 1);
        $this->assertSame(['DYEING', 'FINISHING', 'GREY-DYE', 'PRINTING'], $active(), 'Switching back offers them again.');
        $this->assertSame($units, DB::table('units')->where('company_id', $this->company->id)->count(), 'Preset units are added once.');
    }

    public function test_case_purchase_box_sale_free_scheme_and_mrp_use_normalized_base_quantities(): void
    {
        app(IndustryProfileService::class)->apply('fmcg', 'distribution', $this->context(), 1);
        $units = DB::table('units')->where('company_id', $this->company->id)->whereIn('unit_code', ['BOX', 'CASE'])->pluck('id', 'unit_code');
        $this->assertCount(2, $units, 'The FMCG preset adds its BOX and CASE units.');
        $product = $this->material(0, 1, ['is_batch' => true, 'tax_category_id' => DB::table('tax_categories')->value('id'), 'hsn_code' => '1001']);
        foreach ([$units['BOX'] => 12, $units['CASE'] => 144] as $id => $factor) ProductUomConversion::create(['company_id' => $this->company->id,
            'product_id' => $product->id, 'from_uom_id' => $id, 'to_uom_id' => 1, 'factor' => $factor, 'rounding_scale' => 4]);
        app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($product, ['items' => [[
            'product_id' => $product->id, 'qty' => 1, 'purchase_unit_id' => $units['CASE'], 'net_unit_cost' => 144,
            'batch' => ['batch_no' => 'CASE-A', 'mfg_date' => '2026-09-01', 'expired_date' => '2027-09-01', 'mrp' => 5],
        ]]]), 'case-purchase', 1, $this->context()));
        $scheme = app(FmcgInventoryService::class)->configureScheme(['product_id' => $product->id, 'name' => '12+1',
            'buy_qty' => 12, 'free_qty' => 1, 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31'], $this->context(), 1);
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($product, ['items' => [[
            'product_id' => $product->id, 'qty' => 1, 'sale_unit_id' => $units['BOX'], 'net_unit_price' => 24, 'quantity_scheme_id' => $scheme,
        ]]]), 'box-sale', 1, $this->context()));
        $this->assertEquals(28, $sale->grand_total); $this->assertEquals(131, $product->fresh()->qty);
        $this->assertEquals(5, DB::table('product_batches')->value('mrp'));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_piece_picker_and_invoice_freeze_selected_volume_after_profile_or_formula_changes(): void
    {
        $profile = app(IndustryProfileService::class); $profile->apply('timber', 'trading', $this->context(), 1);
        $product = $this->material(0, 5, ['tax_category_id' => DB::table('tax_categories')->value('id'), 'hsn_code' => '1001']); $profile->attributes($product->id, $this->context(), 1, ['species' => 'Teak', 'grade' => 'A']);
        app(InventoryMovementService::class)->receive(new StockMovementCommand(date: '2026-10-03', lines: [
            new StockLine(productId: $product->id, qty: 20, unitCost: 3, dimensions: ['identity_no' => 'TEAK-1',
                'length' => 10, 'width' => 1, 'thickness' => 1, 'pieces' => 2, 'dimension_uom' => 'ft', 'grade' => 'A'])], warehouseId: 1, context: $this->context(), userId: 1));
        $pieces = app(IndustryStockQueries::class)->pieces($product->id, 1, 'Teak', $this->context(), 1);
        $this->assertCount(1, $pieces); $this->assertEquals(20, $pieces[0]['qty_base']);
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($product, ['items' => [[
            'product_id' => $product->id, 'qty' => 5, 'net_unit_price' => 6, 'stock_identity_id' => StockIdentity::sole()->id,
        ]]]), 'piece-sale', 1, $this->context()));
        $renderer = app(DocumentRenderingService::class); $dto = $renderer->dto('sale', $sale->id, $this->context());
        $this->assertEquals(5, $dto['lines'][0]['dimensions']['line_cft']);
        $html = $renderer->html($dto, $renderer->profile('sale', $this->context(), null, 'a4'));
        $this->assertStringContainsString('TEAK-1', $html); $this->assertStringContainsString('rectangular-v1', $html);
        $this->assertEquals(15, $product->fresh()->qty); $this->assertEquals(20, StockDimension::sole()->computed_cft);
        $profile->apply('general_trading', 'trading', $this->context(), 1);
        $this->assertSame($dto, $renderer->dto('sale', $sale->id, $this->context()));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_solar_5kw_quote_missing_purchase_serial_commission_invoice_payment_and_credit_margin(): void
    {
        $profiles = app(IndustryProfileService::class); $profiles->apply('solar', 'epc', $this->context(), 1);
        $panel = $this->material(0, 50, ['is_imei' => true, 'price' => 100,
            'tax_category_id' => DB::table('tax_categories')->value('id'), 'hsn_code' => '1001']);
        $profiles->attributes($panel->id, $this->context(), 1, ['wattage' => 500, 'warranty_months' => 120]);
        $system = $this->material(0); $bom = $this->bom($panel, $system, ['output_qty' => 5,
            'lines' => [['component_product_id' => $panel->id, 'qty' => 10, 'uom_id' => 1]]]);
        $projects = app(\App\Services\Industry\ProjectService::class);
        $project = $projects->create(['title' => '5kW rooftop', 'client_id' => 1, 'system_kw' => 5,
            'site_address' => 'Test rooftop', 'business_date' => '2026-10-03'], 'site-5kw', $this->context(), 1);
        $projects->quotation($project->id, ['bom_id' => $bom->id, 'qty' => 5, 'warehouse_id' => 1,
            'business_date' => '2026-10-03'], 'quote-5kw', $this->context(), 1);
        $this->assertEquals(1180, DB::table('sales')->value('grand_total')); $this->assertEquals(0, $panel->fresh()->qty);
        $serials = array_map(fn ($i) => 'PANEL-'.$i, range(1, 10));
        app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($panel, ['project_id' => $project->id,
            'items' => [['product_id' => $panel->id, 'qty' => 10, 'net_unit_cost' => 50, 'serials' => $serials]]]), 'missing-panels', 1, $this->context()));
        foreach ($serials as $serial) {
            $identity = StockIdentity::where('identity_no', $serial)->sole();
            $allocation = $projects->allocate($project->id, ['stock_identity_id' => $identity->id, 'business_date' => '2026-10-03'], 'allocate-'.$serial, $this->context(), 1);
            $projects->dispatch($allocation->id, ['business_date' => '2026-10-03'], 'dispatch-'.$serial, $this->context(), 1);
            $installed = $projects->install($allocation->id, ['business_date' => '2026-10-03'], 'install-'.$serial, $this->context(), 1);
            $projects->commission($installed->id, ['business_date' => '2026-10-03', 'amc_months' => 12], 'commission-'.$serial, $this->context(), 1);
        }
        $sale = app(SaleApplicationService::class)->create(new SaleCommand($this->saleData($panel, ['project_id' => $project->id,
            'warehouse_id' => $project->site_warehouse_id, 'items' => [['product_id' => $panel->id, 'qty' => 10, 'net_unit_price' => 100, 'serials' => $serials]]]), 'site-invoice', 1, $this->context()));
        app(\App\Services\ERP\PaymentService::class)->addPayment($sale, ['amount' => 1180, 'account_id' => 1,
            'paying_method' => 'Cash', 'business_date' => '2026-10-03', 'idempotency_key' => 'site-payment'], 1, $this->context());
        $this->assertEquals(0, DB::table('account_open_items')->where('source_type', 'sale')->where('source_id', $sale->id)->sum('open_amount'));
        $this->assertEquals(0, $panel->fresh()->qty); $this->assertSame(20, DB::table('warranty_contracts')->count());
        $this->assertSame('500.0000', $projects->margin($project->id, $this->context(), 1)['margin']);
        $this->postedReturn($sale, ['adjustment_type' => 'rate_difference', 'items' => [['line_id' => $sale->productSales->first()->id, 'amount' => 100]]], 'site-rate-credit');
        $margin = $projects->margin($project->id, $this->context(), 1);
        $this->assertSame('900.0000', $margin['revenue']); $this->assertSame('500.0000', $margin['material_cost']);
        $this->assertSame('400.0000', $margin['margin']);
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }
}
