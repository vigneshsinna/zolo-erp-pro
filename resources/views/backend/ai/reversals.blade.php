@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops py-3" aria-labelledby="ai-reversals-heading">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / AI Operations</span>
            <h1 id="ai-reversals-heading">Reversal review requests</h1>
            <p>Request an accounting and stock reconciliation review. This screen never undoes an ERP transaction.</p>
        </div>
        <a href="{{ route('ai.operations') }}" class="btn btn-outline-secondary">AI Operations</a>
    </header>
    <div class="alert alert-warning" role="status">
        <strong>No automatic reversal.</strong>
        Reversing a bill or stock transaction requires a domain-specific compensating workflow. A request does not delete a bill, journal or stock movement.
    </div>
    @if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    <section class="ai-ops-panel mb-3">
        <h2>Request a manual reversal review</h2>
        <form action="{{ route('ai.reversals.store') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="reversal-execution">Completed execution ID</label>
                <input type="text" name="execution_id" id="reversal-execution" class="form-control"
                    maxlength="36" required placeholder="UUID from a completed AI action" value="{{ old('execution_id') }}">
            </div>
            <div class="form-group">
                <label for="reversal-reason">Business reason</label>
                <textarea id="reversal-reason" name="reason" class="form-control" rows="3" minlength="12" maxlength="500"
                    required placeholder="Describe the correction and evidence to review">{{ old('reason') }}</textarea>
            </div>
            <button type="submit" class="btn btn-outline-danger">Request manual review</button>
        </form>
    </section>
    <section class="ai-ops-panel">
        <h2>Requests in this company, branch and financial year</h2>
        @forelse($requests as $review)
            <article class="ai-ops-activity">
                <div>
                    <strong>{{ $review['execution_id'] }}</strong>
                    <small>{{ $review['reason'] }}</small>
                    <small>{{ $review['created_at'] }}</small>
                </div>
                <span class="badge badge-warning">Pending manual review</span>
            </article>
        @empty
            <p class="ai-ops-empty">No reversal review requests have been recorded.</p>
        @endforelse
    </section>
</main>
@endsection
