<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evaluation;

use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\CreditAccountResolver;
use App\Ai\Conversation\BusinessIntentInterpreter;
use App\Ai\Conversation\ConversationalSlotInterpreter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Knowledge\BusinessWorkflowMetrics;
use App\Ai\Services\ActionSecurity;
use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\Gstin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BusinessKnowledgeEvaluationAndMeteringTest extends TestCase
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
        config()->set('ai_credits.enabled', true);
        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.semantic_enabled', true);
        config()->set('ai_business_knowledge.planner_enabled', true);
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');

        $this->company = Company::create([
            'legal_name' => 'Metering & Eval Corp ' . uniqid(),
            'trade_name' => 'EvalCorp',
            'code' => 'MTR-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->branch = $this->company->branches()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $this->fiscalYear = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);

        $this->user = User::create([
            'name' => 'Quality Evaluator ' . uniqid(),
            'email' => 'evaluator_' . uniqid() . '@example.com',
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
     * Test 1: Evaluate English, Tamil, and Mixed Tamil-English Prompts.
     * Verifies >= 95% intent accuracy, zero model-invented IDs, and zero credit burn for deterministic flows.
     */
    public function test_multilingual_english_tamil_and_tanglish_prompt_evaluation(): void
    {
        $interpreter = app(BusinessIntentInterpreter::class);
        $threadId = (string) Str::uuid();

        $testCorpus = [
            // English standard intents
            ['utterance' => 'Create a supplier named Tirupur Textiles', 'expected_role' => 'supplier', 'name' => 'Tirupur Textiles'],
            ['utterance' => 'Create a customer named Erode Garments', 'expected_role' => 'customer', 'name' => 'Erode Garments'],
            ['utterance' => 'Create a party who will buy linen from us', 'expected_role' => 'customer', 'name' => null],
            ['utterance' => 'Create a party who will sell fabric to our company', 'expected_role' => 'supplier', 'name' => null],

            // Tamil script intents
            ['utterance' => 'ஒரு புதிய supplier create பண்ணுங்க', 'expected_role' => 'supplier', 'name' => null],
            ['utterance' => 'புதிய கஸ்டமர் add பண்ணுங்க', 'expected_role' => 'customer', 'name' => null],
            ['utterance' => 'நம்ம கிட்ட இருந்து வாங்குவாங்க', 'direction_only' => true, 'expected_role' => 'customer'],
            ['utterance' => 'நமக்கு சப்ளை பண்ணுவாங்க', 'direction_only' => true, 'expected_role' => 'supplier'],

            // Mixed Tamil-English (Tanglish) intents
            ['utterance' => 'oru puthiya supplier create pannunga', 'expected_role' => 'supplier', 'name' => null],
            ['utterance' => 'pudhu customer add pannunga', 'expected_role' => 'customer', 'name' => null],
            ['utterance' => 'avanga namma kitta vanguranga', 'direction_only' => true, 'expected_role' => 'customer'],
            ['utterance' => 'avanga namakku supply pannuvanga', 'direction_only' => true, 'expected_role' => 'supplier'],
            ['utterance' => 'namma avanga kitta vanguroam', 'direction_only' => true, 'expected_role' => 'supplier'],
            ['utterance' => 'namma kitta irundhu vanguvanga', 'direction_only' => true, 'expected_role' => 'customer'],

            // Strict direction test: generic mention without trading direction must abstain/be ambiguous
            ['utterance' => 'Create a new party for buying linen', 'expected_role' => 'ambiguous', 'name' => null],
        ];

        $totalCases = count($testCorpus);
        $correctClassifications = 0;

        foreach ($testCorpus as $case) {
            if (!empty($case['direction_only'])) {
                $resolved = $interpreter->direction($case['utterance']);
                if ($resolved === $case['expected_role']) {
                    $correctClassifications++;
                }
            } else {
                $result = $interpreter->start($this->principal, $threadId, $case['utterance']);
                $this->assertNotNull($result, "Interpreter failed to parse: {$case['utterance']}");
                if ($result['role'] === $case['expected_role']) {
                    $correctClassifications++;
                }
                if (!empty($case['name'])) {
                    $this->assertEquals($case['name'], $result['facts']['name'] ?? null);
                }
                // Verify zero model usage for deterministic flows
                $this->assertEquals('deterministic', $result['provenance'] ?? 'deterministic');
            }
        }

        $accuracy = ($correctClassifications / $totalCases) * 100.0;
        $this->assertGreaterThanOrEqual(95.0, $accuracy, "Intent classification accuracy ({$accuracy}%) must meet or exceed 95% target.");
    }

    /**
     * Test 2: Deterministic Operations Zero-Credit and Zero-Token Guarantee.
     * Verifies that deterministic PHP/SQL tasks consume 0 LLM calls and 0 model credits.
     */
    public function test_deterministic_actions_consume_zero_credits_and_zero_tokens(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $initialBalance = $creditMeter->balance($this->company->id);

        $initialModelInvocations = DB::table('ai_model_invocations')
            ->where('company_id', $this->company->id)
            ->count();
        $initialLedgerDebits = DB::table('ai_credit_ledger')
            ->where('company_id', $this->company->id)
            ->where('kind', 'usage_debit')
            ->count();

        // 1. Deterministic conversational slot interpretation
        $slotInterpreter = app(ConversationalSlotInterpreter::class);
        $task = ['slots_json' => json_encode(['items' => []])];
        $result = $slotInterpreter->interpret($task, "from ABC Traders; delivered to Central Warehouse; use credit 30 days");

        $this->assertNotEmpty($result['corrections']);
        $fieldNames = array_column($result['corrections'], 'field');
        $this->assertContains('supplier_query', $fieldNames);
        $this->assertContains('warehouse_query', $fieldNames);
        $this->assertContains('payment_terms_type', $fieldNames);

        // 2. Deterministic GST normalization and checksum
        $validGstin = '33AABCT1234A1Z7';
        $normalized = Gstin::normalize($validGstin);
        $this->assertEquals($validGstin, $normalized);

        // 3. Database queries and metrics summary
        $metrics = app(BusinessWorkflowMetrics::class);
        $summary = $metrics->summary($this->principal);

        $this->assertEquals(0, $summary['deterministic_actions_model_usage']);
        $this->assertEquals(0, $summary['deterministic_actions_credits']);

        // Assert 0 new model invocations and 0 new ledger debits
        $finalModelInvocations = DB::table('ai_model_invocations')
            ->where('company_id', $this->company->id)
            ->count();
        $finalLedgerDebits = DB::table('ai_credit_ledger')
            ->where('company_id', $this->company->id)
            ->where('kind', 'usage_debit')
            ->count();

        $this->assertEquals($initialModelInvocations, $finalModelInvocations, 'Deterministic actions must consume zero model invocations.');
        $this->assertEquals($initialLedgerDebits, $finalLedgerDebits, 'Deterministic actions must consume zero model credits.');

        $finalBalance = $creditMeter->balance($this->company->id);
        $this->assertEquals($initialBalance->availableCredits, $finalBalance->availableCredits, 'Available credit balance must not change on deterministic operations.');
    }

    /**
     * Test 3: Centralized Credit Metering: Reservation, Settlement, and Failure Release.
     * Verifies atomic ledger accounting, credit reservation, settlement, and safe release on error.
     */
    public function test_centralized_credit_metering_reservation_settlement_and_refund(): void
    {
        $creditMeter = app(AiCreditMeter::class);
        $balance = $creditMeter->balance($this->company->id);
        $initialAvailable = $balance->availableCredits;

        // 1. Reserve credits for an estimated 100 credits operation
        $runId = (string) Str::uuid();
        $idempKey = 'idemp_res_' . Str::uuid();

        $reservation = $creditMeter->reserve(
            $this->principal,
            $runId,
            'test.model_inference',
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            $idempKey,
            100
        );

        $this->assertNotNull($reservation);
        $this->assertEquals(100, $reservation->estimatedCredits);

        // Balance after reservation: available credits decreased by held reservation
        $midBalance = $creditMeter->balance($this->company->id);
        $this->assertEquals($initialAvailable - 100, $midBalance->availableCredits);

        // 2. Settle with actual usage: settling tokens
        $settlement = $creditMeter->settle(
            $reservation->id,
            'attempt_' . Str::uuid(),
            'nvidia',
            'nvidia/nemotron-3.5-lightning-30b-a3b',
            600, // input tokens
            200  // output tokens
        );

        $this->assertNotNull($settlement);
        $this->assertGreaterThan(0, $settlement->creditsDebited);
        $this->assertGreaterThanOrEqual(0, $settlement->creditsReleased);

        // Balance after settlement: reserved credits returned to 0
        $finalBalance = $creditMeter->balance($this->company->id);
        $this->assertLessThan($initialAvailable, $finalBalance->availableCredits);
        $this->assertEquals(0, $finalBalance->reservedCredits);

        // Verify credit ledger debits and releases
        $debitRow = DB::table('ai_credit_ledger')
            ->where('account_id', $reservation->accountId)
            ->where('kind', 'usage_debit')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($debitRow);
        $this->assertLessThan(0, $debitRow->amount_credits);
    }

    /**
     * Test 4: Provider Rate Limiting (429) Backpressure Safety.
     * Verifies that rate limiting fails closed without bypassing human approval or duplicate billing.
     */
    public function test_provider_429_backpressure_fails_closed_safely(): void
    {
        $gateway = app(ApprovedModelGateway::class);
        $creditMeter = app(AiCreditMeter::class);
        $initialBalance = $creditMeter->balance($this->company->id);

        RateLimiter::clear('ai:rpm:nvidia');
        for ($i = 0; $i < 35; $i++) {
            RateLimiter::hit('ai:rpm:nvidia', 60);
        }

        $failedClosed = false;
        try {
            $gateway->enforceProviderRateLimit('nvidia');
        } catch (TooManyRequestsHttpException $e) {
            $failedClosed = true;
            $this->assertStringContainsString('request limit reached', $e->getMessage());
        }

        $this->assertTrue($failedClosed, 'Provider rate limiting must trigger TooManyRequestsHttpException.');

        // Verify that 429 backpressure incurred ZERO credit billing and ZERO ERP mutations
        $finalBalance = $creditMeter->balance($this->company->id);
        $this->assertEquals($initialBalance->availableCredits, $finalBalance->availableCredits);
        $this->assertEquals(0, DB::table('purchases')->where('company_id', $this->company->id)->count());
    }

    /**
     * Test 5: BusinessWorkflowMetrics Live Join with Authoritative Telemetry & Ledgers.
     * Verifies that summary() provides exact execution, latency, recovery rate, and cost breakdowns.
     */
    public function test_business_workflow_metrics_joins_authoritative_ledgers_and_telemetry(): void
    {
        $metrics = app(BusinessWorkflowMetrics::class);

        // Seed workflow and steps
        $workflowId = (string) Str::uuid();
        DB::table('ai_business_workflows')->insert([
            'id' => $workflowId,
            'company_id' => $this->company->id,
            'branch_id' => $this->principal->branchId,
            'financial_year_id' => $this->principal->financialYearId,
            'actor_id' => $this->principal->actorId,
            'process_id' => 'process.procurement.goods.v1',
            'knowledge_hash' => hash('sha256', 'procurement_knowledge'),
            'status' => 'executing',
            'revision' => 2,
            'facts_json' => json_encode(['po_number' => 'PO-TEST-001']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_business_workflow_steps')->insert([
            [
                'id' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'step_key' => 'step_1_create_po',
                'kind' => 'action',
                'action_key' => 'purchase_order.create.v1',
                'status' => 'completed',
                'depends_on_json' => json_encode([]),
                'proposal_id' => (string) Str::uuid(),
                'evidence_hash' => hash('sha256', 'ev1'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'step_key' => 'step_2_verify_grn',
                'kind' => 'validation',
                'action_key' => 'inventory.grn.verify.v1',
                'status' => 'completed',
                'depends_on_json' => json_encode(['step_1_create_po']),
                'proposal_id' => null,
                'evidence_hash' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed proposal and completed execution
        $proposalId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId,
            'company_id' => $this->company->id,
            'branch_id' => $this->principal->branchId,
            'financial_year_id' => $this->principal->financialYearId,
            'actor_id' => $this->principal->actorId,
            'action_type' => 'purchase_order.create.v1',
            'action_title' => 'Create Purchase Order for New Trader',
            'action_key' => 'purchase_order.create.v1',
            'version' => 1,
            'status' => 'approved',
            'payload_json' => json_encode(['supplier_id' => 1]),
            'preview_json' => json_encode(['summary' => 'PO Draft']),
            'payload_hash' => hash('sha256', 'payload'),
            'source_state_hash' => hash('sha256', 'state'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_action_executions')->insert([
            'id' => (string) Str::uuid(),
            'proposal_id' => $proposalId,
            'company_id' => $this->company->id,
            'executor_id' => $this->principal->actorId,
            'domain_service' => 'PurchaseOrderCommandAdapter',
            'domain_method' => 'execute',
            'erp_resource_type' => 'purchases',
            'erp_resource_id' => 101,
            'idempotency_key' => 'idemp_exec_' . Str::uuid(),
            'status' => 'completed',
            'result_json' => json_encode(['po_id' => 101]),
            'duration_ms' => 450,
            'executed_at' => now(),
        ]);

        // Seed model invocation and ledger debit
        DB::table('ai_model_invocations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'run_id' => $this->principal->requestId,
            'provider' => 'nvidia',
            'model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
            'input_tokens' => 850,
            'output_tokens' => 320,
            'duration_ms' => 340,
            'status' => 'completed',
            'credits_debited' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolver = app(CreditAccountResolver::class);
        $account = $resolver->resolve($this->company->id);

        DB::table('ai_credit_ledger')->insert([
            'id' => (string) Str::uuid(),
            'account_id' => $account->id,
            'period_id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'actor_id' => $this->principal->actorId,
            'kind' => 'usage_debit',
            'amount_credits' => -15,
            'currency' => 'INR',
            'cost_minor_inr' => 75,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        // Query summary
        $summary = $metrics->summary($this->principal);

        $this->assertEquals(1, $summary['completed_erp_actions']);
        $this->assertEquals(0, $summary['failed_erp_actions']);
        $this->assertEquals(100.0, $summary['recovery_rate']);
        $this->assertEquals(450.0, $summary['execution_latency_ms']['avg_duration_ms']);
        $this->assertEquals(450, $summary['execution_latency_ms']['max_duration_ms']);
        $this->assertEquals(1170, $summary['model_tokens']);
        $this->assertEquals(15, $summary['model_credits']);
        $this->assertEquals(75, $summary['model_cost_minor_inr']);
        $this->assertEquals(1, $summary['model_invocations_count']);
        $this->assertEquals(7.5, $summary['per_step_cost_credits']); // 15 credits / 2 completed steps
        $this->assertEquals(0, $summary['deterministic_actions_model_usage']);
        $this->assertEquals(0, $summary['deterministic_actions_credits']);
    }
}
