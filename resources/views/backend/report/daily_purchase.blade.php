@extends('backend.layout.main')
@section('content')


<x-error-message key="not_permitted" />

@php
    $warehouseName = 'All Warehouses';
    if($warehouse_id && $warehouse_id > 0) {
        $wh = \App\Models\Warehouse::find($warehouse_id);
        if($wh) $warehouseName = $wh->name;
    }

    $monthTotalPurchase = 0;
    $monthTotalTax = 0;
    $monthTotalDiscount = 0;
    $activeDays = 0;

    for ($d = 1; $d <= $number_of_day; $d++) {
        $gTot = isset($grand_total[$d]) ? floatval(str_replace(',', '', $grand_total[$d])) : 0;
        $tTax = isset($total_tax[$d]) ? floatval(str_replace(',', '', $total_tax[$d])) : 0;
        $oTax = isset($order_tax[$d]) ? floatval(str_replace(',', '', $order_tax[$d])) : 0;
        $tDisc = isset($total_discount[$d]) ? floatval(str_replace(',', '', $total_discount[$d])) : 0;
        $oDisc = isset($order_discount[$d]) ? floatval(str_replace(',', '', $order_discount[$d])) : 0;

        $monthTotalPurchase += $gTot;
        $monthTotalTax += ($tTax + $oTax);
        $monthTotalDiscount += ($tDisc + $oDisc);
        if ($gTot > 0) {
            $activeDays++;
        }
    }
@endphp

