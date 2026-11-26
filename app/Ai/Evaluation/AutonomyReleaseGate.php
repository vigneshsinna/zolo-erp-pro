<?php

declare(strict_types=1);

namespace App\Ai\Evaluation;

/**
 * Dataset validation never qualifies as end-to-end autonomous ERP execution.
 * This gate summarizes evidence. Deployment still needs independent approval.
 */
final class AutonomyReleaseGate
{
    private const CORPUS = ['indian_purchase_bills' => 100, 'erp_copilot_requests' => 100, 'difficult_requests' => 50];
    private const CONTROLS = [
        'tenant_isolation', 'signed_human_approval', 'no_unapproved_business_writes',
        'duplicate_posting_prevention', 'tax_and_totals_deterministic',
        'event_deduplication', 'reversal_request_no_business_writes',
        'company_branch_fy_isolation', 'credit_metering_limits',
    ];

    public function evaluate(array $evidence, bool $runtimeAutonomousPostingEnabled = false): array
    {
        $blockers = [];
        $provenance = $evidence['provenance'] ?? [];
        if (!preg_match('/^[a-f0-9]{40}$/D', (string) ($provenance['git_sha'] ?? ''))
            || empty($provenance['ci_run_id'])) {
            $blockers[] = 'Missing Git SHA and CI run provenance.';
        }
        foreach (self::CORPUS as $group => $expected) {
            if (($evidence['corpus'][$group]['validated'] ?? 0) < $expected) {
                $blockers[] = 'Dataset validation missing for '.$group.'.';
            }
            $execution = $evidence['executed_workflows'][$group] ?? [];
            if (($execution['executed_and_asserted'] ?? 0) < $expected
                || ($execution['evidence_scope'] ?? '') !== 'workflow_execution') {
                $blockers[] = 'Actual workflow execution not evidenced for '.$group.'.';
            }
        }
        foreach (self::CONTROLS as $name) {
            $control = $evidence['controls'][$name] ?? [];
            if (($control['status'] ?? '') !== 'PASSED'
                || !preg_match('/^[a-f0-9]{64}$/D', (string) ($control['artifact_sha256'] ?? ''))) {
                $blockers[] = 'Verified security evidence missing: '.$name.'.';
            }
        }
        foreach ([5, 10, 20] as $users) {
            $trial = $evidence['concurrent_users'][(string) $users] ?? [];
            if (($trial['status'] ?? '') !== 'PASSED'
                || !isset($trial['p95_ms'], $trial['request_count'], $trial['provider_requests'], $trial['throttled_requests'])
                || $trial['p95_ms'] <= 0 || $trial['request_count'] < $users) {
                $blockers[] = 'Measured load evidence missing for '.$users.' users.';
            }
        }
        if (($evidence['live_staging']['status'] ?? '') !== 'PASSED'
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($evidence['live_staging']['artifact_sha256'] ?? ''))) {
            $blockers[] = 'Verified live staging evidence missing.';
        }
        if (($evidence['human_signoff']['security'] ?? '') !== 'APPROVED'
            || ($evidence['human_signoff']['accounting'] ?? '') !== 'APPROVED') {
            $blockers[] = 'Security and accounting sign-off required.';
        }
        if (!$runtimeAutonomousPostingEnabled) {
            $blockers[] = 'Independent autonomous posting kill switch is off.';
        }
        return [
            'required_corpus_counts' => self::CORPUS,
            'required_controls' => self::CONTROLS,
            'status' => $blockers === [] ? 'READY_FOR_INDEPENDENT_REVIEW' : 'BLOCKED',
            'production_approved' => false,
            'blockers' => $blockers,
        ];
    }
}
