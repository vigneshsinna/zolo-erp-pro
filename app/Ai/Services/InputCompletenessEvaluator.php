<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\BatchResolutionResult;
use App\Ai\DTOs\EvaluationResult;
use App\Ai\DTOs\FieldIssue;
use App\Ai\DTOs\WorkflowTaskContext;

final class InputCompletenessEvaluator
{
    /**
     * Deterministically evaluates completeness, validity, ambiguity, and dependencies across all requirements.
     * Never returns on first error; evaluates all fields and lines in a single pass.
     *
     * @param array<string, mixed> $slots
     */
    public function evaluate(
        ActionRequirementsProviderInterface $provider,
        WorkflowTaskContext $taskContext,
        array $slots,
        BatchResolutionResult $batchResult,
    ): EvaluationResult {
        $issues = [];
        $confirmed = [];
        $pendingChecks = [];
        $cleanSlots = [];

        $requirements = $provider->requirements($taskContext);

        foreach ($requirements as $req) {
            if ($req->isList) {
                $this->evaluateListRequirement($req, $taskContext, $slots, $batchResult, $issues, $confirmed, $cleanSlots);
                continue;
            }

            $this->evaluateScalarRequirement($req, $taskContext, $slots, $batchResult, $issues, $confirmed, $cleanSlots);
        }

        // Add downstream pending checks when prerequisite entities are unresolved
        $hasSupplierIssue = collect($issues)->contains(fn (FieldIssue $i) => $i->field === 'supplier_id');
        if ($hasSupplierIssue) {
            $pendingChecks[] = 'Authoritative GST determination and party tax calculation';
        }

        $hasProductIssue = collect($issues)->contains(fn (FieldIssue $i) => str_contains($i->field, 'product_id') || str_contains($i->field, 'qty'));
        if ($hasProductIssue) {
            $pendingChecks[] = 'Commercial order line pricing and grand total reconciliation';
        }

        // If no blocking entity or format issues exist, run authoritative domain schema validation
        if (empty($issues)) {
            $validationErrors = $provider->validate($taskContext, $cleanSlots);
            foreach ($validationErrors as $field => $message) {
                $issues[] = new FieldIssue(
                    field: $field,
                    label: ucwords(str_replace(['.', '_'], ' ', $field)),
                    status: 'invalid',
                    reason: $message,
                );
            }
        }

        return new EvaluationResult(
            issues: $issues,
            confirmedFields: $confirmed,
            pendingChecks: $pendingChecks,
            validatedSlots: $cleanSlots,
        );
    }

    private function evaluateScalarRequirement(
        ActionFieldRequirement $req,
        WorkflowTaskContext $taskContext,
        array $slots,
        BatchResolutionResult $batchResult,
        array &$issues,
        array &$confirmed,
        array &$cleanSlots,
    ): void {
        $field = $req->fieldPath;
        $resolvedId = $batchResult->getResolvedId($field);
        $entity = $batchResult->getEntity($field);
        $resolution = $batchResult->getResolution($field);

        if ($resolvedId !== null) {
            $displayName = $entity && isset($entity->name) ? (string) $entity->name : (string) $resolvedId;
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => $displayName,
                'source' => isset($slots[$field]) ? 'user' : 'resolved',
            ];
            $cleanSlots[$field] = $resolvedId;
            return;
        }

        if ($resolution !== null) {
            if ($resolution['status'] === 'candidates') {
                $query = $resolution['query'] ?? '';
                $reason = $field === 'supplier_id'
                    ? "Multiple suppliers matched '{$query}'. Please select the intended {$req->label}."
                    : "Multiple matches found for '{$query}'. Please select the intended {$req->label}.";
                $issues[] = new FieldIssue(
                    field: $field,
                    label: $req->label,
                    status: 'ambiguous',
                    reason: $reason,
                    control: $req->inputControl,
                    candidates: $resolution['candidates'] ?? [],
                );
                return;
            }

            if ($resolution['status'] === 'none') {
                $query = $resolution['query'] ?? '';
                $issues[] = new FieldIssue(
                    field: $field,
                    label: $req->label,
                    status: 'missing',
                    reason: "No match found for '{$query}'. Please select an existing {$req->label} or check spelling.",
                    control: $req->inputControl,
                    candidates: [],
                );
                return;
            }
        }

