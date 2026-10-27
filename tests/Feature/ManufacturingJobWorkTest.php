<?php

namespace Tests\Feature;

use App\Models\Accounting\JournalEntry;
use App\Models\Inventory\StockMovement;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\JobWork\JobWorkService;
use App\Services\Manufacturing\BomService;
use App\Services\Manufacturing\ProductionService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperationsTestCase;

class ManufacturingJobWorkTest extends OperationsTestCase
{
    public function test_production_costs_retry_and_reversal_preserve_history_and_reconcile(): void
    {
        $input = $this->material(100, 5); $output = $this->material(0);
        $bom = $this->bom($input, $output);
        $service = app(ProductionService::class);
        $order = $service->plan(['bom_id' => $bom->id, 'warehouse_id' => 1, 'planned_qty' => 10, 'business_date' => '2026-10-03'], 'plan', $this->context(), 1);
        $data = ['completed_qty' => 10, 'direct_cost' => '20.0000', 'overhead_cost' => '10.0000', 'business_date' => '2026-10-03'];
        $posted = $service->complete($order->id, $data, 'complete', $this->context(), 1);
        $this->assertEquals(130, $posted->total_cost);
        $this->assertEquals(80, $input->fresh()->qty); $this->assertEquals(10, $output->fresh()->qty);
        $this->assertSame($posted->id, $service->complete($order->id, $data, 'complete', $this->context(), 1)->id);
        $this->assertEquals(130, JournalEntry::find($posted->journal_entry_id)->total_debit);
        $this->assertSame('production_consume', StockMovement::find($posted->consume_movement_id)->movement_type);
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
        $this->rejected(fn () => $posted->update(['total_cost' => 1]), 'immutable');
        $service->reverse($order->id, '2026-10-03', 'Wrong output', $this->context(), 1);
        $this->assertEquals(100, $input->fresh()->qty); $this->assertEquals(0, $output->fresh()->qty);
        $this->assertSame(2, JournalEntry::whereIn('reference_type', ['production', 'reversal'])->count());
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_missing_cost_mapping_rolls_back_all_production_effects_and_retry(): void
    {
        $input = $this->material(100); $output = $this->material(0); $bom = $this->bom($input, $output);
        $service = app(ProductionService::class);
        $order = $service->plan(['bom_id' => $bom->id, 'warehouse_id' => 1, 'planned_qty' => 10, 'business_date' => '2026-10-03'], 'plan', $this->context(), 1);
        DB::table('semantic_account_mappings')->where('semantic_role', 'production_costs')->update(['account_id' => null]);
        $count = StockMovement::count();
        $this->rejected(fn () => $service->complete($order->id, ['completed_qty' => 10, 'direct_cost' => 20, 'business_date' => '2026-10-03'], 'complete', $this->context(), 1), 'production_costs');
        $this->assertSame($count, StockMovement::count()); $this->assertEquals(100, $input->fresh()->qty);
        $this->assertEquals(0, $output->fresh()->qty); $this->assertSame('planned', $order->fresh()->status);
        $this->assertSame(0, DB::table('operation_requests')->where('key', 'complete')->count());
    }

    public function test_multi_output_and_recovered_scrap_share_exact_consumed_value(): void
    {
        $input = $this->material(100); $output = $this->material(0); $offcut = $this->material(0); $scrap = $this->material(0);
        $bom = $this->bom($input, $output); $service = app(ProductionService::class);
        $order = $service->plan(['bom_id' => $bom->id, 'warehouse_id' => 1, 'planned_qty' => 10, 'business_date' => '2026-10-03'], 'plan', $this->context(), 1);
        $posted = $service->complete($order->id, ['completed_qty' => 10, 'business_date' => '2026-10-03',
            'outputs' => [['product_id' => $output->id, 'qty' => 10, 'uom_id' => 1, 'cost_weight' => 8],
                ['product_id' => $offcut->id, 'qty' => 5, 'uom_id' => 1, 'cost_weight' => 1]],
            'scrap_outputs' => [['product_id' => $scrap->id, 'qty' => 2, 'uom_id' => 1, 'cost_weight' => 1]]], 'complete', $this->context(), 1);
        $this->assertEquals(100, DB::table('production_outputs')->sum('value'));
        $this->assertEquals(80, StockMovement::find($posted->output_movement_id)->lines->first()->value);
        $this->assertEquals(10, StockMovement::find($posted->scrap_movement_id)->lines->sum('value'));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_bom_versions_cycle_dates_and_foreign_components_fail_without_changes(): void
    {
        $input = $this->material(); $output = $this->material(0); $bom = $this->bom($input, $output);
        $this->rejected(fn () => $this->bom($output, $input), 'cycles');
        $this->rejected(fn () => $bom->update(['output_qty' => 10]), 'immutable');
        $plan = ['bom_id' => $bom->id, 'warehouse_id' => 1, 'planned_qty' => 1, 'business_date' => '2026-10-02'];
        $this->rejected(fn () => app(ProductionService::class)->plan($plan, 'bad-date', $this->context(), 1), 'effective');
        $input->forceFill(['company_id' => $this->other->id])->save();
        $this->rejected(fn () => $this->bom($input, $this->material(0)), 'company-owned');
        $this->assertSame(1, DB::table('boms')->count());
    }

    public function test_textile_500_sent_480_received_and_four_percent_loss_reconcile(): void
    {
        $material = $this->material(500, 2); [$order, $dispatch, $line] = $this->job($material);
        $service = app(JobWorkService::class);
        $this->assertEquals(500, $material->fresh()->qty); $this->assertSame(0, JournalEntry::count());
        $this->assertEquals(500, $service->pending($this->context(), 1)[0]['pending_qty']);
        $data = ['warehouse_id' => 1, 'business_date' => '2026-10-03', 'lines' => [['dispatch_line_id' => $line->id,
            'accepted_qty' => 480, 'rejected_qty' => 0, 'loss_qty' => 20]]];
        $receipt = $service->receive($dispatch->id, $data, 'receive', $this->context(), 1);
        $this->assertEquals(480, $material->fresh()->qty); $this->assertEquals(4, $receipt->details_json['loss_percent']);
        $this->assertSame([], $service->pending($this->context(), 1));
        $this->assertEquals(40, JournalEntry::find($receipt->journal_entry_id)->total_debit);
        $this->assertSame($receipt->id, $service->receive($dispatch->id, $data, 'receive', $this->context(), 1)->id);
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
        $service->reverse('receipt', $receipt->id, '2026-10-03', 'Wrong quantities', $this->context(), 1);
        $this->assertEquals(500, $service->pending($this->context(), 1)[0]['pending_qty']);
        $service->reverse('dispatch', $dispatch->id, '2026-10-03', 'Cancelled', $this->context(), 1);
        $this->assertEquals(500, DB::table('product_warehouse')->where('warehouse_id', 1)->where('product_id', $material->id)->sum('qty'));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_partial_receipts_cannot_over_receive_or_bypass_shrinkage_policy(): void
    {
        $material = $this->material(500); [, $dispatch, $line] = $this->job($material); $service = app(JobWorkService::class);
        $data = ['warehouse_id' => 1, 'business_date' => '2026-10-03', 'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 250, 'rejected_qty' => 0, 'loss_qty' => 0]]];
        $service->receive($dispatch->id, $data, 'part1', $this->context(), 1);
        $this->assertEquals(250, $service->pending($this->context(), 1)[0]['pending_qty']);
        $data['lines'][0]['accepted_qty'] = 260;
        $this->rejected(fn () => $service->receive($dispatch->id, $data, 'over', $this->context(), 1), 'remaining');
        $data['lines'][0]['accepted_qty'] = 220; $data['lines'][0]['loss_qty'] = 30;
        $this->rejected(fn () => $service->receive($dispatch->id, $data, 'loss', $this->context(), 1), 'threshold');
        $this->assertEquals(250, $service->pending($this->context(), 1)[0]['pending_qty']);
    }

    public function test_outsourced_repacking_converts_material_value_without_service_stock(): void
    {
        $material = $this->material(500, 2); $packs = $this->material(0); [, $dispatch, $line] = $this->job($material, 500, true);
        $service = app(JobWorkService::class);
        $receipt = $service->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 480, 'rejected_qty' => 0, 'loss_qty' => 20]],
            'outputs' => [['product_id' => $packs->id, 'qty' => 48, 'cost_weight' => 1]]], 'receive', $this->context(), 1);
        $this->assertEquals(0, $material->fresh()->qty); $this->assertEquals(48, $packs->fresh()->qty);
        $this->assertEquals(960, StockMovement::find($receipt->output_movement_id)->lines->sum('value'));
        $serviceItem = $this->material(0); $serviceItem->forceFill(['type' => 'service'])->save();
        $count = StockMovement::count();
        $billed = $service->serviceBill($receipt->id, ['business_date' => '2026-10-03',
            'items' => [['product_id' => $serviceItem->id, 'qty' => 1, 'net_unit_cost' => 25]]], 'bill', $this->context(), 1);
        // The repacker's charge is conversion cost: one value-only revaluation adds it to the packs, no quantity moves.
        $this->assertNotNull($billed->service_purchase_id); $this->assertSame($count + 1, StockMovement::count());
        $revaluation = StockMovement::where('movement_type', 'revaluation')->firstOrFail();
        $this->assertEquals([0, 25], [$revaluation->lines->sum('qty_base'), $revaluation->lines->sum('value')]);
        $this->assertEquals(985, DB::table('stock_movement_lines')->where('product_id', $packs->id)->sum('value'));
        $this->assertEquals(48, $packs->fresh()->qty);
        $this->assertEquals(25, DB::table('account_open_items')->where('source_type', 'purchase')->sum('original_amount'));
        $this->rejected(fn () => $service->reverse('receipt', $receipt->id, '2026-10-03', 'Undo', $this->context(), 1), 'service bill');
    }

