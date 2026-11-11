<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Ai\Schemas\CreatePurchaseOrderInputSchema;
use Illuminate\Support\Facades\Validator;

final class PurchaseOrderRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'purchase_order.create.v1';
    }

    /**
     * @param array<string, mixed> $currentSlots
     * @return list<ActionFieldRequirement>
     */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement(
                fieldPath: 'supplier_id',
                label: 'Supplier',
                dataType: 'entity_reference',
                entityType: 'supplier',
                inputControl: 'entity_select',
                helpText: 'Select an active supplier for this purchase order.',
                resolutionPolicy: 'exact_or_candidates',
            ),
            new ActionFieldRequirement(
                fieldPath: 'warehouse_id',
                label: 'Destination Warehouse',
                dataType: 'entity_reference',
                entityType: 'warehouse',
                inputControl: 'entity_select',
                helpText: 'Destination warehouse for order fulfillment.',
                resolutionPolicy: 'exact_or_candidates',
                defaultPolicy: function (WorkflowTaskContext $ctx): ?int {
                    if (!$ctx->companyId()) {
                        return null;
                    }
                    $wh = Warehouse::where('company_id', $ctx->companyId())
                        ->where('is_active', true)
                        ->when($ctx->branchId(), fn ($q) => $q->where('branch_id', $ctx->branchId()))
                        ->first();
                    return $wh ? (int) $wh->id : null;
                },
            ),
            new ActionFieldRequirement(
                fieldPath: 'date',
                label: 'Order Date',
                dataType: 'date',
                inputControl: 'date',
                helpText: 'Date of the purchase order.',
                defaultPolicy: fn (): string => now()->toDateString(),
            ),
            new ActionFieldRequirement(
                fieldPath: 'items',
                label: 'Line Items',
                dataType: 'list',
                isList: true,
                itemRequirements: [
                    new ActionFieldRequirement(
                        fieldPath: 'product_id',
                        label: 'Product',
                        dataType: 'entity_reference',
                        entityType: 'product',
                        inputControl: 'entity_select',
                        helpText: 'Product or item to purchase.',
                        resolutionPolicy: 'exact_or_candidates',
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'qty',
                        label: 'Quantity',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Quantity to order (must be greater than zero).',
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'net_unit_cost',
                        label: 'Unit Cost',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Agreed unit price or product master cost.',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false, // Can default from product master
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'purchase_unit_id',
                        label: 'Unit of Measure',
                        dataType: 'entity_reference',
                        entityType: 'unit',
                        inputControl: 'entity_select',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false, // Can default from product purchase_unit_id
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'discount',
                        label: 'Line Discount',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'tax_rate',
                        label: 'Tax Rate Override',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'note',
                        label: 'Line Note',
                        dataType: 'string',
                        inputControl: 'text',
                        requiredWhen: fn (): bool => false,
                    ),
                ],
            ),
            new ActionFieldRequirement(
                fieldPath: 'order_discount',
                label: 'Order Discount',
                dataType: 'decimal_string',
                inputControl: 'number',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'shipping_cost',
                label: 'Shipping / Freight Cost',
                dataType: 'decimal_string',
                inputControl: 'number',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'note',
                label: 'Order Note / Reference',
                dataType: 'string',
                inputControl: 'text',
                requiredWhen: fn (): bool => false,
            ),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $schema = new CreatePurchaseOrderInputSchema();
        $validator = Validator::make($resolvedInputs, $schema->rules());

        $errors = [];
        if ($validator->fails()) {
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[$field] = $messages[0] ?? 'Invalid value.';
            }
        }

        // Domain checks
        $companyId = $context->companyId();
        if (!$companyId) {
            $errors['company_id'] = 'Company context is missing.';
            return $errors;
        }

        $supplierId = (int) ($resolvedInputs['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $supplier = Supplier::where('company_id', $companyId)->find($supplierId);
            if (!$supplier) {
                $errors['supplier_id'] = 'Supplier does not belong to the current company.';
            } elseif (isset($supplier->is_active) && !$supplier->is_active) {
                $errors['supplier_id'] = 'Selected supplier is inactive.';
            }
        }

        $warehouseId = (int) ($resolvedInputs['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $warehouse = Warehouse::where('company_id', $companyId)->find($warehouseId);
            if (!$warehouse) {
                $errors['warehouse_id'] = 'Warehouse does not belong to the current company.';
            } elseif (isset($warehouse->is_active) && !$warehouse->is_active) {
                $errors['warehouse_id'] = 'Selected warehouse is inactive.';
            }
        }

        if (!empty($resolvedInputs['items']) && is_array($resolvedInputs['items'])) {
            foreach ($resolvedInputs['items'] as $idx => $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                if ($productId > 0) {
                    $product = Product::where('company_id', $companyId)->find($productId);
                    if (!$product) {
                        $errors["items.{$idx}.product_id"] = "Product ID {$productId} does not belong to the current company.";
                    } elseif (isset($product->is_active) && !$product->is_active) {
                        $errors["items.{$idx}.product_id"] = "Product '{$product->name}' is inactive.";
                    }
                }
            }
        }

        return $errors;
    }
}
