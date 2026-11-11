<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Product;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;

final class ProductSetupCommandAdapter implements ActionCommandAdapterInterface
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
            throw new \InvalidArgumentException('Mode B company context required for product setup execution.');
        }

        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $product = DB::transaction(function () use ($context, $payload) {
            $row = Product::where('company_id', $context->companyId)
                ->where('code', $payload['code'])
                ->first();

            if (!$row) {
                $unitId = $payload['unit_id'] ?? null;
                if (!$unitId) {
                    $unitId = \App\Models\Unit::where('company_id', $context->companyId)->value('id');
                }
                if (!$unitId) {
                    $unit = \App\Models\Unit::firstOrCreate(
                        ['company_id' => $context->companyId, 'unit_code' => 'PCS'],
                        ['unit_name' => 'Pieces', 'is_active' => true]
                    );
                    $unitId = $unit->id;
                }
                $purchaseUnitId = $payload['purchase_unit_id'] ?? $unitId;
                $saleUnitId = $payload['sale_unit_id'] ?? $unitId;

                $row = Product::forceCreate([
                    'company_id' => $context->companyId,
                    'name' => $payload['name'],
                    'code' => $payload['code'],
                    'type' => $payload['type'] ?? 'standard',
                    'barcode_symbology' => $payload['barcode_symbology'] ?? 'code128',
                    'unit_id' => $unitId,
                    'purchase_unit_id' => $purchaseUnitId,
                    'sale_unit_id' => $saleUnitId,
                    'category_id' => $payload['category_id'] ?? null,
                    'cost' => $payload['cost'] ?? '0.00',
                    'price' => $payload['price'] ?? '0.00',
                    'tax_category_id' => $payload['tax_category_id'] ?? null,
                    'hsn_code' => $payload['hsn_code'] ?? null,
                    'qty' => 0,
                    'is_active' => true,
                ]);
            }

            return $row;
        });

        return new ActionExecutionReceipt(
            resourceType: 'product',
            resourceId: (int) $product->id,
            status: 'completed',
            details: [
                'company_id' => $context->companyId,
                'product_id' => (int) $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'cost' => sprintf('%.2f', (float) $product->cost),
                'price' => sprintf('%.2f', (float) $product->price),
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
            resourceType: (string) ($row->erp_resource_type ?? 'product'),
            resourceId: (int) ($row->erp_resource_id ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}
