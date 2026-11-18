<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

/**
 * Recognizes read-only ERP directory questions without asking a model to invent
 * workflow corrections. Actual results are always fetched from scoped ERP queries.
 */
final class ConversationDirectoryIntent
{
    /** @return list<string> */
    public function types(string $message): array
    {
        if (!$this->isReadOnlyQuestion($message)) {
            return [];
        }

        // A price, stock or document question is not automatically a master directory listing.
        if (!preg_match('/\b(?:available|existing|all|any|which|list|show|browse|find|search|what\s+are)\b/iu', $message)) {
            return [];
        }

        preg_match_all('/\b(suppliers?|vendors?|products?|items?|warehouses?|units?)\b/iu', $message, $matches);
        $types = [];
        foreach ($matches[1] as $noun) {
            $type = match (strtolower($noun)) {
                'supplier', 'suppliers', 'vendor', 'vendors' => 'supplier',
                'product', 'products', 'item', 'items' => 'product',
                'warehouse', 'warehouses' => 'warehouse',
                'unit', 'units' => 'unit',
            };
            if (!in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public function isReadOnlyQuestion(string $message): bool
    {
        return (bool) preg_match(
            '/^\s*(?:please\s+|kindly\s+)?(?:'
            .'(?:can|could|would)\s+you\s+(?:please\s+)?(?:tell\s+me|show\s+me|list|find|search|check|explain|give\s+me)'
            .'|can\s+i\s+see'
            .'|(?:what|which|who|how|where|when|why)\b'
            .'|(?:are\s+there|do\s+we\s+have)\b'
            .'|(?:show|list|find|search|browse|display|tell\s+me|give\s+me)\b'
            .')/iu',
            $message,
        );
    }

    public function query(string $message, string $type, int $typeCount): string
    {
        if ($typeCount !== 1) {
            return '';
        }

        $names = match ($type) {
            'supplier' => 'suppliers?|vendors?',
            'product' => 'products?|items?',
            'warehouse' => 'warehouses?',
            'unit' => 'units?',
            default => '',
        };

        if ($names !== '' && preg_match('/\b(?:'.$names.')\s+(?:named|called|matching|for)\s+(.+?)\??$/iu', trim($message), $match)) {
            return trim($match[1]);
        }

        return '';
    }
}
