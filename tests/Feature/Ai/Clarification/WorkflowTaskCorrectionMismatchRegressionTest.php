<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Clarification;

use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Task T01 Regression Test:
 * Verifies that the legacy request shape (slots_patch) fails validation with HTTP 422,
 * while the canonical typed corrections payload with expected_revision succeeds,
 * handles revision conflicts (HTTP 409), and blocks premature proposal preparation.
 */
final class WorkflowTaskCorrectionMismatchRegressionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Clarification Admin User',
            'email' => 'clarify_reg_' . uniqid() . '@example.com',
            'phone' => '9777777799',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'T01 Test Company Ltd',
            'code' => 'T01_CO_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = CompanyBranch::where('company_id', $this->company->id)->first() ?? CompanyBranch::create([
            'company_id' => $this->company->id,
            'name' => 'T01 Main Branch',
            'code' => 'T01-BR',
            'is_active' => true,
        ]);

        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->first() ?? FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-2027',
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

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->first() ?? Warehouse::create([
            'company_id' => $this->company->id,
            'name' => 'Central Hub Warehouse',
            'code' => 'HUB-01',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::where('company_id', $this->company->id)->first() ?? Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Apex Raw Materials Ltd',
            'company_name' => 'Apex Raw Materials Ltd',
            'is_active' => true,
        ]);

        $this->unit = Unit::where('company_id', $this->company->id)->first() ?? Unit::create([
            'company_id' => $this->company->id,
            'unit_code' => 'PCS',
            'unit_name' => 'Pieces',
            'is_active' => true,
        ]);

        $this->product = Product::where('company_id', $this->company->id)->first() ?? Product::create([
            'company_id' => $this->company->id,
            'name' => 'Cotton Combed 30s',
            'code' => 'COT-30S',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 150.00,
            'price' => 200.00,
            'unit_id' => $this->unit->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        config()->set('ai_actions.foundation_enabled', false);
        config()->set('ai_actions.execution_enabled', false);
        parent::tearDown();
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
     * Regression Test 1:
     * Legacy frontend payloads sending 'slots_patch' without 'corrections' or 'expected_revision'
     * fail validation with HTTP 422 Unprocessable Entity.
     */
    public function test_legacy_slots_patch_payload_fails_validation_with_422(): void
    {
        $this->actingAs($this->adminUser);

        // Create initial clarification task
        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_query' => 'Apex',
                'items' => [
                    ['product_query' => 'COT-30S', 'qty' => 10],
                ],
            ],
        ], $this->contextHeaders())->assertCreated();

        $taskId = $initResponse->json('task.id');

        // Case A: Old Submit Details payload (missing expected_revision AND missing corrections)
        $oldSubmitDetailsPayload = [
            'slots_patch' => [
                'warehouse_id' => $this->warehouse->id,
            ],
        ];

        $respA = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", $oldSubmitDetailsPayload, $this->contextHeaders());
        $respA->assertStatus(422);
        $respA->assertJsonValidationErrors(['expected_revision', 'corrections']);

        // Case B: Old Update & Revalidate payload (has expected_revision, but uses slots_patch instead of corrections)
        $oldUpdateRevalidatePayload = [
            'expected_revision' => 1,
            'slots_patch' => [
                'supplier_invoice_no' => 'INV-2026-001',
                'date' => '2026-11-17',
                'items' => [
                    ['client_line_id' => 'line-0', 'product_query' => 'COT-30S', 'qty' => 15, 'net_unit_cost' => 150.0],
                ],
            ],
        ];

        $respB = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", $oldUpdateRevalidatePayload, $this->contextHeaders());
        $respB->assertStatus(422);
        $respB->assertJsonValidationErrors(['corrections']);
    }

    /**
     * Regression Test 2:
     * Canonical typed corrections array with expected_revision succeeds (HTTP 200),
     * advances task revision, and updates task slots.
     */
    public function test_typed_corrections_with_expected_revision_succeeds_and_increments_revision(): void
    {
        $this->actingAs($this->adminUser);
        config()->set('erp_ai.conversation_enabled', true);

        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'payment_terms_type' => 'cash',
                'items' => [
                    ['product_id' => $this->product->id, 'qty' => 10, 'net_unit_cost' => 150.0],
                ],
            ],
        ], $this->contextHeaders())->assertCreated();

        $taskId = $initResponse->json('task.id');
        $initialRevision = (int) $initResponse->json('task.revision');

        // Canonical payload for Submit Details
        $canonicalPayload = [
            'expected_revision' => $initialRevision,
            'corrections' => [
                [
                    'field' => 'warehouse_id',
                    'value' => $this->warehouse->id,
                    'confirmation' => 'user_selected',
                ],
            ],
        ];

        $patchResp = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", $canonicalPayload, $this->contextHeaders());
        $patchResp->assertStatus(200);

        $updatedRevision = (int) $patchResp->json('task.revision');
        $this->assertSame($initialRevision + 1, $updatedRevision);
        $patchResp->assertJsonPath('task.slots.warehouse_id', $this->warehouse->id)
            ->assertJsonPath('evaluation.is_ready', true);
    }

    /**
     * Regression Test 3:
     * Concurrency check: Stale expected_revision fails with HTTP 409 Conflict (CONCURRENT_TASK_MODIFICATION),
     * preventing silent stale replays, and client can fetch the latest task state.
     */
    public function test_stale_expected_revision_returns_409_conflict(): void
    {
        $this->actingAs($this->adminUser);

        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'items' => [
                    ['product_id' => $this->product->id, 'qty' => 10, 'net_unit_cost' => 150.0],
                ],
            ],
        ], $this->contextHeaders())->assertCreated();

        $taskId = $initResponse->json('task.id');
        $rev1 = (int) $initResponse->json('task.revision');

        // First patch advances revision to rev2
        $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
            'expected_revision' => $rev1,
            'corrections' => [
                ['field' => 'note', 'value' => 'First modification'],
            ],
        ], $this->contextHeaders())->assertOk();

        // Second patch using stale rev1 fails with 409 Conflict
        $staleResp = $this->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
            'expected_revision' => $rev1,
            'corrections' => [
                ['field' => 'note', 'value' => 'Conflicting stale update'],
            ],
        ], $this->contextHeaders());

        $staleResp->assertStatus(409);
        $staleResp->assertJsonFragment([
            'error_code' => 'CONCURRENT_TASK_MODIFICATION',
        ]);

        // Client can cleanly fetch latest task without corruption
        $latestResp = $this->getJson("/api/v1/ai/workflow-tasks/{$taskId}", $this->contextHeaders());
        $latestResp->assertOk();
        $this->assertSame($rev1 + 1, (int) $latestResp->json('task.revision'));
        $this->assertSame('First modification', $latestResp->json('task.slots.note'));
    }

    /**
     * Regression Test 4:
     * Incomplete task blocks proposal preparation with HTTP 422,
     * ensuring zero duplicate proposals and zero unapproved purchase writes.
     */
    public function test_incomplete_task_blocks_prepare_proposal_and_prevents_unapproved_writes(): void
    {
        $this->actingAs($this->adminUser);

        // Task with missing/unresolved supplier
        $initResponse = $this->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_query' => 'Nonexistent Supplier 9999',
                'items' => [
                    ['product_id' => $this->product->id, 'qty' => 10, 'net_unit_cost' => 150.0],
                ],
            ],
        ], $this->contextHeaders())->assertCreated();

        $taskId = $initResponse->json('task.id');
        $this->assertFalse($initResponse->json('evaluation.is_ready'));

        $initialProposalsCount = DB::table('ai_action_proposals')->count();
        $initialPurchasesCount = DB::table('purchases')->count();

        // Calling prepare-proposal while task is incomplete must fail
        $prepResp = $this->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal", [], $this->contextHeaders());
        $prepResp->assertStatus(422);
        $prepResp->assertJson([
            'status' => 'needs_input',
            'message' => 'Cannot prepare proposal; task has unresolved blockers.',
        ]);

        // Invariant: Zero proposals and zero purchases created
        $this->assertSame($initialProposalsCount, DB::table('ai_action_proposals')->count());
        $this->assertSame($initialPurchasesCount, DB::table('purchases')->count());
    }
}
