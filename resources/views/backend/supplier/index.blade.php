@extends('backend.layout.main') @section('content')


<x-success-message key="message" />
<x-error-message key="not_permitted" />

<!-- Top Command Bar -->
<div class="comm-command-bar">
    <div class="comm-title-group">
        <h1><i class="dripicons-user text-primary"></i> {{ __('db.Supplier') }} Command Center</h1>
        <span class="comm-title-badge"><i class="dripicons-card"></i> Vendor Master Directory &amp; Accounts</span>
    </div>
    <ul class="comm-nav-pills">
        <li><a class="nav-link active" href="{{ route('supplier.index') }}"><i class="dripicons-list"></i> All Suppliers</a></li>
        <li><a class="nav-link" href="{{ route('purchases.index') }}"><i class="dripicons-card"></i> Purchase Bills</a></li>
        <li><a class="nav-link" href="{{ route('purchases.index', ['view' => 'orders', 'purchase_status' => 4]) }}"><i class="dripicons-clipboard"></i> Purchase Orders</a></li>
        <li><a class="nav-link" href="{{ route('purchases.index', ['returns' => 1]) }}"><i class="dripicons-return"></i> Returns</a></li>
    </ul>
    <div class="comm-actions">
        @can('suppliers-add')
            <a href="{{route('supplier.create')}}" class="btn btn-primary py-1 px-3 u-bg-primary u-bc-primary u-fs-11_5px u-r-4px">
                <i class="dripicons-plus"></i> {{__('db.Add Supplier')}}
            </a>
        @endcan
        @can('suppliers-import')
            <a href="#" data-toggle="modal" data-target="#importSupplier" class="btn btn-outline-secondary py-1 px-3 u-fs-11_5px u-r-4px">
                <i class="dripicons-upload"></i> {{__('db.Import Supplier')}}
            </a>
        @endcan
    </div>
</div>

