@extends('backend.layout.main')
@section('content')


<section class="cf-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar d-print-none">
        <div class="comm-title-group">
            <h1><i class="dripicons-pulse text-primary"></i> Statement of Cash Flows</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> GAAP Operating, Investing &amp; Financing Cash Analysis</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('accounting.voucher.entry') }}"><i class="dripicons-pencil"></i> Voucher Entry · F9</a></li>
            <li><a class="nav-link" href="{{ route('accounting.journal-entries') }}"><i class="dripicons-align-justify"></i> Journal Entries</a></li>
            <li><a class="nav-link" href="{{ route('accounting.coa') }}"><i class="dripicons-network"></i> Chart of Accounts</a></li>
            <li><a class="nav-link" href="{{ route('accounting.general-ledger') }}"><i class="dripicons-view-thumb"></i> General Ledger</a></li>
            <li><a class="nav-link" href="{{ route('accounting.trial-balance') }}"><i class="dripicons-scale"></i> Trial Balance</a></li>
            <li><a class="nav-link" href="{{ route('accounting.profit-loss') }}"><i class="dripicons-graph-line"></i> P&amp;L</a></li>
            <li><a class="nav-link" href="{{ route('accounting.balance-sheet') }}"><i class="dripicons-document"></i> Balance Sheet</a></li>
        </ul>
        <div class="comm-actions">
            <button data-erp-print class="btn btn-outline-secondary btn-action-pill">
                <i class="dripicons-print"></i> Print Statement
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="desk-filter-card d-print-none">
        <form action="{{ route('accounting.cash-flow') }}" method="GET" class="m-0">
            <div class="row align-items-end u-m-0-6px">
                <div class="col-md-4 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">From Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm u-h-32px u-fs-12px" value="{{ $startDate }}">
                </div>
                <div class="col-md-4 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">To Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm u-h-32px u-fs-12px" value="{{ $endDate }}">
                </div>
                <div class="col-md-4 u-p-0-6px">
                    <button type="submit" class="btn btn-primary btn-sm w-100 u-h-32px u-fs-12px u-fw-600">
                        <i class="dripicons-search"></i> Generate Cash Flow
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">OPERATING INFLOWS</div>
                <div class="kpi-number u-c-success">+ ₹ {{ number_format($operatingDebits, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-arrow-up u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">OPERATING OUTFLOWS</div>
                <div class="kpi-number u-c-danger">- ₹ {{ number_format($operatingCredits, 2) }}</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-arrow-down u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">NET OPERATING CASH</div>
                <div class="kpi-number {{ $netOperatingCash >= 0 ? 'u-c-success' : 'u-c-danger' }}">
                    {{ $netOperatingCash >= 0 ? '+' : '' }}₹ {{ number_format($netOperatingCash, 2) }}
                </div>
            </div>
            <div class="z-icon-tile {{ $netOperatingCash >= 0 ? 'z-tone-success' : 'z-tone-danger' }}">
                <i class="dripicons-pulse u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">NET CASH FLOW</div>
                <div class="kpi-number {{ $netCashFlow >= 0 ? 'u-c-success' : 'u-c-danger' }}">
                    {{ $netCashFlow >= 0 ? '+' : '' }}₹ {{ number_format($netCashFlow, 2) }}
                </div>
            </div>
            <div class="z-icon-tile {{ $netCashFlow >= 0 ? 'z-tone-info' : 'z-tone-danger' }}">
                <i class="dripicons-wallet u-fs-18px"></i>
            </div>
        </div>
    </div>

    <!-- Statement Table -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table class="table cf-table mb-0">
                <thead>
                    <tr>
                        <th>Cash Flow Activity Category</th>
                        <th class="text-right u-w-250px">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- 1. Operating Activities -->
                    <tr class="u-bg-surface-3 u-fw-700 u-tt-uppercase">
                        <td colspan="2"><i class="dripicons-pulse text-primary mr-1"></i> 1. Cash Flows from Operating Activities</td>
                    </tr>
                    <tr>
                        <td class="pl-4 font-weight-500">Cash Received from Customers &amp; Sales</td>
                        <td class="text-right text-success font-weight-bold font-monospace">+ ₹ {{ number_format($operatingDebits, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="pl-4 font-weight-500">Cash Paid to Suppliers, Inventory &amp; Operating Expenses</td>
                        <td class="text-right text-danger font-weight-bold font-monospace">- ₹ {{ number_format($operatingCredits, 2) }}</td>
                    </tr>
                    <tr class="u-bg-surface-2 u-fw-700">
                        <td class="pl-3 font-weight-bold">Net Cash Provided by (Used in) Operating Activities</td>
                        <td class="text-right font-monospace font-weight-bold {{ $netOperatingCash >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $netOperatingCash >= 0 ? '+' : '' }}₹ {{ number_format($netOperatingCash, 2) }}
                        </td>
                    </tr>

                    <!-- 2. Investing Activities -->
                    <tr class="u-bg-surface-3 u-fw-700 u-tt-uppercase">
                        <td colspan="2"><i class="dripicons-graph-pie text-info mr-1"></i> 2. Cash Flows from Investing Activities</td>
                    </tr>
                    <tr>
                        <td class="pl-4 font-weight-500">Capital Equipment &amp; Fixed Asset Acquisitions</td>
                        <td class="text-right font-weight-bold text-muted font-monospace">₹ 0.00</td>
                    </tr>
                    <tr class="u-bg-surface-2 u-fw-700">
                        <td class="pl-3 font-weight-bold">Net Cash Provided by (Used in) Investing Activities</td>
                        <td class="text-right text-muted font-monospace font-weight-bold">₹ 0.00</td>
                    </tr>

                    <!-- 3. Financing Activities -->
                    <tr class="u-bg-surface-3 u-fw-700 u-tt-uppercase">
                        <td colspan="2"><i class="dripicons-wallet text-warning mr-1"></i> 3. Cash Flows from Financing Activities</td>
                    </tr>
                    <tr>
                        <td class="pl-4 font-weight-500">Owner Equity Contributions / Loan Borrowings</td>
                        <td class="text-right font-weight-bold text-muted font-monospace">₹ 0.00</td>
                    </tr>
                    <tr class="u-bg-surface-2 u-fw-700">
                        <td class="pl-3 font-weight-bold">Net Cash Provided by (Used in) Financing Activities</td>
                        <td class="text-right text-muted font-monospace font-weight-bold">₹ 0.00</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="u-bg-ink u-c-xffffff">
                        <td><h6 class="m-0 font-weight-bold u-c-xffffff u-fs-14px">Net Increase / (Decrease) in Cash and Cash Equivalents</h6></td>
                        <td class="text-right font-monospace">
                            <h5 class="m-0 font-weight-bold {{ $netCashFlow >= 0 ? 'text-success' : 'text-danger' }} u-fs-16px">
                                {{ $netCashFlow >= 0 ? '+' : '' }}₹ {{ number_format($netCashFlow, 2) }}
                            </h5>
                        </td>
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
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/accounting-cash-flow-statement.css') }}">
@endpush
