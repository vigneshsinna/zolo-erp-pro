<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Purchase;
use App\Services\Platform\CompanyContext;

/**
 * Potential duplicate supplier invoice number lookup. This does not make
 * the final accounting decision: the supplier identity, invoice date and
 * document history still need to be reviewed.
 */
final class ReadDuplicatePurchaseInvoice
{
    public function execute(AiPrincipal $principal, string $invoiceNumber): ErpToolResult
    {
        $invoiceNumber = trim($invoiceNumber);
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        $matches = Purchase::visibleIn($context)
            ->where('purchases.branch_id', $context->branchId)
            ->where('purchases.financial_year_id', $context->financialYearId)
            ->where('purchases.supplier_invoice_no', $invoiceNumber)
            ->with(['supplier' => fn ($query) => $query->forCompany($context)])
            ->orderByDesc('purchases.id')
            ->limit(21)
            ->get(['purchases.id', 'purchases.reference_no', 'purchases.supplier_invoice_no',
                'purchases.supplier_id', 'purchases.posted_at', 'purchases.reversed_at',
                'purchases.company_id', 'purchases.warehouse_id', 'purchases.branch_id',
                'purchases.financial_year_id']);

        $rows = $matches->take(20)->map(static fn (Purchase $purchase): array => [
            'id' => $purchase->id,
            'reference_no' => $purchase->reference_no,
            'supplier' => $purchase->supplier?->name,
            'is_posted' => $purchase->posted_at !== null,
            'is_reversed' => $purchase->reversed_at !== null,
        ])->all();

        return ErpToolResult::ok(
            toolKey: 'erp_checks.duplicate_purchase',
            scope: [
                'company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId,
            ],
            data: [
                'supplier_invoice_no' => $invoiceNumber,
                'potential_match_count' => count($matches) > 20 ? '20+' : count($matches),
                'matches' => array_values($rows),
                'search_scope' => 'active branch and financial year',
            ],
            sourceRefs: [],
            warnings: ['This is a possible invoice-number match, not proof of duplicate posting. Confirm the supplier and document before recording a bill.'],
            asOf: now()->toDateString(),
            rowCount: count($rows),
        );
    }
}
