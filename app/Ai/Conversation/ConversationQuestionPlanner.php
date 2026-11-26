<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

/**
 * Shared, schema-aware conversational guidance.
 * Prompts are UX guidance, not new schema validation or approval permissions.
 */
final class ConversationQuestionPlanner
{
    private const PROFILES = [
        'setup.supplier.create.v1' => [
            'name' => 'What is the supplier name?',
            'contact' => 'What phone number or email should I save for this supplier? You can provide both or say "skip contact".',
            'address' => 'What is their address and city/state? You can provide what you know or say "skip address".',
            'tax' => 'Is this supplier GST registered? If yes, share the GSTIN. Otherwise say "unregistered", or "skip tax" for now.',
        ],
        'setup.customer.create.v1' => [
            'name' => 'What is the customer name?',
            'contact' => 'What contact number or email should I save? You can skip contact details.',
            'address' => 'What is their address or location? You can skip it for now.',
        ],
    ];

    public function next(string $actionKey, array $slots): ?string
    {
        $profile = self::PROFILES[$actionKey] ?? null;
        if (!$profile) return null;
        $skipped = $slots['_conversation_skips'] ?? [];
        if (!is_array($skipped)) $skipped = [];

        if (trim((string) ($slots['name'] ?? '')) === '') return $profile['name'];
        if (!in_array('contact', $skipped, true)
            && empty($slots['phone_number']) && empty($slots['email'])) return $profile['contact'];

        if (!in_array('address', $skipped, true)
            && empty($slots['address'])) return $profile['address'];

        if (isset($profile['tax']) && !in_array('tax', $skipped, true)
            && empty($slots['vat_number'])
            && ($slots['_conversation_gst_status'] ?? '') !== 'unregistered') {
            return ($slots['_conversation_gst_status'] ?? '') === 'registered'
                ? 'You confirmed the supplier is GST registered. Please provide the GSTIN before previewing.'
                : $profile['tax'];
        }
        return null;
    }

    /** @return list<string> */
    public function skipGroups(string $message): array
    {
        $message = trim($message);
        if (preg_match('/^skip (?:all |the )?(?:optional|remaining)(?: details)?[.!]?$/i', $message)) {
            return ['contact', 'address', 'tax'];
        }
        return match (true) {
            (bool) preg_match('/^skip (?:the )?contact(?: details)?[.!]?$/i', $message) => ['contact'],
            (bool) preg_match('/^skip (?:the )?address[.!]?$/i', $message) => ['address'],
            (bool) preg_match('/^skip (?:the )?(?:gst|tax|gstin)[.!]?$/i', $message) => ['tax'],
            default => [],
        };
    }
}
