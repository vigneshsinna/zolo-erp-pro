@section('virtual-keyboard', '1')
@extends('backend.layout.main') @section('content')


<x-success-message key="create_message" />
<x-success-message key="edit_message" />
<x-success-message key="import_message" />
<x-error-message key="not_permitted" />

<!-- Top Command Bar -->
<div class="comm-command-bar">
    <div class="comm-title-group">
        <h1><i class="dripicons-user-group text-primary"></i> {{ __('db.customer') }} Command Center</h1>
        <span class="comm-title-badge"><i class="dripicons-cart"></i> Client Accounts &amp; Receivables Directory</span>
    </div>
    <ul class="comm-nav-pills">
        <li><a class="nav-link active" href="{{ route('customer.index') }}"><i class="dripicons-list"></i> All Customers</a></li>
        <li><a class="nav-link" href="{{ route('sales.index') }}"><i class="dripicons-cart"></i> Sales Bills</a></li>
        <li><a class="nav-link" href="{{ route('sales.index', ['orders' => 1]) }}"><i class="dripicons-clipboard"></i> Sales Orders</a></li>
        <li><a class="nav-link" href="{{ route('delivery-challans.index') }}"><i class="dripicons-box"></i> Customer DC</a></li>
        <li><a class="nav-link" href="{{ route('sales.index', ['returns' => 1]) }}"><i class="dripicons-return"></i> Returns</a></li>
    </ul>
    <div class="comm-actions">
        @can('customers-add')
            <a href="{{route('customer.create')}}" class="btn btn-primary py-1 px-3 u-bg-primary u-bc-primary u-fs-11_5px u-r-4px">
                <i class="dripicons-plus"></i> {{__('db.Add Customer')}}
            </a>
        @endcan
        @can('customers-import')
            <a href="#" data-toggle="modal" data-target="#importCustomer" class="btn btn-outline-secondary py-1 px-3 u-fs-11_5px u-r-4px">
                <i class="dripicons-upload"></i> {{__('db.Import Customer')}}
            </a>
        @endcan
    </div>
</div>

<div class="customer-workspace-view">
    @php
        $totalCustCount = \App\Models\Customer::where('is_active', true)->count();
        $totalDepositSum = \App\Models\Customer::where('is_active', true)->sum('deposit');
        $totalPointsSum = \App\Models\Customer::where('is_active', true)->sum('points');
    @endphp

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Total Active Customers</div>
                <div class="kpi-number">{{ $totalCustCount }}</div>
            </div>
            <div class="u-bg-primary-soft u-c-primary u-w-38px u-h-38px u-r-6px u-d-flex u-items-center u-justify-center">
                <i class="dripicons-user-group u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Customer Deposits Balance</div>
                <div class="kpi-number u-c-info">₹ {{ number_format($totalDepositSum, 2) }}</div>
            </div>
            <div class="u-bg-info-soft u-c-info u-w-38px u-h-38px u-r-6px u-d-flex u-items-center u-justify-center">
                <i class="fa fa-money u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Reward Points Balance</div>
                <div class="kpi-number u-c-warning">{{ number_format($totalPointsSum, 0) }} pts</div>
            </div>
            <div class="u-bg-warning-soft u-c-warning u-w-38px u-h-38px u-r-6px u-d-flex u-items-center u-justify-center">
                <i class="dripicons-trophy u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Desk Table Card -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="customer-table" class="table customer-list u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported u-w-30px"></th>
                        <th>{{__('db.Customer Group')}}</th>
                        <th>{{__('db.Customer Details')}}</th>
                        <th>{{__('db.Discount Plan')}}</th>
                        <th>{{__('db.Reward Points')}}</th>
                        <th>{{__('db.Deposited Balance')}}</th>
                        <th>{{__('db.Total Due')}}</th>
                        @foreach($custom_fields as $fieldName)
                        <th>{{$fieldName}}</th>
                        @endforeach
                        <th class="not-exported u-w-100px u-ta-center">{{__('db.action')}}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<div id="importCustomer" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
      <div class="modal-content">
        {!! Form::open(['route' => 'customer.import', 'method' => 'post', 'files' => true]) !!}
        <div class="modal-header">
          <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Import Customer')}}</h5>
          <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
        </div>
        <div class="modal-body">
          <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
           <p class="u-fs-12px u-c-text-muted">{{__('db.The correct column order is')}} (customer_group*, name*, company_name, email, phone_number*, address*, city*, state, postal_code, country, deposit) {{__('db.and you must follow this')}}.</p>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>{{__('db.Upload CSV File')}} *</label>
                        {{Form::file('file', array('class' => 'form-control','required'))}}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label> {{__('db.Sample File')}}</label>
                        <a href="sample_file/sample_customer.csv" class="btn btn-info btn-block btn-md"><i class="dripicons-download"></i>  {{__('db.Download')}}</a>
                    </div>
                </div>
            </div>
            <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary btn-block u-bg-primary u-bc-primary" id="submit-button">
        </div>
        {!! Form::close() !!}
      </div>
    </div>
