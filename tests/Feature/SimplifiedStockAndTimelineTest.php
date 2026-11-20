<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Services\Industry\IndustryProfileService;
use App\Services\Industry\SimplifiedStockService;
use App\Services\JobWork\JobWorkService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperationsTestCase;

class SimplifiedStockAndTimelineTest extends OperationsTestCase
{
    private function setupJobWorkFlow(float $opening = 200, float $dispatchQty = 100): array
    {
        app(IndustryProfileService::class)->apply('textile', 'manufacturing', $this->context(), 1);
        if (!\Illuminate\Support\Facades\Schema::hasColumn('products', 'short_description')) {
            \Illuminate\Support\Facades\Schema::table('products', fn ($t) => $t->text('short_description')->nullable());
        }

        $rawMaterial = $this->material($opening, 10, ['name' => 'Raw Yarn 40s']);
        [$order, $dispatch, $line] = $this->job($rawMaterial, $dispatchQty, false);

        $receipt = app(JobWorkService::class)->receive($dispatch->id, [
            'warehouse_id' => 1,
            'business_date' => '2026-10-03',
            'lines' => [[
                'dispatch_line_id' => $line->id,
                'accepted_qty' => $dispatchQty,
                'rejected_qty' => 0,
                'loss_qty' => 0,
            ]],
        ], 'received', $this->context(), 1);

        $charge = $this->material(0);
        $charge->forceFill(['type' => 'service'])->save();

        $data = [
            'business_date' => '2026-10-03',
            'items' => [['product_id' => $charge->id, 'qty' => $dispatchQty, 'net_unit_cost' => 2]],
            'stock_names' => [$rawMaterial->id => 'Dyed Yarn 40s'],
        ];

        $billed = app(JobWorkService::class)->serviceBill($receipt->id, $data, 'test-bill', $this->context(), 1);

        return [$rawMaterial, $order, $dispatch, $receipt, $billed];
    }

    public function test_simplified_stock_report_reconciles_business_flow(): void
    {
        [$rawMaterial] = $this->setupJobWorkFlow(200, 100);

        $service = app(SimplifiedStockService::class);
        $data = $service->simplifiedStock([
            'from' => '2026-10-01',
            'to' => '2026-10-05',
            'group_by' => 'simplified',
        ], $this->context(), 1);

        $this->assertNotEmpty($data['rows']);
        $rawRow = collect($data['rows'])->firstWhere('product_id', $rawMaterial->id);
        $this->assertNotNull($rawRow);

        $this->assertEquals(100, $rawRow->given_to_job_work_qty);
        $this->assertEquals(100, $rawRow->returned_after_job_work_qty);
        $this->assertEquals(100, $rawRow->in_godown_qty);
        $this->assertEquals(100, $rawRow->final_stock_qty);

        // Find converted product 'Dyed Yarn 40s'
        $dyedProduct = Product::forCompany($this->context())->where('name', 'Dyed Yarn 40s')->first();
        $this->assertNotNull($dyedProduct);

        $dyedRow = collect($data['rows'])->firstWhere('product_id', $dyedProduct->id);
        $this->assertNotNull($dyedRow);
        $this->assertEquals(100, $dyedRow->returned_after_job_work_qty);
        $this->assertEquals(100, $dyedRow->in_godown_qty);
        $this->assertEquals(100, $dyedRow->final_stock_qty);

        // Test web endpoint
        $response = $this->get('/inventory-reports/stock-by-lot?group_by=simplified&from=2026-10-01&to=2026-10-05');
        $response->assertOk();
        $response->assertSee('Simplified Business Flow');
        $response->assertSee('Purchased Inward');
        $response->assertSee('Given to Job Work');
        $response->assertSee('Returned after Job Work');
        $response->assertSee('Final Owned Stock');
        $response->assertSee('Raw Yarn 40s');
        $response->assertSee('Dyed Yarn 40s');

        // Test CSV export
        $csvResponse = $this->get('/inventory-reports/stock-by-lot?group_by=simplified&export=csv&from=2026-10-01&to=2026-10-05');
        $csvResponse->assertOk();
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('Purchased Inward', $csvContent);
        $this->assertStringContainsString('Given to Job Work', $csvContent);
        $this->assertStringContainsString('Raw Yarn 40s', $csvContent);
    }

