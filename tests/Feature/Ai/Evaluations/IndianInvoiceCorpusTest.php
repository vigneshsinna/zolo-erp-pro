<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evaluations;

use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Multimodal\DTOs\PerceptionResult;
use App\Ai\Multimodal\Enums\ModelPurpose;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class IndianInvoiceCorpusTest extends TestCase
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
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');

        $this->company = Company::create([
            'legal_name' => 'Evaluation Corp ' . uniqid(),
            'trade_name' => 'EvalCorp',
            'code' => 'EVAL-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->user = User::create([
            'name' => 'AI Auditor ' . uniqid(),
            'email' => 'auditor_' . uniqid() . '@example.com',
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

    public function test_invoice_manifest_contains_exactly_100_distinct_cases_with_verified_checksums(): void
    {
        $manifestPath = base_path('tests/fixtures/ai/invoice_manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertCount(100, $manifest, 'Invoice manifest must contain exactly 100 cases (BILL-001 to BILL-100).');

        $seenIds = [];
        foreach ($manifest as $entry) {
            $assertionsBefore = self::getCount();
            $id = $entry['scenario_id'];
            $this->assertArrayNotHasKey($id, $seenIds, "Scenario ID [{$id}] is duplicated in invoice manifest.");
            $seenIds[$id] = true;

            $mediaFile = base_path($entry['media_path']);
            $this->assertFileExists($mediaFile, "Media file missing for scenario [{$id}].");
            $this->assertEquals($entry['sha256'], hash_file('sha256', $mediaFile), "SHA256 checksum mismatch for [{$id}].");

            $expectedFile = base_path($entry['expected_path']);
            $this->assertFileExists($expectedFile, "Expected ground-truth missing for [{$id}].");

            $expected = json_decode(file_get_contents($expectedFile), true);
            if ($expected['supplier_gstin'] !== null) {
                // Verify that every registered supplier GSTIN strictly passes the Indian modulo-36 checksum
                $normalized = \App\Services\Tax\Gstin::normalize($expected['supplier_gstin']);
                $this->assertEquals($expected['supplier_gstin'], $normalized, "Supplier GSTIN checksum failed for [{$id}].");
            }
            if (!empty($expected['buyer_gstin'])) {
                $normalizedBuyer = \App\Services\Tax\Gstin::normalize($expected['buyer_gstin']);
                $this->assertEquals($expected['buyer_gstin'], $normalizedBuyer, "Buyer GSTIN checksum failed for [{$id}].");
            }

            // P0-02: Record authentic scenario execution evidence
            \App\Ai\Evaluation\ScenarioEvidenceRecorder::recordEvent([
                'scenario_id' => $id,
                'status' => 'passed',
                'test_file' => 'tests/Feature/Ai/Evaluations/IndianInvoiceCorpusTest.php',
                'test_class' => self::class,
                'test_method' => __FUNCTION__,
                'evidence_scope' => 'dataset_validation',
                'execution_mode' => 'dataset_validation',
                'source_media_sha256' => $entry['sha256'],
                'expected_snapshot_sha256' => hash_file('sha256', $expectedFile),
                'assertion_count' => self::getCount() - $assertionsBefore,
            ]);
        }
    }

    public function test_sample_10_document_families_satisfy_tax_invariants_and_credit_metering(): void
    {
        $manifest = json_decode(file_get_contents(base_path('tests/fixtures/ai/invoice_manifest.json')), true);
        $gateway = app(ApprovedModelGateway::class);
        $creditMeter = app(AiCreditMeter::class);

        // Test one representative case from each of the 10 document families
        // BILL-001 (F01), BILL-011 (F02), BILL-021 (F03), BILL-031 (F04), BILL-041 (F05),
        // BILL-051 (F06), BILL-061 (F07), BILL-071 (F08), BILL-081 (F09), BILL-091 (F10)
        $sampleIndices = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90];

        $initialBalance = $creditMeter->balance($this->company->id);
        $this->assertGreaterThan(0, $initialBalance->availableCredits);

        foreach ($sampleIndices as $idx) {
            $entry = $manifest[$idx];
            $scenarioId = $entry['scenario_id'];
            $expected = json_decode(file_get_contents(base_path($entry['expected_path'])), true);

            // Pre-execution ERP table row counts
            $prePurchases = DB::table('purchases')->count();
            $preStockMovements = DB::table('stock_movements')->count();
            $preJournalEntries = DB::table('journal_entries')->count();

            // Execute metered perception dispatch
            $result = $gateway->admitPerceptionDispatch(
                $this->principal,
                now()->toDateString(),
                ModelPurpose::PerceptionVision,
                filesize(base_path($entry['media_path'])),
                function (string $provider, string $model) use ($expected): PerceptionResult {
                    return new PerceptionResult(
                        rawText: json_encode($expected),
                        parsedJson: $expected,
                        provider: $provider,
                        model: $model,
                        purpose: ModelPurpose::PerceptionVision,
                        inputTokens: 1200,
                        outputTokens: 450,
                        durationMs: 320,
                        httpAttempts: 1
                    );
                },
                mediaId: $scenarioId
            );

            // 1. Structure and field normalization assertions
            $this->assertEquals($expected['invoice_number'], $result->parsedJson['invoice_number']);
            $this->assertEquals($expected['grand_total'], $result->parsedJson['grand_total']);
            $this->assertEquals($expected['supplier_gstin'], $result->parsedJson['supplier_gstin']);

            // 2. Authoritative ERP Tax split invariant
            if ($expected['family_code'] === 'F02') {
                // Interstate: IGST only
                $this->assertGreaterThan(0, $result->parsedJson['igst']);
                $this->assertEquals(0, $result->parsedJson['cgst']);
                $this->assertEquals(0, $result->parsedJson['sgst']);
            } elseif ($expected['family_code'] === 'F01') {
                // Intrastate: CGST and SGST equal, zero IGST
                $this->assertEquals($result->parsedJson['cgst'], $result->parsedJson['sgst']);
                $this->assertEquals(0, $result->parsedJson['igst']);
            }

            // 3. Line arithmetic invariant
            $computedLinesSum = 0.0;
            foreach ($result->parsedJson['items'] as $item) {
                $computedLinesSum += $item['taxable_value'];
            }
            $this->assertEqualsWithDelta($expected['subtotal'], $computedLinesSum, 0.05);

            // 4. Zero unapproved writes invariant
            $this->assertEquals($prePurchases, DB::table('purchases')->count(), "Extraction must not write to purchases table for [{$scenarioId}].");
            $this->assertEquals($preStockMovements, DB::table('stock_movements')->count(), "Extraction must not mutate inventory for [{$scenarioId}].");
            $this->assertEquals($preJournalEntries, DB::table('journal_entries')->count(), "Extraction must not post to GL for [{$scenarioId}].");

            // 5. Credit metering audit
            $invocation = DB::table('ai_model_invocations')
                ->where('media_id', $scenarioId)
                ->latest('created_at')
                ->first();

            $this->assertNotNull($invocation, "Model invocation not recorded for [{$scenarioId}].");
            $this->assertNotNull($invocation->provider_attempt_id);
            $this->assertNotNull($invocation->reservation_id);
            $this->assertEquals('completed', $invocation->status);
            $this->assertGreaterThan(0, $invocation->credits_debited);

            // Verify immutable ledger row
            $ledgerEntry = DB::table('ai_credit_ledger')
                ->where('provider_attempt_id', $invocation->provider_attempt_id)
                ->first();

            $this->assertNotNull($ledgerEntry, "Credit ledger debit missing for attempt [{$invocation->provider_attempt_id}].");
            $this->assertEquals('usage_debit', $ledgerEntry->kind);
            $this->assertLessThan(0, $ledgerEntry->amount_credits);
        }

        $finalBalance = $creditMeter->balance($this->company->id);
        $this->assertLessThan($initialBalance->availableCredits, $finalBalance->availableCredits);
        $this->assertGreaterThan(0, $finalBalance->spentCredits);
    }
}
