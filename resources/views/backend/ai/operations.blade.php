@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops py-3" aria-labelledby="ai-ops-heading">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / AI</span>
            <h1 id="ai-ops-heading">AI Operations</h1>
            <p>Manage governed actions, review exceptions, and follow your scheduled work.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center ai-ops-actions">
            @if($canManagePolicies)
                <a class="btn btn-outline-secondary" href="{{ route('ai.autonomy.index') }}">Autonomy policies</a>
            @endif
            <a class="btn btn-primary" href="{{ route('ai.workspace') }}"><i class="dripicons-sparkles mr-1" aria-hidden="true"></i> Ask Zolo</a>
        </div>
    </header>

    @unless($enabled)
        <div class="alert alert-warning" role="status">AI is unavailable for this company. No automation or background execution is enabled here.</div>
    @endunless

    <div class="ai-ops-grid" aria-label="AI workspaces">
        <section class="ai-ops-card" aria-labelledby="ai-ops-review-title">
            <div class="ai-ops-card-icon"><i class="dripicons-checklist" aria-hidden="true"></i></div>
            <h2 id="ai-ops-review-title">Needs Review</h2>
            <p>Pending approval and exceptions that require a decision.</p>
            <strong class="ai-ops-metric">{{ $summary['pending'] + $summary['attention'] }}</strong>
            @if($foundation)
                <a href="{{ route('ai.exceptions.index') }}">Triage exceptions <span aria-hidden="true">&rarr;</span></a>
                <a href="{{ route('ai.actions.review', ['status' => 'pending', 'scope' => $canApprove ? 'all' : 'mine']) }}" class="mt-2">Open review queue <span aria-hidden="true">&rarr;</span></a>
            @else
                <span class="ai-ops-note">Action foundation not enabled</span>
            @endif
        </section>
        <section class="ai-ops-card" aria-labelledby="ai-ops-completed-title">
            <div class="ai-ops-card-icon"><i class="dripicons-checkmark" aria-hidden="true"></i></div>
            <h2 id="ai-ops-completed-title">Completed Actions</h2>
            <p>Previously executed, governed ERP proposals.</p>
            <strong class="ai-ops-metric">{{ $summary['completed'] }}</strong>
            @if($foundation)
                <a href="{{ route('ai.actions.review', ['status' => 'completed', 'scope' => $canApprove ? 'all' : 'mine']) }}">View completed actions <span aria-hidden="true">&rarr;</span></a>
            @else
                <span class="ai-ops-note">No action history available</span>
            @endif
        </section>
        <section class="ai-ops-card" id="automations" aria-labelledby="ai-ops-schedule-title">
            <div class="ai-ops-card-icon"><i class="dripicons-clock" aria-hidden="true"></i></div>
            <h2 id="ai-ops-schedule-title">Scheduled Work</h2>
            <p>Opted-in low-stock checks and purchase-order preparation.</p>
            <strong class="ai-ops-metric">{{ $rules->count() }}</strong>
            <span class="ai-ops-note">{{ $scheduling ? 'Your active schedules' : 'Scheduling is disabled' }}</span>
        </section>
        <section class="ai-ops-card" aria-labelledby="ai-ops-copilot-title">
            <div class="ai-ops-card-icon"><i class="dripicons-conversation" aria-hidden="true"></i></div>
            <h2 id="ai-ops-copilot-title">Ask Zolo Copilot</h2>
            <p>Ask questions, attach invoices or prepare transactions through conversation.</p>
            <a href="{{ route('ai.workspace') }}">Open Copilot <span aria-hidden="true">&rarr;</span></a>
        </section>
    </div>

    <div class="ai-ops-lower">
        <section class="ai-ops-panel" id="activity" aria-labelledby="ai-ops-activity-title">
            <h2 id="ai-ops-activity-title">Recent governed activity</h2>
            <p>Only actions accessible to you in the current company, branch and financial year appear here.</p>
            @forelse($recent as $action)
                <div class="ai-ops-activity">
                    <div>
                        <strong>{{ str_replace(['.', '_'], ' ', $action->action_key) }}</strong>
                        <small>{{ $action->risk_tier }} &middot; {{ $action->updated_at }}</small>
                    </div>
                    <span class="ai-ops-state">{{ str_replace('_', ' ', $action->status) }}</span>
                </div>
            @empty
                <p class="ai-ops-empty">No governed actions to show yet.</p>
            @endforelse
        </section>
        <section class="ai-ops-panel" aria-labelledby="ai-ops-rules-title">
            <h2 id="ai-ops-rules-title">Your active schedules</h2>
            <p>These checks prepare drafts only. They do not post an ERP transaction automatically.</p>
            @forelse($rules as $rule)
                <div class="ai-ops-activity">
                    <div>
                        <strong>{{ $rule->name }}</strong>
                        <small>Next: {{ $rule->next_run_at }} &middot; {{ $rule->prepare_purchase_order ? 'Draft PO enabled' : 'Alerts only' }}</small>
                    </div>
                </div>
            @empty
                <p class="ai-ops-empty">No active schedules to show.</p>
            @endforelse
            <div class="ai-ops-note">Autonomous posting and per-action autonomy policies are not enabled by this UI.</div>
        </section>
    </div>
</main>
@endsection
