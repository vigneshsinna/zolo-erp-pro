<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Customer;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;

final class CustomerSetupCommandAdapter implements ActionCommandAdapterInterface
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
            throw new \InvalidArgumentException('Mode B company context required for customer setup execution.');
        }

        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $customer = DB::transaction(function () use ($context, $payload) {
            $row = Customer::where('company_id', $context->companyId)
                ->where('name', $payload['name'])
                ->first();

            if (!$row) {
                $row = Customer::create([
                    'company_id' => $context->companyId,
                    'customer_group_id' => $payload['customer_group_id'] ?? 1,
                    'name' => $payload['name'],
                    'company_name' => $payload['company_name'] ?? null,
                    'phone_number' => $payload['phone_number'] ?? null,
                    'tax_no' => $payload['tax_no'] ?? null,
                    'state' => $payload['state'] ?? null,
                    'city' => $payload['city'] ?? null,
                    'address' => $payload['address'] ?? null,
                    'email' => $payload['email'] ?? null,
                    'is_active' => true,
                ]);

                if (!empty($payload['tax_no'])) {
                    DB::table('party_tax_profiles')->updateOrInsert(
                        [
                            'company_id' => $context->companyId,
                            'party_type' => 'customer',
                            'party_id' => $row->id,
                        ],
                        [
                            'gstin' => $payload['tax_no'],
                            'state_code' => substr($payload['tax_no'], 0, 2),
                            'registration_type' => 'regular',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            return $row;
        });

        return new ActionExecutionReceipt(
            resourceType: 'customer',
            resourceId: (int) $customer->id,
            status: 'completed',
            details: [
                'company_id' => $context->companyId,
                'customer_id' => (int) $customer->id,
                'name' => $customer->name,
                'tax_no' => $customer->tax_no,
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
            resourceType: (string) ($row->erp_resource_type ?? 'customer'),
            resourceId: (int) ($row->erp_resource_id ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}

