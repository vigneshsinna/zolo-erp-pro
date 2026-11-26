<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Supplier;
use App\Services\Accounting\FinancialReportService;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;

/**
 * Authoritative, actor-explicit read facade for Supplier Payables / AP Aging.
 * Does NOT modify global Auth state; passes actor explicitly to accounting services.
 */
class ReadPayablesSummary
{
    public function __construct(
        protected FinancialReportService $financialReportService
    ) {}

    /**
     * Execute the supplier payables query within the principal's explicit company context.
     *
     * @param AiPrincipal $principal The authenticated actor & company tenancy
     * @param string|null $asOf Effective calculation date (YYYY-MM-DD)
     * @param int $limit Number of top due suppliers to return (1..20)
     */
    public function execute(AiPrincipal $principal, ?string $asOf = null, int $limit = 5): ErpToolResult
    {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        ['to' => $asOf, 'timezone' => $timezone] = AiReportingPeriod::resolve($principal, null, $asOf);

        $report = $this->financialReportService->ageing(
            context: $context,
            partyType: 'supplier',
            asOf: $asOf,
            filters: [],
            actorId: $principal->actorId
        );

        $asOfDate = $report['as_of_date'];
        $rawRows = $report['rows'] ?? [];
        $reconciliation = $report['reconciliation'] ?? [];

        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        $supplierTotals = [];
        $grandTotalUnits = BigDecimal::zero();

        foreach ($rawRows as $row) {
            $openItem = $row['item'];
            $openAmount = BigDecimal::of((string) $row['open_amount']);
            $supplierId = (int) $openItem->party_id;
            $overdueDays = (int) ($row['days_overdue'] ?? 0);

            if (!isset($supplierTotals[$supplierId])) {
                $supplierTotals[$supplierId] = [
                    'supplier_id' => $supplierId,
                    'name' => 'Supplier #' . $supplierId,
                    'total_open_balance' => BigDecimal::zero(),
                    'invoice_count' => 0,
                    'max_days_overdue' => 0,
                ];
            }

            $supplierTotals[$supplierId]['total_open_balance'] = $supplierTotals[$supplierId]['total_open_balance']->plus($openAmount);
            $supplierTotals[$supplierId]['invoice_count']++;
            if ($overdueDays > $supplierTotals[$supplierId]['max_days_overdue']) {
                $supplierTotals[$supplierId]['max_days_overdue'] = $overdueDays;
            }

            $grandTotalUnits = $grandTotalUnits->plus($openAmount);
        }

        if (!empty($supplierTotals)) {
            $supplierIds = array_keys($supplierTotals);
            $supplierModels = Supplier::whereIn('id', $supplierIds)
                ->where('company_id', $principal->companyId)
                ->get()
                ->keyBy('id');

            foreach ($supplierTotals as $id => $data) {
                if (isset($supplierModels[$id])) {
                    $supplierTotals[$id]['name'] = $supplierModels[$id]->name;
                }
            }
        }

        usort($supplierTotals, function ($a, $b) {
            return $b['total_open_balance']->compareTo($a['total_open_balance']);
        });

        $topSuppliers = array_slice($supplierTotals, 0, max(1, min(20, $limit)));
        $topSuppliersFormatted = array_map(function ($sup) {
            return [
                'supplier_id' => $sup['supplier_id'],
                'name' => $sup['name'],
                'open_balance' => (string) $sup['total_open_balance'],
                'invoice_count' => $sup['invoice_count'],
                'max_days_overdue' => $sup['max_days_overdue'],
            ];
        }, $topSuppliers);

        $difference = BigDecimal::of((string) ($reconciliation['difference'] ?? '0.0000'));
        $isReconciled = isset($reconciliation['difference']) && $difference->isZero();

        $warnings = [];
        if (!$isReconciled && isset($reconciliation['difference'])) {
            $warnings[] = "Accounts Payable subledger differs from control account by {$reconciliation['difference']} {$currencyCode}. Review unallocated payments or journal entries.";
        }

        $sourceRoute = route('report.supplierDueByDate', absolute: false) . "?end_date={$asOfDate}";

        return ErpToolResult::ok(
            toolKey: 'payables.summary.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'as_of_date' => $asOfDate,
                'currency' => $currencyCode,
                'timezone' => $timezone,
                'total_outstanding' => (string) $grandTotalUnits,
                'supplier_count_with_dues' => count($supplierTotals),
                'top_suppliers' => $topSuppliersFormatted,
                'reconciliation' => [
                    'control_account_balance' => $reconciliation['control_balance'] ?? null,
                    'subledger_total' => $reconciliation['open_item_balance'] ?? null,
                    'difference' => (string) $difference,
                    'is_reconciled' => $isReconciled,
                ],
            ],
            sourceRefs: [$sourceRoute],
            warnings: $warnings,
            asOf: $asOfDate,
            reconciled: $isReconciled
        );
    }
}

