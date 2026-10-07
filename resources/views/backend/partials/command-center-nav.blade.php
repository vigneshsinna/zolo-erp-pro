@php
    $salesCenter = $kind === 'sale';
    $centerRoute = $salesCenter ? 'sales.index' : 'purchases.index';
    $centerContext = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
    $centerPermission = app(\App\Services\Commercial\CommercialPermission::class);
    $centerCan = fn ($permission) => $centerContext && $centerPermission->allows($permission, $centerContext, Auth::id());
    $centerOrders = request('view') === 'orders';
@endphp
@once
    @push('css')
        <link rel="stylesheet" href="{{ asset('css/command-center.css') }}">
    @endpush
@endonce
<nav class="command-center-nav" aria-label="{{ $salesCenter ? 'Sales' : 'Purchase' }} Command Center">
    @if($centerCan($salesCenter ? 'sales-index' : 'purchases-index'))
        <a href="{{ route($centerRoute) }}" @if(!$centerOrders) aria-current="page" @endif>Bills</a>
        <a href="{{ route($centerRoute, ['view' => 'orders', $salesCenter ? 'sale_status' : 'purchase_status' => $salesCenter ? 2 : 4]) }}" @if($centerOrders) aria-current="page" @endif>Orders</a>
    @endif
    @if($centerCan($salesCenter ? 'returns-index' : 'purchase-return-index'))
        <a href="{{ route($salesCenter ? 'return-sale.index' : 'return-purchase.index') }}">Returns</a>
    @endif
    @if($salesCenter && $centerCan('quotes-index'))
        <a href="{{ route('quotations.index') }}">Quotations</a>
    @elseif(!$salesCenter && $centerCan('suppliers-index'))
        <a href="{{ route('supplier.index') }}">Suppliers</a>
    @endif
    @if($centerCan($salesCenter ? 'sales-add' : 'purchases-add'))
        <a class="command-center-new" href="{{ route($centerRoute, ['new' => 1]) }}" data-document-shortcut="{{ $salesCenter ? 'sale' : 'purchase' }}">+ New Bill <kbd>{{ $salesCenter ? 'F2' : 'F12' }}</kbd></a>
    @endif
    <button type="button" class="command-center-help" data-shortcut-help title="Keyboard shortcuts (?)">Shortcuts <kbd>?</kbd></button>
</nav>
<div id="document-tabs" class="document-tabs" hidden>
    <div id="document-tab-list" class="document-tab-list" role="tablist" aria-label="Open {{ $salesCenter ? 'sales' : 'purchase' }} drafts"></div>
    <button type="button" id="document-tab-add" class="document-tab-add" title="New draft tab" aria-label="New draft tab">+</button>
    <span id="document-tab-count" class="document-tab-count" title="Open drafts"></span>
    <span id="document-tab-status" class="document-tab-status" role="status" aria-live="polite"></span>
</div>
<div id="document-draft-banner" class="document-draft-banner" role="alert" hidden></div>
