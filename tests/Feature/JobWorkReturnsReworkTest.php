<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryReconciliationService;
use App\Services\JobWork\JobWorkService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperationsTestCase;

/** Job work is one workflow: Material DC out, Job Work GRN in, with returns and rework as actions on those documents. */
class JobWorkReturnsReworkTest extends OperationsTestCase
{
    private function stockIn(int $productId, int $warehouseId): float
    {
        return (float) DB::table('product_warehouse')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->sum('qty');
    }

    private function quarantine(): int
    {
        return DB::table('warehouses')->insertGetId(['name' => 'Quarantine', 'company_id' => $this->company->id,
            'branch_id' => $this->branch->id, 'is_active' => true]);
    }

    public function test_material_dc_return_brings_material_back_unprocessed_without_loss_or_bill(): void
    {
        $grey = $this->material(500, 2); $dyed = $this->material(0);
        [, $dispatch, $line] = $this->job($grey, 500, true);
        $service = app(JobWorkService::class);
        $returned = $service->returnMaterial($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'returned_qty' => 100]]], 'return', $this->context(), 1);

        // A conversion process still returns the grey that was sent: nothing is consumed or converted.
        $this->assertSame('material_return', $returned->receipt_kind);
        $this->assertEquals([100, 0], [$this->stockIn($grey->id, 1), $this->stockIn($dyed->id, 1)]);
        $this->assertEquals(400, $service->pending($this->context(), 1)[0]['pending_qty']);
        $this->assertNull($returned->journal_entry_id);
        $this->assertSame($returned->id, $service->returnMaterial($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'returned_qty' => 100]]], 'return', $this->context(), 1)->id, 'Retry returns the same document.');

        $this->rejected(fn () => $service->returnMaterial($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'returned_qty' => 401]]], 'over-return', $this->context(), 1), 'remaining');
        $serviceItem = $this->material(0); $serviceItem->forceFill(['type' => 'service'])->save();
        $this->rejected(fn () => $service->serviceBill($returned->id, ['business_date' => '2026-10-03',
            'items' => [['product_id' => $serviceItem->id, 'qty' => 1, 'net_unit_cost' => 5]]], 'bill-return', $this->context(), 1), 'no processing charge');
        $this->rejected(fn () => $service->rework($returned->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['product_id' => $grey->id, 'qty' => 10]]], 'rework-return', $this->context(), 1), 'processed GRN');

        // The rest comes back processed, and the DC is settled.
        $service->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 390, 'rejected_qty' => 0, 'loss_qty' => 10]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 390, 'cost_weight' => 1]]], 'receive', $this->context(), 1);
        $this->assertSame([], $service->pending($this->context(), 1));
        $this->assertEquals([100, 390], [$this->stockIn($grey->id, 1), $this->stockIn($dyed->id, 1)]);

        $service->reverse('receipt', $returned->id, '2026-10-03', 'Returned by mistake', $this->context(), 1);
        $this->assertEquals(100, $service->pending($this->context(), 1)[0]['pending_qty']);
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_grn_with_rejected_material_and_no_quarantine_warehouse_says_so(): void
    {
        $grey = $this->material(500, 2);
        [$order, $dispatch, $line] = $this->job($grey, 500, false);
        try {
            app(JobWorkService::class)->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
                'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 499, 'rejected_qty' => 1, 'loss_qty' => 0]]], 'grn-no-quarantine', $this->context(), 1);
            $this->fail('Rejected material without a quarantine warehouse must be refused.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('quarantine warehouse', $e->errors()['rejected_warehouse_id'][0]);
        }
    }

    public function test_grn_rework_sends_material_back_under_the_same_order_and_receives_it(): void
    {
        $grey = $this->material(500, 2); $dyed = $this->material(0); $quarantine = $this->quarantine();
        [$order, $dispatch, $line] = $this->job($grey, 500, true);
        $service = app(JobWorkService::class);
        $grn = $service->receive($dispatch->id, ['warehouse_id' => 1, 'rejected_warehouse_id' => $quarantine, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 440, 'rejected_qty' => 50, 'loss_qty' => 10]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 440, 'cost_weight' => 1]]], 'grn', $this->context(), 1);
        $this->assertSame($quarantine, $grn->details_json['rejected_warehouse_id']);
        $this->assertEquals(50, $this->stockIn($grey->id, $quarantine));

        // Rejected grey goes back to the dyer on a rework DC that references the GRN.
        $reworkDc = $service->rework($grn->id, ['warehouse_id' => $quarantine, 'business_date' => '2026-10-03',
            'lines' => [['product_id' => $grey->id, 'qty' => 50]]], 'rework', $this->context(), 1);
        $this->assertEquals(['rework', $grn->id, $order->id], [$reworkDc->purpose, $reworkDc->rework_of_receipt_id, $reworkDc->job_work_order_id]);
        $this->assertEquals(0, $this->stockIn($grey->id, $quarantine));
        $this->assertEquals(50, $service->pending($this->context(), 1)[0]['pending_qty']);
        $this->rejected(fn () => $service->reverse('receipt', $grn->id, '2026-10-03', 'Undo', $this->context(), 1), 'rework DC');

        // It comes back re-dyed: the rework GRN converts only because outputs were entered.
        $reworkLine = DB::table('job_work_dispatch_lines')->where('dispatch_id', $reworkDc->id)->first();
        $back = $service->receive($reworkDc->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $reworkLine->id, 'accepted_qty' => 50, 'rejected_qty' => 0, 'loss_qty' => 0]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 50, 'cost_weight' => 1]]], 'rework-grn', $this->context(), 1);
        $this->assertNotNull($back->output_movement_id);
        $this->assertEquals([0, 490], [$this->stockIn($grey->id, 1), $this->stockIn($dyed->id, 1)]);
        $this->assertSame([], $service->pending($this->context(), 1));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_reworked_dyed_fabric_comes_back_as_the_same_item(): void
    {
        $grey = $this->material(100, 2); $dyed = $this->material(0);
        [, $dispatch, $line] = $this->job($grey, 100, true);
        $service = app(JobWorkService::class);
        $grn = $service->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 100, 'rejected_qty' => 0, 'loss_qty' => 0]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 100, 'cost_weight' => 1]]], 'grn', $this->context(), 1);
        // Uneven shade: 30 m of the dyed fabric goes back for re-finishing.
        $reworkDc = $service->rework($grn->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['product_id' => $dyed->id, 'qty' => 30]]], 'rework', $this->context(), 1);
        $reworkLine = DB::table('job_work_dispatch_lines')->where('dispatch_id', $reworkDc->id)->first();
        $service->receive($reworkDc->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $reworkLine->id, 'accepted_qty' => 29, 'rejected_qty' => 0, 'loss_qty' => 1]]], 'rework-grn', $this->context(), 1);
        $this->assertEquals(99, $this->stockIn($dyed->id, 1));
        $this->assertSame([], $service->pending($this->context(), 1));
        $this->assertSame([], app(InventoryReconciliationService::class)->differences());
    }

    public function test_workspace_shows_dc_and_grn_actions_in_place(): void
    {
        $grey = $this->material(500, 2); $dyed = $this->material(0);
        [$order, $dispatch, $line] = $this->job($grey, 500, true);
        $this->get('/operations/job-work/'.$order->id)->assertOk()
            ->assertSee('JOB WORK ORDER')->assertSee('+ New Material DC')->assertSee('Return material')->assertSee('Receive Job Work GRN')
            ->assertSee('Send material')->assertSee('Receive and reconcile')->assertSee('Print job work challan (GST)');
        app(JobWorkService::class)->receive($dispatch->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => 500, 'rejected_qty' => 0, 'loss_qty' => 0]],
            'outputs' => [['product_id' => $dyed->id, 'qty' => 500, 'cost_weight' => 1]]], 'grn', $this->context(), 1);
        $this->get('/operations/job-work/'.$order->id)->assertOk()
            ->assertSee('Return / Send for rework')->assertSee('Service bill')->assertSee('Settled')->assertDontSee('Receive Job Work GRN');
        $this->get('/operations/job-work')->assertOk()->assertSee('Material DC (1)')->assertSee('Job Work GRN (1)')->assertSee($dispatch->reference_no);
    }
}
