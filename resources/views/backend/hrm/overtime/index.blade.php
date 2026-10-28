@extends('backend.layout.main')
@section('content')
<x-error-message key="name" />
<x-success-message key="message" />
<x-error-message key="not_permitted" />

<x-hrm-command-bar active="overtime">
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createModal"><i class="dripicons-plus"></i> {{__('db.Add Overtime')}}</button>
</x-hrm-command-bar>

<section class="section-workspace-view">
    <div class="table-responsive">
        <table id="overtime-table" class="table">
            <thead>
                <tr>
                    <th class="not-exported"></th>
                    <th>{{__('db.Employee')}}</th>
                    <th>{{__('db.date')}}</th>
                    <th>{{__('db.Hours')}}</th>
                    <th>{{__('db.Rate')}}</th>
                    <th>{{__('db.Amount')}}</th>
                    <th>{{__('db.status')}}</th>
                    <th class="not-exported">{{__('db.action')}}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($overtimes as $key=>$overtime)
                <tr data-id="{{$overtime->id}}">
                    <td>{{$key}}</td>
                    <td>{{ $overtime->employee->name }}</td>
                    <td>{{ $overtime->date }}</td>
                    <td>{{ $overtime->hours }}</td>
                    <td>{{ $overtime->rate }}</td>
                    <td>{{ $overtime->amount }}</td>
                    <td>{{ ucfirst($overtime->status) }}</td>
                    <td>
                        <div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                                {{__('db.action')}} <span class="caret"></span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default">
                                <li>
                                    <button type="button"
                                        data-id="{{$overtime->id}}"
                                        data-employee="{{$overtime->employee_id}}"
                                        data-date="{{$overtime->date}}"
                                        data-hours="{{$overtime->hours}}"
                                        data-rate="{{$overtime->rate}}"
                                        data-status="{{$overtime->status}}"
                                        class="edit-btn btn btn-link"
                                        data-toggle="modal" data-target="#editModal" >
                                        <i class="dripicons-document-edit"></i>  {{__('db.edit')}}
                                    </button>
                                </li>
                                <li class="divider"></li>
                                {{ Form::open(['route' => ['overtime.destroy', $overtime->id], 'method' => 'DELETE'] ) }}
                                <li>
                                  <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="dripicons-trash"></i> {{__('db.delete')}}</button>
                                </li>
                                {{ Form::close() }}
                            </ul>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<!-- Create Modal -->
<div id="createModal" class="modal fade">
    <div class="modal-dialog">
        <div class="modal-content">
            {!! Form::open(['route'=>'overtime.store','method'=>'post']) !!}
            <div class="modal-header">
                <h5>{{__('db.Add Overtime')}}</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 col-12 mb-3">
                        <label>{{__('db.Employee')}}</label>
                        {{ Form::select('employee_id', $employees->pluck('name','id'), null, ['class'=>'form-control','required'=>true,'placeholder'=>'Select Employee']) }}
                    </div>
                    <div class="col-md-6 col-12 mb-3">
                        <label>{{__('db.date')}}</label>
                        <input type="text" name="date" class="form-control date" value="{{date($general_setting->date_format)}}" required>
                        {{-- {{ Form::date('date', null, ['class'=>'form-control','required'=>true]) }} --}}
                    </div>
                    <div class="col-md-6 col-12 mb-3">
                        <label>{{__('db.Hours')}}</label>
                        {{ Form::number('hours', null, ['class'=>'form-control','step'=>'0.01','required'=>true]) }}
                    </div>
                    <div class="col-md-6 col-12 mb-3">
                        <label>{{__('db.Rate')}}</label>
                        {{ Form::number('rate', null, ['class'=>'form-control','step'=>'0.01','required'=>true]) }}
                    </div>
                    <div class="col-12 text-end">
                        <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary">
                    </div>
                </div>
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>


