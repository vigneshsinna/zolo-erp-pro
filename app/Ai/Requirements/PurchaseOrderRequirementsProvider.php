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
        $supplierDays = function (WorkflowTaskContext $ctx, array $slots): ?int {
            $supplier = Supplier::where('company_id', $ctx->companyId())->where('is_active', true)
                ->whereKey($slots['supplier_id'] ?? 0)->first();
            $days = $supplier?->credit_days;
            return is_numeric($days) && (int) $days >= 0 && (int) $days <= 3650
                && ((int) $days > 0 || ($supplier?->purchase_defaults['payment_mode'] ?? '') === 'Credit') ? (int) $days : null;
        };
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
                    $query = Warehouse::where('company_id', $ctx->companyId())
                        ->where('is_active', true)
                        ->when($ctx->branchId(), fn($q) => $q->where('branch_id', $ctx->branchId()));
                    if ($query->count() === 1) {
                        return (int) $query->value('id');
                    }
                    return null;
                },
            ),
            new ActionFieldRequirement(
                fieldPath: 'date',
                label: 'Order Date',
                dataType: 'date',
                inputControl: 'date',
                helpText: 'Date of the purchase order.',
                defaultPolicy: fn(): string => now()->toDateString(),
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
                        requiredWhen: fn(): bool => false, // Can default from product master
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'purchase_unit_id',
                        label: 'Unit of Measure',
                        dataType: 'entity_reference',
                        entityType: 'unit',
                        inputControl: 'entity_select',
                        dependencies: ['product_id'],
                        requiredWhen: fn(): bool => false, // Can default from product purchase_unit_id
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'discount',
                        label: 'Line Discount',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        requiredWhen: fn(): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'tax_rate',
                        label: 'Tax Rate Override',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        requiredWhen: fn(): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'note',
                        label: 'Line Note',
                        dataType: 'string',
                        inputControl: 'text',
                        requiredWhen: fn(): bool => false,
                    ),
                ],
            ),
            new ActionFieldRequirement(fieldPath: 'payment_terms_type', label: 'Cash or Credit', inputControl: 'select',
                dependencies: ['supplier_id'], helpText: 'Commercial terms only. No payment is posted.',
                requiredWhen: fn (): bool => (bool) config('erp_ai.conversation_enabled', false),
                defaultPolicy: function (WorkflowTaskContext $ctx, array $slots) use ($supplierDays): ?string {
                    $supplier = Supplier::where('company_id', $ctx->companyId())->where('is_active', true)->whereKey($slots['supplier_id'] ?? 0)->first();
                    $mode = $supplier?->purchase_defaults['payment_mode'] ?? null;
                    return in_array($mode, ['Cash', 'Credit'], true) ? strtolower($mode) : ($supplierDays($ctx, $slots) !== null ? 'credit' : null);
                }),
            new ActionFieldRequirement(fieldPath: 'credit_days', label: 'Credit Days', dataType: 'integer', inputControl: 'number',
                dependencies: ['supplier_id', 'payment_terms_type'],
                requiredWhen: fn (array $slots): bool => ($slots['payment_terms_type'] ?? '') === 'credit',
                defaultPolicy: fn (WorkflowTaskContext $ctx, array $slots) => ($slots['payment_terms_type'] ?? '') === 'cash' ? 0 : $supplierDays($ctx, $slots)),
            new ActionFieldRequirement(
                fieldPath: 'order_discount',
                label: 'Order Discount',
                dataType: 'decimal_string',
                inputControl: 'number',
                requiredWhen: fn(): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'shipping_cost',
                label: 'Shipping / Freight Cost',
                dataType: 'decimal_string',
                inputControl: 'number',
                requiredWhen: fn(): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'note',
                label: 'Order Note / Reference',
                dataType: 'string',
                inputControl: 'text',
                requiredWhen: fn(): bool => false,
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
        if (($resolvedInputs['payment_terms_type'] ?? null) === 'cash' && (int) ($resolvedInputs['credit_days'] ?? 0) !== 0) {
            $errors['credit_days'] = 'Cash terms require zero credit days.';
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
