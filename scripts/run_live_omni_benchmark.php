<?php

declare(strict_types=1);

/**
 * Live Benchmark Script: Executes genuine NVIDIA Nemotron 3 Nano Omni extraction on BILL-001.png
 * and generates ground-truth comparison artifact.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\Clients\NvidiaOmniPerceptionClient;
use App\Ai\Multimodal\Schemas\InvoiceExtractionV1;
use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$opts = getopt('', ['provider::', 'budget-cap-usd::', 'scenario::']);
$provider = (string) ($opts['provider'] ?? 'nvidia');
$budgetCapUsd = isset($opts['budget-cap-usd']) ? (float) $opts['budget-cap-usd'] : 5.00;
$scenarioId = (string) ($opts['scenario'] ?? 'BILL-001');

echo "=== Live AI Benchmark [Scenario: {$scenarioId}, Provider: {$provider}, Budget Cap: \${$budgetCapUsd}] ===" . PHP_EOL;

$outputDir = __DIR__ . '/../artifacts/ai-evaluation/live-benchmarks';
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// 1. Budget Cap Preflight Check: compute cumulative past live spend
$pastSpendUsd = 0.0;
foreach (glob($outputDir . '/*.json') as $file) {
    $content = json_decode(file_get_contents($file), true);
    if (isset($content['cost_usd'])) {
        $pastSpendUsd += (float) $content['cost_usd'];
    } elseif (isset($content['usd_cost'])) {
        $pastSpendUsd += (float) $content['usd_cost'];
    }
}

// Estimated dispatch cost (conservative max 4k tokens + 1 image = ~$0.005 USD)
$estimatedDispatchCostUsd = 0.0050;
if (($pastSpendUsd + $estimatedDispatchCostUsd) > $budgetCapUsd) {
    echo "ERROR: Live benchmark budget cap exceeded! Past spent: \${$pastSpendUsd}, Max Est: \${$estimatedDispatchCostUsd}, Budget Cap: \${$budgetCapUsd}" . PHP_EOL;
    exit(2);
}

$imagePath = __DIR__ . "/../tests/fixtures/ai/invoice_media/{$scenarioId}.png";
$expectedPath = __DIR__ . "/../tests/fixtures/ai/expected/{$scenarioId}.json";

if (! file_exists($imagePath)) {
    echo "ERROR: Fixture image not found: {$imagePath}" . PHP_EOL;
    exit(1);
}

if (! file_exists($expectedPath)) {
    echo "ERROR: Expected JSON not found: {$expectedPath}" . PHP_EOL;
    exit(1);
}

// Ensure DB connection is configured
$dbName = config('database.connections.mysql.database');
echo "Using database: {$dbName}" . PHP_EOL;

// Ensure company & user exist
$company = Company::first() ?? Company::create([
    'legal_name' => 'Live Benchmark Co',
    'trade_name' => 'BenchmarkCo',
    'code' => 'BENCH-01',
    'status' => 'active',
    'country_code' => 'IN',
    'timezone' => 'Asia/Kolkata',
]);

$user = User::first() ?? User::create([
    'name' => 'Benchmark Runner',
    'email' => 'bench@example.com',
    'phone' => '9876543210',
    'password' => bcrypt('secret'),
    'company_id' => $company->id,
    'role_id' => 1,
    'is_active' => true,
    'is_deleted' => false,
]);

// Enable multimodal in config & feature gate
config([
    'erp_ai.enabled' => true,
    'ai_credits.enabled' => true,
    'ai_multimodal.enabled' => true,
    'ai_multimodal.provider' => 'nvidia',
    'ai_multimodal.model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
]);

app(AiFeatureGate::class)->setCompanySetting($company->id, true, false, [
    'multimodal_enabled' => true,
    'daily_token_limit' => 1000000,
]);

$apiKey = (string) config('ai.providers.nvidia.key', env('NVIDIA_API_KEY', ''));
if (empty($apiKey)) {
    echo "ERROR: NVIDIA API key is missing. Set AI_PROVIDERS_NVIDIA_KEY or NVIDIA_API_KEY in .env." . PHP_EOL;
    exit(1);
}

$principal = new AiPrincipal(
    actorId: (int) $user->id,
    companyId: (int) $company->id,
    branchId: 1,
    financialYearId: 1,
    permittedToolNames: ['*'],
    requestId: (string) Str::uuid(),
    effectiveRole: 'Admin'
);

$expectedData = json_decode(file_get_contents($expectedPath), true);
$imageData = file_get_contents($imagePath);
$base64Image = base64_encode($imageData);
$imageSha256 = hash('sha256', $imageData);

echo "Loaded image: BILL-001.png (" . strlen($imageData) . " bytes, SHA256: " . substr($imageSha256, 0, 16) . "...)" . PHP_EOL;
echo "Calling NVIDIA Nemotron 3 Nano Omni live API..." . PHP_EOL;

$client = app(NvidiaOmniPerceptionClient::class);
$mediaId = (string) Str::uuid();

$systemPrompt = InvoiceExtractionV1::systemPrompt($company->legal_name, '33AABCL9876C1ZF');
$userPrompt = "Extract all fields from this invoice image according to the schema. Output JSON only.";

$startTime = microtime(true);
$error = null;
$result = null;

try {
    $result = $client->extractStructuredFromImages(
        principal: $principal,
        mediaId: $mediaId,
        images: [
            [
                'mime' => 'image/png',
                'base64' => $base64Image,
            ],
        ],
        systemPrompt: $systemPrompt,
        userPrompt: $userPrompt,
        options: [
            'temperature' => 0.0,
            'max_tokens' => 2048,
        ]
    );
} catch (Throwable $e) {
    $error = $e->getMessage();
    echo "API Call Exception: " . $error . PHP_EOL;
}

$elapsed = round(microtime(true) - $startTime, 3);
echo "Elapsed time: {$elapsed}s" . PHP_EOL;

$outputDir = __DIR__ . '/../artifacts/ai-evaluation/live-benchmarks';
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$extracted = $result ? $result->parsedJson : [];
$rawText = $result ? $result->rawText : '';
$inputTokens = $result ? $result->inputTokens : 0;
$outputTokens = $result ? $result->outputTokens : 0;

// Apply schema normalization
$invoiceSchema = app(InvoiceExtractionV1::class);
$normalized = $extracted ? $invoiceSchema->validateAndNormalize($company->id, $extracted) : [];

// Extract line items and tax amounts
$lines = $normalized['lines'] ?? ($extracted['lines'] ?? []);
$taxAmount = (float) ($normalized['tax_amount'] ?? ($extracted['tax_amount'] ?? 0));
$subtotal = (float) ($normalized['subtotal'] ?? ($extracted['subtotal'] ?? 0));
$discountAmount = (float) ($normalized['discount_amount'] ?? ($extracted['discount_amount'] ?? ($extracted['header_discount'] ?? 0)));
$freightCharges = (float) ($extracted['freight_charges'] ?? 0);
$roundOff = (float) ($extracted['round_off'] ?? 0);
$grandTotal = (float) ($normalized['total_amount'] ?? ($extracted['total_amount'] ?? ($extracted['grand_total'] ?? 0)));

// Compare with Ground Truth
$expectedTax = (float) ($expectedData['cgst'] ?? 0) + (float) ($expectedData['sgst'] ?? 0) + (float) ($expectedData['igst'] ?? 0);

$fieldsToCompare = [
    'document_type' => ['expected' => $expectedData['document_type'] ?? 'purchase_bill', 'actual' => $normalized['document_type'] ?? ($extracted['document_type'] ?? null)],
    'supplier_name' => ['expected' => $expectedData['supplier_name'] ?? null, 'actual' => $normalized['supplier_name'] ?? ($extracted['supplier_name'] ?? null)],
    'supplier_gstin' => ['expected' => $expectedData['supplier_gstin'] ?? null, 'actual' => $normalized['supplier_gstin'] ?? ($extracted['supplier_gstin'] ?? null)],
    'invoice_number' => ['expected' => $expectedData['invoice_number'] ?? null, 'actual' => $normalized['invoice_number'] ?? ($extracted['invoice_number'] ?? null)],
    'invoice_date' => ['expected' => $expectedData['invoice_date'] ?? null, 'actual' => $normalized['invoice_date'] ?? ($extracted['invoice_date'] ?? null)],
    'subtotal' => ['expected' => (float) ($expectedData['subtotal'] ?? 0), 'actual' => $subtotal],
    'header_discount' => ['expected' => (float) ($expectedData['header_discount'] ?? 0), 'actual' => $discountAmount],
    'freight_charges' => ['expected' => (float) ($expectedData['freight_charges'] ?? 0), 'actual' => $freightCharges],
    'total_tax' => ['expected' => $expectedTax, 'actual' => $taxAmount],
    'round_off' => ['expected' => (float) ($expectedData['round_off'] ?? 0), 'actual' => $roundOff],
    'grand_total' => ['expected' => (float) ($expectedData['grand_total'] ?? 0), 'actual' => $grandTotal],
    'items_count' => ['expected' => count($expectedData['items'] ?? []), 'actual' => count($lines)],
];

$matchedFields = 0;
$totalFields = count($fieldsToCompare);
$fieldResults = [];

foreach ($fieldsToCompare as $field => $data) {
    $exp = $data['expected'];
    $act = $data['actual'];
    $match = false;
    if (is_numeric($exp) && is_numeric($act)) {
        $match = abs((float) $exp - (float) $act) < 0.05;
    } elseif (is_string($exp) && is_string($act)) {
        $match = strcasecmp(trim((string) $exp), trim((string) $act)) === 0;
    } else {
        $match = $exp === $act;
    }
    if ($match) {
        $matchedFields++;
    }
    $fieldResults[$field] = [
        'expected' => $exp,
        'actual' => $act,
        'match' => $match,
    ];
}

$accuracyPct = round(($matchedFields / $totalFields) * 100, 2);

// Arithmetic Formula Verification
// grand_total = subtotal - header_discount + freight_charges + tax_amount + round_off
$calcGrandTotal = round($subtotal - $discountAmount + $freightCharges + $taxAmount + $roundOff, 2);
$arithmeticValid = abs($calcGrandTotal - $grandTotal) < 0.05 && $grandTotal > 0;

$costCalculator = app(\App\Ai\Billing\Services\ModelCostCalculator::class);
$pricing = $costCalculator->calculate(
    provider: $provider,
    model: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
    inputTokens: $inputTokens,
    outputTokens: $outputTokens,
    imagesCount: 1
);

$actualCostUsd = $pricing['usd_cost'];
$totalSpentUsd = round($pastSpendUsd + $actualCostUsd, 6);

$benchmarkRecord = [
    'benchmark_timestamp' => now()->toIso8601String(),
    'scenario_id' => $scenarioId,
    'provider' => $provider,
    'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
    'execution_mode' => 'live_model_dispatch',
    'budget_cap_usd' => $budgetCapUsd,
    'cost_usd' => $actualCostUsd,
    'cost_inr' => round($pricing['cost_minor_inr'] / 100, 4),
    'credits_debited' => $pricing['credits'],
    'cumulative_usd_spent' => $totalSpentUsd,
    'budget_cap_respected' => $totalSpentUsd <= $budgetCapUsd,
    'media_sha256' => $imageSha256,
    'input_tokens' => $inputTokens,
    'output_tokens' => $outputTokens,
    'latency_seconds' => $elapsed,
    'status' => $error ? 'failed' : 'completed',
    'error' => $error,
    'field_accuracy_percentage' => $accuracyPct,
    'arithmetic_formula_valid' => $arithmeticValid,
    'reconciliation_formula' => "grand_total ({$grandTotal}) == subtotal ({$subtotal}) - discount ({$discountAmount}) + freight ({$freightCharges}) + tax ({$taxAmount}) + round_off ({$roundOff}) [computed: {$calcGrandTotal}]",
    'fields_comparison' => $fieldResults,
    'items_count_expected' => count($expectedData['items'] ?? []),
    'items_count_extracted' => count($lines),
    'extracted_lines' => $lines,
    'normalized_output' => $normalized,
    'raw_model_response' => $rawText,
];

$outputFile = $outputDir . "/live_omni_benchmark_{$scenarioId}.json";
file_put_contents($outputFile, json_encode($benchmarkRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Benchmark record written to: {$outputFile}" . PHP_EOL;
echo "Cost: \${$actualCostUsd} USD (~{$pricing['credits']} credits) | Cumulative Spent: \${$totalSpentUsd} / \${$budgetCapUsd}" . PHP_EOL;
echo "Field Accuracy: {$accuracyPct}% ({$matchedFields}/{$totalFields})" . PHP_EOL;
echo "Arithmetic Formula Valid: " . ($arithmeticValid ? 'YES' : 'NO') . PHP_EOL;
echo "Input Tokens: {$inputTokens}, Output Tokens: {$outputTokens}" . PHP_EOL;

if ($error) {
    exit(1);
}
