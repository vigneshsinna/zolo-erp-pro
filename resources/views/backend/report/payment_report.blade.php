@extends('backend.layout.main') @section('content')


@php
    $totalPaymentsCount = count($lims_payment_data);
    $totalAmount = 0;
    foreach($lims_payment_data as $p) {
        $totalAmount += (float)$p->amount;
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-wallet text-primary"></i> {{ __('db.Payment Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Inflows &amp; Outflows Ledger</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
            <li><a class="nav-link" href="{{ url('report/purchase') }}"><i class="dripicons-basket"></i> Purchase Report</a></li>
            <li><a class="nav-link" href="{{ url('report/product_report') }}"><i class="dripicons-box"></i> Product Report</a></li>
            <li><a class="nav-link active" href="{{ route('report.paymentByDate') }}"><i class="dripicons-wallet"></i> Payments</a></li>
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
                <div class="kpi-label">TOTAL PAYMENTS LOGGED</div>
                <div class="kpi-number">{{ number_format($totalPaymentsCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL VALUE</div>
                <div class="kpi-number u-c-success">₹ {{ number_format($totalAmount, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-arrow-up u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">PAYMENT METHOD</div>
                <div class="kpi-number u-fs-14px u-fw-700">{{ request('payment_method') ?: 'All Methods' }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-card u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">WINDOW</div>
                <div class="kpi-number u-fs-13px u-fw-600 u-c-text-2">
                    {{ date('d M Y', strtotime($start_date)) }} — {{ date('d M Y', strtotime($end_date)) }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-calendar u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.paymentByDate', 'method' => 'post', 'class' => 'product-report-filter form-inline d-flex align-items-center flex-wrap gap-2']) !!}
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
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-card"></i> {{ __('db.Payment Method') }}</label>
                <select name="payment_method" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-140px">
                    <option value="">{{ __('db.All') }}</option>
                    <option value="Cash" @selected(request('payment_method') == 'Cash')>Cash</option>
                    <option value="Card" @selected(request('payment_method') == 'Card')>Card</option>
                    <option value="Credit Card" @selected(request('payment_method') == 'Credit Card')>Credit Card</option>
                    <option value="Bank" @selected(request('payment_method') == 'Bank')>Bank</option>
                    <option value="Cheque" @selected(request('payment_method') == 'Cheque')>Cheque</option>
                    <option value="Moneipoint" @selected(request('payment_method') == 'Moneipoint')>Moneipoint</option>
                    <option value="Pesapal" @selected(request('payment_method') == 'Pesapal')>Pesapal</option>
                    <option value="Points" @selected(request('payment_method') == 'Points')>Points</option>
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
            <table id="report-table" class="table table-hover u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.date') }}</th>
                        <th>{{ __('db.Payment Reference') }}</th>
                        <th>{{ __('db.Sale Reference') }}</th>
                        <th>{{ __('db.Purchase Reference') }}</th>
                        <th>{{ __('db.Paid By') }}</th>
                        <th>{{ __('db.Amount') }}</th>
                        <th>{{ __('db.Created By') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lims_payment_data as $payment)
                        <?php
                        $sale = DB::table('sales')->find($payment->sale_id);
                        $purchase = DB::table('purchases')->find($payment->purchase_id);
                        $user = DB::table('users')->find($payment->user_id);
                        ?>
                        <tr>
                            <td></td>
                            <td>{{ date($general_setting->date_format, strtotime($payment->created_at->toDateString())) . ' ' . $payment->created_at->toTimeString() }}</td>
                            <td><span class="font-weight-bold font-monospace text-primary">{{ $payment->payment_reference }}</span></td>
                            <td>@if ($sale) {{ $sale->reference_no }} @else - @endif</td>
                            <td>@if ($purchase) {{ $purchase->reference_no }} @else - @endif</td>
                            <td><span class="badge badge-secondary">{{ $payment->paying_method }}</span></td>
                            <td class="font-weight-bold">{{ number_format($payment->amount, $general_setting->decimal) }}</td>
                            <td>{{ $user ? $user->name : 'N/A' }}<br><small class="text-muted">{{ $user ? $user->email : '' }}</small></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="tfoot active">
                    <th></th>
                    <th>{{ __('db.Total') }}:</th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th>{{ number_format(0, $general_setting->decimal, '.', '') }}</th>
                    <th></th>
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
    $("ul#report li#payment-report-menu").addClass("active");

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
                    columns: ':visible:Not(.not-exported)',
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
                    columns: ':visible:Not(.not-exported)',
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
                    columns: ':visible:Not(.not-exported)',
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
            }
        ],
        drawCallback: function() {
            var api = this.api();
            datatable_sum(api, false);
        }
    });

    function datatable_sum(dt_selector, is_calling_first) {
        if (dt_selector.rows('.selected').any() && is_calling_first) {
            var rows = dt_selector.rows('.selected').indexes();
            $(dt_selector.column(6).footer()).html(dt_selector.cells(rows, 6, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
        } else {
            $(dt_selector.column(6).footer()).html(dt_selector.column(6, { page: 'current' }).data().sum().toFixed({{ $general_setting->decimal }}));
        }
    }

    $(".daterangepicker-field").daterangepicker({
        callback: function(startDate, endDate, period) {
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
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-due-report.css') }}">
@endpush
