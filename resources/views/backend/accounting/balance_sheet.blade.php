@extends('backend.layout.main')

@section('content')


<section class="bs-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar d-print-none">
        <div class="comm-title-group">
            <h1><i class="dripicons-document text-primary"></i> Balance Sheet Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Financial Position (Assets = Liabilities + Equity)</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('accounting.voucher.entry') }}"><i class="dripicons-pencil"></i> Voucher Entry · F9</a></li>
            <li><a class="nav-link" href="{{ route('accounting.journal-entries') }}"><i class="dripicons-align-justify"></i> Journal Entries</a></li>
            <li><a class="nav-link" href="{{ route('accounting.coa') }}"><i class="dripicons-network"></i> Chart of Accounts</a></li>
            <li><a class="nav-link" href="{{ route('accounting.general-ledger') }}"><i class="dripicons-view-thumb"></i> General Ledger</a></li>
            <li><a class="nav-link" href="{{ route('accounting.trial-balance') }}"><i class="dripicons-scale"></i> Trial Balance</a></li>
            <li><a class="nav-link" href="{{ route('accounting.profit-loss') }}"><i class="dripicons-graph-line"></i> P&amp;L</a></li>
            <li><a class="nav-link active" href="{{ route('accounting.balance-sheet') }}"><i class="dripicons-document"></i> Balance Sheet</a></li>
        </ul>
        <div class="comm-actions">
            <button type="button" class="btn btn-outline-secondary btn-action-pill" data-erp-print>
                <i class="dripicons-print"></i> Print / PDF
            </button>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="desk-filter-card d-print-none">
        <form action="{{ route('accounting.balance-sheet') }}" method="GET" class="m-0">
            <div class="row align-items-end u-m-0-6px">
                <div class="col-md-6 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">As of Date</label>
                    <input type="date" name="as_of_date" class="form-control form-control-sm u-h-32px u-fs-12px" value="{{ $asOfDate }}">
                </div>
                <div class="col-md-6 d-flex u-p-0-6px">
                    @include('backend.accounting.partials.branch_scope')
                    <button type="submit" class="btn btn-sm btn-primary flex-grow-1 mr-2 u-h-32px u-fs-12px u-fw-600">
                        <i class="dripicons-search"></i> Update Statement
                    </button>
                    <a href="{{ route('accounting.balance-sheet') }}" class="btn btn-sm btn-outline-secondary u-h-32px u-fs-12px u-d-inline-flex u-items-center">Today</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Equation Check Banner -->
    @if($report['is_balanced'])
        <div class="alert alert-success d-flex align-items-center mb-3 border-0 shadow-sm u-bg-success-soft u-c-success u-r-8px" role="alert">
            <i class="dripicons-checkmark font-weight-bold mr-2 u-fs-18px"></i>
            <div>
                <strong>Accounting Equation Balanced:</strong> Total Assets (₹ {{ number_format($report['total_assets'], 2) }}) = Total Liabilities &amp; Equity (₹ {{ number_format($report['total_liabilities_and_equity'], 2) }}).
            </div>
        </div>
    @else
        <div class="alert alert-danger d-flex align-items-center mb-3 border-0 shadow-sm u-bg-danger-soft u-c-danger u-r-8px" role="alert">
            <i class="dripicons-warning font-weight-bold mr-2 u-fs-18px"></i>
            <div>
                <strong>Equation Discrepancy:</strong> Difference of ₹ {{ number_format($report['difference'], 2) }} between Total Assets and Total Liabilities &amp; Equity.
            </div>
        </div>
    @endif

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL ASSETS</div>
                <div class="kpi-number u-c-info">₹ {{ number_format($report['total_assets'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL LIABILITIES</div>
                <div class="kpi-number u-c-danger">₹ {{ number_format($report['total_liabilities'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-warning u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL EQUITY</div>
                <div class="kpi-number u-c-primary">₹ {{ number_format($report['total_equity'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-graph-bar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">EQUATION STATUS</div>
                <div class="kpi-number u-fs-14px u-fw-600 {{ $report['is_balanced'] ? 'u-c-success' : 'u-c-danger' }}">
                    {{ $report['is_balanced'] ? 'A = L + E (Balanced)' : 'Diff: ₹ ' . number_format($report['difference'], 2) }}
                </div>
            </div>
            <div class="z-icon-tile {{ $report['is_balanced'] ? 'z-tone-success' : 'z-tone-danger' }}">
                <i class="{{ $report['is_balanced'] ? 'dripicons-checkmark' : 'dripicons-warning' }} u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Two Column Balance Sheet Layout -->
    <div class="row">
        <!-- ASSETS COLUMN -->
        <div class="col-md-6 mb-3">
            <div class="desk-card-wrapper h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <h5 class="font-weight-bold mb-0 u-c-info u-fs-15px">ASSETS</h5>
                        <span class="badge badge-primary u-fs-10px">Resources Owned</span>
                    </div>
                    <table class="bs-table mb-4">
                        <tbody>
                            @forelse($report['assets'] as $asset)
                                <tr>
                                    <td class="u-w-70pct">
                                        <span class="font-monospace text-muted mr-1">{{ $asset['code'] }}</span>
                                        <span class="font-weight-600">{{ $asset['name'] }}</span>
                                        <small class="text-muted d-block text-capitalize">{{ str_replace('_', ' ', $asset['sub_type']) }}</small>
                                    </td>
                                    <td class="text-right font-weight-bold font-monospace align-middle u-w-30pct">
                                        ₹ {{ number_format($asset['balance'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted italic py-3">No assets recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3 rounded d-flex justify-content-between align-items-center u-bg-surface-3 u-bt-2px-solid-border-strong">
                    <h6 class="font-weight-bold mb-0 u-c-text u-fs-13px">TOTAL ASSETS</h6>
                    <h5 class="font-weight-bold font-monospace mb-0 u-c-info u-fs-17px">₹ {{ number_format($report['total_assets'], 2) }}</h5>
                </div>
            </div>
        </div>

        <!-- LIABILITIES & EQUITY COLUMN -->
        <div class="col-md-6 mb-3">
            <div class="desk-card-wrapper h-100 d-flex flex-column justify-content-between">
                <div>
                    <!-- Liabilities Section -->
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <h5 class="font-weight-bold mb-0 u-c-danger u-fs-15px">LIABILITIES</h5>
                        <span class="badge badge-danger u-fs-10px">Obligations Owed</span>
                    </div>
                    <table class="bs-table mb-3">
                        <tbody>
                            @forelse($report['liabilities'] as $liab)
                                <tr>
                                    <td class="u-w-70pct">
                                        <span class="font-monospace text-muted mr-1">{{ $liab['code'] }}</span>
                                        <span class="font-weight-600">{{ $liab['name'] }}</span>
                                        <small class="text-muted d-block text-capitalize">{{ str_replace('_', ' ', $liab['sub_type']) }}</small>
                                    </td>
                                    <td class="text-right font-weight-bold font-monospace align-middle u-w-30pct">
                                        ₹ {{ number_format($liab['balance'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted italic py-2">No liabilities recorded.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="font-weight-bold">Total Liabilities:</td>
                                <td class="text-right text-danger font-monospace font-weight-bold">₹ {{ number_format($report['total_liabilities'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Equity Section -->
                    <div class="d-flex justify-content-between align-items-center mt-4 mb-3 pb-2 border-bottom">
                        <h5 class="font-weight-bold mb-0 u-c-primary u-fs-15px">EQUITY</h5>
                        <span class="badge badge-info u-fs-10px">Owner Capital &amp; Earnings</span>
                    </div>
                    <table class="bs-table mb-2">
                        <tbody>
                            @forelse($report['equity'] as $eq)
                                <tr>
                                    <td class="u-w-70pct">
                                        <span class="font-monospace text-muted mr-1">{{ $eq['code'] }}</span>
                                        <span class="font-weight-600">{{ $eq['name'] }}</span>
                                    </td>
                                    <td class="text-right font-weight-bold font-monospace align-middle u-w-30pct">
                                        ₹ {{ number_format($eq['balance'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted italic py-2">No equity recorded.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="font-weight-bold">Total Equity:</td>
                                <td class="text-right text-info font-monospace font-weight-bold">₹ {{ number_format($report['total_equity'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="p-3 rounded d-flex justify-content-between align-items-center mt-3 u-bg-surface-3 u-bt-2px-solid-border-strong">
                    <h6 class="font-weight-bold mb-0 u-c-text u-fs-13px">TOTAL LIABILITIES &amp; EQUITY</h6>
                    <h5 class="font-weight-bold font-monospace mb-0 u-c-text u-fs-17px">₹ {{ number_format($report['total_liabilities_and_equity'], 2) }}</h5>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/accounting-balance-sheet.css') }}">
@endpush
