@extends('backend.layout.main')
@section('content')


<x-success-message key="message" />

@php
    $challanCount = count($challan_data);
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-clipboard text-primary"></i> Challan Report Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Dispatch Log &amp; Delivery Realization</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('delivery-challans.index') }}"><i class="dripicons-list"></i> Challan Register</a></li>
            <li><a class="nav-link active" href="{{ route('report.challan') }}"><i class="dripicons-clipboard"></i> Challan Report</a></li>
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
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
                <div class="kpi-label">TOTAL CHALLANS</div>
                <div class="kpi-number">{{ number_format($challanCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-clipboard u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">BASED ON</div>
                <div class="kpi-number u-fs-14px u-fw-700">
                    {{ $based_on == 'created_at' ? 'Created Date' : 'Closing Date' }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-clock u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">WINDOW</div>
                <div class="kpi-number u-fs-13px u-fw-600 u-c-text-2">
                    {{ date('d M Y', strtotime($starting_date)) }} — {{ date('d M Y', strtotime($ending_date)) }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-calendar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">STATUS</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Dispatched</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        <form action="{{ route('report.challan') }}" method="get" class="form-inline d-flex align-items-center flex-wrap gap-2">
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-tag"></i> Based On</label>
                <select name="based_on" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-140px">
                    <option value="created_at" @selected($based_on != 'closing_date')>Created Date</option>
                    <option value="closing_date" @selected($based_on == 'closing_date')>Closing Date</option>
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> Starting Date</label>
                <input type="text" class="date form-control form-control-sm u-h-30px u-fs-12px u-w-130px" name="starting_date" value="{{ $starting_date }}" required />
            </div>
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> Ending Date</label>
                <input type="text" class="date form-control form-control-sm u-h-30px u-fs-12px u-w-130px" name="ending_date" value="{{ $ending_date }}" required />
            </div>
            <div class="form-group mb-1">
                <button class="btn btn-primary btn-sm btn-action-pill u-bg-ink u-bc-ink" type="submit">
                    <i class="fa fa-filter"></i> {{ __('db.submit') }}
                </button>
            </div>
        </form>
    </div>

    <!-- Data Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="challan-table" class="table table-hover u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>Challan No</th>
                        <th>Order No</th>
                        <th>Order Date</th>
                        <th>Code</th>
                        <th>Delivery Date</th>
                        <th>Sales Amount</th>
                        <th>Cash Payment</th>
                        <th>Online Payment</th>
                        <th>Cheque Payment</th>
                        <th>Shipping Income</th>
                        <th>Delivery Charge</th>
                        <th>Net</th>
                        <th>Net Cash</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($challan_data as $challan)
                        <?php
                        $packingSlipList = explode(",", $challan->packing_slip_list);
                        $status_list = explode(",", $challan->status_list);
                        $cash_list = explode(",", $challan->cash_list);
                        $cheque_list = explode(",", $challan->cheque_list);
                        $online_payment_list = explode(",", $challan->online_payment_list);
                        $delivery_charge_list = explode(",", $challan->delivery_charge_list);
                        ?>
                        @foreach ($packingSlipList as $key => $packingSlipId)
                            <?php
                            $packingSlip = \App\Models\PackingSlip::with('sale.products')->find($packingSlipId);
                            if(!$cash_list[$key]) $cash_list[$key] = 0;
                            if(!$online_payment_list[$key]) $online_payment_list[$key] = 0;
                            if(!$cheque_list[$key]) $cheque_list[$key] = 0;
                            if(!$delivery_charge_list[$key]) $delivery_charge_list[$key] = 0;
                            ?>
                            @if($packingSlip && $packingSlip->sale)
                                <tr>
                                    <td>{{ $index }}</td>
                                    <td><span class="font-weight-bold font-monospace text-primary">DC-{{ $challan->reference_no }}</span></td>
                                    <td>{{ $packingSlip->sale->reference_no }}</td>
                                    <td>{{ date(config('date_format'), strtotime($packingSlip->sale->created_at)) }}</td>
                                    <td>
                                        @foreach ($packingSlip->sale->products as $i => $product)
                                            @if($i),@endif
                                            {{ $product->code }}
                                        @endforeach
                                    </td>
                                    <td>
                                        @if ($packingSlip->sale->sale_status == 1)
                                            {{ date(config('date_format'), strtotime($packingSlip->sale->updated_at)) }}
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td class="font-weight-600">{{ number_format($packingSlip->sale->grand_total, 2) }}</td>
                                    <td>{{ number_format((float)$cash_list[$key], 2) }}</td>
                                    <td>{{ number_format((float)$online_payment_list[$key], 2) }}</td>
                                    <td>{{ number_format((float)$cheque_list[$key], 2) }}</td>
                                    <td>{{ number_format($packingSlip->sale->shipping_cost, 2) }}</td>
                                    <td>{{ number_format((float)$delivery_charge_list[$key], 2) }}</td>
                                    <td class="font-weight-bold">{{ number_format($cash_list[$key] + $online_payment_list[$key] + $cheque_list[$key] - $delivery_charge_list[$key], 2) }}</td>
                                    <td class="font-weight-bold text-success">{{ number_format($cash_list[$key] - $delivery_charge_list[$key], 2) }}</td>
                                </tr>
                                <?php $index++; ?>
                            @endif
                        @endforeach
                    @endforeach
                </tbody>
                <tfoot class="tfoot active">
                    <th></th>
                    <th>Total:</th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
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
    $("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #challan-report-menu").addClass("active");

    $('#challan-table').DataTable({
        "order": [],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0]
            },
            {
                'checkboxes': {
                    'selectRow': true
                },
                'targets': 0
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
                    columns: ':visible:not(.not-exported)',
                    rows: ':visible',
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
            $(dt_selector.column(6).footer()).html(dt_selector.cells(rows, 6, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(7).footer()).html(dt_selector.cells(rows, 7, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(8).footer()).html(dt_selector.cells(rows, 8, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(9).footer()).html(dt_selector.cells(rows, 9, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(10).footer()).html(dt_selector.cells(rows, 10, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(11).footer()).html(dt_selector.cells(rows, 11, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(12).footer()).html(dt_selector.cells(rows, 12, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(13).footer()).html(dt_selector.cells(rows, 13, { page: 'current' }).data().sum().toFixed(2));
        } else {
            $(dt_selector.column(6).footer()).html(dt_selector.column(6, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(7).footer()).html(dt_selector.column(7, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(8).footer()).html(dt_selector.column(8, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(9).footer()).html(dt_selector.column(9, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(10).footer()).html(dt_selector.column(10, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(11).footer()).html(dt_selector.column(11, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(12).footer()).html(dt_selector.column(12, { page: 'current' }).data().sum().toFixed(2));
            $(dt_selector.column(13).footer()).html(dt_selector.column(13, { page: 'current' }).data().sum().toFixed(2));
        }
    }
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/report-challan-report.css') }}">
@endpush
