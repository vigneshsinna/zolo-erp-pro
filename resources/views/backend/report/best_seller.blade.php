@section('charts', '1')
@extends('backend.layout.main') @section('content')


<x-error-message key="not_permitted" />

@php
    $warehouseName = 'All Warehouses';
    if($warehouse_id && $warehouse_id > 0) {
        $wh = \App\Models\Warehouse::find($warehouse_id);
        if($wh) $warehouseName = $wh->name;
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-trophy text-primary"></i> {{ __('db.Best Seller') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Top-Moving Stock Velocity Chart</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/best_seller') }}"><i class="dripicons-trophy"></i> Best Seller</a></li>
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
            <li><a class="nav-link" href="{{ url('report/product_report') }}"><i class="dripicons-box"></i> Product Report</a></li>
            <li><a class="nav-link" href="{{ url('report/warehouse_report') }}"><i class="dripicons-store"></i> Warehouse</a></li>
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
                <div class="kpi-label">TIME WINDOW</div>
                <div class="kpi-number u-fs-14px u-fw-700 u-c-text-2">
                    {{ $start_month.' — '.date("F Y") }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-calendar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">WAREHOUSE SCOPE</div>
                <div class="kpi-number u-fs-14px u-fw-700">{{ $warehouseName }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-store u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">CHART ENGINE</div>
                <div class="kpi-number u-fs-14px u-fw-700">Top 5 Velocity</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-graph-bar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">METRIC STATUS</div>
                <div class="kpi-number u-fs-14px u-fw-600 u-c-success">Live Real-Time</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {{ Form::open(['route' => 'report.bestSellerByWarehouse', 'method' => 'post', 'id' => 'report-form', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) }}
            <input type="hidden" name="warehouse_id_hidden" value="{{ $warehouse_id }}">
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-store"></i> {{ __('db.Choose Warehouse') }}</label>
                <select class="form-control form-control-sm u-h-30px u-fs-12px u-minw-200px" id="warehouse_id" name="warehouse_id">
                    <option value="0">{{ __('db.All Warehouse') }}</option>
                    @foreach($lims_warehouse_list as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-1">
                <button class="btn btn-primary btn-sm btn-action-pill u-bg-ink u-bc-ink" type="submit">
                    <i class="fa fa-filter"></i> {{ __('db.submit') }}
                </button>
            </div>
        {{ Form::close() }}
    </div>

    <!-- Chart Container Wrapper -->
    <div class="desk-card-wrapper">
        <div class="u-maxw-960px u-m-0-auto">
            @php
                $color = '#7c3aed'; $color_rgba = 'rgba(124, 58, 237, 0.85)';
                if($general_setting->theme == 'default.css'){
                    $color = '#7c3aed';
                    $color_rgba = 'rgba(124, 58, 237, 0.85)';
                }
                elseif($general_setting->theme == 'green.css'){
                    $color = '#10b981';
                    $color_rgba = 'rgba(16, 185, 129, 0.85)';
                }
                elseif($general_setting->theme == 'blue.css'){
                    $color = '#0284c7';
                    $color_rgba = 'rgba(2, 132, 199, 0.85)';
                }
                elseif($general_setting->theme == 'dark.css'){
                    $color = '#64748b';
                    $color_rgba = 'rgba(100, 116, 139, 0.85)';
                }
            @endphp
            <canvas id="bestSeller" data-color="{{ $color }}" data-color_rgba="{{ $color_rgba }}" data-product="{{ json_encode($product) }}" data-sold_qty="{{ json_encode($sold_qty) }}" class="u-maxh-440px"></canvas>
        </div>
    </div>
</section>

@endsection
@push('scripts')
<script type="text/javascript">

	$("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #best-seller-report-menu").addClass("active");

	$('#warehouse_id').val($('input[name="warehouse_id_hidden"]').val());
	$('.selectpicker').selectpicker('refresh');

	$('#warehouse_id').on("change", function(){
		$('#report-form').submit();
	});
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/report-best-seller.css') }}">
@endpush
