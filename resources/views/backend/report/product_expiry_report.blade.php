@extends('backend.layout.main') @section('content')


@php
    $totalBatchCount = count($lims_product_data);
    $expiredCount = 0;
    $nearExpiryCount = 0;
    foreach($lims_product_data as $p) {
        if(date('Y-m-d') >= $p->expired_date) {
            $expiredCount++;
        } else {
            $nearExpiryCount++;
        }
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-hourglass text-warning"></i> {{ __('db.Product Expiry Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Batch Expiry &amp; Shelf-Life Compliance</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ url('report/product_report') }}"><i class="dripicons-box"></i> Product Report</a></li>
            <li><a class="nav-link" href="{{ route('report.qtyAlert') }}"><i class="dripicons-warning"></i> Qty Alerts</a></li>
            <li><a class="nav-link active" href="{{ route('report.productExpiry') }}"><i class="dripicons-hourglass"></i> Expiry Report</a></li>
            <li><a class="nav-link" href="{{ url('report/purchase') }}"><i class="dripicons-basket"></i> Purchase Report</a></li>
        </ul>
        <div class="comm-actions">
            <button class="btn btn-secondary btn-action-pill" data-erp-print>
                <i class="dripicons-print"></i> Print
            </button>
        </div>
    </div>

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TRACKED BATCHES</div>
                <div class="kpi-number">{{ number_format($totalBatchCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-stack u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">EXPIRED BATCHES</div>
                <div class="kpi-number u-c-danger">{{ number_format($expiredCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-cross u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">EXPIRING SOON</div>
                <div class="kpi-number u-c-warning">{{ number_format($nearExpiryCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-clock u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">COMPLIANCE</div>
                <div class="kpi-number u-fs-14px u-fw-600 {{ $expiredCount > 0 ? 'u-c-danger' : 'u-c-success' }}">
                    {{ $expiredCount > 0 ? 'Action Required' : 'All Batches Valid' }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Data Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="report-table" class="table table-hover u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.Image') }}</th>
                        <th>{{ __('db.Product Name') }}</th>
                        <th>{{ __('db.Product Code') }}</th>
                        <th>{{ __('db.Batch No') }}</th>
                        <th>{{ __('db.Expiry Date') }}</th>
                        <th>{{ __('db.Quantity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lims_product_data as $key => $product)
                        <tr>
                            <td>{{ $key }}</td>
                            <td>
                                <?php
                                $images = explode(",", $product->image);
                                $product->base_image = $images[0];
                                ?>
                                @if($product->base_image)
                                    <img src="{{ url('images/product', $product->base_image) }}" height="48" width="48" class="u-of-cover u-r-4px u-b-1px-solid-border">
                                @else
                                    <div class="u-w-48px u-h-48px u-bg-surface-3 u-r-4px u-d-flex u-items-center u-justify-center u-c-text-muted">
                                        <i class="dripicons-box u-fs-18px"></i>
                                    </div>
                                @endif
                            </td>
                            <td><span class="font-weight-600">{{ $product->name }}</span></td>
                            <td><span class="font-weight-bold font-monospace text-primary">{{ $product->code }}</span></td>
                            <td><span class="badge badge-light border">{{ $product->batch_no }}</span></td>
                            <td>
                                @if(date('Y-m-d') >= $product->expired_date)
                                    <span class="badge badge-danger font-weight-bold u-fs-11_5px u-p-4px-8px">
                                        Expired: {{ date($general_setting->date_format, strtotime($product->expired_date)) }}
                                    </span>
                                @else
                                    <span class="badge badge-warning font-weight-bold text-dark u-fs-11_5px u-p-4px-8px">
                                        Expires: {{ date($general_setting->date_format, strtotime($product->expired_date)) }}
                                    </span>
                                @endif
                            </td>
                            <td class="font-weight-bold">{{ number_format((float)($product->qty), $general_setting->decimal, '.', '') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #productExpiry-report-menu").addClass("active");

    $('#report-table').DataTable({
        "order": [],
        'language': {
            'lengthMenu': '_MENU_ {{ __('db.records per page') }}',
            "info": '<small>{{ __('db.Showing') }} _START_ - _END_ (_TOTAL_)</small>',
            "search": '{{ __('db.Search') }}',
            'paginate': {
                'previous': '<i class="dripicons-chevron-left"></i>',
                'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        'columnDefs': [
            {
                "orderable": false,
                'targets': 0
            },
            {
                'render': function(data, type, row, meta) {
                    if (type === 'display') {
                        data = '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>';
                    }
                    return data;
                },
                'checkboxes': {
                    'selectRow': true,
                    'selectAllRender': '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>'
                },
                'targets': [0]
            }
        ],
        'select': { style: 'multi', selector: 'td:first-child' },
        'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
        dom: '<"row align-items-center mb-2"<"col-md-6 d-flex align-items-center gap-2"lB><"col-md-6 d-flex justify-content-end"f>r>t<"row align-items-center mt-2"<"col-md-6"i><"col-md-6 d-flex justify-content-end"p>>',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o text-danger"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
            },
            {
                extend: 'excel',
                text: '<i title="export to excel" class="dripicons-document-new text-success"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o text-info"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print text-primary"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            }
        ],
    });
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-product-expiry-report.css') }}">
@endpush
