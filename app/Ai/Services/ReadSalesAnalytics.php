<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative, actor-explicit read facade for Sales and POS Analytics.
 * Scoped strictly by company, branch, and warehouse boundaries (Tenancy Law 1 & SEC-01).
 */
class ReadSalesAnalytics
{
    public function execute(
        AiPrincipal $principal,
        ?string $from = null,
        ?string $to = null,
        ?int $warehouseId = null,
        int $limit = 10
    ): ErpToolResult {
        $limit = max(1, min(50, $limit));
        $companyId = $principal->companyId;
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        // 1. Resolve and Validate Date Range against Financial Year (AI-002)
        $year = FiscalYear::where('company_id', $companyId)->find($principal->financialYearId);
        $warnings = [];
        $dateAdjusted = false;

        $timezone = Company::whereKey($companyId)->value('timezone') ?: config('app.timezone');
        $today = now($timezone)->toDateString();

        if ($year) {
            $startY = $year->start_date->toDateString();
            $endY = $year->end_date->toDateString();

            if ($from === null) {
                // Default to beginning of active month (or FY start date if today is before)
                $firstOfMonth = now($timezone)->startOfMonth()->toDateString();
                $from = max($startY, min($endY, $firstOfMonth));
            } elseif ($from < $startY || $from > $endY) {
                $clamped = max($startY, min($endY, $from));
                $warnings[] = "Requested start date {$from} falls outside active financial year '{$year->name}' ({$startY} to {$endY}). Adjusted to {$clamped}.";
                $from = $clamped;
                $dateAdjusted = true;
            }

            if ($to === null) {
                $to = max($startY, min($endY, $today));
            } elseif ($to < $startY || $to > $endY) {
                $clamped = max($startY, min($endY, $to));
                $warnings[] = "Requested end date {$to} falls outside active financial year '{$year->name}' ({$startY} to {$endY}). Adjusted to {$clamped}.";
                $to = $clamped;
                $dateAdjusted = true;
            }
        } else {
            $from = $from ?? now($timezone)->startOfMonth()->toDateString();
            $to = $to ?? $today;
        }

        if ($from > $to) {
            $temp = $from;
            $from = $to;
            $to = $temp;
        }

        // 2. Validate Warehouse Scope (SEC-01 & SEC-02)
        $warehouseName = 'All Branch Warehouses';
        if ($warehouseId !== null) {
            $warehouse = Warehouse::where('company_id', $companyId)->find($warehouseId);
            if (!$warehouse) {
                return ErpToolResult::error(
                    toolKey: 'sales.summary.v1',
                    message: "Warehouse ID {$warehouseId} does not exist or is not accessible.",
                    errorCode: 'AI_TOOL_FORBIDDEN',
                    scope: [
                        'company_id' => $companyId,
                        'branch_id' => $principal->branchId,
                        'warehouse_id' => $warehouseId,
                    ]
                );
            }

            if ($warehouse->branch_id !== null && (int) $warehouse->branch_id !== (int) $principal->branchId) {
                return ErpToolResult::error(
                    toolKey: 'sales.summary.v1',
                    message: "Warehouse '{$warehouse->name}' belongs to a different branch and is not accessible.",
                    errorCode: 'AI_TOOL_FORBIDDEN',
                    scope: [
                        'company_id' => $companyId,
                        'branch_id' => $principal->branchId,
                        'warehouse_id' => $warehouseId,
                    ]
                );
            }

            $warehouseName = $warehouse->name;
        }

        // 3. Resolve Company Base Currency
        $company = Company::find($companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        // 4. Query Authoritative Sales for Context
        $salesQuery = Sale::visibleIn($context)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNull('deleted_at')
            ->whereNull('reversed_at');

        if ($warehouseId !== null) {
            $salesQuery->where('warehouse_id', $warehouseId);
        }

        $sales = $salesQuery->get();

        $salesCount = $sales->count();
        $grossSales = BigDecimal::zero();
        $totalDiscount = BigDecimal::zero();
        $totalTax = BigDecimal::zero();
        $paidAmount = BigDecimal::zero();

        foreach ($sales as $sale) {
            $grand = BigDecimal::of((string) ($sale->grand_total ?? '0.00'));
            $discount = BigDecimal::of((string) ($sale->total_discount ?? '0.00'));
            $tax = BigDecimal::of((string) ($sale->total_tax ?? '0.00'));
            $paid = BigDecimal::of((string) ($sale->paid_amount ?? '0.00'));

            $grossSales = $grossSales->plus($grand);
            $totalDiscount = $totalDiscount->plus($discount);
            $totalTax = $totalTax->plus($tax);
            $paidAmount = $paidAmount->plus($paid);
        }

        $netSales = $grossSales;
        $dueAmount = $grossSales->minus($paidAmount);
        if ($dueAmount->isNegative()) {
            $dueAmount = BigDecimal::zero();
        }

        // 5. Query Top Products Sold in Period
        $saleIds = $sales->pluck('id')->all();
        $topProducts = [];

        if (!empty($saleIds)) {
            $topQuery = DB::table('product_sales')
                ->join('products', 'products.id', '=', 'product_sales.product_id')
                ->whereIn('product_sales.sale_id', $saleIds)
                ->where('products.company_id', $companyId)
                ->groupBy('products.id', 'products.name', 'products.code')
                ->select([
                    'products.id as product_id',
                    'products.code',
                    'products.name',
                    DB::raw('SUM(product_sales.qty) as total_qty'),
                    DB::raw('SUM(product_sales.total) as total_revenue'),
                ])
                ->orderByDesc('total_revenue')
                ->limit($limit)
                ->get();

            foreach ($topQuery as $p) {
                $topProducts[] = [
                    'product_id' => (int) $p->product_id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'total_qty' => round((float) $p->total_qty, 4),
                    'total_revenue' => (string) BigDecimal::of((string) $p->total_revenue)->toScale(2, RoundingMode::HalfUp),
                ];
            }
        }

        $sourceRoute = route('report.sale', absolute: false);

        return ErpToolResult::ok(
            toolKey: 'sales.summary.v1',
            scope: [
                'company_id' => $companyId,
                'branch_id' => $principal->branchId,
                'warehouse_id' => $warehouseId,
            ],
            data: [
                'period' => [
                    'from' => $from,
                    'to' => $to,
                    'date_adjusted' => $dateAdjusted,
                ],
                'currency' => $currencyCode,
                'warehouse' => $warehouseName,
                'totals' => [
                    'sales_count' => $salesCount,
                    'gross_sales' => (string) $grossSales->toScale(2, RoundingMode::HalfUp),
                    'total_discount' => (string) $totalDiscount->toScale(2, RoundingMode::HalfUp),
                    'total_tax' => (string) $totalTax->toScale(2, RoundingMode::HalfUp),
                    'net_sales' => (string) $netSales->toScale(2, RoundingMode::HalfUp),
                    'paid_amount' => (string) $paidAmount->toScale(2, RoundingMode::HalfUp),
                    'due_amount' => (string) $dueAmount->toScale(2, RoundingMode::HalfUp),
                ],
                'top_products' => $topProducts,
            ],
            sourceRefs: [$sourceRoute],
            warnings: $warnings,
            asOf: $to,
            reconciled: true
        );
    }
}

