<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\Currency;
use App\Services\Accounting\FinancialReportService;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Authoritative, actor-explicit read facade for Financial Statements.
 * Exposes Trial Balance, Profit and Loss, and Balance Sheet strictly within CompanyContext.
 */
class ReadFinancialStatements
{
    public function __construct(
        protected FinancialReportService $financialReportService
    ) {}

    /**
     * Execute financial statement query.
     *
     * @param AiPrincipal $principal Authenticated actor & company tenancy
     * @param string $statementType Statement type: 'profit_and_loss', 'balance_sheet', 'trial_balance'
     * @param string|null $from Start date (YYYY-MM-DD)
     * @param string|null $to End date (YYYY-MM-DD)
     */
    public function execute(
        AiPrincipal $principal,
        string $statementType = 'profit_and_loss',
        ?string $from = null,
        ?string $to = null
    ): ErpToolResult {
        $allowedTypes = ['profit_and_loss', 'balance_sheet', 'trial_balance'];
        if (!in_array($statementType, $allowedTypes, true)) {
            $statementType = 'profit_and_loss';
        }

        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        // 1. Resolve and Validate Date Range against Financial Year
        $year = FiscalYear::where('company_id', $principal->companyId)->find($principal->financialYearId);
        $warnings = [];
        $dateAdjusted = false;

        $timezone = Company::whereKey($principal->companyId)->value('timezone') ?: config('app.timezone');
        $today = now($timezone)->toDateString();

        if ($year) {
            $startY = $year->start_date->toDateString();
            $endY = $year->end_date->toDateString();

            if ($from === null) {
                $from = $startY;
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
            $from = $from ?? now($timezone)->startOfYear()->toDateString();
            $to = $to ?? $today;
        }

        if ($from > $to) {
            $temp = $from;
            $from = $to;
            $to = $temp;
        }

        // 2. Resolve Company Base Currency
        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        // 3. Dispatch to Authoritative Domain Reporting Service
        $data = [
            'statement_type' => $statementType,
            'period' => [
                'from' => $from,
                'to' => $to,
                'date_adjusted' => $dateAdjusted,
            ],
            'currency' => $currencyCode,
        ];
        $sourceRoute = \Illuminate\Support\Facades\Route::has('accounting.profit-loss')
            ? route('accounting.profit-loss', absolute: false)
            : '/accounting/profit-loss';
        $isReconciled = true;

        if ($statementType === 'profit_and_loss') {
            $pnl = $this->financialReportService->getProfitAndLoss(
                startDate: $from,
                endDate: $to,
                context: $context
            );

            $data['summary'] = [
                'total_revenue' => (string) BigDecimal::of((string) ($pnl['total_revenue'] ?? 0))->toScale(2, RoundingMode::HalfUp),
                'total_cogs' => (string) BigDecimal::of((string) ($pnl['total_cogs'] ?? 0))->toScale(2, RoundingMode::HalfUp),
                'gross_profit' => (string) BigDecimal::of((string) ($pnl['gross_profit'] ?? 0))->toScale(2, RoundingMode::HalfUp),
                'total_operating_expenses' => (string) BigDecimal::of((string) ($pnl['total_operating_expenses'] ?? 0))->toScale(2, RoundingMode::HalfUp),
                'net_income' => (string) BigDecimal::of((string) ($pnl['net_income'] ?? 0))->toScale(2, RoundingMode::HalfUp),
            ];
            $data['top_revenues'] = array_slice($pnl['revenues'] ?? [], 0, 5);
            $data['top_expenses'] = array_slice($pnl['operating_expenses'] ?? [], 0, 5);
            $sourceRoute = \Illuminate\Support\Facades\Route::has('accounting.profit-loss')
                ? route('accounting.profit-loss', absolute: false)
                : '/accounting/profit-loss';

        } elseif ($statementType === 'balance_sheet') {
            $bs = $this->financialReportService->getBalanceSheet(
                asOfDate: $to,
                context: $context
            );

            $totalAssets = BigDecimal::of((string) ($bs['total_assets'] ?? 0))->toScale(2, RoundingMode::HalfUp);
            $totalLiab = BigDecimal::of((string) ($bs['total_liabilities'] ?? 0))->toScale(2, RoundingMode::HalfUp);
            $totalEquity = BigDecimal::of((string) ($bs['total_equity'] ?? 0))->toScale(2, RoundingMode::HalfUp);
            $liabAndEquity = $totalLiab->plus($totalEquity);

            $isReconciled = $totalAssets->compareTo($liabAndEquity) === 0;

            $data['summary'] = [
                'total_assets' => (string) $totalAssets,
                'total_liabilities' => (string) $totalLiab,
                'total_equity' => (string) $totalEquity,
                'total_liabilities_and_equity' => (string) $liabAndEquity,
                'is_balanced' => $isReconciled,
            ];
            $data['top_assets'] = array_slice($bs['assets'] ?? [], 0, 5);
            $data['top_liabilities'] = array_slice($bs['liabilities'] ?? [], 0, 5);
            $sourceRoute = \Illuminate\Support\Facades\Route::has('accounting.balance-sheet')
                ? route('accounting.balance-sheet', absolute: false)
                : '/accounting/balance-sheet';

        } elseif ($statementType === 'trial_balance') {
            $tb = $this->financialReportService->getTrialBalance(
                startDate: $from,
                endDate: $to,
                context: $context
            );

            $totalDebit = BigDecimal::of((string) ($tb['total_debit'] ?? 0))->toScale(2, RoundingMode::HalfUp);
            $totalCredit = BigDecimal::of((string) ($tb['total_credit'] ?? 0))->toScale(2, RoundingMode::HalfUp);
            $isReconciled = $totalDebit->compareTo($totalCredit) === 0;

            $data['summary'] = [
                'total_debit' => (string) $totalDebit,
                'total_credit' => (string) $totalCredit,
                'is_balanced' => $isReconciled,
                'account_count' => count($tb['accounts'] ?? []),
            ];
            $data['sample_accounts'] = array_slice($tb['accounts'] ?? [], 0, 8);
            $sourceRoute = \Illuminate\Support\Facades\Route::has('accounting.trial-balance')
                ? route('accounting.trial-balance', absolute: false)
                : '/accounting/trial-balance';
        }

        return ErpToolResult::ok(
            toolKey: 'accounting.statements.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: $data,
            sourceRefs: [$sourceRoute],
            warnings: $warnings,
            asOf: $to,
            reconciled: $isReconciled
        );
    }
}
