@extends('backend.layout.main')

@section('content')


<section class="tb-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar d-print-none">
        <div class="comm-title-group">
            <h1><i class="dripicons-scale text-primary"></i> Trial Balance Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> General Ledger Ending Balances Verification</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('accounting.voucher.entry') }}"><i class="dripicons-pencil"></i> Voucher Entry · F9</a></li>
            <li><a class="nav-link" href="{{ route('accounting.journal-entries') }}"><i class="dripicons-align-justify"></i> Journal Entries</a></li>
            <li><a class="nav-link" href="{{ route('accounting.coa') }}"><i class="dripicons-network"></i> Chart of Accounts</a></li>
            <li><a class="nav-link" href="{{ route('accounting.general-ledger') }}"><i class="dripicons-view-thumb"></i> General Ledger</a></li>
            <li><a class="nav-link active" href="{{ route('accounting.trial-balance') }}"><i class="dripicons-scale"></i> Trial Balance</a></li>
            <li><a class="nav-link" href="{{ route('accounting.profit-loss') }}"><i class="dripicons-graph-line"></i> P&amp;L</a></li>
            <li><a class="nav-link" href="{{ route('accounting.balance-sheet') }}"><i class="dripicons-document"></i> Balance Sheet</a></li>
        </ul>
        <div class="comm-actions">
            <button type="button" class="btn btn-outline-secondary btn-action-pill" data-erp-print>
                <i class="dripicons-print"></i> Print / PDF
            </button>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="desk-filter-card d-print-none">
        <form action="{{ route('accounting.trial-balance') }}" method="GET" class="m-0">
            <div class="row align-items-end u-m-0-6px">
                <div class="col-md-4 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm u-h-32px u-fs-12px" value="{{ $startDate }}">
                </div>
                <div class="col-md-4 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm u-h-32px u-fs-12px" value="{{ $endDate }}">
                </div>
                <div class="col-md-4 d-flex u-p-0-6px">
                    @include('backend.accounting.partials.branch_scope')
                    <button type="submit" class="btn btn-sm btn-primary flex-grow-1 mr-2 u-h-32px u-fs-12px u-fw-600">
                        <i class="dripicons-search"></i> Generate Report
                    </button>
                    <a href="{{ route('accounting.trial-balance') }}" class="btn btn-sm btn-outline-secondary u-h-32px u-fs-12px u-d-inline-flex u-items-center">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Validation Banner -->
    @if($report['is_balanced'])
        <div class="alert alert-success d-flex align-items-center mb-3 border-0 shadow-sm u-bg-success-soft u-c-success u-r-8px" role="alert">
            <i class="dripicons-checkmark font-weight-bold mr-2 u-fs-18px"></i>
            <div>
                <strong>Balanced Trial Balance:</strong> Total Debits (₹ {{ number_format($report['total_debit'], 2) }}) exactly equal Total Credits (₹ {{ number_format($report['total_credit'], 2) }}).
            </div>
        </div>
    @else
        <div class="alert alert-danger d-flex align-items-center mb-3 border-0 shadow-sm u-bg-danger-soft u-c-danger u-r-8px" role="alert">
            <i class="dripicons-warning font-weight-bold mr-2 u-fs-18px"></i>
            <div>
                <strong>Trial Balance Out of Balance:</strong> Difference of ₹ {{ number_format($report['difference'], 2) }} detected between debits and credits.
            </div>
        </div>
    @endif

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL DEBITS</div>
                <div class="kpi-number u-c-info">₹ {{ number_format($report['total_debit'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-arrow-up u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL CREDITS</div>
                <div class="kpi-number u-c-success">₹ {{ number_format($report['total_credit'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-arrow-down u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">DIFFERENCE</div>
                <div class="kpi-number {{ $report['is_balanced'] ? 'u-c-success' : 'u-c-danger' }}">
                    ₹ {{ number_format($report['difference'], 2) }}
                </div>
            </div>
            <div class="z-icon-tile {{ $report['is_balanced'] ? 'z-tone-success' : 'z-tone-danger' }}">
                <i class="{{ $report['is_balanced'] ? 'dripicons-checkmark' : 'dripicons-warning' }} u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">INTEGRITY STATUS</div>
                <div class="kpi-number u-fs-14px u-fw-600 {{ $report['is_balanced'] ? 'u-c-success' : 'u-c-danger' }}">
                    {{ $report['is_balanced'] ? 'Fully Balanced' : 'Out of Balance' }}
                </div>
            </div>
            <div class="z-icon-tile z-tone-neutral">
                <i class="dripicons-scale u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Trial Balance Table Container -->
    <div class="desk-card-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <h5 class="font-weight-bold mb-0 u-c-text u-fs-15px">{{ $general_setting->site_title ?? 'zoloERP' }} &bull; Trial Balance Statement</h5>
                @if($startDate || $endDate)
                    <small class="text-muted">Period: {{ $startDate ?: 'Inception' }} to {{ $endDate ?: 'Present' }}</small>
                @endif
            </div>
            <span class="badge {{ $report['is_balanced'] ? 'badge-success' : 'badge-danger' }} u-fs-11px u-p-4px-8px {{ $report['is_balanced'] ? 'u-bg-success-soft u-c-success u-b-1px-solid-success-border' : 'u-bg-danger-soft u-c-danger' }}">
                {{ $report['is_balanced'] ? 'Balanced Statement' : 'Discrepancy Detected' }}
            </span>
        </div>
        <div class="table-responsive">
            <table class="table tb-grid-table mb-0">
                <thead>
                    <tr>
                        <th class="u-w-15pct">Account Code</th>
                        <th>Account Name</th>
                        <th class="u-w-15pct">Type</th>
                        <th class="text-right u-w-20pct">Debit Balance</th>
                        <th class="text-right u-w-20pct">Credit Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['accounts'] as $row)
                        <tr>
                            <td class="font-monospace font-weight-bold text-primary">{{ $row['code'] }}</td>
                            <td class="font-weight-600">
                                <a href="{{ route('accounting.general-ledger', ['account_id' => $row['id']]) }}" class="text-dark">
                                    {{ $row['name'] }}
                                </a>
                            </td>
                            <td>
                                <span class="badge badge-pill 
                                    @if($row['type'] == 'asset') badge-soft-primary
                                    @elseif($row['type'] == 'liability') badge-soft-danger
                                    @elseif($row['type'] == 'equity') badge-soft-info
                                    @elseif($row['type'] == 'revenue') badge-soft-success
                                    @else badge-soft-warning @endif u-p-3px-7px u-fs-10_5px"
                                   >
                                    {{ ucfirst($row['type']) }}
                                </span>
                            </td>
                            <td class="text-right font-weight-bold font-monospace">
                                {{ $row['debit'] > 0 ? '₹ ' . number_format($row['debit'], 2) : '-' }}
                            </td>
                            <td class="text-right font-weight-bold font-monospace">
                                {{ $row['credit'] > 0 ? '₹ ' . number_format($row['credit'], 2) : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No account balances found for the selected period.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="u-bg-surface-3 u-fw-700 u-c-text">
                    <tr>
                        <td colspan="3" class="text-right u-fs-11_5px">TOTALS:</td>
                        <td class="text-right font-monospace u-c-info">₹ {{ number_format($report['total_debit'], 2) }}</td>
                        <td class="text-right font-monospace u-c-success">₹ {{ number_format($report['total_credit'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</section>

@endsection

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/accounting-trial-balance.css') }}">
@endpush
