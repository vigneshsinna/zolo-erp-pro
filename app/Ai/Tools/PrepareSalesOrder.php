<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\BatchRecordResolutionService;
use App\Ai\Services\ClarificationResponseBuilder;
use App\Ai\Services\InputCompletenessEvaluator;
use App\Ai\Services\RecordResolutionService;
use App\Ai\Services\WorkflowTaskStateService;
use App\Services\Platform\CompanyContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging natural language sales order instructions to the Action Proposal pipeline.
 * Uses the Phase 2.1 Unified Multi-Slot Input Collection & Clarification Engine:
 * - Collects and resolves ALL independently resolvable entities (customer, warehouse, products across all lines)
 * - Returns ONE consolidated clarification response when blocking issues exist
 * - Retains confirmed values across turns via durable workflow task persistence
 * - Creates a governed, versioned Action Proposal only when all prerequisites are satisfied
 * - Strictly READ-ONLY with ZERO ledger or stock writes.
 */
final class PrepareSalesOrder implements Tool
{
    private RecordResolutionService $resolver;
    private WorkflowTaskStateService $taskState;
    private ActionRequirementsRegistry $requirementsRegistry;
    private BatchRecordResolutionService $batchResolver;
    private InputCompletenessEvaluator $evaluator;
    private ClarificationResponseBuilder $clarificationBuilder;
    private ActionProposalService $proposalService;

    public function __construct(
        private readonly AiPrincipal $principal,
        ?RecordResolutionService $resolver = null,
        ?WorkflowTaskStateService $taskState = null,
        ?ActionRequirementsRegistry $requirementsRegistry = null,
        ?BatchRecordResolutionService $batchResolver = null,
        ?InputCompletenessEvaluator $evaluator = null,
        ?ClarificationResponseBuilder $clarificationBuilder = null,
        ?ActionProposalService $proposalService = null,
    ) {
        $this->resolver = $resolver ?? app(RecordResolutionService::class);
        $this->taskState = $taskState ?? app(WorkflowTaskStateService::class);
        $this->requirementsRegistry = $requirementsRegistry ?? app(ActionRequirementsRegistry::class);
        $this->batchResolver = $batchResolver ?? app(BatchRecordResolutionService::class);
        $this->evaluator = $evaluator ?? app(InputCompletenessEvaluator::class);
        $this->clarificationBuilder = $clarificationBuilder ?? app(ClarificationResponseBuilder::class);
        $this->proposalService = $proposalService ?? app(ActionProposalService::class);
    }

    public function name(): string
    {
        return 'prepare_sales_order';
    }

