@extends('backend.layout.main')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">AI Action Review Center</h4>
            <p class="text-muted mb-0">Review history and current status of your own AI action proposals.</p>
        </div>
        <a href="{{ route('ai.workspace') }}" class="btn btn-outline-primary btn-sm">Back to AI Workspace</a>
    </div>

    <div class="alert alert-info" role="status">
        <strong>Foundation phase:</strong>
        @if (!$enabled)
            Action proposals and business execution are disabled.
        @else
            Action review is read-only. Approvals and ERP posting remain unavailable until a separately reviewed business capability is enabled.
        @endif
        <div class="small mt-1">Company {{ $companyId }} · Branch {{ $branchId }} · Financial year {{ $financialYearId }}</div>
    </div>

    @if ($enabled)
        <div class="card">
            <div class="card-header bg-white"><strong>Your action proposals</strong></div>
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Version</th>
                            <th>Risk</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($proposals as $proposal)
                        <tr>
                            <td>
                                <strong>{{ $proposal->action_title }}</strong>
                                <div class="small text-muted">{{ $proposal->action_key }}</div>
                            </td>
                            <td>{{ $proposal->version }}</td>
                            <td>{{ $proposal->risk_tier }}</td>
                            <td>{{ $proposal->status }}</td>
                            <td>{{ $proposal->created_at }}</td>
                            <td>{{ $proposal->updated_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No current action proposals.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
