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
            $reusedElsewhere = DB::table('ai_business_workflow_steps')
                ->where('proposal_id', $proposal['id'])
                ->where(function ($query) use ($workflow, $step): void {
                    $query->where('workflow_id', '!=', $workflow->id)
                        ->orWhere('step_key', '!=', $step->step_key);
                })
                ->exists();
            if ($reusedElsewhere) {
                throw ValidationException::withMessages(['proposal' => 'Proposal is already linked to another workflow step.']);
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
                'version' => (int) $proposal['version'], 'status' => 'completed',
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

    public function completeClarification(
        AiPrincipal $principal, string $workflowId, int $revision,
        string $stepKey, array $inputs,
    ): array {
        $this->enabled($principal);
        return $this->change($principal, $workflowId, $revision, $stepKey, function ($step, $workflow) use ($principal, $stepKey, $inputs): array {
            if ($step->kind !== 'read_or_clarify') {
                throw ValidationException::withMessages(['step' => 'Step does not accept structured clarification inputs.']);
            }
            $this->assertDependencies($workflow->id, $step);
            if (empty($inputs) || (isset($inputs['status']) && $inputs['status'] === 'done' && count($inputs) === 1)) {
                throw ValidationException::withMessages(['inputs' => 'Clarification requires typed, server-validated structured fields, not a raw status assertion.']);
            }
            foreach ($inputs as $key => $val) {
                if ($val === null || $val === '') {
                    throw ValidationException::withMessages(['inputs' => "Field '{$key}' cannot be empty."]);
                }
            }
            $hash = hash('sha256', $workflow->id.':'.$stepKey.':'.json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).':'.$principal->actorId);
            return [
                'status' => 'completed',
                'evidence_hash' => $hash,
            ];
        }, 'clarification_completed');
    }

    public function completeValidation(
        AiPrincipal $principal, string $workflowId, int $revision,
        string $stepKey, array $validationEvidence,
    ): array {
        $this->enabled($principal);
        return $this->change($principal, $workflowId, $revision, $stepKey, function ($step, $workflow) use ($principal, $stepKey, $validationEvidence): array {
            if ($step->kind !== 'read_only_validation') {
                throw ValidationException::withMessages(['step' => 'Step does not accept read-only validation results.']);
            }
            $this->assertDependencies($workflow->id, $step);
            if (empty($validationEvidence['validator']) || empty($validationEvidence['source_hash'])) {
                throw ValidationException::withMessages(['validation' => 'Read-only validation requires a named validator and source state hash.']);
            }
            $hash = hash('sha256', $workflow->id.':'.$stepKey.':'.$validationEvidence['validator'].':'.$validationEvidence['source_hash'].':'.$principal->actorId);
            return [
                'status' => 'completed',
                'evidence_hash' => $hash,
            ];
        }, 'validation_completed');
    }

    public function verifyExternalEvent(
        AiPrincipal $principal, string $workflowId, int $revision,
        string $stepKey, array $evidence,
    ): array {
        $this->enabled($principal);
        return $this->change($principal, $workflowId, $revision, $stepKey, function ($step, $workflow) use ($principal, $stepKey, $evidence): array {
            if ($step->kind !== 'external_event') {
                throw ValidationException::withMessages(['step' => 'Step is not an external event.']);
            }
            $this->assertDependencies($workflow->id, $step);

            // Strictly reject free-text statements like "goods received" or "done"
            if (isset($evidence['text']) || isset($evidence['message']) || !isset($evidence['document_type']) || !isset($evidence['resource_id'])) {
                throw ValidationException::withMessages(['evidence' => 'A user statement or free-text description cannot advance an external event. A verified, posted ERP document is required.']);
            }

            $context = $this->security->context($principal);
            $docType = (string) $evidence['document_type'];
            $resourceId = (int) $evidence['resource_id'];

            if ($docType === 'job_work_receipt') {
                $receipt = JobWorkReceipt::visibleIn($context)
                    ->where('financial_year_id', $context->financialYearId)
                    ->where('id', $resourceId)->whereNotNull('posted_at')->whereNull('reversed_at')->first();
                if (!$receipt) {
                    throw ValidationException::withMessages(['evidence' => 'A posted, active physical receipt is required.']);
                }
                $dispatchStep = DB::table('ai_business_workflow_steps')->where([
                    'workflow_id' => $workflow->id, 'step_key' => 'dispatch', 'status' => 'completed',
                ])->first();
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
                    ->whereColumn('e.version', 'p.version')
                    ->where('e.status', 'completed')->where('e.erp_resource_type', 'job_work_dispatch')
                    ->where('e.erp_resource_id', $receipt->dispatch_id)->select('e.*')->first();
                if (!$dispatchReceipt) {
                    throw ValidationException::withMessages(['evidence' => 'Physical return does not belong to the workflow dispatch.']);
                }
                $evidenceHash = hash('sha256', $workflow->id.':'.$dispatchReceipt->id.':'.$receipt->id.':'.$receipt->posted_at);
            } elseif ($docType === 'purchase_order') {
                $poStep = DB::table('ai_business_workflow_steps')->where([
                    'workflow_id' => $workflow->id, 'step_key' => 'prepare_po', 'status' => 'completed',
                ])->first();
                if (!$poStep || !$poStep->proposal_id) {
                    throw ValidationException::withMessages(['evidence' => 'No approved PO is linked to this workflow.']);
                }
                $poExecution = DB::table('ai_action_executions as e')
                    ->join('ai_action_proposals as p', 'p.id', '=', 'e.proposal_id')
                    ->where('p.id', $poStep->proposal_id)
                    ->where('p.company_id', $principal->companyId)
                    ->where('p.branch_id', $principal->branchId)
                    ->where('p.financial_year_id', $principal->financialYearId)
                    ->where('p.actor_id', $principal->actorId)
                    ->whereColumn('e.version', 'p.version')
                    ->where('e.status', 'completed')->where('e.erp_resource_id', $resourceId)->first();
                if (!$poExecution) {
                    throw ValidationException::withMessages(['evidence' => 'Document resource does not match the approved PO in this workflow.']);
                }
                $po = \App\Models\Purchase::visibleIn($context)->where('status', 4)->find($resourceId);
                if (!$po) {
                    throw ValidationException::withMessages(['evidence' => 'The purchase order must be in active Ordered status.']);
                }
                $evidenceHash = hash('sha256', $workflow->id.':po_delivery:'.$resourceId.':'.$principal->actorId);
            } else {
                throw ValidationException::withMessages(['evidence' => "Unsupported external document type '{$docType}'."]);
            }

            return [
                'status' => 'completed',
                'evidence_hash' => $evidenceHash,
            ];
        }, 'external_event_verified');
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
                    ->whereColumn('e.version', 'p.version')
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
                ->whereColumn('e.version', 'p.version')
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
            $this->assertProcessEnabled($workflow, $principal);
            if ($workflow->status !== 'planned') {
                throw ValidationException::withMessages(['workflow' => 'Pause/cancelled workflows cannot progress.']);
            }
            $steps = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)->orderBy('created_at')->get();
            // Refresh linked actions from the exact current proposal version.
            // Neither user chat nor a model response is sufficient proof of execution.
            foreach ($steps as $step) {
                if ($step->kind !== 'reviewed_action' || !$step->proposal_id
                    || !in_array($step->status, ['awaiting_approval', 'awaiting_execution'], true)) continue;
                $proposal = DB::table('ai_action_proposals')->where([
                    'id' => $step->proposal_id, 'actor_id' => $principal->actorId,
                    'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                    'action_key' => $step->action_key,
                ])->first();
                if (!$proposal || in_array($proposal->status, ['cancelled', 'rejected', 'expired', 'failed'], true)
                    || ($proposal->status === 'pending'
                        && $proposal->expires_at <= now('UTC')->toDateTimeString())) {
                    DB::table('ai_business_workflow_steps')->where('id', $step->id)->update([
                        'status' => 'awaiting_proposal', 'proposal_id' => null,
                        'evidence_hash' => null, 'updated_at' => now('UTC'),
                    ]);
                    $step->status = 'awaiting_proposal';
                    continue;
                }
                $receipt = DB::table('ai_action_executions')->where([
                    'proposal_id' => $proposal->id, 'version' => $proposal->version,
                    'company_id' => $principal->companyId, 'status' => 'completed',
                ])->whereNotNull('erp_resource_id')->first();
                if ($proposal->status === 'completed' && $receipt) {
                    DB::table('ai_business_workflow_steps')->where('id', $step->id)->update([
                        'status' => 'completed',
                        'evidence_hash' => hash('sha256', $proposal->id.':'.$proposal->payload_hash.':'.$receipt->id),
                        'updated_at' => now('UTC'),
                    ]);
                    $step->status = 'completed';
                } elseif (in_array($proposal->status, ['approved', 'executing'], true)) {
                    DB::table('ai_business_workflow_steps')->where('id', $step->id)
                        ->update(['status' => 'awaiting_execution', 'updated_at' => now('UTC')]);
                    $step->status = 'awaiting_execution';
                }
            }

            // Revalidate completed physical_return steps if underlying receipt was reversed.
            $context = $this->security->context($principal);
            foreach ($steps as $step) {
                if ($step->status !== 'completed' || $step->kind !== 'external_event' || $step->step_key !== 'physical_return') {
                    continue;
                }
                $grnStep = $steps->firstWhere('step_key', 'receipt');
                if (!$grnStep || !$grnStep->proposal_id) {
                    continue;
                }
                $grnExec = DB::table('ai_action_executions')
                    ->where('proposal_id', $grnStep->proposal_id)
                    ->where('company_id', $principal->companyId)
                    ->where('erp_resource_type', 'job_work_receipt')
                    ->where('status', 'completed')
                    ->first();
                if ($grnExec && $grnExec->erp_resource_id) {
                    $stillActive = JobWorkReceipt::visibleIn($context)
                        ->where('financial_year_id', $context->financialYearId)
                        ->where('id', $grnExec->erp_resource_id)
                        ->whereNotNull('posted_at')
                        ->whereNull('reversed_at')
                        ->exists();
                    if (!$stillActive) {
                        DB::table('ai_business_workflow_steps')->where('id', $step->id)->update([
                            'status' => 'needs_reconciliation',
                            'evidence_hash' => null,
                            'updated_at' => now('UTC'),
                        ]);
                        $step->status = 'needs_reconciliation';
                    }
                }
            }
            $byKey = $steps->keyBy('step_key');
            foreach ($steps as $step) {
                if ($step->status !== 'blocked') continue;
                $dependencies = json_decode($step->depends_on_json, true, flags: JSON_THROW_ON_ERROR);
                if (collect($dependencies)->every(static fn (string $k): bool => in_array($byKey[$k]->status ?? '', ['completed', 'skipped_not_applicable'], true))) {
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
            $freshSteps = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)->get();
            $allCompleted = $freshSteps->isNotEmpty()
                && $freshSteps->every(static fn ($s): bool => in_array($s->status, ['completed', 'skipped_not_applicable'], true));
            if ($allCompleted) {
                DB::table('ai_business_workflows')->where('id', $workflowId)->update([
                    'status' => 'completed', 'updated_at' => now('UTC'),
                ]);
                $this->bump($principal, $workflowId, $revision, 'workflow_completed');
            } else {
                $this->bump($principal, $workflowId, $revision, 'reconciled');
            }
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
            $this->assertProcessEnabled($workflow, $principal);
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

            if (in_array($changes['status'] ?? '', ['completed', 'skipped_not_applicable'], true)) {
                $allSteps = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)->get();
                $byKey = $allSteps->keyBy('step_key');
                foreach ($allSteps as $s) {
                    if ($s->status !== 'blocked') continue;
                    $deps = json_decode($s->depends_on_json, true, flags: JSON_THROW_ON_ERROR);
                    if (collect($deps)->every(static fn (string $k): bool => in_array($byKey[$k]->status ?? '', ['completed', 'skipped_not_applicable'], true))) {
                        $next = match ($s->kind) {
                            'reviewed_action' => 'awaiting_proposal',
                            'external_event' => 'awaiting_real_event',
                            'read_or_clarify' => 'needs_input',
                            'read_only_validation' => 'needs_validation',
                            default => 'unsupported',
                        };
                        DB::table('ai_business_workflow_steps')->where('id', $s->id)
                            ->update(['status' => $next, 'updated_at' => now('UTC')]);
                    }
                }

                $freshSteps = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)->get();
                if ($freshSteps->isNotEmpty() && $freshSteps->every(static fn ($s): bool => in_array($s->status, ['completed', 'skipped_not_applicable'], true))) {
                    DB::table('ai_business_workflows')->where('id', $workflowId)
                        ->update(['status' => 'completed', 'updated_at' => now('UTC')]);
                    DB::table('ai_business_workflow_events')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'workflow_id' => $workflowId,
                        'actor_id' => $principal->actorId,
                        'revision' => $revision + 1,
                        'event_type' => 'workflow_completed',
                        'metadata_json' => json_encode(['completed_at' => now('UTC')->toIso8601String()], JSON_THROW_ON_ERROR),
                        'created_at' => now('UTC'),
                    ]);
                }
            }
            $this->bump($principal, $workflowId, $revision, $event, ['step_key' => $stepKey]);
        }, 3);
        return $this->planner->inspect($principal, $workflowId);
    }

    private function assertDependencies(string $workflowId, object $step): void
    {
        $deps = json_decode($step->depends_on_json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($deps as $dep) {
            if (!DB::table('ai_business_workflow_steps')
                ->where('workflow_id', $workflowId)
                ->where('step_key', $dep)
                ->whereIn('status', ['completed', 'skipped_not_applicable'])
                ->exists()) {
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

    /** Skip explicitly optional non-applicable predecessor steps (e.g., direct route without DC/SO). */
    public function skipNotApplicableStep(
        AiPrincipal $principal,
        string $workflowId,
        int $revision,
        string $stepKey,
        string $reason,
    ): array {
        $this->enabled($principal);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Specify why the optional step does not apply on this route.']);
        }
        return $this->change($principal, $workflowId, $revision, $stepKey, function (object $step, object $workflow) use ($principal, $workflowId, $stepKey, $reason): array {
            if ($step->status === 'skipped_not_applicable') {
                throw ValidationException::withMessages(['step' => 'Step was already skipped as not applicable.']);
            }
            $this->assertDependencies($workflowId, $step);
            $preview = $this->planner->preview($principal, (string) $workflow->process_id);
            $stepDef = collect($preview['steps'] ?? [])->firstWhere('step_key', $stepKey);
            if (!is_array($stepDef) || empty($stepDef['optional'])) {
                throw ValidationException::withMessages(['step' => 'Required workflow steps cannot be skipped.']);
            }

            return [
                'status' => 'skipped_not_applicable',
                'evidence_hash' => hash('sha256', 'skipped:'.$stepKey.':'.trim($reason)),
            ];
        }, 'step_skipped');
    }

    private function assertProcessEnabled(object $workflow, ?AiPrincipal $principal = null): void
    {
        if (str_contains((string) $workflow->process_id, 'job_work')
            && !config('ai_business_knowledge.job_work_enabled', false)) {
            throw new AuthorizationException('Job-work workflow tracking is disabled.');
        }
        if ($principal !== null) {
            $this->planner->assertWorkflowDefinitionCurrent($principal, $workflow);
        }
    }

    private function enabled(AiPrincipal $principal): void
    {
        if (!config('ai_business_knowledge.enabled', false)
            || !config('ai_business_knowledge.planner_enabled', false)) {
            throw new AuthorizationException('Business workflow tracking is disabled.');
        }
        $this->security->assertEnabled($principal);
    }
}
