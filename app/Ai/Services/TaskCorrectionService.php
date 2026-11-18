<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Adapters\CommercialDecimal;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\EvaluationResult;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

final class TaskCorrectionService
{
    public function __construct(
        private readonly WorkflowTaskStateService $taskState,
        private readonly ActionRequirementsRegistry $requirementsRegistry,
        private readonly BatchRecordResolutionService $batchResolver,
        private readonly InputCompletenessEvaluator $evaluator,
        private readonly ClarificationResponseBuilder $clarificationBuilder,
    ) {
    }

    /**
     * Apply consolidated user corrections with optimistic concurrency check,
     * invalidates dependent fields when a parent entity changes, and re-evaluates completeness.
     *
     * @param list<array{field: string, value: mixed, confirmation?: string}> $corrections
     * @return array{task: array, evaluation: EvaluationResult, clarification: ?array}
     */
    public function applyCorrections(
        AiPrincipal|BootstrapSetupPrincipal $principal,
        string $taskId,
        int $expectedRevision,
        array $corrections,
    ): array {
        $task = $this->taskState->getTask($principal, $taskId);
        if (!in_array($task['status'], ['collecting', 'ready', 'proposal_created', 'awaiting_master'], true)
            || $task['expires_at'] <= now('UTC')->toDateTimeString()) {
            throw ValidationException::withMessages(['task' => 'This draft is no longer editable.']);
        }
        $actionKey = (string) $task['action_key'];
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get($actionKey);
        if ($principal instanceof AiPrincipal)
            app(ActionSecurity::class)->definition($actionKey, $principal);
        $rules = app($definition->inputSchemaClass)->rules();
        $provider = $this->requirementsRegistry->get($actionKey);

        $slots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($slots['items'] ?? [] as $index => $line) {
            $slots['items'][$index]['client_line_id'] ??= "line-{$index}";
        }

        // Apply field corrections
        foreach ($corrections as $correction) {
            $field = (string) ($correction['field'] ?? '');
            $value = $correction['value'] ?? null;

            if ($field === '') {
                continue;
            }
            $schemaField = preg_replace('/^items(?:\[[^\]]+\]|\.[^.]+)\./', 'items.*.', $field);
            $internalField = (in_array($field, ['supplier_query', 'warehouse_query', 'customer_query'], true)
                    && isset($rules[str_replace('_query', '_id', $field)]))
                || preg_match('/^items\[[a-zA-Z0-9_-]+\]\.(?:_destroy|product_query|uom)$/D', $field);
            if (!isset($rules[$schemaField]) && !$internalField) {
                throw ValidationException::withMessages(['corrections' => 'Unsupported field correction.']);
            }

            if (isset($rules[$field]) && !str_contains($field, '.')) {
                $fieldRules = is_array($rules[$field]) ? implode('|', $rules[$field]) : $rules[$field];
                if (str_contains($fieldRules, 'array') && is_string($value)) {
                    try {
                        $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        if ($field === 'items' && trim($value) !== '') {
                            $value = [
                                [
                                    'client_line_id' => 'line-0',
                                    'product_query' => trim($value),
                                    'qty' => '1.00',
                                ]
                            ];
                        } else {
                            throw ValidationException::withMessages([$field => 'Enter a valid JSON array.']);
                        }
                    }
                }
                if (!in_array($field, ['supplier_id', 'customer_id', 'warehouse_id', 'items'], true)) {
                    $slots[$field] = $value;
                    continue;
                }
            }
            $this->applyFieldCorrection($slots, $field, $value);
        }

        // Contextual re-evaluation
        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $principal instanceof AiPrincipal
            ? new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId)
            : null,
            mode: (string) $task['mode'],
            taskId: $taskId,
            threadId: (string) $task['thread_id'],
        );

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        $newStatus = $evaluation->isReady() ? 'ready' : 'collecting';

        $updatedTask = $this->taskState->updateTaskState(
            principal: $principal,
            taskId: $taskId,
            expectedRevision: $expectedRevision,
            slots: $slots,
            resolution: [
                'resolved_ids' => $batchResult->resolvedIds,
            ],
            issues: $evaluation->issuesArray(),
            status: $newStatus,
            patch: $corrections,
        );

        $clarification = null;
        if (!$evaluation->isReady()) {
            $clarification = $this->clarificationBuilder->build($updatedTask, $evaluation);
        }

        return [
            'task' => $updatedTask,
            'evaluation' => $evaluation,
            'clarification' => $clarification,
        ];
    }

    public function prepareProposal(AiPrincipal $principal, array $task, EvaluationResult $evaluation): array
    {
        $current = $this->taskState->getTask($principal, $task['id']);
        if ((int) $current['revision'] !== (int) $task['revision']) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Draft changed before proposal preparation.');
        }
        if (!in_array($current['status'], ['collecting', 'ready', 'proposal_created'], true) || $current['expires_at'] <= now('UTC')->toDateTimeString()) {
            throw ValidationException::withMessages(['task' => 'This draft is no longer editable.']);
        }
        if (!$evaluation->isReady()) {
            throw ValidationException::withMessages(['task' => 'ERP validation is incomplete.']);
        }
        $service = app(ActionProposalService::class);
        if ($task['linked_proposal_id']) {
            $old = $service->getOwned($principal, $task['linked_proposal_id']);
            $proposal = $service->applyChangeRequest($principal, $old['id'], $evaluation->validatedSlots, (int) $old['version'], reuseUnchanged: true);
        } else {
            $proposal = $service->create($principal, $task['action_key'], $evaluation->validatedSlots, $task['thread_id']);
            $this->taskState->linkProposal($task['id'], $proposal['id']);
        }
        return ['status' => 'proposal_created', 'task_id' => $task['id'], 'task_revision' => (int) $task['revision'],
            'action_key' => $task['action_key'], 'proposal_id' => $proposal['id'], 'version' => (int) $proposal['version'],
            'preview' => json_decode($proposal['preview_json'], true, flags: JSON_THROW_ON_ERROR),
            'message' => 'ERP validation passed. Review this proposal and use the explicit approval control to create the order.',
            'can_prepare_proposal' => true];
    }

    private function applyFieldCorrection(array &$slots, string $field, mixed $value): void
    {
        if (in_array($field, ['supplier_query', 'warehouse_query', 'customer_query'], true)) {
            $slots[$field] = trim((string) $value);
            unset($slots[str_replace('_query', '_id', $field)]);
            return;
        }
        // 1. Direct scalar / entity fields
        if ($field === 'supplier_id') {
            if (is_numeric($value) && (int) $value > 0) {
                $slots['supplier_id'] = (int) $value;
                unset($slots['supplier_query']);
            } else {
                $slots['supplier_query'] = trim((string) $value);
                unset($slots['supplier_id']);
            }
            return;
        }

        if ($field === 'warehouse_id') {
            if (is_numeric($value) && (int) $value > 0) {
                $slots['warehouse_id'] = (int) $value;
                unset($slots['warehouse_query']);
            } else {
                $slots['warehouse_query'] = trim((string) $value);
                unset($slots['warehouse_id']);
            }
            return;
        }

        if ($field === 'customer_id') {
            if (is_numeric($value) && (int) $value > 0) {
                $slots['customer_id'] = (int) $value;
                unset($slots['customer_query']);
            } else {
                $slots['customer_query'] = trim((string) $value);
                unset($slots['customer_id']);
            }
            return;
        }

        if ($field === 'items') {
            if (is_array($value)) {
                $cleanItems = [];
                foreach ($value as $idx => $line) {
                    if (!is_array($line))
                        continue;
                    $lineId = (string) ($line['client_line_id'] ?? "line-{$idx}");
                    $cleanLine = array_intersect_key($line, array_flip(['client_line_id', 'uom', 'discount', 'tax_rate', 'note', 'sale_unit_id']));
                    $cleanLine['client_line_id'] = $lineId;
                    if (!empty($line['product_id']) && is_numeric($line['product_id'])) {
                        $cleanLine['product_id'] = (int) $line['product_id'];
                    } elseif (!empty($line['product_query']) || !empty($line['product_name'])) {
                        $cleanLine['product_query'] = trim((string) ($line['product_query'] ?? $line['product_name']));
                    }
                    if (isset($line['qty']) && is_numeric($line['qty'])) {
                        $cleanLine['qty'] = CommercialDecimal::format((float) $line['qty']);
                    } else {
                        $cleanLine['qty'] = '1.00';
                    }
                    if (isset($line['net_unit_cost']) && is_numeric($line['net_unit_cost'])) {
                        $cleanLine['net_unit_cost'] = CommercialDecimal::format((float) $line['net_unit_cost']);
                        $cleanLine['net_unit_price'] = CommercialDecimal::format((float) $line['net_unit_cost']);
                    } elseif (isset($line['net_unit_price']) && is_numeric($line['net_unit_price'])) {
                        $cleanLine['net_unit_cost'] = CommercialDecimal::format((float) $line['net_unit_price']);
                        $cleanLine['net_unit_price'] = CommercialDecimal::format((float) $line['net_unit_price']);
                    } elseif (isset($line['rate']) && is_numeric($line['rate'])) {
                        $cleanLine['net_unit_cost'] = CommercialDecimal::format((float) $line['rate']);
                        $cleanLine['net_unit_price'] = CommercialDecimal::format((float) $line['rate']);
                    }
                    if (isset($line['purchase_unit_id']) && is_numeric($line['purchase_unit_id'])) {
                        $cleanLine['purchase_unit_id'] = (int) $line['purchase_unit_id'];
                    }
                    $cleanItems[] = $cleanLine;
                }
                $slots['items'] = $cleanItems;
            } elseif (is_string($value) && trim($value) !== '') {
                $slots['items'] = [
                    [
                        'client_line_id' => 'line-0',
                        'product_query' => trim($value),
                        'qty' => '1.00',
                    ]
                ];
            }
            return;
        }

        if (
            in_array($field, [
                'date',
                'note',
                'order_discount',
                'shipping_cost',
                'biller_id',
                'valid_until',
                'sales_order_id',
                'delivery_challan_id',
                'purpose',
                'due_date',
                'open_item_id',
                'amount',
                'settlement_account_id'
            ], true)
        ) {
            $slots[$field] = $value;
            return;
        }

        // 2. Line item fields (e.g. items[line-0].product_id, items.0.net_unit_cost, items[0].qty)
        if (preg_match('/^items(?:\[([^\]]+)\]|\.([0-9a-zA-Z\-_]+))\.([a-z_]+)$/D', $field, $matches)) {
            $clientLineId = !empty($matches[1]) ? $matches[1] : $matches[2];
            $property = $matches[3];

            if (!isset($slots['items']) || !is_array($slots['items'])) {
                $slots['items'] = [];
            }

            $lineIndex = null;
            foreach ($slots['items'] as $idx => $item) {
                if (($item['client_line_id'] ?? null) === $clientLineId || (string) $idx === $clientLineId) {
                    $lineIndex = $idx;
                    break;
                }
            }

            if ($lineIndex === null) {
                if ($property === '_destroy') {
                    throw ValidationException::withMessages(['items' => 'The selected line no longer exists. Reload the task.']);
                }
                // New line with this client_line_id
                $lineIndex = count($slots['items']);
                $slots['items'][$lineIndex] = [
                    'client_line_id' => $clientLineId,
                ];
            }

            if ($property === '_destroy') {
                if ($value !== true)
                    throw ValidationException::withMessages(['items' => 'Line deletion requires an explicit true value.']);
                unset($slots['items'][$lineIndex]);
                $slots['items'] = array_values($slots['items']);
            } elseif (in_array($property, ['product_id', 'product_query'], true)) {
                if ($property === 'product_id' && is_numeric($value) && (int) $value > 0) {
                    $slots['items'][$lineIndex]['product_id'] = (int) $value;
                    unset($slots['items'][$lineIndex]['product_query']);
                } else {
                    $slots['items'][$lineIndex]['product_query'] = trim((string) $value);
                    unset($slots['items'][$lineIndex]['product_id']);
                }
                // Dependency invalidation: clear previously assumed UOM or cost if product changed
                foreach (['uom', 'purchase_unit_id', 'sale_unit_id', 'net_unit_cost', 'net_unit_price', 'tax_rate', 'hsn_code'] as $dependent) {
                    unset($slots['items'][$lineIndex][$dependent]);
                }
            } elseif (in_array($property, ['purchase_unit_id', 'sale_unit_id'], true)) {
                $slots['items'][$lineIndex][$property] = $value;
                unset($slots['items'][$lineIndex]['uom']);
            } elseif ($property === 'uom') {
                $slots['items'][$lineIndex]['uom'] = $value;
                unset($slots['items'][$lineIndex]['purchase_unit_id'], $slots['items'][$lineIndex]['sale_unit_id']);
            } elseif ($property === 'qty') {
                $slots['items'][$lineIndex]['qty'] = is_numeric($value) ? CommercialDecimal::format((float) $value) : $value;
            } elseif ($property === 'net_unit_cost' || $property === 'net_unit_price') {
                $val = (is_numeric($value) && (float) $value >= 0) ? CommercialDecimal::format((float) $value) : '0.00';
                $slots['items'][$lineIndex]['net_unit_cost'] = $val;
                $slots['items'][$lineIndex]['net_unit_price'] = $val;
            } else {
                $slots['items'][$lineIndex][$property] = $value;
            }
        }
    }
}
