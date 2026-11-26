<?php

declare(strict_types=1);

namespace App\Ai\Evaluation;

/** Reads execution artifacts without running services, fabricating outcomes, or writing ERP data. */
final class ExecutedEvidenceReport
{
    public function junit(string $path): array
    {
        $result = ['file' => $path, 'sha256' => null, 'total_tests' => 0, 'total_assertions' => 0,
            'failures' => 0, 'errors' => 0, 'skipped' => 0, 'cases' => [], 'issues' => [], 'status' => 'INCOMPLETE'];
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false || str_contains(strtoupper($contents), '<!DOCTYPE')) {
            $result['issues'][] = 'JUnit is missing or contains a prohibited document type.';
            return $result;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false || !in_array($xml->getName(), ['testsuite', 'testsuites'], true)) {
            $result['issues'][] = 'JUnit XML is malformed.';
            return $result;
        }
        $result['sha256'] = hash('sha256', $contents);
        foreach ($xml->xpath('//testcase') as $case) {
            $key = (string) $case['class'].'::'.(string) $case['name'];
            if (isset($result['cases'][$key])) {
                $result['issues'][] = 'Duplicate JUnit testcase: '.$key;
            }
            $status = isset($case->error) ? 'ERROR' : (isset($case->failure) ? 'FAILED'
                : (isset($case->skipped) ? 'SKIPPED' : ((int) $case['assertions'] > 0 ? 'PASSED' : 'INCOMPLETE')));
            $result['cases'][$key] = ['status' => $status, 'assertions' => (int) $case['assertions'],
                'time_seconds' => (float) $case['time'], 'file' => (string) $case['file']];
            $result['total_tests']++;
            $result['total_assertions'] += (int) $case['assertions'];
            $result['errors'] += $status === 'ERROR' ? 1 : 0;
            $result['failures'] += $status === 'FAILED' ? 1 : 0;
            $result['skipped'] += $status === 'SKIPPED' ? 1 : 0;
        }
        $suites = $xml->getName() === 'testsuite' ? [$xml] : $xml->testsuite;
        $declared = ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];
        foreach ($suites as $suite) {
            foreach ($declared as $attribute => $_) {
                $declared[$attribute] += (int) $suite[$attribute];
            }
        }
        if ($declared['tests'] !== $result['total_tests']) {
            $result['issues'][] = 'JUnit test count does not match executed testcase records.';
        }
        foreach (['failures', 'errors', 'skipped'] as $attribute) {
            $result[$attribute] = max($result[$attribute], $declared[$attribute]);
        }
        $incomplete = in_array('INCOMPLETE', array_column($result['cases'], 'status'), true);
        $result['status'] = $result['failures'] + $result['errors'] > 0 ? 'FAILED'
            : ($result['total_tests'] > 0 && $result['skipped'] === 0 && !$incomplete && !$result['issues'] ? 'PASSED' : 'INCOMPLETE');
        return $result;
    }

    public function scenarios(string $path, array $manifest, array $junit, string $root, string $gitSha, string $runId): array
    {
        $events = [];
        $issues = [];
        if ($runId === '') {
            $issues[] = 'An execution run ID is required.';
        }
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || empty($event['scenario_id'])) {
                $issues[] = 'Malformed scenario event.';
                continue;
            }
            $id = $event['scenario_id'];
            if (isset($events[$id])) {
                $issues[] = 'Duplicate scenario event: '.$id;
            }
            $events[$id] = $event;
        }
        $rows = [];
        foreach ($manifest as $entry) {
            $id = $entry['scenario_id'];
            $event = $events[$id] ?? [];
            $test = ($event['test_class'] ?? '').'::'.($event['test_method'] ?? '');
            $expectedPath = $root.'/'.$entry['expected_path'];
            $valid = $runId !== '' && ($event['test_run_id'] ?? '') === $runId
                && ($event['git_sha'] ?? '') === $gitSha && ($event['status'] ?? '') === 'passed'
                && ($junit['cases'][$test]['status'] ?? '') === 'PASSED'
                && ($event['assertion_count'] ?? 0) > 0
                && ($event['evidence_scope'] ?? '') === 'dataset_validation'
                && is_file($expectedPath)
                && hash_file('sha256', $expectedPath) === ($event['expected_snapshot_sha256'] ?? null);
            if (isset($entry['media_path'])) {
                $mediaPath = $root.'/'.$entry['media_path'];
                $valid = $valid && is_file($mediaPath)
                    && hash_file('sha256', $mediaPath) === ($event['source_media_sha256'] ?? null);
            }
            $rows[] = ['scenario_id' => $id, 'status' => $valid ? 'PASSED' : 'INCOMPLETE',
                'evidence_scope' => 'dataset_validation', 'workflow_execution' => 'NOT_EVIDENCED',
                'test' => $test, 'event' => $event ?: null];
        }
        $expectedIds = array_column($manifest, 'scenario_id');
        if (count(array_unique($expectedIds)) !== count($expectedIds)) {
            $issues[] = 'Duplicate manifest scenario ID.';
        }
        if (array_diff(array_keys($events), $expectedIds)) {
            $issues[] = 'Scenario events contain unknown IDs.';
        }
        $passed = count(array_filter($rows, fn ($row) => $row['status'] === 'PASSED'));
        return ['file' => $path, 'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            'total' => count($manifest), 'passed' => $passed, 'issues' => $issues, 'rows' => $rows,
            'status' => count($manifest) > 0 && $passed === count($manifest) && !$issues ? 'PASSED' : 'INCOMPLETE'];
    }

    public function verifyTestPlan(array $junit, string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = $contents !== false && !str_contains(strtoupper($contents), '<!DOCTYPE')
                ? simplexml_load_string($contents, options: LIBXML_NONET) : false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $ids = [];
        foreach ($xml !== false ? $xml->xpath('//*[local-name()="testMethod"]') : [] as $method) {
            $ids[] = (string) $method['id'];
        }
        $junit['test_plan'] = ['file' => $path, 'sha256' => $contents !== false ? hash('sha256', $contents) : null,
            'expected_tests' => count($ids)];
        if (!$ids || array_diff($ids, array_keys($junit['cases'])) || array_diff(array_keys($junit['cases']), $ids)) {
            $junit['issues'][] = 'JUnit does not contain exactly the tests discovered in the full AI suite.';
            if ($junit['status'] !== 'FAILED') {
                $junit['status'] = 'INCOMPLETE';
            }
        }
        return $junit;
    }

    public function gate(array $junit, array $scenarios): array
    {
        $automated = $junit['status'] === 'PASSED' && $scenarios['status'] === 'PASSED';
        return ['automated_checks' => $automated ? 'PASSED' : ($junit['status'] === 'FAILED' ? 'FAILED' : 'INCOMPLETE'),
            'concurrent_reservations' => 'NOT_RUN', 'live_model_validation' => 'NOT_RUN',
            'production_approved' => false, 'status' => $junit['status'] === 'FAILED' ? 'FAILED' : 'INCOMPLETE'];
    }
}
