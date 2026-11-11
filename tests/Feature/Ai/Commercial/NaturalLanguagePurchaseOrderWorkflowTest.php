<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\RecordResolutionService;
use App\Ai\Tools\PreparePurchaseOrder;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class NaturalLanguagePurchaseOrderWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Supplier $supplierA;
    private Supplier $supplierB;
    private Product $productYarn;
    private Product $productFabric;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'NL PO Admin User',
            'email' => 'nl_po_admin_' . uniqid() . '@example.com',
            'phone' => '9777777781',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Kongu Textiles Ltd',
            'code' => 'KONGU_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->where('code', 'MAIN')->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'branch_type' => 'branch']);

        $this->fiscalYear = $this->company->fiscalYears()->first()
            ?? $this->company->fiscalYears()->create([
                'name' => 'FY 2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
                'is_closed' => false,
            ]);

        $this->company->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branch->id]
        );

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch->id)->first()
            ?? Warehouse::create([
                'name' => 'Spinning Raw Material Store',
                'address' => '54 Cotton Street, Tiruppur',
                'is_active' => true,
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
            ]);

        $this->supplierA = Supplier::create([
            'name' => 'ABC Traders Coimbatore',
            'company_name' => 'ABC Traders Pvt Ltd',
            'vat_number' => '33AAACC1206D1ZN',
            'phone_number' => '9842100011',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->supplierB = Supplier::create([
            'name' => 'ABC Cotton Spinners',
            'company_name' => 'ABC Spinners Ltd',
            'vat_number' => '33AAACL2890A1ZB',
            'phone_number' => '9842100012',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'supplier',
                'party_id' => $this->supplierA->id,
            ],
            [
                'gstin' => '33AAACC1206D1ZN',
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->unit = Unit::where('company_id', $this->company->id)->first()
            ?? Unit::forceCreate(['unit_code' => 'KG', 'unit_name' => 'Kilogram', 'company_id' => $this->company->id, 'is_active' => true]);

        $category = Category::where('company_id', $this->company->id)->first()
            ?? Category::forceCreate(['name' => 'Yarn', 'company_id' => $this->company->id, 'is_active' => true]);

        $taxCategoryId = DB::table('tax_categories')->where('company_id', $this->company->id)->where('name', 'GST 5%')->value('id')
            ?? DB::table('tax_categories')->insertGetId([
                'company_id' => $this->company->id,
                'name' => 'GST 5%',
                'supply_type' => 'taxable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tax_rates')->updateOrInsert(
            ['company_id' => $this->company->id, 'tax_category_id' => $taxCategoryId],
            [
                'rate' => 5.0,
                'effective_from' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->productYarn = Product::forceCreate([
            'name' => 'Combed Organic Cotton Yarn 30s',
            'code' => 'YARN-ORG-30S',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 220.00,
            'price' => 280.00,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'category_id' => $category->id,
            'tax_category_id' => $taxCategoryId,
            'hsn_code' => '5205',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->productFabric = Product::forceCreate([
            'name' => 'Single Jersey Blue Fabric',
            'code' => 'FAB-SJ-BLUE',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 350.00,
            'price' => 450.00,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'category_id' => $category->id,
            'tax_category_id' => $taxCategoryId,
            'hsn_code' => '5205',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        // Register Product Alias
        ProductAlias::firstOrCreate([
            'company_id' => $this->company->id,
            'product_id' => $this->productFabric->id,
            'party_type' => 'company',
            'party_id' => 0,
            'normalized_name' => 'blue fabric',
        ], [
            'name' => 'blue fabric',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Branch-Id' => (string) $this->branch->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYear->id,
        ];
    }

    public function test_record_resolution_service_resolves_supplier_product_and_warehouse(): void
    {
        $resolver = app(RecordResolutionService::class);
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);

        // 1. Exact supplier match by GSTIN
        $resByGstin = $resolver->resolveSupplier($context, '33AAACC1206D1ZN');
        $this->assertSame('exact', $resByGstin['status']);
        $this->assertSame($this->supplierA->id, $resByGstin['supplier']->id);

        // 2. Ambiguous supplier search ('ABC')
        $resAmbiguous = $resolver->resolveSupplier($context, 'ABC');
        $this->assertSame('candidates', $resAmbiguous['status']);
        $this->assertGreaterThanOrEqual(2, count($resAmbiguous['candidates']));

        // 3. Exact product match by code
        $resByCode = $resolver->resolveProduct($context, 'YARN-ORG-30S');
        $this->assertSame('exact', $resByCode['status']);
        $this->assertSame($this->productYarn->id, $resByCode['product']->id);

        // 4. Exact product match by alias ('blue fabric')
        $resByAlias = $resolver->resolveProduct($context, 'blue fabric');
        $this->assertSame('exact', $resByAlias['status']);
        $this->assertSame($this->productFabric->id, $resByAlias['product']->id);

        // 5. Warehouse resolution defaults to active branch warehouse
        $resWarehouse = $resolver->resolveWarehouse($context, null);
        $this->assertSame('exact', $resWarehouse['status']);
        $this->assertSame($this->warehouse->id, $resWarehouse['warehouse']->id);
    }

    public function test_prepare_purchase_order_tool_returns_needs_input_on_ambiguity(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['purchase_order.create.v1'],
            requestId: 'req-test-ambiguity'
        );

        $tool = new PreparePurchaseOrder($principal, app(RecordResolutionService::class));

        // When supplier is ambiguous ('ABC')
        $request = new ToolRequest([
            'supplier_query' => 'ABC',
            'items' => [
                ['product_query' => 'blue fabric', 'qty' => 100],
            ],
        ]);

        $json = json_decode($tool->handle($request), true);
        $this->assertSame('needs_input', $json['status']);
        $this->assertSame('supplier', $json['missing_field']);
        $this->assertStringContainsString('Multiple suppliers matched', $json['message']);
        $this->assertGreaterThanOrEqual(2, count($json['candidates']));

        // When product is unknown
        $requestUnknownProd = new ToolRequest([
            'supplier_query' => 'ABC Traders Coimbatore',
            'items' => [
                ['product_query' => 'non_existent_silk_yarn_xyz', 'qty' => 50],
            ],
        ]);

        $jsonProd = json_decode($tool->handle($requestUnknownProd), true);
        $this->assertSame('needs_input', $jsonProd['status']);
        $this->assertSame('items.0.product', $jsonProd['missing_field']);
        $this->assertStringContainsString('Could not find any product', $jsonProd['message']);
    }

    public function test_prepare_purchase_order_tool_creates_proposal_with_zero_ledger_writes(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['purchase_order.create.v1'],
            requestId: 'req-nl-po-success'
        );

        $tool = new PreparePurchaseOrder($principal, app(RecordResolutionService::class));

        $request = new ToolRequest([
            'supplier_query' => 'ABC Traders Coimbatore',
            'items' => [
                ['product_query' => 'blue fabric', 'qty' => 100, 'net_unit_cost' => 350.00],
            ],
            'note' => 'Order 100 metres of blue fabric from ABC Traders',
        ]);

        $output = $tool->handle($request);
        $res = json_decode($output, true);

        // 1. Tool returns OK fact payload
        $this->assertSame('ok', $res['status']);
        $this->assertSame('purchase_order.create.v1', $res['tool']);
        $this->assertNotEmpty($res['data']['proposal_id']);
        $this->assertSame(1, $res['data']['version']);

        $proposalId = $res['data']['proposal_id'];

        // 2. Verify row created in ai_action_proposals with status 'pending'
        $this->assertDatabaseHas('ai_action_proposals', [
            'id' => $proposalId,
            'company_id' => $this->company->id,
            'action_key' => 'purchase_order.create.v1',
            'status' => 'pending',
            'version' => 1,
        ]);

        // 3. ZERO ERP Purchases created prior to approval
        $this->assertDatabaseMissing('purchases', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierA->id,
        ]);

        // 4. ZERO Stock movements created
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->count());

        // 5. ZERO General ledger / journal entries created
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'reference_type' => 'purchase',
        ]);
    }

    public function test_action_review_center_desk_renders_and_approves_proposal(): void
    {
        $this->actingAs($this->adminUser, 'web');

        // Create a proposal via API
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['purchase_order.create.v1'],
            requestId: 'req-review-desk'
        );
        $tool = new PreparePurchaseOrder($principal, app(RecordResolutionService::class));
        $request = new ToolRequest([
            'supplier_query' => 'ABC Traders Coimbatore',
            'items' => [
                ['product_query' => 'YARN-ORG-30S', 'qty' => 50, 'net_unit_cost' => 220.00],
            ],
        ]);
        $res = json_decode($tool->handle($request), true);
        $proposalId = $res['data']['proposal_id'];

        // 1. Action Review Center desk loads HTTP 200 with pending proposal
        $viewResponse = $this->withHeaders($this->contextHeaders())->get('/ai/action-review');
        $viewResponse->assertStatus(200);
        $viewResponse->assertSee('AI Action Review Center');
        $viewResponse->assertSee('ABC Traders Coimbatore');
        $viewResponse->assertSee('Combed Organic Cotton Yarn 30s');
        $viewResponse->assertSee('Approve & Place Order', false);

        // 2. Approve via API
        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);
        $approveResponse->assertStatus(200);
        $approveResponse->assertJson(['status' => 'completed']);

        // 3. Purchase record now created with status 4 (Ordered) in order register
        $this->assertDatabaseHas('purchases', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierA->id,
            'status' => 4,
        ]);
        $purchase = \App\Models\Purchase::where('company_id', $this->company->id)
            ->where('supplier_id', $this->supplierA->id)
            ->first();

        // 4. Action Review Center now reflects Completed and displays reference number from result_json
        $afterView = $this->withHeaders($this->contextHeaders())->get('/ai/action-review?status=completed');
        $afterView->assertStatus(200);
        $afterView->assertSee('Completed');
        $afterView->assertSee('PO #' . $purchase->reference_no);

        // 5. Non-approver user accessing Review Center is restricted to scope=mine and cannot approve
        DB::table('roles')->updateOrInsert(['id' => 4], ['name' => 'Viewer', 'is_active' => true, 'guard_name' => 'web']);
        $viewer = User::create([
            'name' => 'Review Center Viewer',
            'email' => 'viewer_' . uniqid() . '@example.com',
            'phone' => '9555555551',
            'password' => bcrypt('secret'),
            'role_id' => 4,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->syncWithoutDetaching([$viewer->id => ['is_default' => true]]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $viewer->id, 'branch_id' => $this->branch->id]
        );
        $aiUseId = DB::table('permissions')->where('name', 'ai.use')->value('id');
        if ($aiUseId) {
            DB::table('role_has_permissions')->updateOrInsert(['permission_id' => $aiUseId, 'role_id' => 4]);
        }

        $this->actingAs($viewer, 'sanctum');
        $viewerResponse = $this->withHeaders($this->contextHeaders())->get('/ai/action-review?scope=all');
        $viewerResponse->assertStatus(200);
        $this->assertFalse($viewerResponse->viewData('canApprove'));
        $this->assertSame('mine', $viewerResponse->viewData('scope'));
        $viewerResponse->assertDontSee('Approve & Place Order');
    }
}
