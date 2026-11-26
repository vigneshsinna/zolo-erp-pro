<?php

declare(strict_types=1);

namespace App\Ai\Documents;

/** Conservative hints only. OCR text never authorizes a party or a financial amount. */
final class InvoiceSlotExtractor
{
    public function extract(array $pages): array
    {
        $fields = [];
        foreach ($pages as $pageIndex => $text) {
            foreach ([
                'supplier_invoice_no' => '/\b(?:invoice|bill)\s*(?:no\.?|number|#)\s*[:#-]?\s*([A-Z0-9][A-Z0-9\/-]{1,49})/iu',
                'date' => '/\b(?:invoice\s*)?date\s*:?\s*(\d{4}-\d{2}-\d{2})\b/iu',
                'supplier_gstin' => '/\bGSTIN\s*:?\s*([0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9])\b/iu',
            ] as $field => $pattern) {
                if (!isset($fields[$field]) && preg_match($pattern, $text, $match)) {
                    $fields[$field] = ['value' => $match[1], 'page' => $pageIndex + 1, 'confidence' => 'needs_review'];
                }
            }
        }
        return ['fields' => $fields, 'items' => [], 'needs_review' => true,
            'warnings' => ['Confirm supplier, line items, quantities, prices and declared tax against the source before preparing a bill.']];
    }
}
