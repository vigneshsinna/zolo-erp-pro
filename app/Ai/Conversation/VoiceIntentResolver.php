<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

/**
 * Classify only an explicitly requested transaction in user-confirmed speech.
 *
 * Delegates to the shared TransactionIntentResolver so text, voice, and media
 * follow one canonical phrase precedence, negation, and conflict contract.
 */
final class VoiceIntentResolver
{
    public function resolve(string $text): ?string
    {
        $clean = mb_strtolower(trim($text));
        if ($clean === '') {
            return null;
        }
        if (app(SupplierSetupConversation::class)->start($clean) !== null) {
            return SupplierSetupConversation::KEY;
        }
        if (app(CustomerSetupConversation::class)->start($clean) !== null) {
            return CustomerSetupConversation::KEY;
        }

        return app(TransactionIntentResolver::class)->resolveExplicitAction($text);
    }
}
