@extends('backend.layout.main') @section('content')


<x-error-message key="not_permitted" />

@php
    $supplierName = 'All Suppliers';
    if(isset($supplier_id) && $supplier_id > 0) {
        $sup = \App\Models\Supplier::find($supplier_id);
        if($sup) $supplierName = $sup->name . ' (' . ($sup->company_name ?? '') . ')';
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-user-id text-primary"></i> {{ __('db.Supplier Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> 360° Vendor Payable &amp; Procurement History</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/supplier') }}"><i class="dripicons-user-id"></i> Supplier Report</a></li>
            <li><a class="nav-link" href="{{ route('report.supplierDueByDate') }}"><i class="dripicons-warning"></i> Supplier Due</a></li>
            <li><a class="nav-link" href="{{ url('report/purchase') }}"><i class="dripicons-basket"></i> Purchases</a></li>
            <li><a class="nav-link" href="{{ route('report.paymentByDate') }}"><i class="dripicons-wallet"></i> Payments</a></li>
            <li><a class="nav-link" href="{{ route('report.qtyAlert') }}"><i class="dripicons-alert"></i> Qty Alerts</a></li>
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
                <div class="kpi-label">SELECTED SUPPLIER</div>
                <div class="kpi-number u-fs-14px u-fw-700">{{ $supplierName }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-user-id u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">AUDITED SEGMENTS</div>
                <div class="kpi-number u-fs-14px u-fw-700">4 Operations</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-list u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">VENDOR RECONCILIATION</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Verified Active</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.supplier', 'method' => 'post', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) !!}
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
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-user-id"></i> {{ __('db.Choose Supplier') }}</label>
                <input type="hidden" name="supplier_id_hidden" value="{{ $supplier_id }}" />
                <select id="supplier_id" name="supplier_id" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-200px">
                    @foreach ($lims_supplier_list as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }} ({{ $supplier->phone_number }})</option>
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

    <!-- Multi-Operation Segmented Tabs -->
    <ul class="nav desk-report-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" href="#supplier-purchase" role="tab" data-toggle="tab"><i class="dripicons-basket"></i> {{ __('db.Purchase') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#supplier-payments" role="tab" data-toggle="tab"><i class="dripicons-wallet"></i> {{ __('db.Payment') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#supplier-return" role="tab" data-toggle="tab"><i class="dripicons-return"></i> {{ __('db.return') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#supplier-quotation" role="tab" data-toggle="tab"><i class="dripicons-document"></i> {{ __('db.Quotation') }}</a>
        </li>
    </ul>

    <!-- Tab Panels Container -->
    <div class="tab-content">
        <div role="tabpanel" class="tab-pane fade show active" id="supplier-purchase">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="purchase-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-purchase"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
                                <th>{{ __('db.product') }} ({{ __('db.qty') }})</th>
                                <th>{{ __('db.grand total') }}</th>
                                <th>{{ __('db.Paid') }}</th>
                                <th>{{ __('db.Balance') }}</th>
                                <th>{{ __('db.status') }}</th>
                            </tr>
                        </thead>
                        <tfoot class="tfoot active">
                            <tr>
                                <th></th>
                                <th>{{ __('db.Total') }}</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="supplier-payments">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="payment-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-payment"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.Payment Reference') }}</th>
                                <th>{{ __('db.Purchase Reference') }}</th>
                                <th>{{ __('db.Amount') }}</th>
                                <th>{{ __('db.Paid Method') }}</th>
                            </tr>
                        </thead>
                        <tfoot class="tfoot active">
                            <tr>
                                <th></th>
                                <th>{{ __('db.Total') }}</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="supplier-return">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="return-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-return"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
                                <th>{{ __('db.product') }} ({{ __('db.qty') }})</th>
                                <th>{{ __('db.grand total') }}</th>
                            </tr>
                        </thead>
                        <tfoot class="tfoot active">
                            <tr>
                                <th></th>
                                <th>{{ __('db.Total') }}</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="supplier-quotation">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="quotation-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-quotation"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
                                <th>{{ __('db.customer') }}</th>
                                <th>{{ __('db.product') }} ({{ __('db.qty') }})</th>
                                <th>{{ __('db.grand total') }}</th>
                                <th>{{ __('db.status') }}</th>
                            </tr>
                        </thead>
                        <tfoot class="tfoot active">
                            <tr>
                                <th></th>
                                <th>{{ __('db.Total') }}</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #supplier-report-menu").addClass("active");

    var start_date = <?php echo json_encode($start_date); ?>;
    var end_date = <?php echo json_encode($end_date); ?>;
    var supplier_id = <?php echo json_encode($supplier_id); ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $('#supplier_id').val($('input[name="supplier_id_hidden"]').val());
    $('.selectpicker').selectpicker('refresh');

    $('#purchase-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"supplier-purchase-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                supplier_id: supplier_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "warehouse"},
            {"data": "product"},
            {"data": "grand_total"},
            {"data": "paid"},
            {"data": "balance"},
            {"data": "status"}
        ],
        'language': {

            'lengthMenu': '_MENU_ {{__("db.records per page")}}',
             "info":      '<small>{{__("db.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
            "search":  '{{__("db.Search")}}',
            'paginate': {
                    'previous': '<i class="dripicons-chevron-left"></i>',
                    'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        order:[['1', 'desc']],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 3, 4, 5, 6, 7, 8]
            },
            {
                'render': function(data, type, row, meta){
                    if(type === 'display'){
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
        'select': { style: 'multi',  selector: 'td:first-child'},
        'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
        dom: '<"row"lfB>rtip',
        rowId: 'ObjectID',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-sale)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_sale(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_sale(dt, false);
                },
                footer:true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-sale)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_sale(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_sale(dt, false);
                },
                footer:true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-sale)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_sale(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_sale(dt, false);
                },
                footer:true
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
        drawCallback: function () {
            var api = this.api();
            datatable_sum_sale(api, false);
        }
    });

    function datatable_sum_sale(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 5 ).footer() ).html(dt_selector.cells( rows, 5, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.cells( rows, 6, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 7 ).footer() ).html(dt_selector.cells( rows, 7, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 5 ).footer() ).html(dt_selector.column( 5, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.column( 6, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 7 ).footer() ).html(dt_selector.cells( rows, 7, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#payment-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"supplier-payment-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                supplier_id: supplier_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "purchase_reference"},
            {"data": "amount"},
            {"data": "paying_method"}
        ],
        'language': {

            'lengthMenu': '_MENU_ {{__("db.records per page")}}',
             "info":      '<small>{{__("db.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
            "search":  '{{__("db.Search")}}',
            'paginate': {
                    'previous': '<i class="dripicons-chevron-left"></i>',
                    'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        order:[['1', 'desc']],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 2, 3, 4]
            },
            {
                'render': function(data, type, row, meta){
                    if(type === 'display'){
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
        'select': { style: 'multi',  selector: 'td:first-child'},
        'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
        dom: '<"row"lfB>rtip',
        rowId: 'ObjectID',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-payment)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_payment(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_payment(dt, false);
                },
                footer:true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-payment)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_payment(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_payment(dt, false);
                },
                footer:true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-payment)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_payment(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_payment(dt, false);
                },
                footer:true
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
        drawCallback: function () {
            var api = this.api();
            datatable_sum_payment(api, false);
        }
    });

    function datatable_sum_payment(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 4 ).footer() ).html(dt_selector.cells( rows, 4, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 4 ).footer() ).html(dt_selector.column( 4, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#return-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"supplier-return-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                supplier_id: supplier_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "warehouse"},
            {"data": "product"},
            {"data": "grand_total"}
        ],
        'language': {

            'lengthMenu': '_MENU_ {{__("db.records per page")}}',
             "info":      '<small>{{__("db.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
            "search":  '{{__("db.Search")}}',
            'paginate': {
                    'previous': '<i class="dripicons-chevron-left"></i>',
                    'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        order:[['1', 'desc']],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 3, 4, 5]
            },
            {
                'render': function(data, type, row, meta){
                    if(type === 'display'){
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
        'select': { style: 'multi',  selector: 'td:first-child'},
        'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
        dom: '<"row"lfB>rtip',
        rowId: 'ObjectID',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-return)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_return(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_return(dt, false);
                },
                footer:true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-return)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_return(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_return(dt, false);
                },
                footer:true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-return)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_return(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_return(dt, false);
                },
                footer:true
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
        drawCallback: function () {
            var api = this.api();
            datatable_sum_return(api, false);
        }
    });

    function datatable_sum_return(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 5 ).footer() ).html(dt_selector.cells( rows, 5, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 5 ).footer() ).html(dt_selector.column( 5, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#quotation-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"supplier-quotation-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                supplier_id: supplier_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "warehouse"},
            {"data": "customer"},
            {"data": "product"},
            {"data": "grand_total"},
            {"data": "status"}
        ],
        'language': {

            'lengthMenu': '_MENU_ {{__("db.records per page")}}',
             "info":      '<small>{{__("db.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
            "search":  '{{__("db.Search")}}',
            'paginate': {
                    'previous': '<i class="dripicons-chevron-left"></i>',
                    'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        order:[['1', 'desc']],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 3, 4, 5, 6, 7]
            },
            {
                'render': function(data, type, row, meta){
                    if(type === 'display'){
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
        'select': { style: 'multi',  selector: 'td:first-child'},
        'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
        dom: '<"row"lfB>rtip',
        rowId: 'ObjectID',
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-quotation)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_quotation(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_quotation(dt, false);
                },
                footer:true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-quotation)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_quotation(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_quotation(dt, false);
                },
                footer:true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported-quotation)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum_quotation(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_quotation(dt, false);
                },
                footer:true
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
        drawCallback: function () {
            var api = this.api();
            datatable_sum_quotation(api, false);
        }
    });

    function datatable_sum_quotation(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 6 ).footer() ).html(dt_selector.cells( rows, 6, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.column( 6, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

$(".daterangepicker-field").daterangepicker({
  callback: function(startDate, endDate, period){
    var start_date = startDate.format('YYYY-MM-DD');
    var end_date = endDate.format('YYYY-MM-DD');
    var title = start_date + ' to ' + end_date;
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
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-biller-report.css') }}">
@endpush
