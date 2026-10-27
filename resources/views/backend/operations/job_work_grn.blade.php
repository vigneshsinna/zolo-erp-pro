@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    $d = $receipt->details_json ?? [];
    $meta = array_filter([
        'GRN No.' => $receipt->reference_no, 'GRN Date' => \Carbon\Carbon::parse($receipt->business_date)->format('d-m-Y'),
        'Against DC' => $dispatch->reference_no.' ('.\Carbon\Carbon::parse($dispatch->business_date)->format('d-m-Y').')',
        'Job work order' => $order->reference_no, 'Process' => $c['process']['name'] ?? null,
        'Received into' => $receivedInto, 'Rejected to' => $quarantine, 'Service bill' => $bill,
    ], fn ($value) => $value !== null && $value !== '');
    $sum = fn ($key) => $lines->sum(fn ($line) => (float) $line->{$key});
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Job Work GRN {{ $receipt->reference_no }}</title>
<link rel="stylesheet" href="{{ versioned_asset('css/pages/operations-job-work-challan.css') }}"></head><body>
<div class="print-bar"><button type="button" onclick="window.print()">Print 2 copies</button></div>
@foreach(['Original (our copy)', 'Duplicate for job worker'] as $copy)
<div class="sheet copy">
    <table class="top"><tr>
        <td class="u-w-33pct"><strong>GSTIN: {{ $c['company']['gstin'] ?: 'Unregistered' }}</strong></td>
        <td class="title u-w-34pct">Job Work GRN</td>
        <td class="u-w-33pct u-ta-right"><em>{{ $copy }}</em></td>
    </tr><tr><td colspan="3" class="sub">Goods received back from job work · Section 143 of the CGST Act</td></tr></table>

    <table class="box">
        <tr><td colspan="2" class="u-p-0"><table class="letterhead"><tr>
            <td class="logo">@if($c['company']['logo'])<img src="{{ $c['company']['logo'] }}" alt="">@endif</td>
            <td class="company"><h1>{{ $c['company']['name'] }}</h1>
                @if(strcasecmp($c['company']['legal'], $c['company']['name']) !== 0)<p>{{ $c['company']['legal'] }}</p>@endif
                <p>{{ $c['company']['address'] }}</p>
                <p>{{ implode(' · ', array_filter([$c['company']['state'] ? 'State: '.$c['company']['state'] : null, $c['company']['phone'] ? 'Ph: '.$c['company']['phone'] : null, $c['company']['email']])) }}</p></td>
            <td class="logo"></td></tr></table></td></tr>
        <tr>
            <td class="u-w-55pct"><div class="label">Received from (Job worker)</div>
                <div class="party">{{ $c['worker']['name'] }}</div>
                @if($c['worker']['contact'] && $c['worker']['contact'] !== $c['worker']['name'])<div>{{ $c['worker']['contact'] }}</div>@endif
                <div>{{ $c['worker']['address'] ?: 'Address not recorded' }}</div>
                @if($c['worker']['state'])<div>State: {{ $c['worker']['state'] }}</div>@endif
                @if($c['worker']['phone'])<div>Ph: {{ $c['worker']['phone'] }}</div>@endif
                <div><strong>GSTIN: {{ $c['worker']['gstin'] ?: 'Unregistered job worker' }}</strong></div>
            </td>
            <td><table class="meta">@foreach($meta as $key => $value)<tr><td class="k">{{ $key }}</td><td>:</td><td class="v">{{ $value }}</td></tr>@endforeach</table></td>
        </tr>
    </table>

    <table class="box items u-mt-1px">
        <thead><tr><th class="u-w-5pct">S.No</th><th>Material sent</th><th class="u-w-10pct">HSN</th><th class="u-w-13pct">Sent on DC</th><th class="u-w-13pct">Accepted</th><th class="u-w-13pct">Rejected</th><th class="u-w-11pct">Loss</th></tr></thead>
        <tbody>
        @foreach($lines as $i => $line)
            <tr><td class="c">{{ $i + 1 }}</td><td><strong>{{ $line->name }}</strong> <span class="small">{{ $line->code }}</span></td><td class="c">{{ $line->hsn_code ?: '—' }}</td>
                <td class="num">{{ $qty($line->sent) }} {{ $line->unit_code }}</td><td class="num">{{ $qty($line->accepted_qty) }} {{ $line->unit_code }}</td>
                <td class="num">{{ $qty($line->rejected_qty) }} {{ $line->unit_code }}</td><td class="num">{{ $qty($line->loss_qty) }} {{ $line->unit_code }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="3" class="num"><strong>Total</strong></td><td class="num"><strong>{{ $qty($sum('sent')) }}</strong></td><td class="num"><strong>{{ $qty($sum('accepted_qty')) }}</strong></td>
            <td class="num"><strong>{{ $qty($sum('rejected_qty')) }}</strong></td><td class="num"><strong>{{ $qty($sum('loss_qty')) }}</strong></td></tr></tfoot>
    </table>

    @if($outputs->isNotEmpty())
    <table class="box items u-mt-1px">
        <thead><tr><th class="u-w-5pct">S.No</th><th>Came back as (processed goods)</th><th class="u-w-20pct">Quantity</th></tr></thead>
        <tbody>@foreach($outputs as $i => $out)<tr><td class="c">{{ $i + 1 }}</td><td><strong>{{ $out->name }}</strong> <span class="small">{{ $out->code }}</span></td><td class="num">{{ $qty($out->qty_base) }} {{ $out->unit_code }}</td></tr>@endforeach</tbody>
    </table>
    @endif

    <table class="box u-mt-1px"><tr><td>
        <p class="note"><strong>Summary:</strong> Received {{ $qty($d['received_qty'] ?? $sum('accepted_qty') + $sum('rejected_qty')) }} · Loss {{ $qty($d['loss_qty'] ?? $sum('loss_qty')) }}@if(isset($d['loss_percent'])) ({{ (float) $d['loss_percent'] }}%)@endif @if(!empty($d['lots']['output'])) · Lots {{ implode(', ', $d['lots']['output']) }}@endif</p>
        <p class="note"><strong>Tax:</strong> Goods returned from job work under Section 143 of the CGST Act; no GST on this receipt. The job worker's processing charge is billed separately{{ $bill ? ' on '.$bill : '' }}.</p>
        @if($receipt->reversed_at)<p class="note"><strong>Reversed:</strong> {{ $receipt->reversal_reason }}</p>@endif
    </td></tr></table>

    <table class="box sign u-mt-1px"><tr>
        <td class="u-w-50pct">Goods handed over by<br><br>Signature, name &amp; date<br>for {{ $c['worker']['name'] }}</td>
        <td class="u-ta-right">Received and checked for {{ $c['company']['name'] }}<br><br><br>Store in-charge / Authorised Signatory</td>
    </tr></table>
</div>
@endforeach
</body></html>