</div>

<div id="clearDueModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
      <div class="modal-content">
        {!! Form::open(['route' => 'customer.clearDue', 'method' => 'post', 'class' => 'clear-due-form']) !!}
        <div class="modal-header">
          <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Clear Due')}}</h5>
          <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
        </div>
        <div class="modal-body">
            <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
            <div class="row">
                <input type="hidden" name="customer_id">
                <input type="hidden" name="balance">
                <div class="col-md-6">
                    <label>{{__('db.Recieved Amount')}} *</label>
                    <input type="text" name="paying_amount" class="form-control numkey" step="any" required>
                </div>
                <div class="col-md-6">
                    <label>{{__('db.Paying Amount')}} *</label>
                    <input type="text" id="p_amount" name="amount" class="form-control"  step="any" required>
                </div>
                <div class="col-md-6 mt-1">
                    <label>{{__('db.Change')}} : </label>
                    <p class="change ml-2">{{number_format(0, $general_setting->decimal, '.', '')}}</p>
                </div>
                <div class="col-md-6 mt-1">
                    <label>{{__('db.Paid By')}}</label>
                    <select name="paid_by_id" class="form-control">
                        @if(in_array("cash",$options))
                        <option value="1">Cash</option>
                        @endif
                        @if(in_array("gift_card",$options))
                        <option value="2">Gift Card</option>
                        @endif
                        @if(in_array("card",$options))
                        <option value="3">Credit Card</option>
                        @endif
                        @if(in_array("cheque",$options))
                        <option value="4">Cheque</option>
                        @endif
                        @if(in_array("paypal",$options) && (strlen($lims_pos_setting_data->paypal_live_api_username)>0) && (strlen($lims_pos_setting_data->paypal_live_api_password)>0) && (strlen($lims_pos_setting_data->paypal_live_api_secret)>0))
                        <option value="5">Paypal</option>
                        @endif
                        @if(in_array("deposit",$options))
                        <option value="6">Deposit</option>
                        @endif
                        @if($lims_reward_point_setting_data && $lims_reward_point_setting_data->is_active)
                        <option value="7">Points</option>
                        @endif
                    </select>
                </div>
                <div class="form-group col-md-12 mt-2">
                    <label>{{__('db.Payment Note')}}</label>
                    <textarea name="payment_note" rows="2" class="form-control"></textarea>
                </div>
            </div>
            <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary btn-block u-bg-primary u-bc-primary" id="submit-button">
        </div>
        {!! Form::close() !!}
      </div>
    </div>
</div>

<div id="deposit-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Add Deposit')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
            </div>
            <div class="modal-body">
                <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
                {!! Form::open(['route' => 'customer.addDeposit', 'method' => 'post']) !!}
                    <div class="form-group">
                        <input type="hidden" name="customer_id">
                        <label>{{__('db.Amount')}} *</label>
                        <input type="number" name="amount" step="any" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>{{__('db.Note')}}</label>
                        <textarea name="note" rows="3" class="form-control"></textarea>
                    </div>
                    <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary btn-block u-bg-primary u-bc-primary" id="submit-button">
                {!! Form::close() !!}
            </div>
        </div>
    </div>
</div>

