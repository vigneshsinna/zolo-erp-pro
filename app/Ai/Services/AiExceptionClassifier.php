<?php

declare(strict_types=1);

namespace App\Ai\Services;

use Illuminate\Support\Carbon;

/**
 * Deterministic triage hints only. Never approves, rejects, or executes a command.
 */
final class AiExceptionClassifier
{
    public function classify(object $proposal, Carbon $now): array
    {
        $status = (string) $proposal->status;
        $issues = [];
        $priority = 'normal';
        if ($status === 'requires_reconciliation') {
            $issues[] = 'Execution outcome needs manual reconciliation before any retry.';
            $priority = 'critical';
        }
        if ($status === 'pending' && $proposal->expires_at
            && Carbon::parse($proposal->expires_at, 'UTC')->lessThanOrEqualTo($now)) {
            $issues[] = 'Proposal expired. Prepare a fresh preview before approving.';
            $priority = 'high';
        }

        $preview = json_decode((string) ($proposal->preview_json ?? '{}'), true);
        if (is_array($preview) && isset($preview['warnings']) && is_array($preview['warnings'])) {
            $n = count($preview['warnings']);
            if ($n > 0) {
                $issues[] = $n.' preview warning'.($n === 1 ? '' : 's').' require review.';
                if ($priority === 'normal') $priority = 'high';
            }
        }

        if ($issues === []) {
            $issues[] = 'Review the proposal and source data before signing.';
        }
        return ['priority' => $priority, 'issues' => $issues];
    }
}
