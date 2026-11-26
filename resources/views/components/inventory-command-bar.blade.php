@props(['active' => 'stock', 'view' => null])
@php
    $inventoryAccess = new \App\Services\Platform\NavigationAccess($role_has_permissions_list ?? null);
    $inventorySections = app(\App\Services\Platform\InventoryNavigation::class)->sections($inventoryAccess);
    $contextLinks = [];
    if ($active === 'corrections' && $inventoryAccess->allows('sidebar_product')) {
        if ($inventoryAccess->allows('stock_count')) $contextLinks[] = ['key' => 'count', 'label' => 'Physical count', 'url' => route('stock-count.index')];
        if ($inventoryAccess->allows('adjustment')) $contextLinks[] = ['key' => 'adjustment', 'label' => 'Manual adjustments', 'url' => route('qty_adjustment.index')];
        if ($inventoryAccess->has('inventory.damage_stock')) $contextLinks[] = ['key' => 'damage', 'label' => 'Damage audit', 'url' => route('damage-stock.index')];
    } elseif ($active === 'transfers') {
        if ($inventoryAccess->allows('sidebar_transfer') && $inventoryAccess->allows('transfers-index')) $contextLinks[] = ['key' => 'register', 'label' => 'Transfer register', 'url' => route('transfers.index')];
        if ($inventoryAccess->allows('sidebar_transfer') && $inventoryAccess->allows('transfers-import')) $contextLinks[] = ['key' => 'import', 'label' => 'Import CSV', 'url' => url('/transfers/transfer_by_csv')];
    } elseif (($active === 'stock' || $active === 'lifecycle') && config('operations.enabled') && $inventoryAccess->has('inventory.batch_expiry')) {
        if ($inventoryAccess->has('inventory.lot_report')) {
            $contextLinks[] = ['key' => 'simplified', 'label' => 'Simplified Stock', 'url' => route('inventory-reports.stock-by-lot', ['group_by' => 'simplified'])];
            $contextLinks[] = ['key' => 'balances', 'label' => 'Stock balances (Detailed)', 'url' => route('inventory-reports.stock-by-lot')];
            $contextLinks[] = ['key' => 'lifecycle', 'label' => 'Product Lifecycle', 'url' => route('inventory-reports.lifecycle')];
            $contextLinks[] = ['key' => 'timeline', 'label' => 'Inventory Timeline', 'url' => route('inventory-reports.timeline')];
        }
        $contextLinks[] = ['key' => 'tools', 'label' => 'Expiry & stock tools', 'url' => url('/operations/stock')];
    }
    $purpose = match ($active) {
        'corrections' => $view === 'count' ? 'Count what is physically present, compare with system stock, then adjust the differences.'
            : ($view === 'damage' ? 'Review damaged stock and its correction history.' : 'Record a stock increase or decrease with a reason. Use physical count for a warehouse stocktake.'),
        'transfers' => $view === 'import' ? 'Upload warehouse transfers from a CSV file.' : 'Move stock between warehouses. Total company quantity stays the same.',
        default => $view === 'tools' ? 'Review expiry-controlled batches and stock tools.'
            : ($view === 'lifecycle' ? 'Interactive Product Lifecycle & Conversion Tree: Trace purchased raw materials through job work branches, finished conversions, process shrinkage, and ready stock.'
            : ($view === 'timeline' ? 'Visual chronological journey: Purchases → Job Work DCs → Processor WIP → WGRN Returns → Final Stock.'
            : ($view === 'simplified' ? 'Simplified Business Flow: Purchased, Sent to Job Work, Returned after Processing, and Current Godown Stock.'
            : 'View stock by product and warehouse. Choose Individual lots for lot details.'))),
    };
@endphp
@once
    @push('css')
        <link rel="stylesheet" href="{{ versioned_asset('css/product-workspace.css') }}">
        <link rel="stylesheet" href="{{ versioned_asset('css/pages/inventory-workspace.css') }}">
    @endpush
@endonce
<div class="comm-command-bar inventory-command-bar">
    <div class="comm-title-group"><h1><i class="dripicons-box"></i> Inventory</h1></div>
    <ul class="comm-nav-pills" aria-label="Inventory screens">
        @foreach($inventorySections as $key => $item)<li><a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ $item['url'] }}" @if($active === $key) aria-current="page" @endif>{{ $item['label'] }}</a></li>@endforeach
    </ul>
    <div class="comm-actions">{{ $slot }}</div>
</div>
<div class="inventory-context">
    @if(count($contextLinks) > 1)
    <nav class="inventory-context-links" aria-label="{{ $inventorySections[$active]['label'] ?? 'Inventory' }} views">
        @foreach($contextLinks as $item)<a href="{{ $item['url'] }}" class="{{ $view === $item['key'] ? 'active' : '' }}" @if($view === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>@endforeach
    </nav>
    @endif
    <p>{{ $purpose }}</p>
</div>
