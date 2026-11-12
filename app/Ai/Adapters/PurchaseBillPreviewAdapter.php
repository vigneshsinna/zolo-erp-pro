<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\GoodsReceivedNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPricing;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\TaxDeterminationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preview adapter for purchase_bill.create.v1 (Purchase Bill / Supplier Invoice).
 * Computes authoritative pricing, GST splits, and Accounts Payable liabilities.
 * Strictly READ-ONLY with ZERO database mutations.
 */
final class PurchaseBillPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function __construct(
        private readonly CommercialPricing $pricing,
        private readonly TaxDeterminationService $taxService,
    ) {
    }

    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for purchase bill preview.');
        }

        $supplierId = (int) ($slots['supplier_id'] ?? 0);
        $warehouseId = (int) ($slots['warehouse_id'] ?? 0);
        $billDate = !empty($slots['date']) ? (string) $slots['date'] : now()->toDateString();
        $poId = (int) ($slots['purchase_order_id'] ?? 0);
        $grnId = (int) ($slots['grn_id'] ?? 0);

        // 1. Resolve Supplier
        $supplier = Supplier::where('company_id', $context->companyId)
            ->whereKey($supplierId)
            ->first();

        if (!$supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier not found under current company.']);
        }
        if (isset($supplier->is_active) && !$supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Selected supplier is inactive.']);
        }

        // 2. Resolve Warehouse
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
                throw ValidationException::withMessages(['purchase_order_id' => 'Purchase Order supplier differs from bill supplier.']);
            }

            try {
                $openData = app(OrderLifecycleService::class)->remaining('purchase', $po->id, $context);
                foreach ($openData['items'] as $item) {
                    $openQuantities[(int) $item['product_id']] = (float) ($item['remaining_qty'] ?? 0);
                }
            } catch (\Throwable) {
            }
        }

        // 4. GRN verification (if linked)
        $grn = null;
        if ($grnId > 0) {
            $grn = GoodsReceivedNote::where('company_id', $context->companyId)->whereKey($grnId)->first();
            if (!$grn) {
                throw ValidationException::withMessages(['grn_id' => "Goods Received Note ID {$grnId} not found."]);
            }
            if (!$grn->posted_at) {
                throw ValidationException::withMessages(['grn_id' => 'Referenced GRN is not yet posted.']);
            }
            if ((int) $grn->supplier_id !== $supplierId) {
                throw ValidationException::withMessages(['grn_id' => 'GRN supplier differs from bill supplier.']);
            }
        }

        // 5. Products & Pricing
        $rawItems = (array) ($slots['items'] ?? []);
        if (empty($rawItems)) {
            throw ValidationException::withMessages(['items' => 'At least one line item is required.']);
        }

        $productIds = [];
        $pricingItems = [];
        $productsById = [];

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

            $productIds[] = $productId;
            $productsById[$productId] = $product;

            $qty = (float) ($item['qty'] ?? 1);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.{$index}.qty" => 'Quantity must be positive.']);
            }

            if ($po && isset($openQuantities[$productId]) && $qty > $openQuantities[$productId] + 0.0001) {
                throw ValidationException::withMessages(["items.{$index}.qty" => "Billed quantity ({$qty}) exceeds remaining open billing quantity ({$openQuantities[$productId]}) on order {$po->reference_no}."]);
            }

            $unitCost = isset($item['net_unit_cost']) ? (float) $item['net_unit_cost'] : (float) ($product->cost ?? 0);
            $unitId = (int) ($item['purchase_unit_id'] ?? $product->purchase_unit_id ?? $product->unit_id ?? 1);
            $discount = isset($item['discount']) ? (float) $item['discount'] : 0.0;

            $pricingLine = [
                'product_id' => $productId,
                'qty' => $qty,
                'net_unit_cost' => $unitCost,
                'purchase_unit_id' => $unitId,
                'discount' => $discount,
                'order_item_id' => isset($item['order_item_id']) ? (int) $item['order_item_id'] : null,
                'note' => $item['note'] ?? null,
            ];

            if (isset($item['tax_rate_override'])) {
                $pricingLine['tax_rate_override'] = (float) $item['tax_rate_override'];
            } elseif (isset($item['tax_rate'])) {
                $pricingLine['tax_rate'] = (float) $item['tax_rate'];
            }

            $pricingItems[] = $pricingLine;
        }

        $orderDiscount = (float) ($slots['order_discount'] ?? 0.0);
        $shippingCost = (float) ($slots['shipping_cost'] ?? 0.0);

        $pricingInput = [
            'warehouse_id' => $warehouseId,
            'supplier_id' => $supplierId,
            'items' => $pricingItems,
        ];
        if ($orderDiscount > 0) {
            $pricingInput['order_discount'] = $orderDiscount;
        }
        if ($shippingCost > 0) {
            $pricingInput['shipping_cost'] = $shippingCost;
        }

        // Authoritative GST determination and pricing calculation
        $preparedData = $this->taxService->prepare(
            $pricingInput,
            'purchase',
            $productsById,
            $supplier,
            $context,
            $billDate,
        );

        $calculated = $this->pricing->calculate($preparedData, purchase: true);

        $totalQty = 0.0;
        $subtotal = 0.0;
        $totalTax = 0.0;
        $cgstTotal = 0.0;
        $sgstTotal = 0.0;
        $igstTotal = 0.0;
        $cessTotal = 0.0;

        $detailedPreviewLines = [];
        $normalizedLines = [];
        $lineTotals = [];

        foreach ($calculated['items'] as $index => $calcLine) {
            $productId = (int) $calcLine['product_id'];
            $product = $productsById[$productId];
            $qty = (float) $calcLine['qty'];
            $netCost = (float) $calcLine['net_unit_cost'];
            $discount = (float) ($calcLine['discount'] ?? 0);
            $taxRate = (float) ($calcLine['tax_rate'] ?? 0);
            $taxAmount = (float) ($calcLine['tax'] ?? $calcLine['tax_amount'] ?? 0);
            $lineSubtotal = (float) ($calcLine['subtotal'] ?? round($qty * $netCost - $discount, 2));
            $lineTotal = (float) ($calcLine['total'] ?? round($lineSubtotal + $taxAmount, 2));

            $totalQty += $qty;
            $subtotal += $lineSubtotal;
            $totalTax += $taxAmount;

            $taxSnapshot = $calcLine['_tax_snapshot'] ?? [];
            $cgstTotal += (float) ($taxSnapshot['cgst'] ?? 0);
            $sgstTotal += (float) ($taxSnapshot['sgst'] ?? 0);
            $igstTotal += (float) ($taxSnapshot['igst'] ?? 0);
            $cessTotal += (float) ($taxSnapshot['cess'] ?? 0);

            $normalizedLine = [
                'product_id' => $productId,
                'qty' => sprintf('%.2f', $qty),
                'net_unit_cost' => sprintf('%.2f', $netCost),
                'purchase_unit_id' => (int) $calcLine['purchase_unit_id'],
                'discount' => sprintf('%.2f', $discount),
                'tax_rate' => sprintf('%.2f', $taxRate),
                'order_item_id' => isset($calcLine['order_item_id']) ? (int) $calcLine['order_item_id'] : null,
                'note' => $calcLine['note'] ?? null,
            ];

            if (isset($calcLine['tax_rate_override'])) {
                $normalizedLine['tax_rate_override'] = sprintf('%.2f', (float) $calcLine['tax_rate_override']);
            }

            $normalizedLines[] = $normalizedLine;

            $detailedPreviewLines[] = [
                'line_number' => $index + 1,
                'product_id' => $productId,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'qty' => sprintf('%.2f', $qty),
                'net_unit_cost' => sprintf('%.2f', $netCost),
                'discount' => sprintf('%.2f', $discount),
                'tax_rate' => sprintf('%.2f%%', $taxRate),
                'tax_amount' => sprintf('%.2f', $taxAmount),
                'line_total' => sprintf('%.2f', $lineTotal),
                'purchase_unit_id' => (int) $calcLine['purchase_unit_id'],
            ];

            $lineTotals[] = [
                'product_id' => $productId,
                'qty' => sprintf('%.2f', $qty),
                'net_unit_cost' => sprintf('%.2f', $netCost),
                'tax_amount' => sprintf('%.2f', $taxAmount),
                'line_total' => sprintf('%.2f', $lineTotal),
            ];
        }

        $grandTotal = (float) ($calculated['grand_total'] ?? round($subtotal + $totalTax - $orderDiscount + $shippingCost, 2));
        $roundOff = (float) ($calculated['round_off'] ?? 0.0);

        $normalizedPayload = [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'date' => $billDate,
            'supplier_invoice_no' => $slots['supplier_invoice_no'] ?? null,
            'purchase_order_id' => $poId > 0 ? $poId : null,
            'grn_id' => $grnId > 0 ? $grnId : null,
            'due_date' => $slots['due_date'] ?? null,
            'note' => $slots['note'] ?? null,
            'items' => $normalizedLines,
            'expected_totals' => [
                'grand_total' => sprintf('%.2f', $grandTotal),
                'tax_total' => sprintf('%.2f', $totalTax),
                'total_price' => sprintf('%.2f', $subtotal + $orderDiscount),
                'subtotal' => sprintf('%.2f', $subtotal),
                'tax_breakdown' => [
                    'cgst' => sprintf('%.2f', $cgstTotal),
                    'sgst' => sprintf('%.2f', $sgstTotal),
                    'igst' => sprintf('%.2f', $igstTotal),
                    'cess' => sprintf('%.2f', $cessTotal),
                ],
                'line_totals' => $lineTotals,
            ],
        ];

        if ($orderDiscount > 0) {
            $normalizedPayload['order_discount'] = sprintf('%.2f', $orderDiscount);
        }
        if ($shippingCost > 0) {
            $normalizedPayload['shipping_cost'] = sprintf('%.2f', $shippingCost);
        }

        $inventoryPolicy = $grn
            ? "Material was already received under GRN #{$grn->grn_no}. This bill settles the GRN accrual (clears Goods Received Not Invoiced) with zero secondary stock movement."
            : "Physical stock will be received into warehouse '{$warehouse->name}'.";

        $previewData = [
            'document_type' => 'Purchase Bill (Accounts Payable Voucher)',
            'supplier' => [
                'id' => (int) $supplier->id,
                'name' => $supplier->name,
                'company_name' => $supplier->company_name ?? null,
                'vat_number' => $supplier->vat_number ?? null,
            ],
            'warehouse' => [
                'id' => (int) $warehouse->id,
                'name' => $warehouse->name,
                'branch_id' => (int) $warehouse->branch_id,
            ],
            'date' => $billDate,
            'supplier_invoice_no' => $slots['supplier_invoice_no'] ?? null,
            'purchase_order' => $po ? [
                'id' => (int) $po->id,
                'reference_no' => $po->reference_no,
            ] : null,
            'grn' => $grn ? [
                'id' => (int) $grn->id,
                'grn_no' => $grn->grn_no,
            ] : null,
            'items' => $detailedPreviewLines,
            'totals' => [
                'total_qty' => sprintf('%.2f', $totalQty),
                'subtotal' => sprintf('%.2f', $subtotal),
                'tax_total' => sprintf('%.2f', $totalTax),
                'discount_total' => sprintf('%.2f', $orderDiscount),
                'shipping_cost' => sprintf('%.2f', $shippingCost),
                'round_off' => sprintf('%.2f', $roundOff),
                'grand_total' => sprintf('%.2f', $grandTotal),
                'tax_breakdown' => [
                    'cgst' => sprintf('%.2f', $cgstTotal),
                    'sgst' => sprintf('%.2f', $sgstTotal),
                    'igst' => sprintf('%.2f', $igstTotal),
                    'cess' => sprintf('%.2f', $cessTotal),
                ],
            ],
            'policy' => [
                'status' => 'completed',
                'ledger_impact' => "Dr Expense/Inventory ₹".sprintf('%.2f', $subtotal).", Dr Input Tax Credit ₹".sprintf('%.2f', $totalTax).", Cr Accounts Payable ₹".sprintf('%.2f', $grandTotal).".",
                'inventory_impact' => $inventoryPolicy,
            ],
        ];

        $sourceStateHash = self::computeSourceStateHash(
            $context,
            $supplierId,
            $warehouseId,
            array_values(array_unique($productIds)),
            $poId > 0 ? $poId : null,
            $grnId > 0 ? $grnId : null,
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
        ?int $grnId = null,
    ): string {
        $supplier = DB::table('suppliers')
            ->where('company_id', $context->companyId)
            ->where('id', $supplierId)
            ->select(['id', 'is_active', 'vat_number', 'state', 'updated_at'])
            ->first();

        $partyTaxProfile = DB::table('party_tax_profiles')
            ->where('company_id', $context->companyId)
            ->where('party_type', 'supplier')
            ->where('party_id', $supplierId)
            ->select(['id', 'gstin', 'state_code', 'registration_type', 'verified_at', 'updated_at'])
            ->first();

        $warehouse = DB::table('warehouses')
            ->where('company_id', $context->companyId)
            ->where('id', $warehouseId)
            ->select(['id', 'branch_id', 'is_active', 'updated_at'])
            ->first();

        $fiscalYear = DB::table('fiscal_years')
            ->where('company_id', $context->companyId)
            ->where('id', $context->financialYearId)
            ->select(['id', 'status', 'is_closed', 'lock_date', 'start_date', 'end_date', 'updated_at'])
            ->first();

        $products = DB::table('products')
            ->where('company_id', $context->companyId)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->select(['id', 'cost', 'price', 'is_active', 'tax_category_id', 'tax_id', 'hsn_code', 'unit_id', 'purchase_unit_id', 'updated_at'])
            ->get();

        $taxCategoryIds = $products->pluck('tax_category_id')->filter()->unique()->values()->all();

        $taxCategories = DB::table('tax_categories')
            ->where('company_id', $context->companyId)
            ->whereIn('id', $taxCategoryIds)
            ->orderBy('id')
            ->select(['id', 'name', 'supply_type', 'input_credit_allowed', 'updated_at'])
            ->get();

        $taxRates = DB::table('tax_rates')
            ->where('company_id', $context->companyId)
            ->whereIn('tax_category_id', $taxCategoryIds)
            ->orderBy('id')
            ->select(['id', 'tax_category_id', 'rate', 'cess_rate', 'effective_from', 'effective_to', 'updated_at'])
            ->get();

        $taxRegistrations = DB::table('tax_registrations')
            ->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('status', 'active')
            ->orderBy('id')
            ->select(['id', 'gstin', 'state_code', 'status', 'effective_from', 'effective_to', 'updated_at'])
            ->get();

        $po = $poId !== null ? DB::table('purchases')
            ->where('company_id', $context->companyId)
            ->where('id', $poId)
            ->select(['id', 'status', 'reversed_at', 'updated_at'])
            ->first() : null;

        $grn = $grnId !== null ? DB::table('goods_received_notes')
            ->where('company_id', $context->companyId)
            ->where('id', $grnId)
            ->select(['id', 'status', 'posted_at', 'updated_at'])
            ->first() : null;

        $state = [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'financial_year_id' => $context->financialYearId,
            'supplier' => $supplier,
            'party_tax_profile' => $partyTaxProfile,
            'warehouse' => $warehouse,
            'fiscal_year' => $fiscalYear,
            'products' => $products->toArray(),
            'tax_categories' => $taxCategories->toArray(),
            'tax_rates' => $taxRates->toArray(),
            'tax_registrations' => $taxRegistrations->toArray(),
            'purchase_order' => $po,
            'grn' => $grn,
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
