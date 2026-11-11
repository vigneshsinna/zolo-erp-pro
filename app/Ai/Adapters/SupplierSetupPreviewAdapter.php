<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Supplier;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

final class SupplierSetupPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for supplier setup.');
        }

        $name = trim((string) ($slots['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Supplier name is required.']);
        }

        $companyName = trim((string) ($slots['company_name'] ?? ''));
        $vatNumber = strtoupper(trim((string) ($slots['vat_number'] ?? '')));

        $existing = Supplier::where('company_id', $context->companyId)
            ->where(function ($q) use ($name, $companyName, $vatNumber) {
                $q->where('name', $name);
                if ($companyName !== '') {
                    $q->orWhere('company_name', $companyName);
                }
                if ($vatNumber !== '') {
                    $q->orWhere('vat_number', $vatNumber);
                }
            })->first();

        $payload = [
            'name' => $name,
            'company_name' => $companyName !== '' ? $companyName : null,
            'phone_number' => (string) ($slots['phone_number'] ?? ''),
            'vat_number' => $vatNumber !== '' ? $vatNumber : null,
            'state' => (string) ($slots['state'] ?? 'Tamil Nadu'),
            'city' => (string) ($slots['city'] ?? ''),
            'address' => (string) ($slots['address'] ?? ''),
            'email' => !empty($slots['email']) ? (string) $slots['email'] : null,
        ];

        $previewData = [
            'entity' => 'Supplier Directory',
            'name' => $name,
            'company_name' => $companyName,
            'vat_number' => $vatNumber,
            'state' => $payload['state'],
            'is_existing' => $existing !== null,
        ];

        $sourceStateHash = hash('sha256', 'SUPPLIER_SETUP:' . $context->companyId . ':' . $name . ':' . ($existing?->id ?? 'NEW'));

        return new ActionPreviewResult(
            payload: $payload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }
}

