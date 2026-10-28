@push('scripts')
@php
$entryAids = app(\App\Services\Industry\DocumentEntryAids::class)->forContext(request()->attributes->get(\App\Services\Platform\CompanyContext::class), $kind);
$commandCenterFormConfig = [
    'kind' => $kind, 'shared' => (bool) config('commercial.enabled'),
    'textile' => $entryAids['tracking']['profile'] === 'textile',
    'baseUrl' => url('commercial/'.$kind), 'ordersUrl' => url('commercial/orders/'.$kind), 'listUrl' => route($kind === 'sale' ? 'sales.index' : 'purchases.index'),
    'units' => $lims_unit_list ?? \App\Models\Unit::where('is_active', true)->get(),
    'series' => $documentSeries,
    'context' => $documentContext ?? [],
    'aids' => $entryAids['aids'], 'tracking' => $entryAids['tracking'],
    'piecesUrl' => config('operations.enabled') ? url('operations/inventory/pieces') : null,
    'lotsUrl' => config('operations.enabled') ? url('operations/inventory/lots') : null,
    'printUrl' => config('compliance.enabled') && config('commercial.enabled') ? url('compliance/documents/'.$kind) : null,
    'purchaseOrders' => $kind === 'purchase' && app(\App\Services\Platform\DocumentShortcutRegistry::class)->purchaseOrdersEnabled(),
];
@endphp
<script type="application/json" id="command-center-form-data">@json($commandCenterFormConfig)</script>
<script src="{{ asset('js/unknown-product.js') }}?v={{ filemtime(public_path('js/unknown-product.js')) }}"></script>
<script src="{{ asset('js/line-tax-override.js') }}?v={{ filemtime(public_path('js/line-tax-override.js')) }}"></script>
<script src="{{ asset('js/command-center-form.js') }}?v={{ filemtime(public_path('js/command-center-form.js')) }}"></script>
<script src="{{ asset('js/document-drafts.js') }}?v={{ filemtime(public_path('js/document-drafts.js')) }}"></script>
<script src="{{ asset('js/document-workspace.js') }}?v={{ filemtime(public_path('js/document-workspace.js')) }}"></script>
<script src="{{ asset('js/document-aids.js') }}?v={{ filemtime(public_path('js/document-aids.js')) }}"></script>
<link rel="stylesheet" href="{{ versioned_asset('css/commercial-assistance.css') }}">
<script src="{{ versioned_asset('js/commercial-assistance.js') }}"></script>
<script src="{{ versioned_asset('js/commercial-orders.js') }}"></script>
@endpush
