{{-- Purchase Command Center bar for the purchase-side screens that are not the bill entry itself
     (suppliers, CSV import): same dark bar as the Product & Inventory and Supplier centers.
     Usage: <x-purchase-command-bar active="suppliers" title="Add supplier"> page action buttons </x-purchase-command-bar> --}}
@props(['active' => null, 'title' => null])
@php
    $user = Auth::user();
    $admin = $user && in_array((int) $user->role_id, [1, 2], true);
    $allow = function (string $permission) use ($user, $admin) {
        if ($admin) return true;
        try { return $user && $user->can($permission); } catch (\Throwable $e) { return false; }
    };
    $links = [
        ['key' => 'bills', 'permission' => 'purchases-index', 'url' => route('purchases.index'), 'icon' => 'dripicons-card', 'label' => 'Bills'],
        ['key' => 'orders', 'permission' => 'purchases-index', 'url' => route('purchases.index', ['orders' => 1]), 'icon' => 'dripicons-clipboard', 'label' => 'Orders'],
        ['key' => 'grn', 'permission' => 'purchases-index', 'url' => route('goods-received-notes.index'), 'icon' => 'dripicons-download', 'label' => 'Supplier GRN'],
        ['key' => 'returns', 'permission' => 'purchase-return-index', 'url' => route('purchases.index', ['returns' => 1]), 'icon' => 'dripicons-return', 'label' => 'Returns'],
        ['key' => 'import', 'permission' => 'purchases-import', 'url' => url('purchases/purchase_by_csv'), 'icon' => 'dripicons-upload', 'label' => 'Import CSV'],
        ['key' => 'suppliers', 'permission' => 'suppliers-index', 'url' => route('supplier.index'), 'icon' => 'dripicons-user', 'label' => 'Suppliers'],
    ];
@endphp
@once
    @push('css')
        <link rel="stylesheet" href="{{ versioned_asset('css/product-workspace.css') }}" type="text/css">
    @endpush
@endonce
<div class="comm-command-bar purchase-command-bar">
    <div class="comm-title-group">
        <h1><i class="dripicons-card text-success"></i> Purchase Command Center</h1>
        @if($title)<span class="comm-title-badge">{{ $title }}</span>@endif
    </div>
    <ul class="comm-nav-pills" aria-label="Purchase screens">
        @foreach($links as $link)
            @if($link['url'] && $allow($link['permission']))
                <li><a class="nav-link {{ $active === $link['key'] ? 'active' : '' }}" href="{{ $link['url'] }}" @if($active === $link['key']) aria-current="page" @endif><i class="{{ $link['icon'] }}"></i> {{ $link['label'] }}</a></li>
            @endif
        @endforeach
    </ul>
    <div class="comm-actions">{{ $slot }}</div>
</div>
