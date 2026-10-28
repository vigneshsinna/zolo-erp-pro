@extends('backend.layout.main')
@section('content')


<x-error-message key="not_permitted" />

@php
    $warehouseName = 'All Warehouses';
    if($warehouse_id && $warehouse_id > 0) {
        $wh = \App\Models\Warehouse::find($warehouse_id);
        if($wh) $warehouseName = $wh->name;
    }

    $annualTotalSales = 0;
    $annualTotalTax = 0;
    $annualTotalDiscount = 0;
    $activeMonths = 0;

    $monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    foreach($monthNames as $idx => $mName) {
        $t = isset($total[$idx]) ? floatval(str_replace(',', '', $total[$idx])) : 0;
        $tTax = isset($total_tax[$idx]) ? floatval(str_replace(',', '', $total_tax[$idx])) : 0;
        $oTax = isset($order_tax[$idx]) ? floatval(str_replace(',', '', $order_tax[$idx])) : 0;
        $tDisc = isset($total_discount[$idx]) ? floatval(str_replace(',', '', $total_discount[$idx])) : 0;
        $oDisc = isset($order_discount[$idx]) ? floatval(str_replace(',', '', $order_discount[$idx])) : 0;

        $annualTotalSales += $t;
        $annualTotalTax += ($tTax + $oTax);
        $annualTotalDiscount += ($tDisc + $oDisc);
        if($t > 0) {
            $activeMonths++;
        }
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-calendar text-primary"></i> {{ __('db.Monthly Sale Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Annual Sales Turnover & Monthly Velocity Matrix</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/monthly_sale/'.$year) }}"><i class="dripicons-calendar"></i> Monthly Sale</a></li>
            <li><a class="nav-link" href="{{ url('report/daily_sale/'.$year.'/'.date('m')) }}"><i class="dripicons-calendar"></i> Daily Sale</a></li>
            <li><a class="nav-link" href="{{ url('report/sale_report') }}"><i class="dripicons-cart"></i> Sales Report</a></li>
            <li><a class="nav-link" href="{{ url('report/best_seller') }}"><i class="dripicons-trophy"></i> Best Seller</a></li>
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
                <div class="kpi-label">ANNUAL WINDOW</div>
                <div class="kpi-number u-fs-15px u-fw-700 u-c-text-2">
                    Year {{ $year }}
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
                <div class="kpi-label">ANNUAL GRAND TURNOVER</div>
                <div class="kpi-number u-c-success">₹ {{ number_format($annualTotalSales, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL ANNUAL TAX</div>
                <div class="kpi-number">₹ {{ number_format($annualTotalTax, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-ticket u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">ACTIVE TRADING MONTHS</div>
                <div class="kpi-number">{{ $activeMonths }} / 12 Months</div>
            </div>
            <div class="z-icon-tile z-tone-neutral">
                <i class="dripicons-pulse u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {{ Form::open(['route' => ['report.monthlySaleByWarehouse', $year], 'method' => 'post', 'id' => 'report-form', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) }}
            <input type="hidden" name="warehouse_id_hidden" value="{{ $warehouse_id }}">
            <div class="form-group mr-2 mb-1">
                <label class="mr-2 font-weight-bold text-muted small"><i class="dripicons-store"></i> {{ __('db.Choose Warehouse') }}</label>
                <select class="form-control form-control-sm selectpicker u-h-30px u-fs-12px u-minw-200px" id="warehouse_id" name="warehouse_id">
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

    <!-- Annual Months Container Wrapper -->
    <div class="desk-card-wrapper">
        <div class="calendar-year-bar">
            <a href="{{ url('report/monthly_sale/'.($year-1)) }}" class="btn btn-sm btn-outline-light btn-action-pill u-bc-rgba-255-255-255-0_3 u-c-xffffff">
                <i class="fa fa-chevron-left"></i> {{ __('db.Previous') }} ({{ $year - 1 }})
            </a>
            <div class="h5 font-weight-bold text-white mb-0 text-center">
                <i class="dripicons-calendar mr-2"></i> Sales Turnover Calendar &mdash; Year {{ $year }}
            </div>
            <a href="{{ url('report/monthly_sale/'.($year+1)) }}" class="btn btn-sm btn-outline-light btn-action-pill u-bc-rgba-255-255-255-0_3 u-c-xffffff">
                ({{ $year + 1 }}) {{ __('db.Next') }} <i class="fa fa-chevron-right"></i>
            </a>
        </div>

        <div class="months-grid">
            @foreach($monthNames as $idx => $mName)
                @php
                    $mNumber = str_pad($idx + 1, 2, '0', STR_PAD_LEFT);
                    $mSales = isset($total[$idx]) ? $total[$idx] : '0';
                    $hasSales = floatval(str_replace(',', '', $mSales)) > 0;
                @endphp
                <div class="month-card">
                    <div class="month-header">
                        <span class="month-title">{{ $mName }} {{ $year }}</span>
                        <a href="{{ url('report/daily_sale/'.$year.'/'.$mNumber) }}" class="btn btn-sm btn-link p-0 text-primary small u-fs-11px" title="View Daily Breakdown">
                            Daily <i class="fa fa-arrow-right"></i>
                        </a>
                    </div>

                    <div class="month-total-pill">
                        <span class="u-fs-10_5px u-tt-uppercase u-fw-600">Total</span>
                        <span>₹ {{ $mSales }}</span>
                    </div>

                    @if(isset($total_tax[$idx]) && floatval(str_replace(',', '', $total_tax[$idx])) > 0)
                        <div class="month-metric-row">
                            <span>Product Tax:</span>
                            <strong>₹ {{ $total_tax[$idx] }}</strong>
                        </div>
                    @endif
                    @if(isset($order_tax[$idx]) && floatval(str_replace(',', '', $order_tax[$idx])) > 0)
                        <div class="month-metric-row">
                            <span>Order Tax:</span>
                            <strong>₹ {{ $order_tax[$idx] }}</strong>
                        </div>
                    @endif
                    @if(isset($total_discount[$idx]) && floatval(str_replace(',', '', $total_discount[$idx])) > 0)
                        <div class="month-metric-row">
                            <span>Product Disc:</span>
                            <strong>₹ {{ $total_discount[$idx] }}</strong>
                        </div>
                    @endif
                    @if(isset($order_discount[$idx]) && floatval(str_replace(',', '', $order_discount[$idx])) > 0)
                        <div class="month-metric-row">
                            <span>Order Disc:</span>
                            <strong>₹ {{ $order_discount[$idx] }}</strong>
                        </div>
                    @endif
                    @if(isset($shipping_cost[$idx]) && floatval(str_replace(',', '', $shipping_cost[$idx])) > 0)
                        <div class="month-metric-row">
                            <span>Shipping Cost:</span>
                            <strong>₹ {{ $shipping_cost[$idx] }}</strong>
                        </div>
                    @endif
                    @if(!$hasSales)
                        <div class="text-center text-muted small py-2 u-fs-11px">
                            No sales recorded
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
	$("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #monthly-sale-report-menu").addClass("active");

	$('#warehouse_id').val($('input[name="warehouse_id_hidden"]').val());
	$('.selectpicker').selectpicker('refresh');

	$('#warehouse_id').on("change", function(){
        if ($(this).val() == '0') {
            window.location.href = "{{ url('report/monthly_sale/'.$year) }}";
        } else {
            $('#report-form').submit();
        }
	});
</script>
@endpush

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-monthly-purchase.css') }}">
@endpush
