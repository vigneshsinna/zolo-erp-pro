<?php

namespace Tests\Feature;

use App\Models\Inventory\ProductUomConversion;
use App\Models\Product;
use App\Services\Commercial\{PurchaseApplicationService, PurchaseCommand, SaleApplicationService, SaleCommand};
use App\Services\Documents\DocumentRenderingService;
use App\Services\ERP\PaymentService;
use App\Services\Industry\{FmcgInventoryService, IndustryProfileService};
use App\Services\Inventory\{InventoryReconciliationService, InventoryMovementService, StockLine, StockMovementCommand};
use App\Services\JobWork\JobWorkService;
use App\Services\Platform\CapabilityService;
use App\Services\Tax\{GstProjectionService, GstSetupService};
use Illuminate\Support\Facades\DB;
use Tests\Support\{CreatesOperationsFixtures, ReturnsDocumentTestCase};

/**
 * Textile scenario matrix: each test drives one textile business case through the real purchase, job work,
 * sale, GST and ledger services. Case ids live in tests/Support/textile_scenario_matrix.php.
 */
class TextileScenarioMatrixTest extends ReturnsDocumentTestCase
{
    use CreatesOperationsFixtures;

    private int $fabricTax;
    private int $garmentTax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOperationsFixtures();
        // Textile lots carry no expiry date; this migration relaxes the column as it does in production.
        (require database_path('migrations/2026_10_16_000005_job_work_textile_process_route.php'))->up();
        (require database_path('migrations/2026_10_24_000003_textile_trade_compliance_features.php'))->up();
        (require database_path('migrations/2026_11_20_235003_add_principal_job_financial_year_ownership.php'))->up();
        // Party 1 is both the mill and the dyer in these fixtures.
        DB::table('suppliers')->where('id', 1)->update(['name' => 'Lakshmi Mills & Dyeing', 'company_name' => 'Lakshmi Mills & Dyeing Works']);
        DB::table('customers')->where('id', 1)->update(['name' => 'Kaveri Garments', 'company_name' => 'Kaveri Garments Pvt Ltd']);
        DB::table('warehouses')->where('id', 1)->update(['name' => 'Central Fabric Godown']);
        $this->company->update(['name' => 'Animazon Textiles Pvt Ltd']);
        DB::table('tax_registrations')->update(['legal_name' => 'Animazon Textiles Pvt Ltd']);
        // Fabric and job work services are 5%; garments above the price threshold are 18% (GST 2.0 slabs).
        $this->fabricTax = $this->taxCategory('Textile fabric 5%', 5);
        $this->garmentTax = $this->taxCategory('Garments above threshold 18%', 18);
        foreach ([['5208', 'hsn', 'Woven cotton fabric'], ['6109', 'hsn', 'Knitted T-shirts'], ['6310', 'hsn', 'Rags and fents'],
            ['3204', 'hsn', 'Reactive dyes'], ['998821', 'sac', 'Textile manufacturing services'], ['996511', 'sac', 'Road transport of goods']] as [$code, $kind, $description]) {
            DB::table('hsn_sac_codes')->insert(['company_id' => $this->company->id, 'code' => $code, 'kind' => $kind, 'description' => $description]);
        }
    }

    private function named(Product $product, string $name): Product
    {
        $product->forceFill(['name' => $name])->save();
        return $product;
    }

    private function taxCategory(string $name, float $rate): int
    {
        $id = DB::table('tax_categories')->insertGetId(['company_id' => $this->company->id, 'name' => $name, 'supply_type' => 'taxable', 'input_credit_allowed' => true]);
        DB::table('tax_rates')->insert(['company_id' => $this->company->id, 'tax_category_id' => $id, 'rate' => $rate, 'effective_from' => '2026-01-01']);
        return $id;
    }

    private function fabric(float $qty = 0, float $cost = 0, string $hsn = '5208', ?int $tax = null, array $extra = []): Product
    {
        return $this->material($qty, $cost, ['tax_category_id' => $tax ?? $this->fabricTax, 'hsn_code' => $hsn] + $extra);
    }

    private function charge(): Product
    {
        $service = $this->material(0, 0, ['tax_category_id' => $this->fabricTax, 'hsn_code' => '998821', 'type' => 'service']);
        DB::table('product_warehouse')->where('product_id', $service->id)->update(['qty' => 0]);
        return $service;
    }

    private function buy(Product $product, float $qty, float $cost, string $key, array $extra = [])
    {
        return app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($product, $extra + [
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'net_unit_cost' => $cost]]]), $key, 1, $this->context()));
    }

    private function sell(array $items, string $key, array $extra = [])
    {
        return app(SaleApplicationService::class)->create(new SaleCommand($extra + ['customer_id' => 1, 'warehouse_id' => 1,
            'business_date' => '2026-10-03', 'items' => $items], $key, 1, $this->context()));
    }

    /** One job work order with its first Material DC; $lines are [product, qty] pairs. */
    private function sendForJob(string $code, array $lines, string $key, float $maxLoss = 5): array
    {
        $job = app(JobWorkService::class);
        $process = $job->configureProcess(['code' => $code, 'name' => $code, 'max_loss_percent' => $maxLoss, 'conversion' => true], $this->context(), 1);
        $order = $job->order(['job_worker_party_id' => 1, 'process_type_id' => $process, 'business_date' => '2026-10-03'], $key.'-order', $this->context(), 1);
        $dispatch = $job->dispatch($order->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => array_map(fn ($line) => ['product_id' => $line[0]->id, 'qty' => $line[1]], $lines)], $key.'-dc', $this->context(), 1);
        return [$order, $dispatch, DB::table('job_work_dispatch_lines')->where('dispatch_id', $dispatch->id)->orderBy('line_no')->get()];
    }

    private function receive($dispatch, array $lines, array $outputs, string $key)
    {
        return app(JobWorkService::class)->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03', 'lines' => $lines,
            'outputs' => array_map(fn ($o) => ['product_id' => $o[0]->id, 'qty' => $o[1], 'cost_weight' => $o[2] ?? 1], $outputs)], $key, $this->context(), 1);
    }

    private function bill($receipt, Product $charge, float $qty, float $rate, string $key)
    {
        return app(JobWorkService::class)->serviceBill($receipt->id, ['business_date' => '2026-10-03',
            'items' => [['product_id' => $charge->id, 'qty' => $qty, 'net_unit_cost' => $rate]]], $key, $this->context(), 1);
    }

    /** Net debit balance of every journal line on accounts of one sub type. */
    private function ledger(string $subType): float
    {
        return round((float) DB::table('journal_items as i')->join('chart_of_accounts as a', 'a.id', '=', 'i.chart_of_account_id')
            ->where('a.sub_type', $subType)->sum(DB::raw('i.debit - i.credit')), 2);
    }

    private function stockValue(Product $product): float
    {
        return round((float) DB::table('stock_movement_lines')->where('product_id', $product->id)->sum('value'), 2);
    }

    private function unitCost(Product $product): float
    {
        return round($this->stockValue($product) / max(0.0001, (float) DB::table('stock_movement_lines')->where('product_id', $product->id)->sum('qty_base')), 4);
    }

    private function partyState(string $type, string $state, string $registration = 'regular'): void
    {
        DB::table('party_tax_profiles')->where('party_type', $type)->update(['state_code' => $state, 'registration_type' => $registration,
            'gstin' => $registration === 'regular' ? $this->gstin($state) : null]);
    }

    private function assertBooksReconcile(): void
    {
        $this->assertEquals(round(DB::table('journal_items')->sum('debit'), 2), round(DB::table('journal_items')->sum('credit'), 2), 'Journals balance.');
        $this->assertSame([], app(InventoryReconciliationService::class)->differences(), 'Stock ledger matches stock balances.');
    }

    public function test_e2e_manufacturing_animazon_story_posts_real_stock_gst_and_profit(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'manufacturing', $this->context(), 1);
        $grey = $this->named($this->fabric(), 'Grey cotton fabric'); $dyed = $this->named($this->fabric(), 'Dyed cotton - Royal Blue'); $dyeing = $this->named($this->charge(), 'Dyeing charge');

        // Purchase 400 grey @ ₹150 + 5% GST.
        $purchase = $this->buy($grey, 400, 150, 'animazon-purchase');
        $this->assertEquals(63000, $purchase->grand_total); $this->assertEquals(400, $grey->fresh()->qty);

        // Material DC to the dyer, all 400 come back dyed, then the dyer bills ₹60 per piece.
        [$order, $dispatch, $lines] = $this->sendForJob('ROYAL-BLUE', [[$grey, 400]], 'animazon-job');
        $at = fn (int $warehouse) => (float) DB::table('product_warehouse')->where('product_id', $grey->id)->where('warehouse_id', $warehouse)->sum('qty');
        $this->assertEquals([0, 400], [$at(1), $at($order->external_warehouse_id)], 'Grey is still ours but sits at the job worker, not in our godown.');
        $receipt = $this->receive($dispatch, [['dispatch_line_id' => $lines[0]->id, 'accepted_qty' => 400, 'rejected_qty' => 0, 'loss_qty' => 0]],
            [[$dyed, 400]], 'animazon-grn');
        $this->assertSame(150.0, $this->unitCost($dyed));
        $this->bill($receipt, $dyeing, 400, 60, 'animazon-dyeing-bill');
        $this->assertSame(210.0, $this->unitCost($dyed), 'Dyeing charge is capitalised: ₹150 + ₹60.');

        // Sell 350 @ ₹380 + 5% GST.
        $sale = $this->sell([['product_id' => $dyed->id, 'qty' => 350, 'net_unit_price' => 380]], 'animazon-sale');
        $this->assertEquals(139650, $sale->grand_total);
        $this->assertEquals(-133000, $this->ledger('sales_revenue'));
        $this->assertEquals(73500, $this->ledger('cogs'));
        $this->assertEquals(59500, -$this->ledger('sales_revenue') - $this->ledger('cogs'), 'Gross profit read from the ledger.');
        $this->assertEquals(50, $dyed->fresh()->qty); $this->assertEquals(10500, $this->stockValue($dyed));
        $this->assertEquals(2100, $this->ledger('input_tax_cgst'), 'Input CGST: ₹1,500 fabric + ₹600 dyeing.');
        $this->assertEquals(-3325, $this->ledger('output_tax_cgst'));
        $summary = app(GstProjectionService::class)->summary(app(GstProjectionService::class)->report($this->context(), '2026-10-01', '2026-10-31'));
        $this->assertSame('133000.0000', $summary['outward_taxable']['taxable_value']);
        $this->assertSame([], app(JobWorkService::class)->pending($this->context(), 1));
        $this->assertBooksReconcile();
    }

    public function test_e2e_wholesale_trader_buys_finished_fabric_and_sells_in_bales_without_job_work(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'wholesale', $this->context(), 1);
        $poplin = $this->named($this->fabric(), 'Cotton poplin');
        $this->buy($poplin, 1250.375, 40, 'ws-purchase');
        $this->assertEquals(1250.375, $poplin->fresh()->qty, 'Metres keep three decimals.');
        $this->rejected(fn () => app(JobWorkService::class)->order(['job_worker_party_id' => 1, 'process_type_id' => 1, 'business_date' => '2026-10-03'],
            'ws-job', $this->context(), 1), 'Capability is disabled: operations.job_work');
        $sale = $this->sell([['product_id' => $poplin->id, 'qty' => 125.125, 'net_unit_price' => 70]], 'ws-sale', ['transport_name' => 'ARC Transports',
            'lr_number' => 'ARC-ERD-45129', 'lr_date' => '2026-10-03', 'bale_count' => 2, 'bundle_count' => 4]);
        $this->assertEquals(1125.25, $poplin->fresh()->qty);
        $snapshot = $sale->productSales->first()->tax_snapshot_json;
        $this->assertEquals([5, 'regular'], [$snapshot['rate'], DB::table('party_tax_profiles')->where('party_type', 'customer')->value('registration_type')]);
        $print = app(DocumentRenderingService::class);
        $text = $print->fixedWidth($print->dto('sale', $sale->id, $this->context()), $print->profile('sale', $this->context(), null, 'dot_matrix'));
        $this->assertStringContainsString('Qty 125.125 Rate', $text); $this->assertStringContainsString('Bale count: 2', $text);
        $this->assertStringContainsString('ARC-ERD-45129', $text);
        $this->assertBooksReconcile();
    }

    public function test_e2e_distributor_hides_mill_specs_and_reports_b2b_and_b2c_sales(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'distributor', $this->context(), 1);
        $attributes = DB::table('product_attribute_definitions')->where('company_id', $this->company->id)->where('enabled', true)->pluck('key')->all();
        $this->assertNotContains('gsm', $attributes); $this->assertContains('brand', $attributes);
        $this->assertTrue(app(CapabilityService::class)->enabled('sales.route_distribution', $this->context()));
        $this->assertFalse(app(CapabilityService::class)->enabled('operations.job_work', $this->context()));
        $shirt = $this->named($this->fabric(0, 0, '6109'), 'Cotton T-shirt');
        $this->buy($shirt, 100, 400, 'ds-purchase');
        $this->sell([['product_id' => $shirt->id, 'qty' => 60, 'net_unit_price' => 550]], 'ds-b2b');
        $this->partyState('customer', '33', 'unregistered');
        $this->sell([['product_id' => $shirt->id, 'qty' => 2, 'net_unit_price' => 600]], 'ds-b2c');
        $sections = array_column(app(GstProjectionService::class)->report($this->context(), '2026-10-01', '2026-10-31'), 'section');
        $this->assertContains('b2b', $sections); $this->assertContains('b2c', $sections);
        $this->assertEquals(38, $shirt->fresh()->qty);
        $this->assertBooksReconcile();
    }

    public function test_e2e_wholesale_distributor_issues_free_pieces_under_a_volume_scheme(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'wholesale_distributor', $this->context(), 1);
        $this->assertTrue(app(CapabilityService::class)->enabled('sales.route_distribution', $this->context()));
        $shirt = $this->named($this->fabric(0, 0, '6109'), 'Cotton T-shirt');
        $this->buy($shirt, 50, 300, 'wd-purchase');
        $scheme = app(FmcgInventoryService::class)->configureScheme(['product_id' => $shirt->id, 'name' => '10+1',
            'buy_qty' => 10, 'free_qty' => 1, 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31'], $this->context(), 1);
        $sale = $this->sell([['product_id' => $shirt->id, 'qty' => 10, 'net_unit_price' => 500, 'quantity_scheme_id' => $scheme]], 'wd-scheme-sale');
        $this->assertEquals(5250, $sale->grand_total, 'Only the 10 bought pieces are charged.');
        $this->assertEquals(39, $shirt->fresh()->qty, 'Ten sold plus one free piece left the godown.');
        $this->assertBooksReconcile();
    }

    public function test_job_work_is_refused_for_every_trading_business_type(): void
    {
        $profiles = app(IndustryProfileService::class);
        foreach (['wholesale', 'distributor', 'wholesale_distributor'] as $type) {
            $profiles->apply('textile', $type, $this->context(), 1);
            $this->rejected(fn () => app(JobWorkService::class)->order(['job_worker_party_id' => 1, 'process_type_id' => 1, 'business_date' => '2026-10-03'],
                'refused-'.$type, $this->context(), 1), 'Capability is disabled: operations.job_work');
        }
        $profiles->apply('textile', 'manufacturing', $this->context(), 1);
        $grey = $this->named($this->fabric(10, 80), 'Grey cotton fabric');
        [$order] = $this->sendForJob('ALLOWED', [[$grey, 10]], 'allowed');
        $this->assertNotNull($order->posted_at);
    }

    public function test_interstate_mill_purchase_and_interstate_garment_sale_use_igst(): void
    {
        $fabric = $this->named($this->fabric(), 'Cotton fabric');
        $this->partyState('supplier', '24');
        $purchase = $this->buy($fabric, 1000, 50, 'surat-purchase');
        $in = $purchase->productPurchases->first()->tax_snapshot_json;
        $this->assertEquals([0, 0, 2500], [$in['cgst'], $in['sgst'], $in['igst']]);
        $this->assertEquals(2500, $this->ledger('input_tax_igst'));
        $this->partyState('customer', '27');
        $sale = $this->sell([['product_id' => $fabric->id, 'qty' => 400, 'net_unit_price' => 90]], 'mumbai-sale');
        $out = $sale->productSales->first()->tax_snapshot_json;
        $this->assertEquals([0, 0, 1800], [$out['cgst'], $out['sgst'], $out['igst']]);
        $this->assertEquals(-1800, $this->ledger('output_tax_igst'));
        $this->assertBooksReconcile();
    }

    public function test_one_invoice_mixes_five_and_eighteen_percent_lines(): void
    {
        $fabric = $this->named($this->fabric(500, 40), 'Cotton fabric'); $premium = $this->named($this->fabric(10, 2000, '6109', $this->garmentTax), 'Premium shirt');
        $sale = $this->sell([['product_id' => $fabric->id, 'qty' => 100, 'net_unit_price' => 10],
            ['product_id' => $premium->id, 'qty' => 1, 'net_unit_price' => 3000]], 'mixed-slab');
        $rates = $sale->productSales->map(fn ($line) => (float) $line->tax_snapshot_json['rate'])->all();
        $this->assertEquals([5, 18], $rates);
        $this->assertEquals(590, $sale->total_tax, '₹50 on fabric + ₹540 on the premium garment.');
        $this->assertEquals(4590, $sale->grand_total);
        $this->assertBooksReconcile();
    }

    public function test_rolls_bought_by_the_roll_are_stocked_and_costed_in_metres(): void
    {
        $base = (array) DB::table('units')->find(1); unset($base['id']);
        $roll = DB::table('units')->insertGetId(['unit_code' => 'ROLL', 'unit_name' => 'Roll (100 m)'] + $base);
        $fabric = $this->named($this->fabric(), 'Cotton fabric');
        ProductUomConversion::create(['company_id' => $this->company->id, 'product_id' => $fabric->id, 'from_uom_id' => $roll,
            'to_uom_id' => 1, 'factor' => 100, 'rounding_scale' => 3]);
        $this->buy($fabric, 5, 4000, 'roll-purchase', ['items' => [['product_id' => $fabric->id, 'qty' => 5, 'purchase_unit_id' => $roll, 'net_unit_cost' => 4000]]]);
        $this->assertEquals(500, $fabric->fresh()->qty);
        $this->assertSame(40.0, $this->unitCost($fabric));
        $this->sell([['product_id' => $fabric->id, 'qty' => 1, 'sale_unit_id' => $roll, 'net_unit_price' => 6000]], 'roll-sale');
        $this->assertEquals(400, $fabric->fresh()->qty);
        $this->assertBooksReconcile();
    }

    public function test_one_job_order_takes_phased_dispatches_and_tracks_pending_per_lot(): void
    {
        $grey = $this->named($this->fabric(500, 80), 'Grey cotton fabric'); $dyed = $this->named($this->fabric(), 'Dyed cotton - Royal Blue');
        [$order, $first, $firstLines] = $this->sendForJob('PHASED', [[$grey, 300]], 'phased', 4);
        $job = app(JobWorkService::class);
        $second = $job->dispatch($order->id, ['warehouse_id' => 1, 'business_date' => '2026-10-04', 'lines' => [['product_id' => $grey->id, 'qty' => 200]]],
            'phased-dc-2', $this->context(), 1);
        $this->assertEquals(500, $job->pending($this->context(), 1)[0]['pending_qty']);
        $this->receive($first, [['dispatch_line_id' => $firstLines[0]->id, 'accepted_qty' => 290, 'rejected_qty' => 0, 'loss_qty' => 10]],
            [[$dyed, 290]], 'phased-grn-1');
        $this->assertEquals(200, $job->pending($this->context(), 1)[0]['pending_qty'], 'Only the second lot is still with the dyer.');
        $this->assertNotEquals($first->reference_no, $second->reference_no);
        $this->assertEquals(290, $dyed->fresh()->qty);
        $this->assertBooksReconcile();
    }

    public function test_multi_stage_dyeing_then_printing_carries_every_charge_into_the_printed_fabric(): void
    {
        $grey = $this->named($this->fabric(100, 80), 'Grey cotton fabric'); $dyed = $this->named($this->fabric(), 'Dyed cotton - Royal Blue'); $printed = $this->named($this->fabric(), 'Printed cotton'); $charge = $this->named($this->charge(), 'Processing charge');
        [, $dc1, $l1] = $this->sendForJob('STAGE-DYE', [[$grey, 100]], 'stage-1');
        $grn1 = $this->receive($dc1, [['dispatch_line_id' => $l1[0]->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]], [[$dyed, 100]], 'stage-1-grn');
        $this->bill($grn1, $charge, 100, 15, 'stage-1-bill');
        [, $dc2, $l2] = $this->sendForJob('STAGE-PRINT', [[$dyed, 100]], 'stage-2');
        $grn2 = $this->receive($dc2, [['dispatch_line_id' => $l2[0]->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]], [[$printed, 100]], 'stage-2-grn');
        $this->bill($grn2, $charge, 100, 10, 'stage-2-bill');
        $this->assertSame(105.0, $this->unitCost($printed), '₹80 grey + ₹15 dyeing + ₹10 printing.');
        $this->assertEquals([0, 0, 100], [$grey->fresh()->qty, $dyed->fresh()->qty, $printed->fresh()->qty]);
        $this->assertBooksReconcile();
    }

    public function test_principal_supplied_dyes_travel_on_the_same_dc_and_are_consumed_into_the_fabric(): void
    {
        $grey = $this->named($this->fabric(500, 80), 'Grey cotton fabric'); $dye = $this->named($this->fabric(10, 300, '3204'), 'Reactive dye'); $dyed = $this->named($this->fabric(), 'Dyed cotton - Royal Blue');
        [, $dispatch, $lines] = $this->sendForJob('DYE-WITH-CHEM', [[$grey, 500], [$dye, 10]], 'chem');
        $this->receive($dispatch, [
            ['dispatch_line_id' => $lines[0]->id, 'accepted_qty' => 490, 'rejected_qty' => 0, 'loss_qty' => 10],
            ['dispatch_line_id' => $lines[1]->id, 'accepted_qty' => 10, 'rejected_qty' => 0, 'loss_qty' => 0],
        ], [[$dyed, 490]], 'chem-grn');
        $this->assertEquals(42200, $this->stockValue($dyed), '490 m × ₹80 grey + 10 kg × ₹300 dye.');
        $this->assertEquals(800, $this->ledger('inventory_loss'), '10 m shrinkage at ₹80 is a loss, not fabric cost.');
        $this->assertEquals([0, 0], [$grey->fresh()->qty, $dye->fresh()->qty]);
        $this->assertSame([], app(JobWorkService::class)->pending($this->context(), 1));
        $this->assertBooksReconcile();
    }

    public function test_grade_a_seconds_and_fents_share_the_grey_cost_by_weight(): void
    {
        $grey = $this->named($this->fabric(100, 100), 'Grey cotton fabric'); $gradeA = $this->named($this->fabric(), 'Dyed fabric grade A'); $seconds = $this->named($this->fabric(), 'Dyed fabric seconds'); $fents = $this->named($this->fabric(0, 0, '6310'), 'Fents / chindi');
        [, $dispatch, $lines] = $this->sendForJob('GRADED', [[$grey, 100]], 'graded', 0);
        $this->receive($dispatch, [['dispatch_line_id' => $lines[0]->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]],
            [[$gradeA, 85, 85], [$seconds, 10, 8], [$fents, 14, 7]], 'graded-grn');
        $this->assertEquals([8500, 800, 700], [$this->stockValue($gradeA), $this->stockValue($seconds), $this->stockValue($fents)]);
        $this->assertEquals([100, 80, 50], [$this->unitCost($gradeA), $this->unitCost($seconds), $this->unitCost($fents)]);
        $this->assertEquals(10000, $this->stockValue($gradeA) + $this->stockValue($seconds) + $this->stockValue($fents), 'No value is created or lost.');
        $this->assertBooksReconcile();
    }

    public function test_inward_freight_is_capitalised_by_quantity_or_by_bale_weight(): void
    {
        // GST company: freight is a taxed service line; ticked, its taxable value joins the fabric cost and its GST stays input credit.
        $cotton = $this->named($this->fabric(), 'Cotton bale'); $rayon = $this->named($this->fabric(), 'Rayon bale');
        $freight = $this->named($this->material(0, 0, ['tax_category_id' => $this->garmentTax, 'hsn_code' => '996511', 'type' => 'service']), 'Lorry freight');
        $this->rejected(fn () => $this->buy($cotton, 10, 100, 'freight-header', ['shipping_cost' => 100]), 'Add freight to item cost');
        $this->buy($cotton, 100, 100, 'freight-gst', ['capitalise_services' => 1, 'landed_cost_method' => 'quantity', 'items' => [
            ['product_id' => $cotton->id, 'qty' => 100, 'net_unit_cost' => 100], ['product_id' => $rayon->id, 'qty' => 300, 'net_unit_cost' => 50],
            ['product_id' => $freight->id, 'qty' => 1, 'net_unit_cost' => 1000]]]);
        $this->assertEquals([10250, 15750], [$this->stockValue($cotton), $this->stockValue($rayon)], 'Freight ₹1,000 split 100 : 300 m.');
        $this->assertEquals(0, $this->ledger('operating_expense'), 'Nothing goes to expense.');
        $this->assertEquals(715, $this->ledger('input_tax_cgst'), 'CGST: ₹625 on fabric + ₹90 on freight.');
        $this->assertEquals(-27430, $this->ledger('accounts_payable'));
        // Company without GST registration: a header freight amount is spread the same way.
        DB::table('tax_registrations')->delete();
        foreach (['quantity' => [250, 750], 'weight' => [400, 600]] as $method => [$freightA, $freightB]) {
            $cotton = $this->named($this->material(0), 'Cotton bale'); $rayon = $this->named($this->material(0), 'Rayon bale');
            $this->buy($cotton, 100, 100, 'freight-'.$method, ['shipping_cost' => 1000, 'landed_cost_method' => $method, 'items' => [
                ['product_id' => $cotton->id, 'qty' => 100, 'net_unit_cost' => 100, 'weight' => 40],
                ['product_id' => $rayon->id, 'qty' => 300, 'net_unit_cost' => 50, 'weight' => 60]]]);
            $this->assertEquals([10000 + $freightA, 15000 + $freightB], [$this->stockValue($cotton), $this->stockValue($rayon)], 'Freight split by '.$method.'.');
        }
        $this->assertBooksReconcile();
    }

    public function test_cash_discount_on_prompt_payment_and_customer_tds_settle_the_bill_without_money(): void
    {
        $fabric = $this->named($this->fabric(), 'Cotton fabric');
        $pay = fn ($document, array $data, string $key) => app(PaymentService::class)->addPayment($document, $data + ['account_id' => 1,
            'paying_method' => 'Cash', 'idempotency_key' => $key], 1, $this->context());
        $open = fn (string $type, $document) => (float) DB::table('account_open_items')->where('source_type', $type)->where('source_id', $document->id)->sum('open_amount');

        // Supplier offers 1% if paid within 10 days.
        $purchase = $this->buy($fabric, 101, 100, 'cd-purchase', ['cash_discount_percent' => 1, 'cash_discount_days' => 10]);
        $pay($purchase, ['amount' => 10498.95, 'discount' => 106.05, 'business_date' => '2026-10-10'], 'cd-supplier');
        $this->assertEquals(0, $open('purchase', $purchase));
        $this->assertEquals(-106.05, $this->ledger('discount_received'));

        // We offer 2% within 7 days: more than 2%, or after day 7, is refused.
        $sale = $this->sell([['product_id' => $fabric->id, 'qty' => 100, 'net_unit_price' => 150]], 'cd-sale', ['cash_discount_percent' => 2, 'cash_discount_days' => 7]);
        $this->assertEquals(15750, $sale->grand_total);
        $this->rejected(fn () => $pay($sale, ['amount' => 15434, 'discount' => 316, 'business_date' => '2026-10-05'], 'cd-too-much'), 'exceeds the 2%');
        $this->rejected(fn () => $pay($sale, ['amount' => 15435, 'discount' => 315, 'business_date' => '2026-10-11'], 'cd-late'), 'period ended');
        $pay($sale, ['amount' => 15435, 'discount' => 315, 'business_date' => '2026-10-05'], 'cd-customer');
        $this->assertEquals(0, $open('sale', $sale));
        $this->assertEquals(315, $this->ledger('discount_allowed'));
        $this->assertEquals(-375, $this->ledger('output_tax_cgst'), 'GST on the invoice is unchanged by the discount.');

        // Buyer with turnover over ₹10 crore deducts 0.1% TDS (194Q) on the value before GST.
        $second = $this->sell([['product_id' => $fabric->id, 'qty' => 1, 'net_unit_price' => 150]], 'tds-sale');
        $this->rejected(fn () => $pay($second, ['amount' => 100, 'discount' => 5, 'business_date' => '2026-10-05'], 'cd-no-terms'), 'Give a reason');
        $pay($second, ['amount' => 157.85, 'tds_amount' => 0.15, 'business_date' => '2026-10-05'], 'tds-customer');
        $this->assertEquals(0, $open('sale', $second));
        $this->assertEquals(0.15, $this->ledger('tds_receivable'));
        $this->assertBooksReconcile();
    }

    public function test_processor_ships_straight_to_the_customer_and_the_invoice_says_so(): void
    {
        $grey = $this->named($this->fabric(200, 80), 'Grey cotton fabric'); $dyed = $this->named($this->fabric(), 'Dyed cotton - Royal Blue');
        [$order, $dispatch, $lines] = $this->sendForJob('DIRECT', [[$grey, 200]], 'direct');
        $receive = fn (array $extra, string $key) => app(JobWorkService::class)->receive($dispatch->id, $extra + ['business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $lines[0]->id, 'accepted_qty' => 196, 'rejected_qty' => 0, 'loss_qty' => 4]]], $key, $this->context(), 1);
        $this->rejected(fn () => $receive([], 'direct-no-output'), 'warehouse');
        $receive(['direct_dispatch' => true, 'outputs' => [['product_id' => $dyed->id, 'qty' => 196, 'cost_weight' => 1]]], 'direct-grn');
        $at = fn (int $warehouse) => (float) DB::table('product_warehouse')->where('product_id', $dyed->id)->where('warehouse_id', $warehouse)->sum('qty');
        $this->assertEquals([0, 196], [$at(1), $at($order->external_warehouse_id)], 'Dyed fabric waits at the dyer, not in our godown.');
        $sale = $this->sell([['product_id' => $dyed->id, 'qty' => 196, 'net_unit_price' => 120]], 'direct-sale', ['warehouse_id' => $order->external_warehouse_id]);
        $this->assertStringStartsWith('Lakshmi Mills & Dyeing Works', $sale->attributes_json['dispatched_from']);
        $this->assertSame([], app(JobWorkService::class)->pending($this->context(), 1), 'Nothing is left at the dyer.');
        $this->assertEquals(15680, $this->ledger('cogs'), 'Cost of goods sold: 196 m at ₹80.');
        $this->assertBooksReconcile();
    }

    public function test_processing_charge_can_be_booked_as_period_expense_instead_of_fabric_cost(): void
    {
        $grey = $this->named($this->fabric(100, 80), 'Grey cotton fabric'); $dyed = $this->named($this->fabric(), 'Dyed cotton'); $charge = $this->named($this->charge(), 'Dyeing charge');
        [, $dispatch, $lines] = $this->sendForJob('EXPENSED', [[$grey, 100]], 'expensed');
        $receipt = $this->receive($dispatch, [['dispatch_line_id' => $lines[0]->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]], [[$dyed, 100]], 'expensed-grn');
        app(JobWorkService::class)->serviceBill($receipt->id, ['business_date' => '2026-10-03', 'charge_mode' => 'expense',
            'items' => [['product_id' => $charge->id, 'qty' => 100, 'net_unit_cost' => 15]]], 'expensed-bill', $this->context(), 1);
        $this->assertSame(80.0, $this->unitCost($dyed), 'Dyed fabric keeps the grey cost.');
        $this->assertEquals(1500, $this->ledger('operating_expense'), 'The ₹1,500 charge is a period expense.');
        $this->assertBooksReconcile();
    }

    public function test_material_at_job_worker_shows_its_section_143_return_deadline(): void
    {
        $grey = $this->named($this->fabric(100, 80), 'Grey cotton fabric');
        $this->sendForJob('SEC143', [[$grey, 100]], 'sec143');
        $row = fn (string $asOf) => app(JobWorkService::class)->deadlines($this->context(), 1, $asOf)[0];
        $this->assertEquals(['2026-10-03', '2027-10-03', 'inputs'], [$row('2026-10-03')['sent_on'], $row('2026-10-03')['return_by'], $row('2026-10-03')['goods_kind']]);
        $this->assertEquals(['ok', 365], [$row('2026-10-03')['status'], $row('2026-10-03')['days_left']]);
        $this->assertEquals(['due_soon', 33], [$row('2027-08-31')['status'], $row('2027-08-31')['days_left']]);
        $this->assertEquals(['overdue', -10], [$row('2027-10-13')['status'], $row('2027-10-13')['days_left']]);
        $this->get('/operations/job-work')->assertOk()->assertSee('Sec 143 return by')->assertSee('03-10-2027');
    }

    public function test_garment_rate_is_picked_from_the_per_piece_sale_price(): void
    {
        $gst = app(GstSetupService::class);
        $band = $gst->installGarmentBands($this->context(), 1);
        $this->assertSame($band, $gst->installGarmentBands($this->context(), 1), 'Installing twice keeps one rate.');
        $shirt = $this->named($this->fabric(50, 900, '6109', $band), 'Cotton shirt'); $blazer = $this->named($this->fabric(10, 2600, '6109', $band), 'Linen blazer');
        $sale = $this->sell([['product_id' => $shirt->id, 'qty' => 4, 'net_unit_price' => 2500], ['product_id' => $blazer->id, 'qty' => 1, 'net_unit_price' => 3200]], 'garment-band');
        $this->assertEquals([5, 18], $sale->productSales->map(fn ($line) => (float) $line->tax_snapshot_json['rate'])->all(), '₹2,500 a piece is 5%; ₹3,200 is 18%.');
        $this->assertEquals(500 + 576, $sale->total_tax);
        $discounted = $this->sell([['product_id' => $blazer->id, 'qty' => 1, 'net_unit_price' => 2600]], 'garment-band-discount', ['order_discount' => 200]);
        $this->assertEquals(5, $discounted->productSales->first()->tax_snapshot_json['rate'], 'The band uses the price after discount (₹2,400).');
        $this->rejected(fn () => $gst->changeRate($band, 12, 0, '2026-11-01', $this->context(), 1), 'price bands');
        $this->assertBooksReconcile();
    }

    public function test_export_and_sez_supplies_are_zero_rated_under_lut_or_charged_igst(): void
    {
        $fabric = $this->named($this->fabric(500, 40), 'Cotton poplin');
        $export = fn (string $supply, string $key) => $this->sell([['product_id' => $fabric->id, 'qty' => 100, 'net_unit_price' => 100]], $key, ['supply_type' => $supply]);
        DB::table('party_tax_profiles')->where('party_type', 'customer')->delete();
        $this->rejected(fn () => $export('export_lut', 'exp-no-lut'), 'Letter of Undertaking');
        app(GstSetupService::class)->saveTradeSettings(['lut_number' => 'AD330326000123X', 'lut_valid_from' => '2026-04-01', 'lut_valid_to' => '2027-03-31'], $this->context(), 1);
        $lut = $export('export_lut', 'exp-lut');
        $this->assertEquals([10000, 0], [(float) $lut->grand_total, (float) $lut->total_tax], 'No IGST under LUT.');
        $this->assertSame(['zero_rated', '96'], [$lut->productSales->first()->tax_snapshot_json['supply_type'], $lut->productSales->first()->tax_snapshot_json['place_of_supply']]);
        $this->assertSame('AD330326000123X', $lut->attributes_json['lut_number']);
        $paid = $export('export_igst', 'exp-igst');
        $this->assertEquals([0, 500], [$paid->productSales->first()->tax_snapshot_json['cgst'], $paid->productSales->first()->tax_snapshot_json['igst']], 'Export with IGST paid (refund route).');
        DB::table('party_tax_profiles')->insert(['company_id' => $this->company->id, 'party_type' => 'customer', 'party_id' => 1,
            'gstin' => $this->gstin('33'), 'state_code' => '33', 'registration_type' => 'regular']);
        $sez = $export('sez_igst', 'sez-igst');
        $this->assertEquals([0, 500], [$sez->productSales->first()->tax_snapshot_json['cgst'], $sez->productSales->first()->tax_snapshot_json['igst']], 'An SEZ in our own state is still inter-state.');
        $sections = array_count_values(array_column(app(GstProjectionService::class)->report($this->context(), '2026-10-01', '2026-10-31'), 'section'));
        $this->assertSame(['exp' => 2, 'sez' => 1], $sections);
        $summary = app(GstProjectionService::class)->summary(app(GstProjectionService::class)->report($this->context(), '2026-10-01', '2026-10-31'));
        $this->assertSame('30000.0000', $summary['outward_zero_rated']['taxable_value']);
        $this->assertBooksReconcile();
    }

    public function test_buyer_deducts_194q_tds_only_on_purchases_above_fifty_lakh_a_year(): void
    {
        app(GstSetupService::class)->saveTradeSettings(['tds_194q' => true], $this->context(), 1);
        $yarn = $this->named($this->fabric(), 'Cotton yarn');
        $first = $this->buy($yarn, 100000, 40, 'tds-first');
        $this->assertArrayNotHasKey('tds_194q', $first->fresh()->attributes_json ?? [], '₹40 lakh so far: below the ₹50 lakh threshold.');
        $second = $this->buy($yarn, 50000, 40, 'tds-second')->fresh();
        // MySQL JSON columns reorder object keys, so compare key-sorted.
        $expected = ['section' => '194Q', 'base' => 1000000, 'rate' => 0.1, 'amount' => 1000, 'pan' => 'ABCDE1234F', 'year_to_date' => 6000000];
        $actual = array_map(fn ($v) => is_float($v) && floor($v) == $v ? (int) $v : $v, $second->attributes_json['tds_194q']);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, 'Only the ₹10 lakh above the threshold, at 0.1%.');
        $this->assertEquals(-1000, $this->ledger('tds_payable'));
        $this->assertEquals(2100000 - 1000, DB::table('account_open_items')->where('source_type', 'purchase')->where('source_id', $second->id)->sum('open_amount'));
        $this->assertCount(1, app(\App\Services\Tax\Tds194qService::class)->register($this->context(), '2026-10-01', '2026-10-31'));
        // Cash discount terms default from the supplier master.
        DB::table('suppliers')->where('id', 1)->update(['cd_percent' => 1.5, 'cd_days' => 15]);
        $third = $this->buy($yarn, 10, 40, 'tds-third');
        $this->assertEquals([1.5, 15], [$third->attributes_json['cash_discount_percent'], $third->attributes_json['cash_discount_days']]);
        $this->assertBooksReconcile();
    }

    public function test_gstr3b_itc04_einvoice_and_eway_bill_come_from_posted_documents(): void
    {
        foreach (['address', 'city', 'postal_code'] as $column) if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', $column)) {
            \Illuminate\Support\Facades\Schema::table('companies', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->string($column)->nullable());
        }
        DB::table('companies')->where('id', $this->company->id)->update(['address' => '24, Textile Mill Road', 'city' => 'Erode', 'postal_code' => '638001']);
        DB::table('customers')->where('id', 1)->update(['address' => '102, Garment Avenue', 'city' => 'Coimbatore', 'postal_code' => '641018']);
        DB::table('suppliers')->where('id', 1)->update(['address' => '5, SIPCOT', 'city' => 'Perundurai', 'postal_code' => '638052']);
        app(GstSetupService::class)->saveTradeSettings(['lut_number' => 'AD330326000123X'], $this->context(), 1);
        $fabric = $this->named($this->fabric(), 'Cotton poplin'); $grey = $this->named($this->fabric(500, 80), 'Grey cotton fabric'); $dyed = $this->named($this->fabric(), 'Dyed cotton');
        $this->buy($fabric, 1000, 50, 'r-purchase');
        $b2b = $this->sell([['product_id' => $fabric->id, 'qty' => 400, 'net_unit_price' => 100]], 'r-b2b');
        $this->sell([['product_id' => $fabric->id, 'qty' => 100, 'net_unit_price' => 100]], 'r-export', ['supply_type' => 'export_lut']);
        $this->partyState('customer', '29', 'unregistered');
        $b2c = $this->sell([['product_id' => $fabric->id, 'qty' => 10, 'net_unit_price' => 100]], 'r-b2c');
        [, $dispatch, $lines] = $this->sendForJob('ITC04', [[$grey, 500]], 'r-job', 4);
        $this->receive($dispatch, [['dispatch_line_id' => $lines[0]->id, 'accepted_qty' => 480, 'rejected_qty' => 0, 'loss_qty' => 20]], [[$dyed, 480]], 'r-grn');

        $returns = app(\App\Services\Tax\GstReturnsService::class);
        $r = $returns->gstr3b($this->context(), '2026-10-01', '2026-10-31');
        $this->assertEquals(['taxable_value' => 41000, 'igst' => 50, 'cgst' => 1000, 'sgst' => 1000, 'cess' => 0], $r['boxes']['3.1(a)']);
        $this->assertEquals(10000, $r['boxes']['3.1(b)']['taxable_value'], 'Export under LUT is zero-rated.');
        $this->assertEquals([1250, 1250], [$r['boxes']['4(A)(5)']['cgst'], $r['boxes']['4(A)(5)']['sgst']]);
        $this->assertEquals(['taxable_value' => 1000, 'igst' => 50], $r['3.2_inter_state_unregistered']['29']);
        $this->assertEquals(['cgst' => ['cgst' => 1000, 'igst' => 50], 'sgst' => ['sgst' => 1000]], $r['6.1']['paid_through_itc'], 'CGST credit pays CGST, then IGST.');
        $this->assertEquals(['igst' => 0, 'cgst' => 200, 'sgst' => 250, 'cess' => 0], $r['6.1']['credit_carried_forward']);
        $this->assertEquals(0, array_sum($r['6.1']['paid_in_cash']));
        [$used, $cash] = $returns->setOff(['igst' => 100, 'cgst' => 100, 'sgst' => 100, 'cess' => 0], ['igst' => 50, 'cgst' => 0, 'sgst' => 300, 'cess' => 0]);
        $this->assertEquals([['igst' => ['igst' => 50], 'sgst' => ['sgst' => 100, 'igst' => 50]], ['igst' => 0, 'cgst' => 100, 'sgst' => 0, 'cess' => 0]], [$used, $cash],
            'SGST credit never pays CGST, so CGST is paid in cash.');

        $itc = $returns->itc04($this->context(), '2026-10-01', '2026-10-31');
        $this->assertEquals([500, 40000.0, 5.0, 'Inputs'], [$itc['table_4_sent'][0]['qty'], $itc['table_4_sent'][0]['taxable_value'], $itc['table_4_sent'][0]['gst_rate'], $itc['table_4_sent'][0]['goods_type']]);
        $this->assertEquals([480, 20, $dispatch->reference_no], [$itc['table_5a_received_back'][0]['qty'], $itc['table_5a_received_back'][0]['loss_and_waste_qty'], $itc['table_5a_received_back'][0]['original_challan_no']]);
        $this->get('/compliance/gst/returns?from=2026-10-01&to=2026-10-31')->assertOk()->assertSee('GSTR-3B')->assertSee('Table 4')->assertSee('Rule 88A');
        $this->assertStringContainsString('loss_and_waste_qty', $this->get('/compliance/gst/returns?from=2026-10-01&to=2026-10-31&download=itc04_5a&format=csv')->assertOk()->getContent());

        $e = app(\App\Services\Tax\EDocumentService::class);
        $invoice = $e->einvoice($b2b->id, $this->context());
        $this->assertSame(['B2B', 'INV', 638001, 641018, 'PCS'], [$invoice['TranDtls']['SupTyp'], $invoice['DocDtls']['Typ'], $invoice['SellerDtls']['Pin'], $invoice['BuyerDtls']['Pin'], $invoice['ItemList'][0]['Unit']]);
        $this->assertEquals([40000, 1000, 1000, 42000], [$invoice['ValDtls']['AssVal'], $invoice['ValDtls']['CgstVal'], $invoice['ValDtls']['SgstVal'], $invoice['ValDtls']['TotInvVal']]);
        $this->rejected(fn () => $e->einvoice($b2c->id, $this->context()), 'not B2C');
        $eway = $e->ewayBill('sale', $b2b->id, $this->context(), ['vehicle_number' => 'TN 33 AB 1234', 'distance_km' => 80]);
        $this->assertSame([1, 'INV', 'TN33AB1234', false], [$eway['billLists'][0]['subSupplyType'], $eway['billLists'][0]['docType'], $eway['billLists'][0]['vehicleNo'], $eway['required']]);
        $challan = $e->ewayBill('dispatch', $dispatch->id, $this->context());
        $this->assertSame([4, 'CHL', 0.0, 40000.0], [$challan['billLists'][0]['subSupplyType'], $challan['billLists'][0]['docType'], $challan['billLists'][0]['igstValue'], $challan['billLists'][0]['totalValue']]);
        $this->get('/compliance/einvoice/sale/'.$b2b->id)->assertOk()->assertJsonPath('Version', '1.1');
        $this->assertBooksReconcile();
    }

    public function test_agent_brokerage_is_booked_once_with_194h_tds_above_the_yearly_threshold(): void
    {
        $agent = DB::table('agents')->insertGetId(['company_id' => $this->company->id, 'name' => 'Ramesh Dalali', 'commission_rate' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $fabric = $this->named($this->fabric(2100, 600), 'Cotton poplin');
        $small = $this->sell([['product_id' => $fabric->id, 'qty' => 100, 'net_unit_price' => 1000]], 'brk-1', ['agent_id' => $agent]);
        $this->sell([['product_id' => $fabric->id, 'qty' => 2000, 'net_unit_price' => 1000]], 'brk-2', ['agent_id' => $agent]);
        $brokerage = app(\App\Services\Commercial\BrokerageService::class);
        $rows = $brokerage->register($this->context(), '2026-10-01', '2026-10-31');
        $this->assertEquals([1000, 20000], array_column($rows, 'commission'), '1% on the value before GST.');
        $this->assertEquals([0, 0], array_column($brokerage->register($this->context(), '2026-10-01', '2026-10-31', null, 'collection'), 'commission'), 'Nothing collected yet.');
        $booked = $brokerage->book($agent, '2026-10-01', '2026-10-31', '2026-10-31', 'invoice', $this->context(), 1);
        $this->assertEquals(['invoices' => 2, 'amount' => 21000, 'tds' => 420, 'payable' => 20580], array_intersect_key($booked, array_flip(['invoices', 'amount', 'tds', 'payable'])),
            'Rs 21,000 for the year passes Rs 20,000, so 2% TDS on all of it.');
        $this->assertEquals([21000, -20580, -420], [$this->ledger('commission_expense'), $this->ledger('commission_payable'), $this->ledger('tds_payable')]);
        $this->rejected(fn () => $brokerage->book($agent, '2026-10-01', '2026-10-31', '2026-10-31', 'invoice', $this->context(), 1), 'No unbooked brokerage');
        $this->get('/trade/brokerage?from=2026-10-01&to=2026-10-31')->assertOk()->assertSee('Ramesh Dalali')->assertSee('Booked')->assertSee($small->reference_no);
        $this->assertBooksReconcile();
    }

    public function test_distributor_route_sheet_lists_customers_with_outstanding_and_overdue(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'distributor', $this->context(), 1);
        $north = DB::table('areas')->insertGetId(['company_id' => $this->company->id, 'name' => 'Erode North', 'code' => 'R1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('areas')->insert(['company_id' => $this->company->id, 'name' => 'Tiruppur', 'code' => 'R2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->where('id', 1)->update(['area_id' => $north, 'credit_days' => 0]);
        $shirt = $this->named($this->fabric(50, 300, '6109'), 'Cotton T-shirt');
        $this->sell([['product_id' => $shirt->id, 'qty' => 10, 'net_unit_price' => 500]], 'route-sale');
        $routes = app(\App\Services\Commercial\RouteSheetService::class);
        $sheet = collect($routes->sheet($this->context(), '2026-10-10'))->keyBy('route');
        $this->assertEquals([5250, 5250, 0], [$sheet['Erode North']['outstanding'], $sheet['Erode North']['overdue'], $sheet['Erode North']['billed_today']]);
        $this->assertSame('2026-10-03', $sheet['Erode North']['customers'][0]['last_invoice']);
        $this->assertSame([], $sheet['Tiruppur']['customers']);
        $this->assertEquals(5250, collect($routes->sheet($this->context(), '2026-10-03'))->firstWhere('route', 'Erode North')['billed_today']);
        $this->get('/trade/routes?date=2026-10-10')->assertOk()->assertSee('Erode North')->assertSee('Kaveri Garments');
        app(IndustryProfileService::class)->apply('textile', 'wholesale', $this->context(), 1);
        $this->rejected(fn () => $routes->sheet($this->context(), '2026-10-10'), 'sales.route_distribution');
    }

    public function test_dyeing_unit_processes_customer_material_without_owning_it_and_bills_only_the_charge(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'manufacturing', $this->context(), 1);
        $charge = $this->named($this->charge(), 'Dyeing charge (SAC 998821)');
        $jobs = app(\App\Services\JobWork\PrincipalJobService::class);
        $movements = DB::table('stock_movements')->count();
        $order = $jobs->order(['customer_id' => 1, 'business_date' => '2026-10-03', 'process' => 'Reactive dyeing - Navy', 'service_product_id' => $charge->id,
            'rate' => 12, 'max_loss_percent' => 5], $this->context(), 1);
        $jobs->receive($order, ['business_date' => '2026-10-03', 'challan_no' => 'KG-DC-771', 'challan_date' => '2026-10-02',
            'lines' => [['description' => 'Grey cotton 60s', 'hsn_code' => '5208', 'uqc' => 'MTR', 'qty' => 1000, 'value' => 80000]]], $this->context(), 1);
        $line = ['description' => 'Dyed cotton 60s - Navy', 'hsn_code' => '5208', 'uqc' => 'MTR'];
        $this->rejected(fn () => $jobs->returnGoods($order, ['business_date' => '2026-10-06', 'lines' => [$line + ['qty' => 900, 'loss_qty' => 100]]], $this->context(), 1), 'above the agreed 5%');
        $this->rejected(fn () => $jobs->returnGoods($order, ['business_date' => '2026-10-06', 'lines' => [$line + ['qty' => 980, 'loss_qty' => 40]]], $this->context(), 1), 'cannot exceed');
        $jobs->returnGoods($order, ['business_date' => '2026-10-06', 'lines' => [$line + ['qty' => 960, 'loss_qty' => 40]]], $this->context(), 1);
        $sale = $jobs->bill($order, ['business_date' => '2026-10-06'], 'pjo-bill', $this->context(), 1);
        $this->assertEquals(12096, $sale->grand_total, '960 m at Rs 12 + 5% GST.');
        $this->assertEquals(['received' => 1000, 'returned' => 960, 'loss' => 40, 'with_us' => 0, 'billed' => 960, 'unbilled' => 0], $jobs->balance($order));
        $this->assertSame($movements, DB::table('stock_movements')->count(), 'The customer cloth never enters our stock ledger.');
        $this->assertEquals(-11520, $this->ledger('sales_revenue'));
        $this->get('/trade/customer-jobs/'.$order)->assertOk()->assertSee('KG-DC-771')->assertSee('Print challan');
        $this->get('/trade/customer-jobs/'.$order.'?challan=PJR-00001')->assertOk()->assertSee('Rule 55')->assertSee('Dyed cotton 60s - Navy');
        $this->assertBooksReconcile();
    }

    public function test_fabric_is_stocked_roll_by_roll_and_a_roll_is_cut_until_it_is_finished(): void
    {
        $fabric = $this->named($this->fabric(0, 0, '5208'), 'Cotton poplin 58in');
        $roll = fn (string $number, float $metres) => new StockLine(productId: $fabric->id, qty: $metres, unitCost: 60, dimensions: ['identity_no' => $number,
            'length' => $metres, 'width' => 1.47, 'thickness' => 0.0004, 'dimension_uom' => 'm', 'grade' => 'A']);
        app(InventoryMovementService::class)->receive(new StockMovementCommand(date: '2026-10-02', warehouseId: 1, context: $this->context(), userId: 1,
            lines: [$roll('ROLL-A1', 120), $roll('ROLL-A2', 95.5)]));
        $id = fn (string $number) => (int) DB::table('stock_identities')->where('identity_no', $number)->value('id');
        $this->sell([['product_id' => $fabric->id, 'qty' => 45, 'net_unit_price' => 95, 'stock_identity_id' => $id('ROLL-A1')]], 'roll-cut-1');
        $this->rejected(fn () => $this->sell([['product_id' => $fabric->id, 'qty' => 80, 'net_unit_price' => 95, 'stock_identity_id' => $id('ROLL-A1')]], 'roll-cut-2'), 'only 75');
        $this->sell([['product_id' => $fabric->id, 'qty' => 75, 'net_unit_price' => 95, 'stock_identity_id' => $id('ROLL-A1')]], 'roll-cut-3');
        $this->assertEquals(['issued', 'in_stock'], [DB::table('stock_identities')->find($id('ROLL-A1'))->status, DB::table('stock_identities')->find($id('ROLL-A2'))->status]);
        $this->assertEquals(95.5, $fabric->fresh()->qty, 'Only roll A2 is left, whole.');
        $this->assertBooksReconcile();
    }


    public function test_supplier_is_paid_in_full_and_customer_part_payment_leaves_the_balance_open(): void
    {
        $fabric = $this->named($this->fabric(), 'Cotton fabric');
        $purchase = $this->buy($fabric, 100, 100, 'pay-purchase');
        app(PaymentService::class)->addPayment($purchase, ['amount' => 10500, 'account_id' => 1, 'paying_method' => 'Cash',
            'business_date' => '2026-10-03', 'idempotency_key' => 'pay-supplier'], 1, $this->context());
        $this->assertEquals(0, DB::table('account_open_items')->where('source_type', 'purchase')->where('source_id', $purchase->id)->sum('open_amount'));
        $sale = $this->sell([['product_id' => $fabric->id, 'qty' => 100, 'net_unit_price' => 150]], 'pay-sale');
        app(PaymentService::class)->addPayment($sale, ['amount' => 7875, 'account_id' => 1, 'paying_method' => 'Cash',
            'business_date' => '2026-10-03', 'idempotency_key' => 'pay-customer'], 1, $this->context());
        $this->assertEquals(7875, DB::table('account_open_items')->where('source_type', 'sale')->where('source_id', $sale->id)->sum('open_amount'), '50% advance; ₹7,875 still due.');
        $this->assertBooksReconcile();
    }

    public function test_lot_and_shade_stay_traceable_from_opening_stock_to_the_invoice(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'wholesale', $this->context(), 1);
        $fabric = $this->named($this->fabric(0, 0, '5208', null, ['is_batch' => true]), 'Cotton fabric');
        app(InventoryMovementService::class)->importOpening(new StockMovementCommand(date: '2026-10-01', warehouseId: 1, context: $this->context(), userId: 1, lines: [
            new StockLine(productId: $fabric->id, qty: 120, unitCost: 60, batch: ['batch_no' => 'SHADE-NAVY-01']),
            new StockLine(productId: $fabric->id, qty: 80, unitCost: 62, batch: ['batch_no' => 'SHADE-NAVY-02'])]));
        $lot = DB::table('product_batches')->where('batch_no', 'SHADE-NAVY-02')->value('id');
        $this->sell([['product_id' => $fabric->id, 'qty' => 30, 'net_unit_price' => 95, 'product_batch_id' => $lot]], 'lot-sale');
        $balances = DB::table('product_batches')->whereIn('batch_no', ['SHADE-NAVY-01', 'SHADE-NAVY-02'])->orderBy('batch_no')->pluck('qty')->map(fn ($q) => (float) $q)->all();
        $this->assertEquals([120, 50], $balances, 'Only the shade on the invoice was issued.');
        $this->assertBooksReconcile();
    }
}