    public function test_service_bill_keeps_the_job_workers_own_name_for_the_material(): void
    {
        $material = $this->material(100, 2); [$order, $dispatch, $line] = $this->job($material, 100, false);
        $service = app(JobWorkService::class);
        $receipt = $service->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]]], 'receive-alias', $this->context(), 1);
        $serviceItem = $this->material(0); $serviceItem->forceFill(['type' => 'service'])->save();
        $billed = $service->serviceBill($receipt->id, ['business_date' => '2026-10-03', 'their_names' => [$material->id => 'Cotton 3200s'],
            'items' => [['product_id' => $serviceItem->id, 'qty' => 100, 'net_unit_cost' => 2]]], 'bill-alias', $this->context(), 1);
        $this->assertStringContainsString('Their name: Cotton 3200s for '.$material->name, DB::table('purchases')->where('id', $billed->service_purchase_id)->value('note'));
        if (\Illuminate\Support\Facades\Schema::hasTable('product_aliases')) $this->assertTrue(DB::table('product_aliases')->where(['product_id' => $material->id, 'party_type' => 'supplier',
            'party_id' => $order->job_worker_party_id, 'name' => 'Cotton 3200s'])->exists(), 'saved as the job worker\'s alternate name');
    }

    public function test_a_wrong_service_bill_is_corrected_by_reversal_and_rebooking(): void
    {
        $material = $this->material(100, 2); [, $dispatch, $line] = $this->job($material, 100, false);
        $service = app(JobWorkService::class);
        $receipt = $service->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]]], 'receive-fix', $this->context(), 1);
        $serviceItem = $this->material(0); $serviceItem->forceFill(['type' => 'service'])->save();
        $bill = fn ($rate) => ['business_date' => '2026-10-03', 'items' => [['product_id' => $serviceItem->id, 'qty' => 100, 'net_unit_cost' => $rate]]];
        $wrong = $service->serviceBill($receipt->id, $bill(3), 'bill-wrong', $this->context(), 1)->service_purchase_id;
        $stockValue = fn () => (float) DB::table('stock_movement_lines')->where('product_id', $material->id)->sum('value');
        $this->assertEquals(500, $stockValue(), '200 material + 300 processing');

        // Edit bill: the wrong bill is reversed with its reason and the corrected one is booked against the same GRN.
        $fixed = $service->rebill($receipt->id, $bill(2) + ['bill_id' => $wrong, 'reason' => 'Agreed rate is 2'], 'bill-fixed', $this->context(), 1)->service_purchase_id;
        $this->assertNotEquals($wrong, $fixed);
        $this->assertNotNull(DB::table('purchases')->where('id', $wrong)->value('reversed_at'));
        $this->assertEquals(400, $stockValue(), 'only the corrected charge stays on the processed stock');
        $this->assertSame($fixed, $service->rebill($receipt->id, $bill(2) + ['bill_id' => $wrong, 'reason' => 'Agreed rate is 2'], 'bill-fixed', $this->context(), 1)->service_purchase_id,
            'resubmitting the same correction changes nothing');

        // Editing it as an ordinary purchase would lose the link, so Purchase Bills refuses it.
        $this->rejected(fn () => app(\App\Services\Commercial\CommercialReversalService::class)->replace(\App\Models\Purchase::findOrFail($fixed), [],
            'replace-jw', '2026-10-03', 'Edit', $this->context()), 'job work order');

        // A plain reversal frees the GRN for a new bill.
        app(\App\Services\Commercial\CommercialReversalService::class)->reverse(\App\Models\Purchase::findOrFail($fixed), '2026-10-03', 'Billed twice', $this->context());
        $this->assertNull($receipt->fresh()->service_purchase_id);
        $this->assertEquals(200, $stockValue());
        $this->assertNotNull($service->serviceBill($receipt->id, $bill(2), 'bill-again', $this->context(), 1)->service_purchase_id);
    }

    public function test_migrations_resume_and_activation_gate_blocks_services(): void
    {
        (require database_path('migrations/2026_10_07_000001_create_manufacturing_and_job_work.php'))->up();
        (require database_path('migrations/2026_10_08_000001_create_industry_profile_extensions.php'))->up();
        config(['operations.enabled' => false]);
        $this->rejected(fn () => app(ProductionService::class)->plan([], 'plan', $this->context(), 1), 'await');
        $this->assertSame(0, DB::table('production_orders')->count());
    }
}
