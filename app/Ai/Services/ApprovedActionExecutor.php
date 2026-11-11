<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Two-phase, approved-only command dispatcher. PH1 has no registered commands.
 * Claim is atomic; execution is delegated to the established domain service.
 */
final class ApprovedActionExecutor
{
    public function __construct(
        private readonly ActionSecurity $security,
        private readonly ActionApprovalService $approvals,
        private readonly ActionCapabilityRegistry $registry,
    ) {}

    public function execute(AiPrincipal $principal, string $proposalId, int $expectedVersion): array
    {
        // Independent kill switch; approval alone never authorizes execution.
        if (!config('ai_actions.execution_enabled', false)) {
            throw new AuthorizationException('AI business action execution is disabled.');
        }

        $this->security->assertEnabled($principal);
        $this->security->context($principal);

        // Short transaction: locks only AI proposal/execution state; NO model or
        // domain invocation while holding these locks.
        $claimed = DB::transaction(function () use ($principal, $proposalId, $expectedVersion): array {
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $proposalId)
                ->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)
                ->whereNotNull('action_key')
                ->whereNotNull('payload_hash')
                ->lockForUpdate()->first();

            if (!$proposal) {
                throw new AuthorizationException('Proposal not accessible.');
            }
            $definition = $this->security->definition($proposal->action_key, $principal);
            if (!$definition->domainOwnsTransaction || !$definition->requiresApproval) {
                throw new AuthorizationException('Unsafe action execution adapter.');
            }

            if ((int) $proposal->version !== $expectedVersion) {
                throw ValidationException::withMessages(['proposal' => 'Proposal version is stale.']);
            }

            $key = 'ai-action:' . $proposal->id . ':v' . $proposal->version;
            $existing = DB::table('ai_action_executions')
                ->where('company_id', $principal->companyId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                // Do not rerun an uncertain or incomplete posting.
                return ['existing' => $existing, 'definition' => $definition, 'proposal' => $proposal, 'key' => $key];
            }

            $isExpired = !$proposal->expires_at || \Illuminate\Support\Carbon::parse($proposal->expires_at, 'UTC')->isPast();
            if ($proposal->status !== 'approved' || $isExpired) {
                throw ValidationException::withMessages(['proposal' => 'Proposal is not an approved current version.']);
            }

            $this->approvals->assertApproved($proposal);
            // Re-read the exact approved command to rule out hash tampering.
            $payload = json_decode($proposal->payload_json, true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($proposal->payload_hash, CanonicalActionPayload::hash($payload))) {
                throw new AuthorizationException('Approved command payload mismatch.');
            }

            // Current approver must still have permissions, even if a different
            // user triggered the execution worker.
            $approval = DB::table('ai_action_approvals')
                ->where('proposal_id', $proposal->id)
                ->where('version', $proposal->version)
                ->where('decision', 'approved')
                ->whereNotNull('approval_signature')
                ->orderByDesc('created_at')->first();
            if (!$approval) {
                throw new AuthorizationException('No valid approval.');
            }

            $approver = new AiPrincipal(
                actorId: (int) $approval->approver_id,
                companyId: (int) $proposal->company_id,
                branchId: (int) $proposal->branch_id,
                financialYearId: (int) $proposal->financial_year_id,
            );
            $this->security->definition($proposal->action_key, $approver);
            app(\App\Services\Commercial\CommercialPermission::class)->assert('ai.action.approve', $this->security->context($approver), $approver->actorId);

            $id = (string) Str::uuid();
            DB::table('ai_action_executions')->insert([
                'id' => $id,
                'proposal_id' => $proposal->id,
                'company_id' => $proposal->company_id,
                'executor_id' => $principal->actorId,
                'version' => $expectedVersion,
                'idempotency_key' => $key,
                'status' => 'executing',
                'result_json' => '{}',
                'error_message' => null,
                'domain_service' => $definition->commandAdapterClass,
                'domain_method' => 'execute',
                'executed_at' => now(),
            ]);
            DB::table('ai_action_proposals')->where('id', $proposalId)->update([
                'status' => 'executing',
                'updated_at' => now(),
            ]);

            return ['execution_id' => $id, 'definition' => $definition, 'proposal' => $proposal, 'payload' => $payload, 'key' => $key];
        }, 1);

        if (isset($claimed['existing'])) {
            $existing = $claimed['existing'];
            if ($existing->status === 'completed') {
                return [
                    'status' => 'completed',
                    'proposal_id' => $proposalId,
                    'execution_id' => $existing->id,
                    'receipt' => json_decode($existing->result_json, true, flags: JSON_THROW_ON_ERROR),
                ];
            }
            // For an uncertain commit, manual reconciliation or explicit adapter
            // recovery is required before any retry is permitted.
            return ['status' => 'requires_reconciliation', 'proposal_id' => $proposalId, 'execution_id' => $existing->id];
        }

        $adapter = app($claimed['definition']->commandAdapterClass);
        $start = hrtime(true);
        try {
            // No outer transaction here: the domain adapter MUST re-check live
            // source state/locks, fiscal period and idempotency inside the domain
            // service's own transaction, or fail. The preview is not trusted.
            $result = $adapter->execute(
                $claimed['definition'],
                $claimed['payload'],
                $this->security->context($principal),
                $principal->actorId,
                $claimed['key'],
                (string) $claimed['proposal']->source_state_hash,
            );

            if (!($result instanceof ActionExecutionReceipt)) {
                throw new \LogicException('Domain adapter did not return an ERP receipt.');
            }

            $receipt = [
                'resource_type' => $result->resourceType,
                'resource_id' => $result->resourceId,
                'status' => $result->status,
                'details' => $result->details,
            ];

            DB::transaction(function () use ($claimed, $receipt, $result, $start): void {
                DB::table('ai_action_executions')->where('id', $claimed['execution_id'])->update([
                    'status' => 'completed',
                    'result_json' => CanonicalActionPayload::json($receipt),
                    'erp_resource_type' => $result->resourceType,
                    'erp_resource_id' => $result->resourceId,
                    'duration_ms' => min(PHP_INT_MAX, (int) ((hrtime(true) - $start) / 1_000_000)),
                ]);
                DB::table('ai_action_proposals')->where('id', $claimed['proposal']->id)
                    ->where('version', $claimed['proposal']->version)
                    ->update(['status' => 'completed', 'updated_at' => now()]);
            });

            return ['status' => 'completed', 'execution_id' => $claimed['execution_id'], 'receipt' => $receipt];
        } catch (Throwable $e) {
            // Never automatically retry: command may already have committed.
            // Reconciliation must examine the authoritative ERP idempotency receipt.
            DB::table('ai_action_executions')->where('id', $claimed['execution_id'])
                ->update(['status' => 'uncertain', 'error_message' => 'Execution outcome requires verification.']);
            DB::table('ai_action_proposals')->where('id', $claimed['proposal']->id)
                ->update(['status' => 'requires_reconciliation', 'updated_at' => now()]);
            throw $e;
        }
    }
}
