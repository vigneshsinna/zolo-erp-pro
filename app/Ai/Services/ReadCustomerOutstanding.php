<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Currency;
use App\Services\Accounting\FinancialReportService;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;

/**
 * Authoritative, actor-explicit read facade for Customer Outstanding / AR Aging.
 * Does NOT modify global Auth state; passes actor explicitly to accounting services.
 */
class ReadCustomerOutstanding
{
    public function __construct(
        protected FinancialReportService $financialReportService
    ) {}

    /**
     * Execute the customer outstanding query within the principal's explicit company context.
     *
     * @param AiPrincipal $principal The authenticated actor & company tenancy
     * @param string|null $asOf Effective calculation date (YYYY-MM-DD)
     * @param int $limit Number of top due customers to return (1..20)
     */
    public function execute(AiPrincipal $principal, ?string $asOf = null, int $limit = 5): ErpToolResult
    {
        // 1. Establish CompanyContext
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        // 2. Call authoritative ERP financial reporting service with explicit actor ID
        $report = $this->financialReportService->ageing(
            context: $context,
            partyType: 'customer',
            asOf: $asOf,
            filters: [],
            actorId: $principal->actorId
        );

        $asOfDate = $report['as_of_date'];
        $rawRows = $report['rows'] ?? [];
        $reconciliation = $report['reconciliation'] ?? [];

        // 3. Resolve Company Base Currency
        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        // 4. Aggregate open items per party
        $customerTotals = [];
        $grandTotalUnits = BigDecimal::zero();

        foreach ($rawRows as $row) {
            $partyName = $row['party_name'] ?? 'Unknown Customer';
            $amountDec = (string) ($row['open_amount'] ?? '0.00');
            $partyId = $row['item']->party_id ?? 0;

            $amount = BigDecimal::of($amountDec);
            $grandTotalUnits = $grandTotalUnits->plus($amount);

            if (!isset($customerTotals[$partyId])) {
                $customerTotals[$partyId] = [
                    'customer_id' => $partyId,
                    'customer_name' => $partyName,
                    'outstanding_amount' => BigDecimal::zero(),
                    'invoice_count' => 0,
                    'max_days_overdue' => 0,
                ];
            }

            $customerTotals[$partyId]['outstanding_amount'] = $customerTotals[$partyId]['outstanding_amount']->plus($amount);
            $customerTotals[$partyId]['invoice_count']++;
            $customerTotals[$partyId]['max_days_overdue'] = max(
                $customerTotals[$partyId]['max_days_overdue'],
                (int) ($row['days'] ?? 0)
            );
        }

        // 5. Sort customers descending by total outstanding amount
        uasort($customerTotals, function ($a, $b) {
            return $b['outstanding_amount']->compareTo($a['outstanding_amount']);
        });

        // 6. Slice to requested limit (bounded 1..20)
        $limit = max(1, min(20, $limit));
        $topCustomers = [];

        foreach (array_slice($customerTotals, 0, $limit) as $cust) {
            $topCustomers[] = [
                'customer_id' => $cust['customer_id'],
                'customer_name' => $cust['customer_name'],
                'outstanding_amount' => $this->amount($cust['outstanding_amount']),
                'invoice_count' => $cust['invoice_count'],
                'max_days_overdue' => $cust['max_days_overdue'],
            ];
        }

        // 7. Robust Reconciliation & Warning Verification
        $hasReconciliation = isset($reconciliation['difference']);
        $difference = $hasReconciliation ? (string) $reconciliation['difference'] : null;
        $isReconciled = $hasReconciliation && BigDecimal::of($difference)->isZero();
        $warnings = [];

        if (!$hasReconciliation) {
            $warnings[] = 'Reconciliation data unavailable for this calculation period.';
        } elseif (!$isReconciled) {
            $warnings[] = "Control account variance detected: open items differ from AR control ledger by {$difference}.";
        }

        $sourceRefs = [
            route('accounting.ageing', ['party_type' => 'customer', 'as_of_date' => $asOfDate], absolute: false),
        ];

        return ErpToolResult::ok(
            toolKey: 'receivables.outstanding.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'total_outstanding' => $this->amount($grandTotalUnits),
                'total_customers_with_dues' => count($customerTotals),
                'top_customers' => $topCustomers,
                'currency' => $currencyCode,
                'reconciliation' => [
                    'is_reconciled' => $isReconciled,
                    'control_balance' => $reconciliation['control_balance'] ?? null,
                    'open_item_balance' => $reconciliation['open_item_balance'] ?? null,
                    'difference' => $difference,
                ],
            ],
            sourceRefs: $sourceRefs,
            warnings: $warnings,
            asOf: $asOfDate,
            reconciled: $isReconciled
        );
    }

    private function amount(BigDecimal $value): string
    {
        $value = $value->strippedOfTrailingZeros();

        return (string) $value->toScale(max(2, $value->getScale()));
    }
}
