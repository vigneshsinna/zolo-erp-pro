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

        $sourceStateHash = self::computeSourceStateHash($context, $name, $vatNumber !== '' ? $vatNumber : null);

        return new ActionPreviewResult(
            payload: $payload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }

    public static function computeSourceStateHash(
        CompanyContext $context,
        string $name,
        ?string $vatNumber,
    ): string {
        $existing = Supplier::where('company_id', $context->companyId)
            ->where(function ($q) use ($name, $vatNumber) {
                $q->where('name', $name);
                if (!empty($vatNumber)) {
                    $q->orWhere('vat_number', $vatNumber);
                }
            })
            ->first();

        $taxProfile = $existing ? DB::table('party_tax_profiles')
            ->where('company_id', $context->companyId)
            ->where('party_type', 'supplier')
            ->where('party_id', $existing->id)
            ->first() : null;

        $state = [
            'company_id' => $context->companyId,
            'name' => $name,
            'vat_number' => $vatNumber,
            'existing_id' => $existing?->id,
            'existing_updated_at' => $existing?->updated_at?->toIso8601String(),
            'tax_profile_updated_at' => $taxProfile?->updated_at,
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}

