@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops py-3" aria-labelledby="ai-exceptions-heading">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / AI Operations</span>
            <h1 id="ai-exceptions-heading">Needs Review</h1>
            <p>Exceptions and pending proposals in your current company, branch and financial year.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('ai.operations') }}">AI Operations</a>
    </header>
    @unless($enabled)
        <div class="alert alert-warning" role="status">The action foundation is disabled. There is no review queue available.</div>
    @else
        <div class="alert alert-info" role="status">Triage is read-only. Approvals remain in the signed Action Review Center. Never retry an uncertain execution without reconciliation.</div>
        <section class="ai-ops-panel" aria-label="Scoped action exceptions">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h2>Pending decisions and reconciliation</h2>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('ai.actions.review', ['scope' => $canApprove ? 'all' : 'mine', 'status' => 'all']) }}">Action Review Center</a>
            </div>
            @forelse($items as $item)
                <article class="ai-ops-activity">
                    <div>
                        <strong>{{ str_replace(['.', '_'], ' ', $item['key']) }} <span class="badge badge-light">{{ $item['risk'] }}</span></strong>
                        <small>{{ ucfirst(str_replace('_', ' ', $item['status'])) }} · {{ $item['updated_at'] }} · ID {{ substr($item['id'], 0, 8) }}</small>
                        @foreach($item['issues'] as $issue)
                            <small>{{ $issue }}</small>
                        @endforeach
                    </div>
                    <span class="badge {{ $item['priority'] === 'critical' ? 'badge-danger' : ($item['priority'] === 'high' ? 'badge-warning' : 'badge-light') }}">{{ ucfirst($item['priority']) }}</span>
                </article>
            @empty
                <p class="ai-ops-empty">No pending decisions or reconciliation items in this scope.</p>
            @endforelse
        </section>
    @endunless
</main>
@endsection
