<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

/**
 * Classify only an explicitly requested transaction in user-confirmed speech.
 *
 * Perception model guesses are not authoritative. Ambiguous or unsupported
 * requests never become write actions through a fallback document type.
 */
final class VoiceIntentResolver
{
    public function resolve(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        // The sentence must request a transaction, not merely refer to a PO number,
        // invoice reference, or existing transaction in a general question.
        $action = (bool) preg_match(
            '/\b(?:create|prepare|raise|make|draft|start|place|book|enter|post|generate|issue|need|want)\b/iu',
            $text,
        );
        $bare = (bool) preg_match(
            '/^(?:a |an |the )?(?:purchase order|p\.?\s*o\.?|purchase bill|supplier invoice|sales order|sales invoice)\s*[.!?]*$/iu',
            $text,
        );
        if (! $action && ! $bare) {
            return null;
        }

        $matches = [];
        $terms = [
            'purchase_order.create.v1' => '/\b(?:purchase\s+orders?|p\.?\s*o\.?)\b/iu',
            'purchase_bill.create.v1' => '/\b(?:purchase\s+bills?|supplier\s+invoices?|vendor\s+bills?|purchase\s+invoices?)\b/iu',
            'sales_order.create.v1' => '/\b(?:sales\s+orders?|s\.?\s*o\.?)\b/iu',
            'sales_invoice.create.v1' => '/\b(?:sales\s+invoices?|tax\s+invoices?)\b/iu',
        ];
        foreach ($terms as $key => $pattern) {
            if (preg_match($pattern, $text)) {
                $matches[] = $key;
            }
        }

        // Mixed PO / bill wording is not resolved by precedence, model guess,
        // document type or the order in which the words appeared.
        return count($matches) === 1 ? $matches[0] : null;
    }
}
