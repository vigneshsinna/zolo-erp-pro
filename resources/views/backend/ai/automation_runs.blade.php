@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-automation.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops ai-automation py-3" aria-labelledby="rule-history-title">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / Automation Builder</span>
            <h1 id="rule-history-title">Run history: {{ $rule['name'] }}</h1>
            <p>Read-only status and execution evidence from your authorized scheduled rule.</p>
        </div>
        <nav class="ai-automation-actions" aria-label="Automation navigation">
            <a href="{{ route('ai.performance.index') }}" class="btn btn-outline-primary">Agent performance</a>
            <a href="{{ route('ai.builder.index') }}" class="btn btn-outline-secondary">Back to Builder</a>
        </nav>
    </header>
    <section class="ai-ops-panel">
        <h2>Recent scheduled runs</h2>
        <p>Completed means the scheduled check finished. It does not mean an ERP purchase or accounting document was posted.</p>
        <div class="table-responsive">
            <table class="table table-sm ai-automation-table">
                <thead>
                    <tr><th scope="col">Scheduled at (UTC)</th><th scope="col">Outcome</th>
                        <th scope="col">Alerts</th><th scope="col">Preparation</th><th scope="col">Duration</th><th scope="col">Error</th></tr>
                </thead>
                <tbody>
                @forelse($runs as $run)
                    @php
                        $result = json_decode($run['result_json'] ?? '{}', true) ?: [];
                        $duration = $run['started_at'] && $run['completed_at']
                            ? \Illuminate\Support\Carbon::parse($run['started_at'])->diffInMilliseconds(\Illuminate\Support\Carbon::parse($run['completed_at']))
                            : null;
                    @endphp
                    <tr>
                        <td>{{ $run['scheduled_for'] }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', $run['status'])) }}</td>
                        <td>{{ count($result['alerts'] ?? []) }}</td>
                        <td>{{ str_replace('_', ' ', (string) ($result['preparation_status'] ?? ($run['proposal_id'] ? 'Draft prepared' : 'Alert only'))) }}</td>
                        <td>{{ $duration !== null ? number_format(max(0, $duration)) . ' ms' : '—' }}</td>
                        <td>{{ $run['error_code'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No runs recorded. The background queue must process a due schedule before results appear.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
@endsection
