<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\BatchRecordResolutionService;
use App\Ai\Services\ClarificationResponseBuilder;
use App\Ai\Services\DependentMasterOrchestrator;
use App\Ai\Services\InputCompletenessEvaluator;
use App\Ai\Services\TaskCorrectionService;
use App\Ai\Services\WorkflowTaskStateService;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AiWorkflowTaskController extends Controller
{
    public function __construct(
        private readonly WorkflowTaskStateService $taskState,
        private readonly TaskCorrectionService $correctionService,
        private readonly ActionRequirementsRegistry $requirementsRegistry,
        private readonly ActionCapabilityRegistry $capabilityRegistry,
        private readonly BatchRecordResolutionService $batchResolver,
        private readonly InputCompletenessEvaluator $evaluator,
        private readonly ClarificationResponseBuilder $clarificationBuilder,
        private readonly ActionProposalService $proposalService,
        private readonly DependentMasterOrchestrator $masterOrchestrator,
        private readonly CommercialPermission $permissions,
        private readonly CompanyContextResolver $contextResolver,
        private readonly AiFeatureGate $featureGate,
    ) {
    }

    /**
     * POST /api/v1/ai/workflow-tasks
     * Initiate a new typed collection workflow task.
     */
    public function store(Request $request): JsonResponse
    {
        $this->assertFoundationEnabled();

        $validated = $request->validate([
            'action_key' => ['required', 'string'],
            'slots' => ['nullable', 'array'],
            'thread_id' => ['nullable', 'string', 'max:120'],
        ]);

        $principal = $this->resolvePrincipal($request, $validated['action_key']);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);

        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            mode: 'company',
            threadId: $validated['thread_id'] ?? null,
        );

        $slots = $validated['slots'] ?? [];
        $provider = $this->requirementsRegistry->get($validated['action_key']);

        $task = $this->taskState->createTask($taskContext, $validated['action_key'], $slots);

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        $updatedTask = $this->taskState->updateTaskState(
            principal: $principal,
            taskId: $task['id'],
            expectedRevision: 1,
            slots: $slots,
            resolution: ['resolved_ids' => $batchResult->resolvedIds],
            issues: $evaluation->issuesArray(),
            status: $evaluation->isReady() ? 'ready' : 'collecting',
        );

        return response()->json([
            'task' => $this->formatTaskResponse($updatedTask),
            'evaluation' => [
                'is_ready' => $evaluation->isReady(),
                'confirmed_fields' => $evaluation->confirmedFields,
                'required_inputs' => $evaluation->issuesArray(),
                'pending_checks' => $evaluation->pendingChecks,
            ],
            'clarification' => !$evaluation->isReady() ? $this->clarificationBuilder->build($updatedTask, $evaluation) : null,
        ], 201);
    }

    /**
     * GET /api/v1/ai/workflow-tasks/{id}
     * Inspect owned workflow task.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();
        $principal = $this->resolvePrincipal($request);

        $task = $this->taskState->getTask($principal, $id);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);

        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            mode: (string) $task['mode'],
            taskId: $id,
            threadId: $task['thread_id'],
        );

        $provider = $this->requirementsRegistry->get((string) $task['action_key']);
        $slots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        return response()->json([
            'task' => $this->formatTaskResponse($task),
            'evaluation' => [
                'is_ready' => $evaluation->isReady(),
                'confirmed_fields' => $evaluation->confirmedFields,
                'required_inputs' => $evaluation->issuesArray(),
                'pending_checks' => $evaluation->pendingChecks,
            ],
            'clarification' => !$evaluation->isReady() ? $this->clarificationBuilder->build($task, $evaluation) : null,
        ]);
    }

    /**
     * PATCH /api/v1/ai/workflow-tasks/{id}/inputs
     * Apply consolidated user corrections with expected_revision.
     */
    public function updateInputs(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();
        $principal = $this->resolvePrincipal($request);

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'corrections' => ['required', 'array', 'min:1'],
            'corrections.*.field' => ['required', 'string'],
            'corrections.*.value' => ['nullable'],
            'corrections.*.confirmation' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->correctionService->applyCorrections(
                principal: $principal,
                taskId: $id,
                expectedRevision: (int) $validated['expected_revision'],
                corrections: $validated['corrections'],
            );
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            return response()->json([
                'error_code' => 'CONCURRENT_TASK_MODIFICATION',
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'task' => $this->formatTaskResponse($result['task']),
            'evaluation' => [
                'is_ready' => $result['evaluation']->isReady(),
                'confirmed_fields' => $result['evaluation']->confirmedFields,
                'required_inputs' => $result['evaluation']->issuesArray(),
                'pending_checks' => $result['evaluation']->pendingChecks,
            ],
            'clarification' => $result['clarification'],
        ]);
    }

    /**
     * POST /api/v1/ai/workflow-tasks/{id}/prepare-proposal
     * Convert complete task into governed Action Proposal.
     */
    public function prepareProposal(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();
        $principal = $this->resolvePrincipal($request);

        $task = $this->taskState->getTask($principal, $id);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);

        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            mode: (string) $task['mode'],
            taskId: $id,
            threadId: $task['thread_id'],
        );

        $actionKey = (string) $task['action_key'];
        $provider = $this->requirementsRegistry->get($actionKey);
        $slots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);

        $batchResult = $this->batchResolver->resolveAll($taskContext, $slots);
        $evaluation = $this->evaluator->evaluate($provider, $taskContext, $slots, $batchResult);

        if (!$evaluation->isReady()) {
            return response()->json([
                'status' => 'needs_input',
                'message' => 'Cannot prepare proposal; task has unresolved blockers.',
                'clarification' => $this->clarificationBuilder->build($task, $evaluation),
            ], 422);
        }

        $proposalId = $task['linked_proposal_id'] ?? $task['proposal_id'] ?? null;
        if (!empty($proposalId)) {
            $existingProposal = DB::table('ai_action_proposals')
                ->where('id', $proposalId)
                ->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)
                ->first();

            if ($existingProposal && in_array($existingProposal->status, ['pending', 'approved'], true)) {
                $proposal = $this->proposalService->applyChangeRequest(
                    principal: $principal,
                    id: (string) $proposalId,
                    changes: $evaluation->validatedSlots,
                    expectedVersion: (int) $existingProposal->version,
                );

                return response()->json([
                    'status' => 'proposal_created',
                    'proposal' => $proposal,
                    'task_id' => $id,
                ], 200);
            }
        }

        $proposal = $this->proposalService->create(
            principal: $principal,
            key: $actionKey,
            slots: $evaluation->validatedSlots,
            threadId: $task['thread_id'],
        );

        $this->taskState->linkProposal($id, (string) $proposal['id']);

        return response()->json([
            'status' => 'proposal_created',
            'proposal' => $proposal,
            'task_id' => $id,
        ], 201);
    }

    /**
     * POST /api/v1/ai/workflow-tasks/{id}/cancel
     * Cancel workflow task without ERP writes.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();
        $principal = $this->resolvePrincipal($request);

        $this->taskState->cancelTask($principal, $id);

        return response()->json([
            'status' => 'cancelled',
            'task_id' => $id,
            'message' => 'Workflow task cancelled.',
        ]);
    }

    /**
     * POST /api/v1/ai/workflow-tasks/{id}/dependent-masters/spawn
     * Phase 2.2: Spawn requirements schema and prefilled defaults for a dependent master sub-flow.
     */
    public function spawnDependentMaster(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();

        $validated = $request->validate([
            'field' => ['required', 'string'],
        ]);

        $principal = $this->resolvePrincipal($request);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            taskId: $id,
        );

        $result = $this->masterOrchestrator->spawnMasterRequirements($taskContext, $id, $validated['field']);

        return response()->json($result);
    }

    /**
     * POST /api/v1/ai/workflow-tasks/{id}/dependent-masters/preview
     * Phase 2.2: Previews a governed master setup proposal linked to this task.
     */
    public function previewDependentMaster(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();

        $validated = $request->validate([
            'field' => ['required', 'string'],
            'data' => ['required', 'array'],
        ]);

        $principal = $this->resolvePrincipal($request);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            taskId: $id,
        );

        $result = $this->masterOrchestrator->previewMasterProposal($taskContext, $id, $validated['field'], $validated['data']);

        return response()->json($result);
    }

    /**
     * POST /api/v1/ai/workflow-tasks/{id}/dependent-masters/approve-and-execute
     * Phase 2.2: Approves and executes the master proposal, then automatically resumes the parent task.
     */
    public function approveAndExecuteDependentMaster(Request $request, string $id): JsonResponse
    {
        $this->assertFoundationEnabled();

        if (!config('ai_actions.execution_enabled', false)) {
            abort(403, 'AI action execution is disabled.');
        }

        $validated = $request->validate([
            'field' => ['required', 'string'],
            'master_proposal_id' => ['required', 'uuid'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $principal = $this->resolvePrincipal($request);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        $taskContext = new WorkflowTaskContext(
            principal: $principal,
            context: $context,
            taskId: $id,
        );

        $result = $this->masterOrchestrator->approveAndExecuteMaster(
            $taskContext,
            $id,
            $validated['field'],
            $validated['master_proposal_id'],
            (int) ($validated['expected_version'] ?? 1)
        );

        return response()->json($result);
    }

    private function resolvePrincipal(Request $request, ?string $actionKey = null): AiPrincipal
    {
        $actorId = (int) $request->user()->id;
        $context = $this->contextResolver->forActor(userId: $actorId);

        $this->permissions->assert('ai.use', $context, $actorId);

        if ($actionKey) {
            $def = $this->capabilityRegistry->get($actionKey);
            foreach ($def->requiredPermissions as $perm) {
                $this->permissions->assert($perm, $context, $actorId);
            }
        }

        return new AiPrincipal(
            actorId: $actorId,
            companyId: $context->companyId,
            branchId: $context->branchId,
            financialYearId: $context->financialYearId,
            permittedToolNames: $actionKey ? [$actionKey] : [],
            requestId: (string) $request->header('X-Request-Id', ''),
        );
    }

    private function assertFoundationEnabled(): void
    {
        if (!config('ai_actions.foundation_enabled', false)) {
            abort(403, 'AI action foundation is disabled.');
        }
    }

    private function formatTaskResponse(array $task): array
    {
        return [
            'id' => $task['id'],
            'action_key' => $task['action_key'],
            'status' => $task['status'],
            'revision' => (int) $task['revision'],
            'mode' => $task['mode'],
            'company_id' => $task['company_id'],
            'branch_id' => $task['branch_id'],
            'financial_year_id' => $task['financial_year_id'],
            'slots' => json_decode((string) $task['slots_json'], true) ?? [],
            'issues' => json_decode((string) ($task['issues_json'] ?? '[]'), true) ?? [],
            'linked_proposal_id' => $task['linked_proposal_id'] ?? null,
            'expires_at' => $task['expires_at'],
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at'],
        ];
    }
}
