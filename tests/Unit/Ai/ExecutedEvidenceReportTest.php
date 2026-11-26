<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Evaluation\ExecutedEvidenceReport;
use PHPUnit\Framework\TestCase;

final class ExecutedEvidenceReportTest extends TestCase
{
    private string $directory;
    private ExecutedEvidenceReport $reporter;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ai-evidence-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->reporter = new ExecutedEvidenceReport();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_missing_empty_malformed_or_external_entity_junit_never_passes(): void
    {
        $this->assertSame('INCOMPLETE', $this->reporter->junit($this->directory.'/missing.xml')['status']);
        foreach (['<testsuites/>', '<broken', '<!DOCTYPE testsuite [<!ENTITY x SYSTEM "file:///etc/passwd">]><testsuite/>'] as $xml) {
            $this->assertSame('INCOMPLETE', $this->reporter->junit($this->write('junit.xml', $xml))['status']);
        }
    }

    public function test_junit_counts_every_suite_and_distinguishes_failures_errors_and_skips(): void
    {
        $xml = '<testsuites><testsuite tests="2" failures="1"><testcase class="A" name="pass" assertions="3"/>'
            .'<testcase class="A" name="fail" assertions="1"><failure>failure</failure></testcase></testsuite>'
            .'<testsuite tests="2" errors="1" skipped="1"><testcase class="B" name="error"><error>error</error></testcase>'
            .'<testcase class="B" name="skip"><skipped/></testcase></testsuite></testsuites>';
        $result = $this->reporter->junit($this->write('junit.xml', $xml));
        $this->assertSame([4, 4, 1, 1, 1, 'FAILED'], array_map(fn ($key) => $result[$key],
            ['total_tests', 'total_assertions', 'failures', 'errors', 'skipped', 'status']));
        $this->assertSame('SKIPPED', $result['cases']['B::skip']['status']);
    }

    public function test_skipped_zero_assertion_inconsistent_or_duplicate_cases_never_pass(): void
    {
        foreach ([
            '<testsuite tests="1" skipped="1"><testcase class="A" name="skip" assertions="1"><skipped/></testcase></testsuite>',
            '<testsuite tests="1"><testcase class="A" name="empty"/></testsuite>',
            '<testsuite tests="2"><testcase class="A" name="pass" assertions="1"/></testsuite>',
            '<testsuite tests="2"><testcase class="A" name="pass" assertions="1"/><testcase class="A" name="pass" assertions="1"/></testsuite>',
        ] as $xml) {
            $this->assertSame('INCOMPLETE', $this->reporter->junit($this->write('junit.xml', $xml))['status']);
        }
    }

    public function test_subset_junit_cannot_satisfy_full_test_plan(): void
    {
        $junit = $this->passingJunit();
        $path = $this->write('plan.xml', '<testSuite><tests><testClass><testMethod id="A::pass"/>'
            .'<testMethod id="A::missing"/></testClass></tests></testSuite>');
        $this->assertSame('INCOMPLETE', $this->reporter->verifyTestPlan($junit, $path)['status']);
        $this->write('plan.xml', '<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass>'
            .'<testMethod id="A::pass"/></testClass></tests></testSuite>');
        $this->assertSame('PASSED', $this->reporter->verifyTestPlan($junit, $path)['status']);
    }

    public function test_scenarios_require_current_run_commit_hashes_and_passing_junit_case(): void
    {
        $junit = $this->passingJunit();
        $this->write('expected.json', '{}');
        $manifest = [['scenario_id' => 'COP-001', 'expected_path' => 'expected.json']];
        $event = ['scenario_id' => 'COP-001', 'status' => 'passed', 'test_run_id' => 'run-1', 'git_sha' => 'sha-1',
            'test_class' => 'A', 'test_method' => 'pass', 'assertion_count' => 1, 'evidence_scope' => 'dataset_validation',
            'expected_snapshot_sha256' => hash('sha256', '{}')];
        $path = $this->write('events.jsonl', json_encode($event));
        $result = $this->reporter->scenarios($path, $manifest, $junit, $this->directory, 'sha-1', 'run-1');
        $this->assertSame('PASSED', $result['status']);
        $this->assertSame('NOT_EVIDENCED', $result['rows'][0]['workflow_execution']);
        foreach (['test_run_id' => 'stale', 'git_sha' => 'stale', 'test_method' => 'absent',
            'assertion_count' => 0, 'expected_snapshot_sha256' => 'tampered'] as $key => $value) {
            $this->write('events.jsonl', json_encode(array_replace($event, [$key => $value])));
            $this->assertSame('INCOMPLETE', $this->reporter->scenarios($path, $manifest, $junit, $this->directory, 'sha-1', 'run-1')['status'], $key);
        }
        $this->write('events.jsonl', json_encode($event));
        $junit['cases']['A::pass']['status'] = 'FAILED';
        $this->assertSame('INCOMPLETE', $this->reporter->scenarios($path, $manifest, $junit, $this->directory, 'sha-1', 'run-1')['status']);
    }

    public function test_duplicate_malformed_missing_or_changed_media_evidence_fails_closed(): void
    {
        $this->write('expected.json', '{}');
        $this->write('media.png', 'fixture');
        $manifest = [['scenario_id' => 'BILL-001', 'expected_path' => 'expected.json', 'media_path' => 'media.png']];
        $event = ['scenario_id' => 'BILL-001', 'status' => 'passed', 'test_run_id' => 'run-1', 'git_sha' => 'sha-1',
            'test_class' => 'A', 'test_method' => 'pass', 'assertion_count' => 1, 'evidence_scope' => 'dataset_validation',
            'expected_snapshot_sha256' => hash('sha256', '{}'), 'source_media_sha256' => hash('sha256', 'fixture')];
        $junit = $this->passingJunit();
        $line = json_encode($event);
        foreach (['', '{broken', $line.PHP_EOL.$line] as $contents) {
            $path = $this->write('events.jsonl', $contents);
            $this->assertSame('INCOMPLETE', $this->reporter->scenarios($path, $manifest, $junit, $this->directory, 'sha-1', 'run-1')['status']);
        }
        $this->write('events.jsonl', $line);
        $this->write('media.png', 'changed');
        $this->assertSame('INCOMPLETE', $this->reporter->scenarios($path, $manifest, $junit, $this->directory, 'sha-1', 'run-1')['status']);
    }

    public function test_green_automated_evidence_does_not_approve_production_or_claim_live_concurrency(): void
    {
        $gate = $this->reporter->gate($this->passingJunit(), ['status' => 'PASSED']);
        $this->assertSame('PASSED', $gate['automated_checks']);
        $this->assertSame('INCOMPLETE', $gate['status']);
        $this->assertFalse($gate['production_approved']);
        $this->assertSame('NOT_RUN', $gate['concurrent_reservations']);
        $this->assertSame('NOT_RUN', $gate['live_model_validation']);
        $this->assertSame('FAILED', $this->reporter->gate(['status' => 'FAILED'], ['status' => 'PASSED'])['status']);
    }

    private function passingJunit(): array
    {
        return $this->reporter->junit($this->write('junit.xml',
            '<testsuite tests="1"><testcase class="A" name="pass" assertions="1" time="0.1"/></testsuite>'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $contents);
        return $path;
    }
}
