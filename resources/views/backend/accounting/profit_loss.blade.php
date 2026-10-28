@extends('backend.layout.main')

@section('content')


<section class="pl-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar d-print-none">
        <div class="comm-title-group">
            <h1><i class="dripicons-graph-line text-primary"></i> Profit &amp; Loss Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Income Statement: Revenue, COGS &amp; Net Margins</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('accounting.voucher.entry') }}"><i class="dripicons-pencil"></i> Voucher Entry · F9</a></li>
            <li><a class="nav-link" href="{{ route('accounting.journal-entries') }}"><i class="dripicons-align-justify"></i> Journal Entries</a></li>
            <li><a class="nav-link" href="{{ route('accounting.coa') }}"><i class="dripicons-network"></i> Chart of Accounts</a></li>
            <li><a class="nav-link" href="{{ route('accounting.general-ledger') }}"><i class="dripicons-view-thumb"></i> General Ledger</a></li>
            <li><a class="nav-link" href="{{ route('accounting.trial-balance') }}"><i class="dripicons-scale"></i> Trial Balance</a></li>
            <li><a class="nav-link active" href="{{ route('accounting.profit-loss') }}"><i class="dripicons-graph-line"></i> P&amp;L</a></li>
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
        <form action="{{ route('accounting.profit-loss') }}" method="GET" class="m-0">
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
                    <a href="{{ route('accounting.profit-loss') }}" class="btn btn-sm btn-outline-secondary u-h-32px u-fs-12px u-d-inline-flex u-items-center">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- KPI Summary Cards -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL REVENUE</div>
                <div class="kpi-number u-c-success">₹ {{ number_format($report['total_revenue'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-arrow-up u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">COST OF GOODS SOLD</div>
                <div class="kpi-number u-c-warning">₹ {{ number_format($report['total_cogs'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-cart u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">GROSS PROFIT</div>
                <div class="kpi-number u-c-info">₹ {{ number_format($report['gross_profit'], 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-graph-bar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">NET {{ $report['net_income'] >= 0 ? 'PROFIT' : 'LOSS' }}</div>
                <div class="kpi-number {{ $report['net_income'] >= 0 ? 'u-c-success' : 'u-c-danger' }}">
                    ₹ {{ number_format($report['net_income'], 2) }}
                </div>
            </div>
            <div class="z-icon-tile {{ $report['net_income'] >= 0 ? 'z-tone-success' : 'z-tone-danger' }}">
                <i class="{{ $report['net_income'] >= 0 ? 'dripicons-checkmark' : 'dripicons-warning' }} u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Detailed Statement Card -->
    <div class="desk-card-wrapper">
        <div class="text-center mb-4 pb-2 border-bottom">
            <h4 class="font-weight-bold mb-1 u-c-text">{{ $general_setting->site_title ?? 'zoloERP' }}</h4>
            <h5 class="text-muted mb-1 u-fs-15px">Statement of Profit &amp; Loss</h5>
            <small class="text-muted">Reporting Period: {{ $startDate ?: 'Inception' }} to {{ $endDate ?: 'Present' }}</small>
        </div>

        <!-- 1. Operating Revenue -->
        <div class="statement-section-title">1. Operating Revenue</div>
        <table class="statement-table">
            <tbody>
                @forelse($report['revenues'] as $rev)
                    <tr>
                        <td class="font-weight-600 u-w-70pct">
                            <span class="font-monospace text-muted mr-1">{{ $rev['code'] }}</span> {{ $rev['name'] }}
                        </td>
                        <td class="text-right font-weight-bold font-monospace u-w-30pct">
                            ₹ {{ number_format($rev['amount'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="text-muted italic py-2">No revenue recorded in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td class="font-weight-bold">Total Operating Revenue:</td>
                    <td class="text-right text-success font-monospace font-weight-bold u-fs-14px">
                        ₹ {{ number_format($report['total_revenue'], 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- 2. Cost of Goods Sold -->
        <div class="statement-section-title mt-4">2. Cost of Goods Sold (COGS)</div>
        <table class="statement-table">
            <tbody>
                @forelse($report['cogs'] as $cg)
                    <tr>
                        <td class="font-weight-600 u-w-70pct">
                            <span class="font-monospace text-muted mr-1">{{ $cg['code'] }}</span> {{ $cg['name'] }}
                        </td>
                        <td class="text-right font-weight-bold font-monospace u-w-30pct">
                            ₹ {{ number_format($cg['amount'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="text-muted italic py-2">No cost of goods sold recorded.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td class="font-weight-bold">Total Cost of Goods Sold:</td>
                    <td class="text-right text-warning font-monospace font-weight-bold u-fs-14px">
                        (₹ {{ number_format($report['total_cogs'], 2) }})
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- Gross Profit Highlight -->
        <div class="d-flex justify-content-between align-items-center p-3 mb-4 rounded u-bg-info-soft u-b-1px-solid-info-border">
            <h5 class="font-weight-bold mb-0 u-c-info u-fs-14px">GROSS PROFIT</h5>
            <h4 class="font-weight-bold font-monospace mb-0 u-c-info u-fs-18px">
                ₹ {{ number_format($report['gross_profit'], 2) }}
            </h4>
        </div>

        <!-- 3. Operating Expenses -->
        <div class="statement-section-title mt-4">3. Operating Expenses</div>
        <table class="statement-table">
            <tbody>
                @forelse($report['operating_expenses'] as $exp)
                    <tr>
                        <td class="font-weight-600 u-w-70pct">
                            <span class="font-monospace text-muted mr-1">{{ $exp['code'] }}</span> {{ $exp['name'] }}
                        </td>
                        <td class="text-right font-weight-bold font-monospace u-w-30pct">
                            ₹ {{ number_format($exp['amount'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="text-muted italic py-2">No operating expenses recorded.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td class="font-weight-bold">Total Operating Expenses:</td>
                    <td class="text-right text-danger font-monospace font-weight-bold u-fs-14px">
                        (₹ {{ number_format($report['total_operating_expenses'], 2) }})
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- Net Income Highlight -->
        <div class="d-flex justify-content-between align-items-center p-3 rounded mt-3 {{ $report['net_income'] >= 0 ? 'u-bg-success-soft u-b-1px-solid-success-border' : 'u-bg-danger-soft u-b-1px-solid-danger-border' }}">
            <div>
                <h5 class="font-weight-bold mb-0 u-fs-15px {{ $report['net_income'] >= 0 ? 'u-c-success' : 'u-c-danger' }}">
                    NET {{ $report['net_income'] >= 0 ? 'PROFIT' : 'LOSS' }} FOR PERIOD
                </h5>
                <small class="text-muted">Transferable to Balance Sheet Retained Earnings</small>
            </div>
            <h3 class="font-weight-bold font-monospace mb-0 u-fs-22px {{ $report['net_income'] >= 0 ? 'u-c-success' : 'u-c-danger' }}">
                ₹ {{ number_format($report['net_income'], 2) }}
            </h3>
        </div>
    </div>
</section>

@endsection

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/accounting-profit-loss.css') }}">
@endpush
