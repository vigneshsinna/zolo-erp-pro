<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Customer;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerSetupPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for customer setup.');
        }

        $name = trim((string) ($slots['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Customer name is required.']);
        }

        $companyName = trim((string) ($slots['company_name'] ?? ''));
        $taxNo = strtoupper(trim((string) ($slots['tax_no'] ?? '')));

        $existing = Customer::where('company_id', $context->companyId)
            ->where(function ($q) use ($name, $companyName, $taxNo) {
                $q->where('name', $name);
                if ($companyName !== '') {
                    $q->orWhere('company_name', $companyName);
                }
                if ($taxNo !== '') {
                    $q->orWhere('tax_no', $taxNo);
                }
            })->first();

        if (!empty($slots['customer_group_id'])) {
            $customerGroupId = (int) $slots['customer_group_id'];
            $groupExists = DB::table('customer_groups')
                ->where('company_id', $context->companyId)
                ->where('id', $customerGroupId)
                ->exists();

            if (!$groupExists) {
                throw ValidationException::withMessages(['customer_group_id' => 'Selected customer group does not belong to the company.']);
            }
        } else {
            $customerGroupId = (int) (DB::table('customer_groups')->where('company_id', $context->companyId)->value('id') ?? 1);
        }

        $payload = [
            'name' => $name,
            'company_name' => $companyName !== '' ? $companyName : null,
            'phone_number' => (string) ($slots['phone_number'] ?? ''),
            'tax_no' => $taxNo !== '' ? $taxNo : null,
            'customer_group_id' => $customerGroupId,
            'state' => (string) ($slots['state'] ?? 'Tamil Nadu'),
            'city' => (string) ($slots['city'] ?? ''),
            'address' => (string) ($slots['address'] ?? ''),
            'email' => !empty($slots['email']) ? (string) $slots['email'] : null,
        ];

        $previewData = [
            'entity' => 'Customer Directory',
            'name' => $name,
            'company_name' => $companyName,
            'tax_no' => $taxNo,
            'customer_group_id' => $customerGroupId,
            'state' => $payload['state'],
            'is_existing' => $existing !== null,
        ];

        $sourceStateHash = self::computeSourceStateHash($context, $name, $customerGroupId, $taxNo !== '' ? $taxNo : null);

        return new ActionPreviewResult(
            payload: $payload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }

    public static function computeSourceStateHash(
        CompanyContext $context,
        string $name,
        ?int $customerGroupId,
        ?string $taxNo,
    ): string {
        $existing = Customer::where('company_id', $context->companyId)
            ->where(function ($q) use ($name, $taxNo) {
                $q->where('name', $name);
                if (!empty($taxNo)) {
                    $q->orWhere('tax_no', $taxNo);
                }
            })
            ->first();

        $group = $customerGroupId ? DB::table('customer_groups')->where('company_id', $context->companyId)->where('id', $customerGroupId)->first() : null;
        $taxProfile = $existing ? DB::table('party_tax_profiles')
            ->where('company_id', $context->companyId)
            ->where('party_type', 'customer')
            ->where('party_id', $existing->id)
            ->first() : null;

        $state = [
            'company_id' => $context->companyId,
            'name' => $name,
            'tax_no' => $taxNo,
            'customer_group_id' => $customerGroupId,
            'customer_group_updated_at' => $group?->updated_at,
            'existing_id' => $existing?->id,
            'existing_updated_at' => $existing?->updated_at?->toIso8601String(),
            'tax_profile_updated_at' => $taxProfile?->updated_at,
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}

