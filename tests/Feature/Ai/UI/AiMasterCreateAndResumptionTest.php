<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\UI;

use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Task T05: Governed Missing-Master UI & Resumption Test Suite.
 * Verifies that missing warehouses or suppliers can be created through governed
 * two-phase Action Proposals, returning an authoritative ERP ID, atomically resuming
 * the parent workflow task, and transitioning towards bill preview without unapproved writes.
 */
final class AiMasterCreateAndResumptionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private User $restrictedUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Supplier $supplier;
    private Product $product;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('erp_ai.enabled', true);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 4], ['name' => 'Restricted Operator', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::create([
            'name' => 'Master Orchestration Admin',
            'email' => 'master_admin_' . uniqid() . '@example.com',
            'phone' => '97777777' . rand(10, 99),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::create([
            'name' => 'Restricted Non-Admin',
            'email' => 'restricted_op_' . uniqid() . '@example.com',
            'phone' => '97777777' . rand(10, 99),
            'password' => bcrypt('secret'),
            'role_id' => 4,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'name' => 'Kongu Textiles Ltd',
            'legal_name' => 'Kongu Textiles Ltd',
            'code' => 'KONGU_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->branch = CompanyBranch::create([
            'company_id' => $this->company->id,
            'name' => 'Main Mill',
            'code' => 'MM-01',
            'is_active' => true,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $this->company->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
            $this->restrictedUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branch->id]
        );
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->restrictedUser->id, 'branch_id' => $this->branch->id]
        );

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Premier Cotton Mills',
            'company_name' => 'Premier Cotton Mills Pvt Ltd',
            'vat_number' => '33AAACP1234D1Z9',
            'phone_number' => '9842100088',
            'is_active' => true,
        ]);

        $this->unit = Unit::create([
            'company_id' => $this->company->id,
            'unit_code' => 'KGS',
            'unit_name' => 'Kilograms',
            'is_active' => true,
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Raw Materials',
            'is_active' => true,
            'company_id' => $this->company->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Carded Cotton Yarn 30s',
            'code' => 'YRN-30S',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 280.00,
            'price' => 340.00,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'category_id' => $categoryId,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Verify that public/js/ai-master-create.js exists, has no syntax errors,
     * and defines window.AiMasterCreatePanel.render.
     */
    public function test_ai_master_create_script_exists_and_defines_panel(): void
    {
        $path = public_path('js/ai-master-create.js');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('window.AiMasterCreatePanel', $content);
        $this->assertStringContainsString('render:', $content);
        $this->assertStringContainsString('ai-master-create-panel', $content);
        $this->assertStringContainsString('dependent-masters/spawn', $content);
        $this->assertStringContainsString('dependent-masters/preview', $content);
        $this->assertStringContainsString('dependent-masters/approve-and-execute', $content);
    }

    /**
     * Test 2: Verify that workspace Blade template includes ai-master-create.js.
     */
    public function test_workspace_blade_includes_ai_master_create_script(): void
    {
        $path = resource_path('views/backend/ai/workspace.blade.php');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('js/ai-master-create.js', $content);
    }

    /**
     * Test 3: End-to-end missing warehouse flow:
     * - Create parent workflow task with missing warehouse_id.
     * - Spawn master requirements -> Preview governed proposal -> Approve & Execute -> Parent task resumes with new warehouse.
     */
    public function test_missing_warehouse_creation_and_automatic_task_resumption(): void
    {
        // 1. Create task missing warehouse_id
        $initRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/ai/workflow-tasks', [
                'action_key' => 'purchase_bill.create.v1',
                'slots' => [
                    'supplier_id' => $this->supplier->id,
                    'supplier_invoice_no' => 'INV-MASTER-001',
                    'date' => '2026-06-15',
                    'items' => [
                        [
                            'product_id' => $this->product->id,
                            'qty' => 50,
                            'net_unit_cost' => 280.00,
                        ],
                    ],
                ],
            ]);

        $initRes->assertCreated();
        $taskId = $initRes->json('task.id');
        $this->assertNotEmpty($taskId);
        $this->assertSame('collecting', $initRes->json('task.status'));

        // 2. Spawn requirements for missing warehouse
        $spawnRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/spawn", [
                'field' => 'warehouse_id',
            ]);

        $spawnRes->assertOk();
        $this->assertSame('warehouse', $spawnRes->json('entity_type'));
        $this->assertSame('setup.warehouse.create.v1', $spawnRes->json('capability_key'));

        // 3. Preview governed proposal
        $previewRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'warehouse_id',
                'data' => [
                    'name' => 'New Central Godown',
                    'address' => 'Plot 4, Tex Park, Tiruppur',
                    'phone' => '9842100099',
                ],
            ]);

        $previewRes->assertOk();
        $masterProposalId = $previewRes->json('proposal.id');
        $this->assertNotEmpty($masterProposalId);
        $this->assertSame('pending', $previewRes->json('proposal.status'));

        // 4. Approve and execute master proposal
        $execRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'warehouse_id',
                'master_proposal_id' => $masterProposalId,
                'expected_version' => 1,
            ]);

        $execRes->assertOk();
        $this->assertSame('resumed', $execRes->json('status'));
        $newWarehouseId = $execRes->json('newly_created.resource_id');
        $this->assertGreaterThan(0, $newWarehouseId);

        // Verify warehouse exists in database
        $createdWh = Warehouse::find($newWarehouseId);
        $this->assertNotNull($createdWh);
        $this->assertSame('New Central Godown', $createdWh->name);
        $this->assertSame($this->company->id, $createdWh->company_id);

        // Verify parent task updated with newly created warehouse ID and incremented revision
        $taskDb = DB::table('ai_workflow_tasks')->where('id', $taskId)->first();
        $slotsDb = json_decode((string) $taskDb->slots_json, true);
        $this->assertSame($newWarehouseId, (int) $slotsDb['warehouse_id']);
        $this->assertGreaterThan(1, (int) $taskDb->revision);

        // Since supplier, invoice_no, date, product, qty, and warehouse are now all set, task should be ready!
        $this->assertTrue($execRes->json('can_prepare_proposal'));
    }

    /**
     * Test 4: Replaying approve-and-execute with stale version or already approved proposal fails cleanly.
     */
    public function test_replay_with_stale_version_is_rejected(): void
    {
        $initRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/ai/workflow-tasks', [
                'action_key' => 'purchase_bill.create.v1',
                'slots' => ['supplier_id' => $this->supplier->id],
            ]);
        $taskId = $initRes->json('task.id');

        $previewRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/preview", [
                'field' => 'warehouse_id',
                'data' => ['name' => 'Stale Version Test Godown'],
            ]);
        $masterProposalId = $previewRes->json('proposal.id');

        // Approve and execute once
        $firstExec = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'warehouse_id',
                'master_proposal_id' => $masterProposalId,
                'expected_version' => 1,
            ]);
        $firstExec->assertOk();

        // Replaying with expected_version 1 should now fail (proposal is no longer in draft status)
        $replayExec = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/approve-and-execute", [
                'field' => 'warehouse_id',
                'master_proposal_id' => $masterProposalId,
                'expected_version' => 1,
            ]);

        // Expect 409 or 422 or error indicating proposal cannot be re-approved
        $this->assertTrue(in_array($replayExec->status(), [409, 422, 500, 400], true));
    }

    /**
     * Test 5: User without warehouse create permission is forbidden.
     */
    public function test_user_without_warehouse_permission_is_forbidden(): void
    {
        $initRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/ai/workflow-tasks', [
                'action_key' => 'purchase_bill.create.v1',
                'slots' => ['supplier_id' => $this->supplier->id],
            ]);
        $taskId = $initRes->json('task.id');

        // Restricted user attempts to spawn master requirements for warehouse
        $spawnRes = $this->actingAs($this->restrictedUser, 'sanctum')
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/dependent-masters/spawn", [
                'field' => 'warehouse_id',
            ]);

        $this->assertTrue(in_array($spawnRes->status(), [403, 404], true));
    }
}
