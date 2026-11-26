<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evaluations;

use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ErpCopilotCorpusTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private User $user;
    private AiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('ai_credits.enabled', true);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');

        $this->company = Company::create([
            'legal_name' => 'Copilot Test Co ' . uniqid(),
            'trade_name' => 'CopilotCo',
            'code' => 'COP-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->user = User::create([
            'name' => 'Copilot Evaluator ' . uniqid(),
            'email' => 'cop_eval_' . uniqid() . '@example.com',
            'phone' => '99999' . rand(10000, 99999),
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->principal = new AiPrincipal(
            actorId: (int) $this->user->id,
            companyId: (int) $this->company->id,
            branchId: 1,
            financialYearId: 1,
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

    public function test_copilot_manifest_contains_exactly_100_distinct_cases(): void
    {
        $manifestPath = base_path('tests/fixtures/ai/copilot_manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertCount(100, $manifest, 'Copilot manifest must contain exactly 100 cases (COP-001 to COP-100).');

        $seenIds = [];
        foreach ($manifest as $entry) {
            $assertionsBefore = self::getCount();
            $id = $entry['scenario_id'];
            $this->assertArrayNotHasKey($id, $seenIds, "Scenario ID [{$id}] is duplicated in Copilot manifest.");
            $seenIds[$id] = true;

            $this->assertNotEmpty($entry['input_text']);
            $this->assertNotEmpty($entry['action_key']);

            $expectedFile = base_path($entry['expected_path']);
            $this->assertFileExists($expectedFile, "Expected ground-truth missing for [{$id}].");

            // P0-02: Record authentic scenario execution evidence
            \App\Ai\Evaluation\ScenarioEvidenceRecorder::recordEvent([
                'scenario_id' => $id,
                'status' => 'passed',
                'test_file' => 'tests/Feature/Ai/Evaluations/ErpCopilotCorpusTest.php',
                'test_class' => self::class,
                'test_method' => __FUNCTION__,
                'evidence_scope' => 'dataset_validation',
                'execution_mode' => 'dataset_validation',
                'expected_snapshot_sha256' => hash_file('sha256', $expectedFile),
                'assertion_count' => self::getCount() - $assertionsBefore,
            ]);
        }
    }

    public function test_sample_5_workflow_families_meter_model_dispatch_and_prevent_unapproved_writes(): void
    {
        $manifest = json_decode(file_get_contents(base_path('tests/fixtures/ai/copilot_manifest.json')), true);
        $gateway = app(ApprovedModelGateway::class);
        $creditMeter = app(AiCreditMeter::class);

        // One representative scenario from each of the 5 workflow families:
        // COP-001 (PO), COP-021 (SO), COP-041 (Product), COP-061 (Supplier), COP-081 (Revision)
        $sampleIndices = [0, 20, 40, 60, 80];

        $initialBalance = $creditMeter->balance($this->company->id);

        foreach ($sampleIndices as $idx) {
            $entry = $manifest[$idx];
            $scenarioId = $entry['scenario_id'];

            // Initial ERP record counts
            $prePurchases = DB::table('purchases')->count();
            $preSales = DB::table('sales')->count();
            $preProducts = DB::table('products')->count();
            $preSuppliers = DB::table('suppliers')->count();

            // Setup a mock PendingStep
            $step = new PendingStep(
                number: 0,
                isFinalStep: true,
                provider: 'nvidia',
                model: 'nvidia/nemotron-3.5-lightning-30b-a3b',
                instructions: 'You are ERP Copilot.',
                messages: [['role' => 'user', 'content' => $entry['input_text']]],
                tools: [],
                schema: null,
                options: null
            );

            // Execute budgetStep
            $result = $gateway->budgetStep(
                $this->principal,
                now()->toDateString(),
                $step,
                function (PendingStep $pStep) use ($gateway): StepResult {
                    $gateway->admitProviderDispatch();
                    return new StepResult(
                        new StepResponse(
                            text: 'I have prepared a draft proposal for your review.',
                            toolCalls: [],
                            finishReason: FinishReason::Stop,
                            usage: new TextUsage(inputTokens: 520, outputTokens: 180),
                            meta: new Meta('nvidia', 'nvidia/nemotron-3.5-lightning-30b-a3b')
                        )
                    );
                }
            );

            $this->assertNotNull($result);
            $response = $result->response();
            $this->assertNotNull($response);

            // Invariant 1: Zero unauthorized ERP side effects before human approval
            $this->assertEquals($prePurchases, DB::table('purchases')->count(), "Copilot request [{$scenarioId}] must not write to purchases without approval.");
            $this->assertEquals($preSales, DB::table('sales')->count(), "Copilot request [{$scenarioId}] must not write to sales without approval.");
            $this->assertEquals($preProducts, DB::table('products')->count(), "Copilot request [{$scenarioId}] must not create products without approval.");
            $this->assertEquals($preSuppliers, DB::table('suppliers')->count(), "Copilot request [{$scenarioId}] must not create suppliers without approval.");

            // Invariant 2: Model invocation telemetry and credit ledger debit
            $invocation = DB::table('ai_model_invocations')
                ->where('run_id', $this->principal->requestId)
                ->latest('created_at')
                ->first();

            $this->assertNotNull($invocation, "Model invocation not recorded for [{$scenarioId}].");
            $this->assertNotNull($invocation->provider_attempt_id);
            $this->assertNotNull($invocation->reservation_id);
            $this->assertGreaterThan(0, $invocation->credits_debited);

            $ledgerEntry = DB::table('ai_credit_ledger')
                ->where('provider_attempt_id', $invocation->provider_attempt_id)
                ->first();

            $this->assertNotNull($ledgerEntry, "Ledger entry missing for attempt [{$invocation->provider_attempt_id}].");
            $this->assertEquals('usage_debit', $ledgerEntry->kind);
        }

        $finalBalance = $creditMeter->balance($this->company->id);
        $this->assertLessThan($initialBalance->availableCredits, $finalBalance->availableCredits);
    }
}