        // Check if a direct slot value was provided for non-entity fields (e.g. date, note, order_discount)
        if (isset($slots[$field]) && $slots[$field] !== '' && $slots[$field] !== null) {
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => (string) $slots[$field],
                'source' => 'user',
            ];
            $cleanSlots[$field] = $slots[$field];
            return;
        }

        // Check default policy
        $default = $req->resolveDefault($taskContext, $slots);
        if ($default !== null) {
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => (string) $default,
                'source' => 'erp_default',
            ];
            $cleanSlots[$field] = $default;
            return;
        }

        // If required and still empty, report missing
        if ($req->isRequired($slots, $taskContext)) {
            $issues[] = new FieldIssue(
                field: $field,
                label: $req->label,
                status: 'missing',
                reason: "{$req->label} is required.",
                control: $req->inputControl,
            );
        }
    }

    private function evaluateListRequirement(
        ActionFieldRequirement $req,
        WorkflowTaskContext $taskContext,
        array $slots,
        BatchResolutionResult $batchResult,
        array &$issues,
        array &$confirmed,
        array &$cleanSlots,
    ): void {
        $rawItems = (array) ($slots[$req->fieldPath] ?? []);

        if (empty($rawItems)) {
            if ($req->isRequired($slots, $taskContext)) {
                $issues[] = new FieldIssue(
                    field: $req->fieldPath,
                    label: $req->label,
                    status: 'missing',
                    reason: 'At least one line item is required.',
                    control: 'list',
                );
            }
            return;
        }

        $cleanLines = [];

        foreach ($rawItems as $index => $line) {
            $clientLineId = (string) ($line['client_line_id'] ?? "line-{$index}");
            $lineIndex = $index + 1;
            $cleanLine = [
                'client_line_id' => $clientLineId,
            ];

            // 1. Evaluate Product ID for this line
            $productFieldKey = "items[{$clientLineId}].product_id";
            $resolvedProductId = $batchResult->getResolvedId($productFieldKey);
            $productEntity = $batchResult->getEntity($productFieldKey);
            $productResolution = $batchResult->getResolution($productFieldKey);

            if ($resolvedProductId !== null) {
                $pName = $productEntity && isset($productEntity->name) ? (string) $productEntity->name : "Product #{$resolvedProductId}";
                $pCode = $productEntity && isset($productEntity->code) ? " (SKU: {$productEntity->code})" : '';
                $confirmed[] = [
                    'field' => $productFieldKey,
                    'label' => "Line {$lineIndex} Product",
                    'display' => "{$pName}{$pCode}",
                    'source' => isset($line['product_id']) ? 'user' : 'resolved',
                ];
                $cleanLine['product_id'] = (int) $resolvedProductId;
            } elseif ($productResolution !== null && $productResolution['status'] === 'candidates') {
                $query = $productResolution['query'] ?? '';
                $issues[] = new FieldIssue(
                    field: $productFieldKey,
                    label: "Line {$lineIndex} Product",
                    status: 'ambiguous',
                    reason: "Multiple products match '{$query}'. Select the specific SKU.",
                    control: 'entity_select',
                    candidates: $productResolution['candidates'] ?? [],
                    clientLineId: $clientLineId,
                );
            } elseif ($productResolution !== null && $productResolution['status'] === 'none') {
                $query = $productResolution['query'] ?? '';
                $issues[] = new FieldIssue(
                    field: $productFieldKey,
                    label: "Line {$lineIndex} Product",
                    status: 'missing',
                    reason: "Could not find any product matching '{$query}'.",
                    control: 'entity_select',
                    clientLineId: $clientLineId,
                );
            } else {
                $issues[] = new FieldIssue(
                    field: $productFieldKey,
                    label: "Line {$lineIndex} Product",
                    status: 'missing',
                    reason: "Product is required for line {$lineIndex}.",
                    control: 'entity_select',
                    clientLineId: $clientLineId,
                );
            }

            // 2. Evaluate Quantity for this line
            $qtyFieldKey = "items[{$clientLineId}].qty";
            if (isset($line['qty']) && is_numeric($line['qty']) && (float) $line['qty'] > 0) {
                $qtyVal = (float) $line['qty'];
                $confirmed[] = [
                    'field' => $qtyFieldKey,
                    'label' => "Line {$lineIndex} Quantity",
                    'display' => sprintf('%.2f units', $qtyVal),
                    'source' => 'user',
                ];
                $cleanLine['qty'] = sprintf('%.2f', $qtyVal);
            } else {
                $issues[] = new FieldIssue(
                    field: $qtyFieldKey,
                    label: "Line {$lineIndex} Quantity",
                    status: isset($line['qty']) ? 'invalid' : 'missing',
                    reason: 'Quantity must be a positive number.',
                    control: 'number',
                    clientLineId: $clientLineId,
                );
            }

            // 3. Evaluate Unit Cost for this line
            $costFieldKey = "items[{$clientLineId}].net_unit_cost";
            if (isset($line['net_unit_cost']) && is_numeric($line['net_unit_cost']) && (float) $line['net_unit_cost'] >= 0) {
                $costVal = (float) $line['net_unit_cost'];
                $confirmed[] = [
                    'field' => $costFieldKey,
                    'label' => "Line {$lineIndex} Unit Cost",
                    'display' => sprintf('₹%.2f', $costVal),
                    'source' => 'user',
                ];
                $cleanLine['net_unit_cost'] = sprintf('%.2f', $costVal);
            } elseif ($productEntity && isset($productEntity->cost)) {
                $cleanLine['net_unit_cost'] = sprintf('%.2f', (float) $productEntity->cost);
                $confirmed[] = [
                    'field' => $costFieldKey,
                    'label' => "Line {$lineIndex} Unit Cost",
                    'display' => sprintf('₹%.2f (Master Cost)', (float) $productEntity->cost),
                    'source' => 'erp_default',
                ];
            }

            // 4. Evaluate Unit for this line
            $unitFieldKey = "items[{$clientLineId}].purchase_unit_id";
            $resolvedUnitId = $batchResult->getResolvedId($unitFieldKey);
            if ($resolvedUnitId !== null) {
                $cleanLine['purchase_unit_id'] = (int) $resolvedUnitId;
            } elseif ($productEntity) {
                $cleanLine['purchase_unit_id'] = (int) ($productEntity->purchase_unit_id ?? $productEntity->unit_id ?? 1);
            }

            if (isset($line['discount']) && is_numeric($line['discount'])) {
                $cleanLine['discount'] = sprintf('%.2f', (float) $line['discount']);
            }
            if (isset($line['tax_rate']) && is_numeric($line['tax_rate'])) {
                $cleanLine['tax_rate'] = sprintf('%.2f', (float) $line['tax_rate']);
            }
            if (isset($line['note'])) {
                $cleanLine['note'] = (string) $line['note'];
            }

            $cleanLines[] = $cleanLine;
        }

        $cleanSlots[$req->fieldPath] = $cleanLines;
    }
}