<div class="supplier-workspace-view">
    @php
        $totalSuppliersCount = count($lims_supplier_all);
        $totalDueSum = 0;
        $suppliersWithDueCount = 0;
        foreach($lims_supplier_all as $s) {
            $retAmt = \Illuminate\Support\Facades\DB::table('purchases')
                ->join('return_purchases', 'purchases.id', '=', 'return_purchases.purchase_id')
                ->where('purchases.supplier_id', $s->id)
                ->where('purchases.payment_status', 1)
                ->sum('return_purchases.grand_total');
            $pData = \App\Models\Purchase::where('supplier_id', $s->id)
                ->where('payment_status', 1)
                ->selectRaw('SUM(grand_total) as grand_total, SUM(paid_amount) as paid_amount')
                ->first();
            $due = ($pData ? ($pData->grand_total - $retAmt - $pData->paid_amount) : 0);
            if ($due > 0) {
                $totalDueSum += $due;
                $suppliersWithDueCount++;
            }
        }
    @endphp

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Total Suppliers</div>
                <div class="kpi-number">{{ $totalSuppliersCount }}</div>
            </div>
            <div class="u-bg-primary-soft u-c-primary u-w-38px u-h-38px u-r-6px u-d-flex u-items-center u-justify-center">
                <i class="dripicons-user u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Vendors With Due</div>
                <div class="kpi-number {{ $suppliersWithDueCount > 0 ? 'u-c-danger' : 'u-c-success' }}">{{ $suppliersWithDueCount }}</div>
            </div>
            <div class="u-bg-danger-soft u-c-danger u-w-38px u-h-38px u-r-6px u-d-flex u-items-center u-justify-center">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Total Outstanding Due</div>
                <div class="kpi-number {{ $totalDueSum > 0 ? 'u-c-danger' : 'u-c-success' }}">₹ {{ number_format($totalDueSum, 2) }}</div>
            </div>
            <div class="u-bg-warning-soft u-c-warning u-w-38px u-h-38px u-r-6px u-d-flex u-items-center u-justify-center">
                <i class="fa fa-inr u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Desk Table Card -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table id="supplier-table" class="table">
                <thead>
                    <tr>
                        <th class="not-exported u-w-30px"></th>
                        <th class="u-w-60px u-ta-center">{{__('db.Image')}}</th>
                        <th>{{__('db.Supplier Details')}}</th>
                        <th class="u-w-160px u-ta-right">{{__('db.Total Due')}}</th>
                        <th class="not-exported u-w-100px u-ta-center">{{__('db.action')}}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lims_supplier_all as $key => $supplier)
                    @php
                        $returned_amount = \Illuminate\Support\Facades\DB::table('purchases')
                            ->join('return_purchases', 'purchases.id', '=', 'return_purchases.purchase_id')
                            ->where([
                                ['purchases.supplier_id', $supplier->id],
                                ['purchases.payment_status', 1]
                            ])
                            ->sum('return_purchases.grand_total');
                        $purchaseData = \App\Models\Purchase::where([
                                ['supplier_id', $supplier->id],
                                ['payment_status', 1]
                            ])
                            ->selectRaw('SUM(grand_total) as grand_total, SUM(paid_amount) as paid_amount')
                            ->first();
                        $totalDue = $purchaseData ? ($purchaseData->grand_total - $returned_amount - $purchaseData->paid_amount) : 0;
                        $hasDiskImage = !empty($supplier->image) && file_exists(public_path('documents/supplier/' . $supplier->image));
                        $initials = strtoupper(substr($supplier->name ?? 'S', 0, 1)) . strtoupper(substr($supplier->company_name ?? '', 0, 1));
                        if (strlen($initials) < 2) {
                            $initials = strtoupper(substr($supplier->name ?? 'SP', 0, 2));
                        }
                    @endphp
                    <tr data-id="{{$supplier->id}}">
                        <td>{{$key}}</td>
                        <td class="u-ta-center">
                            @if($hasDiskImage)
                                <img src="{{ route('documents.file', ['supplier', $supplier->image]) }}" class="rounded-circle border u-of-cover" width="42" height="42" alt="{{ $supplier->name }}">
                            @else
                                <div class="supplier-avatar-circle" title="{{ $supplier->name }}">
                                    {{ $initials }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="supplier-name-title">{{ $supplier->name }}</span>
                                @if($supplier->company_name)
                                    <span class="badge badge-light-primary px-2 u-fs-11px u-fw-600">
                                        <i class="fa fa-building-o"></i> {{ $supplier->company_name }}
                                    </span>
                                @endif
                                @if($supplier->vat_number)
                                    <span class="badge badge-light-secondary px-2 u-fs-10px">
                                        GSTIN/VAT: {{ $supplier->vat_number }}
                                    </span>
                                @endif
                            </div>
                            <div class="d-flex align-items-center flex-wrap">
                                @if($supplier->phone_number)
                                    <span class="supplier-meta-tag"><i class="fa fa-phone"></i> {{ $supplier->phone_number }}</span>
                                @endif
                                @if($supplier->email)
                                    <span class="supplier-meta-tag"><i class="fa fa-envelope-o"></i> {{ $supplier->email }}</span>
                                @endif
                                @if($supplier->address || $supplier->city)
                                    <span class="supplier-meta-tag"><i class="fa fa-map-marker"></i> {{ $supplier->address }}{{ $supplier->city ? ', '.$supplier->city : '' }}{{ $supplier->state ? ', '.$supplier->state : '' }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="u-ta-right">
                            @if($totalDue > 0)
                                <span class="due-badge-pill due-has-balance">
                                    <i class="fa fa-exclamation-circle"></i> ₹ {{ number_format($totalDue, 2) }}
                                </span>
                            @else
                                <span class="due-badge-pill due-cleared">
                                    <i class="fa fa-check-circle"></i> ₹ 0.00
                                </span>
                            @endif
                        </td>
                        <td class="u-ta-center">
                            <div class="btn-group">
                                <button type="button" class="btn btn-action-pill dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    {{__('db.action')}} <i class="fa fa-angle-down"></i>
                                </button>
                                <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" role="menu">
                                    @if(in_array("suppliers-index", $all_permission))
                                        <li>
                                            <a href="{{ route('supplier.show', $supplier->id) }}" class="btn btn-link">
                                                <i class="dripicons-preview"></i> {{ __('db.Supplier Details') }}
                                            </a>
                                        </li>
                                    @endif
                                    @if(in_array("suppliers-edit", $all_permission))
                                    <li>
                                        <a href="{{ route('supplier.edit', $supplier->id) }}" class="btn btn-link"><i class="dripicons-document-edit"></i> {{__('db.edit')}}</a>
                                    </li>
                                    @endif
                                    @if(in_array("supplier-due-report", $all_permission))
                                    <li>
                                        {!! Form::open(['route' => 'report.supplierDueByDate', 'method' => 'post', 'id' => 'supplier-due-report-form-'.$supplier->id]) !!}
                                        <input type="hidden" name="start_date" value="{{date('Y-m-d', strtotime('-30 year'))}}" />
                                        <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                                        <input type="hidden" name="supplier_id" value="{{$supplier->id}}" />
                                        <button type="submit" class="btn btn-link"><i class="dripicons-pulse"></i> {{__('db.Supplier Due Report')}}</button>
                                        {!! Form::close() !!}
                                    </li>
                                    @endif
                                    <li>
                                        <button type="button" data-id="{{$supplier->id}}" class="clear-due btn btn-link" data-toggle="modal" data-target="#clearDueModal"><i class="dripicons-brush"></i> {{__('db.Clear Due')}}</button>
                                    </li>
                                    <li class="divider"></li>
                                    @php
                                        $settings = \App\Models\WhatsappSetting::first();
                                        $phone = preg_replace('/\D/', '', $supplier->wa_number ?? '');

                                        if (!$settings || empty($settings->phone_number_id) || empty($settings->permanent_access_token)) {
                                            $href = "https://web.whatsapp.com/send/?phone={$phone}";
                                        } else {
                                            $href = route('whatsapp.send.page', [
                                                'group' => 'Suppliers',
                                                'phone' => $phone
                                            ]);
                                        }
                                    @endphp
                                    @if($phone)
                                    <li>
                                        <a href="{{ $href }}" class="btn btn-link" target="_blank">
                                            <i class="fa fa-whatsapp"></i> {{ __('db.Whatsapp Notification') }}
                                        </a>
                                    </li>
                                    @endif
                                    <li class="divider"></li>
                                    @if(in_array("suppliers-delete", $all_permission))
                                    {{ Form::open(['route' => ['supplier.destroy', $supplier->id], 'method' => 'DELETE'] ) }}
                                    <li>
                                        <button type="submit" class="btn btn-link text-danger" onclick="return confirmDelete()"><i class="dripicons-trash"></i> {{__('db.delete')}}</button>
                                    </li>
                                    {{ Form::close() }}
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="clearDueModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
      <div class="modal-content">
        {!! Form::open(['route' => 'supplier.clearDue', 'method' => 'post']) !!}
        <div class="modal-header">
          <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Clear Due')}}</h5>
          <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
        </div>
        <div class="modal-body">
          <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
            <div class="form-group">
                <input type="hidden" name="supplier_id">
                <label>{{__('db.Amount')}} *</label>
                <input type="number" name="amount" step="any" class="form-control" required>
            </div>
            <div class="form-group">
                <label>{{__('db.Note')}}</label>
                <textarea name="note" rows="3" class="form-control"></textarea>
            </div>
            <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary btn-block u-bg-primary u-bc-primary" id="submit-button">
        </div>
        {!! Form::close() !!}
      </div>
    </div>
</div>

<div id="importSupplier" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
	<div role="document" class="modal-dialog">
	  <div class="modal-content">
	  	{!! Form::open(['route' => 'supplier.import', 'method' => 'post', 'files' => true]) !!}
	    <div class="modal-header">
	      <h5 id="exampleModalLabel" class="modal-title font-weight-bold">{{__('db.Import Supplier')}}</h5>
	      <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
	    </div>
	    <div class="modal-body">
	      <p class="italic text-muted"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
	       <p class="u-fs-12px u-c-text-muted">{{__('db.The correct column order is')}} (name*, image, company_name*, vat_number, email*, phone_number*, address*, city*, state, postal_code, country) {{__('db.and you must follow this')}}.</p>
           <p class="u-fs-11px u-c-text-muted">{{__('db.To display Image it must be stored in')}} images/supplier {{__('db.directory')}}</p>
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
                        <a href="sample_file/sample_supplier.csv" class="btn btn-info btn-block btn-md"><i class="dripicons-download"></i> {{__('db.Download')}}</a>
                    </div>
                </div>
            </div>
	        <input type="submit" value="{{__('db.submit')}}" class="btn btn-primary btn-block u-bg-primary u-bc-primary" id="submit-button">
		</div>
		{!! Form::close() !!}
	  </div>
	</div>
</div>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#people").siblings('a').attr('aria-expanded','true');
    $("ul#people").addClass("show");
    $("ul#people #supplier-list-menu").addClass("active");

    var all_permission = <?php echo json_encode($all_permission) ?>;
    var supplier_id = [];
    var user_verified = <?php echo json_encode(config('app.user_verified')) ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $(".clear-due").on("click", function() {
        var id = $(this).data('id').toString();
        $("#clearDueModal input[name='supplier_id']").val(id);
    });

	function confirmDelete() {
	    if (confirm("Are you sure want to delete?")) {
	        return true;
	    }
	    return false;
	}

    $('#supplier-table').DataTable( {
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
                'targets': [0, 1, 4]
            },
            {
                'checkboxes': {
                   'selectRow': true
                },
                'targets': 0
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
                    rows: ':visible',
                    stripHtml: true
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
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            },
        ],
    } );

    if(all_permission.indexOf("suppliers-delete") == -1)
        $('.buttons-delete').addClass('d-none');

</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/supplier-index.css') }}">
@endpush
