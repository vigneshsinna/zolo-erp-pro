<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\ActionSecurity;
use App\Models\Operations\JobWorkReceipt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Advances a timeline ONLY from scoped, completed ERP execution receipts or
 * a verified physical job-work record linked to an approved dispatch.
 * Never executes, approves or infers the physical event.
 */
final class BusinessWorkflowProgress
{
    public function __construct(
        private readonly ActionSecurity $security,
        private readonly ActionProposalService $proposals,
        private readonly BusinessWorkflowPlanner $planner,
    ) {}

    public function linkProposal(
        AiPrincipal $principal, string $workflowId, int $revision,
        string $stepKey, string $proposalId,
    ): array {
        $proposal = $this->proposals->getOwned($principal, $proposalId);
        $this->enabled($principal);
        return $this->change($principal, $workflowId, $revision, $stepKey, function ($step, $workflow) use ($principal, $proposal): array {
            if ($step->kind !== 'reviewed_action' || $step->action_key !== $proposal['action_key']) {
                throw ValidationException::withMessages(['proposal' => 'Proposal does not match the planned step.']);
            }
            $this->assertDependencies($workflow->id, $step);
            if (!in_array($proposal['status'], ['pending', 'approved', 'executing', 'completed'], true)
                || ($proposal['status'] !== 'completed'
                    && $proposal['expires_at'] <= now('UTC')->toDateTimeString())) {
                throw ValidationException::withMessages(['proposal' => 'Proposal is not in a reviewable or completed state.']);
            }
            $status = match ($proposal['status']) {
                'pending' => 'awaiting_approval',
                'approved', 'executing' => 'awaiting_execution',
                'completed' => 'completed',
            };
            if ($status === 'completed' && !DB::table('ai_action_executions')->where([
                'proposal_id' => $proposal['id'], 'company_id' => $principal->companyId,
                'status' => 'completed',
            ])->whereNotNull('erp_resource_id')->exists()) {
                throw ValidationException::withMessages(['proposal' => 'No verified ERP execution receipt for this proposal.']);
            }
            return [
                'status' => $status,
                'proposal_id' => $proposal['id'],
                'evidence_hash' => $status === 'completed'
                    ? hash('sha256', $proposal['id'].':'.$proposal['payload_hash']) : null,
            ];
        }, 'proposal_linked');
    }

    public function verifyPhysicalReturn(
        AiPrincipal $principal, string $workflowId, int $revision,
        string $stepKey, int $receiptId,
    ): array {
        $this->enabled($principal);
        return $this->change($principal, $workflowId, $revision, $stepKey, function ($step, $workflow) use ($principal, $receiptId): array {
            if ($workflow->process_id !== 'process.job_work_outward.v1'
                || $step->kind !== 'external_event' || $step->step_key !== 'physical_return') {
                throw ValidationException::withMessages(['evidence' => 'This step does not accept job-work physical receipts.']);
            }
            $this->assertDependencies($workflow->id, $step);
            $context = $this->security->context($principal);
            $receipt = JobWorkReceipt::visibleIn($context)
                ->where('financial_year_id', $context->financialYearId)
                ->where('id', $receiptId)->whereNotNull('posted_at')->whereNull('reversed_at')->first();
            if (!$receipt) {
                throw ValidationException::withMessages(['evidence' => 'A posted, active physical receipt is required.']);
            }
            $dispatchStep = DB::table('ai_business_workflow_steps')->where([
                'workflow_id' => $workflow->id, 'step_key' => 'dispatch', 'status' => 'completed',
            ])->first();
            $grnStep = DB::table('ai_business_workflow_steps')->where([
                'workflow_id' => $workflow->id, 'step_key' => 'receipt', 'status' => 'completed',
            ])->first();
            if (!$grnStep || !$grnStep->proposal_id
                || !DB::table('ai_action_executions as e')
                    ->join('ai_action_proposals as p', 'p.id', '=', 'e.proposal_id')
                    ->where('p.id', $grnStep->proposal_id)
                    ->where('p.company_id', $principal->companyId)
                    ->where('p.branch_id', $principal->branchId)
                    ->where('p.financial_year_id', $principal->financialYearId)
                    ->where('p.actor_id', $principal->actorId)
                    ->where('e.status', 'completed')->where('e.erp_resource_type', 'job_work_receipt')
                    ->where('e.erp_resource_id', $receipt->id)->exists()) {
                throw ValidationException::withMessages(['evidence' => 'No approved posted GRN is linked to this workflow.']);
            }
            if (!$dispatchStep || !$dispatchStep->proposal_id) {
                throw ValidationException::withMessages(['evidence' => 'No completed dispatch is linked to this workflow.']);
            }
            $dispatchReceipt = DB::table('ai_action_executions as e')
                ->join('ai_action_proposals as p', 'p.id', '=', 'e.proposal_id')
                ->where('p.id', $dispatchStep->proposal_id)
                ->where('p.company_id', $principal->companyId)
                ->where('p.branch_id', $principal->branchId)
                ->where('p.financial_year_id', $principal->financialYearId)
                ->where('p.actor_id', $principal->actorId)
                ->where('e.status', 'completed')->where('e.erp_resource_type', 'job_work_dispatch')
                ->where('e.erp_resource_id', $receipt->dispatch_id)->select('e.*')->first();
            if (!$dispatchReceipt) {
                throw ValidationException::withMessages(['evidence' => 'Physical return does not belong to the workflow dispatch.']);
            }
            return [
                'status' => 'completed',
                'evidence_hash' => hash('sha256', $workflow->id.':'.$dispatchReceipt->id.':'.$receipt->id.':'.$receipt->posted_at),
            ];
        }, 'physical_receipt_verified');
    }

