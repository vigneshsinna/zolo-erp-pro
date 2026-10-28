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

    $totalSoldVolume = 0;
    if(is_array($sold_qty)) {
        foreach($sold_qty as $qty) {
            $totalSoldVolume += floatval($qty);
        }
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-graph-line text-primary"></i> {{ __('db.Sale Report Chart') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Sales Volume Trend & Sold Quantity Velocity</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/sale_report_chart') }}"><i class="dripicons-graph-line"></i> Sales Chart</a></li>
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
            <li><a class="nav-link" href="{{ url('report/best_seller') }}"><i class="dripicons-trophy"></i> Best Seller</a></li>
            <li><a class="nav-link" href="{{ url('report/warehouse_stock') }}"><i class="dripicons-store"></i> Stock Chart</a></li>
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
                <div class="kpi-number u-fs-13_5px u-fw-700 u-c-text-2">
                    {{ $start_date }} &mdash; {{ $end_date }}
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
                <div class="kpi-label">PERIOD GRANULARITY</div>
                <div class="kpi-number u-fs-14px u-fw-700 u-tt-capitalize">{{ $time_period ?? 'Monthly' }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-pulse u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL UNITS SOLD</div>
                <div class="kpi-number u-c-success">{{ number_format($totalSoldVolume, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-box u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="desk-filter-card">
        {!! Form::open(['route' => 'report.saleChart', 'method' => 'post', 'id' => 'report-filter-form']) !!}
            <div class="row align-items-end">
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold text-muted small"><i class="dripicons-calendar"></i> {{ __('db.Choose Your Date') }}</label>
                    <input type="text" class="daterangepicker-field form-control form-control-sm u-h-32px u-fs-12px" value="{{$start_date}} To {{$end_date}}" required />
                    <input type="hidden" name="start_date" value="{{$start_date}}" />
                    <input type="hidden" name="end_date" value="{{$end_date}}" />
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold text-muted small"><i class="dripicons-store"></i> {{ __('db.Choose Warehouse') }}</label>
                    <input type="hidden" name="warehouse_id_hidden" value="{{$warehouse_id}}" />
                    <select id="warehouse_id" name="warehouse_id" class="selectpicker form-control form-control-sm u-h-32px u-fs-12px" data-live-search="true">
                        <option value="0">{{ __('db.All Warehouse') }}</option>
                        @foreach($lims_warehouse_list as $warehouse)
                            <option value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="font-weight-bold text-muted small"><i class="dripicons-clock"></i> {{ __('db.Time Period') }}</label>
                    <select class="form-control form-control-sm u-h-32px u-fs-12px" name="time_period">
                        <option value="weekly" {{ $time_period == 'weekly' ? 'selected' : '' }}>Weekly</option>
                        <option value="monthly" {{ $time_period == 'monthly' ? 'selected' : '' }}>Monthly</option>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold text-muted small"><i class="dripicons-search"></i> {{ __('db.product_list') }}</label>
                    <input type="text" name="product_list" class="form-control form-control-sm u-h-32px u-fs-12px" placeholder="{{ __('db.Type product code seperated by comma') }}">
                </div>
                <div class="col-md-1 mb-2">
                    <button class="btn btn-primary btn-block btn-action-pill justify-content-center u-bg-ink u-bc-ink u-h-32px u-w-100pct" type="submit">
                        <i class="fa fa-filter"></i>
                    </button>
                </div>
            </div>
        {!! Form::close() !!}
    </div>

    <!-- Chart Container Wrapper -->
    <div class="desk-card-wrapper">
        @php 
            $color = '#7c3aed';
            $color_rgba = 'rgba(124, 58, 237, 0.85)';
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
        <div class="u-w-100pct u-minh-400px">
            <canvas id="sale-report-chart" data-color="{{$color}}" data-color_rgba="{{$color_rgba}}" data-soldqty="{{json_encode($sold_qty)}}" data-datepoints="{{json_encode($date_points)}}" data-label1="{{__('db.Sold Qty')}}" class="u-maxh-480px u-w-100pct"></canvas>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
	$("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #sale-report-chart-menu").addClass("active");

	$('#warehouse_id').val($('input[name="warehouse_id_hidden"]').val());
	$('.selectpicker').selectpicker('refresh');

    $(".daterangepicker-field").daterangepicker({
      callback: function(startDate, endDate, period){
        var start_date = startDate.format('YYYY-MM-DD');
        var end_date = endDate.format('YYYY-MM-DD');
        var title = start_date + ' To ' + end_date;
        $(this).val(title);
        $('input[name="start_date"]').val(start_date);
        $('input[name="end_date"]').val(end_date);
      }
    });
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/report-sale-report-chart.css') }}">
@endpush
