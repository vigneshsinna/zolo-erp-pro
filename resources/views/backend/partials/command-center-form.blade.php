@push('scripts')
@php
$commandCenterFormConfig = [
    'kind' => $kind, 'shared' => (bool) config('commercial.enabled'),
    'baseUrl' => url('commercial/'.$kind), 'listUrl' => route($kind === 'sale' ? 'sales.index' : 'purchases.index'),
    'units' => $lims_unit_list ?? \App\Models\Unit::where('is_active', true)->get(),
    'series' => $documentSeries,
    'context' => $documentContext ?? [],
    'purchaseOrders' => $kind === 'purchase' && config('documents.purchase_order_shortcut'),
];
@endphp
<script type="application/json" id="command-center-form-data">@json($commandCenterFormConfig)</script>
<script src="{{ asset('js/command-center-form.js') }}?v={{ filemtime(public_path('js/command-center-form.js')) }}"></script>
<script src="{{ asset('js/document-workspace.js') }}?v={{ filemtime(public_path('js/document-workspace.js')) }}"></script>
@endpush
