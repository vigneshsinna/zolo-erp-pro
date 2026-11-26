<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
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
        ['from' => $from, 'to' => $to, 'timezone' => $timezone] = AiReportingPeriod::resolve($principal, $from, $to, monthToDate: true);
        $warnings = [];

        // 2. Validate Warehouse Scope (SEC-01 & SEC-02)
        $warehouseName = 'All Branch Warehouses';
        if ($warehouseId !== null) {
            $warehouse = Warehouse::where('company_id', $companyId)
                ->where('branch_id', $principal->branchId)->where('is_active', true)->find($warehouseId);
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


            $warehouseName = $warehouse->name;
        }

        // 3. Resolve Company Base Currency
        $company = Company::find($companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        // Posting and the sales report use created_at as the document business date.
        $start = $from . ' 00:00:00';
        $end = \Carbon\CarbonImmutable::parse($to, $timezone)->addDay()->toDateString() . ' 00:00:00';
        $sales = Sale::visibleIn($context)
            ->where('sales.branch_id', $principal->branchId)
            ->where('sales.financial_year_id', $principal->financialYearId)
            ->where('sales.created_at', '>=', $start)->where('sales.created_at', '<', $end)
            ->where('sales.sale_status', 1)->whereNull('sales.reversed_at')
            ->when($warehouseId !== null, fn ($q) => $q->where('sales.warehouse_id', $warehouseId));

        $adjustments = DB::table('returns')
            ->where('company_id', $companyId)->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->where(fn ($q) => $q->where('status', 'posted')->orWhereNull('status'))
            ->whereIn('sale_id', Sale::visibleIn($context)->where('sales.branch_id', $principal->branchId)->select('sales.id'))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId));

        $salesRate = 'CASE WHEN sales.exchange_rate > 0 THEN sales.exchange_rate ELSE 1 END';
        $returnRate = 'CASE WHEN returns.exchange_rate > 0 THEN returns.exchange_rate ELSE 1 END';
        $adjustmentSign = "CASE WHEN returns.note_type = 'debit' THEN 1 ELSE -1 END";

        $summary = (clone $sales)->toBase()->selectRaw("
            COUNT(*) AS sales_count,
            SUM(COALESCE(sales.grand_total, 0) / ($salesRate)) AS gross_sales,
            SUM((COALESCE(sales.total_discount, 0) + COALESCE(sales.order_discount, 0)) / ($salesRate)) AS discounts,
            SUM((COALESCE(sales.total_tax, 0) + COALESCE(sales.order_tax, 0)) / ($salesRate)) AS tax,
            SUM(COALESCE(sales.paid_amount, 0) / ($salesRate)) AS paid,
            SUM(CASE WHEN sales.exchange_rate <= 0 THEN 1 ELSE 0 END) AS invalid_rates
        ")->first();
        $changes = (clone $adjustments)->selectRaw("
            COUNT(*) AS adjustment_count,
            SUM(($adjustmentSign) * COALESCE(grand_total, 0) / ($returnRate)) AS adjustment,
            SUM(($adjustmentSign) * (COALESCE(total_tax, 0) + COALESCE(order_tax, 0)) / ($returnRate)) AS tax,
            SUM(CASE WHEN exchange_rate <= 0 THEN 1 ELSE 0 END) AS invalid_rates
        ")->first();

        $gross = BigDecimal::of((string) ($summary->gross_sales ?? 0));
        $change = BigDecimal::of((string) ($changes->adjustment ?? 0));
        $net = $gross->plus($change);
        $paid = BigDecimal::of((string) ($summary->paid ?? 0));
        $tax = BigDecimal::of((string) ($summary->tax ?? 0))->plus((string) ($changes->tax ?? 0));
        $amount = static fn (BigDecimal|string $value): string => (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);

        $invoiceLines = DB::table('product_sales')
            ->joinSub((clone $sales)->select('sales.id', 'sales.exchange_rate'), 'sales', 'sales.id', '=', 'product_sales.sale_id')
            ->where('product_sales.company_id', $companyId)
            ->selectRaw("product_sales.product_id, product_sales.sale_unit_id, product_sales.qty AS quantity, product_sales.total / ($salesRate) AS revenue");
        $adjustmentLines = DB::table('product_returns')
            ->joinSub((clone $adjustments)->select('returns.id', 'returns.exchange_rate', 'returns.note_type'), 'returns', 'returns.id', '=', 'product_returns.return_id')
            ->where('product_returns.company_id', $companyId)
            ->selectRaw("product_returns.product_id, product_returns.sale_unit_id, ($adjustmentSign) * product_returns.qty AS quantity, ($adjustmentSign) * product_returns.total / ($returnRate) AS revenue");
        $topProducts = DB::query()->fromSub($invoiceLines->unionAll($adjustmentLines), 'lines')
            ->join('products', 'products.id', '=', 'lines.product_id')->where('products.company_id', $companyId)
            ->leftJoin('units', fn ($join) => $join->on('units.id', '=', 'lines.sale_unit_id')->where('units.company_id', $companyId))
            ->groupBy('products.id', 'products.code', 'products.name', 'lines.sale_unit_id', 'units.unit_code')
            ->selectRaw('products.id AS product_id, products.code, products.name, units.unit_code AS unit, SUM(lines.quantity) AS total_qty, SUM(lines.revenue) AS total_revenue')
            ->orderByDesc('total_revenue')->orderBy('products.id')->orderBy('lines.sale_unit_id')->limit($limit)->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'code' => $row->code,
                'name' => $row->name,
                'unit' => $row->unit,
                'total_qty' => (string) BigDecimal::of((string) $row->total_qty)->toScale(4, RoundingMode::HalfUp),
                'total_revenue' => $amount((string) $row->total_revenue),
            ])->all();

        $invalidRates = (int) $summary->invalid_rates + (int) $changes->invalid_rates;
        if ($invalidRates > 0) {
            $warnings[] = 'Some documents have invalid exchange rates. They are shown at rate 1 and the figures require reconciliation.';
        }
        $warnings[] = 'Paid amounts are the current settlement totals for the selected invoices. Use the receivables report for historical balances.';

        return ErpToolResult::ok(
            toolKey: 'sales.summary.v1',
            scope: [
                'company_id' => $companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'warehouse_id' => $warehouseId,
            ],
            data: [
                'period' => ['from' => $from, 'to' => $to, 'date_adjusted' => false, 'timezone' => $timezone],
                'currency' => $currencyCode,
                'warehouse' => $warehouseName,
                'totals' => [
                    'sales_count' => (int) $summary->sales_count,
                    'gross_sales' => $amount($gross),
                    'total_discount' => $amount((string) ($summary->discounts ?? 0)),
                    'total_tax' => $amount($tax),
                    'net_sales' => $amount($net),
                    'adjustments' => $amount($change),
                    'paid_amount' => $amount($paid),
                    'due_amount' => $amount($net->minus($paid)),
                ],
                'top_products' => $topProducts,
            ],
            sourceRefs: [route('report.sale', ['start_date' => $from, 'end_date' => $to, 'warehouse_id' => $warehouseId], absolute: false)],
            warnings: $warnings,
            asOf: $to,
            reconciled: $invalidRates === 0,
            rowCount: (int) $summary->sales_count + (int) $changes->adjustment_count,
        );
    }
}
