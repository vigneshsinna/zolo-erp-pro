<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CreateGoodsReceivedNoteInputSchema;
use App\Models\GoodsReceivedNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\OrderLifecycleService;
use Illuminate\Support\Facades\Validator;

final class GoodsReceivedNoteRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'grn.create.v1';
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
                helpText: 'Select an active supplier for this goods receipt note.',
                resolutionPolicy: 'exact_or_candidates',
            ),
            new ActionFieldRequirement(
                fieldPath: 'warehouse_id',
                label: 'Receiving Warehouse',
                dataType: 'entity_reference',
                entityType: 'warehouse',
                inputControl: 'entity_select',
                helpText: 'Receiving warehouse where inventory will be stocked.',
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
                fieldPath: 'grn_date',
                label: 'Receipt Date',
                dataType: 'date',
                inputControl: 'date',
                helpText: 'Date when goods were received.',
                defaultPolicy: fn (): string => now()->toDateString(),
            ),
            new ActionFieldRequirement(
                fieldPath: 'purchase_order_id',
                label: 'Purchase Order Reference',
                dataType: 'entity_reference',
                entityType: 'purchase_order',
                inputControl: 'entity_select',
                helpText: 'Optional purchase order being fulfilled.',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'transport_name',
                label: 'Transporter Name',
                dataType: 'string',
                inputControl: 'text',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'lr_no',
                label: 'Lorry Receipt / Docket No',
                dataType: 'string',
                inputControl: 'text',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'lr_date',
                label: 'LR Date',
                dataType: 'date',
                inputControl: 'date',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'remarks',
                label: 'Remarks / Notes',
                dataType: 'string',
                inputControl: 'text',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'items',
                label: 'Received Items',
                dataType: 'list',
                isList: true,
                itemRequirements: [
                    new ActionFieldRequirement(
                        fieldPath: 'product_id',
                        label: 'Product',
                        dataType: 'entity_reference',
                        entityType: 'product',
                        inputControl: 'entity_select',
                        helpText: 'Stock item received.',
                        resolutionPolicy: 'exact_or_candidates',
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'qty',
                        label: 'Received Quantity',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Actual physical quantity received (must be greater than zero).',
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'cost',
                        label: 'Unit Cost',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Unit valuation cost.',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'unit_id',
                        label: 'Unit of Measure',
                        dataType: 'entity_reference',
                        entityType: 'unit',
                        inputControl: 'entity_select',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'batch_no',
                        label: 'Batch Number',
                        dataType: 'string',
                        inputControl: 'text',
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'order_item_id',
                        label: 'Order Line Link',
                        dataType: 'integer',
                        inputControl: 'hidden',
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'remarks',
                        label: 'Line Remark',
                        dataType: 'string',
                        inputControl: 'text',
                        requiredWhen: fn (): bool => false,
                    ),
                ],
            ),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $schema = new CreateGoodsReceivedNoteInputSchema();
        $validator = Validator::make($resolvedInputs, $schema->rules());

        $errors = [];
        if ($validator->fails()) {
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[$field] = $messages[0] ?? 'Invalid value.';
            }
        }

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

        $poId = (int) ($resolvedInputs['purchase_order_id'] ?? 0);
        $po = null;
        if ($poId > 0) {
            $po = Purchase::where('company_id', $companyId)->find($poId);
            if (!$po) {
                $errors['purchase_order_id'] = "Purchase Order ID {$poId} not found under current company.";
            } elseif ((int) $po->status !== 4) {
                $errors['purchase_order_id'] = "Referenced purchase is not an open purchase order (status: {$po->status}).";
            } elseif ($supplierId > 0 && (int) $po->supplier_id !== $supplierId) {
                $errors['purchase_order_id'] = "Purchase Order belongs to a different supplier.";
            } elseif ($warehouseId > 0 && (int) $po->warehouse_id !== $warehouseId) {
                $errors['purchase_order_id'] = "Purchase Order destination warehouse differs from the receiving warehouse.";
            }
        }

        if (!empty($resolvedInputs['items']) && is_array($resolvedInputs['items'])) {
            $openQuantities = [];
            if ($po && $context->context) {
                try {
                    $openData = app(OrderLifecycleService::class)->remaining('purchase', $po->id, $context->context);
                    foreach ($openData['items'] as $item) {
                        $openQuantities[(int) $item['product_id']] = (float) ($item['remaining_delivery_qty'] ?? 0);
                    }
                } catch (\Throwable) {
                    // Fall back if order items not migrated
                }
            }

            foreach ($resolvedInputs['items'] as $idx => $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                if ($productId > 0) {
                    $product = Product::where('company_id', $companyId)->find($productId);
                    if (!$product) {
                        $errors["items.{$idx}.product_id"] = "Product ID {$productId} does not belong to the current company.";
                    } elseif (isset($product->is_active) && !$product->is_active) {
                        $errors["items.{$idx}.product_id"] = "Product '{$product->name}' is inactive.";
                    } elseif ($product->type !== 'standard') {
                        $errors["items.{$idx}.product_id"] = "A GRN receives stock items only; service/digital product '{$product->name}' must be billed directly.";
                    }

                    if ($po && isset($openQuantities[$productId])) {
                        $qty = (float) ($line['qty'] ?? 0);
                        if ($qty > $openQuantities[$productId] + 0.0001) {
                            $errors["items.{$idx}.qty"] = "Received quantity ({$qty}) exceeds the remaining open delivery quantity ({$openQuantities[$productId]}) on order {$po->reference_no}.";
                        }
                    }
                }
            }
        }

        return $errors;
    }
}

