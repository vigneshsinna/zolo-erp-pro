<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Accounting\AccountAllocation;
use App\Models\Accounting\AccountOpenItem;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\Accounting\FinancialReportService;
use App\Support\LedgerAmount;
use App\Services\Platform\CompanyContext;
use Carbon\CarbonImmutable;

/**
 * Authoritative, actor-explicit read facade for Customer Invoices & Detailed Open Items.
 * Reconstructs exact invoice numbers, document dates, due dates, allocations, and remaining balances.
 */
class ReadCustomerInvoices
{
    public function __construct(
        protected FinancialReportService $financialReportService
    ) {}

    /**
     * Execute invoice-level receivables query.
     *
     * @param AiPrincipal $principal The authenticated actor & company tenancy
     * @param int|null $customerId Target customer ID (optional)
     * @param string|null $asOf Effective calculation date (YYYY-MM-DD)
     * @param string $status Filter: 'open', 'all', 'overdue'
     * @param int $limit Maximum invoice rows to return (1..50)
     */
    public function execute(
        AiPrincipal $principal,
        ?int $customerId = null,
        ?string $asOf = null,
        string $status = 'open',
        int $limit = 20
    ): ErpToolResult {
        $limit = max(1, min(50, $limit));
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        // 1. Resolve and Validate As-Of Date against Financial Year (AI-002)
        $requestedAsOf = $asOf;
        ['to' => $asOf, 'timezone' => $timezone] = AiReportingPeriod::resolve($principal, null, $asOf);
        $warnings = [];

        // 2. Validate Customer if provided
        $customer = null;
        if ($customerId !== null) {
            $customer = Customer::where('company_id', $principal->companyId)->find($customerId);
            if (!$customer) {
                return ErpToolResult::error(
                    toolKey: 'receivables.invoices.v1',
                    errorCode: 'ENTITY_NOT_FOUND',
                    message: "Customer ID {$customerId} not found in this company.",
                    scope: [
                        'company_id' => $principal->companyId,
                        'branch_id' => $principal->branchId,
                        'customer_id' => $customerId,
                    ]
                );
            }
        }

        // 3. Query Open Items for Customer(s)
        $itemsQuery = AccountOpenItem::forCompany($context)
            ->where('branch_id', $context->branchId)
            ->where('party_type', 'customer')
            ->whereDate('document_date', '<=', $asOf);

        if ($customerId !== null) {
            $itemsQuery->where('party_id', $customerId);
        }

        $items = $itemsQuery->orderBy('due_date')->get();

        // 4. Resolve Base Currency
        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        if ($items->isEmpty()) {
            return ErpToolResult::ok(
                toolKey: 'receivables.invoices.v1',
                scope: [
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                    'customer_id' => $customerId,
                ],
                data: [
                    'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name] : null,
                    'requested_as_of' => $requestedAsOf,
                    'effective_as_of' => $asOf,
                    'date_adjusted' => false,
                    'timezone' => $timezone,
                    'currency' => $currencyCode,
                    'totals' => [
                        'original_amount' => '0.0000',
                        'allocated_amount' => '0.0000',
                        'outstanding_amount' => '0.0000',
                        'overdue_amount' => '0.0000',
                        'not_yet_due_amount' => '0.0000',
                    ],
                    'invoice_count' => 0,
                    'invoices' => [],
                    'other_open_items' => [],
                    'has_more' => false,
                ],
                sourceRefs: [route('accounting.ageing', ['party_type' => 'customer', 'as_of_date' => $asOf], absolute: false)],
                warnings: $customer
                    ? ["No open items found for {$customer->name} as of {$asOf}."]
                    : ["No open customer items found as of {$asOf}."],
                asOf: $asOf,
                reconciled: true
            );
        }

        // 5. Query Historical Allocations up to As-Of Date
        $allocations = AccountAllocation::forCompany($context)
            ->whereDate('allocation_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereIn('debit_open_item_id', $items->pluck('id'))->orWhereIn('credit_open_item_id', $items->pluck('id')))
            ->get();

        $effects = [];
        foreach ($allocations as $allocation) {
            $amount = LedgerAmount::units($allocation->allocated_amount) * ($allocation->reversal_of_id ? -1 : 1);
            $effects[$allocation->debit_open_item_id] = ($effects[$allocation->debit_open_item_id] ?? 0) - $amount;
            $effects[$allocation->credit_open_item_id] = ($effects[$allocation->credit_open_item_id] ?? 0) + $amount;
        }

        // 6. Preload Related Sales and Customers
        $saleIds = $items->where('source_type', 'sale')->pluck('source_id')->filter()->unique();
        $sales = Sale::forCompany($context)->whereIn('id', $saleIds)->get()->keyBy('id');

        $customerIds = $items->pluck('party_id')->unique();
        $customers = Customer::where('company_id', $principal->companyId)->whereIn('id', $customerIds)->get()->keyBy('id');

        // 7. Assemble Structured Invoices and Separate Non-Invoice Open Items (AI-007)
        $invoices = [];
        $otherOpenItems = [];
        $totalOriginalUnits = 0;
        $totalAllocatedUnits = 0;
        $totalOutstandingUnits = 0;
        $totalOverdueUnits = 0;
        $totalNotYetDueUnits = 0;

        foreach ($items as $item) {
            $originalUnits = LedgerAmount::units($item->original_amount);
            $netEffect = $effects[$item->id] ?? 0;
            $openUnits = $originalUnits + $netEffect;

            if ($openUnits <= 0 && $status === 'open') {
                continue; // Fully settled as of this date
            }

            $allocatedUnits = max(0, -$netEffect);
            $daysOverdue = max(0, (int) CarbonImmutable::parse($item->due_date)->diffInDays(CarbonImmutable::parse($asOf), false));
            $isOverdue = $daysOverdue > 0 && $openUnits > 0;

            if ($status === 'overdue' && !$isOverdue) {
                continue;
            }

            $totalOriginalUnits += $originalUnits;
            $totalAllocatedUnits += $allocatedUnits;
            $totalOutstandingUnits += $openUnits;

            if ($isOverdue) {
                $totalOverdueUnits += $openUnits;
            } else {
                $totalNotYetDueUnits += $openUnits;
            }

            $sale = $item->source_type === 'sale' ? $sales->get($item->source_id) : null;
            $invoiceNo = $sale?->reference_no ?: $item->document_no;
            $party = $customers->get($item->party_id);

            $itemStatus = 'unpaid';
            if ($openUnits <= 0) {
                $itemStatus = 'paid';
            } elseif ($allocatedUnits > 0) {
                $itemStatus = $isOverdue ? 'partially_paid_overdue' : 'partially_paid';
            } elseif ($isOverdue) {
                $itemStatus = 'overdue';
            }

            $row = [
                'open_item_id' => $item->id,
                'invoice_id' => $item->source_id,
                'invoice_number' => $invoiceNo,
                'party_id' => $item->party_id,
                'customer_name' => $party?->name ?? 'Unknown Customer',
                'document_type' => $item->source_type === 'sale' ? 'tax_invoice' : $item->source_type,
                'invoice_date' => $item->document_date->toDateString(),
                'due_date' => $item->due_date->toDateString(),
                'original_amount' => LedgerAmount::decimal($originalUnits),
                'allocated_amount' => LedgerAmount::decimal($allocatedUnits),
                'outstanding_amount' => LedgerAmount::decimal($openUnits),
                'days_overdue' => $daysOverdue,
                'is_overdue' => $isOverdue,
                'status' => $itemStatus,
                'source_ref' => route('sales.index', ['reference_no' => $invoiceNo], absolute: false),
            ];

            if ($item->source_type === 'sale') {
                $invoices[] = $row;
            } else {
                $otherOpenItems[] = $row;
            }
        }

        $sourceRefs = [
            route('accounting.ageing', ['party_type' => 'customer', 'as_of_date' => $asOf], absolute: false),
        ];

        return ErpToolResult::ok(
            toolKey: 'receivables.invoices.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'customer_id' => $customerId,
            ],
            data: [
                'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name] : null,
                'requested_as_of' => $requestedAsOf,
                'effective_as_of' => $asOf,
                'date_adjusted' => false,
                'timezone' => $timezone,
                'currency' => $currencyCode,
                'totals' => [
                    'original_amount' => LedgerAmount::decimal($totalOriginalUnits),
                    'allocated_amount' => LedgerAmount::decimal($totalAllocatedUnits),
                    'outstanding_amount' => LedgerAmount::decimal($totalOutstandingUnits),
                    'overdue_amount' => LedgerAmount::decimal($totalOverdueUnits),
                    'not_yet_due_amount' => LedgerAmount::decimal($totalNotYetDueUnits),
                ],
                'invoice_count' => count($invoices),
                'invoices' => array_slice($invoices, 0, $limit),
                'other_open_items' => array_slice($otherOpenItems, 0, $limit),
                'has_more' => count($invoices) > $limit,
            ],
            sourceRefs: $sourceRefs,
            warnings: $warnings,
            asOf: $asOf,
            reconciled: true
        );
    }
}
