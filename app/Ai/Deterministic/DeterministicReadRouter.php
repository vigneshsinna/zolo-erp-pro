<?php

declare(strict_types=1);

namespace App\Ai\Deterministic;

use App\Services\Tax\Gstin;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * A small, auditable allowlist. Only full, unambiguous instructions are
 * handled here; there is no semantic guess or fallback write action.
 *
 * The plan deliberately names existing, authorized ERP read-tool keys.
 * Model use is reserved for language that genuinely needs interpretation.
 */
final class DeterministicReadRouter
{
    /** @return array{type:string,tool?:string,input?:array,message?:string}|null */
    public function plan(string $utterance): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $utterance));
        if ($text === '' || mb_strlen($text) > 240) {
            return null;
        }

        // Never treat a compound instruction, document explanation, comparison
        // or a requested draft as a simple, deterministic reporting question.
        if (preg_match('/\b(?:draft|compose|write|why|explain|analyse|analyze|diagnose|recommend|predict|forecast)\b/iu', $text)
            || preg_match('/(?:\band\b|\bthen\b|\bafter\b).*(?:\bcreate\b|\bpost\b|\bupdate\b|\bpay\b)/iu', $text)) {
            return null;
        }

        if (preg_match('/^(?:calculate|what\s+is|what\x27s)\s+(-?\d{1,12}(?:\.\d{1,6})?)\s*([+*\/-])\s*(-?\d{1,12}(?:\.\d{1,6})?)\??$/iu', $text, $math)) {
            $left = BigDecimal::of($math[1]);
            $right = BigDecimal::of($math[3]);
            if ($math[2] === '/' && $right->isZero()) {
                return ['type' => 'clarify', 'message' => 'Division by zero is undefined; the calculation was not performed.'];
            }
            $value = match ($math[2]) {
                '+' => $left->plus($right),
                '-' => $left->minus($right),
                '*' => $left->multipliedBy($right),
                '/' => $left->dividedBy($right, 6, RoundingMode::HalfUp),
            };
            return ['type' => 'answer', 'message' => 'Result: '.(string) $value->strippedOfTrailingZeros()];
        }

        if (preg_match('/^(?:check|validate|verify)\s+(?:the\s+)?(?:gstin|gst\s+number)(?:\s+(?:of|for))?\s+(\S+)$/iu', $text, $m)) {
            try {
                Gstin::normalize($m[1]); // Uses the authoritative checkDigit() algorithm.
                return ['type' => 'answer', 'message' => 'The GSTIN format and checksum are valid. This does not verify whether the registration is active.'];
            } catch (ValidationException) {
                return ['type' => 'answer', 'message' => 'The GSTIN format or checksum is invalid. This does not establish registration status.'];
            }
        }

        $lead = '(?:show|list|get|check|display|view|fetch|give me|what is|what are)';
        $optional = '(?:\s+(?:the|my|our|current|company|today(?:\x27s)?))?';

        if (preg_match('/^'.$lead.$optional.'\s+(?:customer\s+)?(?:receivables?\s+ag(?:e)?ing|accounts?\s+receivable\s+ag(?:e)?ing|ar\s+ag(?:e)?ing|outstanding\s+receivables?)\??$/iu', $text)) {
            return ['type' => 'tool', 'tool' => 'receivables.outstanding.v1', 'input' => []];
        }
        if (preg_match('/^'.$lead.$optional.'\s+(?:supplier\s+)?(?:payables?\s+ag(?:e)?ing|accounts?\s+payable\s+ag(?:e)?ing|ap\s+ag(?:e)?ing|outstanding\s+payables?)\??$/iu', $text)) {
            return ['type' => 'tool', 'tool' => 'payables.summary.v1', 'input' => []];
        }
        if (preg_match('/^'.$lead.$optional.'\s+(?:warehouse\s+)?(?:stock|inventory)(?:\s+summary|\s+levels?|\s+availability)?\??$/iu', $text)) {
            return ['type' => 'tool', 'tool' => 'inventory.stock_summary.v1', 'input' => []];
        }
        if (preg_match('/^'.$lead.$optional.'\s+(?:products?\s+)?(?:below\s+(?:the\s+)?reorder\s+(?:level|point)|low\s+stock|reorder\s+alerts?)\??$/iu', $text)
            || preg_match('/^'.$lead.$optional.'\s+(?:low\s+stock|reorder\s+alerts?|products?\s+below\s+reorder\s+level)\??$/iu', $text)) {
            return ['type' => 'tool', 'tool' => 'inventory.stock_summary.v1', 'input' => ['below_reorder_only' => true]];
        }
        if (preg_match('/^'.$lead.$optional.'\s+(?:open|pending)\s+(purchase|sales?)\s+orders?\??$/iu', $text, $m)) {
            return ['type' => 'tool', 'tool' => 'orders.open.v1',
                'input' => ['type' => str_starts_with(strtolower($m[1]), 'purchase') ? 'purchase' : 'sale']];
        }
        if (preg_match('/^'.$lead.$optional.'\s+(?:gst\s+summary|gst\s+report|gst\s+breakdown)\??$/iu', $text)) {
            return ['type' => 'tool', 'tool' => 'tax.gst_summary.v1', 'input' => []];
        }
        if (preg_match('/^'.$lead.$optional.'\s+(?:sales\s+summary|sales\s+report)\??$/iu', $text)) {
            return ['type' => 'tool', 'tool' => 'sales.summary.v1', 'input' => []];
        }

        // These are deterministic ERP responsibilities, but a safe generic
        // chat-layer implementation needs actual document/party identifiers
        // and the existing posting-domain validation. Do not ask a model to
        // invent a tax rate, duplicate decision or three-way-match outcome.
        if (preg_match('/\b(?:calculate|compute|determine|check|verify|validate)\b.*\b(?:gst|rcm|reverse\s+charge|taxable\s+value|section\s*15)\b/iu', $text)) {
            return ['type' => 'clarify', 'message' =>
                'Tax and reverse-charge decisions must use the ERP tax engine with a specific transaction, registration, date, supply details and product tax classifications. Open or identify that transaction to run the verified check. No tax rate was guessed.'];
        }
        if (preg_match('/\b(?:duplicate\s+(?:supplier|purchase|vendor)\s+(?:bill|invoice)|(?:supplier|purchase|vendor)\s+(?:bill|invoice)\s+duplicate)\b/iu', $text)) {
            return ['type' => 'clarify', 'message' =>
                'Duplicate purchase invoices must be checked using the ERP supplier, invoice number and transaction scope. Specify the saved supplier and invoice details; no duplicate decision was guessed.'];
        }
        if (preg_match('/\b(?:three[\s-]?way\s+match|3[\s-]?way\s+match|po\s*(?:vs|versus|against)\s*grn|grn\s*(?:vs|versus|against)\s*bill)\b/iu', $text)) {
            return ['type' => 'clarify', 'message' =>
                'A PO–GRN–Bill comparison requires the actual linked documents, normalized units, quantities, rates, taxes and tolerances. Identify the records to run the authoritative matching process. No mismatch was invented.'];
        }

        return null;
    }
}