    public function test_inventory_timeline_traces_lifecycle_chronologically(): void
    {
        $this->withoutExceptionHandling();
        [$rawMaterial] = $this->setupJobWorkFlow(200, 100);

        $response = $this->get('/inventory-reports/timeline?product_id='.$rawMaterial->id.'&from=2026-10-01&to=2026-10-05');
        $response->assertOk();
        $response->assertSee('Inventory Traceability Journey');
        $response->assertSee('Raw Yarn 40s');
        $response->assertSee('Given to Job Work');
        $response->assertSee('Returned after Job Work');
        $response->assertSee('Reconciled Final Inventory Balance');
    }

    public function test_multi_tenant_isolation_in_simplified_stock_and_timeline(): void
    {
        [$rawMaterial] = $this->setupJobWorkFlow(200, 100);

        // Switch to foreign company context that actor is not a member of
        $otherCompany = \App\Models\Company::create(['code' => 'OTHR', 'legal_name' => 'Other Co']);
        $foreignContext = new \App\Services\Platform\CompanyContext($otherCompany->id, 1, 1);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $service = app(SimplifiedStockService::class);
        $service->simplifiedStock([
            'from' => '2026-10-01',
            'to' => '2026-10-05',
            'group_by' => 'simplified',
        ], $foreignContext, 1);
    }

    public function test_conversion_lifecycle_tree_builds_nodes_handles_and_branches(): void
    {
        $this->withoutExceptionHandling();
        [$rawMaterial] = $this->setupJobWorkFlow(200, 100);

        $service = app(SimplifiedStockService::class);
        $treeData = $service->conversionLifecycleTree([
            'from' => '2026-10-01',
            'to' => '2026-10-05',
            'group_by' => 'simplified',
        ], $this->context(), 1);

        $this->assertNotEmpty($treeData['nodes']);
        $rawNode = collect($treeData['nodes'])->firstWhere('product_id', $rawMaterial->id);
        $this->assertNotNull($rawNode);
        $this->assertNotEmpty($rawNode['handle']);
        $this->assertNotEmpty($rawNode['branches']);
        $this->assertGreaterThan(0, count($rawNode['branches']));

        // Verify stock-by-lot renders clean navigation link to dedicated Product Lifecycle page
        $response = $this->get('/inventory-reports/stock-by-lot?group_by=simplified&from=2026-10-01&to=2026-10-05');
        $response->assertOk();
        $response->assertSee('Product Lifecycle', false);
        $response->assertSee(route('inventory-reports.lifecycle'), false);
    }

    public function test_dedicated_product_lifecycle_page_renders_branching_tree_and_catalog(): void
    {
        $this->withoutExceptionHandling();
        [$rawMaterial] = $this->setupJobWorkFlow(200, 100);

        $service = app(SimplifiedStockService::class);
        $tree = $service->productLifecycleTree($rawMaterial->id, [
            'from' => '2026-10-01',
            'to' => '2026-10-05',
        ], $this->context(), 1);

        $this->assertEquals(200, $tree['totals']['purchased_inward']);
        $this->assertEquals(100, $tree['totals']['given_to_jobwork']);
        $this->assertEquals(100, $tree['totals']['current_godown_stock']);

        // Verify remaining godown stock branch is a clean terminal leaf in Level 2
        $godownBranch = collect($tree['branches'])->firstWhere('type', 'godown_stock');
        $this->assertNotNull($godownBranch);
        $this->assertEquals(100, $godownBranch['qty']);
        $this->assertEmpty($godownBranch['sub_branches']);

        $response = $this->get('/inventory-reports/lifecycle?product_id='.$rawMaterial->id.'&from=2026-10-01&to=2026-10-05');
        $response->assertOk();
        $response->assertSee('Lifecycle Product Catalog', false);
        $response->assertSee('catalog-count-badge', false);
        $response->assertSee('tree-active-product-badge', false);
        $response->assertSee('Lifecycle & Transformation Branching Tree', false);
        $response->assertSee('PURCHASED INWARD', false);
        $response->assertSee('GIVEN TO JOB WORK', false);
        $response->assertSee('RETURNED / CONVERTED', false);
        $response->assertSee('PROCESS SHRINKAGE', false);
        $response->assertSee('CURRENT GODOWN STOCK', false);
        $response->assertSee('Raw Yarn 40s', false);
        $response->assertSee('Dyed Yarn 40s', false);
        $response->assertSee('PLC:', false);
    }
}