<!-- Edit Modal -->
<div id="editModal" class="modal fade">
  <div class="modal-dialog">
    <div class="modal-content">
      {{ Form::open(['route' => ['overtime.update', 1], 'method' => 'PUT', 'id'=>'editForm'] ) }}
      <div class="modal-header">
        <h5>{{__('db.Update Overtime')}}</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6 col-12 mb-3">
            <label>{{__('db.Employee')}}</label>
            {{ Form::select('employee_id', $employees->pluck('name','id'), null, ['class'=>'form-control','id'=>'edit_employee','required'=>true]) }}
          </div>
          <div class="col-md-6 col-12 mb-3">
            <label>{{__('db.date')}}</label>
            <input type="text" name="date" class="form-control date" value="{{date($general_setting->date_format)}}" required>
            {{-- {{ Form::date('date', null, ['class'=>'form-control','id'=>'edit_date','required'=>true]) }} --}}
          </div>
          <div class="col-md-6 col-12 mb-3">
            <label>{{__('db.Hours')}}</label>
            {{ Form::number('hours', null, ['class'=>'form-control','step'=>'0.01','id'=>'edit_hours','required'=>true]) }}
          </div>
          <div class="col-md-6 col-12 mb-3">
            <label>{{__('db.Rate')}}</label>
            {{ Form::number('rate', null, ['class'=>'form-control','step'=>'0.01','id'=>'edit_rate','required'=>true]) }}
          </div>
          <div class="col-md-6 col-12 mb-3">
            <label>{{__('db.status')}}</label>
            {{ Form::select('status', ['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'], null, ['class'=>'form-control','id'=>'edit_status','required'=>true]) }}
          </div>
          <input type="hidden" name="overtime_id" id="edit_id">
          <div class="col-12 text-end">
            <input type="submit" value="{{__('db.Update')}}" class="btn btn-primary">
          </div>
        </div>
      </div>
      {{ Form::close() }}
    </div>
  </div>
</div>


@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#hrm").siblings('a').attr('aria-expanded','true');
    $("ul#hrm").addClass("show");
    $("ul#hrm #overtime-menu").addClass("active");

    var overtime_id = [];
    var user_verified = <?php echo json_encode(config('app.user_verified')) ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    function confirmDelete() {
      return confirm("Are you sure want to delete?");
    }

    $(document).ready(function() {
        $('.edit-btn').on('click', function(){
            $('#edit_id').val($(this).data('id'));
            $('#edit_employee').val($(this).data('employee'));
            $('#edit_date').val($(this).data('date'));
            $('#edit_hours').val($(this).data('hours'));
            $('#edit_rate').val($(this).data('rate'));
            $('#edit_status').val($(this).data('status'));
            $('#editForm').attr('action','/overtime/'+$(this).data('id'));
        });
    });

    $('#overtime-table').DataTable({
        "order": [],
        'language': {
            'lengthMenu': '_MENU_ {{__("db.records per page")}}',
            "info": '<small>{{__("db.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
            "search": '{{__("db.Search")}}',
            'paginate': {
                'previous': '<i class="dripicons-chevron-left"></i>',
                'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        'columnDefs': [
            { "orderable": false, 'targets': [0, 7] },
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
        'lengthMenu': [[10,25,50,-1],[10,25,50,"All"]],
        dom: '<"row"lfB>rtip',
        buttons:[
            { extend:'pdf', text:'<i class="fa fa-file-pdf-o"></i>', exportOptions:{columns:':visible:Not(.not-exported)',rows:':visible'}, footer:true },
            { extend:'excel', text:'<i class="dripicons-document-new"></i>', exportOptions:{columns:':visible:Not(.not-exported)',rows:':visible'}, footer:true },
            { extend:'csv', text:'<i class="fa fa-file-text-o"></i>', exportOptions:{columns:':visible:Not(.not-exported)',rows:':visible'}, footer:true },
            { extend:'print', text:'<i class="fa fa-print"></i>', exportOptions:{columns:':visible:Not(.not-exported)',rows:':visible'}, footer:true },
            {
                text:'<i class="dripicons-cross"></i>',
                className:'buttons-delete',
                action: function(e, dt, node, config){
                    if(user_verified=='1'){
                        overtime_id.length=0;
                        $(':checkbox:checked').each(function(i){
                            if(i) overtime_id[i-1]=$(this).closest('tr').data('id');
                        });
                        if(overtime_id.length && confirm("Are you sure want to delete?")){
                            $.ajax({type:'POST', url:'overtime/deletebyselection', data:{overtimeIdArray:overtime_id}, success:function(data){alert(data);}});
                            dt.rows({ page:'current', selected:true }).remove().draw(false);
                        } else if(!overtime_id.length) alert('No overtime selected!');
                    } else alert('This feature is disabled for demo!');
                }
            },
            { extend:'colvis', text:'<i class="fa fa-eye"></i>', columns:':gt(0)'}
        ]
    });


	// var date = $('.date');
    // date.datepicker({
    //  format: "dd-mm-yyyy",
    //  autoclose: true,
    //  todayHighlight: true
    //  });

    //  $('#checkin, #checkout').timepicker({
    // 	'step': 15,
    // });

</script>
@endpush
