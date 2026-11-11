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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preview adapter for purchase_order.create.v1.
 * Validates supplier, warehouse, products, and calculates deterministic pricing preview
 * delegating strictly to the authoritative ERP CommercialPricing domain service.
 * Strictly READ-ONLY with ZERO database mutations.
 */
final class PurchaseOrderPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function __construct(
        private readonly CommercialPricing $pricing,
    ) {
    }

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

        // 3. Resolve & validate Products and compute exact lines via authoritative CommercialPricing
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
            $unitCost = (float) ($item['net_unit_cost'] ?? $product->cost ?? 0);
            $purchaseUnitId = (int) ($item['purchase_unit_id'] ?? $product->purchase_unit_id ?? $product->unit_id ?? 1);
            $discount = (float) ($item['discount'] ?? 0);

            $pricingLine = [
                'product_id' => $productId,
                'qty' => $qty,
                'net_unit_cost' => $unitCost,
                'purchase_unit_id' => $purchaseUnitId,
                'discount' => $discount,
            ];

            if (isset($item['tax_rate']) && $item['tax_rate'] !== null && $item['tax_rate'] !== '') {
                $pricingLine['tax_rate'] = (float) $item['tax_rate'];
                $pricingLine['tax_rate_override'] = (string) $item['tax_rate'];
            }

            $pricingItems[] = $pricingLine;
        }

        $orderDiscount = (float) ($slots['order_discount'] ?? 0);
        $shippingCost = (float) ($slots['shipping_cost'] ?? 0);

        $pricingInput = [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'business_date' => $orderDate,
            'items' => $pricingItems,
        ];
        if ($orderDiscount > 0) {
            $pricingInput['order_discount'] = $orderDiscount;
        }
        if ($shippingCost > 0) {
            $pricingInput['shipping_cost'] = $shippingCost;
        }

        // Authoritative ERP CommercialPricing calculation (Zero separate ad-hoc arithmetic)
        $pricingResult = $this->pricing->preview($pricingInput, purchase: true, context: $context);

        $normalizedItems = [];
        $detailedPreviewLines = [];
        $totalQty = 0.0;

        foreach ($pricingResult['items'] as $index => $pricedLine) {
            $productId = (int) $pricedLine['product_id'];
            $product = $productsById[$productId];
            $qty = (float) $pricedLine['qty'];
            $totalQty += $qty;

            $unitCost = (float) ($pricedLine['net_unit_cost'] ?? $pricingItems[$index]['net_unit_cost']);
            $lineDiscount = (float) ($pricedLine['discount'] ?? 0);
            $lineTaxRate = (float) ($pricedLine['tax_rate'] ?? 0);
            $lineTaxAmount = (float) ($pricedLine['tax'] ?? 0);
            $lineTotal = (float) ($pricedLine['total'] ?? 0);

            $qtyStr = sprintf('%.2f', $qty);
            $costStr = sprintf('%.2f', $unitCost);
            $discountStr = sprintf('%.2f', $lineDiscount);
            $taxRateStr = sprintf('%.2f', $lineTaxRate);
            $taxAmountStr = sprintf('%.2f', $lineTaxAmount);
            $lineTotalStr = sprintf('%.2f', $lineTotal);

            $normalizedLine = [
                'product_id' => $productId,
                'qty' => $qtyStr,
                'net_unit_cost' => $costStr,
                'discount' => $discountStr,
                'tax_rate' => $taxRateStr,
                'purchase_unit_id' => (int) ($pricedLine['purchase_unit_id'] ?? $pricingItems[$index]['purchase_unit_id']),
                'note' => isset($rawItems[$index]['note']) ? (string) $rawItems[$index]['note'] : null,
            ];
            if (isset($pricingItems[$index]['tax_rate_override'])) {
                $normalizedLine['tax_rate_override'] = $pricingItems[$index]['tax_rate_override'];
            }
            $normalizedItems[] = $normalizedLine;

            $previewLine = [
                'product_id' => $productId,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'qty' => $qtyStr,
                'net_unit_cost' => $costStr,
                'discount' => $discountStr,
                'tax_rate' => $taxRateStr,
                'tax_amount' => $taxAmountStr,
                'line_total' => $lineTotalStr,
            ];
            if (!empty($pricedLine['_tax_snapshot'])) {
                $previewLine['tax_breakdown'] = [
                    'cgst' => sprintf('%.2f', (float) ($pricedLine['_tax_snapshot']['cgst'] ?? 0)),
                    'sgst' => sprintf('%.2f', (float) ($pricedLine['_tax_snapshot']['sgst'] ?? 0)),
                    'igst' => sprintf('%.2f', (float) ($pricedLine['_tax_snapshot']['igst'] ?? 0)),
                    'cess' => sprintf('%.2f', (float) ($pricedLine['_tax_snapshot']['cess'] ?? 0)),
                    'reverse_charge' => (bool) ($pricedLine['_tax_snapshot']['reverse_charge'] ?? false),
                ];
            }
            $detailedPreviewLines[] = $previewLine;
        }

        $normalizedPayload = [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'date' => $orderDate,
            'items' => $normalizedItems,
            'note' => isset($slots['note']) ? (string) $slots['note'] : null,
        ];
        if ($orderDiscount > 0) {
            $normalizedPayload['order_discount'] = sprintf('%.2f', $orderDiscount);
        }
        if ($shippingCost > 0) {
            $normalizedPayload['shipping_cost'] = sprintf('%.2f', $shippingCost);
        }

        $grandTotal = (float) $pricingResult['grand_total'];
        $totalTax = (float) $pricingResult['total_tax'];
        $totalPrice = (float) $pricingResult['total_price'];
        $subtotal = $totalPrice - $totalTax;
        $roundOff = (float) ($pricingResult['round_off'] ?? 0);

        $cgstTotal = 0.0;
        $sgstTotal = 0.0;
        $igstTotal = 0.0;
        $cessTotal = 0.0;
        $lineTotals = [];

        foreach ($pricingResult['items'] as $pItem) {
            $lineTaxSnapshot = (array) ($pItem['_tax_snapshot'] ?? []);
            $cgstTotal += (float) ($lineTaxSnapshot['cgst'] ?? 0);
            $sgstTotal += (float) ($lineTaxSnapshot['sgst'] ?? 0);
            $igstTotal += (float) ($lineTaxSnapshot['igst'] ?? 0);
            $cessTotal += (float) ($lineTaxSnapshot['cess'] ?? 0);

            $lineTotals[] = [
                'product_id' => (int) $pItem['product_id'],
                'qty' => sprintf('%.2f', (float) $pItem['qty']),
                'net_unit_cost' => sprintf('%.2f', (float) ($pItem['net_unit_cost'] ?? 0)),
                'tax_amount' => sprintf('%.2f', (float) ($pItem['tax'] ?? 0)),
                'line_total' => sprintf('%.2f', (float) ($pItem['total'] ?? 0)),
            ];
        }

        $normalizedPayload['expected_totals'] = [
            'grand_total' => sprintf('%.2f', $grandTotal),
            'tax_total' => sprintf('%.2f', $totalTax),
            'total_price' => sprintf('%.2f', $totalPrice),
            'subtotal' => sprintf('%.2f', $subtotal),
            'tax_breakdown' => [
                'cgst' => sprintf('%.2f', $cgstTotal),
                'sgst' => sprintf('%.2f', $sgstTotal),
                'igst' => sprintf('%.2f', $igstTotal),
                'cess' => sprintf('%.2f', $cessTotal),
            ],
            'line_totals' => $lineTotals,
        ];

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
                'tax_total' => sprintf('%.2f', $totalTax),
                'discount_total' => sprintf('%.2f', $orderDiscount),
                'shipping_cost' => sprintf('%.2f', $shippingCost),
                'round_off' => sprintf('%.2f', $roundOff),
                'grand_total' => sprintf('%.2f', $grandTotal),
            ],
            'policy' => [
                'status' => 'ordered',
                'commercial_register' => 'order_register',
                'ledger_impact' => 'none_until_billed',
                'inventory_impact' => 'open_order_allocations',
            ],
        ];

        $sourceStateHash = self::computeSourceStateHash(
            $context,
            $supplierId,
            $warehouseId,
            array_values(array_unique($productIds)),
        );

        return new ActionPreviewResult(
            payload: $normalizedPayload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
            warnings: $warnings,
        );
    }

    /**
     * Compute a deterministic SHA-256 hash representing the current business state of the
     * underlying entities that this purchase order depends upon (supplier, warehouse, fiscal year, products, tax).
     */
    public static function computeSourceStateHash(
        CompanyContext $context,
        int $supplierId,
        int $warehouseId,
        array $productIds,
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

        $legacyTaxIds = $products->pluck('tax_id')->filter()->unique()->values()->all();
        $legacyTaxes = !empty($legacyTaxIds)
            ? DB::table('taxes')
                ->whereIn('id', $legacyTaxIds)
                ->orderBy('id')
                ->select(['id', 'name', 'rate', 'is_active', 'updated_at'])
                ->get()
            : collect();

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

        $state = [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'financial_year_id' => $context->financialYearId,
            'fiscal_year' => $fiscalYear ? (array) $fiscalYear : null,
            'supplier' => $supplier ? (array) $supplier : null,
            'party_tax_profile' => $partyTaxProfile ? (array) $partyTaxProfile : null,
            'warehouse' => $warehouse ? (array) $warehouse : null,
            'products' => $products->map(fn($p) => (array) $p)->all(),
            'legacy_taxes' => $legacyTaxes->map(fn($t) => (array) $t)->all(),
            'tax_categories' => $taxCategories->map(fn($c) => (array) $c)->all(),
            'tax_rates' => $taxRates->map(fn($r) => (array) $r)->all(),
            'tax_registrations' => $taxRegistrations->map(fn($r) => (array) $r)->all(),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
