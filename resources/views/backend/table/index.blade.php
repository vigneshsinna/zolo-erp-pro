@extends('backend.layout.main') @section('content')

<x-validation-error fieldName="name" />
<x-success-message key="message" />
<x-error-message key="not_permitted" />

<x-settings-command-bar>
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createModal"><i class="dripicons-plus"></i> {{__('db.Add Table')}}</button>
</x-settings-command-bar>

<section class="settings-workspace-view">
    <div class="table-responsive">
        <table id="table-table" class="table">
            <thead>
                <tr>
                    <th class="not-exported"></th>
                    <th>{{__('db.name')}}</th>
                    <th>{{__('db.Number of Person')}}</th>
                    <th>{{__('db.Description')}}</th>
                    <th class="not-exported">{{__('db.action')}}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lims_table_all as $key=>$table)
                <tr data-id="{{$table->id}}">
                    <td>{{$key}}</td>
                    <td>{{ $table->name }}</td>
                    <td>{{ $table->number_of_person }}</td>
                    <td>{{ $table->description }}</td>
                    <td>
                        <div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{__('db.action')}}
                              <span class="caret"></span>
                              <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                <li>
                                    <button type="button" data-id="{{$table->id}}" data-name="{{$table->name}}" data-number_of_person="{{$table->number_of_person}}" data-description="{{$table->description}}" data-floor_id="{{$table->floor_id}}" class="edit-btn btn btn-link" data-toggle="modal" data-target="#editModal" ><i class="dripicons-document-edit"></i>  {{__('db.edit')}}</button>
                                </li>
                                <li class="divider"></li>
                                {{ Form::open(['route' => ['tables.destroy', $table->id], 'method' => 'DELETE'] ) }}
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
        {!! Form::open(['route' => 'tables.store', 'method' => 'post']) !!}
        <div class="modal-header">
          <h5 id="exampleModalLabel" class="modal-title">{{__('db.Add Table')}}</h5>
          <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
        </div>
        <div class="modal-body">
          <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
          <form>
          	<div class="row">
          		<div class="col-md-6 form-group">
	                <label>{{__('db.name')}} *</label>
	                {{Form::text('name',null,array('required' => 'required', 'class' => 'form-control', 'placeholder' => __('db.Type table name')))}}
	            </div>
	            <div class="col-md-6 form-group">
	                <label>{{__('db.Number of Person')}} *</label>
	                {{Form::number('number_of_person', null, array('required' => 'required', 'class' => 'form-control'))}}
	            </div>
	            <div class="col-md-12 form-group">
	                <label>{{__('db.Description')}}</label>
	                <textarea class="form-control" name="description" rows="5"></textarea>
	            </div>
                @if(isset($floors))
                <div class="col-md-12 form-group">
	                <label>{{__('db.Floor')}} *</label>
	                <select class="selectpicker form-control" name="floor_id">
                        @foreach($floors as $floor)
                        <option value="{{$floor->id}}">{{$floor->name}}</option>
                        @endforeach
                    </select>
	            </div>
                @endif
          	</div>
            <div class="form-group">
              <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary">
            </div>
          </form>
        </div>
        {{ Form::close() }}
      </div>
    </div>
</div>
<!-- Edit Modal -->
<div id="editModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
  <div role="document" class="modal-dialog">
    <div class="modal-content">
        {{ Form::open(['route' => ['tables.update', 1], 'method' => 'PUT'] ) }}
      <div class="modal-header">
        <h5 id="exampleModalLabel" class="modal-title">{{__('db.Update Table')}}</h5>
        <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
      </div>
      <div class="modal-body">
        <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
        <form>
	      	<div class="row">
	      		<div class="col-md-6 form-group">
	      			<input type="hidden" name="table_id">
	                <label>{{__('db.name')}} *</label>
	                {{Form::text('name',null,array('required' => 'required', 'class' => 'form-control', 'placeholder' => __('db.Type table name')))}}
	            </div>
	            <div class="col-md-6 form-group">
	                <label>{{__('db.Number of Person')}} *</label>
	                {{Form::number('number_of_person', null, array('required' => 'required', 'class' => 'form-control'))}}
	            </div>
	            <div class="col-md-12 form-group">
	                <label>{{__('db.Description')}}</label>
	                <textarea class="form-control" name="description" rows="5"></textarea>
	            </div>
                @if(isset($floors))
                <div class="col-md-12 form-group">
	                <label>{{__('db.Floor')}} *</label>
	                <select class="selectpicker form-control" id="floor_id" name="floor_id">
                        @foreach($floors as $floor)
                        <option value="{{$floor->id}}">{{$floor->name}}</option>
                        @endforeach
                    </select>
	            </div>
                @endif
	      	</div>
	        <div class="form-group">
	          <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary">
	        </div>
	    </form>
      {{ Form::close() }}
    </div>
  </div>
</div>


@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#setting").siblings('a').attr('aria-expanded','true');
    $("ul#setting").addClass("show");
    $("ul#setting #table-menu").addClass("active");

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
        $("#editModal input[name='table_id']").val($(this).data('id'));
        $("#editModal input[name='name']").val($(this).data('name'));
        $("#editModal input[name='number_of_person']").val($(this).data('number_of_person'));
        $("#editModal textarea[name='description']").val($(this).data('description'));
        $("#floor_id").val($(this).data('floor_id'));
        $('.selectpicker').selectpicker('refresh');
    });
});

    $('#table-table').DataTable( {
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
                'targets': [0, 3, 4]
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
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
    } );
</script>
@endpush
