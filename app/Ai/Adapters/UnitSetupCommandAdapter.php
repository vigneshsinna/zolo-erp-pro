<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Unit;
use App\Services\Commercial\CommercialPermission;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // 1. Assert action-specific Spatie permission
        app(CommercialPermission::class)->assert('unit', $context, $actorId);

        // 2. Idempotency recovery
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $code = (string) ($payload['unit_code'] ?? '');
        $baseUnitId = !empty($payload['base_unit']) ? (int) $payload['base_unit'] : null;

        // 3. Enforce source-state hash
        $currentSourceStateHash = UnitSetupPreviewAdapter::computeSourceStateHash($context, $code, $baseUnitId);
        if (!hash_equals($expectedSourceStateHash, $currentSourceStateHash)) {
            throw ValidationException::withMessages([
                'source_state' => 'Underlying ERP business state changed since proposal approval. Rebuild preview.',
            ]);
        }

        // 4. Validate ownership and execute within transactional boundary
        $unit = DB::transaction(function () use ($context, $payload, $baseUnitId) {
            if ($baseUnitId !== null) {
                app(CompanyWriteGuard::class)->owned(Unit::class, $baseUnitId, $context, 'base_unit');
            }

            $row = Unit::where('company_id', $context->companyId)
                ->where('unit_code', $payload['unit_code'])
                ->first();

            if (!$row) {
                $row = Unit::forceCreate([
                    'company_id' => $context->companyId,
                    'unit_code' => $payload['unit_code'],
                    'unit_name' => $payload['unit_name'],
                    'base_unit' => $baseUnitId,
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