<div id="view-deposit" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.View Deposit')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
            </div>
            <div class="modal-body">
                <table class="table table-hover deposit-list">
                    <thead>
                        <tr>
                            <th>{{__('db.date')}}</th>
                            <th>{{__('db.Amount')}}</th>
                            <th>{{__('db.Note')}}</th>
                            <th>{{__('db.Created By')}}</th>
                            <th>{{__('db.action')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="edit-deposit" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Update Deposit')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
            </div>
            <div class="modal-body">
                <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
                {!! Form::open(['route' => 'customer.updateDeposit', 'method' => 'post']) !!}
                    <div class="form-group">
                        <label>{{__('db.Amount')}} *</label>
                        <input type="text" name="amount" step="any" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>{{__('db.Note')}}</label>
                        <textarea name="note" rows="3" class="form-control"></textarea>
                    </div>
                    <input type="hidden" name="deposit_id">
                    <button type="submit" class="btn btn-primary btn-block u-bg-primary u-bc-primary">{{__('db.update')}}</button>
                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

<div id="view-points" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.View Point History')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
            </div>
            <div class="modal-body">
                <table class="table table-hover points-list">
                    <thead>
                        <tr>
                            <th>{{__('db.date')}}</th>
                            <th>{{__('db.Points')}}</th>
                            <th>{{__('db.action')}}</th>
                            <th>{{__('db.Reference No')}}</th>
                            <th>{{__('db.Created By')}}</th>
                            <th>{{__('db.Note')}}</th>
                            <th>{{__('db.action')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="edit-point" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Update Point')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
            </div>
            <div class="modal-body">
                <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
                {!! Form::open(['route' => 'customer.updatePoint', 'method' => 'post']) !!}
                    <div class="form-group">
                        <label>{{__('db.Points')}} *</label>
                        <input type="text" name="points" step="any" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>{{__('db.Note')}}</label>
                        <textarea name="note" rows="3" class="form-control"></textarea>
                    </div>
                    <input type="hidden" name="point_id">
                    <button type="submit" class="btn btn-primary btn-block u-bg-primary u-bc-primary">{{__('db.update')}}</button>
                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#people").siblings('a').attr('aria-expanded','true');
    $("ul#people").addClass("show");
    $("ul#people #customer-list-menu").addClass("active");

    function confirmDelete() {
      if (confirm("Are you sure want to delete?")) {
          return true;
      }
      return false;
    }

    var all_permission = <?php echo json_encode($all_permission) ?>;
    var customer_id = [];
    var user_verified = <?php echo json_encode(config('app.user_verified')) ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

  $(".clear-due").on("click", function() {
        var id = $(this).data('id').toString();
        $("#clearDueModal input[name='customer_id']").val(id);
        $("#clearDueModal input[name='balance']").val($(this).data('balance'));
        $("#clearDueModal input[name='amount']").val($(this).data('balance'));
        $("#clearDueModal input[name='paying_amount']").val($(this).data('balance'));
  });

  $('.numkey').keyboard({
        layout: 'custom',
        customLayout: {
          'normal': ['1 2 3', '4 5 6', '7 8 9','0 . {b}']
        },
        usePreview: false,
        restrictInput: true,
        accepted: function(e, keyboard, el) {
          $('input[name="paying_amount"]').trigger('input');
        }
  });

  $('input[name="paying_amount"]').on("input", function() {
        var p_amount = parseFloat($('input[name="paying_amount"]').val());
        var amount = parseFloat($('input[name="amount"]').val());
        var balance = parseFloat($('input[name="balance"]').val());
        if(p_amount > amount){
          alert('Paying amount cannot be bigger than due amount');
          $('input[name="paying_amount"]').val(amount);
          p_amount = amount;
        }
        $('input[name="amount"]').val(p_amount);
        $('input[name="paying_amount"]').val(p_amount);
        $('.change').text(parseFloat(p_amount - amount).toFixed({{$general_setting->decimal}}));
  });

  $('input[name="amount"]').on("input", function() {
        var p_amount = parseFloat($('input[name="paying_amount"]').val());
        var amount = parseFloat($('input[name="amount"]').val());
        var balance = parseFloat($('input[name="balance"]').val());
        if(amount > balance){
          alert('Paying amount cannot be bigger than due amount');
          $('input[name="amount"]').val(balance);
          amount = balance;
        }
        $('input[name="paying_amount"]').val(amount);
        $('.change').text(parseFloat(p_amount - amount).toFixed({{$general_setting->decimal}}));
  });

  $(document).on("click", ".deposit", function() {
        var id = $(this).data('id').toString();
        $("#deposit-modal input[name='customer_id']").val(id);
  });

  $(document).on("click", ".get-deposit", function(e) {
        e.preventDefault();
        var id = $(this).data('id').toString();
        $.get('customer/getDeposit/' + id, function(data) {
            $(".deposit-list tbody").remove();
            var newBody = $("<tbody>");
            $.each(data[0], function(index){
                var newRow = $("<tr>");
                var cols = '';
                cols += '<td>' + data[1][index] + '</td>';
                cols += '<td>' + data[2][index] + '</td>';
                if(data[3][index])
                    cols += '<td>' + data[3][index] + '</td>';
                else
                    cols += '<td>N/A</td>';
                cols += '<td>' + data[4][index] + '<br>' + data[5][index] + '</td>';
                cols += '<td><div class="btn-group"><button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{__("db.action")}}<span class="caret"></span><span class="sr-only">Toggle Dropdown</span></button><ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" role="menu"><li><button type="button" class="btn btn-link edit-btn" data-id="' + data[0][index] +'" data-toggle="modal" data-target="#edit-deposit"><i class="dripicons-document-edit"></i> {{__("db.edit")}}</button></li><li class="divider"></li>{{ Form::open(["route" => "customer.deleteDeposit", "method" => "post"] ) }}<li><input type="hidden" name="id" value="' + data[0][index] + '" /> <button type="submit" class="btn btn-link text-danger" onclick="return confirmDelete()"><i class="dripicons-trash"></i> {{__("db.delete")}}</button></li>{{ Form::close() }}</ul></div></td>';
                newRow.append(cols);
                newBody.append(newRow);
                $("table.deposit-list").append(newBody);
            });
            $("#view-deposit").modal('show');
        });
  });

  $(document).on("click", ".get-points", function(e) {
        e.preventDefault();
        var id = $(this).data('id').toString();
        $.get('customer/getPoints/' + id, function(data) {
            $(".points-list tbody").remove();
            var newBody = $("<tbody>");
            $.each(data[0], function(index){
                var newRow = $("<tr>");
                var cols = '';
                cols += '<td>' + data[1][index] + '</td>';
                cols += '<td>' + data[2][index] + '</td>';
                cols += '<td>' + data[7][index] + '</td>';
                if(data[3][index])
                    cols += '<td>' + data[3][index] + '</td>';
                else
                    cols += '<td>N/A</td>';
                cols += '<td>' + data[4][index] + '<br>' + data[5][index] + '</td>';
                cols += '<td>' + data[6][index] + '</td>';
                cols += '<td><div class="btn-group"><button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{__("db.action")}}<span class="caret"></span><span class="sr-only">Toggle Dropdown</span></button><ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" role="menu"><li><button type="button" class="btn btn-link edit-btn" data-id="' + data[0][index] +'" data-toggle="modal" data-target="#edit-point"><i class="dripicons-document-edit"></i> {{__("db.edit")}}</button></li><li class="divider"></li>{{ Form::open(["route" => "customer.deletePoints", "method" => "post"] ) }}<li><input type="hidden" name="id" value="' + data[0][index] + '" /> <button type="submit" class="btn btn-link text-danger" onclick="return confirmDelete()"><i class="dripicons-trash"></i> {{__("db.delete")}}</button></li>{{ Form::close() }}</ul></div></td>';
                newRow.append(cols);
                newBody.append(newRow);
                $("table.points-list").append(newBody);
            });
            $("#view-points").modal('show');
        });
  });

  $(document).on("click", "table.deposit-list .edit-btn", function(event) {
        var id = $(this).data('id');
        var rowindex = $(this).closest('tr').index();
        var amount = $('table.deposit-list tbody tr:nth-child(' + (rowindex + 1) + ')').find('td:nth-child(2)').text();
        var note = $('table.deposit-list tbody tr:nth-child(' + (rowindex + 1) + ')').find('td:nth-child(3)').text();
        if(note == 'N/A') note = '';
        $('#edit-deposit input[name="deposit_id"]').val(id);
        $('#edit-deposit input[name="amount"]').val(amount);
        $('#edit-deposit textarea[name="note"]').val(note);
        $('#view-deposit').modal('hide');
    });

  $(document).on("click", "table.points-list .edit-btn", function(event) {
        var id = $(this).data('id');
        var rowindex = $(this).closest('tr').index();
        var amount = $('table.points-list tbody tr:nth-child(' + (rowindex + 1) + ')').find('td:nth-child(2)').text();
        var note = $('table.points-list tbody tr:nth-child(' + (rowindex + 1) + ')').find('td:nth-child(3)').text();
        if(note == 'N/A') note = '';
        $('#edit-point input[name="point_id"]').val(id);
        $('#edit-point input[name="points"]').val(amount);
        $('#edit-point textarea[name="note"]').val(note);
        $('#view-points').modal('hide');
    });

    var columns = [{"data": "key"}, {"data": "customer_group"}, {"data": "customer_details"}, {"data": "discount_plan"}, {"data": "reward_point"}, {"data": "deposited_balance"}, {"data": "total_due"}];
    var field_name = <?php echo json_encode($field_name) ?>;
    for(i = 0; i < field_name.length; i++) {
        columns.push({"data": field_name[i]});
    }
    columns.push({"data": "options"});

    let buttons = [];

    @can('customer_export')
        buttons.push(
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o text-danger"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                }
            },
            {
                extend: 'excel',
                text: '<i title="export to excel" class="fa fa-file-excel-o text-success"></i>',
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
            }
        );
    @endcan

    buttons.push(
        {
            text: '<i title="delete" class="dripicons-cross text-danger"></i>',
            className: 'buttons-delete',
            action: function ( e, dt, node, config ) {
                var customer_id = [];
                $('#customer-table tbody input.dt-checkboxes:checked').each(function() {
                    var id = $(this).closest('tr').data('customer');
                    if (id) customer_id.push(id);
                });
                if (!customer_id.length) {
                    $(':checkbox:checked').each(function() {
                        var id = $(this).closest('tr').data('customer');
                        if (id && customer_id.indexOf(id) === -1) customer_id.push(id);
                    });
                }
                if (customer_id.length && confirm("Are you sure want to delete?")) {
                    $.ajax({
                        type:'POST',
                        url:'customer/deletebyselection',
                        data:{
                            customerIdArray: customer_id
                        },
                        success:function(data){
                            $('#customer-table tbody input.dt-checkboxes:checked').each(function() {
                                dt.row($(this).closest('tr')).remove();
                            });
                            dt.draw(false);
                            $('section .alert').remove();
                            var alertHtml = '<div class="alert alert-success alert-dismissible text-center fade show u-m-15px-0" role="alert">' +
                                '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
                                '<i class="dripicons-checkmark mr-1"></i> ' + (data || 'Customer deleted successfully!') +
                                '</div>';
                            $('.customer-workspace-view').prepend(alertHtml);
                            setTimeout(function() { $('.alert').fadeOut(600, function() { $(this).remove(); }); }, 4000);
                        },
                        error: function(xhr) {
                            alert(xhr.responseText || 'Error deleting selected customers.');
                        }
                    });
                }
                else if(!customer_id.length)
                    alert('No customer is selected!');
            }
        },
        {
            extend: 'colvis',
            text: '<i title="column visibility" class="fa fa-eye"></i>',
            columns: ':gt(0)'
        }
    );

    $('#customer-table').DataTable( {
        "processing": true,
        "serverSide": true,
        "ajax":{
            url:"{{ url('customers/customer-data')}}",
            data:{
                all_permission: all_permission,
            },
            dataType: "json",
            type:"post"
        },
         "createdRow": function( row, data, dataIndex ) {
             $(row).attr('data-customer', data['id']);
        },
        "columns": columns,
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
                'targets': [0, 2, 3, 4, 5, 6, 7 ]
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
        rowId: 'ObjectID',
        buttons: buttons
    } );

  $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

  if(all_permission.indexOf("customers-delete") == -1)
        $('.buttons-delete').addClass('d-none');
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/customer-index.css') }}">
@endpush
