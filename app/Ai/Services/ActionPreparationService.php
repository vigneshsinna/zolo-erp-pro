<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionRequirementsRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Shared intake for code-registered actions; never approves or executes a business command. */
final class ActionPreparationService
{
    public function prepare(AiPrincipal $principal, string $key, array $inputs, ?string $taskId = null): array
    {
        if (!$principal->hasTool($key)) throw new AuthorizationException('Action is not permitted.');
        $context = app(ActionSecurity::class)->context($principal);
        app(ActionSecurity::class)->definition($key, $principal);
        $provider = app(ActionRequirementsRegistry::class)->get($key);
        $tasks = app(WorkflowTaskStateService::class);
        $taskContext = new WorkflowTaskContext($principal, $context, 'company', taskId: $taskId);
        return DB::transaction(function () use ($principal, $key, $inputs, $taskId, $provider, $tasks, $taskContext) {
            $task = $taskId ? $tasks->getTask($principal, $taskId) : $tasks->createTask($taskContext, $key, $inputs);
            if ($task['action_key'] !== $key) throw new AuthorizationException('Task action does not match.');
            $slots = $taskId ? array_replace_recursive(json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR), $inputs) : $inputs;
            $batch = app(BatchRecordResolutionService::class)->resolveAll($taskContext, $slots);
            $evaluation = app(InputCompletenessEvaluator::class)->evaluate($provider, $taskContext, $slots, $batch);
            $updated = $tasks->updateTaskState($principal, $task['id'], (int) $task['revision'], $slots,
                ['resolved_ids' => $batch->resolvedIds], $evaluation->issuesArray(), 'collecting');
            if ($evaluation->hasBlockingIssues()) return app(ClarificationResponseBuilder::class)->build($updated, $evaluation);
            $proposal = app(ActionProposalService::class)->create($principal, $key, $evaluation->validatedSlots);
            $tasks->linkProposal($task['id'], $proposal['id']);
            return ['status' => 'ok', 'tool' => $key, 'as_of' => now()->toDateString(),
                'data' => json_decode($proposal['preview_json'], true, flags: JSON_THROW_ON_ERROR) + [
                    'proposal_id' => $proposal['id'], 'version' => (int) $proposal['version'], 'action_key' => $key, 'task_id' => $task['id']],
                'source_refs' => ['/ai/action-review'], 'reconciled' => true, 'row_count' => 1];
        });
    }
}
