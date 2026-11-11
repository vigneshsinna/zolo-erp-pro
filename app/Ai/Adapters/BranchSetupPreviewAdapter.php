<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\CompanyBranch;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

final class BranchSetupPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for branch setup.');
        }

        $code = strtoupper(trim((string) ($slots['code'] ?? '')));
        $name = trim((string) ($slots['name'] ?? ''));

        $existing = CompanyBranch::where('company_id', $context->companyId)->where('code', $code)->first();
        if ($existing && $existing->name !== $name) {
            throw ValidationException::withMessages(['code' => 'Branch code already has different details.']);
        }

        $previewData = [
            'company_id' => $context->companyId,
            'branch' => [
                'code' => $code,
                'name' => $name,
                'branch_type' => 'branch',
                'is_existing' => $existing !== null,
            ],
            'effects' => [
                'seeds_series_for_open_fiscal_years' => true,
                'grants_membership_to_creator' => true,
            ],
        ];

        $sourceStateHash = hash('sha256', 'BRANCH_SETUP:' . $context->companyId . ':' . $code . ':' . ($existing?->id ?? 'NEW'));

        return new ActionPreviewResult(
            payload: ['code' => $code, 'name' => $name],
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }
}

