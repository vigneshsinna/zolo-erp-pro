@extends('backend.layout.main') @section('content')


<x-success-message key="message" />
<x-error-message key="not_permitted" />

@php
    $totalExpensesCount = \App\Models\Expense::count();
    $totalExpensesSum = \App\Models\Expense::sum('amount');
    $categoriesCount = \App\Models\ExpenseCategory::where('is_active', true)->count();
    $activeWarehousesCount = count($lims_warehouse_list);
@endphp

<section class="expense-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-wallet text-primary"></i> {{ __('db.Expense List') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Operating &amp; General Expenses</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ route('expenses.index') }}"><i class="dripicons-wallet"></i> Expenses</a></li>
            <li><a class="nav-link" href="{{ route('expense_categories.index') }}"><i class="dripicons-list"></i> Expense Category</a></li>
            <li><a class="nav-link" href="{{ route('incomes.index') }}"><i class="dripicons-rocket"></i> Incomes</a></li>
            <li><a class="nav-link" href="{{ route('accounts.index') }}"><i class="dripicons-briefcase"></i> Accounts</a></li>
            <li><a class="nav-link" href="{{ route('accounting.voucher.entry') }}"><i class="dripicons-pencil"></i> Voucher Entry</a></li>
        </ul>
        <div class="comm-actions">
            @if (in_array('expenses-add', $all_permission))
                <button class="btn btn-primary btn-action-pill u-bg-primary u-bc-primary u-c-xffffff" data-toggle="modal" data-target="#expense-modal">
                    <i class="dripicons-plus"></i> {{ __('db.Add Expense') }}
                </button>
            @endif
        </div>
    </div>

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL EXPENSE RECORDS</div>
                <div class="kpi-number">{{ number_format($totalExpensesCount) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL EXPENSE VALUE</div>
                <div class="kpi-number u-c-danger">₹ {{ number_format($totalExpensesSum, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-arrow-down u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">ACTIVE CATEGORIES</div>
                <div class="kpi-number">{{ $categoriesCount }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-tags u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">DATE WINDOW</div>
                <div class="kpi-number u-fs-13px u-fw-600 u-c-text-2">
                    {{ date('d M Y', strtotime($starting_date)) }} — {{ date('d M Y', strtotime($ending_date)) }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-calendar u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter & Date Range Bar -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'expenses.index', 'method' => 'get', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) !!}
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-calendar"></i> {{ __('db.Choose Your Date') }}</label>
                <div class="input-group">
                    <input type="text" class="daterangepicker-field form-control form-control-sm u-h-30px u-fs-12px u-w-210px"
                        value="{{ $starting_date }} To {{ $ending_date }}" required />
                    <input type="hidden" name="starting_date" value="{{ $starting_date }}" />
                    <input type="hidden" name="ending_date" value="{{ $ending_date }}" />
                </div>
            </div>
            <div class="form-group mr-2 mb-1 @if (\Auth::user()->role_id > 2) {{ 'd-none' }} @endif">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-store"></i> {{ __('db.Choose Warehouse') }}</label>
                <select id="warehouse_id" name="warehouse_id" class="form-control form-control-sm u-h-30px u-fs-12px u-minw-160px">
                    <option value="0">{{ __('db.All Warehouse') }}</option>
                    @foreach ($lims_warehouse_list as $warehouse)
                        @if ($warehouse->id == $warehouse_id)
                            <option selected value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @else
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endif
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

    <!-- Main Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="expense-table" class="table expense-list u-w-100pct">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.date') }}</th>
                        <th>{{ __('db.reference') }} No</th>
                        <th>{{ __('db.Warehouse') }}</th>
                        <th>{{ __('db.category') }}</th>
                        <th>{{ __('db.Amount') }}</th>
                        <th>{{ __('db.Note') }}</th>
                        <th class="not-exported">{{ __('db.action') }}</th>
                    </tr>
                </thead>
                <tfoot class="tfoot active">
                    <th></th>
                    <th>{{ __('db.Total') }}</th>
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

<div id="editModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content u-r-8px u-b-1px-solid-border-strong u-ov-hidden">
            <div class="modal-header u-bg-ink u-c-xffffff">
                <h5 id="exampleModalLabel" class="modal-title font-weight-bold text-white">{{ __('db.Update Expense') }}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close text-white"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p class="italic text-muted"><small>{{ __('db.The field labels marked with * are required input fields') }}.</small></p>
                {!! Form::open(['route' => ['expenses.update', 1], 'method' => 'put', 'files' => true]) !!}
                <?php
                $lims_expense_category_list = DB::table('expense_categories')->where('is_active', true)->get();
                if (Auth::user()->role_id > 2) {
                    $lims_warehouse_list = DB::table('warehouses')
                        ->where([['is_active', true], ['id', Auth::user()->warehouse_id]])
                        ->get();
                } else {
                    $lims_warehouse_list = DB::table('warehouses')->where('is_active', true)->get();
                }
                ?>
                <div class="form-group">
                    <input type="hidden" name="expense_id">
                    <label class="font-weight-bold">{{ __('db.reference') }}</label>
                    <p id="reference" class="font-weight-bold font-monospace text-primary">{{ 'er-' . date('Ymd') . '-' . date('his') }}</p>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">{{ __('db.date') }}</label>
                        <input type="text" name="created_at" class="form-control date" placeholder="{{ __('Choose date') }}" />
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">{{ __('db.Expense Category') }} *</label>
                        <select name="expense_category_id" class="form-control" required>
                            <option value="0">{{ __('db.Employee Expense') }}</option>
                            @foreach ($lims_expense_category_list as $expense_category)
                                <option value="{{ $expense_category->id }}">{{ $expense_category->name . ' (' . $expense_category->code . ')' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">{{ __('db.Warehouse') }} *</label>
                        <select name="warehouse_id" class="form-control" required>
                            @foreach ($lims_warehouse_list as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">{{ __('db.Amount') }} *</label>
                        <input type="number" name="amount" step="any" required class="form-control">
                    </div>
                    <!-- Employee + Type (Hidden by default) -->
                    <div id="edit_employee_fields" style="display:none" class="col-md-12 u-w-100pct">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Employee *</label>
                                <select name="employee_id" id="edit_employee_id" class="form-control">
                                    @foreach (\App\Models\Employee::where('is_active', 1)->get() as $emp)
                                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Type *</label>
                                <select name="type" id="edit_type" class="form-control">
                                    <option value="expense">Expense</option>
                                    <option value="advance">Advance</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold"> {{ __('db.Account') }}</label>
                        <select class="form-control" name="account_id">
                            @foreach ($lims_account_list as $account)
                                @if ($account->is_default)
                                    <option selected value="{{ $account->id }}">{{ $account->name }} [{{ $account->account_no }}]</option>
                                @else
                                    <option value="{{ $account->id }}">{{ $account->name }} [{{ $account->account_no }}]</option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">{{ __('db.Attach Document') }}</label>
                        <input type="file" name="document" class="form-control" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">{{ __('db.Note') }}</label>
                    <textarea name="note" rows="3" class="form-control"></textarea>
                </div>
                <div class="form-group text-right">
                    <button type="submit" class="btn btn-primary btn-action-pill u-bg-ink u-bc-ink">{{ __('db.submit') }}</button>
                </div>
                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#expense").siblings('a').attr('aria-expanded', 'true');
    $("ul#expense").addClass("show");
    $("ul#expense #exp-list-menu").addClass("active");
    @if(request()->boolean('new'))
    $(function () { $('#expense-modal').modal('show'); });
    @endif

    var expense_id = [];
    var user_verified = <?php echo json_encode(config('app.user_verified')); ?>;
    var all_permission = <?php echo json_encode($all_permission); ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $(".daterangepicker-field").daterangepicker({
        callback: function(startDate, endDate, period) {
            var starting_date = startDate.format('YYYY-MM-DD');
            var ending_date = endDate.format('YYYY-MM-DD');
            var title = starting_date + ' To ' + ending_date;
            $(this).val(title);
            $('input[name="starting_date"]').val(starting_date);
            $('input[name="ending_date"]').val(ending_date);
        }
    });

    $(document).on('click', 'button.open-Editexpense_categoryDialog', function() {
        var url = "expenses/";
        var id = $(this).data('id').toString();
        url = url.concat(id).concat("/edit");

        $.get(url, function(data) {
            $('#editModal #reference').text(data['reference_no']);
            $("#editModal input[name='created_at']").val(data['date']);
            $("#editModal select[name='warehouse_id']").val(data['warehouse_id']);
            $("#editModal select[name='expense_category_id']").val(data['expense_category_id']);
            $("#editModal select[name='account_id']").val(data['account_id']);
            $("#editModal input[name='amount']").val(data['amount']);
            $("#editModal input[name='expense_id']").val(data['id']);
            $("#editModal textarea[name='note']").val(data['note']);

            if (data['expense_category_id'] == 0) {
                $("#edit_employee_fields").show();
                $("#edit_employee_id").val(data['employee_id']);
                $("#edit_type").val(data['type']);
            } else {
                $("#edit_employee_fields").hide();
            }
        });
    });

    $(document).on("change", "#editModal select[name='expense_category_id']", function() {
        if ($(this).val() == 0) {
            $("#edit_employee_fields").show();
        } else {
            $("#edit_employee_fields").hide();
        }
    });

    function confirmDelete() {
        if (confirm("Are you sure want to delete?")) {
            return true;
        }
        return false;
    }

    var starting_date = $("input[name=starting_date]").val();
    var ending_date = $("input[name=ending_date]").val();
    var warehouse_id = $("#warehouse_id").val();
    $('#expense-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            url: "expenses/expense-data",
            data: {
                all_permission: all_permission,
                starting_date: starting_date,
                ending_date: ending_date,
                warehouse_id: warehouse_id
            },
            dataType: "json",
            type: "post"
        },
        "createdRow": function(row, data, dataIndex) {
            $(row).attr('data-expense_id', data['id']);
        },
        "columns": [
            { "data": "key" },
            { "data": "date" },
            { "data": "reference_no" },
            { "data": "warehouse" },
            { "data": "expenseCategory" },
            { "data": "amount" },
            { "data": "note" },
            { "data": "options" }
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
        order: [
            ['1', 'desc']
        ],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0, 3, 4, 6, 7]
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
        'select': {
            style: 'multi',
            selector: 'td:first-child'
        },
        'lengthMenu': [
            [10, 25, 50, -1],
            [10, 25, 50, "All"]
        ],
        dom: '<"row align-items-center mb-2"<"col-md-4"l><"col-md-4 text-center"B><"col-md-4"f>>rt<"row align-items-center mt-2"<"col-md-6"i><"col-md-6"p>>',
        rowId: 'ObjectID',
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
                text: '<i title="export to excel" class="fa fa-file-excel-o text-success"></i>',
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
                text: '<i title="delete" class="dripicons-cross text-danger"></i>',
                className: 'buttons-delete',
                action: function(e, dt, node, config) {
                    if (user_verified == '1') {
                        expense_id.length = 0;
                        $(':checkbox:checked').each(function(i) {
                            if (i) {
                                expense_id[i - 1] = $(this).closest('tr').data('expense_id');
                            }
                        });
                        if (expense_id.length && confirm("Are you sure want to delete?")) {
                            $.ajax({
                                type: 'POST',
                                url: 'expenses/deletebyselection',
                                data: {
                                    expenseIdArray: expense_id
                                },
                                success: function(data) {
                                    alert(data);
                                    dt.rows({
                                        page: 'current',
                                        selected: true
                                    }).remove().draw(false);
                                }
                            });
                        } else if (!expense_id.length)
                            alert('Nothing is selected!');
                    } else
                        alert('This feature is disable for demo!');
                }
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
            $(dt_selector.column(5).footer()).html(dt_selector.cells(rows, 5, {
                page: 'current'
            }).data().sum().toFixed({{ $general_setting->decimal }}));
        } else {
            $(dt_selector.column(5).footer()).html(dt_selector.cells(rows, 5, {
                page: 'current'
            }).data().sum().toFixed({{ $general_setting->decimal }}));
        }
    }

    if (all_permission.indexOf("expenses-delete") == -1)
        $('.buttons-delete').addClass('d-none');
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/expense-index.css') }}">
@endpush
