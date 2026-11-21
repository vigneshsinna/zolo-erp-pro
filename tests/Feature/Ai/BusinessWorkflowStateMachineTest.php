<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BusinessWorkflowStateMachineTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private User $actor;
    private int $branchId;
    private int $fyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'legal_name' => 'FSM Fixture Company', 'code' => 'FSM-'.Str::random(8),
            'status' => 'active', 'country_code' => 'IN',
        ]);
        $this->branchId = $this->company->branches()->create([
            'name' => 'FSM Branch', 'code' => 'FSM', 'is_active' => true,
        ])->id;
        $this->fyId = $this->company->fiscalYears()->create([
            'name' => 'FY 2026', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;
        $this->actor = User::create([
            'name' => 'FSM Tester', 'email' => Str::uuid().'@example.test',
            'phone' => '7000000003', 'password' => bcrypt('fixture'),
            'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branchId,
        ]);
        config([
            'erp_ai.enabled' => true, 'erp_ai.conversation_enabled' => true,
            'ai_actions.foundation_enabled' => true,
            'ai_business_knowledge.enabled' => true, 'ai_business_knowledge.planner_enabled' => true,
        ]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
        $this->actingAs($this->actor)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
        ]);
    }

    public function test_clarification_step_requires_typed_inputs_and_unblocks_downstream(): void
    {
        $workflowId = (string) Str::uuid();
        $this->seedWorkflow($workflowId, 'planned', 1);

        // Step 1: clarify supplier (needs_input)
        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'identify_supplier', 'kind' => 'read_or_clarify',
            'action_key' => null, 'status' => 'needs_input',
            'depends_on_json' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Step 2: prepare PO (blocked)
        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'prepare_po', 'kind' => 'reviewed_action',
            'action_key' => 'purchase_order.create.v1', 'status' => 'blocked',
            'depends_on_json' => '["identify_supplier"]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Reject raw "done" assertion
        $this->postJson(route('api.v1.ai.business-workflows.clarify', ['id' => $workflowId]), [
            'expected_revision' => 1, 'step_key' => 'identify_supplier',
            'inputs' => ['status' => 'done'],
        ])->assertUnprocessable();

        // Valid structured inputs
        $res = $this->postJson(route('api.v1.ai.business-workflows.clarify', ['id' => $workflowId]), [
            'expected_revision' => 1, 'step_key' => 'identify_supplier',
            'inputs' => ['supplier_id' => 42, 'supplier_name' => 'Apex Fabrics', 'gstin' => '33ABCDE1234F1Z5'],
        ])->assertOk();

        $steps = collect($res->json('steps'))->keyBy('step_key');
        $this->assertSame('completed', $steps['identify_supplier']['status']);
        $this->assertTrue($steps['identify_supplier']['has_verified_evidence']);

        // Downstream step prepare_po is unblocked
        $this->assertSame('awaiting_proposal', $steps['prepare_po']['status']);
    }

    public function test_external_event_rejects_free_text_and_requires_authoritative_document(): void
    {
        $workflowId = (string) Str::uuid();
        $this->seedWorkflow($workflowId, 'planned', 1);

        $warehouse = Warehouse::create([
            'name' => 'FSM Godown', 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'is_active' => true,
            'address' => 'FSM Godown Address',
        ]);

        $po = Purchase::forceCreate([
            'reference_no' => 'PO-FSM-001', 'user_id' => $this->actor->id, 'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id, 'supplier_id' => null, 'currency_id' => 1,
            'exchange_rate' => 1, 'item' => 1, 'total_qty' => 10, 'total_discount' => 0,
            'total_tax' => 0, 'total_cost' => 1000, 'order_tax_rate' => 0, 'order_tax' => 0,
            'order_discount' => 0, 'shipping_cost' => 0, 'grand_total' => 1000, 'paid_amount' => 0,
            'status' => 4, 'payment_status' => 2,
        ]);

        $poProposalId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $poProposalId, 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'actor_id' => $this->actor->id, 'action_key' => 'purchase_order.create.v1',
            'action_type' => 'purchase_order.create.v1', 'action_title' => 'Purchase Order',
            'version' => 1, 'status' => 'completed',
            'payload_hash' => str_repeat('b', 64), 'payload_json' => '{}',
            'preview_json' => '{}',
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_action_executions')->insert([
            'id' => (string) Str::uuid(), 'proposal_id' => $poProposalId,
            'version' => 1, 'company_id' => $this->company->id,
            'executor_id' => $this->actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'completed', 'result_json' => '{}',
            'erp_resource_type' => 'purchase',
            'erp_resource_id' => $po->id, 'executed_at' => now(),
        ]);

        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'prepare_po', 'kind' => 'reviewed_action',
            'action_key' => 'purchase_order.create.v1', 'status' => 'completed',
            'proposal_id' => $poProposalId, 'evidence_hash' => str_repeat('e', 64),
            'depends_on_json' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'await_delivery', 'kind' => 'external_event',
            'action_key' => null, 'status' => 'awaiting_real_event',
            'depends_on_json' => '["prepare_po"]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Reject plain chat statement "goods received"
        $this->postJson(route('api.v1.ai.business-workflows.verify-event', ['id' => $workflowId]), [
            'expected_revision' => 1, 'step_key' => 'await_delivery',
            'evidence' => ['text' => 'goods received at godown'],
        ])->assertUnprocessable();

        // Valid authoritative document proof
        $res = $this->postJson(route('api.v1.ai.business-workflows.verify-event', ['id' => $workflowId]), [
            'expected_revision' => 1, 'step_key' => 'await_delivery',
            'evidence' => ['document_type' => 'purchase_order', 'resource_id' => $po->id],
        ])->assertOk();

        $steps = collect($res->json('steps'))->keyBy('step_key');
        $this->assertSame('completed', $steps['await_delivery']['status']);
        $this->assertTrue($steps['await_delivery']['has_verified_evidence']);
    }

    public function test_workflow_transitions_to_completed_when_all_steps_verified(): void
    {
        $workflowId = (string) Str::uuid();
        $this->seedWorkflow($workflowId, 'planned', 1);

        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'step_clarify', 'kind' => 'read_or_clarify',
            'action_key' => null, 'status' => 'completed',
            'evidence_hash' => str_repeat('1', 64),
            'depends_on_json' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'step_validate', 'kind' => 'read_only_validation',
            'action_key' => null, 'status' => 'needs_validation',
            'depends_on_json' => '["step_clarify"]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->postJson(route('api.v1.ai.business-workflows.validate', ['id' => $workflowId]), [
            'expected_revision' => 1, 'step_key' => 'step_validate',
            'validation' => ['validator' => 'tax_reconciliation', 'source_hash' => str_repeat('f', 64)],
        ])->assertOk();

        $this->assertSame('completed', $res->json('status'));
        $this->assertSame('completed', DB::table('ai_business_workflows')->where('id', $workflowId)->value('status'));

        // Event logged
        $this->assertTrue(DB::table('ai_business_workflow_events')
            ->where('workflow_id', $workflowId)->where('event_type', 'workflow_completed')->exists());
    }

    public function test_paused_or_cancelled_workflow_cannot_advance(): void
    {
        $workflowId = (string) Str::uuid();
        $this->seedWorkflow($workflowId, 'paused', 1);

        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'identify_supplier', 'kind' => 'read_or_clarify',
            'action_key' => null, 'status' => 'needs_input',
            'depends_on_json' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson(route('api.v1.ai.business-workflows.clarify', ['id' => $workflowId]), [
            'expected_revision' => 1, 'step_key' => 'identify_supplier',
            'inputs' => ['supplier_id' => 10],
        ])->assertUnprocessable();

        // Resume then cancel
        $this->postJson(route('api.v1.ai.business-workflows.resume', ['id' => $workflowId]), [
            'expected_revision' => 1,
        ])->assertOk();

        $this->postJson(route('api.v1.ai.business-workflows.cancel', ['id' => $workflowId]), [
            'expected_revision' => 2,
        ])->assertOk();

        $this->postJson(route('api.v1.ai.business-workflows.clarify', ['id' => $workflowId]), [
            'expected_revision' => 3, 'step_key' => 'identify_supplier',
            'inputs' => ['supplier_id' => 10],
        ])->assertUnprocessable();
    }

    public function test_competing_revisions_yield_409_conflict(): void
    {
        $workflowId = (string) Str::uuid();
        $this->seedWorkflow($workflowId, 'planned', 1);

        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'step_one', 'kind' => 'read_or_clarify',
            'action_key' => null, 'status' => 'needs_input',
            'depends_on_json' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson(route('api.v1.ai.business-workflows.clarify', ['id' => $workflowId]), [
            'expected_revision' => 99, // Stale revision
            'step_key' => 'step_one',
            'inputs' => ['party_id' => 1],
        ])->assertConflict();
    }

    public function test_reconciliation_reverts_step_when_proposal_is_cancelled_or_rejected(): void
    {
        $workflowId = (string) Str::uuid();
        $this->seedWorkflow($workflowId, 'planned', 1);

        $proposalId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId, 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'actor_id' => $this->actor->id, 'action_key' => 'purchase_order.create.v1',
            'action_type' => 'purchase_order.create.v1', 'action_title' => 'Purchase Order',
            'version' => 1, 'status' => 'rejected',
            'payload_hash' => str_repeat('b', 64), 'payload_json' => '{}',
            'preview_json' => '{}',
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('ai_business_workflow_steps')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'step_key' => 'prepare_po', 'kind' => 'reviewed_action',
            'action_key' => 'purchase_order.create.v1', 'status' => 'awaiting_approval',
            'proposal_id' => $proposalId, 'depends_on_json' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->postJson(route('api.v1.ai.business-workflows.reconcile', ['id' => $workflowId]), [
            'expected_revision' => 1,
        ])->assertOk();

        $steps = collect($res->json('steps'))->keyBy('step_key');
        $this->assertSame('awaiting_proposal', $steps['prepare_po']['status']);
        $this->assertNull($steps['prepare_po']['proposal_id']);
    }

    private function seedWorkflow(string $id, string $status, int $revision): void
    {
        DB::table('ai_business_workflows')->insert([
            'id' => $id, 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'actor_id' => $this->actor->id, 'process_id' => 'process.purchase_to_pay.v1',
            'knowledge_hash' => str_repeat('a', 64), 'status' => $status,
            'revision' => $revision, 'facts_json' => '{}',
            'expires_at' => now('UTC')->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
