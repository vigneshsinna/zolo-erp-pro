<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ActiveWorkflowTaskResolver
{
    public function tasks(AiPrincipal $principal, string $threadId): Builder
    {
        return DB::table('ai_workflow_tasks')->where([
            'actor_id' => $principal->actorId, 'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
            'thread_id' => $threadId, 'mode' => 'company',
        ]);
    }

    public function resolve(AiPrincipal $principal, string $threadId, ?string $taskId = null): array
    {
        $tasks = $this->tasks($principal, $threadId)
            ->whereIn('status', ['collecting', 'ready', 'proposal_created', 'awaiting_master'])
            ->where('expires_at', '>', now('UTC'))
            ->when($taskId !== null, fn (Builder $query) => $query->where('id', $taskId))
            ->lockForUpdate()->get()->map(fn ($task) => (array) $task)->all();
        $tasks = array_values(array_filter($tasks, function (array $task) use ($principal): bool {
            if (!$task['linked_proposal_id']) {
                return true;
            }
            return DB::table('ai_action_proposals')->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)->where('financial_year_id', $principal->financialYearId)
                ->where('actor_id', $principal->actorId)->where('id', $task['linked_proposal_id'])
                ->whereIn('status', ['pending', 'approved', 'editing'])->exists();
        }));
        if ($taskId !== null && $tasks === []) {
            throw new AuthorizationException('Active workflow task is unavailable in this conversation.');
        }
        return $tasks;
    }
}
