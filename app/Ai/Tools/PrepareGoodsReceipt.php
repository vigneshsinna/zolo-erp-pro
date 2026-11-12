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
use App\Ai\Services\WorkflowTaskStateService;
use App\Services\Platform\CompanyContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging natural language goods receipt instructions to the Action Proposal pipeline.
 * Action Key: grn.create.v1.
 * Strictly READ-ONLY with ZERO ledger or stock writes.
 */
final class PrepareGoodsReceipt implements Tool
{
    private WorkflowTaskStateService $taskState;
    private ActionRequirementsRegistry $requirementsRegistry;
    private BatchRecordResolutionService $batchResolver;
    private InputCompletenessEvaluator $evaluator;
    private ClarificationResponseBuilder $clarificationBuilder;
    private ActionProposalService $proposalService;

    public function __construct(
        private readonly AiPrincipal $principal,
        ?WorkflowTaskStateService $taskState = null,
        ?ActionRequirementsRegistry $requirementsRegistry = null,
        ?BatchRecordResolutionService $batchResolver = null,
        ?InputCompletenessEvaluator $evaluator = null,
        ?ClarificationResponseBuilder $clarificationBuilder = null,
        ?ActionProposalService $proposalService = null,
    ) {
        $this->taskState = $taskState ?? app(WorkflowTaskStateService::class);
        $this->requirementsRegistry = $requirementsRegistry ?? app(ActionRequirementsRegistry::class);
        $this->batchResolver = $batchResolver ?? app(BatchRecordResolutionService::class);
        $this->evaluator = $evaluator ?? app(InputCompletenessEvaluator::class);
        $this->clarificationBuilder = $clarificationBuilder ?? app(ClarificationResponseBuilder::class);
        $this->proposalService = $proposalService ?? app(ActionProposalService::class);
    }

    public function name(): string
    {
        return 'prepare_goods_receipt';
    }

    public function description(): string
    {
        return 'Prepares a Goods Received Note (GRN) draft proposal from natural language (supplier, warehouse, received items, quantities, costs, purchase order reference). Previews physical stock intake and inventory accrual without making unapproved writes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_query' => $schema->string()
                ->description('Supplier name, company name, VAT/GSTIN, code, or ID'),
            'supplier_id' => $schema->integer()
                ->description('Explicit supplier ID if known'),
            'warehouse_query' => $schema->string()
                ->description('Receiving warehouse name or code'),
            'warehouse_id' => $schema->integer()
                ->description('Explicit warehouse ID if known'),
            'grn_date' => $schema->string()
                ->description('Date goods were received (YYYY-MM-DD). Defaults to today.'),
            'purchase_order_id' => $schema->integer()
                ->description('Optional linked Purchase Order ID'),
            'order_no' => $schema->string()
                ->description('Optional Purchase Order reference number (e.g. PO-42)'),
            'transport_name' => $schema->string()
                ->description('Optional transporter or courier name'),
            'lr_no' => $schema->string()
                ->description('Optional lorry receipt or docket number'),
            'lr_date' => $schema->string()
                ->description('Optional LR date (YYYY-MM-DD)'),
            'remarks' => $schema->string()
                ->description('Optional GRN remarks or notes'),
            'items' => $schema->array()
                ->items($schema->object([
                    'client_line_id' => $schema->string()
                        ->description('Stable client line identifier (e.g. line-0, line-1)'),
                    'product_query' => $schema->string()
                        ->description('Product name, code, SKU, or alias'),
                    'product_id' => $schema->integer()
                        ->description('Explicit product ID if known'),
                    'qty' => $schema->number()
                        ->description('Quantity received (e.g. 50)'),
                    'cost' => $schema->number()
                        ->description('Optional unit valuation cost. If omitted, uses standard product cost.'),
                    'uom' => $schema->string()
                        ->description('Optional unit of measure (e.g. PCS, KG, MTR)'),
                    'unit_id' => $schema->integer()
                        ->description('Optional unit ID'),
                    'batch_no' => $schema->string()
                        ->description('Optional batch number'),
                    'order_item_id' => $schema->integer()
                        ->description('Optional linked purchase order item ID'),
                    'remarks' => $schema->string()
                        ->description('Optional line remark'),
                ])),
            'task_id' => $schema->string()
                ->description('Optional active workflow task ID to resume an existing session'),
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

        $actionKey = 'grn.create.v1';
        $provider = $this->requirementsRegistry->get($actionKey);

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
            $existingSlots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
            $slots = array_replace_recursive($existingSlots, $args);
        }

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

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

        try {
            $validatedSlots = $evaluation->validatedSlots;
            $proposal = $this->proposalService->create(
                $this->principal,
                $actionKey,
                $validatedSlots,
                $this->principal->requestId ?? null,
            );

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
                'tool' => $actionKey,
                'as_of' => $validatedSlots['grn_date'] ?? now()->toDateString(),
                'data' => $factData,
                'source_refs' => ['/goods-received-notes'],
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
                'message' => 'Could not generate goods receipt proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

