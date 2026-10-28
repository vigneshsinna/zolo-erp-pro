@extends('backend.layout.main')

@section('content')
@if(!empty($side))
{{-- Opened from the Purchase or Sales command center: stay inside it. --}}
@section('navbar-center')
<div class="comm-navbar-center-wrap d-flex align-items-center w-100 u-minw-0 u-gap-8px">
    <div class="comm-navbar-title-group">
        <span class="badge comm-title-badge">
            <i class="{{ $side === 'sale' ? 'dripicons-cart text-primary' : 'dripicons-download text-success' }}"></i> {{ $side === 'sale' ? 'Sales' : 'Purchase' }} Command Center
        </span>
        <span class="u-c-xcbd5e1 u-fs-12px u-fw-600">/</span>
        <span class="comm-doc-title">{{ $side === 'sale' ? 'Sales returns (credit notes)' : 'Purchase returns (debit notes)' }}</span>
    </div>
</div>
@endsection
@include('backend.partials.command-center-nav', ['kind' => $side, 'current' => 'returns', 'withDrafts' => false])
@elseif(!empty($trade))
<div class="comm-command-bar">
    <div class="comm-title-group">
        <h1><i class="dripicons-store text-primary"></i> @yield('title', 'Trade')</h1>
        <span class="comm-title-badge"><i class="dripicons-network-3"></i> Agents, routes and job work for customers</span>
    </div>
    <ul class="comm-nav-pills">
        <li><a class="nav-link {{ request()->is('*trade/brokerage*') ? 'active' : '' }}" href="{{ url('/trade/brokerage') }}"><i class="dripicons-user-group"></i> Brokerage</a></li>
        <li><a class="nav-link {{ request()->is('*trade/routes*') ? 'active' : '' }}" href="{{ url('/trade/routes') }}"><i class="dripicons-map"></i> Route sheet</a></li>
        <li><a class="nav-link {{ request()->is('*trade/customer-jobs*') ? 'active' : '' }}" href="{{ url('/trade/customer-jobs') }}"><i class="dripicons-swap"></i> Job work for customers</a></li>
    </ul>
</div>
@else
<div class="comm-command-bar">
    <div class="comm-title-group">
        <h1><i class="dripicons-swap text-primary"></i> @yield('title', 'Returns & Compliance')</h1>
        <span class="comm-title-badge"><i class="dripicons-shield"></i> Sales &amp; Purchase Control · Company {{ $context->companyId ?? 1 }} / Branch {{ $context->branchId ?? 1 }}</span>
    </div>
    <ul class="comm-nav-pills">
        <li><a class="nav-link" href="{{ route('sales.index', ['returns' => 1]) }}"><i class="dripicons-return"></i> Returns &amp; Notes</a></li>
        <li><a class="nav-link {{ request()->is('*compliance/gst/report*') ? 'active' : '' }}" href="{{ url('/compliance/gst/report') }}"><i class="dripicons-graph-bar"></i> GST Review</a></li>
        <li><a class="nav-link {{ request()->is('*compliance/gst/returns*') ? 'active' : '' }}" href="{{ url('/compliance/gst/returns') }}"><i class="dripicons-document"></i> GST Returns</a></li>
        <li><a class="nav-link {{ request()->is('*compliance/setup*') ? 'active' : '' }}" href="{{ url('/compliance/setup') }}"><i class="dripicons-gear"></i> Settings</a></li>
    </ul>
    <div class="comm-actions">
        <a href="{{ route('sales.index') }}" class="btn btn-outline-secondary btn-sm py-1 px-3 u-fs-11_5px u-r-4px"><i class="dripicons-cart"></i> Sales Bills</a>
        <a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary btn-sm py-1 px-3 u-fs-11_5px u-r-4px"><i class="dripicons-card"></i> Purchase Bills</a>
    </div>
</div>
@endif

<div class="compliance-wrapper">
    @if(session('message'))
        <div class="notice" role="status">
            <i class="dripicons-checkmark text-success"></i> {{ session('message') }}
        </div>
    @endif
    @if($errors->any())
        <div class="notice error" role="alert">
            <i class="dripicons-warning text-danger"></i>
            <div>
                <strong>Review these fields:</strong>
                <ul class="mb-0 mt-1 pl-3">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @yield('compliance_body')
</div>
@endsection

@push('css')
    <link rel="stylesheet" href="{{ asset('css/commercial-entry.css') }}?v={{ file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time() }}" type="text/css">
    <link rel="stylesheet" href="{{ asset('css/commercial-workspace.css') }}?v={{ file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time() }}" type="text/css">
    <link rel="stylesheet" href="{{ asset('css/zolo-erp-neo.css') }}?v={{ file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time() }}" type="text/css">
    <link rel="stylesheet" href="{{ asset('css/compliance.css') }}?v={{ file_exists(public_path('css/compliance.css')) ? filemtime(public_path('css/compliance.css')) : time() }}" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/compliance-layout.css') }}">
@endpush
