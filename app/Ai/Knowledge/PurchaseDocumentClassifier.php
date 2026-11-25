<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

/**
 * Safe deterministic classification of explicit purchase context. Ambiguous language
 * is NOT forced into a stock bill; higher-level AI may request clarification.
 */
final class PurchaseDocumentClassifier
{
    public function classify(string $utterance, array $confirmedFacts = []): array
    {
        $text = mb_strtolower(trim($utterance));

        if (!empty($confirmedFacts['route_type'])) {
            $kind = (string) $confirmedFacts['route_type'];
        } else {
            $kind = match (true) {
                ($confirmedFacts['job_work_receipt_id'] ?? null) !== null
                    || (bool) preg_match('/\b(?:job[\s-]?work|dyeing|processing charges?|subcontract)\b/u', $text)
                        && (bool) preg_match('/\b(?:bill|invoice|charges?)\b/u', $text) => 'job_work_processing_bill',
                ($confirmedFacts['receipt_status'] ?? null) === 'not_received'
                    || (bool) preg_match('/\b(?:invoice before goods|before receipt|goods not received|not yet received)\b/u', $text) => 'invoice_before_goods',
                !empty($confirmedFacts['grn_id']) => 'grn_linked_bill',
                !empty($confirmedFacts['purchase_order_id']) && (bool) preg_match('/\b(?:grn|goods received note|receive goods)\b/u', $text) => 'grn',
                !empty($confirmedFacts['purchase_order_id']) => 'po_linked_bill',
                (bool) preg_match('/\b(?:purchase order|create po|raise po|order from)\b/u', $text) => 'purchase_order',
                (bool) preg_match('/\b(?:electricity|rent|consulting fee|maintenance charge|office expense|expense voucher)\b/u', $text) => 'expense_bill',
                (bool) preg_match('/\b(?:service bill|service invoice|professional fee|consultancy bill)\b/u', $text) => 'service_bill',
                (bool) preg_match('/\b(?:direct supplier bill|direct purchase|without po|no po|received goods|goods bill|stock purchase|material bill|supplier invoice for stock)\b/u', $text) => 'goods_bill',
                default => 'ambiguous',
            };
        }

        $reason = match ($kind) {
            'purchase_order' => 'Use a reviewed purchase-order proposal; no purchase has been posted.',
            'goods_bill' => 'Use the existing goods-purchase preview and stock/GRN validation; no PO is required for direct received purchases.',
            'grn_linked_bill' => 'Settle posted GRN accrual with zero secondary stock receipt.',
            'po_linked_bill' => 'Consume open PO lines with verified receipt status.',
            'grn' => 'Record physical Goods Received Note against supplier/PO.',
            'invoice_before_goods' => 'Invoice-before-receipt posting is unsupported; hold as draft or post GRN once goods arrive.',
            'service_bill' => 'A service-only catalog item and an authorised branch accounting location are required; no stock receipt.',
            'job_work_processing_bill' => 'Requires a verified, eligible unbilled job-work receipt; generic goods bill is prohibited.',
            'expense_bill' => 'Expense-account payable requires a separately reviewed non-stock accounting capability; unsupported by this classifier.',
            default => 'Clarify purchase order, direct goods purchase, GRN-linked bill, service bill, expense or job-work bill before creating a proposal.',
        };

        return [
            'classification' => $kind,
            'candidate_action' => match ($kind) {
                'purchase_order' => 'purchase_order.create.v1',
                'goods_bill', 'grn_linked_bill', 'po_linked_bill' => 'purchase_bill.create.v1',
                'grn' => 'grn.create.v1',
                'service_bill' => 'purchase_bill.service.create.v1',
                default => null,
            },
            'po_required' => in_array($kind, ['po_linked_bill'], true),
            'supported_without_clarification' => in_array($kind, ['purchase_order', 'goods_bill', 'grn_linked_bill', 'po_linked_bill', 'grn', 'service_bill'], true),
            'needs_gst_clarification' => (bool) preg_match('/\bnon[\s-]?gst\b/u', $text)
                && empty($confirmedFacts['tax_treatment']),
            'source' => 'deterministic_explicit_classification',
            'reason' => $reason,
            'writes_executed' => false,
        ];
    }
}
