<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPricing;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preview adapter for sales_order.create.v1.
 * Validates customer, warehouse, products, and calculates deterministic pricing preview
 * delegating strictly to the authoritative ERP CommercialPricing domain service.
 * Strictly READ-ONLY with ZERO database mutations.
 */
final class SalesOrderPreviewAdapter implements ActionPreviewAdapterInterface
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
            throw new \InvalidArgumentException('Mode B company context required for sales order preview.');
        }

        $customerId = (int) ($slots['customer_id'] ?? 0);
        $warehouseId = (int) ($slots['warehouse_id'] ?? 0);
        $orderDate = !empty($slots['date']) ? (string) $slots['date'] : now()->toDateString();

        // 1. Resolve & validate Customer
        $customer = Customer::where('company_id', $context->companyId)
            ->whereKey($customerId)
            ->first();

        if (!$customer) {
            throw ValidationException::withMessages(['customer_id' => 'Customer not found under current company.']);
        }
        if (isset($customer->is_active) && !$customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => 'Selected customer is inactive.']);
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

        // Credit limit check advisory
        if (!empty($customer->credit_limit) && (float) $customer->credit_limit > 0) {
            $openReceivables = (float) ($customer->opening_balance ?? 0);
            $creditLimit = (float) $customer->credit_limit;
            if ($openReceivables >= $creditLimit) {
                $warnings[] = "Customer has reached or exceeded credit limit of ₹" . number_format($creditLimit, 2);
            }
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
            $unitPrice = (float) ($item['net_unit_price'] ?? $item['unit_price'] ?? $product->price ?? 0);
            $saleUnitId = (int) ($item['sale_unit_id'] ?? $product->sale_unit_id ?? $product->unit_id ?? 1);
            $discount = (float) ($item['discount'] ?? 0);

            $pricingLine = [
                'product_id' => $productId,
                'qty' => $qty,
                'net_unit_price' => $unitPrice,
                'sale_unit_id' => $saleUnitId,
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
            'customer_id' => $customerId,
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

        // Authoritative ERP CommercialPricing calculation (purchase: false for sales orders)
        $pricingResult = $this->pricing->preview($pricingInput, purchase: false, context: $context);

        $normalizedItems = [];
        $detailedPreviewLines = [];
        $totalQty = 0.0;

        foreach ($pricingResult['items'] as $index => $pricedLine) {
            $productId = (int) $pricedLine['product_id'];
            $product = $productsById[$productId];
            $qty = (float) $pricedLine['qty'];
            $totalQty += $qty;

            $unitPrice = (float) ($pricedLine['net_unit_price'] ?? $pricingItems[$index]['net_unit_price']);
            $lineDiscount = (float) ($pricedLine['discount'] ?? 0);
            $lineTaxRate = (float) ($pricedLine['tax_rate'] ?? 0);
            $lineTaxAmount = (float) ($pricedLine['tax'] ?? 0);
            $lineTotal = (float) ($pricedLine['total'] ?? 0);

            $qtyStr = sprintf('%.2f', $qty);
            $priceStr = sprintf('%.2f', $unitPrice);
            $discountStr = sprintf('%.2f', $lineDiscount);
            $taxRateStr = sprintf('%.2f', $lineTaxRate);
            $taxAmountStr = sprintf('%.2f', $lineTaxAmount);
            $totalStr = sprintf('%.2f', $lineTotal);

            $normalizedLine = [
                'product_id' => $productId,
                'qty' => $qtyStr,
                'net_unit_price' => $priceStr,
                'sale_unit_id' => (int) ($pricingItems[$index]['sale_unit_id'] ?? 1),
                'discount' => $discountStr,
                'tax_rate' => $taxRateStr,
            ];

            if (!empty($rawItems[$index]['note'])) {
                $normalizedLine['note'] = (string) $rawItems[$index]['note'];
            }

            $normalizedItems[] = $normalizedLine;

            $detailedPreviewLines[] = [
                'product_id' => $productId,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'qty' => $qtyStr,
                'net_unit_price' => $priceStr,
                'discount' => $discountStr,
                'tax_rate' => $taxRateStr,
                'tax_amount' => $taxAmountStr,
                'total' => $totalStr,
                'sale_unit_id' => (int) ($pricingItems[$index]['sale_unit_id'] ?? 1),
            ];
        }

        $grandTotal = (float) ($pricingResult['grand_total'] ?? 0);
        $taxTotal = (float) ($pricingResult['total_tax'] ?? 0);
        $totalPrice = (float) ($pricingResult['total_price'] ?? 0);
        $subtotal = (float) ($pricingResult['subtotal'] ?? $totalPrice);

        $taxBreakdown = [
            'cgst' => sprintf('%.2f', (float) ($pricingResult['tax_breakdown']['cgst'] ?? 0)),
            'sgst' => sprintf('%.2f', (float) ($pricingResult['tax_breakdown']['sgst'] ?? 0)),
            'igst' => sprintf('%.2f', (float) ($pricingResult['tax_breakdown']['igst'] ?? 0)),
            'cess' => sprintf('%.2f', (float) ($pricingResult['tax_breakdown']['cess'] ?? 0)),
        ];

        $lineTotals = array_map(fn(array $line) => [
            'product_id' => $line['product_id'],
            'qty' => sprintf('%.2f', $line['qty']),
            'net_unit_price' => sprintf('%.2f', $line['net_unit_price']),
            'tax_amount' => sprintf('%.2f', $line['tax_amount']),
            'line_total' => sprintf('%.2f', $line['total']),
        ], $detailedPreviewLines);

        $normalizedPayload = [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'date' => $orderDate,
            'items' => $normalizedItems,
            'order_discount' => sprintf('%.2f', $orderDiscount),
            'shipping_cost' => sprintf('%.2f', $shippingCost),
            'expected_totals' => [
                'grand_total' => sprintf('%.2f', $grandTotal),
                'tax_total' => sprintf('%.2f', $taxTotal),
                'total_price' => sprintf('%.2f', $totalPrice),
                'subtotal' => sprintf('%.2f', $subtotal),
                'tax_breakdown' => $taxBreakdown,
                'line_totals' => $lineTotals,
            ],
        ];

        if (!empty($slots['delivery_date'])) {
            $normalizedPayload['delivery_date'] = (string) $slots['delivery_date'];
        }
        if (!empty($slots['customer_reference_no'])) {
            $normalizedPayload['customer_reference_no'] = (string) $slots['customer_reference_no'];
        }
        if (!empty($slots['note'])) {
            $normalizedPayload['note'] = (string) $slots['note'];
        }

        $previewData = [
            'document_type' => 'Sales Order',
            'currency' => 'INR',
            'customer_name' => $customer->name,
            'customer' => [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'company_name' => $customer->company_name,
                'tax_no' => $customer->tax_no,
            ],
            'warehouse' => [
                'id' => (int) $warehouse->id,
                'name' => $warehouse->name,
            ],
            'order_date' => $orderDate,
            'delivery_date' => $slots['delivery_date'] ?? null,
            'lines' => $detailedPreviewLines,
            'summary' => [
                'item_count' => count($detailedPreviewLines),
                'total_qty' => sprintf('%.2f', $totalQty),
                'subtotal' => sprintf('%.2f', $subtotal),
                'total_tax' => sprintf('%.2f', $taxTotal),
                'tax_breakdown' => [
                    'cgst' => $taxBreakdown['cgst'],
                    'sgst' => $taxBreakdown['sgst'],
                    'igst' => $taxBreakdown['igst'],
                    'cess' => $taxBreakdown['cess'],
                ],
                'order_discount' => sprintf('%.2f', $orderDiscount),
                'shipping_cost' => sprintf('%.2f', $shippingCost),
                'grand_total' => sprintf('%.2f', $grandTotal),
            ],
            'policy' => [
                'status' => 'open',
                'commercial_register' => 'sales_order_register',
                'ledger_impact' => 'none_until_invoiced',
                'inventory_impact' => 'stock_allocation_only',
            ],
        ];

        // 4. Non-blocking Inventory Availability Check (ATP Advisory)
        $hasSufficientStock = true;
        $stockIssues = [];
        $availabilityService = app(\App\Services\Inventory\InventoryAvailabilityService::class);
        foreach ($rawItems as $item) {
            $pId = (int) ($item['product_id'] ?? 0);
            $reqQty = (float) ($item['qty'] ?? 0);
            if ($pId > 0 && $reqQty > 0) {
                $avail = $availabilityService->forProduct($pId, $warehouseId);
                $availableQty = (float) ($avail['available'] ?? 0);
                if ($availableQty < $reqQty) {
                    $hasSufficientStock = false;
                    $productName = $productsById[$pId]->name ?? "Product #{$pId}";
                    $warnings[] = "Insufficient stock for '{$productName}': requested {$reqQty}, available {$availableQty}.";
                    $stockIssues[] = [
                        'product_id' => $pId,
                        'product_name' => $productName,
                        'requested_qty' => sprintf('%.2f', $reqQty),
                        'available_qty' => sprintf('%.2f', $availableQty),
                    ];
                }
            }
        }

        $previewData['stock_availability'] = [
            'has_sufficient_stock' => $hasSufficientStock,
            'stock_issues' => $stockIssues,
        ];

        $sourceStateHash = self::computeSourceStateHash(
            $context,
            $customerId,
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
     * underlying entities that this sales order depends upon.
     */
    public static function computeSourceStateHash(
        CompanyContext $context,
        int $customerId,
        int $warehouseId,
        array $productIds,
    ): string {
        $customer = DB::table('customers')
            ->where('company_id', $context->companyId)
            ->where('id', $customerId)
            ->select(['id', 'is_active', 'tax_no', 'state', 'updated_at'])
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
            ->select(['id', 'cost', 'price', 'is_active', 'tax_id', 'hsn_code', 'unit_id', 'sale_unit_id', 'updated_at'])
            ->get();

        $canonical = [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'financial_year_id' => $context->financialYearId,
            'customer' => $customer ? (array) $customer : null,
            'warehouse' => $warehouse ? (array) $warehouse : null,
            'fiscal_year' => $fiscalYear ? (array) $fiscalYear : null,
            'products' => $products->map(fn($p) => (array) $p)->all(),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
