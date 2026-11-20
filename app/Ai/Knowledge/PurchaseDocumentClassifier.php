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
        $kind = match (true) {
            ($confirmedFacts['job_work_receipt_id'] ?? null) !== null
                || (bool) preg_match('/\b(?:job[\s-]?work|dyeing|processing charges?|subcontract)\b/u', $text)
                    && (bool) preg_match('/\b(?:bill|invoice|charges?)\b/u', $text) => 'job_work_processing_bill',
            (bool) preg_match('/\b(?:purchase order|create po|raise po|order from)\b/u', $text) => 'purchase_order',
            (bool) preg_match('/\b(?:electricity|rent|consulting fee|maintenance charge|office expense|expense voucher)\b/u', $text) => 'expense_bill',
            (bool) preg_match('/\b(?:service bill|service invoice|professional fee|consultancy bill)\b/u', $text) => 'service_bill',
            (bool) preg_match('/\b(?:received goods|goods bill|stock purchase|material bill|supplier invoice for stock)\b/u', $text) => 'goods_bill',
            default => 'ambiguous',
        };
        $reason = match ($kind) {
            'purchase_order' => 'Use a reviewed purchase-order proposal; no purchase has been posted.',
            'goods_bill' => 'Use the existing goods-purchase preview and stock/GRN validation.',
            'service_bill' => 'A service-only catalog item and an authorised branch accounting location are required; no stock receipt.',
            'job_work_processing_bill' => 'Requires a verified, eligible unbilled job-work receipt; generic goods bill is prohibited.',
            'expense_bill' => 'Expense-account payable requires a separately reviewed non-stock accounting capability; unsupported by this classifier.',
            default => 'Clarify purchase order, goods purchase, service bill, expense or job-work bill before creating a proposal.',
        };
        return [
            'classification' => $kind,
            'candidate_action' => match ($kind) {
                'purchase_order' => 'purchase_order.create.v1',
                'goods_bill' => 'purchase_bill.create.v1',
                'service_bill' => 'purchase_bill.service.create.v1',
                default => null,
            },
            'supported_without_clarification' => in_array($kind, ['purchase_order', 'goods_bill', 'service_bill'], true),
            'needs_gst_clarification' => (bool) preg_match('/\bnon[\s-]?gst\b/u', $text)
                && empty($confirmedFacts['tax_treatment']),
            'source' => 'deterministic_explicit_classification',
            'reason' => $reason,
            'writes_executed' => false,
        ];
    }
}
