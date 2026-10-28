@extends('backend.layout.main') @section('content')


<x-error-message key="not_permitted" />

@php
    $warehouseName = 'All Warehouses';
    if($warehouse_id && $warehouse_id > 0) {
        $wh = \App\Models\Warehouse::find($warehouse_id);
        if($wh) $warehouseName = $wh->name;
    }
    $categoryName = 'All Categories';
    if(isset($category_id) && $category_id > 0) {
        $cat = \App\Models\Category::find($category_id);
        if($cat) $categoryName = $cat->name;
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-basket text-primary"></i> {{ __('db.Purchase Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Inward Procurement &amp; Stock Acquisition</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
            <li><a class="nav-link active" href="{{ url('report/purchase') }}"><i class="dripicons-basket"></i> Purchase Report</a></li>
            <li><a class="nav-link" href="{{ url('report/product_report') }}"><i class="dripicons-box"></i> Product Report</a></li>
            <li><a class="nav-link" href="{{ route('report.paymentByDate') }}"><i class="dripicons-wallet"></i> Payments</a></li>
            <li><a class="nav-link" href="{{ route('report.challan') }}"><i class="dripicons-clipboard"></i> Challan Report</a></li>
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
                <div class="kpi-label">REPORT TIMEFRAME</div>
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
                <div class="kpi-label">WAREHOUSE SCOPE</div>
                <div class="kpi-number u-fs-14px u-fw-700">{{ $warehouseName }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-store u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">CATEGORY FILTER</div>
                <div class="kpi-number u-fs-14px u-fw-700">{{ $categoryName }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-tags u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">REPORT STATUS</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Live DataFeed</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.purchase', 'method' => 'post', 'class' => 'product-report-filter form-inline d-flex align-items-center flex-wrap gap-2']) !!}
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> {{ __('db.Choose Your Date') }}</label>
                <div class="input-group">
                    <input type="text" class="daterangepicker-field form-control form-control-sm u-h-30px u-fs-12px u-w-210px"
                        value="{{ $start_date }} To {{ $end_date }}" required />
                    <input type="hidden" name="start_date" value="{{ $start_date }}" />
                    <input type="hidden" name="end_date" value="{{ $end_date }}" />
                </div>
            </div>
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-store"></i> {{ __('db.Choose Warehouse') }}</label>
                <select name="warehouse_id" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-160px">
                    <option value="0">{{ __('db.All Warehouse') }}</option>
                    @foreach ($lims_warehouse_list as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-tags"></i> {{ __('db.category') }}</label>
                <select name="category_id" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-160px">
                    <option value="0">All Category</option>
                    @foreach ($categories_list as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
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
            <table id="product-report-table" class="table table-hover u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.Product Name') }}</th>
                        <th>{{ __('db.category') }}</th>
                        <th>{{ __('db.Purchased Amount') }}</th>
                        <th>{{ __('db.Purchased') }} {{ __('db.qty') }}</th>
                        <th>{{ __('db.In Stock') }}</th>
                    </tr>
                </thead>
                <tfoot class="tfoot active">
                    <th></th>
                    <th>{{ __('db.Total') }}</th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tfoot>
            </table>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    var warehouse_id = <?php echo json_encode($warehouse_id) ?>;
    var category_id = <?php echo json_encode($category_id) ?>;
    $('.product-report-filter select[name="warehouse_id"]').val(warehouse_id);
    $('.product-report-filter select[name="category_id"]').val(category_id);

    $(".daterangepicker-field").daterangepicker({
        callback: function(startDate, endDate, period) {
            var start_date = startDate.format('YYYY-MM-DD');
            var end_date = endDate.format('YYYY-MM-DD');
            var title = start_date + ' To ' + end_date;
            $(this).val(title);
            $(".product-report-filter input[name=start_date]").val(start_date);
            $(".product-report-filter input[name=end_date]").val(end_date);
        }
    });

    var start_date = $(".product-report-filter input[name=start_date]").val();
    var end_date = $(".product-report-filter input[name=end_date]").val();
    var warehouse_id = $(".product-report-filter select[name=warehouse_id]").val();
    var category_id = $(".product-report-filter select[name=category_id]").val();

    $('#product-report-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            url: "purchase_report_data",
            data: {
                start_date: start_date,
                end_date: end_date,
                warehouse_id: warehouse_id,
                category_id: category_id
            },
            dataType: "json",
            type: "post"
        },
        "columns": [
            {"data": "key"},
            {"data": "name"},
            {"data": "category"},
            {"data": "purchased_amount"},
            {"data": "purchased_qty"},
            {"data": "in_stock"},
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
                'targets': [0, 3, 4]
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
        'lengthMenu': [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
        dom: '<"row align-items-center mb-2"<"col-md-6 d-flex align-items-center gap-2"lB><"col-md-6 d-flex justify-content-end"f>r>t<"row align-items-center mt-2"<"col-md-6"i><"col-md-6 d-flex justify-content-end"p>>',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o text-danger"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'excel',
                text: '<i title="export to excel" class="dripicons-document-new text-success"></i>',
                exportOptions: {
                    columns: ':visible:not(.not-exported)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o text-info"></i>',
                exportOptions: {
                    columns: ':visible:not(.not-exported)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print text-primary"></i>',
                exportOptions: {
                    columns: ':visible:not(.not-exported)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
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
            datatable_sum(api, false);
        }
    });

    function datatable_sum(dt_selector, is_calling_first) {
        if (dt_selector.rows('.selected').any() && is_calling_first) {
            var rows = dt_selector.rows('.selected').indexes();
            $(dt_selector.column(3).footer()).html(dt_selector.cells(rows, 3, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(4).footer()).html(dt_selector.cells(rows, 4, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(5).footer()).html(dt_selector.cells(rows, 5, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
        } else {
            $(dt_selector.column(3).footer()).html(dt_selector.column(3, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(4).footer()).html(dt_selector.column(4, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
            $(dt_selector.column(5).footer()).html(dt_selector.column(5, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
        }
    }
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-product-report.css') }}">
@endpush
