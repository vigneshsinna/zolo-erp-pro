<?php

namespace App\Services\Commercial;

use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Accounting\JournalEntry;
use App\Models\Inventory\StockMovement;
use App\Services\Accounting\AccountingPostingService;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialReversalService
{
    public function reverse(Sale|Purchase $document, string $date, string $reason, ?CompanyContext $context = null): Sale|Purchase
    {
        $guard = app(CompanyWriteGuard::class);
        $context = $guard->context($context, auth()->id());
        $kind = $document instanceof Sale ? 'sale' : 'purchase';
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-delete' : 'purchases-delete', $context, auth()->id());
        if (strlen(trim($reason)) < 3 || strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason between 3 and 500 characters.']);
        }
        return DB::transaction(function () use ($document, $guard, $context, $date, $reason, $kind) {
            $guard->begin($context, $date);
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $document = $document::visibleIn($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->reversed_at) {
                return $document;
            }
            if ($document instanceof Purchase && (int) $document->status === 4
                && \Illuminate\Support\Facades\Schema::hasTable('commercial_order_allocations')
                && DB::table('commercial_order_allocations as a')->join('commercial_order_items as i', 'i.id', '=', 'a.order_item_id')
                    ->where('a.company_id', $context->companyId)->where('i.order_kind', 'purchase')->where('i.order_id', $document->id)
                    ->whereNull('a.released_at')->whereColumn('a.qty_base', '>', 'a.returned_qty_base')->exists()) {
                throw ValidationException::withMessages(['order' => 'This order has fulfilments. Cancel its remaining quantity through Orders instead.']);
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn($kind === 'sale' ? 'returns' : 'return_purchases', 'posted_at')
                && DB::table($kind === 'sale' ? 'returns' : 'return_purchases')->where('company_id', $context->companyId)
                    ->where($kind.'_id', $document->id)->whereNotNull('posted_at')->exists()) {
                throw ValidationException::withMessages(['document' => 'A source with posted notes cannot be reversed again. Post a reviewed financial adjustment instead.']);
            }
            if ($date < $document->created_at->toDateString()) {
                throw ValidationException::withMessages(['business_date' => 'Reverse or edit on or after the document date ('.$document->created_at->format('d-m-Y').').']);
            }
            if ((int) $document->branch_id !== $context->branchId || !$document->financial_year_id) {
                throw ValidationException::withMessages(['document' => 'Legacy documents require reviewed migration before reversal.']);
            }
            // All settlement journals reverse too; original payments stay as immutable source history.
            $paymentIds = $document->payments()->forCompany($context)->pluck('id');
            $journals = JournalEntry::forCompany($context)->where(function ($q) use ($kind, $document, $paymentIds) {
                $q->where(fn ($q) => $q->where('reference_type', $kind)->where('reference_id', $document->id))
                    ->orWhere(fn ($q) => $q->where('reference_type', 'payment')->whereIn('reference_id', $paymentIds));
            })->whereNull('reversal_of_id')->orderByDesc('id')->lockForUpdate()->get();
            foreach ($journals as $journal) {
                app(AccountingPostingService::class)->reverse($journal->id, $date, $reason, $context);
            }
            $movements = StockMovement::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
                ->where('source_type', $kind)->where('source_id', $document->id)->where('status', 'posted')
                ->whereNull('reversal_of_id')->orderByDesc('id')->lockForUpdate()->get();
            foreach ($movements as $movement) {
                if ($movement->projection_mode !== 'applied') {
                    throw ValidationException::withMessages(['document' => 'Shadow stock requires reviewed migration before reversal.']);
                }
                app(InventoryMovementService::class)->reverse($movement, $reason, auth()->id(), $date);
            }
            app(MaterialDocumentService::class)->release($document, $context);
            app(OrderLifecycleService::class)->release($document, $context);
            $document->forceFill(['reversed_at' => now(), 'reversal_reason' => $reason])->save();
            // A reversed job work service bill frees its GRN, so the corrected bill can be booked against it.
            $receiptId = $document instanceof Purchase ? (int) ($document->attributes_json['job_work_receipt_id'] ?? 0) : 0;
            if ($receiptId) {
                $receipt = DB::table('job_work_receipts')->where('company_id', $context->companyId)->where('id', $receiptId)
                    ->where('service_purchase_id', $document->id)->first();
                if ($receipt) {
                    $details = json_decode($receipt->details_json ?? '{}', true) ?: [];
                    unset($details['processing_cost']);
                    DB::table('job_work_receipts')->where('id', $receiptId)->update(['service_purchase_id' => null,
                        'details_json' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
            }
            app(\App\Services\Tax\GstProjectionService::class)->reverse($document, $kind, $date, $context);
            app(CommercialApplicationService::class)->audit('reversed', $document, $context, auth()->id(), ['date' => $date, 'reason' => $reason]);
            return $document;
        });
    }

    public function replace(Sale|Purchase $document, array $data, string $key, string $date, string $reason, ?CompanyContext $context = null): Sale|Purchase
    {
        $context = app(CompanyWriteGuard::class)->context($context, auth()->id());
        return DB::transaction(function () use ($document, $data, $key, $date, $reason, $context) {
            $kind = $document instanceof Sale ? 'sale' : 'purchase';
            $document = $document::visibleIn($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document instanceof Purchase && !empty($document->attributes_json['job_work_receipt_id'])) {
                throw ValidationException::withMessages(['document' => 'This is a job work service bill. Edit it from its job work order (Job Work GRN › Edit bill) so the processing cost stays on the processed goods.']);
            }
            if ($document->reversed_at) {
                $retry = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $key)
                    ->where('response_type', $kind)->value('response_ref');
                if (!$retry || !$document::visibleIn($context)->whereKey($retry)->where('replaces_id', $document->id)->exists()) {
                    throw ValidationException::withMessages(['document' => 'This document has already been reversed. Edit its active replacement instead.']);
                }
            }
            // Include the source and reason in the retry contract to prevent reuse for another replacement.
            $data['replacement_reason'] = [$date, $reason];
            $this->reverse($document, $date, $reason, $context);
            $replacement = app(CommercialApplicationService::class)->create($kind, $data, $key, auth()->id(), $context, $document->id);
            return $replacement;
        });
    }
}
