@extends('backend.compliance.layout')
@section('title','GST returns')
@section('compliance_body')
@php
    $inr = fn ($n) => \App\Services\Documents\DocumentRenderingService::inr((float) $n);
    $q = fn (array $extra) => url('/compliance/gst/returns?'.http_build_query(['from' => $from, 'to' => $to] + $extra));
    $boxes = $gstr3b['boxes'];
    $labels = ['3.1(a)' => 'Outward taxable supplies', '3.1(b)' => 'Zero-rated (exports, SEZ)', '3.1(c)' => 'Nil-rated and exempt', '3.1(d)' => 'Inward supplies on reverse charge',
        '3.1(e)' => 'Non-GST outward', '4(A)(3)' => 'ITC: inward on reverse charge', '4(A)(5)' => 'ITC: all other', '4(B)(1)' => 'ITC reversed (Sec 17(5) blocked)', '4(C) net ITC' => 'Net ITC available'];
    $itcTables = ['itc04_4' => ['Table 4 · goods sent to job workers', $itc04['table_4_sent']], 'itc04_5a' => ['Table 5A · received back (with loss)', $itc04['table_5a_received_back']],
        'itc04_5c' => ['Table 5C · supplied from job worker premises', $itc04['table_5c_supplied_from_job_worker']]];
@endphp
<link rel="stylesheet" href="{{ versioned_asset('css/pages/compliance-gst-returns.css') }}">
<div class="d-flex flex-wrap align-items-end justify-content-between mb-2 u-gap-10px">
    <form method="GET" class="cx-filter" data-enter-flow>
        <div class="cx-field"><label for="ret-from">From</label><input id="ret-from" name="from" type="date" value="{{ $from }}" required></div>
        <div class="cx-field"><label for="ret-to">To</label><input id="ret-to" name="to" type="date" value="{{ $to }}" required></div>
        <button type="submit" class="cx-btn"><i class="dripicons-search"></i> Show period</button>
    </form>
    <p class="cx-lead mb-0">Figures from posted documents. Type the GSTR-3B boxes into the portal; upload ITC-04 tables through the GST offline tool.</p>
</div>
<div class="gst-returns">
    <div class="cx-card" id="gstr3b">
        <h2><i class="dripicons-document"></i> GSTR-3B <a class="cx-btn ghost ml-auto" href="{{ $q(['download' => 'gstr3b', 'format' => 'json']) }}"><i class="dripicons-download"></i> JSON</a></h2>
        <div class="cx-table-wrap"><table class="cx-table">
            <thead><tr><th>Box</th><th>Details</th><th class="num">Taxable</th><th class="num">IGST</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Cess</th></tr></thead>
            <tbody>
            @foreach($labels as $box => $label)
                <tr><td><strong>{{ $box }}</strong></td><td>{{ $label }}</td>
                    <td class="num">{{ isset($boxes[$box]['taxable_value']) ? $inr($boxes[$box]['taxable_value']) : '' }}</td>
                    @foreach(['igst', 'cgst', 'sgst', 'cess'] as $head)<td class="num">{{ $inr($boxes[$box][$head] ?? 0) }}</td>@endforeach</tr>
            @endforeach
                <tr><td><strong>5</strong></td><td>Exempt, nil and non-GST inward</td><td class="num">Inter {{ $inr($boxes['5']['inter_state']) }} · Intra {{ $inr($boxes['5']['intra_state']) }}</td><td colspan="4"></td></tr>
            </tbody>
        </table></div>
        <h3 class="mt-2">6.1 Payment of tax (ITC set off as Rule 88A requires)</h3>
        <div class="cx-table-wrap"><table class="cx-table">
            <thead><tr><th></th><th class="num">IGST</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Cess</th></tr></thead>
            <tbody>
            @foreach(['liability' => 'Tax payable', 'paid_in_cash' => 'Paid in cash (incl. reverse charge)', 'credit_carried_forward' => 'ITC carried forward'] as $key => $label)
                <tr><td>{{ $label }}</td>@foreach(['igst', 'cgst', 'sgst', 'cess'] as $head)<td class="num">{{ $inr($gstr3b['6.1'][$key][$head] ?? 0) }}</td>@endforeach</tr>
            @endforeach
                <tr><td>Paid through ITC</td><td colspan="4">@forelse($gstr3b['6.1']['paid_through_itc'] as $credit => $heads)@foreach($heads as $head => $amount){{ strtoupper($credit) }} credit → {{ strtoupper($head) }} {{ $inr($amount) }}; @endforeach @empty — @endforelse</td></tr>
            </tbody>
        </table></div>
        @if($gstr3b['3.2_inter_state_unregistered'])
            <h3 class="mt-2">3.2 Inter-state supplies to unregistered persons</h3>
            <p class="cx-help mb-0">@foreach($gstr3b['3.2_inter_state_unregistered'] as $place => $row)State {{ $place }}: {{ $inr($row['taxable_value']) }} (IGST {{ $inr($row['igst']) }}); @endforeach</p>
        @endif
    </div>
    <div class="cx-card" id="itc04">
        <h2><i class="dripicons-swap"></i> Form GST ITC-04 · job work</h2>
        <p class="cx-help">Half-yearly if turnover was over ₹5 crore, otherwise yearly. Shrinkage is reported as loss and waste in table 5A.</p>
        @foreach($itcTables as $key => [$title, $rows])
            <h3 class="d-flex align-items-center u-gap-6px">{{ $title }} ({{ count($rows) }})
                @if($rows)<a class="cx-btn ghost" href="{{ $q(['download' => $key, 'format' => 'csv']) }}">CSV</a>@endif</h3>
            @if($rows)
            <div class="cx-table-wrap mb-2"><table class="cx-table">
                <thead><tr>@foreach(array_keys($rows[0]) as $column)<th>{{ str_replace('_', ' ', $column) }}</th>@endforeach</tr></thead>
                <tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody>
            </table></div>
            @endif
        @endforeach
        <h2 class="mt-3" id="tds"><i class="dripicons-wallet"></i> TDS under 194Q ({{ count($tds) }})
            @if($tds)<a class="cx-btn ghost ml-auto" href="{{ $q(['download' => 'tds_194q', 'format' => 'csv']) }}">CSV</a>@endif</h2>
        <p class="cx-help">Deposit by the 7th of next month (challan 281) and report in the quarterly 26Q return.</p>
        @if($tds)
        <div class="cx-table-wrap"><table class="cx-table">
            <thead><tr><th>Bill</th><th>Date</th><th>Supplier</th><th>PAN</th><th class="num">Base</th><th class="num">Rate</th><th class="num">TDS</th></tr></thead>
            <tbody>@foreach($tds as $row)<tr><td>{{ $row['bill'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['supplier'] }}</td><td>{{ $row['pan'] ?? 'No PAN (206AA)' }}</td>
                <td class="num">{{ $inr($row['base']) }}</td><td class="num">{{ $row['rate'] }}%</td><td class="num">{{ $inr($row['amount']) }}</td></tr>@endforeach</tbody>
        </table></div>
        @endif
    </div>
</div>
@endsection
