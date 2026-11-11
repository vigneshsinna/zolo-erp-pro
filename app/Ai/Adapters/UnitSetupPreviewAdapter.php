<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Unit;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

final class UnitSetupPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for unit setup.');
        }

        $code = strtoupper(trim((string) ($slots['unit_code'] ?? '')));
        $name = trim((string) ($slots['unit_name'] ?? ''));

        if ($code === '' || $name === '') {
            throw ValidationException::withMessages(['unit_code' => 'Unit code and name are required.']);
        }

        $existing = Unit::where('company_id', $context->companyId)->where('unit_code', $code)->first();
        if ($existing && $existing->unit_name !== $name) {
            throw ValidationException::withMessages(['unit_code' => 'Unit code already exists with different details.']);
        }

        $baseUnitId = !empty($slots['base_unit']) ? (int) $slots['base_unit'] : null;
        $baseUnit = null;
        if ($baseUnitId) {
            $baseUnit = Unit::where('company_id', $context->companyId)->find($baseUnitId);
            if (!$baseUnit) {
                throw ValidationException::withMessages(['base_unit' => 'Selected base unit not found in company.']);
            }
        }
        $operator = (string) ($slots['operator'] ?? '*');
        $operationValue = (float) ($slots['operation_value'] ?? 1.0);

        $payload = [
            'unit_code' => $code,
            'unit_name' => $name,
            'base_unit' => $baseUnit?->id,
            'operator' => $operator,
            'operation_value' => sprintf('%.4f', $operationValue),
        ];

        $previewData = [
            'entity' => 'Unit of Measure',
            'unit_code' => $code,
            'unit_name' => $name,
            'is_base_unit' => $baseUnit === null,
            'conversion' => $baseUnit ? "1 {$code} = {$operationValue} {$operator} {$baseUnit->unit_code}" : 'Base UOM',
        ];

        $sourceStateHash = self::computeSourceStateHash($context, $code, $baseUnit?->id);

        return new ActionPreviewResult(
            payload: $payload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }

    public static function computeSourceStateHash(
        CompanyContext $context,
        string $code,
        ?int $baseUnitId,
    ): string {
        $existing = Unit::where('company_id', $context->companyId)->where('unit_code', $code)->first();
        $baseUnit = $baseUnitId ? Unit::where('company_id', $context->companyId)->where('id', $baseUnitId)->first() : null;

        $state = [
            'company_id' => $context->companyId,
            'unit_code' => $code,
            'existing_id' => $existing?->id,
            'base_unit_id' => $baseUnit?->id,
            'base_unit_updated_at' => $baseUnit?->updated_at?->toIso8601String(),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
