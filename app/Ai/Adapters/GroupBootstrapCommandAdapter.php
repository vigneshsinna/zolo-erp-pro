<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\CompanyGroup;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Command adapter for Mode A company group creation (setup.group.create.v1).
 * Calls CompanyDirectoryService::createGroup exactly once in a single transaction.
 * Includes safe ERP domain reconciliation for groups committed before AI receipts are saved.
 */
final class GroupBootstrapCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly CompanyDirectoryService $directory,
    ) {}

    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        // 1. Idempotency recovery
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        // 2. Safe Domain Reconciliation: Check if group was already committed to the ERP database
        $reconciled = $this->reconcileCommittedGroup($payload, $actorId, $idempotencyKey);
        if ($reconciled !== null) {
            return $reconciled;
        }

        // 3. Revalidate actor installation admin eligibility
        if (!$this->directory->isInstallationAdmin($actorId)) {
            throw new AuthorizationException('Installation administrator authority required to create a company group.');
        }

        // 4. Execute group creation through authoritative ERP domain service
        $group = $this->directory->createGroup($actorId, $payload);

        // Record the durable resource link on the active execution claim
        DB::table('ai_action_executions')
            ->where('idempotency_key', $idempotencyKey)
            ->where('executor_id', $actorId)
            ->update([
                'erp_resource_type' => 'company_group',
                'erp_resource_id' => $group->id,
            ]);

        return new ActionExecutionReceipt(
            resourceType: 'company_group',
            resourceId: (int) $group->id,
            status: 'completed',
            details: [
                'group_id' => (int) $group->id,
                'code' => $group->code,
                'name' => $group->name,
                'admin_id' => $actorId,
            ],
        );
    }

    public function recover(
        string $idempotencyKey,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        $row = DB::table('ai_action_executions')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (!$row) {
            return null;
        }

        if ($row->status === 'completed') {
            $result = json_decode((string) $row->result_json, true, flags: JSON_THROW_ON_ERROR);

            return new ActionExecutionReceipt(
                resourceType: (string) ($row->erp_resource_type ?? $result['resource_type'] ?? 'company_group'),
                resourceId: (int) ($row->erp_resource_id ?? $result['resource_id'] ?? 0),
                status: 'completed',
                details: (array) ($result['details'] ?? []),
            );
        }

        return null;
    }

    public function reconcileCommittedGroup(array $payload, int $actorId, string $idempotencyKey): ?ActionExecutionReceipt
    {
        // Security Invariant: Reconciliation is ONLY permitted for an execution that was already
        // authenticated, approved, and claimed using the exact proposal idempotency key,
        // and where the ERP resource was durably linked to this exact execution.
        $execution = DB::table('ai_action_executions')
            ->where('idempotency_key', $idempotencyKey)
            ->where('executor_id', $actorId)
            ->where('erp_resource_type', 'company_group')
            ->whereNotNull('erp_resource_id')
            ->first();

        if (!$execution || empty($execution->erp_resource_id)) {
            return null;
        }

        $group = CompanyGroup::where('id', $execution->erp_resource_id)->first();
        if (!$group) {
            return null;
        }

        // Verify group administration by this actor
        $isAdmin = DB::table('company_group_admins')
            ->where('company_group_id', $group->id)
            ->where('user_id', $actorId)
            ->exists();

        if ($isAdmin) {
            return new ActionExecutionReceipt(
                resourceType: 'company_group',
                resourceId: (int) $group->id,
                status: 'completed',
                details: [
                    'group_id' => (int) $group->id,
                    'code' => $group->code,
                    'name' => $group->name,
                    'admin_id' => $actorId,
                    'reconciled_from_erp' => true,
                ],
            );
        }

        return null;
    }
}

