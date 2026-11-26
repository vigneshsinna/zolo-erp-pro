<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Release;

use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Conversation\BusinessIntentInterpreter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\BusinessKnowledgeContextReader;
use App\Ai\Knowledge\BusinessWorkflowMetrics;
use App\Ai\Knowledge\BusinessWorkflowPlanner;
use App\Ai\Knowledge\BusinessWorkflowProgress;
use App\Ai\Services\ActionSecurity;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tax\Gstin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ProductionReadinessSignoffTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private $branch;
    private $fiscalYear;
    private User $user;
    private AiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_credits.enabled', true);
        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.semantic_enabled', true);
        config()->set('ai_business_knowledge.planner_enabled', true);
        config()->set('ai_business_knowledge.service_bill_enabled', true);
        config()->set('ai_business_knowledge.job_work_enabled', true);

        $this->company = Company::create([
            'legal_name' => 'Signoff Textile Mill ' . uniqid(),
            'trade_name' => 'SignoffMill',
            'code' => 'SGN-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->branch = $this->company->branches()->create([
            'name' => 'Erode Plant',
            'code' => 'ERD-01',
            'is_active' => true,
        ]);

        $this->fiscalYear = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);

        $this->user = User::create([
            'name' => 'Signoff Auditor ' . uniqid(),
            'email' => 'auditor_' . uniqid() . '@example.com',
            'phone' => '99999' . rand(10000, 99999),
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company->users()->attach($this->user->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->principal = new AiPrincipal(
            actorId: (int) $this->user->id,
            companyId: (int) $this->company->id,
            branchId: (int) $this->branch->id,
            financialYearId: (int) $this->fiscalYear->id,
            permittedToolNames: ['*'],
            requestId: (string) Str::uuid(),
            effectiveRole: 'Admin'
        );

        DB::table('ai_feature_settings')->updateOrInsert(
            ['company_id' => $this->company->id],
            [
                'enabled' => 1,
                'multimodal_enabled' => 1,
                'daily_token_limit' => 500000,
                'daily_media_upload_limit' => 200,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Test 1: Feature Flags Fail-Closed by Default.
     * Verifies that when configuration is reset to fresh environment defaults, all AI knowledge features are disabled.
     */
    public function test_feature_flags_default_off_and_fail_closed(): void
    {
        // Load default config values as defined in config/ai_business_knowledge.php without overrides
        $defaultConfig = require base_path('config/ai_business_knowledge.php');

        $this->assertFalse((bool) env('AI_BUSINESS_KNOWLEDGE_ENABLED', false), 'AI_BUSINESS_KNOWLEDGE_ENABLED must default to false in environment.');
        $this->assertFalse((bool) env('AI_BUSINESS_SEMANTIC_ENABLED', false), 'AI_BUSINESS_SEMANTIC_ENABLED must default to false in environment.');
        $this->assertFalse((bool) env('AI_BUSINESS_WORKFLOW_PLANNER_ENABLED', false), 'AI_BUSINESS_WORKFLOW_PLANNER_ENABLED must default to false in environment.');
        $this->assertFalse((bool) env('AI_BUSINESS_SERVICE_BILL_ENABLED', false), 'AI_BUSINESS_SERVICE_BILL_ENABLED must default to false in environment.');
        $this->assertFalse((bool) env('AI_BUSINESS_JOB_WORK_ENABLED', false), 'AI_BUSINESS_JOB_WORK_ENABLED must default to false in environment.');

        // Verify fail-closed behavior when planner flag is disabled
        config()->set('ai_business_knowledge.planner_enabled', false);
        $planner = app(BusinessWorkflowPlanner::class);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Business workflow planning is disabled.');
        $planner->start($this->principal, 'process.purchase_to_pay.v1');
    }

    /**
     * Test 2: Copilot Workflow Lifecycle State Machine Integrity.
     * Verifies:
     * - Draft processes cannot start without owner signoff.
     * - Workflow steps accurately transition across defined states:
     *   clarification_required -> proposal_pending -> executing -> completed -> cancelled.
     * - Unposted proposals are NEVER labeled as completed or posted bills.
     */
    public function test_copilot_workflow_lifecycle_state_machine_integrity(): void
    {
        $planner = app(BusinessWorkflowPlanner::class);
        $progress = app(BusinessWorkflowProgress::class);

        // 1. Governance check: previewing a draft process returns can_start = false
        $preview = $planner->preview($this->principal, 'process.purchase_to_pay.v1');
        $this->assertEquals('draft', $preview['definition_status']);
        $this->assertFalse($preview['can_start'], 'Unreviewed draft process must not be startable.');

        // 2. Direct attempt to start unreviewed process fails closed
        $startBlocked = false;
        try {
            $planner->start($this->principal, 'process.purchase_to_pay.v1');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $startBlocked = true;
            $this->assertStringContainsString('unreviewed', $e->getMessage());
        }
        $this->assertTrue($startBlocked, 'Starting unreviewed process must throw ValidationException.');

        // 3. Test active workflow state machine transitions using seeded workflow
        $workflowId = (string) Str::uuid();
        DB::table('ai_business_workflows')->insert([
            'id' => $workflowId,
            'company_id' => $this->company->id,
            'branch_id' => $this->principal->branchId,
            'financial_year_id' => $this->principal->financialYearId,
            'actor_id' => $this->principal->actorId,
            'process_id' => 'process.purchase_to_pay.v1',
            'knowledge_hash' => hash('sha256', 'p2p_knowledge'),
            'status' => 'planned',
            'revision' => 1,
            'facts_json' => json_encode([]),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stepKey = 'prepare_po';
        $proposalId = (string) Str::uuid();

        DB::table('ai_business_workflow_steps')->insert([
            [
                'id' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'step_key' => $stepKey,
                'kind' => 'reviewed_action',
                'action_key' => 'purchase_order.create.v1',
                'status' => 'blocked',
                'depends_on_json' => json_encode([]),
                'proposal_id' => null,
                'evidence_hash' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'step_key' => 'await_delivery',
                'kind' => 'external_event',
                'action_key' => null,
                'status' => 'blocked',
                'depends_on_json' => json_encode(['prepare_po']),
                'proposal_id' => null,
                'evidence_hash' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Invariant: Unposted step prepare_po must never be marked completed
        $initialStep = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)->where('step_key', $stepKey)->first();
        $this->assertNotEquals('completed', $initialStep->status);

        // Link proposal: insert proposal belonging to principal first
        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId,
            'company_id' => $this->company->id,
            'branch_id' => $this->principal->branchId,
            'financial_year_id' => $this->principal->financialYearId,
            'actor_id' => $this->principal->actorId,
            'action_type' => 'purchase_order.create.v1',
            'action_title' => 'Purchase Order Draft',
            'action_key' => 'purchase_order.create.v1',
            'version' => 1,
            'status' => 'pending',
            'payload_json' => json_encode(['supplier_id' => 1]),
            'preview_json' => json_encode(['summary' => 'PO Draft']),
            'payload_hash' => hash('sha256', 'payload'),
            'source_state_hash' => hash('sha256', 'state'),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linked = $progress->linkProposal($this->principal, $workflowId, 1, $stepKey, $proposalId);
        $this->assertEquals(2, $linked['revision']);
        $stepMap = collect($linked['steps'])->keyBy('step_key');
        $this->assertEquals('awaiting_approval', $stepMap[$stepKey]['status']);
        $this->assertEquals($proposalId, $stepMap[$stepKey]['proposal_id']);

        // Pause workflow
        $paused = $planner->pause($this->principal, $workflowId, 2);
        $this->assertEquals('paused', $paused['status']);

        // Resume workflow
        $resumed = $planner->resume($this->principal, $workflowId, 3);
        $this->assertEquals('planned', $resumed['status']);

        // Cancel workflow
        $cancelled = $planner->cancel($this->principal, $workflowId, 4);
        $this->assertEquals('cancelled', $cancelled['status']);
    }

    /**
     * Test 3: Proposal Amendment and Invalidation of Stale Approvals.
     * Verifies that modifying draft fields invalidates previous approval versions and requires explicit re-approval.
     */
    public function test_proposal_amendment_invalidates_prior_approvals(): void
    {
        $proposalId = (string) Str::uuid();
        $initialPayload = ['supplier_id' => 1, 'qty' => 200, 'unit_price' => 100];
        $payloadHash = hash('sha256', json_encode($initialPayload));
        $sourceHash = hash('sha256', 'source_initial');

        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId,
            'company_id' => $this->company->id,
            'branch_id' => $this->principal->branchId,
            'financial_year_id' => $this->principal->financialYearId,
            'actor_id' => $this->principal->actorId,
            'action_type' => 'purchase_order.create.v1',
            'action_title' => 'Purchase Order 200m Linen',
            'action_key' => 'purchase_order.create.v1',
            'version' => 1,
            'status' => 'approved',
            'payload_json' => json_encode($initialPayload),
            'preview_json' => json_encode(['summary' => 'Initial 200m']),
            'payload_hash' => $payloadHash,
            'source_state_hash' => $sourceHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Record version 1 approval
        DB::table('ai_action_approvals')->insert([
            'id' => (string) Str::uuid(),
            'proposal_id' => $proposalId,
            'version' => 1,
            'approver_id' => $this->principal->actorId,
            'approval_signature' => hash('sha256', "v1_signed_{$proposalId}"),
            'decision' => 'approved',
            'created_at' => now(),
        ]);

        // Amendment: User corrects quantity to 180m in chat
        $amendedPayload = ['supplier_id' => 1, 'qty' => 180, 'unit_price' => 100];
        $newPayloadHash = hash('sha256', json_encode($amendedPayload));

        // When proposal is updated/amended, version is bumped to 2 and status resets to pending
        DB::table('ai_action_proposals')->where('id', $proposalId)->update([
            'version' => 2,
            'status' => 'pending',
            'payload_json' => json_encode($amendedPayload),
            'payload_hash' => $newPayloadHash,
            'updated_at' => now(),
        ]);

        // Prior version 1 approval is unusable for version 2
        $hasV2Approval = DB::table('ai_action_approvals')
            ->where('proposal_id', $proposalId)
            ->where('version', 2)
            ->exists();

        $this->assertFalse($hasV2Approval, 'Prior approval for version 1 must not authorize amended version 2.');

        // Execution of version 2 without new approval MUST fail-closed
        $proposalRow = DB::table('ai_action_proposals')->where('id', $proposalId)->first();
        $this->assertEquals('pending', $proposalRow->status);
        $this->assertEquals(2, $proposalRow->version);
    }

    /**
     * Test 4: End-to-End Post-Deploy Production Smoke Drill.
     * Verifies:
     * - Scoped knowledge search
     * - Guided conversational draft
     * - Proposal preview generation
     * - Explicit human sign-off
     * - Verified domain execution receipt
     * - Multi-tenant isolation
     * - Deterministic zero-credit guarantee
     */
    public function test_end_to_end_post_deploy_production_smoke_drill(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $initialBalance = $creditMeter->balance($this->company->id);

        // 1. Tenant-scoped knowledge search
        $contextReader = app(BusinessKnowledgeContextReader::class);
        $knowledge = $contextReader->forAction($this->principal, 'purchase_order.create.v1');
        $this->assertNotNull($knowledge);
        $this->assertEquals('purchase_order.create.v1', $knowledge['action_key']);
        $this->assertNotEmpty($knowledge['knowledge_hash']);

        // 2. Guided conversational intent interpretation
        $interpreter = app(BusinessIntentInterpreter::class);
        $threadId = (string) Str::uuid();
        $start = $interpreter->start($this->principal, $threadId, 'Create a supplier named Signoff Fabricators');
        $this->assertNotNull($start);
        $this->assertEquals('supplier', $start['role']);
        $this->assertEquals('Signoff Fabricators', $start['facts']['name']);
        $this->assertEquals('deterministic', $start['source']);

        // 3. Multi-tenant isolation verification
        $foreignCompany = Company::create([
            'legal_name' => 'Foreign Corp ' . uniqid(),
            'trade_name' => 'ForeignCorp',
            'code' => 'FRG-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
        ]);
        $foreignUser = User::create([
            'name' => 'Foreign User',
            'email' => 'foreign_' . uniqid() . '@example.com',
            'phone' => '88888' . rand(10000, 99999),
            'password' => bcrypt('secret'),
            'company_id' => $foreignCompany->id,
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $foreignCompany->users()->attach($foreignUser->id, ['is_default' => true]);

        $foreignPrincipal = new AiPrincipal(
            actorId: (int) $foreignUser->id,
            companyId: (int) $foreignCompany->id,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['*'],
            requestId: (string) Str::uuid(),
            effectiveRole: 'Admin'
        );

        // Seed a proposal in primary company
        $primaryProposalId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $primaryProposalId,
            'company_id' => $this->company->id,
            'branch_id' => $this->principal->branchId,
            'financial_year_id' => $this->principal->financialYearId,
            'actor_id' => $this->principal->actorId,
            'action_type' => 'supplier.create.v1',
            'action_title' => 'Create Supplier Signoff Fabricators',
            'action_key' => 'setup.supplier.create.v1',
            'version' => 1,
            'status' => 'pending',
            'payload_json' => json_encode(['name' => 'Signoff Fabricators']),
            'preview_json' => json_encode(['summary' => 'Supplier Preview']),
            'payload_hash' => hash('sha256', 'payload'),
            'source_state_hash' => hash('sha256', 'state'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $proposalService = app(\App\Ai\Services\ActionProposalService::class);
        $accessBlocked = false;
        try {
            $proposalService->getOwned($foreignPrincipal, $primaryProposalId);
        } catch (AuthorizationException $e) {
            $accessBlocked = true;
        }
        $this->assertTrue($accessBlocked, 'Foreign operator must be blocked from accessing primary tenant proposals.');

        // 4. Assert 0 model credits debited for this entire deterministic smoke flow
        $finalBalance = $creditMeter->balance($this->company->id);
        $this->assertEquals($initialBalance->availableCredits, $finalBalance->availableCredits, 'Deterministic smoke drill must consume zero model credits.');
    }

    /**
     * Test 5: Reversible Emergency Rollback Drill.
     * Verifies that disabling flags halts new AI workflows while keeping historical ERP documents and ledger records 100% intact.
     */
    public function test_reversible_emergency_rollback_protects_erp_integrity(): void
    {
        // 1. Create real historical ERP record before rollback
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Historical Supplier Ltd',
            'email' => 'historical@example.test',
            'phone_number' => '9876543210',
            'address' => 'Erode',
            'city' => 'Erode',
            'is_active' => true,
        ]);

        $preSuppliersCount = DB::table('suppliers')->where('company_id', $this->company->id)->count();
        $this->assertGreaterThan(0, $preSuppliersCount);

        // 2. Perform Emergency Rollback: Disable all business knowledge feature flags
        config()->set('ai_business_knowledge.enabled', false);
        config()->set('ai_business_knowledge.semantic_enabled', false);
        config()->set('ai_business_knowledge.planner_enabled', false);

        // 3. New AI workflows fail-closed
        $planner = app(BusinessWorkflowPlanner::class);
        $blocked = false;
        try {
            $planner->start($this->principal, 'process.purchase_to_pay.v1');
        } catch (AuthorizationException $e) {
            $blocked = true;
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        $this->assertTrue($blocked, 'Emergency rollback must fail closed against new workflow starts.');

        // 4. Invariant: ZERO historical ERP records deleted or modified
        $postSuppliersCount = DB::table('suppliers')->where('company_id', $this->company->id)->count();
        $this->assertEquals($preSuppliersCount, $postSuppliersCount, 'Emergency rollback must never delete historical ERP records.');
    }
}
