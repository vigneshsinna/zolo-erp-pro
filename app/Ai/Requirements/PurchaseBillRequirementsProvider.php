<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CreatePurchaseBillInputSchema;
use App\Models\GoodsReceivedNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\MaterialDocumentService;
use App\Services\Commercial\OrderLifecycleService;
use Illuminate\Support\Facades\Validator;

final class PurchaseBillRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'purchase_bill.create.v1';
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
                helpText: 'Select the billing supplier.',
                resolutionPolicy: 'exact_or_candidates',
            ),
            new ActionFieldRequirement(
                fieldPath: 'warehouse_id',
                label: 'Receiving Warehouse',
                dataType: 'entity_reference',
                entityType: 'warehouse',
                inputControl: 'entity_select',
                helpText: 'Receiving warehouse for stock items.',
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
                label: 'Bill Date',
                dataType: 'date',
                inputControl: 'date',
                helpText: 'Invoice date of the supplier bill.',
                defaultPolicy: fn (): string => now()->toDateString(),
            ),
            new ActionFieldRequirement(
                fieldPath: 'supplier_invoice_no',
                label: 'Supplier Invoice Number',
                dataType: 'string',
                inputControl: 'text',
                helpText: 'Vendor bill or invoice reference number.',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'purchase_order_id',
                label: 'Purchase Order Reference',
                dataType: 'entity_reference',
                entityType: 'purchase_order',
                inputControl: 'entity_select',
                helpText: 'Purchase order linked to this bill.',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'grn_id',
                label: 'Goods Received Note Reference',
                dataType: 'entity_reference',
                entityType: 'grn',
                inputControl: 'entity_select',
                helpText: 'Goods received note settling inventory for this bill.',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'due_date',
                label: 'Payment Due Date',
                dataType: 'date',
                inputControl: 'date',
                helpText: 'Credit payment due date.',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'items',
                label: 'Billed Line Items',
                dataType: 'list',
                isList: true,
                itemRequirements: [
                    new ActionFieldRequirement(
                        fieldPath: 'product_id',
                        label: 'Product',
                        dataType: 'entity_reference',
                        entityType: 'product',
                        inputControl: 'entity_select',
                        helpText: 'Product or service item billed.',
                        resolutionPolicy: 'exact_or_candidates',
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'qty',
                        label: 'Quantity',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Quantity billed (must be greater than zero).',
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'net_unit_cost',
                        label: 'Unit Cost',
                        dataType: 'decimal_string',
                        inputControl: 'number',
                        helpText: 'Agreed unit price or vendor billed cost.',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false,
                    ),
                    new ActionFieldRequirement(
                        fieldPath: 'purchase_unit_id',
                        label: 'Unit of Measure',
                        dataType: 'entity_reference',
                        entityType: 'unit',
                        inputControl: 'entity_select',
                        dependencies: ['product_id'],
                        requiredWhen: fn (): bool => false,
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
                        fieldPath: 'order_item_id',
                        label: 'Order Line Link',
                        dataType: 'integer',
                        inputControl: 'hidden',
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
                label: 'Bill Discount',
                dataType: 'decimal_string',
                inputControl: 'number',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'shipping_cost',
                label: 'Shipping / Freight',
                dataType: 'decimal_string',
                inputControl: 'number',
                requiredWhen: fn (): bool => false,
            ),
            new ActionFieldRequirement(
                fieldPath: 'note',
                label: 'Bill Note',
                dataType: 'string',
                inputControl: 'text',
                requiredWhen: fn (): bool => false,
            ),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $schema = new CreatePurchaseBillInputSchema();
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
            }
        }

        $grnId = (int) ($resolvedInputs['grn_id'] ?? 0);
        $grn = null;
        if ($grnId > 0) {
            $grn = GoodsReceivedNote::where('company_id', $companyId)->find($grnId);
            if (!$grn) {
                $errors['grn_id'] = "Goods Received Note ID {$grnId} not found under current company.";
            } elseif (!$grn->posted_at) {
                $errors['grn_id'] = "Referenced GRN is not yet posted.";
            } elseif ($supplierId > 0 && (int) $grn->supplier_id !== $supplierId) {
                $errors['grn_id'] = "GRN belongs to a different supplier.";
            }
        }

        if (!empty($resolvedInputs['items']) && is_array($resolvedInputs['items'])) {
            $openQuantities = [];
            if ($po && $context->context) {
                try {
                    $openData = app(OrderLifecycleService::class)->remaining('purchase', $po->id, $context->context);
                    foreach ($openData['items'] as $item) {
                        $openQuantities[(int) $item['product_id']] = (float) ($item['remaining_qty'] ?? 0);
                    }
                } catch (\Throwable) {
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
                    }

                    if ($po && isset($openQuantities[$productId])) {
                        $qty = (float) ($line['qty'] ?? 0);
                        if ($qty > $openQuantities[$productId] + 0.0001) {
                            $errors["items.{$idx}.qty"] = "Billed quantity ({$qty}) exceeds the remaining open billing quantity ({$openQuantities[$productId]}) on order {$po->reference_no}.";
                        }
                    }
                }
            }
        }

        return $errors;
    }
}

