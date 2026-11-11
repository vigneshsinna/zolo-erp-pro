<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Manages Mode A (Pre-Company Bootstrap) proposals, versioned changes,
 * HMAC approvals, and atomic company creation execution.
 */
final class BootstrapProposalService
{
    public function __construct(
        private readonly BootstrapSetupSecurity $security,
        private readonly ActionApprovalSignature $signatures,
    ) {}

    public function initiateTask(BootstrapSetupPrincipal $principal): array
    {
        return [
            'task_id' => (string) Str::uuid(),
            'task_token' => $principal->taskToken,
            'actor_id' => $principal->actorId,
            'expires_at' => $principal->expiresAt->toIso8601String(),
            'status' => 'initiated',
        ];
    }

    public function createProposal(
        BootstrapSetupPrincipal $principal,
        string $taskId,
        string $actionKey,
        array $slots,
    ): array {
        $definition = $this->security->definition($actionKey, $principal);
        $validated = $this->security->validate($definition, $slots);

        $adapter = app($definition->previewAdapterClass);
        if (!($adapter instanceof ActionPreviewAdapterInterface)) {
            throw new \LogicException('Invalid preview adapter for bootstrap action.');
        }

        $preview = $adapter->preview($definition, $validated, $principal);
        if (!($preview instanceof ActionPreviewResult) || !preg_match('/^[a-f0-9]{64}$/D', $preview->sourceStateHash)) {
            throw new \LogicException('Bootstrap preview is missing its source-state hash.');
        }

        $now = now();
        $ttlMinutes = (int) config('ai_actions.proposal_ttl_minutes', 30);

        DB::table('ai_action_proposals')->updateOrInsert(
            [
                'id' => $taskId,
            ],
            [
                'actor_id' => $principal->actorId,
                'task_token' => $principal->taskToken,
                'company_id' => null,
                'branch_id' => null,
                'financial_year_id' => null,
                'action_type' => $definition->key,
                'action_key' => $definition->key,
                'action_title' => $definition->businessModule,
                'risk_tier' => $definition->riskTier,
                'version' => 1,
                'payload_json' => CanonicalActionPayload::json($preview->payload),
                'preview_json' => CanonicalActionPayload::json($preview->preview),
                'payload_hash' => CanonicalActionPayload::hash($preview->payload),
                'source_state_hash' => $preview->sourceStateHash,
                'status' => 'pending',
                'expires_at' => $now->copy()->addMinutes($ttlMinutes),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->getProposal($principal, $taskId);
    }

    public function applyChangeRequest(
        BootstrapSetupPrincipal $principal,
        string $taskId,
        array $changes,
        int $expectedVersion,
    ): array {
        $proposal = $this->getProposal($principal, $taskId);

        if ((int) $proposal['version'] !== $expectedVersion || !in_array($proposal['status'], ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['proposal' => 'The bootstrap proposal changed or cannot be edited.']);
        }

        $definition = $this->security->definition($proposal['action_key'], $principal);
        $basePayload = json_decode((string) $proposal['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $merged = array_replace($basePayload, $changes);
        $validated = $this->security->validate($definition, $merged);

        $adapter = app($definition->previewAdapterClass);
        $preview = $adapter->preview($definition, $validated, $principal);

        $ttlMinutes = (int) config('ai_actions.proposal_ttl_minutes', 30);
        $updated = DB::table('ai_action_proposals')
            ->where('id', $taskId)
            ->where('actor_id', $principal->actorId)
            ->where('task_token', $principal->taskToken)
            ->whereNull('company_id')
            ->where('version', $expectedVersion)
            ->whereIn('status', ['pending', 'approved'])
            ->where('expires_at', '>', now())
            ->update([
                'version' => $expectedVersion + 1,
                'payload_json' => CanonicalActionPayload::json($preview->payload),
                'preview_json' => CanonicalActionPayload::json($preview->preview),
                'payload_hash' => CanonicalActionPayload::hash($preview->payload),
                'source_state_hash' => $preview->sourceStateHash,
                'status' => 'pending',
                'expires_at' => now()->addMinutes($ttlMinutes),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['proposal' => 'The proposal changed; reload latest preview.']);
        }

        return $this->getProposal($principal, $taskId);
    }

    public function approveAndExecute(
        BootstrapSetupPrincipal $principal,
        string $taskId,
        int $expectedVersion,
        ?string $suppliedSignature = null,
    ): array {
        $key = 'ai-bootstrap:' . $taskId . ':v' . $expectedVersion;

        // Check if already executed (idempotency)
        $existing = DB::table('ai_action_executions')
            ->where('idempotency_key', $key)
            ->first();

        if ($existing && $existing->status === 'completed') {
            $receipt = json_decode((string) $existing->result_json, true, flags: JSON_THROW_ON_ERROR);
            return [
                'status' => 'completed',
                'proposal_id' => $taskId,
                'execution_id' => $existing->id,
                'company_id' => $existing->erp_resource_id,
                'transition_action' => 'switch_context',
                'context_switch_url' => '/select-company',
                'workspace_url' => '/ai',
                'receipt' => $receipt,
            ];
        }

        $executionClaim = DB::transaction(function () use ($principal, $taskId, $expectedVersion, $suppliedSignature, $key): array {
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $taskId)
                ->where('actor_id', $principal->actorId)
                ->where('task_token', $principal->taskToken)
                ->whereNull('company_id')
                ->lockForUpdate()
                ->first();

            if (!$proposal) {
                throw new AuthorizationException('Bootstrap proposal not accessible.');
            }

            $definition = $this->security->definition($proposal->action_key, $principal);

            if ((int) $proposal->version !== $expectedVersion) {
                throw ValidationException::withMessages(['proposal' => 'Proposal version is stale.']);
            }

            if ($proposal->status !== 'pending' && $proposal->status !== 'approved') {
                throw ValidationException::withMessages(['proposal' => 'Proposal is not pending or approved.']);
            }

            if ($proposal->expires_at && now()->greaterThanOrEqualTo($proposal->expires_at)) {
                throw ValidationException::withMessages(['proposal' => 'Proposal has expired.']);
            }

            $payload = json_decode((string) $proposal->payload_json, true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($proposal->payload_hash, CanonicalActionPayload::hash($payload))) {
                throw new AuthorizationException('Proposal payload hash mismatch.');
            }

            $timestamp = now('UTC')->format('Y-m-d H:i:s');
            $envelope = ActionApprovalService::envelope($proposal, $principal->actorId, $timestamp);
            $signature = $this->signatures->sign($envelope);

            if ($suppliedSignature !== null && !$this->signatures->matches($envelope, $suppliedSignature)) {
                throw new AuthorizationException('Human approval signature does not match proposal.');
            }

            // Record approval
            DB::table('ai_action_approvals')->insert([
                'id' => (string) Str::uuid(),
                'proposal_id' => $taskId,
                'approver_id' => $principal->actorId,
                'decision' => 'approved',
                'version' => $expectedVersion,
                'approval_signature' => $signature,
                'notes' => 'Mode A Bootstrap approval',
                'created_at' => $timestamp,
            ]);

            $executionId = (string) Str::uuid();
            DB::table('ai_action_executions')->insert([
                'id' => $executionId,
                'proposal_id' => $taskId,
                'company_id' => null,
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

            DB::table('ai_action_proposals')->where('id', $taskId)->update([
                'status' => 'executing',
                'updated_at' => now(),
            ]);

            return [
                'execution_id' => $executionId,
                'definition' => $definition,
                'proposal' => $proposal,
                'payload' => $payload,
                'key' => $key,
            ];
        });

        $adapter = app($executionClaim['definition']->commandAdapterClass);
        $start = hrtime(true);

        try {
            // Execution delegates directly to domain service (which owns its own transaction)
            $result = $adapter->execute(
                $executionClaim['definition'],
                $executionClaim['payload'],
                $principal,
                $principal->actorId,
                $executionClaim['key'],
                (string) $executionClaim['proposal']->source_state_hash,
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

            $durationMs = min(PHP_INT_MAX, (int) ((hrtime(true) - $start) / 1_000_000));

            DB::transaction(function () use ($executionClaim, $receipt, $result, $durationMs): void {
                DB::table('ai_action_executions')->where('id', $executionClaim['execution_id'])->update([
                    'status' => 'completed',
                    'company_id' => $result->resourceId,
                    'result_json' => CanonicalActionPayload::json($receipt),
                    'erp_resource_type' => $result->resourceType,
                    'erp_resource_id' => $result->resourceId,
                    'duration_ms' => $durationMs,
                ]);

                DB::table('ai_action_proposals')->where('id', $executionClaim['proposal']->id)
                    ->where('version', $executionClaim['proposal']->version)
                    ->update([
                        'status' => 'completed',
                        'company_id' => $result->resourceId,
                        'updated_at' => now(),
                    ]);
            });

            return [
                'status' => 'completed',
                'proposal_id' => $taskId,
                'execution_id' => $executionClaim['execution_id'],
                'company_id' => $result->resourceId,
                'transition_action' => 'switch_context',
                'context_switch_url' => '/select-company',
                'workspace_url' => '/ai',
                'receipt' => $receipt,
            ];
        } catch (Throwable $e) {
            DB::table('ai_action_executions')->where('id', $executionClaim['execution_id'])
                ->update(['status' => 'uncertain', 'error_message' => $e->getMessage()]);
            DB::table('ai_action_proposals')->where('id', $executionClaim['proposal']->id)
                ->update(['status' => 'requires_reconciliation', 'updated_at' => now()]);
            throw $e;
        }
    }

    public function getProposal(BootstrapSetupPrincipal $principal, string $taskId): array
    {
        $row = DB::table('ai_action_proposals')
            ->where('id', $taskId)
            ->where('actor_id', $principal->actorId)
            ->where('task_token', $principal->taskToken)
            ->whereNull('company_id')
            ->first();

        if (!$row) {
            throw new AuthorizationException('Bootstrap proposal not accessible.');
        }

        return (array) $row;
    }
}

