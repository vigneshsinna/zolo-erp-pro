<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Knowledge;

use App\Ai\Knowledge\BusinessWorkflowMetrics;
use App\Ai\Knowledge\BusinessWorkflowProgress;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Covers Doc 03 addendum hardening:
 *  - Cross-workflow proposal reuse blocked
 *  - Reversed JobWorkReceipt after physical_return completion transitions to needs_reconciliation (A22)
 *  - BusinessWorkflowMetrics channel breakdown
 */
final class WorkflowEvidenceHardeningTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'ai_actions.foundation_enabled' => true,
            'ai_business_knowledge.enabled' => true,
            'ai_business_knowledge.planner_enabled' => true,
            'ai_business_knowledge.job_work_enabled' => true,
        ]);

        $this->company = Company::create([
            'legal_name' => 'Workflow Evidence Co', 'code' => 'WFE-' . Str::random(6),
            'status' => 'active', 'country_code' => 'IN',
        ]);
        $this->branchId = $this->company->branches()->create(['name' => 'WFE Branch', 'code' => 'WFE', 'is_active' => true])->id;
        $this->fyId = $this->company->fiscalYears()->create([
            'name' => 'FY 2026', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;
        $this->actor = User::create([
            'name' => 'WFE Actor', 'email' => Str::uuid() . '@example.test', 'phone' => '7100000001',
            'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branchId,
        ]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
    }

    public function test_cross_workflow_proposal_reuse_is_rejected_and_channel_metrics_are_reported(): void
    {
        $principal = app(AiPrincipalFactory::class)->createForUser($this->actor->id, $this->company->id, $this->branchId, $this->fyId);

        $proposalId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId, 'actor_id' => $this->actor->id, 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'action_type' => 'purchase_order.create.v1', 'action_key' => 'purchase_order.create.v1',
            'action_title' => 'commercial', 'risk_tier' => 'r2_operational', 'version' => 1,
            'payload_json' => '{}', 'preview_json' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'source_state_hash' => hash('sha256', 'src'),
            'status' => 'pending', 'source_channel' => 'mcp',
            'expires_at' => now('UTC')->addDay(), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        $wf1 = (string) Str::uuid();
        $wf2 = (string) Str::uuid();
        foreach ([$wf1, $wf2] as $wf) {
            DB::table('ai_business_workflows')->insert([
                'id' => $wf, 'process_id' => 'process.purchase_to_pay.v1',
                'knowledge_hash' => hash('sha256', 'k'), 'company_id' => $this->company->id,
                'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
                'actor_id' => $this->actor->id, 'status' => 'planned', 'revision' => 1,
                'facts_json' => '{}', 'expires_at' => now('UTC')->addDay(),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            DB::table('ai_business_workflow_steps')->insert([
                'id' => (string) Str::uuid(), 'workflow_id' => $wf, 'step_key' => 'prepare_po',
                'kind' => 'reviewed_action', 'action_key' => 'purchase_order.create.v1',
                'status' => 'awaiting_proposal', 'depends_on_json' => '[]',
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }

        $progress = app(BusinessWorkflowProgress::class);
        $progress->linkProposal($principal, $wf1, 1, 'prepare_po', $proposalId);

        try {
            $progress->linkProposal($principal, $wf2, 1, 'prepare_po', $proposalId);
            $this->fail('Reusing same proposal across unrelated workflows must fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already linked', implode(' ', $e->validator->errors()->all()));
        }

        $metrics = app(BusinessWorkflowMetrics::class)->summary($principal);
        $this->assertSame(1, $metrics['proposals_by_channel']['mcp'] ?? 0);
    }

    public function test_a22_reversed_job_work_receipt_invalidates_completed_physical_return_step_on_reconcile(): void
    {
        $principal = app(AiPrincipalFactory::class)->createForUser($this->actor->id, $this->company->id, $this->branchId, $this->fyId);

        $warehouseId = Warehouse::forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'name' => 'JW Godown', 'address' => 'Erode', 'is_active' => true,
        ])->id;
        $extWarehouseId = Warehouse::forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'name' => 'JW External', 'address' => 'Tirupur', 'is_active' => true,
        ])->id;
        $supplierId = Supplier::forceCreate([
            'company_id' => $this->company->id, 'name' => 'JW Dyer', 'company_name' => 'JW Dyer',
            'phone_number' => '9876543211', 'address' => 'Erode', 'city' => 'Erode', 'country' => 'India', 'is_active' => true,
        ])->id;

        $processTypeId = DB::table('process_types')->insertGetId([
            'company_id' => $this->company->id, 'code' => 'PRC-A22-' . Str::random(4), 'name' => 'Dyeing',
            'max_loss_percent' => 5, 'conversion' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = DB::table('job_work_orders')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'process_type_id' => $processTypeId, 'job_worker_party_id' => $supplierId, 'external_warehouse_id' => $extWarehouseId,
            'reference_no' => 'JWO-A22-01', 'business_date' => '2026-11-25', 'status' => 'dispatched',
            'details_json' => '{}', 'created_by' => $this->actor->id, 'posted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dispatchId = DB::table('job_work_dispatches')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'job_work_order_id' => $orderId, 'warehouse_id' => $warehouseId, 'reference_no' => 'JWD-A22-01',
            'business_date' => '2026-11-25', 'posted_at' => now(), 'created_by' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receiptId = DB::table('job_work_receipts')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'dispatch_id' => $dispatchId, 'warehouse_id' => $warehouseId, 'reference_no' => 'JWR-A22-01',
            'business_date' => '2026-11-25', 'posted_at' => now(), 'reversed_at' => now(),
            'details_json' => '{}', 'created_by' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $grnProposalId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $grnProposalId, 'actor_id' => $this->actor->id, 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'action_type' => 'grn.create.v1', 'action_key' => 'grn.create.v1',
            'action_title' => 'purchasing', 'risk_tier' => 'r2_operational', 'version' => 1,
            'payload_json' => '{}', 'preview_json' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'source_state_hash' => hash('sha256', 'src'),
            'status' => 'completed', 'expires_at' => now('UTC')->addDay(),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('ai_action_executions')->insert([
            'id' => (string) Str::uuid(), 'proposal_id' => $grnProposalId, 'company_id' => $this->company->id,
            'executor_id' => $this->actor->id, 'version' => 1, 'idempotency_key' => 'ai-action:' . $grnProposalId . ':v1',
            'status' => 'completed', 'result_json' => '{}', 'erp_resource_type' => 'job_work_receipt',
            'erp_resource_id' => $receiptId, 'executed_at' => now('UTC'),
        ]);

        $wfId = (string) Str::uuid();
        DB::table('ai_business_workflows')->insert([
            'id' => $wfId, 'process_id' => 'process.job_work_outward.v1',
            'knowledge_hash' => hash('sha256', 'k'), 'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'actor_id' => $this->actor->id, 'status' => 'planned', 'revision' => 1,
            'facts_json' => '{}', 'expires_at' => now('UTC')->addDay(),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('ai_business_workflow_steps')->insert([
            [
                'id' => (string) Str::uuid(), 'workflow_id' => $wfId, 'step_key' => 'receipt',
                'kind' => 'reviewed_action', 'action_key' => 'grn.create.v1', 'proposal_id' => $grnProposalId,
                'status' => 'completed', 'depends_on_json' => '[]', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ],
            [
                'id' => (string) Str::uuid(), 'workflow_id' => $wfId, 'step_key' => 'physical_return',
                'kind' => 'external_event', 'action_key' => null, 'proposal_id' => null,
                'status' => 'completed', 'depends_on_json' => '["receipt"]', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ],
        ]);

        $inspected = app(BusinessWorkflowProgress::class)->reconcile($principal, $wfId, 1);
        $steps = collect($inspected['steps'])->keyBy('step_key');
        $this->assertSame('needs_reconciliation', $steps['physical_return']['status']);
    }
}
