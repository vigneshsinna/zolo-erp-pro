<?php

namespace App\Services\Platform;

/** Inventory destinations shared by the sidebar and the inventory command bar. */
final class InventoryNavigation
{
    public function sections(NavigationAccess $access): array
    {
        $sections = [];
        if ($access->allows('products-index')) {
            if ($access->has('inventory.lot_report')) {
                $sections['stock'] = ['label' => 'Stock', 'url' => route('inventory-reports.stock-by-lot'), 'id' => 'stock-by-lot-menu'];
                $sections['lifecycle'] = ['label' => 'Product Lifecycle', 'url' => route('inventory-reports.lifecycle'), 'id' => 'product-lifecycle-menu'];
            } elseif (config('operations.enabled') && $access->has('core.inventory')) {
                $sections['stock'] = ['label' => 'Stock', 'url' => url('/operations/stock'), 'id' => 'stock-menu'];
            }
        }
        if ($access->allows('sidebar_product')) {
            $correction = $access->allows('stock_count') ? route('stock-count.index')
                : ($access->allows('adjustment') ? route('qty_adjustment.index')
                    : ($access->has('inventory.damage_stock') ? route('damage-stock.index') : null));
            if ($correction) $sections['corrections'] = ['label' => 'Stock Corrections', 'url' => $correction, 'id' => 'stock-corrections-menu'];
        }
        if ($access->allows('sidebar_transfer')) {
            $transfer = $access->allows('transfers-index') ? route('transfers.index')
                : ($access->allows('transfers-import') ? url('/transfers/transfer_by_csv') : null);
            if ($transfer) $sections['transfers'] = ['label' => 'Transfers', 'url' => $transfer, 'id' => 'transfer-list-menu'];
        }
        return $sections;
    }
}
