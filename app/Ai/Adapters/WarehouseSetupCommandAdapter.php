<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanySetupService;
use Illuminate\Support\Facades\DB;

final class WarehouseSetupCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly CompanySetupService $setup,
    ) {}

    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for warehouse setup execution.');
        }

        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $warehouse = $this->setup->location($actorId, $context->companyId, $payload);

        return new ActionExecutionReceipt(
            resourceType: 'warehouse',
            resourceId: (int) $warehouse->id,
            status: 'completed',
            details: [
                'company_id' => $context->companyId,
                'warehouse_id' => (int) $warehouse->id,
                'branch_id' => (int) $warehouse->branch_id,
                'name' => $warehouse->name,
            ],
        );
    }

    public function recover(
        string $idempotencyKey,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        $row = DB::table('ai_action_executions')
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if (!$row) {
            return null;
        }

        $result = json_decode((string) $row->result_json, true, flags: JSON_THROW_ON_ERROR);

        return new ActionExecutionReceipt(
            resourceType: (string) ($row->erp_resource_type ?? 'warehouse'),
            resourceId: (int) ($row->erp_resource_id ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}

