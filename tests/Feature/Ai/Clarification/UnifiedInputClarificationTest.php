<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Clarification;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Services\TaskCorrectionService;
use App\Ai\Services\WorkflowTaskStateService;
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

final class UnifiedInputClarificationTest extends TestCase
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
            'name' => 'Clarification Admin User',
            'email' => 'clarification_admin_' . uniqid() . '@example.com',
            'phone' => '9777777782',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Kongu Spinning & Weaving Ltd',
            'code' => 'KONGU_CLARIFY_' . uniqid(),
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
                'name' => 'Main Tiruppur Warehouse',
                'address' => '100 Mill Road, Tiruppur',
                'is_active' => true,
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
            ]);

        $this->supplierA = Supplier::create([
            'name' => 'ABC Spinning Mills',
            'company_name' => 'ABC Spinning Mills Pvt Ltd',
            'vat_number' => '33AAACC1206D1ZN',
            'phone_number' => '9842100091',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->supplierB = Supplier::create([
            'name' => 'ABC Cotton Traders',
            'company_name' => 'ABC Cotton Traders Ltd',
            'vat_number' => '33AAACT5678B1Z2',
            'phone_number' => '9842100092',
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
            ?? Category::forceCreate(['name' => 'Textiles', 'company_id' => $this->company->id, 'is_active' => true]);

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
            'name' => 'Cotton Combed Yarn 40s',
            'code' => 'YARN-COMB-40S',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 240.00,
            'price' => 300.00,
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
            'name' => 'Organic Grey Fabric 240 GSM',
            'code' => 'FAB-ORG-240',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 320.00,
            'price' => 420.00,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'category_id' => $category->id,
            'tax_category_id' => $taxCategoryId,
            'hsn_code' => '5208',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        ProductAlias::firstOrCreate([
            'company_id' => $this->company->id,
            'product_id' => $this->productFabric->id,
            'party_type' => 'company',
            'party_id' => 0,
            'normalized_name' => 'grey fabric',
        ], [
            'name' => 'grey fabric',
            'created_by' => $this->adminUser->id,
        ]);
    }

    protected function tearDown(): void
    {
        config()->set('ai_actions.foundation_enabled', false);
        config()->set('ai_actions.execution_enabled', false);
        parent::tearDown();
    }

    private function principal(): AiPrincipal
    {
        return new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['purchase_order.create.v1'],
            requestId: 'req-clarify-' . uniqid(),
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

    /**
     * IC-01: Full-pass resolution returns one consolidated response instead of stopping on first missing entity.
     */
    public function test_unresolved_supplier_product_and_warehouse_return_one_consolidated_clarification(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);

        // Ambiguous supplier ("ABC" matches suppliers), invalid warehouse ("Nonexistent"),
        // Line 0 has exact product code ("FAB-ORG-240") and quantity, Line 1 has unknown product ("Unknown Wool")
        $responseJson = $tool->handle(new ToolRequest([
            'supplier_query' => 'ABC',
            'warehouse_query' => 'Nonexistent Facility',
            'items' => [
                ['product_query' => 'FAB-ORG-240', 'qty' => 50],
                ['product_query' => 'Unknown Wool', 'qty' => 20],
            ],
        ]));

        $response = json_decode($responseJson, true);

        $this->assertSame('needs_input', $response['status']);
        $this->assertNotEmpty($response['task_id']);
        $this->assertSame(2, $response['task_revision']);

        // Check required_inputs (Blocking Issues): Supplier ambiguous, warehouse invalid, line 1 product missing
        $issues = collect($response['required_inputs']);
        $supplierIssue = $issues->firstWhere('field', 'supplier_id');
        $this->assertNotNull($supplierIssue);
        $this->assertSame('ambiguous', $supplierIssue['status']);
        $this->assertGreaterThanOrEqual(2, count($supplierIssue['candidates']));

        $warehouseIssue = $issues->firstWhere('field', 'warehouse_id');
        $this->assertNotNull($warehouseIssue);
        $this->assertSame('missing', $warehouseIssue['status']);

        $line1ProductIssue = $issues->first(fn ($i) => str_contains($i['field'], 'items') && str_contains($i['field'], 'product_id'));
        $this->assertNotNull($line1ProductIssue);
        $this->assertSame('missing', $line1ProductIssue['status']);

        // Check confirmed_fields: Line 0 product was resolved, quantities are confirmed
        $confirmed = collect($response['confirmed_fields']);
        $this->assertTrue($confirmed->contains(fn ($cf) => str_contains($cf['field'], 'product_id')));
        $this->assertTrue($confirmed->contains(fn ($cf) => str_contains($cf['field'], 'qty') && str_contains($cf['display'], '50.00')));
        $this->assertTrue($confirmed->contains(fn ($cf) => str_contains($cf['field'], 'qty') && str_contains($cf['display'], '20.00')));

        // Pending checks exist
        $this->assertNotEmpty($response['pending_checks']);

        // Zero physical ERP mutations
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('journal_entries')->where('company_id', $this->company->id)->count());

        // Durable workflow task was created
        $this->assertDatabaseHas('ai_workflow_tasks', [
            'id' => $response['task_id'],
            'company_id' => $this->company->id,
            'status' => 'collecting',
            'revision' => 2,
        ]);
    }

    /**
     * IC-02: Missing supplier retains confirmed product and warehouse.
     */
    public function test_missing_supplier_retains_confirmed_product_and_warehouse(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);

        $responseJson = $tool->handle(new ToolRequest([
            'supplier_query' => null,
            'warehouse_query' => $this->warehouse->name,
            'items' => [
                ['product_query' => 'YARN-COMB-40S', 'qty' => 100],
            ],
        ]));

        $response = json_decode($responseJson, true);

        $this->assertSame('needs_input', $response['status']);

        $issues = collect($response['required_inputs']);
        $this->assertCount(1, $issues);
        $this->assertSame('supplier_id', $issues->first()['field']);

        $confirmed = collect($response['confirmed_fields']);
        $this->assertTrue($confirmed->contains(fn ($cf) => $cf['field'] === 'warehouse_id'));
        $this->assertTrue($confirmed->contains(fn ($cf) => str_contains($cf['field'], 'product_id')));

        $this->assertContains('Authoritative GST determination and party tax calculation', $response['pending_checks']);
    }

    /**
     * IC-04: Unique product code, exact supplier and default warehouse auto-resolve without questions.
     */
    public function test_unique_product_code_and_warehouse_default_auto_resolve_without_questions(): void
    {
        $tool = app(PreparePurchaseOrder::class, ['principal' => $this->principal()]);

        $responseJson = $tool->handle(new ToolRequest([
            'supplier_query' => '33AAACC1206D1ZN', // Exact GSTIN
            'warehouse_query' => null, // Default warehouse
            'items' => [
                ['product_query' => 'FAB-ORG-240', 'qty' => 200, 'net_unit_cost' => 320.00],
            ],
            'date' => '2026-06-15',
        ]));

        $response = json_decode($responseJson, true);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('purchase_order.create.v1', $response['tool']);
        $this->assertArrayHasKey('proposal_id', $response['data']);
        $this->assertSame(1, $response['data']['version']);

        // Zero physical ERP mutations
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());

        // Linked proposal on workflow task
        $this->assertDatabaseHas('ai_workflow_tasks', [
            'id' => $response['data']['task_id'],
            'linked_proposal_id' => $response['data']['proposal_id'],
        ]);
    }

    /**
     * IC-13: Patching inputs with expected revision advances state and detects concurrency conflict.
     */
    public function test_patching_inputs_with_expected_revision_updates_state_and_detects_conflict(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Create a task via REST API with ambiguous supplier
        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_query' => 'ABC',
                'items' => [
                    ['product_query' => 'YARN-COMB-40S', 'qty' => 50],
                ],
            ],
        ], $this->contextHeaders());

        $initResponse->assertStatus(201);
        $taskId = $initResponse->json('task.id');
        $this->assertSame(2, $initResponse->json('task.revision'));
        $this->assertFalse($initResponse->json('evaluation.is_ready'));

        // 2. Patch with correct expected_revision = 2
        $patchResponse = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
            'expected_revision' => 2,
            'corrections' => [
                [
                    'field' => 'supplier_id',
                    'value' => $this->supplierA->id,
                    'confirmation' => 'user_selected',
                ],
            ],
        ], $this->contextHeaders());

        $patchResponse->assertStatus(200);
        $this->assertSame(3, $patchResponse->json('task.revision'));
        $this->assertTrue($patchResponse->json('evaluation.is_ready'));

        // 3. Attempt patch with STALE expected_revision = 2 -> should fail with 409 Conflict
        $staleResponse = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
            'expected_revision' => 2,
            'corrections' => [
                [
                    'field' => 'supplier_id',
                    'value' => $this->supplierB->id,
                ],
            ],
        ], $this->contextHeaders());

        $staleResponse->assertStatus(409);
        $staleResponse->assertJsonFragment([
            'error_code' => 'CONCURRENT_TASK_MODIFICATION',
        ]);
    }

    /**
     * IC-15: Proposal is prepared only when 100% ready, retaining Zero ERP Writes before approval.
     */
    public function test_proposal_prepared_only_when_100_percent_ready_with_zero_erp_writes(): void
    {
        $this->actingAs($this->adminUser);

        // Create incomplete task
        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_query' => 'ABC', // Ambiguous
                'items' => [
                    ['product_query' => 'YARN-COMB-40S', 'qty' => 50],
                ],
            ],
        ], $this->contextHeaders());

        $taskId = $initResponse->json('task.id');

        // Attempting to prepare proposal when not ready returns 422
        $prematureResponse = $this->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal", [], $this->contextHeaders());
        $prematureResponse->assertStatus(422);
        $prematureResponse->assertJsonFragment(['status' => 'needs_input']);

        // Patch ambiguous supplier
        $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
            'expected_revision' => 2,
            'corrections' => [
                ['field' => 'supplier_id', 'value' => $this->supplierA->id, 'confirmation' => 'user_selected'],
            ],
        ], $this->contextHeaders())->assertStatus(200);

        // Prepare proposal now succeeds
        $prepareResponse = $this->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal", [], $this->contextHeaders());
        $prepareResponse->assertStatus(201);
        $prepareResponse->assertJsonFragment(['status' => 'proposal_created']);
        $proposalId = $prepareResponse->json('proposal.id');
        $this->assertNotEmpty($proposalId);

        // Zero physical ERP writes
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('journal_entries')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
    }

    /**
     * IC-25: Task cancel terminates workflow without ERP writes.
     */
    public function test_task_cancel_terminates_without_erp_writes(): void
    {
        $this->actingAs($this->adminUser);

        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_query' => 'ABC Spinning Mills',
                'items' => [
                    ['product_query' => 'YARN-COMB-40S', 'qty' => 50],
                ],
            ],
        ], $this->contextHeaders());

        $taskId = $initResponse->json('task.id');

        // Cancel task
        $cancelResponse = $this->postJson("/api/v1/ai/workflow-tasks/{$taskId}/cancel", [], $this->contextHeaders());
        $cancelResponse->assertStatus(200);
        $cancelResponse->assertJsonFragment(['status' => 'cancelled']);

        $this->assertDatabaseHas('ai_workflow_tasks', [
            'id' => $taskId,
            'status' => 'cancelled',
        ]);

        // Attempting to patch cancelled task should fail
        $patchResponse = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
            'expected_revision' => 2,
            'corrections' => [
                ['field' => 'note', 'value' => 'Trying to patch'],
            ],
        ], $this->contextHeaders());

        $patchResponse->assertStatus(422);

        // Zero physical ERP writes
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
    }

    /**
     * Cross-tenant isolation prevents accessing tasks across company boundaries.
     */
    public function test_cross_tenant_isolation_prevents_accessing_other_company_tasks(): void
    {
        $this->actingAs($this->adminUser);

        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_query' => 'ABC Spinning Mills',
                'items' => [
                    ['product_query' => 'YARN-COMB-40S', 'qty' => 50],
                ],
            ],
        ], $this->contextHeaders());

        $taskId = $initResponse->json('task.id');

        // Create a different company & user
        $companyOther = Company::create([
            'name' => 'Other Textiles Ltd',
            'legal_name' => 'Other Textiles Ltd',
            'code' => 'OTHER_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($companyOther->id, true, false);
        $branchOther = $companyOther->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'branch_type' => 'branch']);
        $fyOther = $companyOther->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        $userOther = User::create([
            'name' => 'Other User',
            'email' => 'other_' . uniqid() . '@example.com',
            'phone' => '9777777783',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $companyOther->users()->syncWithoutDetaching([$userOther->id => ['is_default' => true]]);
        DB::table('company_user_branches')->insert([
            'company_id' => $companyOther->id,
            'user_id' => $userOther->id,
            'branch_id' => $branchOther->id,
        ]);

        $this->actingAs($userOther);

        // Attempting to show or patch company A's task as company B user fails
        $headersOther = [
            'X-Company-Id' => (string) $companyOther->id,
            'X-Branch-Id' => (string) $branchOther->id,
            'X-Financial-Year-Id' => (string) $fyOther->id,
        ];

        $forbiddenShow = $this->getJson("/api/v1/ai/workflow-tasks/{$taskId}", $headersOther);
        $forbiddenShow->assertStatus(403);
    }
}