    /** Only unblock downstream steps whose predecessors are verifiably complete. */
    public function reconcile(AiPrincipal $principal, string $workflowId, int $revision): array
    {
        $this->enabled($principal);
        DB::transaction(function () use ($principal, $workflowId, $revision): void {
            $workflow = $this->owned($principal, $workflowId)->lockForUpdate()->first();
            $this->assertRevision($workflow, $revision);
            if ($workflow->status !== 'planned') {
                throw ValidationException::withMessages(['workflow' => 'Pause/cancelled workflows cannot progress.']);
            }
            $steps = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)->orderBy('created_at')->get();
            $byKey = $steps->keyBy('step_key');
            foreach ($steps as $step) {
                if ($step->status !== 'blocked') continue;
                $dependencies = json_decode($step->depends_on_json, true, flags: JSON_THROW_ON_ERROR);
                if (collect($dependencies)->every(static fn (string $k): bool => ($byKey[$k]->status ?? '') === 'completed')) {
                    $next = match ($step->kind) {
                        'reviewed_action' => 'awaiting_proposal',
                        'external_event' => 'awaiting_real_event',
                        'read_or_clarify' => 'needs_input',
                        'read_only_validation' => 'needs_validation',
                        default => 'unsupported',
                    };
                    DB::table('ai_business_workflow_steps')->where('id', $step->id)
                        ->update(['status' => $next, 'updated_at' => now('UTC')]);
                    $step->status = $next;
                }
            }
            $this->bump($principal, $workflowId, $revision, 'reconciled');
        }, 3);
        return $this->planner->inspect($principal, $workflowId);
    }

    private function change(
        AiPrincipal $principal, string $workflowId, int $revision, string $stepKey,
        \Closure $update, string $event,
    ): array {
        DB::transaction(function () use ($principal, $workflowId, $revision, $stepKey, $update, $event): void {
            $workflow = $this->owned($principal, $workflowId)->lockForUpdate()->first();
            $this->assertRevision($workflow, $revision);
            if ($workflow->status !== 'planned') {
                throw ValidationException::withMessages(['workflow' => 'Only active planned workflows can advance.']);
            }
            $step = DB::table('ai_business_workflow_steps')->where([
                'workflow_id' => $workflowId, 'step_key' => $stepKey,
            ])->lockForUpdate()->first();
            if (!$step) throw ValidationException::withMessages(['step' => 'Unknown workflow step.']);
            if ($step->status === 'completed' && $event !== 'proposal_linked') {
                throw ValidationException::withMessages(['step' => 'Step was already verified.']);
            }
            $changes = $update($step, $workflow);
            if ($step->status === 'completed' && ($changes['status'] ?? '') !== 'completed') {
                throw ValidationException::withMessages(['step' => 'Completed evidence cannot be replaced by an unapproved draft.']);
            }
            DB::table('ai_business_workflow_steps')->where('id', $step->id)
                ->update($changes + ['updated_at' => now('UTC')]);
            $this->bump($principal, $workflowId, $revision, $event, ['step_key' => $stepKey]);
        }, 3);
        return $this->planner->inspect($principal, $workflowId);
    }

    private function assertDependencies(string $workflowId, object $step): void
    {
        $deps = json_decode($step->depends_on_json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($deps as $dep) {
            if (!DB::table('ai_business_workflow_steps')->where([
                'workflow_id' => $workflowId, 'step_key' => $dep, 'status' => 'completed',
            ])->exists()) {
                throw ValidationException::withMessages(['step' => 'Finish the earlier verified step before proceeding.']);
            }
        }
    }

    private function assertRevision(?object $workflow, int $revision): void
    {
        if (!$workflow) throw new AuthorizationException('Workflow not accessible.');
        if ($workflow->expires_at <= now('UTC')->toDateTimeString()) {
            throw ValidationException::withMessages(['workflow' => 'Workflow expired.']);
        }
        if ((int) $workflow->revision !== $revision) {
            throw new ConflictHttpException('Workflow revision changed; reload the timeline.');
        }
    }

    private function bump(AiPrincipal $principal, string $workflowId, int $revision, string $event, array $meta = []): void
    {
        $updated = $this->owned($principal, $workflowId)->where('revision', $revision)
            ->update(['revision' => $revision + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) throw new ConflictHttpException('Workflow was concurrently updated.');
        DB::table('ai_business_workflow_events')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $workflowId,
            'actor_id' => $principal->actorId, 'revision' => $revision + 1,
            'event_type' => $event, 'metadata_json' => json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'),
        ]);
    }

    private function owned(AiPrincipal $principal, string $id)
    {
        return DB::table('ai_business_workflows')->where([
            'id' => $id, 'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
            'actor_id' => $principal->actorId,
        ]);
    }

    private function enabled(AiPrincipal $principal): void
    {
        if (!config('ai_business_knowledge.enabled', false)
            || !config('ai_business_knowledge.planner_enabled', false)
            || !config('ai_business_knowledge.job_work_enabled', false)) {
            throw new AuthorizationException('Job-work workflow tracking is disabled.');
        }
        $this->security->assertEnabled($principal);
    }
}