<section class="report-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-calendar text-primary"></i> {{ __('db.Daily Purchase Report') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Daily Inward Procurement & Tax Breakdown Matrix</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="{{ url('report/daily_purchase/'.$year.'/'.$month) }}"><i class="dripicons-calendar"></i> Daily Purchase</a></li>
            <li><a class="nav-link" href="{{ url('report/monthly_purchase/'.$year) }}"><i class="dripicons-calendar"></i> Monthly Purchase</a></li>
            <li><a class="nav-link" href="{{ url('report/purchase_report') }}"><i class="dripicons-box"></i> Procurement Report</a></li>
            <li><a class="nav-link" href="{{ url('report/supplier_report') }}"><i class="dripicons-user"></i> Supplier Report</a></li>
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
                <div class="kpi-label">MONTH WINDOW</div>
                <div class="kpi-number u-fs-14px u-fw-700 u-c-text-2">
                    {{ date("F Y", strtotime($year.'-'.$month.'-01')) }}
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
                <div class="kpi-label">TOTAL MONTHLY INWARD</div>
                <div class="kpi-number u-c-success">₹ {{ number_format($monthTotalPurchase, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-box u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL PURCHASE TAX</div>
                <div class="kpi-number">₹ {{ number_format($monthTotalTax, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-ticket u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">ACTIVE INWARD DAYS</div>
                <div class="kpi-number">{{ $activeDays }} / {{ $number_of_day }} Days</div>
            </div>
            <div class="z-icon-tile z-tone-neutral">
                <i class="dripicons-pulse u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card">
        {{ Form::open(['route' => ['report.dailyPurchaseByWarehouse', $year, $month], 'method' => 'post', 'id' => 'report-form', 'class' => 'form-inline d-flex align-items-center flex-wrap gap-2']) }}
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

    <!-- Calendar Wrapper -->
    <div class="desk-card-wrapper">
        <div class="calendar-month-bar">
            <a href="{{ url('report/daily_purchase/'.$prev_year.'/'.$prev_month) }}" class="btn btn-sm btn-outline-light btn-action-pill u-bc-rgba-255-255-255-0_3 u-c-xffffff">
                <i class="fa fa-chevron-left"></i> {{ __('db.Previous') }}
            </a>
            <div class="h5 font-weight-bold text-white mb-0 text-center">
                <i class="dripicons-calendar mr-2"></i> {{ date("F", strtotime($year.'-'.$month.'-01')).' ' .$year }}
            </div>
            <a href="{{ url('report/daily_purchase/'.$next_year.'/'.$next_month) }}" class="btn btn-sm btn-outline-light btn-action-pill u-bc-rgba-255-255-255-0_3 u-c-xffffff">
                {{ __('db.Next') }} <i class="fa fa-chevron-right"></i>
            </a>
        </div>

        <div class="table-responsive">
            <table class="calendar-table">
                <thead>
                    <tr>
                        <th>SUN</th>
                        <th>MON</th>
                        <th>TUE</th>
                        <th>WED</th>
                        <th>THU</th>
                        <th>FRI</th>
                        <th>SAT</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $i = 1;
                        $flag = 0;
                    @endphp
                    @while ($i <= $number_of_day)
                        <tr>
                            @for ($j = 1; $j <= 7; $j++)
                                @if ($i > $number_of_day)
                                    @php break; @endphp
                                @endif

                                @if ($flag)
                                    @php
                                        $isToday = ($year.'-'.$month.'-'.$i == date('Y').'-'.date('m').'-'.(int)date('d'));
                                        $hasPurchase = (isset($grand_total[$i]) && floatval(str_replace(',', '', $grand_total[$i])) > 0);
                                    @endphp
                                    <td>
                                        <div class="day-header">
                                            <span class="day-number">{{ $i }}</span>
                                            @if($isToday)
                                                <span class="today-pill">Today</span>
                                            @endif
                                        </div>

                                        @if($hasPurchase)
                                            <span class="metric-pill-total">₹ {{ $grand_total[$i] }}</span>
                                        @endif

                                        @if(isset($total_tax[$i]) && floatval(str_replace(',', '', $total_tax[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Tax:</strong> ₹ {{ $total_tax[$i] }}</span>
                                        @endif
                                        @if(isset($order_tax[$i]) && floatval(str_replace(',', '', $order_tax[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Ord.Tax:</strong> ₹ {{ $order_tax[$i] }}</span>
                                        @endif
                                        @if(isset($total_discount[$i]) && floatval(str_replace(',', '', $total_discount[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Disc:</strong> ₹ {{ $total_discount[$i] }}</span>
                                        @endif
                                        @if(isset($order_discount[$i]) && floatval(str_replace(',', '', $order_discount[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Ord.Disc:</strong> ₹ {{ $order_discount[$i] }}</span>
                                        @endif
                                        @if(isset($shipping_cost[$i]) && floatval(str_replace(',', '', $shipping_cost[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Ship:</strong> ₹ {{ $shipping_cost[$i] }}</span>
                                        @endif
                                    </td>
                                    @php $i++; @endphp
                                @elseif ($j == $start_day)
                                    @php
                                        $isToday = ($year.'-'.$month.'-'.$i == date('Y').'-'.date('m').'-'.(int)date('d'));
                                        $hasPurchase = (isset($grand_total[$i]) && floatval(str_replace(',', '', $grand_total[$i])) > 0);
                                    @endphp
                                    <td>
                                        <div class="day-header">
                                            <span class="day-number">{{ $i }}</span>
                                            @if($isToday)
                                                <span class="today-pill">Today</span>
                                            @endif
                                        </div>

                                        @if($hasPurchase)
                                            <span class="metric-pill-total">₹ {{ $grand_total[$i] }}</span>
                                        @endif

                                        @if(isset($total_tax[$i]) && floatval(str_replace(',', '', $total_tax[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Tax:</strong> ₹ {{ $total_tax[$i] }}</span>
                                        @endif
                                        @if(isset($order_tax[$i]) && floatval(str_replace(',', '', $order_tax[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Ord.Tax:</strong> ₹ {{ $order_tax[$i] }}</span>
                                        @endif
                                        @if(isset($total_discount[$i]) && floatval(str_replace(',', '', $total_discount[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Disc:</strong> ₹ {{ $total_discount[$i] }}</span>
                                        @endif
                                        @if(isset($order_discount[$i]) && floatval(str_replace(',', '', $order_discount[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Ord.Disc:</strong> ₹ {{ $order_discount[$i] }}</span>
                                        @endif
                                        @if(isset($shipping_cost[$i]) && floatval(str_replace(',', '', $shipping_cost[$i])) > 0)
                                            <span class="metric-sub-line"><strong>Ship:</strong> ₹ {{ $shipping_cost[$i] }}</span>
                                        @endif
                                    </td>
                                    @php
                                        $flag = 1;
                                        $i++;
                                    @endphp
                                @else
                                    <td class="empty-day"></td>
                                @endif
                            @endfor
                        </tr>
                    @endwhile
                </tbody>
            </table>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
	$("ul#report").siblings('a').attr('aria-expanded','true');
    $("ul#report").addClass("show");
    $("ul#report #daily-purchase-report-menu").addClass("active");

	$('#warehouse_id').val($('input[name="warehouse_id_hidden"]').val());
	$('.selectpicker').selectpicker('refresh');

	$('#warehouse_id').on("change", function(){
        if ($(this).val() == '0') {
            window.location.href = "{{ url('report/daily_purchase/'.$year.'/'.$month) }}";
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
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-report-daily-purchase.css') }}">
@endpush
