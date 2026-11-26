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

        $slots = json_decode((string) ($task['slots_json'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
        $lineItems = [];
        $subtotal = 0.0;
        foreach ($slots['items'] ?? [] as $index => $line) {
            $lineId = (string) ($line['client_line_id'] ?? "line-{$index}");
            $product = collect($evaluation->confirmedFields)->firstWhere('field', "items[{$lineId}].product_id");
            $productName = $product['display'] ?? $line['product_name'] ?? $line['product_query'] ?? ('Product ' . ($index + 1));
            $qty = isset($line['qty']) && is_numeric($line['qty']) ? (float) $line['qty'] : null;
            $rate = isset($line['net_unit_cost']) && is_numeric($line['net_unit_cost'])
                ? (float) $line['net_unit_cost']
                : (isset($line['net_unit_price']) && is_numeric($line['net_unit_price'])
                    ? (float) $line['net_unit_price']
                    : (isset($line['rate']) && is_numeric($line['rate']) ? (float) $line['rate'] : null));
            $lineTotal = ($qty !== null && $rate !== null) ? round($qty * $rate, 2) : 0.0;
            $subtotal += $lineTotal;

            $lineItems[] = [
                'client_line_id' => $lineId,
                'product_id' => $line['product_id'] ?? null,
                'product_name' => $productName,
                'label' => $productName,
                'qty' => $qty,
                'quantity' => $qty,
                'net_unit_cost' => $rate,
                'net_unit_price' => $rate,
                'unit_price' => $rate,
                'line_total' => $lineTotal,
                'total' => $lineTotal,
            ];
        }

        $totals = [
            'subtotal' => $subtotal,
            'tax_total' => 0.0,
            'grand_total' => $subtotal,
        ];

        return [
            'status' => 'needs_input',
            'task_id' => (string) $task['id'],
            'task_revision' => (int) $task['revision'],
            'action_key' => (string) $task['action_key'],
            'missing_field' => $missingField,
            'message' => $message,
            'candidates' => $candidates,
            'confirmed_fields' => $evaluation->confirmedFields,
            'line_items' => $lineItems,
            'totals' => $totals,
            'required_inputs' => $evaluation->issuesArray(),
            'pending_checks' => $evaluation->pendingChecks,
            'next_action' => 'submit_all_corrections',
            'can_prepare_proposal' => false,
        ];
    }
}
