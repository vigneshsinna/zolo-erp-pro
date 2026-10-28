@extends('backend.layout.main') @section('content')


<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-user text-primary"></i> {{ __('db.Customer Due Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Receivables &amp; Outstanding Invoices</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ route('report.customerDueByDate') }}"><i class="dripicons-user"></i> Customer Due</a></li>
            <li><a class="nav-link" href="{{ route('report.supplierDueByDate') }}"><i class="dripicons-user-id"></i> Supplier Due</a></li>
            <li><a class="nav-link" href="{{ route('accounting.ageing') }}"><i class="dripicons-clock"></i> Ageing Ledger</a></li>
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
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
                    {{ date('d M Y', strtotime($start_date)) }} — {{ date('d M Y', strtotime($end_date)) }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-calendar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">LEDGER STATUS</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-danger">Outstanding Receivables</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-arrow-down u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">SCOPE</div>
                <div class="kpi-number u-fs-14px u-fw-700">Customer Invoices</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-document u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">FEED</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Real-Time Sync</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.customerDueByDate', 'method' => 'post', 'id' => 'customer-due-report-form', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) !!}
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> {{ __('db.Choose Your Date') }}</label>
                <div class="input-group">
                    <input type="text" class="daterangepicker-field form-control form-control-sm u-h-30px u-fs-12px u-w-210px"
                        value="{{ $start_date }} To {{ $end_date }}" required />
                    <input type="hidden" name="start_date" value="{{ $start_date }}" />
                    <input type="hidden" name="end_date" value="{{ $end_date }}" />
                </div>
            </div>
            <div class="form-group mb-1">
                <button class="btn btn-primary btn-sm btn-action-pill u-bg-ink u-bc-ink" type="submit">
                    <i class="fa fa-filter"></i> {{ __('db.submit') }}
                </button>
            </div>
        {!! Form::close() !!}
    </div>

    <!-- Data Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="report-table" class="table table-hover u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.date') }}</th>
                        <th>{{ __('db.reference') }}</th>
                        <th>{{ __('db.Customer Details') }}</th>
                        <th>{{ __('db.grand total') }}</th>
                        <th>{{ __('db.Returned Amount') }}</th>
                        <th>{{ __('db.Paid') }}</th>
                        <th>{{ __('db.Due') }}</th>
                    </tr>
                </thead>
                <tfoot class="tfoot active">
                    <th></th>
                    <th>{{ __('db.Total') }}:</th>
                    <th></th>
                    <th></th>
                    <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                    <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                    <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                    <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                </tfoot>
            </table>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #due-report-menu").addClass("active");

    var start_date = <?php echo json_encode($start_date); ?>;
    var end_date = <?php echo json_encode($end_date); ?>;
    var customer_id = <?php echo json_encode($customer_id ?? 0); ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $('#report-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            url: "customer-due-report-data",
            data: {
                start_date: start_date,
                end_date: end_date,
                customer_id: customer_id,
            },
            dataType: "json",
            type: "post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "customer"},
            {"data": "grand_total"},
            {"data": "returned_amount"},
            {"data": "paid"},
            {"data": "due"}
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
                    columns: ':visible:Not(.not-exported-sale)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_due(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_due(dt, false);
                },
                footer: true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o text-info"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-sale)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_due(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_due(dt, false);
                },
                footer: true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print text-primary"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-sale)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_due(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_due(dt, false);
                },
                footer: true
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
        drawCallback: function() {
            var api = this.api();
            datatable_sum_due(api, false);
        }
    });

    function datatable_sum_due(dt_selector, is_calling_first) {
        if (dt_selector.rows('.selected').any() && is_calling_first) {
            var rows = dt_selector.rows('.selected').indexes();
            $(dt_selector.column(4).footer()).html(dt_selector.cells(rows, 4, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(5).footer()).html(dt_selector.cells(rows, 5, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(6).footer()).html(dt_selector.cells(rows, 6, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(7).footer()).html(dt_selector.cells(rows, 7, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
        } else {
            $(dt_selector.column(4).footer()).html(dt_selector.column(4, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(5).footer()).html(dt_selector.column(5, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(6).footer()).html(dt_selector.column(6, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(7).footer()).html(dt_selector.column(7, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
        }
    }

    $(".daterangepicker-field").daterangepicker({
        callback: function(startDate, endDate, period) {
            var start_date = startDate.format('YYYY-MM-DD');
            var end_date = endDate.format('YYYY-MM-DD');
            var title = start_date + ' To ' + end_date;
            $(this).val(title);
            $('input[name="start_date"]').val(start_date);
            $('input[name="end_date"]').val(end_date);
        }
    });
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-due-report.css') }}">
@endpush
