<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionSecurity;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Strictly scoped, non-enumerating status reader for owned AI workflow tasks,
 * proposals, and verified execution receipts.
 */
final class ProposalStatusReader
{
    public function __construct(private readonly ActionSecurity $security) {}

    /**
     * @return array<string, mixed>
     */
    public function read(AiPrincipal $principal, ?string $proposalId = null, ?string $taskId = null): array
    {
        if (($proposalId === null || trim($proposalId) === '') && ($taskId === null || trim($taskId) === '')) {
            throw ValidationException::withMessages([
                'proposal_id' => 'Provide proposal_id or task_id to inspect status.',
            ]);
        }

        $this->security->assertEnabled($principal);
        $this->security->context($principal);

        $task = null;
        if ($taskId !== null && trim($taskId) !== '') {
            $task = DB::table('ai_workflow_tasks')
                ->where('id', $taskId)
                ->where('actor_id', $principal->actorId)
                ->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)
                ->first();

            if (!$task) {
                throw new AuthorizationException('Proposal or task not accessible.');
            }
            if ($proposalId === null && $task->linked_proposal_id !== null) {
                $proposalId = (string) $task->linked_proposal_id;
            }
        }

        $proposal = null;
        if ($proposalId !== null && trim($proposalId) !== '') {
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $proposalId)
                ->where('actor_id', $principal->actorId)
                ->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)
                ->whereNotNull('action_key')
                ->whereNotNull('payload_hash')
                ->first();

            if (!$proposal) {
                throw new AuthorizationException('Proposal or task not accessible.');
            }
            if ($task !== null && $task->linked_proposal_id !== null && (string) $task->linked_proposal_id !== (string) $proposal->id) {
                throw new AuthorizationException('Proposal or task not accessible.');
            }
        }

        if (!$proposal && !$task) {
            throw new AuthorizationException('Proposal or task not accessible.');
        }

        if ($proposal) {
            $this->security->definition((string) $proposal->action_key, $principal);

            $status = (string) $proposal->status;
            $isExpired = !$proposal->expires_at || Carbon::parse((string) $proposal->expires_at, 'UTC')->isPast();
            if (in_array($status, ['pending', 'approved'], true) && $isExpired) {
                $status = 'expired';
            }

            $receipt = null;
            if ($status === 'completed') {
                $execution = DB::table('ai_action_executions')
                    ->where('proposal_id', $proposal->id)
                    ->where('company_id', $principal->companyId)
                    ->where('version', (int) $proposal->version)
                    ->where('status', 'completed')
                    ->whereNotNull('erp_resource_id')
                    ->first();

                if ($execution) {
                    $decoded = json_decode((string) $execution->result_json, true) ?? [];
                    $receipt = [
                        'execution_id' => (string) $execution->id,
                        'resource_type' => (string) $execution->erp_resource_type,
                        'resource_id' => (int) $execution->erp_resource_id,
                        'reference_no' => $decoded['details']['reference_no'] ?? null,
                        'status' => (string) $execution->status,
                        'executed_at' => (string) $execution->executed_at,
                    ];
                }
            }

            return [
                'status' => $status,
                'action_key' => (string) $proposal->action_key,
                'proposal_id' => (string) $proposal->id,
                'proposal_version' => (int) $proposal->version,
                'task_id' => $task ? (string) $task->id : null,
                'task_revision' => $task ? (int) $task->revision : null,
                'expires_at' => (string) $proposal->expires_at,
                'requires_approval' => in_array($status, ['pending'], true),
                'review_url' => $this->canonicalReviewUrl((string) $proposal->id),
                'receipt' => $receipt,
            ];
        }

        $taskStatus = (string) $task->status;
        $taskExpired = $task->expires_at && Carbon::parse((string) $task->expires_at, 'UTC')->isPast();
        if ($taskStatus === 'collecting' && $taskExpired) {
            $taskStatus = 'expired';
        }

        return [
            'status' => $taskStatus,
            'action_key' => (string) $task->action_key,
            'proposal_id' => null,
            'proposal_version' => null,
            'task_id' => (string) $task->id,
            'task_revision' => (int) $task->revision,
            'expires_at' => (string) $task->expires_at,
            'requires_approval' => false,
            'review_url' => null,
            'receipt' => null,
        ];
    }

    public function canonicalReviewUrl(string $proposalId): string
    {
        if (Route::has('ai.actions.review')) {
            return route('ai.actions.review', ['proposal_id' => $proposalId], absolute: false);
        }

        return '/ai/action-review?proposal_id=' . urlencode($proposalId);
    }
}
