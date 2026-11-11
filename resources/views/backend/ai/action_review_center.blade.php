@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ asset('css/pages/ai-workspace.css') }}">
@endpush

@section('content')
<div class="container-fluid py-3 ai-review-desk">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1 font-weight-bold">
                <i class="dripicons-checklist text-primary mr-2"></i>AI Action Review Center
            </h4>
            <p class="text-muted mb-0 small">
                Enterprise Governance & Operational Approval Desk for AI Action Proposals.
                Company {{ $companyId }} · Branch {{ $branchId }} · Financial Year {{ $financialYearId }}
            </p>
        </div>
        <div>
            <a href="{{ route('ai.workspace') }}" class="btn btn-outline-primary btn-sm">
                <i class="dripicons-message mr-1"></i>Open AI Workspace
            </a>
        </div>
    </div>

    @if (!$enabled)
        <div class="alert alert-warning" role="alert">
            <i class="dripicons-warning mr-1"></i>
            <strong>AI Action Foundation Disabled:</strong> Action proposals and operational execution are currently disabled for this company.
        </div>
    @else
        {{-- KPI Metrics Row --}}
        <div class="row mb-3">
            <div class="col-md-3 col-sm-6 mb-2">
                <div class="card ai-review-kpi-card p-3 shadow-none">
                    <div class="text-muted small font-weight-bold text-uppercase">Pending Approval</div>
                    <div class="d-flex align-items-baseline justify-content-between mt-2">
                        <h3 class="mb-0 font-weight-bold text-warning">{{ $counts->pending_count }}</h3>
                        <span class="badge badge-warning px-2 py-1"><i class="dripicons-hourglass mr-1"></i>Awaiting Sign-off</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-2">
                <div class="card ai-review-kpi-card p-3 shadow-none">
                    <div class="text-muted small font-weight-bold text-uppercase">Completed & Executed</div>
                    <div class="d-flex align-items-baseline justify-content-between mt-2">
                        <h3 class="mb-0 font-weight-bold text-success">{{ $counts->completed_count }}</h3>
                        <span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Committed</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-2">
                <div class="card ai-review-kpi-card p-3 shadow-none">
                    <div class="text-muted small font-weight-bold text-uppercase">Cancelled / Rejected</div>
                    <div class="d-flex align-items-baseline justify-content-between mt-2">
                        <h3 class="mb-0 font-weight-bold text-secondary">{{ $counts->cancelled_count }}</h3>
                        <span class="badge badge-secondary px-2 py-1"><i class="dripicons-cross mr-1"></i>Dismissed</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-2">
                <div class="card ai-review-kpi-card p-3 shadow-none">
                    <div class="text-muted small font-weight-bold text-uppercase">Total Governed Actions</div>
                    <div class="d-flex align-items-baseline justify-content-between mt-2">
                        <h3 class="mb-0 font-weight-bold text-dark">{{ $counts->total }}</h3>
                        <span class="badge badge-light px-2 py-1"><i class="dripicons-shield mr-1"></i>100% Audited</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Toolbar: Filters & Scope --}}
        <div class="card mb-3 shadow-none border">
            <div class="card-body p-2 d-flex flex-wrap justify-content-between align-items-center">
                {{-- Status Tabs --}}
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 {{ $statusFilter === 'all' ? 'active' : '' }}" href="{{ route('ai.actions.review', ['status' => 'all', 'scope' => $scope]) }}">
                            All <span class="badge badge-light ml-1">{{ $counts->total }}</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 {{ $statusFilter === 'pending' ? 'active' : '' }}" href="{{ route('ai.actions.review', ['status' => 'pending', 'scope' => $scope]) }}">
                            Pending <span class="badge badge-light ml-1">{{ $counts->pending_count }}</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 {{ $statusFilter === 'completed' ? 'active' : '' }}" href="{{ route('ai.actions.review', ['status' => 'completed', 'scope' => $scope]) }}">
                            Completed <span class="badge badge-light ml-1">{{ $counts->completed_count }}</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 {{ $statusFilter === 'cancelled' ? 'active' : '' }}" href="{{ route('ai.actions.review', ['status' => 'cancelled', 'scope' => $scope]) }}">
                            Cancelled <span class="badge badge-light ml-1">{{ $counts->cancelled_count }}</span>
                        </a>
                    </li>
                </ul>

                {{-- Scope Switcher --}}
                @if ($canApprove)
                    <div class="btn-group btn-group-sm mt-2 mt-md-0" role="group">
                        <a href="{{ route('ai.actions.review', ['status' => $statusFilter, 'scope' => 'all']) }}" class="btn {{ $scope === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}">
                            <i class="dripicons-view-list mr-1"></i>All Company Proposals
                        </a>
                        <a href="{{ route('ai.actions.review', ['status' => $statusFilter, 'scope' => 'mine']) }}" class="btn {{ $scope === 'mine' ? 'btn-primary' : 'btn-outline-secondary' }}">
                            <i class="dripicons-user mr-1"></i>My Proposals Only
                        </a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Proposals Queue --}}
        @forelse ($proposals as $proposal)
            <div class="card mb-3 shadow-none border ai-review-card" id="proposal-card-{{ $proposal->id }}">
                <div class="card-header bg-white py-2 px-3 d-flex flex-wrap justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <span class="font-weight-bold text-dark mr-2">
                            <i class="dripicons-cart text-primary mr-1"></i>{{ $proposal->action_title }}
                        </span>
                        <span class="badge badge-light border text-muted mr-2">v{{ $proposal->version }}</span>
                        <span class="badge badge-info mr-2">{{ $proposal->risk_tier }}</span>
                        <small class="text-muted">ID: {{ substr($proposal->id, 0, 8) }}...</small>
                    </div>
                    <div>
                        @if ($proposal->status === 'pending')
                            <span class="badge ai-review-badge-pending px-2 py-1" id="badge-status-{{ $proposal->id }}">
                                <i class="dripicons-hourglass mr-1"></i>Awaiting Approval
                            </span>
                        @elseif ($proposal->status === 'completed')
                            <span class="badge ai-review-badge-completed px-2 py-1">
                                <i class="dripicons-checkmark mr-1"></i>Completed
                            </span>
                        @else
                            <span class="badge ai-review-badge-cancelled px-2 py-1">
                                <i class="dripicons-cross mr-1"></i>{{ ucfirst($proposal->status) }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="row">
                                <div class="col-sm-6 mb-2">
                                    <div class="text-muted small">Supplier:</div>
                                    <div class="font-weight-bold text-dark">
                                        {{ $proposal->supplier['name'] ?? 'Supplier #' . ($proposal->preview['supplier_id'] ?? 'N/A') }}
                                        @if (!empty($proposal->supplier['gst_number']))
                                            <span class="small text-muted">({{ $proposal->supplier['gst_number'] }})</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="text-muted small">Delivery Warehouse:</div>
                                    <div class="font-weight-bold text-dark">
                                        {{ $proposal->warehouse['name'] ?? 'Warehouse #' . ($proposal->preview['warehouse_id'] ?? 'N/A') }}
                                    </div>
                                </div>
                                <div class="col-sm-6 mb-1">
                                    <div class="text-muted small">Proposed By:</div>
                                    <div>{{ $proposal->actor_name }} · <small class="text-muted">{{ $proposal->created_at }}</small></div>
                                </div>
                                <div class="col-sm-6 mb-1">
                                    <div class="text-muted small">Order Date:</div>
                                    <div>{{ $proposal->date ?? 'Today' }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-right border-left mt-2 mt-md-0">
                            <div class="text-muted small">Estimated Grand Total:</div>
                            <h4 class="font-weight-bold text-dark mb-1">
                                {{ $currencySymbol }}{{ $proposal->totals['grand_total'] ?? '0.00' }}
                            </h4>
                            <div class="small text-muted mb-2">
                                Subtotal: {{ $currencySymbol }}{{ $proposal->totals['subtotal'] ?? '0.00' }} | Tax: {{ $currencySymbol }}{{ $proposal->totals['tax_total'] ?? '0.00' }}
                            </div>

                            <button class="btn btn-outline-secondary btn-sm py-1 px-2" type="button" data-toggle="collapse" data-target="#collapse-details-{{ $proposal->id }}">
                                <i class="dripicons-preview mr-1"></i>Inspect Items & Hash
                            </button>
                        </div>
                    </div>

                    {{-- Collapsible Details: Lines, Taxes, and Provenance --}}
                    <div class="collapse mt-3 pt-3 border-top" id="collapse-details-{{ $proposal->id }}">
                        @if (!empty($proposal->items))
                            <div class="table-responsive mb-2">
                                <table class="table table-sm table-hover mb-0 ai-proposal-items-table border">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Product</th>
                                            <th>Code</th>
                                            <th class="text-right">Qty</th>
                                            <th class="text-right">Unit Cost</th>
                                            <th class="text-right">Discount</th>
                                            <th class="text-right">Tax</th>
                                            <th class="text-right">Line Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($proposal->items as $item)
                                            <tr>
                                                <td class="font-weight-bold">{{ $item['product_name'] ?? 'Product #' . $item['product_id'] }}</td>
                                                <td><span class="badge badge-light">{{ $item['product_code'] ?? 'SKU' }}</span></td>
                                                <td class="text-right">{{ $item['qty'] }}</td>
                                                <td class="text-right">{{ $currencySymbol }}{{ $item['net_unit_cost'] }}</td>
                                                <td class="text-right">{{ $currencySymbol }}{{ $item['discount'] ?? '0.00' }}</td>
                                                <td class="text-right">{{ $currencySymbol }}{{ $item['tax_amount'] ?? '0.00' }}</td>
                                                <td class="text-right font-weight-bold">{{ $currencySymbol }}{{ $item['line_total'] ?? '0.00' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- Accounting & Ledger Effects Guarantee --}}
                        <div class="alert alert-light border small text-muted p-2 mb-2">
                            <strong><i class="dripicons-information mr-1"></i>Operational Integrity Guarantees:</strong>
                            <ul class="mb-0 pl-3">
                                <li><strong>General Ledger:</strong> Zero financial entries created upon order placement. Debits and credits remain untouched until purchase billing.</li>
                                <li><strong>Inventory:</strong> Zero physical stock additions until formal goods receipt. Quantities are tracked as open order commitments.</li>
                                <li><strong>Order Register:</strong> Creates Purchase record with status 4 (Ordered) and registers line allocations in <code>commercial_order_items</code>.</li>
                            </ul>
                        </div>

                        {{-- Cryptographic Hashes --}}
                        <div class="row ai-hash-code">
                            <div class="col-md-6 text-truncate" title="Payload Hash: {{ $proposal->payload_hash }}">
                                <strong>Payload Hash:</strong> {{ $proposal->payload_hash }}
                            </div>
                            <div class="col-md-6 text-truncate text-md-right" title="Source State Hash: {{ $proposal->source_state_hash }}">
                                <strong>Source State Hash:</strong> {{ $proposal->source_state_hash }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Action Footer --}}
                <div class="card-footer bg-light py-2 px-3 d-flex justify-content-between align-items-center" id="action-footer-{{ $proposal->id }}">
                    <div class="small text-muted">
                        Expires at: {{ $proposal->expires_at }}
                    </div>
                    <div class="d-flex align-items-center">
                        @if ($proposal->status === 'pending')
                            @if ($canApprove)
                                <button type="button" class="btn btn-sm btn-success mr-2 py-1 px-3 btn-approve-proposal" data-id="{{ $proposal->id }}" data-version="{{ $proposal->version }}">
                                    <i class="dripicons-checkmark mr-1"></i>Approve & Place Order
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-3 btn-reject-proposal" data-id="{{ $proposal->id }}" data-version="{{ $proposal->version }}">
                                <i class="dripicons-cross mr-1"></i>Reject
                            </button>
                        @elseif ($proposal->status === 'completed')
                            <div class="d-flex align-items-center">
                                <span class="badge badge-success p-2 mr-2">
                                    <i class="dripicons-checkmark mr-1"></i>Order Placed: PO #{{ $proposal->reference_no ?? $proposal->id }}
                                </span>
                                <a href="{{ route('purchases.index') }}" class="btn btn-sm btn-outline-primary py-1 px-2">
                                    <i class="dripicons-document mr-1"></i>View in Purchase Register
                                </a>
                            </div>
                        @else
                            <span class="badge badge-secondary p-2">
                                <i class="dripicons-cross mr-1"></i>Proposal {{ ucfirst($proposal->status) }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card p-5 text-center shadow-none border">
                <i class="dripicons-inbox display-4 text-muted mb-3"></i>
                <h5>No proposals found</h5>
                <p class="text-muted small">There are currently no proposals matching the selected filter criteria.</p>
                <div class="mt-2">
                    <a href="{{ route('ai.workspace') }}" class="btn btn-primary btn-sm">
                        <i class="dripicons-message mr-1"></i>Create a proposal in AI Workspace
                    </a>
                </div>
            </div>
        @endforelse
    @endif
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function () {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    const headers = {
        'X-CSRF-TOKEN': csrfToken,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    };

    // Approve Proposal
    $('.btn-approve-proposal').on('click', function () {
        const btn = $(this);
        const proposalId = btn.data('id');
        const expectedVersion = btn.data('version') || 1;

        if (!confirm('Are you sure you want to approve this purchase order and record it in the order register?')) {
            return;
        }

        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i>Approving...');
        const footer = $('#action-footer-' + proposalId);
        const rejectBtn = footer.find('.btn-reject-proposal');
        rejectBtn.prop('disabled', true);

        $.ajax({
            url: '/api/v1/ai/proposals/' + proposalId + '/approve',
            method: 'POST',
            headers: headers,
            data: JSON.stringify({ expected_version: expectedVersion }),
            success: function (res) {
                const poRef = res.execution?.receipt?.details?.reference_no || proposalId;
                footer.html('<div class="small text-muted">Executed by you just now</div><div class="d-flex align-items-center"><span class="badge badge-success p-2 mr-2"><i class="dripicons-checkmark mr-1"></i>Order Placed: PO #' + poRef + '</span><a href="/purchases" class="btn btn-sm btn-outline-primary py-1 px-2"><i class="dripicons-document mr-1"></i>View in Purchase Register</a></div>');
                $('#badge-status-' + proposalId).removeClass('ai-review-badge-pending').addClass('ai-review-badge-completed').html('<i class="dripicons-checkmark mr-1"></i>Completed');
            },
            error: function (xhr) {
                btn.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Approve & Place Order');
                rejectBtn.prop('disabled', false);
                const errMsg = xhr.responseJSON?.error_message || xhr.responseJSON?.message || 'Approval failed.';
                alert('Approval Failed: ' + errMsg);
            }
        });
    });

    // Reject Proposal
    $('.btn-reject-proposal').on('click', function () {
        const btn = $(this);
        const proposalId = btn.data('id');
        const expectedVersion = btn.data('version') || 1;

        if (!confirm('Are you sure you want to reject and cancel this proposal?')) {
            return;
        }

        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i>Rejecting...');
        const footer = $('#action-footer-' + proposalId);
        const approveBtn = footer.find('.btn-approve-proposal');
        approveBtn.prop('disabled', true);

        $.ajax({
            url: '/api/v1/ai/proposals/' + proposalId + '/reject',
            method: 'POST',
            headers: headers,
            data: JSON.stringify({ expected_version: expectedVersion }),
            success: function (res) {
                footer.html('<div class="small text-muted">Proposal dismissed</div><span class="badge badge-secondary p-2"><i class="dripicons-cross mr-1"></i>Cancelled</span>');
                $('#badge-status-' + proposalId).removeClass('ai-review-badge-pending').addClass('ai-review-badge-cancelled').html('<i class="dripicons-cross mr-1"></i>Cancelled');
            },
            error: function (xhr) {
                btn.prop('disabled', false).html('<i class="dripicons-cross mr-1"></i>Reject');
                approveBtn.prop('disabled', false);
                const errMsg = xhr.responseJSON?.error_message || xhr.responseJSON?.message || 'Rejection failed.';
                alert('Rejection Failed: ' + errMsg);
            }
        });
    });
});
</script>
@endpush
