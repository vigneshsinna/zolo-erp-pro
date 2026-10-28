@extends('backend.layout.main') @section('content')


<x-success-message key="message" />
<x-error-message key="not_permitted" />

@php
    $totalQuotesCount = \App\Models\Quotation::count();
    $pendingQuotesCount = \App\Models\Quotation::where('quotation_status', 1)->count();
    $sentQuotesCount = \App\Models\Quotation::where('quotation_status', 2)->count();
    $totalQuotesSum = \App\Models\Quotation::sum('grand_total');
    $currencyCode = $currency->code ?? '₹';
@endphp

<section class="quotation-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-document text-primary"></i> {{ __('db.Quotation List') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-document"></i> Sales Estimates &amp; Quotes</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ route('quotations.index') }}"><i class="dripicons-list"></i> Quotations</a></li>
            <li><a class="nav-link" href="{{ route('sales.index') }}"><i class="dripicons-cart"></i> Bills</a></li>
            <li><a class="nav-link" href="{{ route('sales.index', ['orders' => 1]) }}"><i class="dripicons-clipboard"></i> Orders</a></li>
            <li><a class="nav-link" href="{{ route('delivery-challans.index') }}"><i class="dripicons-truck"></i> Customer DC</a></li>
            <li><a class="nav-link" href="{{ route('customer.index') }}"><i class="dripicons-user"></i> Customers</a></li>
            <li><a class="nav-link" href="{{ route('sales.index', ['returns' => 1]) }}"><i class="dripicons-return"></i> Returns</a></li>
        </ul>
        <div class="comm-actions">
            @if(in_array("quotes-add", $all_permission))
                <a href="{{ route('quotations.create') }}" class="btn btn-primary btn-action-pill u-bg-primary u-bc-primary u-c-xffffff">
                    <i class="dripicons-plus"></i> {{ __('db.Add Quotation') }}
                </a>
            @endif
        </div>
    </div>

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL QUOTATIONS</div>
                <div class="kpi-number">{{ number_format($totalQuotesCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-document u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">PENDING / DRAFT</div>
                <div class="kpi-number u-c-warning">{{ number_format($pendingQuotesCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-clock u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">SENT QUOTES</div>
                <div class="kpi-number u-c-success">{{ number_format($sentQuotesCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">ESTIMATED VALUE</div>
                <div class="kpi-number">{{ $currencyCode }} {{ number_format($totalQuotesSum, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter & Date Range Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'quotations.index', 'method' => 'get', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) !!}
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> {{__('db.Choose Your Date')}}</label>
                <div class="input-group">
                    <input type="text" class="daterangepicker-field form-control form-control-sm u-h-30px u-fs-12px u-w-210px" value="{{$starting_date}} To {{$ending_date}}" required />
                    <input type="hidden" name="starting_date" value="{{$starting_date}}" />
                    <input type="hidden" name="ending_date" value="{{$ending_date}}" />
                </div>
            </div>
            <div class="form-group mr-2 mb-1 @if(\Auth::user()->role_id > 2){{'d-none'}}@endif">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-store"></i> {{__('db.Choose Warehouse')}}</label>
                <select id="warehouse_id" name="warehouse_id" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-160px">
                    <option value="0">{{__('db.All Warehouse')}}</option>
                    @foreach($lims_warehouse_list as $warehouse)
                        @if($warehouse->id == $warehouse_id)
                            <option selected value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                        @else
                            <option value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <label for="follow-up-from" class="small">Follow-up from</label>
                <input id="follow-up-from" name="follow_up_from" type="date" class="form-control form-control-sm" value="{{ request('follow_up_from') }}">
            </div>
            <div class="form-group mr-2 mb-1">
                <label for="follow-up-to" class="small">Follow-up to</label>
                <input id="follow-up-to" name="follow_up_to" type="date" class="form-control form-control-sm" value="{{ request('follow_up_to') }}">
            </div>
            <div class="form-group mr-2 mb-1">
                <label><input type="checkbox" name="follow_up_due" value="1" @checked(request()->boolean('follow_up_due'))> Follow-up due</label>
            </div>
            <div class="form-group mb-1">
                <button class="btn btn-primary btn-sm btn-action-pill u-bg-ink u-bc-ink" id="filter-btn" type="submit">
                    <i class="fa fa-filter"></i> {{__('db.submit')}}
                </button>
            </div>
        {!! Form::close() !!}
    </div>

    <!-- Main Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="quotation-table" class="table quotation-list u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{__('db.date')}}</th>
                        <th>{{__('db.reference')}}</th>
                        <th>{{__('db.Warehouse')}}</th>
                        <th>{{__('db.Biller')}}</th>
                        <th>{{__('db.customer')}}</th>
                        <th>{{__('db.Supplier')}}</th>
                        <th>{{__('db.Quotation Status')}}</th>
                        <th>{{__('db.grand total')}}</th>
                        <th>Next follow-up</th>
                        <th class="not-exported">{{__('db.action')}}</th>
                    </tr>
                </thead>

                <tfoot class="tfoot active">
                    <th></th>
                    <th>{{__('db.Total')}}</th>
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

<div id="quotation-details" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog modal-lg">
      <div class="modal-content u-r-8px u-b-1px-solid-border-strong u-ov-hidden">
        <div class="container mt-3 pb-2 border-bottom">
            <div class="row align-items-center">
                <div class="col-md-6 d-print-none d-flex align-items-center gap-2">
                    <button id="print-btn" type="button" class="btn btn-outline-secondary btn-sm mr-2"><i class="dripicons-print"></i> {{__('db.Print')}}</button>
                    {{ Form::open(['route' => 'quotation.sendmail', 'method' => 'post', 'class' => 'sendmail-form d-inline'] ) }}
                        <input type="hidden" name="quotation_id">
                        <button class="btn btn-outline-secondary btn-sm"><i class="dripicons-mail"></i> {{__('db.Email')}}</button>
                    {{ Form::close() }}
                </div>
                <div class="col-md-6 text-right d-print-none">
                    <button type="button" id="close-btn" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
                </div>
                <div class="col-md-12 text-center mt-2">
                    <h4 id="exampleModalLabel" class="modal-title font-weight-bold u-c-text">{{$general_setting->site_title}}</h4>
                    <span class="badge badge-secondary u-fs-11px u-tt-uppercase">{{__('db.Quotation Details')}}</span>
                </div>
            </div>
        </div>
        <div id="quotation-content" class="modal-body">
        </div>
        <div class="px-3">
            <table class="table table-bordered product-quotation-list u-fs-12px">
                <thead class="u-bg-ink u-c-xffffff">
                    <th>#</th>
                    <th>{{__('db.product')}}</th>
                    <th>{{__('db.Batch No')}}</th>
                    <th>Qty</th>
                    <th>{{__('db.Unit Price')}}</th>
                    <th>{{__('db.Tax')}}</th>
                    <th>{{__('db.Discount')}}</th>
                    <th>{{__('db.Subtotal')}}</th>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
        <div id="quotation-footer" class="modal-body border-top pt-2 u-fs-12px"></div>
      </div>
    </div>
</div>

@endsection

@push('scripts')
<script type="text/javascript">

    $("ul#sale").siblings('a').attr('aria-expanded','true');
    $("ul#sale").addClass("show");
    $("ul#sale #quotation-list-menu").addClass("active");

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

    var all_permission = <?php echo json_encode($all_permission) ?>;
    var quotation_id = [];
    var user_verified = <?php echo json_encode(config('app.user_verified')) ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    function confirmDelete() {
        if (confirm("Are you sure want to delete?")) {
            return true;
        }
        return false;
    }

    $(document).on("click", "tr.quotation-link td:not(:first-child, :last-child)", function() {
        var quotation = $(this).parent().data('quotation');
        quotationDetails(quotation);
    });

    $(document).on("click", ".view", function() {
        var quotation = $(this).parent().parent().parent().parent().parent().data('quotation');
        quotationDetails(quotation);
    });

    $("#print-btn").on("click", function(){
        var divContents = document.getElementById("quotation-details").innerHTML;
        var a = window.open('');
        a.document.write('<html>');
        a.document.write('<body><link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-quotation-index-2.css') }}"><link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-customer-index-2.css') }}">');
        a.document.write(divContents);
        a.document.write('</body></html>');
        a.document.close();
        setTimeout(function(){a.close();},10);
        a.print();
    });

    var starting_date = $("input[name=starting_date]").val();
    var ending_date = $("input[name=ending_date]").val();
    var warehouse_id = $("#warehouse_id").val();
    $('#quotation-table').DataTable( {
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"quotations/quotation-data",
            data:{
                all_permission: all_permission,
                starting_date: starting_date,
                ending_date: ending_date,
                warehouse_id: warehouse_id,
                follow_up_from: $('#follow-up-from').val(),
                follow_up_to: $('#follow-up-to').val(),
                follow_up_due: $('[name="follow_up_due"]').prop('checked') ? 1 : 0
            },
            dataType: "json",
            type:"post",
        },
        "createdRow": function( row, data, dataIndex ) {
            $(row).addClass('quotation-link');
            $(row).attr('data-quotation', JSON.stringify(data['quotation']));
        },
        "columns": [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "warehouse"},
            {"data": "biller"},
            {"data": "customer"},
            {"data": "supplier"},
            {"data": "status"},
            {"data": "grand_total"},
            {"data": "next_follow_up"},
            {"data": "options"},
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
                'targets': [0, 3, 4, 7, 10]
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
        dom: '<"row align-items-center mb-2"<"col-md-4"l><"col-md-4 text-center"B><"col-md-4"f>>rt<"row align-items-center mt-2"<"col-md-6"i><"col-md-6"p>>',
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
                footer:true
            },
            {
                extend: 'excel',
                text: '<i title="export to excel" class="fa fa-file-excel-o text-success"></i>',
                exportOptions: {
                    columns: ':visible:not(.not-exported)',
                    rows: ':visible'
                },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer:true
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
                footer:true
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
                footer:true
            },
            {
                text: '<i title="delete" class="dripicons-cross text-danger"></i>',
                className: 'buttons-delete',
                action: function ( e, dt, node, config ) {
                    if(user_verified == '1') {
                        quotation_id.length = 0;
                        $(':checkbox:checked').each(function(i){
                            if(i){
                                var quotation = $(this).closest('tr').data('quotation');
                                quotation_id[i-1] = quotation[13];
                            }
                        });
                        if(quotation_id.length && confirm("Are you sure want to delete?")) {
                            $.ajax({
                                type:'POST',
                                url:'quotations/deletebyselection',
                                data:{
                                    quotationIdArray: quotation_id
                                },
                                success:function(data) {
                                    alert(data);
                                    dt.rows({ page: 'current', selected: true }).remove().draw(false);
                                }
                            });
                        }
                        else if(!quotation_id.length)
                            alert('Nothing is selected!');
                    }
                    else
                        alert('This feature is disable for demo!');
                }
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
        drawCallback: function () {
            var api = this.api();
            datatable_sum(api, false);
        }
    } );

    function datatable_sum(dt_selector, is_calling_first) {
        if (dt_selector.rows( '.selected' ).any() && is_calling_first) {
            var rows = dt_selector.rows( '.selected' ).indexes();
            $( dt_selector.column( 8 ).footer() ).html(dt_selector.cells( rows, 8, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
        else {
            $( dt_selector.column( 8 ).footer() ).html(dt_selector.cells( rows, 8, { page: 'current' } ).data().sum().toFixed({{$general_setting->decimal}}));
        }
    }

    if(all_permission.indexOf("quotes-delete") == -1)
        $('.buttons-delete').addClass('d-none');

    function quotationDetails(quotation){
        $('input[name="quotation_id"]').val(quotation[13]);
        var htmltext = '<strong>{{__("db.date")}}: </strong>'+quotation[0]+'<br><strong>{{__("db.reference")}}: </strong>'+quotation[1]+'<br><strong>{{__("db.status")}}: </strong>'+quotation[2]+'<br>';
        if(quotation[25])
            htmltext += '<strong>{{__("db.Attach Document")}}: </strong><a href="{{ url('secure-documents/quotation') }}/'+quotation[25]+'">Download</a><br>';
        htmltext += '<br><div class="row"><div class="col-md-6"><strong>{{__("db.From")}}:</strong><br>'+quotation[3]+'<br>'+quotation[4]+'<br>'+quotation[5]+'<br>'+quotation[6]+'<br>'+quotation[7]+'<br>'+quotation[8]+'</div><div class="col-md-6"><div class="float-right"><strong>{{__("db.To")}}:</strong><br>'+quotation[9]+'<br>'+quotation[10]+'<br>'+quotation[11]+'<br>'+quotation[12]+'</div></div></div>';
        $.get('quotations/product_quotation/' + quotation[13], function(data){
            $(".product-quotation-list tbody").remove();
            var name_code = data[0];
            var qty = data[1];
            var unit_code = data[2];
            var tax = data[3];
            var tax_rate = data[4];
            var discount = data[5];
            var subtotal = data[6];
            var batch_no = data[7];
            var newBody = $("<tbody>");
            $.each(name_code, function(index){
                var newRow = $("<tr>");
                var cols = '';
                cols += '<td><strong>' + (index+1) + '</strong></td>';
                cols += '<td>' + name_code[index] + '</td>';
                cols += '<td>' + batch_no[index] + '</td>';
                cols += '<td>' + qty[index] + ' ' + unit_code[index] + '</td>';
                cols += '<td>' + parseFloat(subtotal[index] / qty[index]).toFixed({{$general_setting->decimal}}) + '</td>';
                cols += '<td>' + tax[index] + '(' + tax_rate[index] + '%)' + '</td>';
                cols += '<td>' + discount[index] + '</td>';
                cols += '<td>' + subtotal[index] + '</td>';
                newRow.append(cols);
                newBody.append(newRow);
            });

            var newRow = $("<tr>");
            cols = '';
            cols += '<td colspan=5><strong>{{__("db.Total")}}:</strong></td>';
            cols += '<td>' + quotation[14] + '</td>';
            cols += '<td>' + quotation[15] + '</td>';
            cols += '<td>' + quotation[16] + '</td>';
            newRow.append(cols);
            newBody.append(newRow);

            var newRow = $("<tr>");
            cols = '';
            cols += '<td colspan=7><strong>{{__("db.Order Tax")}}:</strong></td>';
            cols += '<td>' + quotation[17] + '(' + quotation[18] + '%)' + '</td>';
            newRow.append(cols);
            newBody.append(newRow);

            var newRow = $("<tr>");
            cols = '';
            cols += '<td colspan=7><strong>{{__("db.Order Discount")}}:</strong></td>';
            cols += '<td>' + quotation[19] + '</td>';
            newRow.append(cols);
            newBody.append(newRow);

            var newRow = $("<tr>");
            cols = '';
            cols += '<td colspan=7><strong>{{__("db.Shipping Cost")}}:</strong></td>';
            cols += '<td>' + quotation[20] + '</td>';
            newRow.append(cols);
            newBody.append(newRow);

            var newRow = $("<tr>");
            cols = '';
            cols += '<td colspan=7><strong>{{__("db.grand total")}}:</strong></td>';
            cols += '<td>' + quotation[21] + '</td>';
            newRow.append(cols);
            newBody.append(newRow);

            $("table.product-quotation-list").append(newBody);
        });
        var htmlfooter = '<p><strong>{{__("db.Note")}}:</strong> '+quotation[22]+'</p><strong>{{__("db.Created By")}}:</strong><br>'+quotation[23]+'<br>'+quotation[24];
        $('#quotation-content').html(htmltext);
        $('#quotation-footer').html(htmlfooter);
        $('#quotation-details').modal('show');
    }
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/quotation-index.css') }}">
@endpush
