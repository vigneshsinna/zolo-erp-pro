<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CreateSalesOrderInputSchema;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Validator;

final class SalesOrderRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'sales_order.create.v1';
    }

    /**
     * @param array<string, mixed> $currentSlots
     * @return list<ActionFieldRequirement>
     */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement(
                fieldPath: 'customer_id',
                label: 'Customer',
                dataType: 'entity_reference',
                entityType: 'customer',
                inputControl: 'entity_select',
                helpText: 'Select an active customer for this sales order.',
                resolutionPolicy: 'exact_or_candidates',
            ),
            new ActionFieldRequirement(
                fieldPath: 'warehouse_id',
                label: 'Dispatch Warehouse',
                dataType: 'entity_reference',
                entityType: 'warehouse',
                inputControl: 'entity_select',
                helpText: 'Dispatch warehouse for order fulfillment.',
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
                helpText: 'Date of the sales order.',
                defaultPolicy: fn (): string => now()->toDateString(),
            ),
            new ActionFieldRequirement(
                fieldPath: 'delivery_date',
                label: 'Requested Delivery Date',
                dataType: 'date',
                inputControl: 'date',
                helpText: 'Promised delivery date for the customer.',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'customer_reference_no',
                label: 'Customer PO / Reference #',
                dataType: 'string',
                inputControl: 'text',
                helpText: 'Customer PO number or reference.',
                requiredWhen: fn (): bool => false,
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
                        helpText: 'Product or item to sell.',
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
                        fieldPath: 'net_unit_price',
                        label: 'Unit Selling Price',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Agreed unit selling price or product master price.',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false, // Can default from product master price
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'sale_unit_id',
                        label: 'Sale Unit of Measure',
                        dataType: 'entity_reference',
                        entityType: 'unit',
                        inputControl: 'entity_select',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false, // Can default from product sale_unit_id
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
        $schema = new CreateSalesOrderInputSchema();
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

        $customerId = (int) ($resolvedInputs['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = Customer::where('company_id', $companyId)->find($customerId);
            if (!$customer) {
                $errors['customer_id'] = 'Customer does not belong to the current company.';
            } elseif (isset($customer->is_active) && !$customer->is_active) {
                $errors['customer_id'] = 'Selected customer is inactive.';
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
