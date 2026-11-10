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
use App\Support\LedgerAmount;
use App\Services\Platform\CompanyContext;

/**
 * Authoritative, actor-explicit read facade for Invoice Allocations & Payment Breakdown.
 * Details each receipt, credit note, or reversal applied against a specific open item.
 */
class ReadInvoiceAllocations
{
    public function execute(
        AiPrincipal $principal,
        int $openItemId,
        ?string $asOf = null
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        ['to' => $asOfDate, 'timezone' => $timezone] = AiReportingPeriod::resolve($principal, null, $asOf);
        $warnings = [];

        $openItem = AccountOpenItem::forCompany($context)
            ->where('branch_id', $context->branchId)
            ->where('party_type', 'customer')->where('source_type', 'sale')
            ->where('document_date', '<=', $asOfDate)->find($openItemId);

        if (!$openItem) {
            return ErpToolResult::error(
                toolKey: 'receivables.invoice_allocations.v1',
                errorCode: 'ENTITY_NOT_FOUND',
                message: "Open item ID {$openItemId} not found in this company/branch.",
                scope: [
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'open_item_id' => $openItemId,
                ]
            );
        }

        // 3. Resolve Company Base Currency
        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        // 4. Query Allocations for this Open Item
        $counterparties = AccountOpenItem::forCompany($context)->where('branch_id', $context->branchId)
            ->where('party_type', 'customer')->where('party_id', $openItem->party_id);
        $allocationsQuery = AccountAllocation::forCompany($context)->where('allocation_date', '<=', $asOfDate)
            ->where(fn ($q) => $q
                ->where(fn ($debit) => $debit->where('debit_open_item_id', $openItemId)
                    ->whereIn('credit_open_item_id', (clone $counterparties)->select('id')))
                ->orWhere(fn ($credit) => $credit->where('credit_open_item_id', $openItemId)
                    ->whereIn('debit_open_item_id', (clone $counterparties)->select('id'))));

        $allocationCount = (clone $allocationsQuery)->count();
        $totalAllocatedUnits = LedgerAmount::units((clone $allocationsQuery)
            ->selectRaw('COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN allocated_amount ELSE -allocated_amount END), 0) AS net')
            ->value('net'));
        $allocations = $allocationsQuery->orderBy('allocation_date')->orderBy('id')->limit(50)->get();
        if ($allocationCount > $allocations->count()) {
            $warnings[] = 'Showing the first 50 allocations. The balance includes all authorized allocations through the effective date.';
        }

        // 5. Preload Contra Open Items
        $contraIds = $allocations->map(function ($a) use ($openItemId) {
            return (int) $a->debit_open_item_id === $openItemId ? (int) $a->credit_open_item_id : (int) $a->debit_open_item_id;
        })->unique();

        $contraItems = $counterparties->whereIn('id', $contraIds)->get()->keyBy('id');

        // Preload Sale if open item is an invoice
        $sale = $openItem->source_type === 'sale' && $openItem->source_id
            ? Sale::visibleIn($context)->find($openItem->source_id)
            : null;
        $customer = $openItem->party_id ? Customer::where('company_id', $principal->companyId)->find($openItem->party_id) : null;

        // 6. Assemble Allocation Records
        $allocationRows = [];
        foreach ($allocations as $a) {
            $isReversal = $a->reversal_of_id !== null;
            $amountUnits = LedgerAmount::units($a->allocated_amount);

            $contraId = (int) $a->debit_open_item_id === $openItemId ? (int) $a->credit_open_item_id : (int) $a->debit_open_item_id;
            $contra = $contraItems->get($contraId);

            $allocationRows[] = [
                'allocation_id' => $a->id,
                'allocation_no' => $a->allocation_no,
                'allocation_date' => $a->allocation_date?->toDateString() ?? $a->created_at?->toDateString(),
                'allocated_amount' => LedgerAmount::decimal($amountUnits),
                'is_reversal' => $isReversal,
                'contra_document_no' => $contra?->document_no ?? 'Receipt/Adjustment',
                'contra_source_type' => $contra?->source_type ?? 'payment',
                'contra_document_date' => $contra?->document_date?->toDateString(),
            ];
        }

        $originalUnits = LedgerAmount::units($openItem->original_amount);
        $remainingUnits = max(0, $originalUnits - $totalAllocatedUnits);

        $invoiceNo = $sale?->reference_no ?: $openItem->document_no;

        return ErpToolResult::ok(
            toolKey: 'receivables.invoice_allocations.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'open_item_id' => $openItemId,
            ],
            data: [
                'timezone' => $timezone,
                'invoice' => [
                    'open_item_id' => $openItem->id,
                    'invoice_number' => $invoiceNo,
                    'customer_id' => $openItem->party_id,
                    'customer_name' => $customer?->name ?? 'Customer',
                    'document_date' => $openItem->document_date->toDateString(),
                    'due_date' => $openItem->due_date->toDateString(),
                    'original_amount' => LedgerAmount::decimal($originalUnits),
                    'total_allocated' => LedgerAmount::decimal($totalAllocatedUnits),
                    'remaining_balance' => LedgerAmount::decimal($remainingUnits),
                    'currency' => $currencyCode,
                    'status' => $remainingUnits <= 0 ? 'paid' : ($totalAllocatedUnits > 0 ? 'partially_paid' : 'unpaid'),
                ],
                'allocations' => $allocationRows,
                'allocation_count' => $allocationCount,
            ],
            sourceRefs: [
                route('accounting.ageing', ['party_type' => 'customer', 'as_of_date' => $asOfDate], absolute: false),
            ],
            warnings: $warnings,
            asOf: $asOfDate,
            reconciled: true,
            rowCount: count($allocationRows) + 1,
        );
    }
}
