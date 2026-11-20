<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

/** Classification guides a conversation; does not provide physical event evidence. */
final class JobWorkDirectionClassifier
{
    public function classify(string $text): array
    {
        $message = mb_strtolower(trim($text));
        $outward = (bool) preg_match('/\b(?:we|our company|i)\s+(?:own|send|sent|dispatch|sent out)\b.{0,100}\b(?:material|fabric|grey|cotton|dyeing|worker|processor)\b/u', $message)
            || (bool) preg_match('/\b(?:send|dispatch)\b.{0,80}\b(?:to (?:a |the )?(?:job worker|processor|dyeing unit)|for dyeing)\b/u', $message);
        $inward = (bool) preg_match('/\b(?:customer|client|principal)\b.{0,100}\b(?:owns|supplied|sent|gave|material|fabric)\b/u', $message)
            || (bool) preg_match('/\b(?:we|i)\s+(?:process|dye|dyeing)\b.{0,100}\b(?:for (?:a |the )?customer|customer.s fabric)\b/u', $message);
        $direction = $outward === $inward ? 'ambiguous' : ($outward ? 'company_owned_outward' : 'customer_owned_inward');
        return [
            'direction' => $direction,
            'needs_clarification' => $direction === 'ambiguous',
            'question' => $direction === 'ambiguous'
                ? 'Who owns the input material: your company or the customer?'
                : null,
            'writes_executed' => false,
        ];
    }
}
