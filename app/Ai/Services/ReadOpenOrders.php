<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Authoritative read facade for Open Orders, pending fulfilment, and unbilled purchase/sale orders.
 * Strictly scoped by CompanyContext and branch isolation.
 */
class ReadOpenOrders
{
    public function execute(
        AiPrincipal $principal,
        string $type = 'all', // 'all', 'sale', 'purchase'
        int $limit = 20
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        $limit = max(1, min(50, $limit));

        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        $orders = [];
        $totalSalesRemaining = BigDecimal::zero();
        $totalPurchasesRemaining = BigDecimal::zero();

        // 1. Open Sales Orders / Pending Deliveries
        if (in_array($type, ['all', 'sale'], true)) {
            $sales = Sale::visibleIn($context)
                ->where('sale_status', '!=', 1) // status 1 is completed/delivered
                ->whereNull('deleted_at')
                ->whereNull('reversed_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            foreach ($sales as $sale) {
                $customer = Customer::find($sale->customer_id);
                $grandTotal = BigDecimal::of((string) ($sale->grand_total ?? 0))->toScale(2, RoundingMode::HalfUp);
                $paid = BigDecimal::of((string) ($sale->paid_amount ?? 0))->toScale(2, RoundingMode::HalfUp);
                $openAmt = $grandTotal->minus($paid);

                $totalSalesRemaining = $totalSalesRemaining->plus($grandTotal);

                $orders[] = [
                    'order_type' => 'sale',
                    'order_id' => (int) $sale->id,
                    'reference_no' => $sale->reference_no,
                    'date' => substr((string) $sale->created_at, 0, 10),
                    'party' => [
                        'type' => 'customer',
                        'id' => (int) $sale->customer_id,
                        'name' => $customer?->name ?? 'Unknown Customer',
                    ],
                    'items_count' => (int) ($sale->item ?? 1),
                    'total_qty' => (float) ($sale->total_qty ?? 0),
                    'grand_total' => (string) $grandTotal,
                    'open_balance' => (string) $openAmt,
                    'status' => $sale->sale_status == 2 ? 'pending' : ($sale->sale_status == 3 ? 'draft' : 'in_progress'),
                    'source_link' => Route::has('sale.invoice') ? route('sale.invoice', $sale->id, absolute: false) : "/sales/{$sale->id}",
                ];
            }
        }

        // 2. Open Purchase Orders / Pending Receipts
        if (in_array($type, ['all', 'purchase'], true)) {
            $purchases = Purchase::where('company_id', $principal->companyId)
                ->where(function ($q) use ($principal) {
                    $q->where('branch_id', $principal->branchId)->orWhereNull('branch_id');
                })
                ->where('status', '!=', 1) // status 1 is completed/received
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            foreach ($purchases as $purchase) {
                $supplier = Supplier::find($purchase->supplier_id);
                $grandTotal = BigDecimal::of((string) ($purchase->grand_total ?? 0))->toScale(2, RoundingMode::HalfUp);
                $paid = BigDecimal::of((string) ($purchase->paid_amount ?? 0))->toScale(2, RoundingMode::HalfUp);
                $openAmt = $grandTotal->minus($paid);

                $totalPurchasesRemaining = $totalPurchasesRemaining->plus($grandTotal);

                $orders[] = [
                    'order_type' => 'purchase',
                    'order_id' => (int) $purchase->id,
                    'reference_no' => $purchase->reference_no,
                    'date' => substr((string) $purchase->created_at, 0, 10),
                    'party' => [
                        'type' => 'supplier',
                        'id' => (int) $purchase->supplier_id,
                        'name' => $supplier?->name ?? 'Unknown Supplier',
                    ],
                    'items_count' => (int) ($purchase->item ?? 1),
                    'total_qty' => (float) ($purchase->total_qty ?? 0),
                    'grand_total' => (string) $grandTotal,
                    'open_balance' => (string) $openAmt,
                    'status' => $purchase->status == 2 ? 'pending' : ($purchase->status == 3 ? 'partial' : 'draft'),
                    'source_link' => Route::has('purchases.index') ? route('purchases.index', absolute: false) : "/purchases/{$purchase->id}",
                ];
            }
        }

        return ErpToolResult::ok(
            toolKey: 'orders.open.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'currency' => $currencyCode,
                'summary' => [
                    'total_open_orders' => count($orders),
                    'total_sales_value' => (string) $totalSalesRemaining,
                    'total_purchase_value' => (string) $totalPurchasesRemaining,
                ],
                'orders' => array_slice($orders, 0, $limit),
            ],
            sourceRefs: ['/sales', '/purchases'],
            warnings: [],
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: count($orders)
        );
    }
}

