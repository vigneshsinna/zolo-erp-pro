@extends('backend.compliance.layout')
@section('title','Route sheet')
@section('compliance_body')
@php $inr = fn ($n) => \App\Services\Documents\DocumentRenderingService::inr((float) $n); @endphp
<form method="GET" class="cx-filter mb-2" data-enter-flow>
    <div class="cx-field"><label for="route-date">Date</label><input id="route-date" name="date" type="date" value="{{ $date }}" required></div>
    <div class="cx-field"><label for="route-area">Route / area</label><select id="route-area" name="area_id"><option value="">All routes</option>@foreach($areas as $area)<option value="{{ $area->id }}" @selected($areaId === $area->id)>{{ $area->name }}</option>@endforeach</select></div>
    <button type="submit" class="cx-btn"><i class="dripicons-search"></i> Show</button>
    <button type="button" class="cx-btn ghost" data-erp-print><i class="dripicons-print"></i> Print</button>
</form>
<p class="cx-lead">Routes are the Areas master; put each customer on a route in the customer master. Overdue is what is past its due date on {{ \Carbon\Carbon::parse($date)->format('d-m-Y') }}.</p>
<div class="cx-grid">
@forelse($sheet as $route)
    <div class="cx-card">
        <h2><i class="dripicons-map"></i> {{ $route['route'] }} @if($route['code'])<span class="text-muted small">({{ $route['code'] }})</span>@endif</h2>
        <p class="cx-help">{{ count($route['customers']) }} customers · outstanding {{ $inr($route['outstanding']) }} · overdue {{ $inr($route['overdue']) }} · billed today {{ $inr($route['billed_today']) }}</p>
        <div class="cx-table-wrap"><table class="cx-table">
            <thead><tr><th>Customer</th><th>Phone</th><th class="text-right">Outstanding</th><th class="text-right">Overdue</th><th>Last bill</th><th class="text-right">Today</th></tr></thead>
            <tbody>@forelse($route['customers'] as $c)<tr><td>{{ $c['customer'] }}</td><td>{{ $c['phone'] }}</td><td class="text-right">{{ $inr($c['outstanding']) }}</td>
                <td class="text-right {{ $c['overdue'] > 0 ? 'text-danger font-weight-bold' : '' }}">{{ $inr($c['overdue']) }}</td><td>{{ $c['last_invoice'] ?? '—' }}</td><td class="text-right">{{ $inr($c['billed_today']) }}</td></tr>
            @empty<tr><td colspan="6" class="text-muted">No customers on this route.</td></tr>@endforelse</tbody>
        </table></div>
    </div>
@empty
    <div class="cx-card"><p class="mb-0">No active routes. Add them in Masters › Areas.</p></div>
@endforelse
</div>
@endsection
