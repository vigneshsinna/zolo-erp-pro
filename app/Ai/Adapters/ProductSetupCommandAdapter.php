<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Product;
use App\Services\Commercial\CommercialPermission;
use App\Services\ERP\ProductMasterService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // 1. Assert action-specific permission
        app(CommercialPermission::class)->assert('products-add', $context, $actorId);

        // 2. Idempotency recovery
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $code = (string) ($payload['code'] ?? '');
        $unitId = !empty($payload['unit_id']) ? (int) $payload['unit_id'] : null;
        $categoryId = !empty($payload['category_id']) ? (int) $payload['category_id'] : null;
        $taxCategoryId = !empty($payload['tax_category_id']) ? (int) $payload['tax_category_id'] : null;

        // 3. Enforce source-state hash to guarantee underlying ERP state has not drifted
        $currentSourceStateHash = ProductSetupPreviewAdapter::computeSourceStateHash(
            $context,
            $code,
            $unitId,
            $categoryId,
            $taxCategoryId,
        );

        if (!hash_equals($expectedSourceStateHash, $currentSourceStateHash)) {
            throw ValidationException::withMessages([
                'source_state' => 'Underlying ERP business state changed since proposal approval. Rebuild preview.',
            ]);
        }

        // 4. Delegate to authoritative ERP domain service ProductMasterService
        $product = DB::transaction(function () use ($context, $payload, $unitId, $categoryId, $taxCategoryId) {
            $existingProduct = Product::forCompany($context)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($payload['code'])])
                ->where('is_active', true)
                ->first();

            if ($existingProduct) {
                return $existingProduct;
            }

            $resolvedUnitId = $unitId;
            if (!$resolvedUnitId) {
                $resolvedUnitId = \App\Models\Unit::where('company_id', $context->companyId)->where('is_active', true)->value('id');
            }
            if (!$resolvedUnitId) {
                throw ValidationException::withMessages(['unit_id' => 'No active units configured for this company.']);
            }

            $symbology = strtoupper((string) ($payload['barcode_symbology'] ?? 'C128'));
            if ($symbology === 'CODE128') {
                $symbology = 'C128';
            }

            $attributes = [
                'name' => $payload['name'],
                'code' => $payload['code'],
                'type' => $payload['type'] ?? 'standard',
                'barcode_symbology' => in_array($symbology, ['C128', 'C39', 'UPCA', 'UPCE', 'EAN8', 'EAN13'], true) ? $symbology : 'C128',
                'unit_id' => $resolvedUnitId,
                'purchase_unit_id' => !empty($payload['purchase_unit_id']) ? (int) $payload['purchase_unit_id'] : $resolvedUnitId,
                'sale_unit_id' => !empty($payload['sale_unit_id']) ? (int) $payload['sale_unit_id'] : $resolvedUnitId,
                'category_id' => $categoryId,
                'cost' => $payload['cost'] ?? '0.00',
                'price' => $payload['price'] ?? '0.00',
            ];

            $hsnCode = !empty($payload['hsn_code']) ? trim((string) $payload['hsn_code']) : null;
            if (!$hsnCode && \Illuminate\Support\Facades\Schema::hasTable('hsn_sac_codes')) {
                $hsnCode = DB::table('hsn_sac_codes')
                    ->where('company_id', $context->companyId)
                    ->where('kind', 'hsn')
                    ->value('code');
            }
            if (!$hsnCode && \Illuminate\Support\Facades\Schema::hasTable('products')) {
                $hsnCode = DB::table('products')
                    ->where('company_id', $context->companyId)
                    ->whereNotNull('hsn_code')
                    ->where('hsn_code', '!=', '')
                    ->value('hsn_code');
            }

            if ($hsnCode !== null && preg_match('/^[0-9]{4}(?:[0-9]{2})?(?:[0-9]{2})?$/', $hsnCode) && \Illuminate\Support\Facades\Schema::hasTable('hsn_sac_codes')) {
                DB::table('hsn_sac_codes')->updateOrInsert(
                    [
                        'company_id' => $context->companyId,
                        'code' => $hsnCode,
                        'kind' => 'hsn',
                    ],
                    [
                        'description' => $payload['name'] ?? ('Goods HSN ' . $hsnCode),
                        'updated_at' => now(),
                    ]
                );
            }

            $metadata = [
                'tax_category_id' => $taxCategoryId,
                'hsn_code' => $hsnCode,
                'negative_stock_policy' => $payload['negative_stock_policy'] ?? 'warn',
            ];

            return app(ProductMasterService::class)->create($attributes, $metadata, $context);
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
