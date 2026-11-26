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
 * AI Tool bridging customer payment collection settlement to the Action Proposal pipeline.
 * Reconciles invoice balance and books double-entry cash/bank receipt voucher only after explicit human approval.
 */
final class PreparePaymentReceipt implements Tool
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
        return 'prepare_payment_receipt';
    }

    public function description(): string
    {
        return 'Prepares a draft Customer Payment Receipt proposal from natural language parameters (customer, open invoice ID, amount, settlement cash/bank account, date). Settles accounts receivable against open invoices only after explicit human review and approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_query' => $schema->string()
                ->description('Customer name, company name, VAT/GSTIN, phone, or ID'),
            'customer_id' => $schema->integer()
                ->description('Explicit customer ID if known'),
            'open_item_id' => $schema->integer()
                ->description('The target open invoice item ID to settle against'),
            'amount' => $schema->number()
                ->description('The collection amount to settle (must be positive and within invoice balance)'),
            'settlement_account_id' => $schema->integer()
                ->description('The cash or bank account ID receiving the payment'),
            'date' => $schema->string()
                ->description('Optional collection date in YYYY-MM-DD format. Defaults to today.'),
            'note' => $schema->string()
                ->description('Optional payment notes (e.g. Cheque / NEFT / UTR reference)'),
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

        $actionKey = 'payment_receipt.create.v1';
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
                'proposal' => $proposal,
                'tool' => $actionKey,
                'as_of' => $validatedSlots['date'] ?? now()->toDateString(),
                'data' => $factData,
                'source_refs' => ['/accounting/receivables'],
                'reconciled' => true,
                'row_count' => 1,
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
                'error_code' => 'AI_DOMAIN_EXECUTION_FAILED',
                'message' => 'Could not generate payment receipt proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

