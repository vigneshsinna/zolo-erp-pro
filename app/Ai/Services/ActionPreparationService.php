<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionRequirementsRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared intake for code-registered actions; never approves or executes a business command. */
final class ActionPreparationService
{
    public function prepare(
        AiPrincipal $principal,
        string $key,
        array $inputs,
        ?string $taskId = null,
        ?int $expectedRevision = null,
        string $sourceChannel = 'internal',
        ?string $sourceClientBindingId = null,
        ?string $threadId = null,
    ): array {
        if (!$principal->hasTool($key)) {
            throw new AuthorizationException('Action is not permitted.');
        }

        $context = app(ActionSecurity::class)->context($principal);
        app(ActionSecurity::class)->definition($key, $principal);
        $provider = app(ActionRequirementsRegistry::class)->get($key);
        $tasks = app(WorkflowTaskStateService::class);
        $effectiveThreadId = $threadId ?? $principal->threadId;
        $taskContext = new WorkflowTaskContext($principal, $context, 'company', taskId: $taskId, threadId: $effectiveThreadId);

        return DB::transaction(function () use (
            $principal,
            $key,
            $inputs,
            $taskId,
            $expectedRevision,
            $sourceChannel,
            $sourceClientBindingId,
            $effectiveThreadId,
            $provider,
            $tasks,
            $taskContext,
        ): array {
            $task = $taskId ? $tasks->getTask($principal, $taskId) : $tasks->createTask($taskContext, $key, $inputs);

            if ($task['action_key'] !== $key) {
                throw new AuthorizationException('Task action does not match.');
            }
            if ($effectiveThreadId !== null && $task['thread_id'] !== null && $task['thread_id'] !== $effectiveThreadId) {
                throw new AuthorizationException('Workflow task does not belong to this thread.');
            }
            if ($taskId && $expectedRevision !== null && (int) $task['revision'] !== $expectedRevision) {
                throw new ConflictHttpException("Task revision conflict. Expected revision {$expectedRevision}, but current revision is {$task['revision']}.");
            }

            if ($taskId) {
                $existingSlots = json_decode((string) $task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
                $slots = $this->mergeSlots($existingSlots, $inputs);
            } else {
                $slots = $inputs;
            }

            $batch = app(BatchRecordResolutionService::class)->resolveAll($taskContext, $slots);
            $evaluation = app(InputCompletenessEvaluator::class)->evaluate($provider, $taskContext, $slots, $batch);

            $updated = $tasks->updateTaskState(
                principal: $principal,
                taskId: (string) $task['id'],
                expectedRevision: $expectedRevision ?? (int) $task['revision'],
                slots: $slots,
                resolution: ['resolved_ids' => $batch->resolvedIds],
                issues: $evaluation->issuesArray(),
                status: 'collecting',
                patch: $taskId ? $inputs : null,
            );

            if ($evaluation->hasBlockingIssues()) {
                return app(ClarificationResponseBuilder::class)->build($updated, $evaluation);
            }

            $proposalService = app(ActionProposalService::class);
            $proposal = null;

            if (!empty($updated['linked_proposal_id'])) {
                $existingProposal = DB::table('ai_action_proposals')
                    ->where('id', $updated['linked_proposal_id'])
                    ->where('actor_id', $principal->actorId)
                    ->where('company_id', $principal->companyId)
                    ->where('branch_id', $principal->branchId)
                    ->where('financial_year_id', $principal->financialYearId)
                    ->first();

                if ($existingProposal && in_array($existingProposal->status, ['pending', 'approved', 'superseded'], true)) {
                    $proposal = $proposalService->applyChangeRequest(
                        principal: $principal,
                        id: (string) $existingProposal->id,
                        changes: $evaluation->validatedSlots,
                        expectedVersion: (int) $existingProposal->version,
                        reuseUnchanged: true,
                        allowSuperseded: true,
                    );
                }
            }

            if ($proposal === null) {
                $proposal = $proposalService->create(
                    principal: $principal,
                    key: $key,
                    slots: $evaluation->validatedSlots,
                    threadId: $effectiveThreadId,
                    sourceChannel: $sourceChannel,
                    sourceClientBindingId: $sourceClientBindingId,
                );
            }

            $tasks->linkProposal((string) $updated['id'], (string) $proposal['id']);

            $reviewUrl = Route::has('ai.actions.review')
                ? route('ai.actions.review', ['proposal_id' => $proposal['id']], absolute: false)
                : '/ai/action-review?proposal_id=' . urlencode((string) $proposal['id']);

            return [
                'status' => 'ok',
                'tool' => $key,
                'as_of' => $evaluation->validatedSlots['date'] ?? now()->toDateString(),
                'data' => json_decode((string) $proposal['preview_json'], true, flags: JSON_THROW_ON_ERROR) + [
                    'proposal_id' => $proposal['id'],
                    'version' => (int) $proposal['version'],
                    'action_key' => $key,
                    'task_id' => $updated['id'],
                    'task_revision' => (int) $updated['revision'],
                    'expires_at' => (string) $proposal['expires_at'],
                    'requires_approval' => true,
                    'review_url' => $reviewUrl,
                ],
                'source_refs' => [$reviewUrl],
                'reconciled' => true,
                'row_count' => is_array($evaluation->validatedSlots['items'] ?? null) ? count($evaluation->validatedSlots['items']) : 1,
            ];
        });
    }

    /**
     * Merge incoming slot patches while respecting explicit null clears and
     * replacing line-item lists atomically so split/amended lines never retain ghosts.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeSlots(array $existing, array $incoming): array
    {
        $merged = $existing;
        foreach ($incoming as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
                continue;
            }
            if ($key === 'items' && is_array($value)) {
                $merged['items'] = $value;
                continue;
            }
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]) && !array_is_list($value)) {
                $merged[$key] = $this->mergeSlots($merged[$key], $value);
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }
}
