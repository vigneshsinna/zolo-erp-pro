<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Clarification;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Tools\PreparePurchaseOrder;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class DependentMasterResolutionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Unit $unit;
    private Category $category;
    private int $taxCategoryId;

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
            'name' => 'Master Orchestrator Admin',
            'email' => 'master_admin_' . uniqid() . '@example.com',
            'phone' => '9777777799',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Apex Textiles Ltd',
            'code' => 'APEX_TEXT_' . uniqid(),
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
                'name' => 'Apex Central Warehouse',
                'address' => '42 Ring Road, Tiruppur',
                'is_active' => true,
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
            ]);

        $this->unit = Unit::where('company_id', $this->company->id)->first()
            ?? Unit::forceCreate(['unit_code' => 'MTR', 'unit_name' => 'Meter', 'company_id' => $this->company->id, 'is_active' => true]);

        $this->category = Category::where('company_id', $this->company->id)->first()
            ?? Category::forceCreate(['name' => 'Fabrics', 'company_id' => $this->company->id, 'is_active' => true]);

        $this->taxCategoryId = DB::table('tax_categories')->where('company_id', $this->company->id)->where('name', 'GST 5%')->value('id')
            ?? DB::table('tax_categories')->insertGetId([
                'company_id' => $this->company->id,
                'name' => 'GST 5%',
                'supply_type' => 'taxable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tax_rates')->updateOrInsert(
            ['company_id' => $this->company->id, 'tax_category_id' => $this->taxCategoryId],
            [
                'rate' => 5.0,
                'effective_from' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    protected function tearDown(): void
    {
        config()->set('ai_actions.foundation_enabled', false);
        config()->set('ai_actions.execution_enabled', false);
        parent::tearDown();
    }

    private function gstin(string $state = '33'): string
    {
        return '33AAACC1206D1ZN';
    }

    private function principal(): AiPrincipal
    {
        return new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['purchase_order.create.v1', 'setup.supplier.create.v1', 'setup.product.create.v1', 'setup.warehouse.create.v1'],
            requestId: 'req-dep-' . uniqid(),
        );
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Branch-Id' => (string) $this->branch->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYear->id,
        ];
    }

    private function validGstin(string $state = '33'): string
    {
        return '33AAACC1206D1ZN';
    }

    /**
     * Test 1: Field issue generation produces the Three-Way Disambiguation Triad options.
     */
    public function test_field_issue_generates_three_way_disambiguation_triad(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);

        // Unresolved supplier query "ABC Traders" and unresolved product "Cotton Shirt Blue"
        $responseJson = $tool->handle(new ToolRequest([
            'supplier_query' => 'ABC Traders',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_query' => 'Cotton Shirt Blue', 'qty' => 10, 'net_unit_cost' => 500],
            ],
        ]));

        $response = json_decode($responseJson, true);
        $this->assertSame('needs_input', $response['status']);
        $this->assertNotEmpty($response['task_id']);

        $issues = collect($response['required_inputs']);

        // 1. Supplier Issue
        $supplierIssue = $issues->firstWhere('field', 'supplier_id');
        $this->assertNotNull($supplierIssue);
        $this->assertNotEmpty($supplierIssue['resolution_options']);
        $this->assertCount(3, $supplierIssue['resolution_options']);

        $supplierOptions = collect($supplierIssue['resolution_options']);
        $this->assertNotNull($supplierOptions->firstWhere('key', 'select_existing'));
        $createSupplierOpt = $supplierOptions->firstWhere('key', 'create_new');
        $this->assertNotNull($createSupplierOpt);
        $this->assertTrue($createSupplierOpt['can_create']);
        $this->assertSame('setup.supplier.create.v1', $createSupplierOpt['capability_key']);
        $this->assertSame('ABC Traders', $createSupplierOpt['prefilled_slots']['name'] ?? null);
        $this->assertNotNull($supplierOptions->firstWhere('key', 'correct_name'));

        // 2. Product Line Issue
        $productIssue = $issues->first(fn($i) => str_contains($i['field'], 'items') && str_contains($i['field'], 'product_id'));
        $this->assertNotNull($productIssue);
        $this->assertNotEmpty($productIssue['resolution_options']);
        $this->assertCount(3, $productIssue['resolution_options']);

        $productOptions = collect($productIssue['resolution_options']);
        $createProductOpt = $productOptions->firstWhere('key', 'create_new');
        $this->assertNotNull($createProductOpt);
        $this->assertTrue($createProductOpt['can_create']);
        $this->assertSame('setup.product.create.v1', $createProductOpt['capability_key']);
        $this->assertSame('Cotton Shirt Blue', $createProductOpt['prefilled_slots']['name'] ?? null);
        $this->assertSame(500.0, (float) ($createProductOpt['prefilled_slots']['cost'] ?? 0));
    }

    /**
     * Test 2: Spawning master requirements returns prefilled defaults and tenant options.
     */
    public function test_spawn_master_requirements_returns_prefilled_defaults_and_options(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $responseJson = $tool->handle(new ToolRequest([
            'supplier_query' => 'ABC Traders',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_query' => 'Cotton Shirt Blue', 'qty' => 10, 'net_unit_cost' => 500],
            ],
        ]));
        $task = json_decode($responseJson, true);
        $taskId = $task['task_id'];

        // Spawn supplier requirements
        $resSupplier = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/spawn", [
                'field' => 'supplier_id',
            ]);

        $resSupplier->assertStatus(200);
        $resSupplier->assertJson([
            'task_id' => $taskId,
            'field' => 'supplier_id',
            'entity_type' => 'supplier',
            'capability_key' => 'setup.supplier.create.v1',
            'prefilled' => [
                'name' => 'ABC Traders',
            ],
        ]);

        // Spawn product requirements
        $resProduct = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/spawn", [
                'field' => 'items[line-0].product_id',
            ]);

        $resProduct->assertStatus(200);
        $resProduct->assertJson([
            'task_id' => $taskId,
            'field' => 'items[line-0].product_id',
            'entity_type' => 'product',
            'capability_key' => 'setup.product.create.v1',
        ]);
        $this->assertNotEmpty($resProduct->json('tenant_defaults.units'));
    }

    /**
     * Test 3: Previewing master proposal (Gate 1) prepares sealed Action Proposal linked to parent task.
     */
    public function test_preview_master_proposal_creates_sealed_proposal(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $task = json_decode($tool->handle(new ToolRequest([
            'supplier_query' => 'ABC Traders',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_query' => 'Cotton Shirt Blue', 'qty' => 10],
            ],
        ])), true);
        $taskId = $task['task_id'];

        $supplierData = [
            'name' => 'ABC Traders',
            'company_name' => 'ABC Traders Private Limited',
            'vat_number' => $this->gstin('33'),
            'phone_number' => '9840011223',
            'city' => 'Tiruppur',
        ];

        $res = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'supplier_id',
                'data' => $supplierData,
            ]);

        $res->assertStatus(200);
        $proposal = $res->json('proposal');
        $this->assertNotNull($proposal['id']);
        $this->assertSame('setup.supplier.create.v1', $proposal['action_key']);
        $this->assertSame('pending', $proposal['status']);
        $this->assertSame(1, $proposal['version']);

        // Verify task dependent_masters_json updated
        $taskRow = DB::table('ai_workflow_tasks')->where('id', $taskId)->first();
        $dep = json_decode((string) $taskRow->dependent_masters_json, true);
        $this->assertSame($proposal['id'], $dep['supplier_id']['proposal_id']);
        $this->assertSame('proposal_prepared', $dep['supplier_id']['status']);

        // Zero physical ERP mutations before approval
        $this->assertSame(0, Supplier::where('company_id', $this->company->id)->where('name', 'ABC Traders')->count());
    }

    /**
     * Test 4: Approving and executing master proposal (Gate 1) creates ERP record and automatically resumes parent task.
     */
    public function test_approve_and_execute_master_creates_erp_record_and_resumes_parent_task(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $task = json_decode($tool->handle(new ToolRequest([
            'supplier_query' => 'ABC Traders',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_query' => 'Cotton Shirt Blue', 'qty' => 10],
            ],
        ])), true);
        $taskId = $task['task_id'];
        $initialRevision = (int) $task['task_revision'];

        $supplierData = [
            'name' => 'ABC Traders',
            'company_name' => 'ABC Traders Private Limited',
            'vat_number' => $this->gstin('33'),
            'phone_number' => '9840011223',
            'city' => 'Tiruppur',
        ];

        // 1. Preview
        $previewRes = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'supplier_id',
                'data' => $supplierData,
            ]);
        $previewRes->assertStatus(200);
        $proposalId = $previewRes->json('proposal.id');

        // 2. Approve and Execute (Gate 1)
        $execRes = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'supplier_id',
                'master_proposal_id' => $proposalId,
                'expected_version' => 1,
            ]);

        $execRes->assertStatus(200);
        $execRes->assertJson([
            'status' => 'resumed',
            'parent_task_id' => $taskId,
            'revision' => $initialRevision + 1,
        ]);

        $createdSupplierId = $execRes->json('newly_created.resource_id');
        $this->assertGreaterThan(0, $createdSupplierId);

        // Verify supplier was created in ERP table
        $supplier = Supplier::where('company_id', $this->company->id)->where('id', $createdSupplierId)->first();
        $this->assertNotNull($supplier);
        $this->assertSame('ABC Traders', $supplier->name);

        // Verify parent workflow task now has supplier_id bound and revision incremented
        $resumedTask = DB::table('ai_workflow_tasks')->where('id', $taskId)->first();
        $resumedSlots = json_decode((string) $resumedTask->slots_json, true);
        $this->assertSame($createdSupplierId, $resumedSlots['supplier_id']);
        $this->assertSame($initialRevision + 1, (int) $resumedTask->revision);

        // Verify dependent_masters_json records completed status
        $dep = json_decode((string) $resumedTask->dependent_masters_json, true);
        $this->assertSame('completed', $dep['supplier_id']['status']);
        $this->assertSame($createdSupplierId, $dep['supplier_id']['created_resource_id']);
    }

    /**
     * Test 5: Sequential resolution of all missing records auto-advances task to ready and enables PO preparation (Gate 2).
     */
    public function test_full_sequential_resolution_auto_advances_task_to_ready(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $task = json_decode($tool->handle(new ToolRequest([
            'supplier_query' => 'Star Spinning Mill',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_query' => 'Polyester Thread 5000m', 'qty' => 25, 'net_unit_cost' => 120],
            ],
        ])), true);
        $taskId = $task['task_id'];

        // Step 1: Create Supplier via Gate 1
        $prevSupplierResponse = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'supplier_id',
                'data' => [
                    'name' => 'Star Spinning Mill',
                    'company_name' => 'Star Spinning Mill LLP',
                    'vat_number' => $this->gstin('33'),
                ],
            ]);
        $prevSupplierResponse->assertStatus(200);
        $prevSupplier = $prevSupplierResponse->json('proposal');

        $execSupplier = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'supplier_id',
                'master_proposal_id' => $prevSupplier['id'],
            ]);
        $execSupplier->assertStatus(200);
        $this->assertFalse($execSupplier->json('can_prepare_proposal'), 'Should not be ready yet since product is missing');

        // Step 2: Create Product via Gate 1
        $prevProductResponse = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'items[line-0].product_id',
                'data' => [
                    'name' => 'Polyester Thread 5000m',
                    'code' => 'THRD-POLY-5000',
                    'unit_id' => $this->unit->id,
                    'cost' => 120.0,
                    'price' => 150.0,
                    'category_id' => $this->category->id,
                    'tax_category_id' => $this->taxCategoryId,
                    'hsn_code' => '5205',
                ],
            ]);
        $prevProductResponse->assertStatus(200);
        $prevProduct = $prevProductResponse->json('proposal');

        $execProduct = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'items[line-0].product_id',
                'master_proposal_id' => $prevProduct['id'],
            ]);
        $execProduct->assertStatus(200);

        // Assert parent task completeness is now ready!
        $this->assertTrue($execProduct->json('can_prepare_proposal'));

        $finalTask = DB::table('ai_workflow_tasks')->where('id', $taskId)->first();
        $this->assertSame('ready', $finalTask->status);

        // Step 3: Now prepare the Gate 2 Commercial Purchase Order Proposal
        $poProposalRes = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal");

        $poProposalRes->assertStatus(201);
        $poProposal = $poProposalRes->json('proposal');
        $this->assertSame('purchase_order.create.v1', $poProposal['action_key']);
        $this->assertSame('pending', $poProposal['status']);
    }

    /**
     * Test 6: Security - Execution without permission is blocked.
     */
    public function test_execution_without_permission_is_blocked(): void
    {
        // User without role 1 / permissions
        $guestUser = User::create([
            'name' => 'Guest User',
            'email' => 'guest_' . uniqid() . '@example.com',
            'phone' => '9777777700',
            'password' => bcrypt('secret'),
            'role_id' => 999,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company->users()->syncWithoutDetaching([$guestUser->id => ['is_default' => true]]);

        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $task = json_decode($tool->handle(new ToolRequest([
            'supplier_query' => 'ABC Traders',
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_query' => 'Cotton Shirt', 'qty' => 10]],
        ])), true);
        $taskId = $task['task_id'];

        // Guest attempts to spawn
        $res = $this->actingAs($guestUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/spawn", [
                'field' => 'supplier_id',
            ]);

        $res->assertStatus(403);
    }

    /**
     * Test 7: Warehouse dependent master creation automatically defaults branch_id from active session when omitted.
     */
    public function test_warehouse_creation_automatically_defaults_branch_id_when_omitted(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $task = json_decode($tool->handle(new ToolRequest([
            'supplier_query' => 'ABC Traders',
            'warehouse_query' => 'Main Warehouse',
            'items' => [['product_query' => 'Cotton Shirt', 'qty' => 10]],
        ])), true);
        $taskId = $task['task_id'];

        // Preview warehouse without passing explicit branch_id or address
        $resPreview = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'warehouse_id',
                'data' => [
                    'name' => 'Main Warehouse',
                    'phone' => '98563214785',
                    'address' => 'tamilnadu',
                ],
            ]);

        $resPreview->assertStatus(200);
        $proposal = $resPreview->json('proposal');
        $this->assertNotNull($proposal);
        $this->assertSame('setup.warehouse.create.v1', $proposal['action_key']);
        $payload = json_decode($proposal['payload_json'], true);
        $this->assertSame((int) $this->branch->id, (int) $payload['branch_id']);
        $this->assertSame('Main Warehouse', $payload['name']);

        // Execute master creation
        $resExec = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'warehouse_id',
                'master_proposal_id' => $proposal['id'],
                'expected_version' => 1,
            ]);

        $resExec->assertStatus(200);
        $this->assertSame('warehouse', $resExec->json('newly_created.resource_type'));
        $createdWhId = $resExec->json('newly_created.resource_id');
        $this->assertGreaterThan(0, $createdWhId);

        // Verify task updated with the new warehouse ID
        $taskRecord = DB::table('ai_workflow_tasks')->where('id', $taskId)->first();
        $taskSlots = json_decode($taskRecord->slots_json, true);
        $this->assertSame($createdWhId, $taskSlots['warehouse_id']);
    }

    /**
     * Test 8: Product dependent master creation automatically defaults tax_category_id and hsn_code when omitted.
     */
    public function test_product_creation_automatically_defaults_tax_category_and_hsn_when_omitted(): void
    {
        $supplier = Supplier::create([
            'name' => 'Apex Fabrics Supplier',
            'company_name' => 'Apex Fabrics Pvt Ltd',
            'phone_number' => '9888877777',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);
        $task = json_decode($tool->handle(new ToolRequest([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_query' => 'Linen Shirt White', 'qty' => 5, 'net_unit_cost' => 600]],
        ])), true);
        $taskId = $task['task_id'];

        // Preview product without tax_category_id and hsn_code
        $resPreview = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'items[line-0].product_id',
                'data' => [
                    'name' => 'Linen Shirt White',
                    'code' => 'SKU-LINENWHITE',
                    'unit_id' => $this->unit->id,
                    'cost' => 600,
                    'price' => 600,
                ],
            ]);

        $resPreview->assertStatus(200);
        $proposal = $resPreview->json('proposal');
        $this->assertNotNull($proposal);
        $this->assertSame('setup.product.create.v1', $proposal['action_key']);
        $payload = json_decode($proposal['payload_json'], true);
        $this->assertNotNull($payload['tax_category_id']);
        $this->assertNotNull($payload['hsn_code']);

        // Execute master creation
        $resExec = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'items[line-0].product_id',
                'master_proposal_id' => $proposal['id'],
                'expected_version' => 1,
            ]);

        $resExec->assertStatus(200);
        $createdProductId = $resExec->json('newly_created.resource_id');
        $this->assertGreaterThan(0, $createdProductId);

        $dbProduct = DB::table('products')->where('id', $createdProductId)->first();
        $this->assertNotNull($dbProduct->tax_category_id);
        $this->assertNotNull($dbProduct->hsn_code);

        // Verify that parent task can now prepare proposal without tax errors
        $resProp = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal");

        $resProp->assertStatus(201);
        $this->assertSame('proposal_created', $resProp->json('status'));
    }
}
