<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\EvaluationResult;

final class ClarificationResponseBuilder
{
    /**
     * Build one consolidated, tenant-safe clarification response covering all current blockers.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public function build(array $task, EvaluationResult $evaluation): array
    {
        $firstIssue = $evaluation->firstBlockingIssue();
        $missingField = 'slots';
        $message = 'Additional details are required to complete this proposal.';
        $candidates = [];

        if ($firstIssue) {
            $missingField = match (true) {
                $firstIssue->field === 'supplier_id' => 'supplier',
                str_contains($firstIssue->field, 'items') && str_contains($firstIssue->field, 'product_id') => preg_replace('/items\[line-(\d+)\]\.product_id/', 'items.$1.product', $firstIssue->field),
                default => $firstIssue->field,
            };
            $message = $firstIssue->reason;
            $candidates = $firstIssue->candidates;
        }

        return [
            'status' => 'needs_input',
            'task_id' => (string) $task['id'],
            'task_revision' => (int) $task['revision'],
            'action_key' => (string) $task['action_key'],
            'missing_field' => $missingField,
            'message' => $message,
            'candidates' => $candidates,
            'confirmed_fields' => $evaluation->confirmedFields,
            'required_inputs' => $evaluation->issuesArray(),
            'pending_checks' => $evaluation->pendingChecks,
            'next_action' => 'submit_all_corrections',
            'can_prepare_proposal' => false,
        ];
    }
}
