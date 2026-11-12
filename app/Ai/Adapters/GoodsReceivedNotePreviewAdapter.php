<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preview adapter for grn.create.v1 (Goods Received Note).
 * Calculates physical inventory intake, valuation cost, and accrual projection.
 * Strictly READ-ONLY with ZERO database mutations.
 */
final class GoodsReceivedNotePreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for GRN preview.');
        }

        $supplierId = (int) ($slots['supplier_id'] ?? 0);
        $warehouseId = (int) ($slots['warehouse_id'] ?? 0);
        $grnDate = !empty($slots['grn_date']) ? (string) $slots['grn_date'] : now()->toDateString();
        $poId = (int) ($slots['purchase_order_id'] ?? 0);

        // 1. Supplier validation
        $supplier = Supplier::where('company_id', $context->companyId)
            ->whereKey($supplierId)
            ->first();

        if (!$supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier not found under current company.']);
        }
        if (isset($supplier->is_active) && !$supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Selected supplier is inactive.']);
        }

        // 2. Warehouse validation
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

        // 3. Purchase Order verification (if linked)
        $po = null;
        $openQuantities = [];
        if ($poId > 0) {
            $po = Purchase::where('company_id', $context->companyId)->whereKey($poId)->first();
            if (!$po) {
                throw ValidationException::withMessages(['purchase_order_id' => "Purchase Order ID {$poId} not found."]);
            }
            if ((int) $po->status !== 4) {
                throw ValidationException::withMessages(['purchase_order_id' => "Purchase Order is not open (status: {$po->status})."]);
            }
            if ((int) $po->supplier_id !== $supplierId) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Purchase Order supplier differs from GRN supplier.']);
            }
            if ((int) $po->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Purchase Order destination warehouse differs from GRN receiving warehouse.']);
            }

            try {
                $openData = app(OrderLifecycleService::class)->remaining('purchase', $po->id, $context);
                foreach ($openData['items'] as $item) {
                    $openQuantities[(int) $item['product_id']] = (float) ($item['remaining_delivery_qty'] ?? 0);
                }
            } catch (\Throwable) {
            }
        }

        // 4. Products & line calculation
        $rawItems = (array) ($slots['items'] ?? []);
        if (empty($rawItems)) {
            throw ValidationException::withMessages(['items' => 'At least one line item is required.']);
        }

        $productIds = [];
        $detailedPreviewLines = [];
        $normalizedLines = [];
        $lineTotals = [];
        $totalQty = 0.0;
        $totalCost = 0.0;

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
            if ($product->type !== 'standard') {
                throw ValidationException::withMessages(["items.{$index}.product_id" => "Product '{$product->name}' is {$product->type}; GRN receives stock items only."]);
            }

            $productIds[] = $productId;
            $qty = (float) ($item['qty'] ?? 1);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.{$index}.qty" => 'Quantity must be positive.']);
            }

            if ($po && isset($openQuantities[$productId]) && $qty > $openQuantities[$productId] + 0.0001) {
                throw ValidationException::withMessages(["items.{$index}.qty" => "Received quantity ({$qty}) exceeds the remaining open delivery quantity ({$openQuantities[$productId]}) on order {$po->reference_no}."]);
            }

            $unitCost = isset($item['cost']) ? (float) $item['cost'] : (float) ($product->cost ?? 0);
            $lineTotal = round($qty * $unitCost, 2);
            $unitId = (int) ($item['unit_id'] ?? $product->purchase_unit_id ?? $product->unit_id ?? 1);

            $totalQty += $qty;
            $totalCost += $lineTotal;

            $normalizedLines[] = [
                'product_id' => $productId,
                'qty' => sprintf('%.2f', $qty),
                'cost' => sprintf('%.2f', $unitCost),
                'unit_id' => $unitId,
                'order_item_id' => isset($item['order_item_id']) ? (int) $item['order_item_id'] : null,
                'batch_no' => $item['batch_no'] ?? null,
                'remarks' => $item['remarks'] ?? null,
            ];

            $detailedPreviewLines[] = [
                'line_number' => $index + 1,
                'product_id' => $productId,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'qty' => sprintf('%.2f', $qty),
                'cost' => sprintf('%.2f', $unitCost),
                'line_total' => sprintf('%.2f', $lineTotal),
                'unit_id' => $unitId,
                'batch_no' => $item['batch_no'] ?? null,
            ];

            $lineTotals[] = [
                'product_id' => $productId,
                'qty' => sprintf('%.2f', $qty),
                'cost' => sprintf('%.2f', $unitCost),
                'line_total' => sprintf('%.2f', $lineTotal),
            ];
        }

        $grandTotal = round($totalCost, 2);

        $normalizedPayload = [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'grn_date' => $grnDate,
            'purchase_order_id' => $poId > 0 ? $poId : null,
            'transport_name' => $slots['transport_name'] ?? null,
            'lr_no' => $slots['lr_no'] ?? null,
            'lr_date' => $slots['lr_date'] ?? null,
            'remarks' => $slots['remarks'] ?? null,
            'items' => $normalizedLines,
            'expected_totals' => [
                'total_qty' => sprintf('%.2f', $totalQty),
                'total_cost' => sprintf('%.2f', $totalCost),
                'grand_total' => sprintf('%.2f', $grandTotal),
                'line_totals' => $lineTotals,
            ],
        ];

        $previewData = [
            'document_type' => 'Goods Received Note (GRN)',
            'supplier' => [
                'id' => (int) $supplier->id,
                'name' => $supplier->name,
                'company_name' => $supplier->company_name ?? null,
            ],
            'warehouse' => [
                'id' => (int) $warehouse->id,
                'name' => $warehouse->name,
                'branch_id' => (int) $warehouse->branch_id,
            ],
            'grn_date' => $grnDate,
            'purchase_order' => $po ? [
                'id' => (int) $po->id,
                'reference_no' => $po->reference_no,
            ] : null,
            'items' => $detailedPreviewLines,
            'totals' => [
                'total_qty' => sprintf('%.2f', $totalQty),
                'total_cost' => sprintf('%.2f', $totalCost),
                'grand_total' => sprintf('%.2f', $grandTotal),
            ],
            'policy' => [
                'status' => 'pending',
                'inventory_impact' => "Stock will be received into warehouse '{$warehouse->name}'.",
                'ledger_impact' => "Dr Inventory (Asset) ₹".sprintf('%.2f', $grandTotal).", Cr Goods Received Not Invoiced (Accrual) ₹".sprintf('%.2f', $grandTotal).".",
                'ap_impact' => 'Zero accounts payable or tax liability will be recorded until the supplier bill is posted.',
            ],
        ];

        $sourceStateHash = self::computeSourceStateHash(
            $context,
            $supplierId,
            $warehouseId,
            array_values(array_unique($productIds)),
            $poId > 0 ? $poId : null,
        );

        return new ActionPreviewResult(
            payload: $normalizedPayload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
            warnings: $warnings,
        );
    }

    public static function computeSourceStateHash(
        CompanyContext $context,
        int $supplierId,
        int $warehouseId,
        array $productIds,
        ?int $poId = null,
    ): string {
        $supplier = DB::table('suppliers')
            ->where('company_id', $context->companyId)
            ->where('id', $supplierId)
            ->select(['id', 'is_active', 'updated_at'])
            ->first();

        $warehouse = DB::table('warehouses')
            ->where('company_id', $context->companyId)
            ->where('id', $warehouseId)
            ->select(['id', 'branch_id', 'is_active', 'updated_at'])
            ->first();

        $fiscalYear = DB::table('fiscal_years')
            ->where('company_id', $context->companyId)
            ->where('id', $context->financialYearId)
            ->select(['id', 'status', 'is_closed', 'lock_date', 'updated_at'])
            ->first();

        $products = DB::table('products')
            ->where('company_id', $context->companyId)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->select(['id', 'cost', 'is_active', 'unit_id', 'purchase_unit_id', 'updated_at'])
            ->get();

        $po = $poId !== null ? DB::table('purchases')
            ->where('company_id', $context->companyId)
            ->where('id', $poId)
            ->select(['id', 'status', 'reversed_at', 'updated_at'])
            ->first() : null;

        $state = [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'financial_year_id' => $context->financialYearId,
            'supplier' => $supplier,
            'warehouse' => $warehouse,
            'fiscal_year' => $fiscalYear,
            'products' => $products->toArray(),
            'purchase_order' => $po,
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
