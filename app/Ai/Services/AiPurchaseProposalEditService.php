<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attaches an owned, unexecuted PO/bill proposal to an owned Copilot thread.
 * No financial transaction, approval, or proposal revision occurs on opening.
 */
final class AiPurchaseProposalEditService
{
    public function __construct(
        private readonly ActionProposalService $proposals,
        private readonly WorkflowTaskStateService $tasks,
    ) {}

    public function open(AiPrincipal $principal, string $proposalId): array
    {
        if (!config('erp_ai.conversation_enabled', false)
            || !config('ai_actions.foundation_enabled', false)
            || !$principal->isActive) {
            throw new AuthorizationException('Purchase proposal editing is disabled.');
        }

        return DB::transaction(function () use ($principal, $proposalId): array {
            // Lock only a proposal that belongs to the current ERP actor/context.
            $this->proposals->getOwned($principal, $proposalId);
            $locked = DB::table('ai_action_proposals')
                ->where('id', $proposalId)->where('actor_id', $principal->actorId)
                ->where('company_id', $principal->companyId)->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)->lockForUpdate()->first();
            if (!$locked || !in_array($locked->action_key, [
                'purchase_order.create.v1', 'purchase_bill.create.v1',
            ], true) || !in_array($locked->status, ['pending', 'approved', 'editing'], true)
                || $locked->expires_at <= now('UTC')->toDateTimeString()) {
                throw ValidationException::withMessages([
                    'proposal' => 'This purchase proposal is not editable. Start a new one or refresh the review queue.',
                ]);
            }
            $this->proposals->suspendForEditing($principal, $proposalId);
            $existing = DB::table('ai_workflow_tasks')->where('linked_proposal_id', $proposalId)
                ->where('company_id', $principal->companyId)->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)->where('actor_id', $principal->actorId)
                ->whereIn('status', ['collecting', 'ready', 'proposal_created', 'awaiting_master'])
                ->where('expires_at', '>', now('UTC')->toDateTimeString())
                ->whereNotNull('thread_id')
                // A previously opened editor is reusable only while bound
                // to this exact proposal version. Never resurrect old slots.
                ->where('provenance_json->source', 'proposal_edit')
                ->where('provenance_json->source_version', (int) $locked->version)
                ->orderByDesc('created_at')->first();
            if ($existing && DB::table('ai_thread_scopes')->where('id', $existing->thread_id)
                ->where('user_id', $principal->actorId)->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)->exists()) {
                return ['thread_id' => $existing->thread_id, 'task_id' => $existing->id];
            }

            $threadId = (string) Str::uuid();
            DB::table('ai_thread_scopes')->insert([
                'id' => $threadId, 'sdk_conversation_id' => $threadId,
                'user_id' => $principal->actorId,
                'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'title' => ($locked->action_key === 'purchase_bill.create.v1'
                    ? 'Edit Purchase Bill ' : 'Edit Purchase Order ') . substr($proposalId, 0, 8),
                'status' => 'active', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);

            $payload = json_decode((string) $locked->payload_json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw ValidationException::withMessages(['proposal' => 'Proposal payload is invalid.']);
            }
            // Prior preview totals and GST rates are derived evidence, not user
            // instructions. Recompute them from authoritative supplier/product data.
            unset($payload['expected_totals']);
            foreach ($payload['items'] ?? [] as $index => &$line) {
                $line['client_line_id'] = 'line-'.$index;
                unset($line['tax_rate'], $line['tax_rate_override']);
            }
            unset($line);

            $task = $this->tasks->createTask(
                new WorkflowTaskContext(
                    principal: $principal,
                    context: new CompanyContext(
                        $principal->companyId, $principal->branchId, $principal->financialYearId,
                    ),
                    mode: 'company', threadId: $threadId,
                ),
                $locked->action_key,
                $payload,
                ['source' => 'proposal_edit', 'source_proposal_id' => $proposalId,
                    'source_version' => (int) $locked->version],
            );
            $this->tasks->linkProposal($task['id'], $proposalId);

            return ['thread_id' => $threadId, 'task_id' => $task['id']];
        }, 3);
    }
}
