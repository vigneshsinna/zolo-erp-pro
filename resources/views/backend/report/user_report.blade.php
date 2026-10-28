@extends('backend.layout.main') @section('content')


<x-error-message key="not_permitted" />

@php
    $userName = 'All Users';
    if($user_id && $user_id > 0) {
        $u = \App\Models\User::find($user_id);
        if($u) $userName = $u->name;
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-user text-primary"></i> {{ __('db.User Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Multi-Disciplinary Staff Audit</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/user_report') }}"><i class="dripicons-user"></i> User Report</a></li>
            <li><a class="nav-link" href="{{ url('report/customer_report') }}"><i class="dripicons-user-group"></i> Customers</a></li>
            <li><a class="nav-link" href="{{ url('report/supplier') }}"><i class="dripicons-user-id"></i> Suppliers</a></li>
            <li><a class="nav-link" href="{{ url('report/biller') }}"><i class="dripicons-briefcase"></i> Billers</a></li>
            <li><a class="nav-link" href="{{ route('report.paymentByDate') }}"><i class="dripicons-wallet"></i> Payments</a></li>
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
                <div class="kpi-label">AUDITED USER</div>
                <div class="kpi-number u-fs-14px u-fw-700">{{ $userName }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-user u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">OPERATION DOMAINS</div>
                <div class="kpi-number u-fs-14px u-fw-700">7 Modules</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-list u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">SYSTEM AUDIT</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Verified Active</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.user', 'method' => 'post', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) !!}
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
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-user"></i> {{ __('db.Choose User') }}</label>
                <input type="hidden" name="user_id_hidden" value="{{ $user_id }}" />
                <select id="user_id" name="user_id" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-200px">
                    @foreach ($lims_user_list as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->phone }})</option>
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
            <a class="nav-link active" href="#user-sale" role="tab" data-toggle="tab"><i class="dripicons-cart"></i> {{ __('db.Sale') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user-purchase" role="tab" data-toggle="tab"><i class="dripicons-basket"></i> {{ __('db.Purchase') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user-quotation" role="tab" data-toggle="tab"><i class="dripicons-document"></i> {{ __('db.Quotation') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user-transfer" role="tab" data-toggle="tab"><i class="dripicons-export"></i> {{ __('db.Transfer') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user-payments" role="tab" data-toggle="tab"><i class="dripicons-wallet"></i> {{ __('db.Payment') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user-expense" role="tab" data-toggle="tab"><i class="dripicons-ticket"></i> {{ __('db.Expense') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user-payroll" role="tab" data-toggle="tab"><i class="dripicons-card"></i> {{ __('db.Payroll') }}</a>
        </li>
    </ul>

    <!-- Tab Panels Container -->
    <div class="tab-content">
        <div role="tabpanel" class="tab-pane fade show active" id="user-sale">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="sale-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-sale"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.customer') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
                                <th>{{ __('db.product') }} ({{ __('db.qty') }})</th>
                                <th>{{ __('db.grand total') }}</th>
                                <th>{{ __('db.Paid') }}</th>
                                <th>{{ __('db.Due') }}</th>
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
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="user-purchase">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="purchase-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-purchase"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Supplier') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
                                <th>{{ __('db.product') }} ({{ __('db.qty') }})</th>
                                <th>{{ __('db.grand total') }}</th>
                                <th>{{ __('db.Paid Amount') }}</th>
                                <th>{{ __('db.Due') }}</th>
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
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="user-quotation">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="quotation-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-quotation"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.customer') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
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

        <div role="tabpanel" class="tab-pane fade" id="user-transfer">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="transfer-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-transfer"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.From') }}</th>
                                <th>{{ __('db.To') }}</th>
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

        <div role="tabpanel" class="tab-pane fade" id="user-payments">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="payment-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-payment"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Amount') }}</th>
                                <th>{{ __('db.Paid Method') }}</th>
                            </tr>
                        </thead>
                        <tfoot class="tfoot active">
                            <tr>
                                <th></th>
                                <th>{{ __('db.Total') }}</th>
                                <th></th>
                                <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="user-expense">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="expense-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-expense"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Warehouse') }}</th>
                                <th>{{ __('db.category') }}</th>
                                <th>{{ __('db.Amount') }}</th>
                                <th>{{ __('db.Note') }}</th>
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
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div role="tabpanel" class="tab-pane fade" id="user-payroll">
            <div class="desk-card-wrapper">
                <div class="table-responsive">
                    <table id="payroll-table" class="table table-hover u-w-100pct">
                        <thead>
                            <tr>
                                <th class="not-exported-payroll"></th>
                                <th>{{ __('db.date') }}</th>
                                <th>{{ __('db.reference') }}</th>
                                <th>{{ __('db.Employee') }}</th>
                                <th>{{ __('db.Amount') }}</th>
                                <th>{{ __('db.Method') }}</th>
                            </tr>
                        </thead>
                        <tfoot class="tfoot active">
                            <tr>
                                <th></th>
                                <th>{{ __('db.Total') }}</th>
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
</section>


@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #user-report-menu").addClass("active");

    var start_date = <?php echo json_encode($start_date); ?>;
    var end_date = <?php echo json_encode($end_date); ?>;
    var user_id = <?php echo json_encode($user_id); ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $('#user_id').val($('input[name="user_id_hidden"]').val());
    $('.selectpicker').selectpicker('refresh');

    $('#sale-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-sale-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "customer"},
            {"data": "warehouse"},
            {"data": "product"},
            {"data": "grand_total"},
            {"data": "paid"},
            {"data": "due"},
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

            $( dt_selector.column( 8 ).footer() ).html(dt_selector.cells( rows, 8, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.cells( rows, 6, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 7 ).footer() ).html(dt_selector.cells( rows, 7, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 8 ).footer() ).html(dt_selector.column( 8, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.column( 6, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 7 ).footer() ).html(dt_selector.cells( rows, 7, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#purchase-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-purchase-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "supplier"},
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

            $( dt_selector.column( 8 ).footer() ).html(dt_selector.cells( rows, 8, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.cells( rows, 6, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 7 ).footer() ).html(dt_selector.cells( rows, 7, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 8 ).footer() ).html(dt_selector.column( 8, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 6 ).footer() ).html(dt_selector.column( 6, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
            $( dt_selector.column( 7 ).footer() ).html(dt_selector.cells( rows, 7, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#quotation-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-quotation-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "customer"},
            {"data": "warehouse"},
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

    $('#transfer-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-transfer-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "fromWarehouse"},
            {"data": "toWarehouse"},
            {"data": "product"},
            {"data": "grandTotal"},
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
                    datatable_sum_transfer(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_transfer(dt, false);
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
                    datatable_sum_transfer(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_transfer(dt, false);
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
                    datatable_sum_transfer(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_transfer(dt, false);
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
            datatable_sum_transfer(api, false);
        }
    });

    function datatable_sum_transfer(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 6 ).footer() ).html(dt_selector.cells( rows, 6, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {

            $( dt_selector.column( 6 ).footer() ).html(dt_selector.column( 6, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#payment-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-payment-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
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

            $( dt_selector.column( 3 ).footer() ).html(dt_selector.cells( rows, 3, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 3 ).footer() ).html(dt_selector.column( 3, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#expense-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-expense-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "warehouse"},
            {"data": "category"},
            {"data": "amount"},
            {"data": "note"}
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
                'targets': [0, 5]
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
                    datatable_sum_expense(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_expense(dt, false);
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
                    datatable_sum_expense(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_expense(dt, false);
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
                    datatable_sum_expense(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_expense(dt, false);
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
            datatable_sum_expense(api, false);
        }
    });

    function datatable_sum_expense(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 5 ).footer() ).html(dt_selector.cells( rows, 5, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 5 ).footer() ).html(dt_selector.column( 5, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    $('#payroll-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"user-payroll-data",
            data:{
                start_date: start_date,
                end_date: end_date,
                user_id: user_id
            },
            dataType: "json",
            type:"post"
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "employee"},
            {"data": "amount"},
            {"data": "method"}
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
                    datatable_sum_payroll(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_payroll(dt, false);
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
                    datatable_sum_payroll(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum_payroll(dt, false);
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
                    datatable_sum_payroll(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum_payroll(dt, false);
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
            datatable_sum_payroll(api, false);
        }
    });

    function datatable_sum_payroll(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();

            $( dt_selector.column( 4 ).footer() ).html(dt_selector.cells( rows, 4, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 4 ).footer() ).html(dt_selector.column( 4, {page:'current'} ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

$(".daterangepicker-field").daterangepicker({
  callback: function(startDate, endDate, period){
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
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/report-user-report.css') }}">
@endpush
