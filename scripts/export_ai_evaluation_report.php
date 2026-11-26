<?php

declare(strict_types=1);

/** Export recorded evidence only. Never boots Laravel or writes to an ERP database. */
require_once __DIR__.'/../vendor/autoload.php';

use App\Ai\Evaluation\ExecutedEvidenceReport;

$root = dirname(__DIR__);
$options = getopt('', ['junit:', 'test-plan:', 'scenario-events:', 'run-id:', 'output-dir:']);
$gitSha = trim((string) shell_exec('git rev-parse HEAD'));
$runId = (string) ($options['run-id'] ?? getenv('AI_EVALUATION_RUN_ID') ?: '');
$reporter = new ExecutedEvidenceReport();
$junit = $reporter->junit($options['junit'] ?? $root.'/artifacts/junit-ai.xml');
$junit = $reporter->verifyTestPlan($junit, $options['test-plan'] ?? $root.'/artifacts/ai-test-plan.xml');
$manifests = ['invoice-100' => 'invoice', 'copilot-100' => 'copilot', 'difficult-50' => 'difficult'];
$manifest = [];
$manifestIssues = [];
foreach ($manifests as $file => $kind) {
    $path = $root.'/tests/fixtures/ai/'.$kind.'_manifest.json';
    $entries = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    $expectedCount = $kind === 'difficult' ? 50 : 100;
    if (!is_array($entries) || count($entries) !== $expectedCount) {
        $manifestIssues[] = 'Missing or incomplete manifest: '.$path;
        continue;
    }
    foreach ($entries as $entry) {
        $entry['suite'] = $file;
        $manifest[] = $entry;
    }
}
$scenarios = $reporter->scenarios($options['scenario-events'] ?? $root.'/artifacts/scenario-events.jsonl',
    $manifest, $junit, $root, $gitSha, $runId);
if ($manifestIssues) {
    $scenarios['issues'] = array_merge($scenarios['issues'], $manifestIssues);
    $scenarios['status'] = 'INCOMPLETE';
}
$gate = $reporter->gate($junit, $scenarios);

// A mapped pass establishes only the named test's assertions. Missing coverage is never a pass.
$securityMap = [
    'SEC-01' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_01_and_02_tenant_and_user_history_isolation',
    'SEC-02' => null, // The combined history test does not exercise a forged context header.
    'SEC-03' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_03_cross_tenant_wallet_drawing_forbidden',
    'SEC-04' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_04_pooled_subscription_shares_balance_when_mapped',
    'SEC-05' => 'Tests\Feature\Ai\Multimodal\NormalizedInputWorkflowTest::test_cross_branch_or_financial_year_handoff_is_forbidden_for_write_actions',
    'SEC-06' => 'Tests\Feature\Ai\AiChatApiAndWorkspaceTest::test_chat_endpoint_denies_unpermitted_user',
    'SEC-07' => 'Tests\Feature\Ai\AiChatApiAndWorkspaceTest::test_chat_endpoint_denies_when_company_ai_disabled',
    'SEC-08' => null, // A pending proposal revision is not an edit after approval.
    'SEC-09' => null,
    'SEC-10' => null, // Serial replay tests do not establish simultaneous approval safety.
    'SEC-11' => null, // The corpus test currently compares counts without submitting the injection.
    'SEC-12' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_12_forged_webhook_rejected_with_403_and_zero_grant',
    'SEC-13' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_13_valid_webhook_repeated_100x_grants_exactly_once',
    'SEC-14' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_14_holds_exceeding_balance_fail_closed',
    'SEC-15' => null,
    'SEC-16' => null,
    'SEC-17' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_17_missing_model_pricing_fails_closed',
    'SEC-18' => 'Tests\Feature\Ai\Multimodal\MediaUploadSecurityTest::test_private_storage_exif_stripping_preview_headers_and_cross_company_isolation',
    'SEC-19' => 'Tests\Feature\Ai\ReceivablesSecurityAndAccuracyTest::test_audit_log_persists_redacted_records_in_database',
    'SEC-20' => 'Tests\Feature\Ai\Billing\CreditSecurityTest::test_sec_20_replaying_settled_attempt_id_is_idempotent',
];
$security = [];
foreach ($securityMap as $id => $test) {
    $case = $test !== null ? ($junit['cases'][$test] ?? null) : null;
    $security[] = ['id' => $id, 'test' => $test, 'status' => $case['status'] ?? 'NOT_EVIDENCED',
        'assertions' => $case['assertions'] ?? null];
}

