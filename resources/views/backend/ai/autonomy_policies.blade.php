@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops py-3" aria-labelledby="autonomy-heading">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / AI Operations</span>
            <h1 id="autonomy-heading">Autonomy policy preferences</h1>
            <p>Configure a requested level for each action. No policy on this screen enables automatic ERP posting.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('ai.operations') }}">Back to AI Operations</a>
    </header>
    <div class="alert alert-info" role="status">
        <strong>Human approval is still mandatory.</strong>
        These preferences are stored for future policy-gated releases. The existing signed-approval executor is unchanged.
        Financial and restricted actions cannot request an automatic level.
    </div>
    @if(session('message'))
        <div class="alert alert-success" role="status">{{ session('message') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Policy not saved.</strong>
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    <section class="ai-ops-panel">
        <h2>Action policies</h2>
        <p>Each action is configured independently, and saved with a versioned audit event.</p>
        @foreach($definitions as $definition)
            @php
                $key = $definition['key'];
                $policy = $stored[$key] ?? null;
                $level = $policy['requested_level'] ?? 'draft';
                $amount = isset($policy['max_amount_minor']) ? (int) ($policy['max_amount_minor'] / 100) : 0;
                $daily = $policy['daily_action_limit'] ?? 1;
                $version = $policy['version'] ?? 0;
            @endphp
            <details class="border rounded mb-2 p-3">
                <summary class="d-flex justify-content-between align-items-center" style="cursor:pointer">
                    <span><strong>{{ $key }}</strong><span class="badge badge-light ml-2">{{ $definition['risk_tier'] }}</span></span>
                    <span class="text-muted small">Requested: {{ str_replace('_', ' ', $level) }} · v{{ $version }}</span>
                </summary>
                <form method="POST" action="{{ route('ai.autonomy.store', ['actionKey' => $key]) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="expected_version" value="{{ $version }}">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="level-{{ $loop->index }}">Requested level</label>
                            <select name="requested_level" id="level-{{ $loop->index }}" class="form-control">
                                @foreach($definition['levels'] as $available)
                                    <option value="{{ $available }}" @selected($level === $available)>{{ ucwords(str_replace('_', ' ', $available)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="amount-{{ $loop->index }}">Limit per action (whole ₹)</label>
                            <input id="amount-{{ $loop->index }}" type="number" min="0" max="10000000" step="1" name="max_amount_rupees" value="{{ $amount }}" class="form-control" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="daily-{{ $loop->index }}">Daily action limit</label>
                            <input id="daily-{{ $loop->index }}" type="number" min="1" max="100" step="1" name="daily_action_limit" value="{{ $daily }}" class="form-control" required>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm" type="submit">Save preference</button>
                    <small class="text-muted ml-2">No change to current approval requirements</small>
                </form>
            </details>
        @endforeach
    </section>
</main>
@endsection
