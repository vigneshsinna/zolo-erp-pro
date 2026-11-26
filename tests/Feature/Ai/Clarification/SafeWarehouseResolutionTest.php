<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Clarification;

use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Requirements\PurchaseBillRequirementsProvider;
use App\Ai\Services\BatchRecordResolutionService;
use App\Ai\Services\InputCompletenessEvaluator;
use App\Ai\Services\RecordResolutionService;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Task T02 Safe Warehouse Resolution Test Suite:
 * Verifies exact, partial, multiple (ambiguous), inactive, wrong-branch, unknown,
 * cross-tenant, and default warehouse resolution rules.
 */
final class SafeWarehouseResolutionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $companyA;
    private Company $companyB;
    private CompanyBranch $branchA1;
    private CompanyBranch $branchA2;
    private FiscalYear $fiscalYear;
    private RecordResolutionService $resolver;
    private BatchRecordResolutionService $batchResolver;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('erp_ai.enabled', true);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Warehouse Safety Admin',
            'email' => 'wh_safety_' . uniqid() . '@example.com',
            'phone' => '9777777711',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->companyA = Company::create([
            'name' => 'Alpha Textiles Ltd',
            'legal_name' => 'Alpha Textiles Ltd',
            'code' => 'ALPHA_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->companyB = Company::create([
            'name' => 'Beta Logistics Ltd',
            'legal_name' => 'Beta Logistics Ltd',
            'code' => 'BETA_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->branchA1 = CompanyBranch::create([
            'company_id' => $this->companyA->id,
            'name' => 'Alpha Branch One',
            'code' => 'BR-A1',
            'is_active' => true,
        ]);

        $this->branchA2 = CompanyBranch::create([
            'company_id' => $this->companyA->id,
            'name' => 'Alpha Branch Two',
            'code' => 'BR-A2',
            'is_active' => true,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $this->companyA->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branchA1->id]
        );
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false);

        $this->resolver = app(RecordResolutionService::class);
        $this->batchResolver = app(BatchRecordResolutionService::class);
    }

    private function createWarehouse(array $attributes): Warehouse
    {
        return Warehouse::query()->create(array_merge([
            'address' => '123 Industrial Area, Sector 5',
            'is_active' => true,
        ], $attributes));
    }

    private function contextBranchA1(): CompanyContext
    {
        return new CompanyContext($this->companyA->id, $this->branchA1->id, $this->fiscalYear->id);
    }

    /**
     * Test 1: Exact ID lookup resolves the warehouse and reports exact_id reason.
     */
    public function test_exact_numeric_id_lookup_resolves_warehouse(): void
    {
        $wh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Central Spinning Warehouse',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), (string) $wh->id);

        $this->assertSame('exact', $result['status']);
        $this->assertNotNull($result['warehouse']);
        $this->assertSame($wh->id, $result['warehouse']->id);
        $this->assertSame('exact_id', $result['reason'] ?? null);
    }

    /**
     * Test 2: Exact Name lookup resolves the warehouse.
     */
    public function test_exact_name_lookup_resolves_warehouse(): void
    {
        $wh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Knitting Shed Warehouse',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Knitting Shed Warehouse');

        $this->assertSame('exact', $result['status']);
        $this->assertNotNull($result['warehouse']);
        $this->assertSame($wh->id, $result['warehouse']->id);
        $this->assertSame('exact_name', $result['reason'] ?? null);
    }

    public function test_duplicate_exact_matches_require_selection(): void
    {
        $branchWarehouse = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Shared Depot',
        ]);
        $companyWarehouse = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'name' => 'Shared Depot',
        ]);
        $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA2->id,
            'name' => 'Shared Depot',
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Shared Depot');

        $this->assertSame('candidates', $result['status']);
        $this->assertNull($result['warehouse']);
        $this->assertSame([$branchWarehouse->id, $companyWarehouse->id], $result['candidates']->modelKeys());
    }

    /**
     * Test 3: Unique partial match resolves when only one warehouse matches the query.
     */
    public function test_unique_partial_match_resolves_warehouse(): void
    {
        $wh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Ginning Yard Unit 4',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Ginning Yard');

        $this->assertSame('exact', $result['status']);
        $this->assertNotNull($result['warehouse']);
        $this->assertSame($wh->id, $result['warehouse']->id);
        $this->assertSame('unique_partial_match', $result['reason'] ?? null);
    }

    /**
     * Test 4: Ambiguous multiple partial matches NEVER auto-select the first match;
     * returns status 'candidates' with candidate list so the user can choose.
     */
    public function test_multiple_partial_matches_return_candidates_without_silent_auto_select(): void
    {
        $wh1 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Dyeing Mill North Storage',
            'is_active' => true,
        ]);

        $wh2 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Dyeing Mill South Storage',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Dyeing Mill');

        // MUST be candidates, NEVER exact, warehouse MUST be null
        $this->assertSame('candidates', $result['status']);
        $this->assertNull($result['warehouse']);
        $this->assertCount(2, $result['candidates']);
        $this->assertSame('ambiguous_candidates', $result['reason'] ?? null);

        $candidateIds = $result['candidates']->pluck('id')->all();
        $this->assertContains($wh1->id, $candidateIds);
        $this->assertContains($wh2->id, $candidateIds);
    }

    /**
     * Test 5: Inactive warehouses are never resolved.
     */
    public function test_inactive_warehouse_is_never_resolved(): void
    {
        $wh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Decommissioned Dyeing Godown',
            'is_active' => false,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Decommissioned Dyeing Godown');

        $this->assertSame('none', $result['status']);
        $this->assertNull($result['warehouse']);
        $this->assertSame('inactive', $result['reason'] ?? null);
    }

    /**
     * Test 6: Warehouse in another branch is flagged as wrong_branch when context specifies a branch.
     */
    public function test_wrong_branch_warehouse_is_rejected(): void
    {
        $whBranchA2 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA2->id,
            'name' => 'Branch Two Remote Hub',
            'is_active' => true,
        ]);

        // Attempting to resolve under Branch A1 context
        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Branch Two Remote Hub');

        $this->assertSame('none', $result['status']);
        $this->assertNull($result['warehouse']);
        $this->assertSame('wrong_branch', $result['reason'] ?? null);
    }

    /**
     * Test 7: Unknown warehouse returns none with not_found reason.
     */
    public function test_unknown_warehouse_returns_none(): void
    {
        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Completely Unknown Silo 9999');

        $this->assertSame('none', $result['status']);
        $this->assertNull($result['warehouse']);
        $this->assertSame('not_found', $result['reason'] ?? null);
    }

    /**
     * Test 8: Cross-tenant isolation: Warehouse belonging to Company B is never returned for Company A.
     */
    public function test_cross_tenant_isolation_never_returns_other_company_warehouse(): void
    {
        $whCompB = $this->createWarehouse([
            'company_id' => $this->companyB->id,
            'name' => 'Beta Secret Warehouse',
            'is_active' => true,
        ]);

        // Query by exact name under Company A context
        $resultByName = $this->resolver->resolveWarehouse($this->contextBranchA1(), 'Beta Secret Warehouse');
        $this->assertSame('none', $resultByName['status']);
        $this->assertNull($resultByName['warehouse']);

        // Query by numeric ID under Company A context
        $resultById = $this->resolver->resolveWarehouse($this->contextBranchA1(), (string) $whCompB->id);
        $this->assertSame('none', $resultById['status']);
        $this->assertNull($resultById['warehouse']);
    }

    /**
     * Test 9: Unique branch default: when query is null and branch has exactly 1 warehouse, auto-selects it.
     */
    public function test_unique_branch_default_auto_selects_when_single_active_warehouse(): void
    {
        $wh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Single Main Branch Warehouse',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), null);

        $this->assertSame('exact', $result['status']);
        $this->assertNotNull($result['warehouse']);
        $this->assertSame($wh->id, $result['warehouse']->id);
        $this->assertSame('unique_branch_default', $result['reason'] ?? null);
    }

    /**
     * Test 10: Multiple branch warehouses: when query is null and branch has 2+ warehouses,
     * returns status 'candidates' and NEVER auto-selects a random one.
     */
    public function test_multiple_branch_warehouses_return_candidates_when_query_omitted(): void
    {
        $wh1 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Branch Warehouse North',
            'is_active' => true,
        ]);

        $wh2 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Branch Warehouse South',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveWarehouse($this->contextBranchA1(), null);

        $this->assertSame('candidates', $result['status']);
        $this->assertNull($result['warehouse']);
        $this->assertCount(2, $result['candidates']);
        $this->assertSame('multiple_branch_warehouses', $result['reason'] ?? null);
    }

    /**
     * Test 11: PurchaseBillRequirementsProvider validation rejects inactive or wrong-branch warehouse.
     */
    public function test_requirements_provider_rejects_inactive_or_wrong_branch_warehouse(): void
    {
        $provider = app(PurchaseBillRequirementsProvider::class);

        $inactiveWh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Inactive Godown',
            'is_active' => false,
        ]);

        $wrongBranchWh = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA2->id,
            'name' => 'Branch Two Godown',
            'is_active' => true,
        ]);

        $principal = new \App\Ai\DTOs\AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->companyA->id,
            branchId: $this->branchA1->id,
            financialYearId: $this->fiscalYear->id,
        );

        $ctx = new WorkflowTaskContext(
            principal: $principal,
            context: $this->contextBranchA1(),
            mode: 'purchase_bill',
            taskId: 'task-test',
        );

        $errorsInactive = $provider->validate($ctx, ['warehouse_id' => $inactiveWh->id]);
        $this->assertArrayHasKey('warehouse_id', $errorsInactive);
        $this->assertStringContainsString('inactive', $errorsInactive['warehouse_id']);

        $errorsWrongBranch = $provider->validate($ctx, ['warehouse_id' => $wrongBranchWh->id]);
        $this->assertArrayHasKey('warehouse_id', $errorsWrongBranch);
        $this->assertStringContainsString('different branch', $errorsWrongBranch['warehouse_id']);
    }
}
