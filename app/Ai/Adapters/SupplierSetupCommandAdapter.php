<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Supplier;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierSetupCommandAdapter implements ActionCommandAdapterInterface
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
            throw new \InvalidArgumentException('Mode B company context required for supplier setup execution.');
        }

        // 1. Assert action-specific Spatie permission
        app(CommercialPermission::class)->assert('suppliers-add', $context, $actorId);

        // 2. Idempotency recovery
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $name = (string) ($payload['name'] ?? '');
        $vatNumber = !empty($payload['vat_number']) ? (string) $payload['vat_number'] : null;

        // 3. Enforce source-state hash
        $currentSourceStateHash = SupplierSetupPreviewAdapter::computeSourceStateHash($context, $name, $vatNumber);
        if (!hash_equals($expectedSourceStateHash, $currentSourceStateHash)) {
            throw ValidationException::withMessages([
                'source_state' => 'Underlying ERP business state changed since proposal approval. Rebuild preview.',
            ]);
        }

        $supplier = DB::transaction(function () use ($context, $payload) {
            $row = Supplier::where('company_id', $context->companyId)
                ->where('name', $payload['name'])
                ->first();

            if (!$row) {
                $row = Supplier::create([
                    'company_id' => $context->companyId,
                    'name' => $payload['name'],
                    'company_name' => $payload['company_name'] ?? null,
                    'phone_number' => $payload['phone_number'] ?? null,
                    'vat_number' => $payload['vat_number'] ?? null,
                    'state' => $payload['state'] ?? null,
                    'city' => $payload['city'] ?? null,
                    'address' => $payload['address'] ?? null,
                    'email' => $payload['email'] ?? null,
                    'is_active' => true,
                ]);

                if (!empty($payload['vat_number'])) {
                    DB::table('party_tax_profiles')->updateOrInsert(
                        [
                            'company_id' => $context->companyId,
                            'party_type' => 'supplier',
                            'party_id' => $row->id,
                        ],
                        [
                            'gstin' => $payload['vat_number'],
                            'state_code' => substr($payload['vat_number'], 0, 2),
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
            resourceType: 'supplier',
            resourceId: (int) $supplier->id,
            status: 'completed',
            details: [
                'company_id' => $context->companyId,
                'supplier_id' => (int) $supplier->id,
                'name' => $supplier->name,
                'vat_number' => $supplier->vat_number,
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
            resourceType: (string) ($row->erp_resource_type ?? 'supplier'),
            resourceId: (int) ($row->erp_resource_id ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}

