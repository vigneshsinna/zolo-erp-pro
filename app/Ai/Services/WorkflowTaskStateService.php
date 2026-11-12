<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Security\BootstrapSetupPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WorkflowTaskStateService
{
    /**
     * Retrieve an owned workflow task by ID.
     */
    public function getTask(AiPrincipal|BootstrapSetupPrincipal $principal, string $taskId): array
    {
        $query = DB::table('ai_workflow_tasks')
            ->where('id', $taskId)
            ->where('actor_id', $principal->actorId);

        if ($principal instanceof AiPrincipal) {
            $query->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId);
        }

        $task = $query->first();
        if (!$task) {
            throw new AuthorizationException('Workflow task not accessible.');
        }

        return (array) $task;
    }

    /**
     * Create a new workflow task.
     *
     * @param array<string, mixed> $initialSlots
     */
    public function createTask(
        WorkflowTaskContext $context,
        string $actionKey,
        array $initialSlots = [],
    ): array {
        $taskId = (string) Str::uuid();
        $ttlMinutes = (int) config('ai_actions.proposal_ttl_minutes', 60);
        $expiresAt = now('UTC')->addMinutes($ttlMinutes);

        $record = [
            'id' => $taskId,
            'action_key' => $actionKey,
            'mode' => $context->mode,
            'actor_id' => $context->actorId(),
            'thread_id' => $context->threadId,
            'company_id' => $context->companyId(),
            'branch_id' => $context->branchId(),
            'financial_year_id' => $context->financialYearId(),
            'bootstrap_task_id' => $context->metadata['bootstrap_task_id'] ?? null,
            'status' => 'collecting',
            'revision' => 1,
            'slots_json' => json_encode($initialSlots, JSON_THROW_ON_ERROR),
            'resolution_json' => json_encode([], JSON_THROW_ON_ERROR),
            'issues_json' => json_encode([], JSON_THROW_ON_ERROR),
            'provenance_json' => json_encode([], JSON_THROW_ON_ERROR),
            'linked_proposal_id' => null,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($record, $taskId, $context, $initialSlots): void {
            DB::table('ai_workflow_tasks')->insert($record);

            DB::table('ai_workflow_task_events')->insert([
                'id' => (string) Str::uuid(),
                'task_id' => $taskId,
                'actor_id' => $context->actorId(),
                'event_type' => 'created',
                'revision' => 1,
                'patch_json' => json_encode($initialSlots, JSON_THROW_ON_ERROR),
                'metadata_json' => json_encode(['action_key' => $record['action_key']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return $record;
    }

    /**
     * Update task state using optimistic concurrency control (expected_revision).
     *
     * @param array<string, mixed> $slots
     * @param ?array<string, mixed> $resolution
     * @param ?list<array<string, mixed>> $issues
     */
    public function updateTaskState(
        AiPrincipal|BootstrapSetupPrincipal $principal,
        string $taskId,
        int $expectedRevision,
        array $slots,
        ?array $resolution = null,
        ?array $issues = null,
        ?string $status = null,
        ?array $patch = null,
    ): array {
        $task = $this->getTask($principal, $taskId);

        if (in_array($task['status'], ['cancelled', 'expired'], true)) {
            throw ValidationException::withMessages(['task' => 'Task is finalized or cancelled.']);
        }

        $newRevision = $expectedRevision + 1;
        $updates = [
            'revision' => $newRevision,
            'slots_json' => json_encode($slots, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        if ($resolution !== null) {
            $updates['resolution_json'] = json_encode($resolution, JSON_THROW_ON_ERROR);
        }
        if ($issues !== null) {
            $updates['issues_json'] = json_encode($issues, JSON_THROW_ON_ERROR);
        }
        if ($status !== null) {
            $updates['status'] = $status;
        }

        $updated = DB::transaction(function () use ($taskId, $expectedRevision, $updates, $principal, $newRevision, $patch): bool {
            $count = DB::table('ai_workflow_tasks')
                ->where('id', $taskId)
                ->where('actor_id', $principal->actorId)
                ->where('revision', $expectedRevision)
                ->update($updates);

            if ($count !== 1) {
                return false;
            }

            DB::table('ai_workflow_task_events')->insert([
                'id' => (string) Str::uuid(),
                'task_id' => $taskId,
                'actor_id' => $principal->actorId,
                'event_type' => 'patched',
                'revision' => $newRevision,
                'patch_json' => $patch ? json_encode($patch, JSON_THROW_ON_ERROR) : null,
                'metadata_json' => json_encode(['status' => $updates['status'] ?? null], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return true;
        });

        if (!$updated) {
            $latest = $this->getTask($principal, $taskId);
            throw new ConflictHttpException("Task revision conflict. Expected revision {$expectedRevision}, but current revision is {$latest['revision']}. Reload latest task state.");
        }

        return $this->getTask($principal, $taskId);
    }

    /**
     * Mark task as proposal created and record link.
     */
    public function linkProposal(string $taskId, string $proposalId): void
    {
        DB::table('ai_workflow_tasks')
            ->where('id', $taskId)
            ->update([
                'status' => 'proposal_created',
                'linked_proposal_id' => $proposalId,
                'updated_at' => now(),
            ]);

        DB::table('ai_workflow_task_events')->insert([
            'id' => (string) Str::uuid(),
            'task_id' => $taskId,
            'actor_id' => DB::table('ai_workflow_tasks')->where('id', $taskId)->value('actor_id') ?? 1,
            'event_type' => 'proposal_linked',
            'revision' => (int) (DB::table('ai_workflow_tasks')->where('id', $taskId)->value('revision') ?? 1),
            'patch_json' => json_encode(['proposal_id' => $proposalId], JSON_THROW_ON_ERROR),
            'metadata_json' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Cancel task without ERP mutations.
     */
    public function cancelTask(AiPrincipal|BootstrapSetupPrincipal $principal, string $taskId): void
    {
        $task = $this->getTask($principal, $taskId);

        DB::table('ai_workflow_tasks')
            ->where('id', $taskId)
            ->where('actor_id', $principal->actorId)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        DB::table('ai_workflow_task_events')->insert([
            'id' => (string) Str::uuid(),
            'task_id' => $taskId,
            'actor_id' => $principal->actorId,
            'event_type' => 'cancelled',
            'revision' => (int) $task['revision'] + 1,
            'patch_json' => null,
            'metadata_json' => null,
            'created_at' => now(),
        ]);
    }
}
