<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Evaluation\AutonomyReleaseGate;
use PHPUnit\Framework\TestCase;

final class AutonomyReleaseGateTest extends TestCase
{
    public function test_no_evidence_never_approves_autonomous_posting(): void
    {
        $report = (new AutonomyReleaseGate())->evaluate([]);
        $this->assertSame('BLOCKED', $report['status']);
        $this->assertFalse($report['production_approved']);
        $this->assertGreaterThan(10, count($report['blockers']));
    }

    public function test_manifest_validation_is_not_execution_proof(): void
    {
        $report = (new AutonomyReleaseGate())->evaluate([
            'corpus' => [
                'indian_purchase_bills' => ['validated' => 100],
                'erp_copilot_requests' => ['validated' => 100],
                'difficult_requests' => ['validated' => 50],
            ],
            'executed_workflows' => [
                'indian_purchase_bills' => ['executed_and_asserted' => 100, 'evidence_scope' => 'dataset_validation'],
                'erp_copilot_requests' => ['executed_and_asserted' => 100, 'evidence_scope' => 'dataset_validation'],
                'difficult_requests' => ['executed_and_asserted' => 50, 'evidence_scope' => 'dataset_validation'],
            ],
        ]);
        $this->assertSame('BLOCKED', $report['status']);
        $this->assertTrue(count(array_filter($report['blockers'],
            static fn (string $s): bool => str_contains($s, 'Actual workflow execution'))) >= 3);
    }

    public function test_provided_signoffs_cannot_bypass_runtime_kill_switch(): void
    {
        $gate = new AutonomyReleaseGate();
        $requirements = $gate->evaluate([]);
        $evidence = [
            'provenance' => ['git_sha' => str_repeat('a', 40), 'ci_run_id' => 'fixture'],
            'human_signoff' => ['security' => 'APPROVED', 'accounting' => 'APPROVED'],
            'live_staging' => ['status' => 'PASSED', 'artifact_sha256' => str_repeat('b', 64)],
        ];
        foreach ($requirements['required_corpus_counts'] as $key => $count) {
            $evidence['corpus'][$key] = ['validated' => $count];
            $evidence['executed_workflows'][$key] = [
                'executed_and_asserted' => $count, 'evidence_scope' => 'workflow_execution',
            ];
        }
        foreach ($requirements['required_controls'] as $key) {
            $evidence['controls'][$key] = [
                'status' => 'PASSED', 'artifact_sha256' => str_repeat('c', 64),
            ];
        }
        foreach ([5, 10, 20] as $count) {
            $evidence['concurrent_users'][(string) $count] = [
                'status' => 'PASSED', 'p95_ms' => 150, 'request_count' => $count,
                'provider_requests' => 0, 'throttled_requests' => 0,
            ];
        }
        $report = $gate->evaluate($evidence);
        $this->assertSame('BLOCKED', $report['status']);
        $this->assertFalse($report['production_approved']);
        $this->assertContains('Independent autonomous posting kill switch is off.', $report['blockers']);
    }
}
