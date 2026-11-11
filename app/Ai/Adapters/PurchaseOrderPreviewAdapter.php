<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPricing;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Preview adapter for purchase_order.create.v1.
 * Validates supplier, warehouse, products, and calculates deterministic pricing preview.
 * Strictly READ-ONLY with ZERO database mutations.
 */
final class PurchaseOrderPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function __construct(
        private readonly CommercialPricing $pricing,
    ) {}

    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for purchase order preview.');
        }

        $supplierId = (int) ($slots['supplier_id'] ?? 0);
        $warehouseId = (int) ($slots['warehouse_id'] ?? 0);
        $orderDate = !empty($slots['date']) ? (string) $slots['date'] : now()->toDateString();

        // 1. Resolve & validate Supplier
        $supplier = Supplier::where('company_id', $context->companyId)
            ->whereKey($supplierId)
            ->first();

        if (!$supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier not found under current company.']);
        }
        if (isset($supplier->is_active) && !$supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Selected supplier is inactive.']);
        }

        // 2. Resolve & validate Warehouse
        $warehouse = Warehouse::where('company_id', $context->companyId)
            ->whereKey($warehouseId)
            ->first();

        if (!$warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'Warehouse not found under current company.']);
        }
        if (isset($warehouse->is_active) && !$warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => 'Selected warehouse is inactive.']);
        }

        $warnings = [];
        if ($warehouse->branch_id && (int) $warehouse->branch_id !== (int) $context->branchId) {
            $warnings[] = "Warehouse '{$warehouse->name}' belongs to a different branch than the active session branch.";
        }

        // 3. Resolve & validate Products and compute exact-decimal lines
        $rawItems = (array) ($slots['items'] ?? []);
        if (empty($rawItems)) {
            throw ValidationException::withMessages(['items' => 'At least one line item is required.']);
        }

        $normalizedItems = [];
        $detailedPreviewLines = [];
        $totalQty = 0.0;
        $subtotal = 0.0;
        $totalTax = 0.0;

        foreach ($rawItems as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $product = Product::where('company_id', $context->companyId)
                ->whereKey($productId)
                ->first();

            if (!$product) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => "Product ID {$productId} not found."]);
            }
            if (isset($product->is_active) && !$product->is_active) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => "Product '{$product->name}' is inactive."]);
            }

            $qty = (float) ($item['qty'] ?? 1);
            $unitCost = (float) ($item['net_unit_cost'] ?? $product->cost ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            $lineNet = ($qty * $unitCost) - $discount;
            $lineTax = $lineNet * ($taxRate / 100);
            $lineTotal = $lineNet + $lineTax;

            $totalQty += $qty;
            $subtotal += $lineNet;
            $totalTax += $lineTax;

            $qtyStr = sprintf('%.2f', $qty);
            $costStr = sprintf('%.2f', $unitCost);
            $discountStr = sprintf('%.2f', $discount);
            $taxRateStr = sprintf('%.2f', $taxRate);
            $lineTotalStr = sprintf('%.2f', $lineTotal);

            $normalizedItems[] = [
                'product_id' => $productId,
                'qty' => $qtyStr,
                'net_unit_cost' => $costStr,
                'discount' => $discountStr,
                'tax_rate' => $taxRateStr,
                'purchase_unit_id' => (int) ($item['purchase_unit_id'] ?? $product->purchase_unit_id ?? $product->unit_id ?? 1),
                'note' => isset($item['note']) ? (string) $item['note'] : null,
            ];

            $detailedPreviewLines[] = [
                'product_id' => $productId,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'qty' => $qtyStr,
                'net_unit_cost' => $costStr,
                'discount' => $discountStr,
                'tax_rate' => $taxRateStr,
                'tax_amount' => sprintf('%.2f', $lineTax),
                'line_total' => $lineTotalStr,
            ];
        }

        $orderTaxRate = (float) ($slots['order_tax_rate'] ?? 0);
        $orderDiscount = (float) ($slots['order_discount'] ?? 0);
        $shippingCost = (float) ($slots['shipping_cost'] ?? 0);

        $orderTax = ($subtotal - $orderDiscount) * ($orderTaxRate / 100);
        $grandTotal = $subtotal - $orderDiscount + $totalTax + $orderTax + $shippingCost;

        $normalizedPayload = [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'date' => $orderDate,
            'items' => $normalizedItems,
            'note' => isset($slots['note']) ? (string) $slots['note'] : null,
        ];

        if ($orderTaxRate > 0) {
            $normalizedPayload['order_tax_rate'] = sprintf('%.2f', $orderTaxRate);
        }
        if ($orderDiscount > 0) {
            $normalizedPayload['order_discount'] = sprintf('%.2f', $orderDiscount);
        }
        if ($shippingCost > 0) {
            $normalizedPayload['shipping_cost'] = sprintf('%.2f', $shippingCost);
        }

        $previewData = [
            'document_type' => 'Purchase Order (Status: Ordered)',
            'supplier' => [
                'id' => (int) $supplier->id,
                'name' => $supplier->name,
                'company_name' => $supplier->company_name ?? null,
                'phone' => $supplier->phone_number ?? null,
                'gst_number' => $supplier->vat_number ?? $supplier->gst_number ?? null,
            ],
            'warehouse' => [
                'id' => (int) $warehouse->id,
                'name' => $warehouse->name,
                'branch_id' => (int) $warehouse->branch_id,
            ],
            'date' => $orderDate,
            'items' => $detailedPreviewLines,
            'totals' => [
                'total_qty' => sprintf('%.2f', $totalQty),
                'subtotal' => sprintf('%.2f', $subtotal),
                'tax_total' => sprintf('%.2f', $totalTax + $orderTax),
                'discount_total' => sprintf('%.2f', $orderDiscount),
                'shipping_cost' => sprintf('%.2f', $shippingCost),
                'grand_total' => sprintf('%.2f', $grandTotal),
            ],
            'policy' => [
                'status' => 'ordered',
                'commercial_register' => 'order_register',
                'ledger_impact' => 'none_until_billed',
                'inventory_impact' => 'open_order_allocations',
            ],
        ];

        // For new records, source_state_hash is hash of NEW_RECORD
        $sourceStateHash = hash('sha256', 'NEW_RECORD');

        return new ActionPreviewResult(
            payload: $normalizedPayload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
            warnings: $warnings,
        );
    }
}
