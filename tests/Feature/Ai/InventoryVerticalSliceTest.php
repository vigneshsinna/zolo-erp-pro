<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Tools\InventoryStockSummary;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class InventoryVerticalSliceTest extends TestCase
{
    use UsesAiMysql;

    protected User $user;
    protected Company $company;
    protected int $branchId;
    protected int $financialYearId;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'AI Inventory Test User',
            'email' => 'ai_inventory_' . uniqid() . '@example.com',
            'phone' => '9999999991',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->actingAs($this->user);

        $this->company = Company::where('status', 'active')->first() ?? Company::first();
        if ($this->company) {
            $this->company->status = 'active';
            $this->company->timezone = 'Asia/Kolkata';
            $this->company->save();
        }

        if (!$this->company->users()->where('users.id', $this->user->id)->exists()) {
            $this->company->users()->attach($this->user->id, ['is_default' => true]);
        }

        $branch = $this->company->branches()->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'is_active' => true]);
        $branch->is_active = true;
        $branch->save();
        $this->branchId = $branch->id;

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $branch->id],
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $branch->id]
        );

        $year = FiscalYear::where('company_id', $this->company->id)->first()
            ?? FiscalYear::create([
                'company_id' => $this->company->id,
                'name' => 'FY 2026',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
            ]);
        $this->financialYearId = $year->id;

        $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->financialYearId,
        ]);

        config(['erp_ai.enabled' => true]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);

        $this->category = Category::where('is_active', true)->first() ?? Category::create([
            'name' => 'General Test Cat ' . uniqid(),
            'is_active' => true,
        ]);
    }

    public function test_erp_copilot_registers_inventory_tool_when_permitted(): void
    {
        $authorizedPrincipal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: ['inventory.stock_summary.v1']
        );

        $copilot = new ErpCopilot($authorizedPrincipal);
        $tools = iterator_to_array($copilot->tools());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(InventoryStockSummary::class, $tools[0]);

        // Principal without permissions
        $unauthorizedPrincipal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: []
        );

        $emptyCopilot = new ErpCopilot($unauthorizedPrincipal);
        $emptyTools = iterator_to_array($emptyCopilot->tools());

        $this->assertCount(0, $emptyTools);
    }

    public function test_inventory_stock_summary_tool_executes_deterministically(): void
    {
        // Create unit and warehouse for company
        $unit = Unit::forceCreate([
            'company_id' => $this->company->id, 'unit_code' => 'PCS',
            'unit_name' => 'Pieces',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::create([
            'name' => 'Test AI Warehouse ' . uniqid(),
            'address' => 'Test Address 123',
            'branch_id' => $this->branchId,
            'is_active' => true,
        ]);
        if (DB::getSchemaBuilder()->hasColumn('warehouses', 'company_id')) {
            $warehouse->company_id = $this->company->id;
            $warehouse->save();
        }

        // Create product
        $product = Product::create([
            'name' => 'AI Test Widget',
            'code' => 'WIDGET-' . uniqid(),
            'barcode_symbology' => 'code128',
            'category_id' => $this->category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'type' => 'standard',
            'cost' => '100.00',
            'price' => '150.00',
            'is_active' => true,
        ]);
        if (DB::getSchemaBuilder()->hasColumn('products', 'company_id')) {
            $product->company_id = $this->company->id;
            $product->save();
        }

        // Add stock in product_warehouse
        DB::table('product_warehouse')->insert([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'qty' => 50,
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: ['inventory.stock_summary.v1'],
            requestId: 'inv-req-001'
        );

        $tool = new InventoryStockSummary($principal);
        $request = new Request(['limit' => 10]);

        $jsonOutput = (string) $tool->handle($request);
        $response = json_decode($jsonOutput, true);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('inventory.stock_summary.v1', $response['tool']);
        $this->assertSame($this->company->id, $response['scope']['company_id']);
        $this->assertArrayHasKey('total_products_tracked', $response['data']);
        $this->assertArrayHasKey('in_stock_count', $response['data']);
        $this->assertArrayHasKey('out_of_stock_count', $response['data']);
        $this->assertArrayHasKey('total_stock_value', $response['data']);
        $this->assertArrayHasKey('units_summary', $response['data']);
        $this->assertArrayHasKey('top_products', $response['data']);
        $this->assertNotEmpty($response['source_refs']);
    }

    public function test_inventory_stock_summary_groups_by_unit_without_mixing(): void
    {
        $codeKg = 'KG_' . rand(1000, 9999);
        $codeMtr = 'MTR_' . rand(1000, 9999);

        $unitKg = Unit::forceCreate(['company_id' => $this->company->id, 'unit_code' => $codeKg, 'unit_name' => 'Kilograms ' . $codeKg, 'is_active' => true]);
        $unitPcs = Unit::forceCreate(['company_id' => $this->company->id, 'unit_code' => $codeMtr, 'unit_name' => 'Meters ' . $codeMtr, 'is_active' => true]);

        $warehouse = Warehouse::create([
            'name' => 'WH ' . uniqid(),
            'address' => 'Test Address 123',
            'branch_id' => $this->branchId,
            'is_active' => true,
        ]);
        if (DB::getSchemaBuilder()->hasColumn('warehouses', 'company_id')) {
            $warehouse->company_id = $this->company->id;
            $warehouse->save();
        }

        $prodKg = Product::create([
            'name' => 'Cotton Yarn', 'code' => 'YARN-' . uniqid(), 'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $this->category->id,
            'unit_id' => $unitKg->id,
            'purchase_unit_id' => $unitKg->id,
            'sale_unit_id' => $unitKg->id,
            'cost' => '200.00', 'price' => '250.00', 'is_active' => true,
        ]);
        $prodKg->company_id = $this->company->id;
        $prodKg->save();

        $prodMtr = Product::create([
            'name' => 'Fabric Roll', 'code' => 'FABRIC-' . uniqid(), 'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $this->category->id,
            'unit_id' => $unitPcs->id,
            'purchase_unit_id' => $unitPcs->id,
            'sale_unit_id' => $unitPcs->id,
            'cost' => '50.00', 'price' => '70.00', 'is_active' => true,
        ]);
        $prodMtr->company_id = $this->company->id;
        $prodMtr->save();

        DB::table('product_warehouse')->insert([
            ['company_id' => $this->company->id, 'product_id' => $prodKg->id, 'warehouse_id' => $warehouse->id, 'qty' => 100],
            ['company_id' => $this->company->id, 'product_id' => $prodMtr->id, 'warehouse_id' => $warehouse->id, 'qty' => 500],
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: ['inventory.stock_summary.v1'],
            requestId: 'inv-req-002'
        );

        $tool = new InventoryStockSummary($principal);
        $jsonOutput = (string) $tool->handle(new Request(['warehouse_id' => $warehouse->id]));
        $response = json_decode($jsonOutput, true);

        $this->assertSame('ok', $response['status']);
        $unitsSummary = collect($response['data']['units_summary'])->keyBy('unit');

        // Confirm separate distinct unit totals
        $this->assertTrue($unitsSummary->has($codeKg));
        $this->assertTrue($unitsSummary->has($codeMtr));
        $this->assertSame(100.0, (float) $unitsSummary[$codeKg]['on_hand']);
        $this->assertSame(500.0, (float) $unitsSummary[$codeMtr]['on_hand']);
    }

    public function test_inventory_stock_summary_denies_unauthorized_principal(): void
    {
        $restrictedUser = User::create([
            'name' => 'Restricted Stock Viewer',
            'email' => 'restricted_stock_' . uniqid() . '@example.com',
            'phone' => '8888888881',
            'password' => bcrypt('secret'),
            'role_id' => 99,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company->users()->attach($restrictedUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $restrictedUser->id,
            'branch_id' => $this->branchId,
        ]);

        $this->actingAs($restrictedUser);

        $factory = app(\App\Ai\Services\AiPrincipalFactory::class);
        $unauthorizedPrincipal = $factory->createForUser(
            userId: $restrictedUser->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId
        );

        $tool = new InventoryStockSummary($unauthorizedPrincipal);
        $jsonOutput = (string) $tool->handle(new Request([]));
        $response = json_decode($jsonOutput, true);

        $this->assertSame('denied', $response['status']);
        $this->assertSame('AI_TOOL_FORBIDDEN', $response['error_code']);
    }

    public function test_inventory_catalog_batches_queries_without_truncating_totals(): void
    {
        $warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'name' => 'Catalog fixture warehouse',
            'address' => 'Fixture address',
            'is_active' => true,
        ]);
        $unit = Unit::forceCreate(['company_id' => $this->company->id, 'unit_code' => 'CAT', 'unit_name' => 'Catalog unit', 'is_active' => true]);
        $prefix = 'AI-CAT-' . uniqid();
        foreach (array_chunk(range(1, 600), 200) as $batch) {
            DB::table('products')->insert(array_map(fn ($i) => [
                'company_id' => $this->company->id,
                'name' => "Catalog product {$i}",
                'code' => "{$prefix}-{$i}",
                'category_id' => $this->category->id,
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => 1,
                'price' => 2,
                'is_active' => true,
            ], $batch));
        }
        $products = Product::where('company_id', $this->company->id)->where('code', 'like', $prefix . '%')->pluck('id');
        DB::table('product_warehouse')->insert($products->map(fn ($id) => [
            'company_id' => $this->company->id, 'product_id' => $id, 'warehouse_id' => $warehouse->id, 'qty' => 2,
        ])->all());

        $principal = new AiPrincipal(
            actorId: $this->user->id, companyId: $this->company->id, branchId: $this->branchId,
            financialYearId: $this->financialYearId, permittedToolNames: ['inventory.stock_summary.v1'],
            requestId: 'inventory-catalog',
        );
        DB::enableQueryLog();
        try {
            $result = json_decode((string) (new InventoryStockSummary($principal))->handle(new Request([
                'product_query' => $prefix, 'warehouse_id' => $warehouse->id, 'limit' => 3,
            ])), true);
            $stockQueries = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/from `(?:product_warehouse|stock_movement_lines|product_sales)`/', $query['query']))->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame('ok', $result['status']);
        $this->assertSame(600, $result['data']['total_products_tracked']);
        $this->assertSame(1200, $result['data']['units_summary'][0]['on_hand']);
        $this->assertCount(3, $result['data']['top_products']);
        $this->assertFalse($result['reconciliation']['is_reconciled']);
        $this->assertNull($result['data']['total_stock_value']);
        $this->assertSame(9, $stockQueries);
    }
}
