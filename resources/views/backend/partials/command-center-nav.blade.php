@php
    $salesCenter = $kind === 'sale';
    $centerRoute = $salesCenter ? 'sales.index' : 'purchases.index';
    $centerContext = (isset($context) && $context instanceof \App\Services\Platform\CompanyContext) ? $context : request()->attributes->get(\App\Services\Platform\CompanyContext::class);
    $centerPermission = app(\App\Services\Commercial\CommercialPermission::class);
    $centerCan = fn ($permission) => $centerContext && $centerPermission->allows($permission, $centerContext, Auth::id());
    $centerCurrent = $current ?? null; // 'returns' when included by the returns register
    $centerOrders = !$centerCurrent && (request('view') === 'orders' || request('orders') == 1);
    // With shared commercial + compliance, Returns is a tab of this same workspace (backend/commercial/returns-workspace).
    $centerReturnsTab = config('commercial.enabled') && config('compliance.enabled');
    $centerReturns = $centerCurrent === 'returns' || ($centerReturnsTab && request('returns') == 1);
@endphp
@once
    @push('css')
        <link rel="stylesheet" href="{{ asset('css/command-center.css') }}">
    @endpush
@endonce
<nav class="command-center-nav" aria-label="{{ $salesCenter ? 'Sales' : 'Purchase' }} Command Center">
    @if($centerCan($salesCenter ? 'sales-index' : 'purchases-index'))
        <a href="{{ route($centerRoute) }}" @if(!$centerOrders && !$centerCurrent && !$centerReturns) aria-current="page" @endif data-nav-tab="bills">Bills</a>
        @if(config('commercial.enabled'))
            <a href="{{ route($centerRoute, ['orders' => 1]) }}" data-order-register data-nav-tab="orders" @if($centerOrders) aria-current="page" @endif>{{ $salesCenter ? 'Sales Orders' : 'Purchase Orders' }}</a>
        @else
            <a href="{{ route($centerRoute, ['view' => 'orders', $salesCenter ? 'sale_status' : 'purchase_status' => $salesCenter ? 2 : 4]) }}" data-nav-tab="orders" @if($centerOrders) aria-current="page" @endif>Orders</a>
        @endif
    @endif
    @if($centerCan($salesCenter ? 'returns-index' : 'purchase-return-index'))
        <a href="{{ $centerReturnsTab ? route($centerRoute, ['returns' => 1]) : route($salesCenter ? 'return-sale.index' : 'return-purchase.index') }}" data-nav-tab="returns" @if($centerReturns) aria-current="page" @endif>Returns</a>
    @endif
    @if($salesCenter && $centerCan('quotes-index'))
        <a href="{{ route('quotations.index') }}">Quotations</a>
    @elseif(!$salesCenter && $centerCan('suppliers-index'))
        <a href="{{ route('supplier.index') }}">Suppliers</a>
    @endif
</nav>
@if($withDrafts ?? true)
@include('backend.partials.document-draft-tabs', ['draftLabel' => $salesCenter ? 'sales' : 'purchase', 'draftKey' => $salesCenter ? 'F2' : 'F12'])
@endif
