@extends('backend.layout.main')
@section('content')
<x-success-message key="message" />
<x-error-message key="not_permitted" />

<x-hrm-command-bar active="payroll">
    <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#filterCollapse" aria-expanded="false" aria-controls="filterCollapse">
        <i class="dripicons-filter"></i> {{ __('db.Filter') }}
    </button>
    <button class="btn btn-primary" data-toggle="modal" data-target="#addMultipleModal">
        <i class="dripicons-plus"></i> {{ __("db.Generate Payroll") }}
    </button>
</x-hrm-command-bar>

<section class="section-workspace-view">
    <div class="container-fluid">
        <div class="collapse mt-3" id="filterCollapse">
            <div class="card card-body">
                <div class="row g-3">
                    <!-- Employee Filter -->
                    <div class="col-md-4">
                        <label>{{ __('db.Employee') }}</label>
                        <select id="filterEmployee" class="form-control selectpicker" data-live-search="true"
                            title="Select Employee">
                            <option value="">{{ __('db.All') }}</option>
                            @foreach ($lims_employee_list as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Month Filter -->
                    <div class="col-md-4">
                        <label>{{ __('db.Month') }}</label>
                        <input type="month" id="filterMonth" class="form-control">
                    </div>

                    <!-- Date Filter -->
                    <div class="col-md-4">
                        <label>{{ __('db.date') }}</label>
                        <input type="date" id="filterDate" class="form-control">
                    </div>
                </div>
            </div>
        </div>

    </div>


    <!-- Add Multiple Payroll Modal -->
    <div id="addMultipleModal" class="modal fade text-left" tabindex="-1" role="dialog"
        aria-labelledby="multiplePayrollLabel" aria-hidden="true">
        <div role="document" class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    {{-- <h5 id="multiplePayrollLabel" class="modal-title">
                        <i class="dripicons-stack"></i> Add Multiple Payroll
                    </h5> --}}
                    <button type="button" data-dismiss="modal" aria-label="Close" class="close">
                        <span aria-hidden="true"><i class="dripicons-cross"></i></span>
                    </button>
                </div>
                <div class="modal-body">

                    {!! Form::open(['route' => 'payroll.generateCards', 'method' => 'POST']) !!}
                    <div class="row g-3">

                        {{-- Warehouse --}}
                        <div class="col-md-6 form-group">
                            <label>{{ __('db.Warehouse') }} *</label>
                            <select id="warehouseSelect" name="warehouse_id" class="form-control select2" required>
                                <option value="0">{{ __('db.All Warehouse') }} </option> <!-- new option -->
                                @foreach ($lims_warehouse_list as $warehouse)
                                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>


                        {{-- Month --}}
                        <div class="col-md-6 form-group">
                            <label>{{ __('db.Month') }} *</label>
                            <input type="month" name="month" id="monthMultiple" class="form-control" required>
                        </div>

                        {{-- Employee Multi Select --}}
                        <div class="col-md-12 form-group">
                            <label>{{ __("db.Employees") }} *</label>
                            <div class="mb-2">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                    id="selectAllEmployees">{{ __('db.Select All') }}</button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                    id="deselectAllEmployees">{{ __('db.Deselect All') }}</button>
                            </div>
                            <select id="employeeMultiple" name="employee_ids[]" class="form-control" multiple required>
                                <!-- Employees will load dynamically -->
                            </select>
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="dripicons-checkmark"></i> {{ __('db.Submit Payrolls') }}
                        </button>
                    </div>
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="payroll-table" class="table">
            <thead>
                <tr>
                    <th class="not-exported"></th>
                    <th>{{ __('db.date') }}</th>
                    <th>{{ __('db.reference') }}</th>
                    <th>{{ __('db.Employee') }}</th>
                    <th>{{ __('db.Account') }}</th>
                    <th>{{ __('db.Amount') }}</th>
                    <th>{{ __('db.Method') }}</th>
                    <th>{{ __('db.Month') }}</th>
                    <th class="not-exported">{{ __('db.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lims_payroll_all as $key => $payroll)
                    @php
                        $employee = \App\Models\Employee::find($payroll->employee_id);
                        $account = \App\Models\Account::find($payroll->account_id);
                        $monthDate = null;
                        if (!empty($payroll->month)) {
                            try {
                                $monthDate = \Carbon\Carbon::createFromFormat('Y-m', $payroll->month);
                            } catch (\Exception $e) {
                                $monthDate = null;
                            }
                        }
                    @endphp
                    <tr  data-id="{{ $payroll->id }}" data-employee_id="{{ $payroll->employee_id }}"
                    data-month="{{ $payroll->month }}" data-date="{{ $payroll->created_at->format('Y-m-d') }}">
                        <td>{{ $key }}</td>
                        <td>{{ date($general_setting->date_format, strtotime($payroll->created_at->toDateString())) }}
                        </td>
                        <td>{{ $payroll->reference_no }}</td>
                        <td>{{ $employee->name }}</td>
                        <td>{{ @$account->name }}</td>
                        <td>{{ number_format((float) $payroll->amount, $general_setting->decimal, '.', '') }}</td>
                        @if ($payroll->paying_method == 0)
                            <td>{{ __("db.Cash") }}</td>
                        @elseif($payroll->paying_method == 1)
                            <td>{{ __("db.Cheque")}}</td>
                        @else
                            <td>{{ __("db.Credit Card")}}</td>
                        @endif
                        <td>{{ $monthDate ? $monthDate->format('F Y') : $payroll->month }}</td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-default btn-sm dropdown-toggle"
                                    data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    {{ __('db.action') }}
                                    <span class="caret"></span>
                                    <span class="sr-only">Toggle Dropdown</span>
                                </button>

                                <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default"
                                    user="menu">

                                    <!-- Edit Payroll -->
                                    <li>
                                        <button type="button" class="btn btn-link edit-btn"
                                            data-id="{{ $payroll->id }}"
                                            data-date="{{ $payroll->created_at->format('Y-m-d') }}"
                                            data-employee="{{ $payroll->employee_id }}"
                                            data-account="{{ $payroll->account_id }}"
                                            data-amount="{{ $payroll->amount }}"
                                            data-paying_method="{{ $payroll->paying_method }}"
                                            data-note="{{ $payroll->note }}" data-month="{{ $payroll->month }}"
                                            @php $amountArray = json_decode($payroll->amount_array, true); @endphp
                                            data-salary="{{ $amountArray['salary'] ?? 0 }}"
                                            data-commission="{{ $amountArray['commission'] ?? 0 }}"
                                            data-prev="{{ $amountArray['previous'] ?? 0 }}" data-toggle="modal"
                                            data-target="#editModal">
                                            <i class="dripicons-document-edit"></i> {{ __('db.Edit') }}
                                        </button>
                                    </li>


                                    @php
                                        $amountArray = json_decode($payroll->amount_array, true);
                                    @endphp

                                    <li>
                                        <button type="button" class="btn btn-link view-btn"
                                            data-id="{{ $payroll->id }}" data-employee_name="{{ $employee->name }}"
                                            data-leaves="{{ $payroll->leaves ?? 0 }}"
                                            data-work_duration="{{ $payroll->work_duration ?? 0 }}"
                                            data-attendance="{{ $payroll->attendance ?? 0 }}"
                                            data-month="{{ $monthDate ? $monthDate->format('F Y') : $payroll->month }}"
                                            data-salary="{{ $amountArray['salary'] ?? 0 }}"
                                            data-commission="{{ $amountArray['commission'] ?? 0 }}"
                                            data-transactions="{{ $amountArray['previous'] ?? 0 }}"
                                            data-amount="{{ $amountArray['total'] ?? $payroll->amount }}"
                                            data-paying_method_text="@if ($payroll->paying_method == 0) Cash @elseif($payroll->paying_method == 1) Cheque @else Credit Card @endif"
                                            data-note="{{ $payroll->note }}" data-toggle="modal"
                                            data-target="#viewModal">
                                            <i class="dripicons-preview"></i> {{ __('db.View') }}
                                        </button>
                                    </li>


                                    <li class="divider"></li>

                                    <!-- Delete -->
                                    {{ Form::open(['route' => ['payroll.destroy', $payroll->id], 'method' => 'DELETE']) }}
                                    <li>
                                        <button type="submit" class="btn btn-link" onclick="return confirmDelete()">
                                            <i class="dripicons-trash"></i> {{ __('db.Delete') }}
                                        </button>
                                    </li>
                                    {{ Form::close() }}

                                </ul>
                            </div>
                        </td>

                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th></th>
                    <th>{{ __("db.Total") }}:</th>
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
</section>


<div id="createModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true"
    class="modal fade text-left">
    <div role="document" class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                {{-- <h5 id="exampleModalLabel" class="modal-title">
                    <i class="dripicons-wallet"></i> {{ __('db.Add Payroll') }}
                </h5> --}}
                <button type="button" data-dismiss="modal" aria-label="Close" class="close">
                    <span aria-hidden="true"><i class="dripicons-cross"></i></span>
                </button>
            </div>

            <div class="modal-body">
                <p class="italic text-muted">
                    <small>{{ __('db.The field labels marked with * are required input fields') }}.</small>
                </p>

                {!! Form::open(['route' => 'payroll.store', 'method' => 'post', 'files' => true]) !!}
                <div class="row g-3">


                    {{-- Employee --}}
                    <div class="col-md-6 form-group">
                        <label>
                            {{ __('db.Employee') }} *
                            <x-info title="Select the employee for whom this payroll is being added" />
                        </label>
                        <select class="form-control selectpicker" name="employee_id" id="employee_id" required
                            data-live-search="true" title="Select Employee...">
                            @foreach ($lims_employee_list as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                            @endforeach
                        </select>
                    </div>


                    {{-- Month --}}
                    <div class="col-md-6 form-group">
                        <label>
                            {{ __('db.Month') }} *
                            <x-info title="Select the month for which payroll is being processed" />
                        </label>
                        <input type="month" name="month" id="monthSelect" class="form-control" required>
                    </div>


                    {{-- Salary Amount --}}
                    <div class="col-md-6 form-group">
                        <label>
                            {{ __('db.Salary Amount') }}
                            <x-info title="Salary Amount = Basic Salary + (Allowances - Deductions)" />
                        </label>
                        <input type="number" step="any" name="salary_amount" id="salaryAmount"
                            class="form-control">
                    </div>


                    {{-- Previous Transactions --}}
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Expense') }} <x-info
                                title="{{ __('db.Loan/Advance/Expense') }}" /></label>
                        <input type="number" step="any" name="previous_transactions" id="previousTransactions"
                            class="form-control">
                    </div>

                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Sale Commission') }} <x-info
                                title="Sale Commission = (Total Sale × Target Commission %) / 100" /></label>
                        <input type="number" step="any" name="commission" id="commissionAmount"
                            class="form-control">
                    </div>


                    {{-- Total Payable --}}
                    <div class="col-md-6 form-group">
                        <label>
                            {{ __('db.Total') }}
                            <x-info title="Total Payable = (Salary + Sale Commission) - Previous Transactions" />
                        </label>
                        <input type="number" step="any" name="amount" id="totalPayable" class="form-control">
                    </div>


                    {{-- Date --}}
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.date') }}</label>
                        <input type="text" name="created_at" class="form-control date"
                            placeholder="{{ __('db.Choose date') }}" value="{{ date('d-m-Y') }}" />
                    </div>


                    {{-- Account --}}
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Account') }} *</label>
                        <select class="form-control selectpicker" name="account_id">
                            @foreach ($lims_account_list as $account)
                                <option value="{{ $account->id }}" {{ $account->is_default ? 'selected' : '' }}>
                                    {{ $account->name }} [{{ $account->account_no }}]
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Payment Method --}}
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Method') }} *</label>
                        <select class="form-control selectpicker" name="paying_method" required>
                            <option value="0">{{ __("db.Cash") }}</option>
                            <option value="1">{{ __("db.Cheque") }}</option>
                            <option value="2">{{ __("db.Credit Card") }}</option>
                        </select>
                    </div>

                    {{-- Note --}}
                    <div class="col-md-12 form-group">
                        <label>{{ __('db.Note') }}</label>
                        <textarea name="note" rows="3" class="form-control" placeholder="Write any note about this payroll..."></textarea>
                    </div>

                </div>

                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="dripicons-checkmark"></i> {{ __('db.Submit') }}
                    </button>
                </div>

                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

<!-- Edit Payroll Modal -->
<div id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true"
    class="modal fade text-left">
    <div role="document" class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-sm">
            <div class="modal-header">
                <h5 id="editModalLabel" class="modal-title">
                    <i class="dripicons-wallet"></i> {{ __('db.Update Payroll') }}
                </h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close">
                    <span aria-hidden="true"><i class="dripicons-cross"></i></span>
                </button>
            </div>

            <div class="modal-body">
                <p class="italic text-muted">
                    <small>{{ __('db.The field labels marked with * are required input fields') }}.</small>
                </p>

                {!! Form::open(['route' => ['payroll.update', 1], 'method' => 'put', 'files' => true]) !!}
                <input type="hidden" name="payroll_id" id="editPayrollId">

                <div class="row g-3">
                    <!-- Employee -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Employee') }} *</label>
                        <select class="form-control selectpicker" name="employee_id" id="editEmployee" required
                            data-live-search="true" title="Select Employee...">
                            @foreach ($lims_employee_list as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Month -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Month') }} *</label>
                        <input type="month" name="month" id="editMonth" class="form-control" required>
                    </div>

                    <!-- Salary Amount -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Salary Amount') }}</label>
                        <input type="number" step="any" name="salary_amount" id="editSalaryAmount"
                            class="form-control salary-input" data-emp="editEmp">
                    </div>

                    <!-- Previous Transactions -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Transactions') }}</label>
                        <input type="number" step="any" name="previous_transactions"
                            id="editPreviousTransactions" class="form-control prev-input" data-emp="editEmp">
                    </div>

                    <!-- Sale Commission -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Sale Commission') }}</label>
                        <input type="number" step="any" name="commission" id="editCommissionAmount"
                            class="form-control comm-input" data-emp="editEmp" data-percent="0">
                    </div>

                    <!-- Total Payable -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Total') }}</label>
                        <input type="number" step="any" name="amount" id="editTotalPayable"
                            class="form-control total-output" data-emp="editEmp" readonly>
                    </div>

                    <!-- Date -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.date') }}</label>
                        <input type="text" name="created_at" id="editDate" class="form-control date"
                            placeholder="{{ __('db.Choose date') }}">
                    </div>

                    <!-- Account -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Account') }} *</label>
                        <select class="form-control selectpicker" name="account_id" id="editAccount">
                            @foreach ($lims_account_list as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}
                                    [{{ $account->account_no }}]</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Payment Method -->
                    <div class="col-md-6 form-group">
                        <label>{{ __('db.Method') }} *</label>
                        <select class="form-control selectpicker" name="paying_method" id="editPayingMethod"
                            required>
                            <option value="0">{{ __("db.Cash") }}</option>
                            <option value="1">{{ __("Cheque") }}</option>
                            <option value="2">{{ __("Credit Card") }}</option>
                        </select>
                    </div>

                    <!-- Note -->
                    <div class="col-md-12 form-group">
                        <label>{{ __('db.Note') }}</label>
                        <textarea name="note" id="editNote" rows="3" class="form-control"></textarea>
                    </div>

                </div>

                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="dripicons-checkmark"></i> {{ __('db.Submit') }}
                    </button>
                </div>

                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

<!-- View Payroll Modal -->
<div id="viewModal" tabindex="-1" role="dialog" aria-labelledby="viewModalLabel" aria-hidden="true"
    class="modal fade text-left">
    <div role="document" class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-sm">
            <div class="modal-header">

                <button type="button" data-dismiss="modal" aria-label="Close" class="close">
                    <span aria-hidden="true"><i class="dripicons-cross"></i></span>
                </button>
            </div>

            <div class="modal-body p-4">
                <!-- Employee Info -->
                <div class="mb-4 text-center">
                    <h4 id="viewEmployee" class="fw-bold">-----</h4>
                    <p class="text-muted mb-0">{{ __("db.Payroll & Attendance Overview") }}</p>
                </div>

                <div class="row text-center">
                    <!-- Leaves -->
                    <div class="col-md-4 mb-3">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h6 class="text-muted">{{ __("db.Leaves") }}</h6>
                                <h3 class="fw-bold text-danger" id="viewLeaves">0 {{ __("db.days") }}</h3>
                            </div>
                        </div>
                    </div>

                    <!-- Work Duration -->
                    <div class="col-md-4 mb-3">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h6 class="text-muted">{{ __("db.Work Duration") }}</h6>
                                <h3 class="fw-bold text-success" id="viewWorkDuration">0.00 {{ __("db.hour") }}</h3>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance -->
                    <div class="col-md-4 mb-3">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h6 class="text-muted">{{ __("db.Attendance") }}</h6>
                                <h3 class="fw-bold text-primary" id="viewAttendance">0 {{ __("db.days") }}</h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payroll Info -->
                <hr>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">{{ __("db.Month") }}:</label>
                        <p id="viewMonth">--</p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">{{ __("db.Salery Amount") }}:</label>
                        <p id="viewSalaryAmount">--</p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">{{ __("db.Sale Commission") }}:</label>
                        <p id="viewCommissionAmount">--</p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">{{ __("db.Expense") }}:</label>
                        <p id="viewPreviousTransactions">--</p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">{{ __("db.Total Payable") }}:</label>
                        <p id="viewTotalPayable">--</p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">{{ __("db.Payment Method") }}:</label>
                        <p id="viewPayingMethod">--</p>
                    </div>
                    <div class="col-md-12">
                        <label class="fw-bold">{{ __("db.Note") }}:</label>
                        <p id="viewNote">--</p>
                    </div>
                </div>


            </div>
        </div>
    </div>
</div>


@endsection

@push('scripts')
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script type="text/javascript">
        $("ul#hrm").siblings('a').attr('aria-expanded', 'true');
        $("ul#hrm").addClass("show");
        $("ul#hrm #payroll-menu").addClass("active");

        var payroll_id = [];
        var user_verified = <?php echo json_encode(config('app.user_verified')); ?>;

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



        $('#payroll-table').DataTable({
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
            'columnDefs': [{
                    "orderable": false,
                    'targets': [0, 1, 6]
                },
                {
                    'render': function(data, type, row, meta) {
                        if (type === 'display') {
                            data =
                                '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>';
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
            dom: '<"row"lfB>rtip',
            buttons: [{
                    extend: 'pdf',
                    text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible',
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
                    text: '<i title="export to excel" class="dripicons-document-new"></i>',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible',
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
                    text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible',
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
                    text: '<i title="print" class="fa fa-print"></i>',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible',
                    },
                    action: function(e, dt, button, config) {
                        datatable_sum(dt, true);
                        $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                        datatable_sum(dt, false);
                    },
                    footer: true
                },
                {
                    text: '<i title="delete" class="dripicons-cross"></i>',
                    className: 'buttons-delete',
                    action: function(e, dt, node, config) {
                        if (user_verified == '1') {
                            payroll_id.length = 0;
                            $(':checkbox:checked').each(function(i) {
                                if (i) {
                                    payroll_id[i - 1] = $(this).closest('tr').data('id');
                                }
                            });
                            if (payroll_id.length && confirm("Are you sure want to delete?")) {
                                $.ajax({
                                    type: 'POST',
                                    url: 'payroll/deletebyselection',
                                    data: {
                                        payrollIdArray: payroll_id
                                    },
                                    success: function(data) {
                                        $(':checkbox:checked').each(function(i) {
                                            if (i) {
                                                dt.row($(this).closest('tr')).remove()
                                                    .draw(false);
                                            }
                                        });
                                        alert(data);
                                    }
                                });
                                // dt.rows({ page: 'current', selected: true }).remove().draw(false);
                            } else if (!payroll_id.length)
                                alert('No payroll is selected!');
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

        // Fetch payroll data via AJAX when both employee and month are selected
        function fetchPayrollData() {
            let employee_id = $('#employee_id').val();
            let month = $('#monthSelect').val();

            if (employee_id && month) {
                $.ajax({
                    url: "{{ route('payroll.monthlyData') }}",
                    type: "GET",
                    data: {
                        employee_id: employee_id,
                        month: month
                    },
                    success: function(data) {
                        // Fill salary, transactions, and commission fields with fetched data
                        $('#salaryAmount').val(data.salary);
                        $('#previousTransactions').val(data.transactions);
                        $('#commissionAmount').val(data.commission);

                        // Calculate total payable
                        calculateTotal();
                    },
                    error: function() {
                        alert('Error loading payroll data!');
                    }
                });
            }
        }

        // Calculate total payable based on salary, commission, and previous transactions
        function calculateTotal() {
            let salary = parseFloat($('#salaryAmount').val()) || 0;
            let transactions = parseFloat($('#previousTransactions').val()) || 0;
            let commission = parseFloat($('#commissionAmount').val()) || 0;

            let total = (salary + commission) - transactions;
            $('#totalPayable').val(total.toFixed(2));
        }

        // Trigger fetch when both employee and month are selected
        $('#employee_id, #monthSelect').on('change', function() {
            fetchPayrollData();
        });

        // Trigger total calculation on keyup in salary, transactions, or commission fields
        $('#salaryAmount, #previousTransactions, #commissionAmount').on('keyup change', function() {
            calculateTotal();
        });

        $(document).ready(function() {

            // Function to load employees via AJAX
            function loadEmployees(warehouse_id = 0) {
                $.ajax({
                    url: "{{ route('payroll.getEmployeesByWarehouse') }}",
                    type: "GET",
                    data: {
                        warehouse_id
                    },
                    success: function(data) {
                        let $select = $('#employeeMultiple');

                        // Destroy previous Select2 instance if exists
                        if ($select.hasClass('select2-hidden-accessible')) {
                            $select.select2('destroy');
                        }

                        // Clear existing options
                        $select.empty();

                        if (data.length > 0) {
                            data.forEach(emp => {
                                $select.append(new Option(emp.name, emp.id, false, false));
                            });
                            $select.prop('disabled', false);
                        } else {
                            $select.append(new Option('No employees available', '', false, false));
                            $select.prop('disabled', true);
                        }

                        // Initialize Select2
                        $select.select2({
                            placeholder: data.length > 0 ? 'Select Employees...' :
                                'No employees available',
                            width: '100%',
                            allowClear: true
                        });
                    },
                    error: function() {
                        alert('Error loading employees!');
                    }
                });
            }

            // On page load, load all employees
            loadEmployees(0);

            // On warehouse change
            $('#warehouseSelect').on('change', function() {
                let warehouse_id = $(this).val() || 0; // 0 = all warehouses
                loadEmployees(warehouse_id);
            });

            // Select All Employees
            $('#selectAllEmployees').click(function() {
                let $select = $('#employeeMultiple');
                if (!$select.prop('disabled')) {
                    $select.find('option').prop('selected', true);
                    $select.trigger('change');
                }
            });

            // Deselect All Employees
            $('#deselectAllEmployees').click(function() {
                let $select = $('#employeeMultiple');
                if (!$select.prop('disabled')) {
                    $select.find('option').prop('selected', false);
                    $select.trigger('change');
                }
            });


            $(document).on('click', '.view-btn', function() {
                let payroll = $(this).data();
                $('#viewEmployee').text(payroll.employee_name);
                $('#viewLeaves').text(payroll.leaves + ' days');
                $('#viewWorkDuration').text(payroll.work_duration + ' hour');
                $('#viewAttendance').text(payroll.attendance + ' Days');
                $('#viewMonth').text(payroll.month);
                $('#viewSalaryAmount').text(payroll.salary);
                $('#viewCommissionAmount').text(payroll.commission);
                $('#viewPreviousTransactions').text(payroll.transactions);
                $('#viewTotalPayable').text(payroll.amount);
                $('#viewPayingMethod').text(payroll.paying_method_text);
                $('#viewNote').text(payroll.note);
            });


            function calculateEditTotal(empId) {
                let salary = parseFloat($(`input.salary-input[data-emp='${empId}']`).val()) || 0;
                let prev = parseFloat($(`input.prev-input[data-emp='${empId}']`).val()) || 0;
                let commission = parseFloat($(`input.comm-input[data-emp='${empId}']`).val()) || 0;
                if (commission > 0) {
                    commission = (salary * commission) / 100;
                }

                let total = salary + commission - prev;
                $(`input.total-output[data-emp='${empId}']`).val(total.toFixed(2));
            }

            // Trigger calculation on input change
            $('#editSalaryAmount, #editPreviousTransactions, #editCommissionAmount').on('input', function() {
                let empId = $(this).data('emp');
                calculateEditTotal(empId);
            });

            // Open modal and populate data
            $('.edit-btn').on('click', function() {
                let empId = 'editEmp'; // single modal identifier

                $('#editPayrollId').val($(this).data('id'));
                $('#editEmployee').val($(this).data('employee')).selectpicker('refresh');
                $('#editMonth').val($(this).data('month'));
                $('#editSalaryAmount').val($(this).data('salary')).data('emp', empId);
                $('#editPreviousTransactions').val($(this).data('prev')).data('emp', empId);
                $('#editCommissionAmount').val($(this).data('commission')).data('emp', empId);
                $('#editTotalPayable').data('emp', empId);
                $('#editDate').val($(this).data('date'));
                $('#editAccount').val($(this).data('account')).selectpicker('refresh');
                $('#editPayingMethod').val($(this).data('paying_method')).selectpicker('refresh');
                $('#editNote').val($(this).data('note'));

                // calculate total immediately
                calculateEditTotal(empId);
            });


            // Filter DataTable based on inputs
            $('#filterEmployee, #filterMonth, #filterDate').on('change keyup', function() {
                let employee = $('#filterEmployee').val();
                let month = $('#filterMonth').val();
                let date = $('#filterDate').val();

                let table = $('#payroll-table').DataTable();

                table.rows().every(function() {
                    let row = this.node();
                    let rowData = $(row).data();

                    let show = true;

                    // Employee filter
                    if (employee && rowData.employee_id != employee) show = false;

                    // Month filter
                    if (month && rowData.month != month) show = false;

                    // Date filter
                    if (date && rowData.date != date) show = false;

                    $(row).toggle(show);
                });
            });


        });
    </script>
@endpush

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/hrm-payroll-index.css') }}">
@endpush
