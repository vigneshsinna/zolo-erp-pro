<?php

namespace App\Services\Platform;

/**
 * One table of document accelerators. A shortcut only opens the existing document page in its new-document state;
 * it never owns a second implementation of that document. The server still authorises every request it opens.
 */
final class DocumentShortcutRegistry
{
    /** Keys follow two families: F2 = selling, F12 = buying. Ctrl+F2 stays unassigned until Sales Orders have a lifecycle. */
    public function definitions(): array
    {
        return [
            ['id' => 'sale', 'key' => 'F2', 'label' => 'New Sales Bill', 'group' => 'Selling', 'permission' => 'sales-add',
                'url' => route('sales.index', ['new' => 1])],
            ['id' => 'sale-return', 'key' => 'Shift+F2', 'label' => 'New Sales Return', 'group' => 'Selling', 'permission' => 'returns-add',
                'url' => route('sales.index', ['returns' => 1])],
            ['id' => 'delivery-challan', 'key' => 'Alt+F2', 'label' => 'New Customer DC', 'group' => 'Selling', 'permission' => 'delivery-challans-index',
                'url' => route('delivery-challans.index', ['new' => 1])],
            ['id' => 'quotation', 'key' => 'Alt+F10', 'label' => 'New Quotation', 'group' => 'Selling', 'permission' => 'quotes-add',
                'url' => route('quotations.create')],
            ['id' => 'purchase', 'key' => 'F12', 'label' => 'New Purchase Bill', 'group' => 'Buying', 'permission' => 'purchases-add',
                'url' => route('purchases.index', ['new' => 1])],
            ['id' => 'purchase-return', 'key' => 'Shift+F12', 'label' => 'New Purchase Return', 'group' => 'Buying', 'permission' => 'purchase-return-add',
                'url' => route('purchases.index', ['returns' => 1])],
            ['id' => 'purchase-order', 'key' => 'Ctrl+F12', 'label' => 'New Purchase Order', 'group' => 'Buying', 'permission' => 'purchases-add',
                'url' => route('purchases.index', ['new' => 1, 'doc' => 'order']), 'enabled' => $this->purchaseOrdersEnabled()],
            ['id' => 'grn', 'key' => 'Alt+F12', 'label' => 'New Supplier GRN', 'group' => 'Buying', 'permission' => 'grn-index',
                'url' => route('goods-received-notes.index', ['new' => 1])],
            // Plain F4-F9 belong to the voucher screen; Ctrl+F9 opens the numbering every voucher and bill uses.
            ['id' => 'voucher-series', 'key' => 'Ctrl+F9', 'label' => 'Voucher Series', 'group' => 'Masters', 'permission' => 'sidebar_product',
                'url' => route('document-series.index')],
        ];
    }

    public function purchaseOrdersEnabled(): bool
    {
        if (!config('commercial.enabled') || !config('documents.purchase_order_shortcut')) return false;
        $cache = app(\App\Support\Database\SchemaCache::class);
        foreach (['commercial_order_items', 'commercial_order_allocations', 'commercial_order_events'] as $table) {
            if (!$cache->hasTable(\Illuminate\Support\Facades\DB::connection(), $table)) return false;
        }
        return true;
    }

    /** Entries the user may open. $can is the same permission test the sidebar uses; $isAdmin mirrors its admin bypass. */
    public function forUser(callable $can, bool $isAdmin): array
    {
        $available = [];
        foreach ($this->definitions() as $definition) {
            if (($definition['enabled'] ?? true) && ($isAdmin || $can($definition['permission']))) {
                $available[] = array_diff_key($definition, ['permission' => true, 'enabled' => true]);
            }
        }
        return $available;
    }

    public function find(array $shortcuts, string $id): ?array
    {
        foreach ($shortcuts as $shortcut) {
            if ($shortcut['id'] === $id) return $shortcut;
        }
        return null;
    }
}
