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

        $customerGroupId = !empty($slots['customer_group_id'])
            ? (int) $slots['customer_group_id']
            : (int) (DB::table('customer_groups')->where('company_id', $context->companyId)->value('id') ?? 1);

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

        $sourceStateHash = hash('sha256', 'CUSTOMER_SETUP:' . $context->companyId . ':' . $name . ':' . ($existing?->id ?? 'NEW'));

        return new ActionPreviewResult(
            payload: $payload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }
}

