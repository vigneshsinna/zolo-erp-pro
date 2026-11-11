<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Setup;

use App\Ai\Adapters\BranchSetupCommandAdapter;
use App\Ai\Adapters\BranchSetupPreviewAdapter;
use App\Ai\Adapters\WarehouseSetupCommandAdapter;
use App\Ai\Adapters\WarehouseSetupPreviewAdapter;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Schemas\CreateBranchInputSchema;
use App\Ai\Schemas\CreateWarehouseInputSchema;
use App\Ai\Services\SetupReadinessService;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BootstrapCompanyCreationTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);

        // Ensure roles exist
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 3], ['name' => 'Operator', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Bootstrap Test Admin',
            'email' => 'admin_bootstrap_' . uniqid() . '@example.com',
            'phone' => '9888888881',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->regularUser = User::where('role_id', 3)->first() ?? User::create([
            'name' => 'Bootstrap Test Operator',
            'email' => 'op_bootstrap_' . uniqid() . '@example.com',
            'phone' => '9888888882',
            'password' => bcrypt('secret'),
            'role_id' => 3,
            'is_active' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_non_admin_cannot_access_bootstrap_api(): void
    {
        $this->actingAs($this->regularUser, 'sanctum');

        $response = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $response->assertStatus(403);
        $response->assertJson(['error_code' => 'BOOTSTRAP_ADMIN_REQUIRED']);
    }

    public function test_bootstrap_preview_does_not_mutate_database(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initResponse = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $initResponse->assertStatus(201);
        $taskId = $initResponse->json('task_id');
        $taskToken = $initResponse->json('task_token');

        $this->assertNotEmpty($taskId);
        $this->assertNotEmpty($taskToken);

        $companiesCount = Company::count();
        $branchesCount = CompanyBranch::count();
        $warehousesCount = Warehouse::count();
        $coaCount = ChartOfAccount::count();

        $previewResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Kovai Tex Ltd',
                'trade_name' => 'Kovai Tex',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);

        $previewResponse->assertStatus(200);
        $previewResponse->assertJson([
            'status' => 'pending',
            'proposal_id' => $taskId,
            'version' => 1,
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $previewResponse->json('payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $previewResponse->json('source_state_hash'));

        // ZERO database writes on core ERP domain tables
        $this->assertSame($companiesCount, Company::count());
        $this->assertSame($branchesCount, CompanyBranch::count());
        $this->assertSame($warehousesCount, Warehouse::count());
        $this->assertSame($coaCount, ChartOfAccount::count());
    }

    public function test_change_request_increments_version_and_updates_preview(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initResponse = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $taskId = $initResponse->json('task_id');
        $taskToken = $initResponse->json('task_token');

        $previewResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Tirupur Garments Ltd',
                'trade_name' => 'Tirupur Garments',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);

        $v1Hash = $previewResponse->json('payload_hash');

        // Apply change request
        $changeResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/change-request", [
                'expected_version' => 1,
                'changes' => [
                    'trade_name' => 'Tirupur Apparel World',
                ],
            ]);

        $changeResponse->assertStatus(200);
        $changeResponse->assertJson([
            'status' => 'pending',
            'version' => 2,
        ]);

        $v2Hash = $changeResponse->json('payload_hash');
        $this->assertNotSame($v1Hash, $v2Hash);

        // Attempting to edit stale version 1 fails
        $staleResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/change-request", [
                'expected_version' => 1,
                'changes' => ['trade_name' => 'Another Name'],
            ]);

        $staleResponse->assertStatus(422);
    }

    public function test_tampered_bootstrap_approval_rejected(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initResponse = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $taskId = $initResponse->json('task_id');
        $taskToken = $initResponse->json('task_token');

        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Erode Silks Ltd',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);

        // Submit altered/fake signature
        $approveResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
                'approval_signature' => str_repeat('f', 64),
            ]);

        $approveResponse->assertStatus(403);
    }

    public function test_installation_admin_creates_company_atomically(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initResponse = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $taskId = $initResponse->json('task_id');
        $taskToken = $initResponse->json('task_token');

        $uniqueLegal = 'Salem Handlooms ' . uniqid();
        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => $uniqueLegal,
                'trade_name' => 'Salem Handlooms',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);

        $approveResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $approveResponse->assertJson([
            'status' => 'completed',
            'transition_action' => 'switch_context',
            'context_switch_url' => '/select-company',
            'workspace_url' => '/ai',
        ]);

        $companyId = (int) $approveResponse->json('company_id');
        $this->assertGreaterThan(0, $companyId);

        // Verify atomic provisioning of all resources
        $company = Company::find($companyId);
        $this->assertNotNull($company);
        $this->assertSame($uniqueLegal, $company->legal_name);

        // MAIN branch
        $branch = $company->branches()->where('code', 'MAIN')->first();
        $this->assertNotNull($branch);

        // Fiscal year
        $fy = $company->fiscalYears()->first();
        $this->assertNotNull($fy);
        $this->assertSame('open', $fy->status);

        // Main warehouse
        $warehouse = Warehouse::where('company_id', $companyId)->where('branch_id', $branch->id)->first();
        $this->assertNotNull($warehouse);

        // Chart of accounts
        $coaCount = ChartOfAccount::where('company_id', $companyId)->count();
        $this->assertGreaterThan(0, $coaCount);

        // Setup audit row
        $auditExists = DB::table('company_setup_audits')->where('company_id', $companyId)->where('action', 'company_created')->exists();
        $this->assertTrue($auditExists);

        // Idempotent retry returns identical receipt without creating duplicate company
        $companiesCount = Company::count();
        $retryResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);

        $retryResponse->assertStatus(200);
        $this->assertSame($companyId, (int) $retryResponse->json('company_id'));
        $this->assertSame($companiesCount, Company::count());

        // Mode B: Evaluate Setup Readiness
        $context = new CompanyContext($companyId, $branch->id, $fy->id);
        $readinessService = app(SetupReadinessService::class);
        $readiness = $readinessService->evaluate($context, $this->adminUser->id);

        $this->assertSame($companyId, $readiness['company_id']);
        $this->assertTrue($readiness['is_ready_for_billing']);
        $this->assertGreaterThanOrEqual(60, $readiness['readiness_score']);
        $this->assertArrayHasKey('main_branch', $readiness['checklist']);
        $this->assertTrue($readiness['checklist']['main_branch']['passed']);
        $this->assertTrue($readiness['checklist']['chart_of_accounts']['passed']);

        // Mode B: Create additional Branch and Warehouse
        $branchPreviewAdapter = app(BranchSetupPreviewAdapter::class);
        $branchCommandAdapter = app(BranchSetupCommandAdapter::class);

        $branchDef = new ActionDefinition(
            key: 'setup.branch.create.v1',
            businessModule: 'branch_setup',
            riskTier: 'r2_operational',
            requiredPermissions: ['ai.use'],
            inputSchemaClass: CreateBranchInputSchema::class,
            previewAdapterClass: BranchSetupPreviewAdapter::class,
            commandAdapterClass: BranchSetupCommandAdapter::class,
            domainOwnsTransaction: true,
            requiresApproval: true,
            enabledByDefault: false,
            mode: 'company',
        );

        $branchPreview = $branchPreviewAdapter->preview($branchDef, ['code' => 'WEAVING', 'name' => 'Weaving Unit'], $context);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $branchPreview->sourceStateHash);

        $branchReceipt = $branchCommandAdapter->execute(
            $branchDef,
            $branchPreview->payload,
            $context,
            $this->adminUser->id,
            'test-idemp-branch-' . uniqid(),
            $branchPreview->sourceStateHash,
        );

        $this->assertSame('branch', $branchReceipt->resourceType);
        $newBranchId = $branchReceipt->resourceId;
        $this->assertGreaterThan(0, $newBranchId);

        // Additional Warehouse under new branch
        $whDef = new ActionDefinition(
            key: 'setup.warehouse.create.v1',
            businessModule: 'warehouse_setup',
            riskTier: 'r2_operational',
            requiredPermissions: ['ai.use'],
            inputSchemaClass: CreateWarehouseInputSchema::class,
            previewAdapterClass: WarehouseSetupPreviewAdapter::class,
            commandAdapterClass: WarehouseSetupCommandAdapter::class,
            domainOwnsTransaction: true,
            requiresApproval: true,
            enabledByDefault: false,
            mode: 'company',
        );

        $whPreviewAdapter = app(WarehouseSetupPreviewAdapter::class);
        $whCommandAdapter = app(WarehouseSetupCommandAdapter::class);

        $whPreview = $whPreviewAdapter->preview($whDef, [
            'branch_id' => $newBranchId,
            'name' => 'Yarn Warehouse',
            'address' => 'Plot 42, Weaving Sector',
        ], $context);

        $whReceipt = $whCommandAdapter->execute(
            $whDef,
            $whPreview->payload,
            $context,
            $this->adminUser->id,
            'test-idemp-wh-' . uniqid(),
            $whPreview->sourceStateHash,
        );

        $this->assertSame('warehouse', $whReceipt->resourceType);
        $this->assertGreaterThan(0, $whReceipt->resourceId);
    }

    public function test_kill_switch_disables_bootstrap_endpoints(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Test foundation kill switch
        config()->set('ai_actions.foundation_enabled', false);

        $initResponse = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $initResponse->assertStatus(403);
        $this->assertSame('AI_ACTIONS_DISABLED', $initResponse->json('error_code'));

        // Re-enable foundation, disable execution switch
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', false);

        $task = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $task->assertStatus(201);
        $taskId = $task->json('task_id');
        $taskToken = $task->json('task_token');

        $preview = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Kill Switch Company Ltd',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);
        $preview->assertStatus(200);

        // Approve must fail closed when execution is disabled
        $approve = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);
        $approve->assertStatus(403);
        $this->assertSame('AI_ACTIONS_DISABLED', $approve->json('error_code'));
    }

    public function test_bootstrap_proposal_creation_prevents_unscoped_task_hijack(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $task = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $task->assertStatus(201);
        $taskId = $task->json('task_id');
        $taskToken = $task->json('task_token');

        // Admin 1 previews and sets up proposal
        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Legit Company Ltd',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ])->assertStatus(200);

        // Another admin user attempts to overwrite or approve Admin 1's task
        $admin2 = User::create([
            'name' => 'Second Admin User',
            'email' => 'admin2_' . uniqid() . '@example.com',
            'phone' => '9888888889',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->actingAs($admin2, 'sanctum');

        // Attempting to overwrite Admin 1's task with foreign task token or foreign session
        $hijackAttempt = $this->withHeader('X-Setup-Task-Token', 'foreign_token_' . str_repeat('a', 50))
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Hijacked Company Ltd',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);

        $hijackAttempt->assertStatus(403);
    }

    public function test_bootstrap_retry_prevents_receipt_leak_to_unauthorized_actor(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $task = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $taskId = $task->json('task_id');
        $taskToken = $task->json('task_token');

        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Receipt Leak Test Co ' . uniqid(),
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ])->assertStatus(200);

        $approve = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);
        $approve->assertStatus(200);
        $companyId = $approve->json('company_id');
        $this->assertGreaterThan(0, $companyId);

        // Another admin attempts to probe the completed task ID to steal receipt info
        $admin2 = User::create([
            'name' => 'Snooping Admin User',
            'email' => 'admin_snoop_' . uniqid() . '@example.com',
            'phone' => '9888888888',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->actingAs($admin2, 'sanctum');

        $snoopAttempt = $this->withHeader('X-Setup-Task-Token', 'foreign_token_' . str_repeat('b', 50))
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);

        // Must reject with 403 rather than returning the existing company creation receipt
        $snoopAttempt->assertStatus(403);
    }

    public function test_installation_admin_creates_company_group(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $task = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $task->assertStatus(201);
        $taskId = $task->json('task_id');
        $taskToken = $task->json('task_token');

        $uniqueGroupName = 'Heritage Textiles Conglomerate ' . uniqid();
        $previewResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'action_key' => 'setup.group.create.v1',
                'slots' => [
                    'name' => $uniqueGroupName,
                ],
            ]);

        $previewResponse->assertStatus(200);
        $previewResponse->assertJson([
            'status' => 'pending',
            'action_key' => 'setup.group.create.v1',
            'version' => 1,
            'preview' => [
                'entity' => 'Company Group',
                'name' => $uniqueGroupName,
            ],
        ]);

        $approveResponse = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $approveResponse->assertJson([
            'status' => 'completed',
            'receipt' => [
                'resource_type' => 'company_group',
            ],
        ]);

        $groupId = (int) $approveResponse->json('receipt.details.group_id');
        $this->assertGreaterThan(0, $groupId);

        $group = \App\Models\CompanyGroup::find($groupId);
        $this->assertNotNull($group);
        $this->assertSame($uniqueGroupName, $group->name);

        $isAdmin = DB::table('company_group_admins')
            ->where('company_group_id', $groupId)
            ->where('user_id', $this->adminUser->id)
            ->exists();
        $this->assertTrue($isAdmin);
    }

    public function test_completed_bootstrap_task_proposal_is_immutable_and_cannot_be_recreated(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $task = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $taskId = $task->json('task_id');
        $taskToken = $task->json('task_token');

        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Immutable Co ' . uniqid(),
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ])->assertStatus(200);

        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ])->assertStatus(200);

        // Attempting to generate a new preview on an already completed task must fail closed (Finding 4)
        $recreateAttempt = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => 'Should Not Overwrite',
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ]);

        $recreateAttempt->assertStatus(422);
        $this->assertStringContainsString('finalized and cannot be overwritten', (string) $recreateAttempt->json('message'));
    }

    public function test_company_creation_safely_reconciles_pre_committed_erp_company(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $directory = app(\App\Services\Platform\CompanyDirectoryService::class);
        $uniqueCode = 'RECON_' . rand(1000, 9999);
        $uniqueName = 'Reconciliation Test Mills ' . uniqid();

        // Simulate that CompanyDirectoryService committed the company to the database
        // but an artificial network drop or crash prevented recording the AI completed receipt
        $company = $directory->createCompany($this->adminUser->id, [
            'legal_name' => $uniqueName,
            'code' => $uniqueCode,
            'industry' => 'textile',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
            'fy_start' => '2026-04-01',
            'fy_end' => '2027-03-31',
        ]);
        $this->assertNotNull($company);

        // Now an AI bootstrap proposal with the same company payload attempts to execute/recover
        $task = $this->postJson('/api/v1/ai/setup/bootstrap/tasks');
        $taskId = $task->json('task_id');
        $taskToken = $task->json('task_token');

        // Preview creates the proposal row matching the committed company
        $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/preview", [
                'legal_name' => $uniqueName,
                'code' => $uniqueCode,
                'industry' => 'textile',
                'state_code' => '33',
                'fy_start' => '2026-04-01',
                'fy_end' => '2027-03-31',
            ])->assertStatus(200);

        // When approving, the safe domain reconciler should recognize the committed ERP company
        // and return the completed receipt without failing with duplicate code or creating duplicate records (Finding 3)
        $approve = $this->withHeader('X-Setup-Task-Token', $taskToken)
            ->postJson("/api/v1/ai/setup/bootstrap/tasks/{$taskId}/approve", [
                'expected_version' => 1,
            ]);

        $approve->assertStatus(200);
        $this->assertSame((int) $company->id, (int) $approve->json('company_id'));
        $this->assertTrue((bool) $approve->json('receipt.details.reconciled_from_erp'));
    }
}

