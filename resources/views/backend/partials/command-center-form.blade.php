@push('scripts')
@php
$entryAids = app(\App\Services\Industry\DocumentEntryAids::class)->forContext(request()->attributes->get(\App\Services\Platform\CompanyContext::class), $kind);
$commandCenterFormConfig = [
    'kind' => $kind, 'shared' => (bool) config('commercial.enabled'),
    'baseUrl' => url('commercial/'.$kind), 'listUrl' => route($kind === 'sale' ? 'sales.index' : 'purchases.index'),
    'units' => $lims_unit_list ?? \App\Models\Unit::where('is_active', true)->get(),
    'series' => $documentSeries,
    'context' => $documentContext ?? [],
    'aids' => $entryAids['aids'], 'tracking' => $entryAids['tracking'],
    'piecesUrl' => config('operations.enabled') ? url('operations/inventory/pieces') : null,
    'purchaseOrders' => $kind === 'purchase' && config('documents.purchase_order_shortcut'),
];
@endphp
<script type="application/json" id="command-center-form-data">@json($commandCenterFormConfig)</script>
<script src="{{ asset('js/command-center-form.js') }}?v={{ filemtime(public_path('js/command-center-form.js')) }}"></script>
<script src="{{ asset('js/document-workspace.js') }}?v={{ filemtime(public_path('js/document-workspace.js')) }}"></script>
<script src="{{ asset('js/document-aids.js') }}?v={{ filemtime(public_path('js/document-aids.js')) }}"></script>
@endpush
