@extends('backend.layout.main')
@section('content')


<section class="book-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-document text-primary"></i> {{ $type === 'cash' ? 'Cash Book' : 'Day Book' }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> {{ $type === 'cash' ? 'Cash Account Transactions' : 'Daily Chronological Activity' }}</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('accounting.vouchers') }}"><i class="dripicons-home"></i> Voucher Hub</a></li>
            <li><a class="nav-link {{ $type === 'day' ? 'active' : '' }}" href="{{ route('accounting.day-book') }}"><i class="dripicons-calendar"></i> Day Book</a></li>
            <li><a class="nav-link {{ $type === 'cash' ? 'active' : '' }}" href="{{ route('accounting.cash-book') }}"><i class="dripicons-wallet"></i> Cash Book</a></li>
            <li><a class="nav-link" href="{{ route('accounting.ageing') }}"><i class="dripicons-clock"></i> Receivables / Payables</a></li>
            <li><a class="nav-link" href="{{ route('accounting.general-ledger') }}"><i class="dripicons-view-thumb"></i> General Ledger</a></li>
        </ul>
        <div class="comm-actions">
            <button data-erp-print class="btn btn-outline-secondary btn-sm u-h-28px u-fs-11px">
                <i class="dripicons-print"></i> Print Book
            </button>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="desk-filter-card">
        <form method="GET" class="m-0">
            <div class="row align-items-end u-m-0-6px">
                <div class="col-md-4 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">Start Date</label>
                    <input class="form-control form-control-sm u-h-32px u-fs-12px" type="date" id="book-start" name="start_date" value="{{ $report['start_date'] }}">
                </div>
                <div class="col-md-4 u-p-0-6px">
                    <label class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">End Date</label>
                    <input class="form-control form-control-sm u-h-32px u-fs-12px" type="date" id="book-end" name="end_date" value="{{ $report['end_date'] }}">
                </div>
                <div class="col-md-4 d-flex u-p-0-6px">
                    <button class="btn btn-primary btn-sm flex-grow-1 u-h-32px u-fs-12px u-fw-600" type="submit">
                        <i class="dripicons-search"></i> Show Book
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Table Container -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table class="table book-table mb-0">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Entry Number</th>
                        <th scope="col">Narration</th>
                        <th scope="col" class="text-right">Debit</th>
                        <th scope="col" class="text-right">Credit</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['entries'] as $entry)
                        <tr>
                            <td>{{ $entry->entry_date->format('Y-m-d') }}</td>
                            <td><span class="font-weight-bold font-monospace text-primary">{{ $entry->entry_number }}</span></td>
                            <td>{{ $entry->description }}</td>
                            <td class="text-right font-monospace font-weight-600 u-c-info">
                                {{ (float)($entry->cash_debit ?? $entry->total_debit) > 0 ? '₹ ' . number_format((float)($entry->cash_debit ?? $entry->total_debit), 2) : '-' }}
                            </td>
                            <td class="text-right font-monospace font-weight-600 u-c-success">
                                {{ (float)($entry->cash_credit ?? $entry->total_credit) > 0 ? '₹ ' . number_format((float)($entry->cash_credit ?? $entry->total_credit), 2) : '-' }}
                            </td>
                            <td>
                                <details>
                                    <summary class="font-weight-600 text-primary u-cur-pointer u-fs-11_5px">Journal Lines</summary>
                                    <ul class="mt-2 mb-0 pl-3 small text-muted">
                                        @foreach($entry->items as $line)
                                            <li>
                                                <a href="{{ route('accounting.general-ledger', ['account_id' => $line->chart_of_account_id, 'start_date' => $entry->entry_date->format('Y-m-d'), 'end_date' => $entry->entry_date->format('Y-m-d')]) }}" class="font-weight-600">
                                                    {{ $line->account->name }}
                                                </a>: Dr ₹ {{ number_format($line->debit, 2) }} / Cr ₹ {{ number_format($line->credit, 2) }} &bull; {{ $line->memo }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No posted entries for this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($report['entries']->hasPages())
            <div class="mt-3 d-flex justify-content-end">
                {{ $report['entries']->links() }}
            </div>
        @endif
    </div>
</section>

@endsection

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/accounting-book.css') }}">
@endpush
