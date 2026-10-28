@section('charts', '1')
@extends('backend.layout.main')
@section('content')


<x-error-message key="not_permitted" />

@php
    $warehouseName = 'All Warehouses';
    if($warehouse_id && $warehouse_id > 0) {
        $wh = \App\Models\Warehouse::find($warehouse_id);
        if($wh) $warehouseName = $wh->name;
    }

    $estimatedProfit = $total_price - $total_cost;
    $profitMarginPercent = ($total_cost > 0) ? (($estimatedProfit / $total_cost) * 100) : 0;
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-store text-primary"></i> {{ __('db.Stock Chart') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Inventory Valuation & Margin Distribution Analytics</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/warehouse_stock') }}"><i class="dripicons-store"></i> Stock Chart</a></li>
            <li><a class="nav-link" href="{{ url('report/warehouse_report') }}"><i class="dripicons-archive"></i> Warehouse Report</a></li>
            <li><a class="nav-link" href="{{ url('report/product_report') }}"><i class="dripicons-box"></i> Product Report</a></li>
            <li><a class="nav-link" href="{{ url('report/qty_alert_report') }}"><i class="dripicons-warning"></i> Qty Alerts</a></li>
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
                <div class="kpi-label">TOTAL STOCK ITEMS</div>
                <div class="kpi-number">{{ number_format((float)$total_item, $general_setting->decimal, '.', '') }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-box u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL QUANTITY</div>
                <div class="kpi-number">{{ number_format((float)$total_qty, $general_setting->decimal, '.', '') }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-stack u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">STOCK VALUE (BY COST)</div>
                <div class="kpi-number">₹ {{ number_format((float)$total_cost, $general_setting->decimal, '.', '') }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-tag u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">STOCK VALUE (BY PRICE)</div>
                <div class="kpi-number u-c-info">₹ {{ number_format((float)$total_price, $general_setting->decimal, '.', '') }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">ESTIMATED PROFIT</div>
                <div class="kpi-number u-c-success">₹ {{ number_format((float)$estimatedProfit, $general_setting->decimal, '.', '') }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-graph-bar u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {{ Form::open(['route' => 'report.warehouseStock', 'method' => 'get', 'id' => 'report-form', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) }}
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-store"></i> {{ __('db.Choose Warehouse') }}</label>
                <select class="form-control form-control-sm selectpicker u-h-30px u-fs-12px u-minw-220px" id="warehouse_id" name="warehouse_id">
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

    <!-- Chart & Valuation Breakdown Grid -->
    <div class="desk-card-wrapper">
        <div class="row align-items-center">
            <!-- Left Column: Valuation Breakdown Details -->
            <div class="col-md-5">
                <div class="u-p-10px-16px-10px-0">
                    <h5 class="font-weight-bold mb-3 u-c-text">
                        <i class="dripicons-view-thumb text-primary mr-2"></i> Inventory Portfolio Valuation
                    </h5>
                    <p class="text-muted small mb-4">
                        Stock valuation breakdown comparing acquisition cost versus estimated retail realizable value for <strong>{{ $warehouseName }}</strong>.
                    </p>

                    <div class="valuation-stat-box">
                        <span class="stat-label"><i class="fa fa-circle text-warning mr-1"></i> Stock Value (By Cost)</span>
                        <span class="stat-val">₹ {{ number_format((float)$total_cost, $general_setting->decimal, '.', '') }}</span>
                    </div>

                    <div class="valuation-stat-box">
                        <span class="stat-label"><i class="fa fa-circle text-primary mr-1"></i> Stock Value (By Retail Price)</span>
                        <span class="stat-val u-c-info">₹ {{ number_format((float)$total_price, $general_setting->decimal, '.', '') }}</span>
                    </div>

                    <div class="valuation-stat-box u-bl-3px-solid-success-border">
                        <span class="stat-label"><i class="fa fa-circle text-success mr-1"></i> Expected Gross Profit</span>
                        <span class="stat-val u-c-success">₹ {{ number_format((float)$estimatedProfit, $general_setting->decimal, '.', '') }}</span>
                    </div>

                    <div class="valuation-stat-box">
                        <span class="stat-label"><i class="dripicons-pulse text-info mr-1"></i> Projected Markup / Margin</span>
                        <span class="stat-val u-c-primary">{{ number_format($profitMarginPercent, 1) }} %</span>
                    </div>
                </div>
            </div>

            <!-- Right Column: Pie Chart -->
            <div class="col-md-7">
                <div class="u-maxw-440px u-m-0-auto u-p-10px">
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
                    <canvas id="pieChart" data-color="{{$color}}" data-color_rgba="{{$color_rgba}}" data-price="{{$total_price}}" data-cost="{{$total_cost}}" width="360" height="360" data-label1="{{__('db.Stock Value by Price')}}" data-label2="{{__('db.Stock Value by Cost')}}" data-label3="{{__('db.Estimate Profit')}}"></canvas>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
	$("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #warehouse-stock-report-menu").addClass("active");

    var warehouse_id = <?php echo json_encode($warehouse_id) ?>;
	$('#warehouse_id').val(warehouse_id);
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
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/report-warehouse-stock.css') }}">
@endpush