$outputDir = $options['output-dir'] ?? $root.'/artifacts/ai-evaluation/'.substr($gitSha, 0, 8).'/'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $runId ?: 'missing-run-id');
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    throw new RuntimeException('Cannot create evidence output directory.');
}
$writeJson = static function (string $name, array $data) use ($outputDir): void {
    file_put_contents($outputDir.'/'.$name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
};
$suiteSummaries = [];
foreach ($manifests as $file => $_) {
    $ids = array_column(array_filter($manifest, fn ($entry) => $entry['suite'] === $file), 'scenario_id');
    $rows = array_values(array_filter($scenarios['rows'], fn ($row) => in_array($row['scenario_id'], $ids, true)));
    file_put_contents($outputDir.'/'.$file.'-results.jsonl', implode(PHP_EOL, array_map(
        fn ($row) => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $rows)).PHP_EOL);
    $suiteSummaries[$file] = ['total' => count($rows),
        'dataset_checks_passed' => count(array_filter($rows, fn ($row) => $row['status'] === 'PASSED')),
        'workflow_execution' => 'NOT_EVIDENCED'];
}
$writeJson('security-tests.json', ['scope' => 'Mapped JUnit assertions only', 'test_cases' => $security]);
$writeJson('concurrency-5-10-20.json', ['status' => 'NOT_RUN',
    'reason' => 'No verified simultaneous worker evidence. Sequential reservation tests do not establish concurrency performance.']);
$writeJson('credit-ledger-reconciliation.json', ['status' => 'NOT_EVIDENCED',
    'reason' => 'No run-bound ledger snapshot. Test transactions roll back; estimated credits are not ledger debits.']);
file_put_contents($outputDir.'/failed-scenario-ids.txt', implode(PHP_EOL, array_column(
    array_filter($scenarios['rows'], fn ($row) => $row['status'] !== 'PASSED'), 'scenario_id')));
$sourceChanges = trim((string) shell_exec('git status --porcelain -- app tests scripts config phpunit.ai.xml .github'));
$summary = ['git_sha' => $gitSha, 'source_changes_present' => $sourceChanges !== '',
    'test_run_id' => $runId, 'generated_at' => gmdate(DATE_ATOM),
    'evaluation_scope' => 'Executed PHPUnit assertions and dataset validation; provider responses are mocked in automated tests.',
    'junit_evidence' => $junit, 'scenario_evidence' => array_diff_key($scenarios, ['rows' => true]),
    'suites' => $suiteSummaries, 'security_coverage' => array_count_values(array_column($security, 'status')),
    'release_gate' => $gate];
$writeJson('summary.json', $summary);

$markdown = "# AI execution evidence\n\nCommit: {$gitSha}\n\nRun: {$runId}\n\n";
$markdown .= "Automated checks: **{$gate['automated_checks']}**. Release gate: **{$gate['status']}**. Production approval: **not granted**.\n\n";
$markdown .= "JUnit: {$junit['total_tests']} tests, {$junit['total_assertions']} assertions, {$junit['failures']} failures, {$junit['errors']} errors, {$junit['skipped']} skipped.\n\n";
$markdown .= "Dataset validation: {$scenarios['passed']}/{$scenarios['total']} records verified against this run, commit, passing JUnit testcase, and file hashes. These are dataset checks, not 250 executed commercial workflows.\n\n";
$markdown .= "Concurrent reservations: **NOT_RUN**. Live-model validation: **NOT_RUN**. Ledger snapshot reconciliation: **NOT_EVIDENCED**. No latency, token usage, costs, or credit debits are inferred. Historical live artifacts are not evidence for this run.\n\n";
$markdown .= "## Executed tests\n\n| Test | Status | Assertions |\n| --- | --- | ---: |\n";
foreach ($junit['cases'] as $test => $case) {
    $markdown .= '| '.str_replace('|', '\\|', $test).' | '.$case['status'].' | '.$case['assertions']." |\n";
}
$markdown .= "\n## Security evidence\n\n| Rule | Status | Executed test |\n| --- | --- | --- |\n";
foreach ($security as $row) {
    $markdown .= '| '.$row['id'].' | '.$row['status'].' | '.($row['test'] ?? 'No matching execution evidence')." |\n";
}
foreach (array_merge($junit['issues'], $scenarios['issues']) as $issue) {
    $markdown .= "\n- ".$issue;
}
file_put_contents($outputDir.'/summary.md', $markdown.PHP_EOL);
echo "Evidence exported: {$outputDir}\nAutomated checks: {$gate['automated_checks']}; release gate: {$gate['status']}\n";
exit($gate['automated_checks'] === 'PASSED' ? 0 : 1);
