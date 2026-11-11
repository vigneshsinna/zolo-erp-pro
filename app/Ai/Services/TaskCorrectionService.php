<?php

declare(strict_types=1);

namespace App\Ai\Services;

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
        $actionKey = (string) $task['action_key'];
        $provider = $this->requirementsRegistry->get($actionKey);

        $slots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);

        // Apply field corrections
        foreach ($corrections as $correction) {
            $field = (string) ($correction['field'] ?? '');
            $value = $correction['value'] ?? null;

            if ($field === '') {
                continue;
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

    private function applyFieldCorrection(array &$slots, string $field, mixed $value): void
    {
        // 1. Direct scalar / entity fields
        if ($field === 'supplier_id') {
            $slots['supplier_id'] = (int) $value;
            unset($slots['supplier_query']);
            return;
        }

        if ($field === 'warehouse_id') {
            $slots['warehouse_id'] = (int) $value;
            unset($slots['warehouse_query']);
            return;
        }

        if (in_array($field, ['date', 'note', 'order_discount', 'shipping_cost'], true)) {
            $slots[$field] = $value;
            return;
        }

        // 2. Line item fields (e.g. items[line-0].product_id, items[line-0].qty, items[line-0].net_unit_cost)
        if (preg_match('/^items\[([^\]]+)\]\.([a-z_]+)$/D', $field, $matches)) {
            $clientLineId = $matches[1];
            $property = $matches[2];

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
                // New line with this client_line_id
                $lineIndex = count($slots['items']);
                $slots['items'][$lineIndex] = [
                    'client_line_id' => $clientLineId,
                ];
            }

            if ($property === 'product_id') {
                $slots['items'][$lineIndex]['product_id'] = (int) $value;
                unset($slots['items'][$lineIndex]['product_query']);
                // Dependency invalidation: clear previously assumed UOM or cost if product changed
                unset($slots['items'][$lineIndex]['uom']);
            } elseif ($property === 'qty') {
                $slots['items'][$lineIndex]['qty'] = is_numeric($value) ? sprintf('%.2f', (float) $value) : $value;
            } elseif ($property === 'net_unit_cost') {
                $slots['items'][$lineIndex]['net_unit_cost'] = is_numeric($value) ? sprintf('%.2f', (float) $value) : $value;
            } else {
                $slots['items'][$lineIndex][$property] = $value;
            }
        }
    }
}
