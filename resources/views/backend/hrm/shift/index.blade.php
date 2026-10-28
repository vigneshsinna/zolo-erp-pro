@extends('backend.layout.main')
@section('content')
<x-error-message key="name" />
<x-success-message key="message" />
<x-error-message key="not_permitted" />

<x-hrm-command-bar active="shift">
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createModal"><i class="dripicons-plus"></i> {{__('db.Add Shift')}}</button>
</x-hrm-command-bar>

<section class="section-workspace-view">
    <div class="table-responsive">
        <table id="shift-table" class="table">
            <thead>
                <tr>
                    <th class="not-exported"></th>
                    <th>{{__('db.Shift')}}</th>
                    <th>{{__('db.Start Time')}}</th>
                    <th>{{__('db.End Time')}}</th>
                    <th>{{__('db.Grace In (min)')}}</th>
                    <th>{{__('db.Grace Out (min)')}}</th>
                    <th class="not-exported">{{__('db.action')}}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lims_shift_all as $key=>$shift)
                <tr data-id="{{$shift->id}}">
                    <td>{{$key}}</td>
                    <td>{{ $shift->name }}</td>
                    <td>{{ $shift->start_time }}</td>
                    <td>{{ $shift->end_time }}</td>
                    <td>{{ $shift->grace_in }}</td>
                    <td>{{ $shift->grace_out }}</td>
                    <td>
                        <div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{__('db.action')}}
                              <span class="caret"></span>
                              <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                <li>
                                    <button type="button"
                                        data-id="{{$shift->id}}"
                                        data-name="{{$shift->name}}"
                                        data-start="{{$shift->start_time}}"
                                        data-end="{{$shift->end_time}}"
                                        data-grace-in="{{$shift->grace_in}}"
                                        data-grace-out="{{$shift->grace_out}}"
                                        class="edit-btn btn btn-link"
                                        data-toggle="modal" data-target="#editModal" >
                                        <i class="dripicons-document-edit"></i>  {{__('db.edit')}}
                                    </button>
                                </li>
                                <li class="divider"></li>
                                {{ Form::open(['route' => ['shift.destroy', $shift->id], 'method' => 'DELETE'] ) }}
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
<div id="createModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
      <div class="modal-content">
        {!! Form::open(['route' => 'shift.store', 'method' => 'post']) !!}
        <div class="modal-header">
          <h5 id="exampleModalLabel" class="modal-title">{{__('db.Add Shift')}}</h5>
          <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
        </div>
        <div class="modal-body">
          <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>

          <div class="form-group">
              <label>{{__('db.name')}} * <x-info title="Enter the name of the shift, e.g., Morning, Evening" /></label>
              {{Form::text('name',null,array('required' => 'required', 'class' => 'form-control', 'placeholder' => __('db.Type shift name')))}}
          </div>

          <div class="form-group">
              <label>{{__('db.Start Time')}} * <x-info title="Enter the shift start time, e.g., 09:00 AM" /></label>
              <input type="text" id="start_time" name="start_time" class="form-control" value="@if($lims_hrm_setting_data){{$lims_hrm_setting_data->start_time}}@endif" required>
          </div>

          <div class="form-group">
              <label>{{__('db.End Time')}} * <x-info title="Enter the shift end time, e.g., 05:00 PM" /></label>
              <input type="text" id="end_time" name="end_time" class="form-control" value="@if($lims_hrm_setting_data){{$lims_hrm_setting_data->start_time}}@endif" required>
          </div>

          <div class="form-group">
              <label>{{__('db.Grace In (min)')}} <x-info title="Enter allowed grace time for check-in in minutes" /></label>
              {{Form::number('grace_in', 0, ['class'=>'form-control','min'=>0])}}
          </div>

          <div class="form-group">
              <label>{{__('db.Grace Out (min)')}} <x-info title="Enter allowed grace time for check-out in minutes" /></label>
              {{Form::number('grace_out', 0, ['class'=>'form-control','min'=>0])}}
          </div>

          <div class="form-group">
            <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary">
          </div>

        </div>
        {{ Form::close() }}
      </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
  <div role="document" class="modal-dialog">
    <div class="modal-content">
        {{ Form::open(['route' => ['shift.update', 1], 'method' => 'PUT'] ) }}
      <div class="modal-header">
        <h5 id="exampleModalLabel" class="modal-title">{{__('db.Update Shift')}}</h5>
        <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
      </div>
      <div class="modal-body">
        <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>

        <div class="form-group">
            <label>{{__('db.name')}} * <x-info title="Edit the name of the shift" /></label>
            {{Form::text('name',null, array('required' => 'required', 'class' => 'form-control'))}}
        </div>

        <div class="form-group">
            <label>{{__('db.Start Time')}} * <x-info title="Edit the shift start time" /></label>
            <input type="text" id="start_time" name="start_time" class="form-control" value="@if($lims_hrm_setting_data){{$lims_hrm_setting_data->start_time}}@endif" required>
        </div>

        <div class="form-group">
            <label>{{__('db.End Time')}} * <x-info title="Edit the shift end time" /></label>
            <input type="text" id="end_time" name="end_time" class="form-control" value="@if($lims_hrm_setting_data){{$lims_hrm_setting_data->start_time}}@endif" required>
        </div>

        <div class="form-group">
            <label>{{__('db.Grace In (min)')}} <x-info title="Update allowed grace time for check-in in minutes" /></label>
            {{Form::number('grace_in', 0, ['class'=>'form-control','min'=>0])}}
        </div>

        <div class="form-group">
            <label>{{__('db.Grace Out (min)')}} <x-info title="Update allowed grace time for check-out in minutes" /></label>
            {{Form::number('grace_out', 0, ['class'=>'form-control','min'=>0])}}
        </div>

        <input type="hidden" name="shift_id">
        <div class="form-group">
            <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary">
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
    $("ul#hrm #shift-menu").addClass("active");

    var shift_id = [];
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

    $(document).ready(function() {
        $('.edit-btn').on('click', function(){
            $("#editModal input[name='shift_id']").val($(this).data('id'));
            $("#editModal input[name='name']").val($(this).data('name'));
            $("#editModal input[name='start_time']").val($(this).data('start'));
            $("#editModal input[name='end_time']").val($(this).data('end'));
            $("#editModal input[name='grace_in']").val($(this).data('grace-in'));
            $("#editModal input[name='grace_out']").val($(this).data('grace-out'));
        });
    });

    $('#shift-table').DataTable( {
        "order": [],
        'language': {
            'lengthMenu': '_MENU_ {{__("db.records per page")}}',
             "info":      '<small>{{__("db.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
            "search":  '{{__("db.Search")}}',
            'paginate': {
                    'previous': '<i class="dripicons-chevron-left"></i>',
                    'next': '<i class="dripicons-chevron-right"></i>'
            }
        },
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 6]
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
        buttons: [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
                footer:true
            },
            {
                extend: 'excel',
                text: '<i title="export to excel" class="dripicons-document-new"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
                footer:true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
                footer:true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print"></i>',
                exportOptions: {
                    columns: ':visible:Not(.not-exported)',
                    rows: ':visible'
                },
                footer:true
            },
            {
                text: '<i title="delete" class="dripicons-cross"></i>',
                className: 'buttons-delete',
                action: function ( e, dt, node, config ) {
                    if(user_verified == '1') {
                        shift_id.length = 0;
                        $(':checkbox:checked').each(function(i){
                            if(i){
                                shift_id[i-1] = $(this).closest('tr').data('id');
                            }
                        });
                        if(shift_id.length && confirm("Are you sure want to delete?")) {
                            $.ajax({
                                type:'POST',
                                url:'shift/deletebyselection',
                                data:{
                                    shiftIdArray: shift_id
                                },
                                success:function(data){
                                    alert(data);
                                }
                            });
                            dt.rows({ page: 'current', selected: true }).remove().draw(false);
                        }
                        else if(!shift_id.length)
                            alert('No shift is selected!');
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
    } );

    var date = $('.date');
        date.datepicker({
        format: "dd-mm-yyyy",
        autoclose: true,
        todayHighlight: true
        });

     $('#start_time, #end_time').timepicker({
    	'step': 15,
    });
</script>
@endpush
