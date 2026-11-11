<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Unit;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;

final class UnitSetupCommandAdapter implements ActionCommandAdapterInterface
{
    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for unit setup execution.');
        }

        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $unit = DB::transaction(function () use ($context, $payload) {
            $row = Unit::where('company_id', $context->companyId)
                ->where('unit_code', $payload['unit_code'])
                ->first();

            if (!$row) {
                $row = Unit::forceCreate([
                    'company_id' => $context->companyId,
                    'unit_code' => $payload['unit_code'],
                    'unit_name' => $payload['unit_name'],
                    'base_unit' => $payload['base_unit'] ?? null,
                    'operator' => $payload['operator'] ?? '*',
                    'operation_value' => $payload['operation_value'] ?? 1.0,
                    'is_active' => true,
                ]);
            }

            return $row;
        });

        return new ActionExecutionReceipt(
            resourceType: 'unit',
            resourceId: (int) $unit->id,
            status: 'completed',
            details: [
                'company_id' => $context->companyId,
                'unit_id' => (int) $unit->id,
                'unit_code' => $unit->unit_code,
                'unit_name' => $unit->unit_name,
            ],
        );
    }

    public function recover(
        string $idempotencyKey,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            return null;
        }

        $row = DB::table('ai_action_executions')
            ->where('company_id', $context->companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if (!$row) {
            return null;
        }

        $result = json_decode((string) $row->result_json, true, flags: JSON_THROW_ON_ERROR);

        return new ActionExecutionReceipt(
            resourceType: (string) ($row->erp_resource_type ?? 'unit'),
            resourceId: (int) ($row->erp_resource_id ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}

