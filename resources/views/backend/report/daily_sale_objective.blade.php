@extends('backend.layout.main') @section('content')


<x-success-message key="message" />
<x-error-message key="not_permitted" />

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-target text-primary"></i> {{ __('db.Daily Sale Objective Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Velocity &amp; Target Realization</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
            <li><a class="nav-link active" href="{{ route('report.dailySaleObjective') }}"><i class="dripicons-target"></i> Daily Objectives</a></li>
            <li><a class="nav-link" href="{{ url('report/daily_sale/'.date('Y').'/'.date('m')) }}"><i class="dripicons-calendar"></i> Daily Calendar</a></li>
            <li><a class="nav-link" href="{{ url('report/monthly_sale/'.date('Y')) }}"><i class="dripicons-calendar"></i> Monthly Calendar</a></li>
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
                <div class="kpi-label">AUDIT WINDOW</div>
                <div class="kpi-number u-fs-13px u-fw-600 u-c-text-2">
                    {{ date('d M Y', strtotime($starting_date)) }} — {{ date('d M Y', strtotime($ending_date)) }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-calendar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TRACKING</div>
                <div class="kpi-number u-fs-14px u-fw-700">Sales Lines &amp; Volumes</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-graph-line u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">OBJECTIVE TYPE</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-info">Daily SKU Count</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-box u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">STATE</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Active Targets</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.dailySaleObjective', 'method' => 'get', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) !!}
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> {{ __('db.date') }}</label>
                <div class="input-group">
                    <input type="text" class="daterangepicker-field form-control form-control-sm u-h-30px u-fs-12px u-w-210px"
                        value="{{ $starting_date }} To {{ $ending_date }}" required />
                    <input type="hidden" name="starting_date" value="{{ $starting_date }}" />
                    <input type="hidden" name="ending_date" value="{{ $ending_date }}" />
                </div>
            </div>
            <div class="form-group mb-1">
                <button class="btn btn-primary btn-sm btn-action-pill u-bg-ink u-bc-ink" id="filter-btn" type="submit">
                    <i class="fa fa-filter"></i> {{ __('db.submit') }}
                </button>
            </div>
        {!! Form::close() !!}
    </div>

    <!-- Data Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="sale-table" class="table sale-list u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.date') }}</th>
                        <th>{{ __('db.Product Info') }}</th>
                        <th>{{ __('db.Number of Products') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #daily-sale-objective-menu").addClass("active");

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $(".daterangepicker-field").daterangepicker({
        callback: function(startDate, endDate, period){
            var starting_date = startDate.format('YYYY-MM-DD');
            var ending_date = endDate.format('YYYY-MM-DD');
            var title = starting_date + ' To ' + ending_date;
            $(this).val(title);
            $('input[name="starting_date"]').val(starting_date);
            $('input[name="ending_date"]').val(ending_date);
        }
    });

    var starting_date = $("input[name=starting_date]").val();
    var ending_date = $("input[name=ending_date]").val();

    $('#sale-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            url: "daily-sale-objective-data",
            data: {
                starting_date: starting_date,
                ending_date: ending_date
            },
            dataType: "json",
            type: "post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "product_info"},
            {"data": "number_of_products"},
        ],
        'language': {
            'lengthMenu': '_MENU_ {{ __('db.records per page') }}',
            "info": '<small>{{ __('db.Showing') }} _START_ - _END_ (_TOTAL_)</small>',
            "search": '{{ __('db.Search') }}',
            'paginate': {
                'previous': '<i class="dripicons-chevron-left"></i>',
                'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        order: [['1', 'desc']],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 2, 3]
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
        rowId: 'ObjectID',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o text-danger"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                }
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o text-info"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                }
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print text-primary"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                }
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ]
    });
</script>
<script type="text/javascript" src="https://js.stripe.com/v3/"></script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/report-daily-sale-objective.css') }}">
@endpush
