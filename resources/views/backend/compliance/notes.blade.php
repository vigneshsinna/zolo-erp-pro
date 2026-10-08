@extends('backend.compliance.layout')
@section('title','Returns & financial notes')
@section('content')
<p>Open a posted sale or purchase to create a note. Approvals preserve the original invoice and its stock history.</p>
<nav class="actions"><a href="{{ url('/sales') }}">Sales</a><a href="{{ url('/purchases') }}">Purchases</a></nav>
@foreach(['sale' => $sales, 'purchase' => $purchases] as $kind => $notes)<section class="panel"><h2>{{ ucfirst($kind) }} notes</h2><div class="table-scroll"><table><thead><tr><th scope="col">Number / date</th><th scope="col">Reason</th><th scope="col">Type</th><th scope="col">Total</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>
@forelse($notes as $note)<tr><td>{{ $note->reference_no }}<br>{{ $note->created_at->toDateString() }}</td><td>{{ $note->return_note }}</td><td>{{ ucfirst($note->note_type) }} · {{ str_replace('_',' ',$note->adjustment_type) }}</td><td>{{ number_format($note->grand_total,4,'.','') }}</td><td>{{ str_replace('_',' ',$note->status) }}</td><td>
@if($note->status === 'awaiting_approval')<form method="POST" action="{{ url('/compliance/'.$kind.'/notes/'.$note->id.'/approve') }}">@csrf<button type="submit">Approve and post</button></form>
@elseif($note->posted_at)<a href="{{ url('/compliance/documents/'.$kind.'_note/'.$note->id) }}">Print / send</a>
@if($kind === 'sale' && $note->adjustment_type === 'quantity')<br><a href="{{ url('/sales?new=1&exchange_return_id='.$note->id) }}">Exchange with new sale</a>@endif
@endif</td></tr>@empty<tr><td colspan="6">No notes in this financial year and branch.</td></tr>@endforelse</tbody></table></div></section>@endforeach
@endsection
