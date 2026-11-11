<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\CompanyBranch;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

final class WarehouseSetupPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for warehouse setup.');
        }

        $branchId = (int) ($slots['branch_id'] ?? 0);
        $name = trim((string) ($slots['name'] ?? ''));
        $address = trim((string) ($slots['address'] ?? '-'));

        $branch = CompanyBranch::where('company_id', $context->companyId)->whereKey($branchId)->first();
        if (!$branch) {
            throw ValidationException::withMessages(['branch_id' => 'Selected branch is not available.']);
        }

        $existing = Warehouse::where('company_id', $context->companyId)
            ->where('branch_id', $branchId)
            ->where('name', $name)
            ->first();

        $previewData = [
            'company_id' => $context->companyId,
            'warehouse' => [
                'branch_id' => $branchId,
                'branch_name' => $branch->name,
                'name' => $name,
                'address' => $address,
                'phone' => $slots['phone'] ?? null,
                'email' => $slots['email'] ?? null,
                'is_existing' => $existing !== null,
            ],
        ];

        $sourceStateHash = hash('sha256', 'WAREHOUSE_SETUP:' . $context->companyId . ':' . $branchId . ':' . $name . ':' . ($existing?->id ?? 'NEW'));

        return new ActionPreviewResult(
            payload: [
                'branch_id' => $branchId,
                'name' => $name,
                'address' => $address,
                'phone' => $slots['phone'] ?? null,
                'email' => $slots['email'] ?? null,
            ],
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }
}

