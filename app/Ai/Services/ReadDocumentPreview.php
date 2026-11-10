<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Route;

/**
 * Authoritative read facade for Permitted Document Previews (P4).
 * Generates secure, scope-bound preview references and view links.
 */
class ReadDocumentPreview
{
    public function execute(
        AiPrincipal $principal,
        string $documentType, // 'sale', 'purchase', 'invoice'
        ?string $referenceNo = null,
        ?int $documentId = null
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        if ($documentType === 'sale' || $documentType === 'invoice') {
            $query = Sale::visibleIn($context);
            if ($documentId !== null) {
                $query->where('id', $documentId);
            } elseif ($referenceNo !== null && trim($referenceNo) !== '') {
                $query->where('reference_no', trim($referenceNo));
            } else {
                return ErpToolResult::error(
                    'documents.preview.v1',
                    'Either document_id or reference_no must be provided.',
                    'AI_INPUT_VALIDATION_FAILED'
                );
            }

            $sale = $query->first();
            if (!$sale) {
                return ErpToolResult::error(
                    'documents.preview.v1',
                    "Sale document not found or access denied in this branch context.",
                    'AI_TOOL_FORBIDDEN'
                );
            }

            $customer = Customer::find($sale->customer_id);
            $viewUrl = Route::has('sale.invoice') ? route('sale.invoice', $sale->id, absolute: false) : "/sales/{$sale->id}";

            $data = [
                'document_type' => 'sale_invoice',
                'id' => (int) $sale->id,
                'reference_no' => $sale->reference_no,
                'date' => substr((string) $sale->created_at, 0, 10),
                'party' => [
                    'type' => 'customer',
                    'name' => $customer?->name ?? 'Unknown Customer',
                    'phone' => $customer?->phone_number ?? '',
                ],
                'items_count' => (int) ($sale->item ?? 1),
                'total_qty' => (float) ($sale->total_qty ?? 0),
                'grand_total' => (string) BigDecimal::of((string) ($sale->grand_total ?? 0))->toScale(2, RoundingMode::HalfUp),
                'paid_amount' => (string) BigDecimal::of((string) ($sale->paid_amount ?? 0))->toScale(2, RoundingMode::HalfUp),
                'status' => $sale->sale_status == 1 ? 'completed' : 'pending',
                'view_link' => $viewUrl,
            ];

            return ErpToolResult::ok(
                toolKey: 'documents.preview.v1',
                scope: [
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                ],
                data: $data,
                sourceRefs: [$viewUrl],
                warnings: [],
                asOf: now()->toDateString(),
                reconciled: true,
                rowCount: 1
            );
        }

        if ($documentType === 'purchase') {
            $query = Purchase::where('company_id', $principal->companyId)
                ->where(function ($q) use ($principal) {
                    $q->where('branch_id', $principal->branchId)->orWhereNull('branch_id');
                });

            if ($documentId !== null) {
                $query->where('id', $documentId);
            } elseif ($referenceNo !== null && trim($referenceNo) !== '') {
                $query->where('reference_no', trim($referenceNo));
            } else {
                return ErpToolResult::error(
                    'documents.preview.v1',
                    'Either document_id or reference_no must be provided.',
                    'AI_INPUT_VALIDATION_FAILED'
                );
            }

            $purchase = $query->first();
            if (!$purchase) {
                return ErpToolResult::error(
                    'documents.preview.v1',
                    "Purchase document not found or access denied in this branch context.",
                    'AI_TOOL_FORBIDDEN'
                );
            }

            $supplier = Supplier::find($purchase->supplier_id);
            $viewUrl = Route::has('purchases.index') ? route('purchases.index', absolute: false) : "/purchases/{$purchase->id}";

            $data = [
                'document_type' => 'purchase_order',
                'id' => (int) $purchase->id,
                'reference_no' => $purchase->reference_no,
                'date' => substr((string) $purchase->created_at, 0, 10),
                'party' => [
                    'type' => 'supplier',
                    'name' => $supplier?->name ?? 'Unknown Supplier',
                ],
                'items_count' => (int) ($purchase->item ?? 1),
                'grand_total' => (string) BigDecimal::of((string) ($purchase->grand_total ?? 0))->toScale(2, RoundingMode::HalfUp),
                'paid_amount' => (string) BigDecimal::of((string) ($purchase->paid_amount ?? 0))->toScale(2, RoundingMode::HalfUp),
                'status' => $purchase->status == 1 ? 'received' : 'pending',
                'view_link' => $viewUrl,
            ];

            return ErpToolResult::ok(
                toolKey: 'documents.preview.v1',
                scope: [
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                ],
                data: $data,
                sourceRefs: [$viewUrl],
                warnings: [],
                asOf: now()->toDateString(),
                reconciled: true,
                rowCount: 1
            );
        }

        return ErpToolResult::error(
            'documents.preview.v1',
            "Unsupported document type [{$documentType}].",
            'AI_INPUT_VALIDATION_FAILED'
        );
    }
}