    public function description(): string
    {
        return 'Prepares a draft Sales Order proposal from natural language parameters (customer, products, quantities, prices, warehouse). Validates customer and product entities, checks branch warehouse, calculates taxes and totals, and creates an editable preview for human sign-off with zero financial writes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_query' => $schema->string()
                ->description('Customer name, company name, VAT/GSTIN, phone, or ID'),
            'customer_id' => $schema->integer()
                ->description('Explicit customer ID if known'),
            'items' => $schema->array()
                ->items($schema->object([
                    'client_line_id' => $schema->string()
                        ->description('Stable client line identifier (e.g. line-0, line-1)'),
                    'product_query' => $schema->string()
                        ->description('Product name, code, SKU, or alias'),
                    'product_id' => $schema->integer()
                        ->description('Explicit product ID if known'),
                    'qty' => $schema->number()
                        ->description('Quantity to order (e.g. 10)'),
                    'net_unit_price' => $schema->number()
                        ->description('Optional agreed selling price. If omitted, uses standard product selling price.'),
                    'discount' => $schema->number()
                        ->description('Optional line discount amount'),
                    'tax_rate' => $schema->number()
                        ->description('Optional tax rate percentage override'),
                    'uom' => $schema->string()
                        ->description('Optional unit of measure (e.g. PCS, BOX, MTR)'),
                    'sale_unit_id' => $schema->integer()
                        ->description('Optional sale unit ID'),
                    'note' => $schema->string()
                        ->description('Optional line item note'),
                ]))
                ->description('List of line items to include in the sales order'),
            'warehouse_query' => $schema->string()
                ->description('Optional dispatch warehouse name or ID. Defaults to the active branch warehouse.'),
            'warehouse_id' => $schema->integer()
                ->description('Explicit dispatch warehouse ID if known'),
            'date' => $schema->string()
                ->description('Optional order date in YYYY-MM-DD format. Defaults to today.'),
            'delivery_date' => $schema->string()
                ->description('Optional promised delivery date in YYYY-MM-DD format.'),
            'customer_reference_no' => $schema->string()
                ->description('Optional customer PO number or purchase reference.'),
            'note' => $schema->string()
                ->description('Optional overall order note or reference'),
            'order_discount' => $schema->number()
                ->description('Optional overall order discount amount'),
            'shipping_cost' => $schema->number()
                ->description('Optional shipping or freight cost amount'),
            'task_id' => $schema->string()
                ->description('Optional active workflow task ID to resume or patch an existing collection session'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $context = new CompanyContext(
            $this->principal->companyId,
            $this->principal->branchId,
            $this->principal->financialYearId
        );

        $taskContext = new WorkflowTaskContext(
            principal: $this->principal,
            context: $context,
            mode: 'company',
            taskId: !empty($args['task_id']) ? (string) $args['task_id'] : null,
            threadId: $this->principal->requestId ?? null,
        );

        $actionKey = 'sales_order.create.v1';
        $provider = $this->requirementsRegistry->get($actionKey);

        // Retrieve existing task or create a new one
        $taskId = !empty($args['task_id']) ? (string) $args['task_id'] : null;
        $task = null;
        if ($taskId) {
            try {
                $task = $this->taskState->getTask($this->principal, $taskId);
            } catch (Throwable) {
                $task = null;
            }
        }

        if (!$task) {
            $task = $this->taskState->createTask($taskContext, $actionKey, $args);
            $slots = $args;
        } else {
            // Merge with existing slots, preserving confirmed values
            $existingSlots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
            $slots = array_replace_recursive($existingSlots, $args);
        }

        // Full-pass batch record resolution across all independent references
        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);

        // Deterministic completeness evaluation
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        // If blocking issues exist, persist state and return consolidated clarification response
        if ($evaluation->hasBlockingIssues()) {
            $updatedTask = $this->taskState->updateTaskState(
                principal: $this->principal,
                taskId: (string) $task['id'],
                expectedRevision: (int) $task['revision'],
                slots: $slots,
                resolution: [
                    'resolved_ids' => $batchResult->resolvedIds,
                ],
                issues: $evaluation->issuesArray(),
                status: 'collecting',
            );

            $clarification = $this->clarificationBuilder->build($updatedTask, $evaluation);

            return json_encode($clarification, JSON_THROW_ON_ERROR);
        }

        // All inputs are valid and complete: construct governed Action Proposal
        try {
            $validatedSlots = $evaluation->validatedSlots;
            $proposal = $this->proposalService->create(
                $this->principal,
                $actionKey,
                $validatedSlots,
                $this->principal->requestId ?? null,
            );

            // Link proposal to workflow task
            $this->taskState->linkProposal((string) $task['id'], (string) $proposal['id']);

            $preview = json_decode((string) ($proposal['preview_json'] ?? '{}'), true) ?? [];

            $factData = array_merge($preview, [
                'proposal_id' => $proposal['id'],
                'version' => (int) $proposal['version'],
                'action_key' => $actionKey,
                'task_id' => $task['id'],
            ]);

            return json_encode([
                'status' => 'ok',
                'proposal' => $proposal,
                'tool' => $actionKey,
                'as_of' => $validatedSlots['date'] ?? now()->toDateString(),
                'data' => $factData,
                'source_refs' => ['/sales'],
                'reconciled' => true,
                'row_count' => count($validatedSlots['items'] ?? []),
            ], JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'task_id' => $task['id'],
                'missing_field' => 'validation',
                'message' => implode(' ', $e->validator->errors()->all()),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_DOMAIN_EXECUTION_FAILURE',
                'message' => 'Could not generate sales order proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}
