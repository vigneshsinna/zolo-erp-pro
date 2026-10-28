@extends('backend.layout.main')
@section('content')


<section class="ageing-workspace-view">
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-clock text-primary"></i> Receivables &amp; Payables Ageing</h1>
            <span class="comm-title-badge"><i class="dripicons-tag"></i> Maturity Bucketing &amp; Outstanding Debt Analysis</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link" href="{{ route('accounting.vouchers') }}"><i class="dripicons-home"></i> Voucher Hub</a></li>
            <li><a class="nav-link" href="{{ route('accounting.general-ledger') }}"><i class="dripicons-view-thumb"></i> General Ledger</a></li>
            <li><a class="nav-link active" href="{{ route('accounting.ageing') }}"><i class="dripicons-clock"></i> Ageing</a></li>
            <li><a class="nav-link" href="{{ route('accounting.day-book') }}"><i class="dripicons-calendar"></i> Day Book</a></li>
            <li><a class="nav-link" href="{{ route('accounting.cash-book') }}"><i class="dripicons-wallet"></i> Cash Book</a></li>
        </ul>
        <div class="comm-actions">
            <a href="{{ route('accounting.collections') }}" class="btn btn-outline-primary btn-sm">Collections</a>
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary btn-sm">Export all filtered rows</a>
            <button data-erp-print class="btn btn-outline-secondary btn-sm u-h-28px u-fs-11px">
                <i class="dripicons-print"></i> Print Report
            </button>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="desk-filter-card">
        <form method="GET" class="m-0">
            <div class="row align-items-end u-m-0-6px">
                <div class="col-md-4 u-p-0-6px">
                    <label for="ageing-party" class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">Party Type</label>
                    <select name="party_type" id="ageing-party" class="form-control form-control-sm u-h-32px u-fs-12px">
                        <option value="customer" @selected($report['party_type'] === 'customer')>Customers (Receivables)</option>
                        <option value="supplier" @selected($report['party_type'] === 'supplier')>Suppliers (Payables)</option>
                    </select>
                </div>
                <div class="col-md-4 u-p-0-6px">
                    <label for="ageing-date" class="font-weight-600 mb-1 u-fs-11px u-tt-uppercase u-c-text-muted">As of Date</label>
                    <input class="form-control form-control-sm u-h-32px u-fs-12px" id="ageing-date" name="as_of_date" type="date" value="{{ $report['as_of_date'] }}" required>
                </div>
                <div class="col-md-4 d-flex u-p-0-6px">
                    <button class="btn btn-primary btn-sm flex-grow-1 u-h-32px u-fs-12px u-fw-600" type="submit">
                        <i class="dripicons-search"></i> Show Ageing
                    </button>
                </div>
            </div>
            <div class="row mt-2">
                @foreach(['party_id' => 'Party', 'agent_id' => 'Agent (invoice/current)', 'area_id' => 'Current area', 'salesman_id' => 'Invoice salesman', 'ledger_group_id' => 'Ledger parent group'] as $field => $label)
                    <div class="col-md-2"><label for="ageing-{{ $field }}">{{ $label }}</label><select class="form-control form-control-sm" name="{{ $field }}" id="ageing-{{ $field }}"><option value="">All</option>@foreach($options[$field] as $option)<option value="{{ $option->id }}" @selected((string)request($field) === (string)$option->id)>{{ $option->name }}</option>@endforeach</select></div>
                @endforeach
                <div class="col-md-2"><label for="ageing-cutoffs">Bucket cutoffs (days)</label><input class="form-control form-control-sm" id="ageing-cutoffs" value="{{ implode(',', $report['bucket_cutoffs']) }}" pattern="[0-9]+(,[0-9]+)*" required></div>
                <span id="ageing-bucket-inputs">@foreach($report['bucket_cutoffs'] as $cutoff)<input type="hidden" name="bucket_cutoffs[]" value="{{ $cutoff }}">@endforeach</span>
            </div>
        </form>
    </div>
    <div class="alert {{ $report['reconciliation']['is_reconciled'] ? 'alert-success' : 'alert-warning' }} py-2">
        <strong>Branch reconciliation:</strong> Control {{ $report['reconciliation']['control_balance'] }};
        open items including credits {{ $report['reconciliation']['open_item_balance'] }};
        difference {{ $report['reconciliation']['difference'] }}. Filtered net {{ $report['reconciliation']['filtered_balance'] }}.
        <small class="d-block">{{ $report['reconciliation']['scope'] }} {{ $report['metadata_basis'] }}</small>
    </div>

    <!-- KPI Summary Strip -->
    <div class="kpi-summary-strip">
        @foreach($report['totals'] as $bucket => $amount)
            <div class="kpi-card">
                <div>
                    <div class="kpi-label">{{ strtoupper($bucket) }}{{ $bucket === 'credits' ? '' : ' DAYS' }}</div>
                    <div class="kpi-number font-monospace u-fs-16px">
                        ₹ {{ is_numeric($amount) ? number_format((float)$amount, 2) : $amount }}
                    </div>
                </div>
                <div class="u-w-34px u-h-34px u-r-6px u-bg-surface-3 u-d-flex u-items-center u-justify-center u-c-text-2">
                    <i class="dripicons-clock u-fs-16px"></i>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Ageing Details Table -->
    <div class="desk-card-wrapper">
        <div class="table-responsive">
            <table class="table ageing-table mb-0">
                <thead>
                    <tr>
                        <th scope="col">Document / Bill</th>
                        <th scope="col">Party</th>
                        <th scope="col">Document Date</th>
                        <th scope="col">Due Date</th>
                        <th scope="col" class="text-center">Days Overdue</th>
                        <th scope="col">Aging Bucket</th>
                        <th scope="col" class="text-right">Open Amount</th>
                        <th scope="col">Agent / Area / Salesman / Group</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['rows'] as $row)
                        <tr>
                            <td><span class="font-weight-bold font-monospace text-primary">{{ $row['item']->document_no }}</span></td>
                            <td class="font-weight-600">{{ $row['party_name'] }}</td>
                            <td>{{ $row['document_date'] }}</td>
                            <td>{{ $row['item']->due_date->format('Y-m-d') }}</td>
                            <td class="text-center font-monospace">{{ $row['days'] }}</td>
                            <td>
                                <span class="badge badge-secondary u-fs-11px u-p-3px-6px">
                                    {{ $row['bucket'] }}
                                </span>
                            </td>
                            <td class="text-right font-monospace font-weight-bold u-c-text">
                                ₹ {{ number_format((float)$row['open_amount'], 2) }}
                            </td>
                            <td>{{ $row['agent_name'] ?? '—' }} ({{ $row['agent_basis'] }}) / {{ $row['area_name'] ?? '—' }} / {{ $row['salesman_name'] ?? '—' }} / {{ $row['ledger_group_name'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No open items as of this date.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
<script>
document.getElementById('ageing-cutoffs').addEventListener('input', function () {
    const container = document.getElementById('ageing-bucket-inputs');
    container.replaceChildren();
    this.value.split(',').forEach(value => { const input = document.createElement('input'); input.type = 'hidden'; input.name = 'bucket_cutoffs[]'; input.value = value.trim(); container.append(input); });
});
</script>

@endsection

@push('css')
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/zolo-erp-neo.css') . '?v=' . (file_exists(public_path('css/zolo-erp-neo.css')) ? filemtime(public_path('css/zolo-erp-neo.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/accounting-ageing.css') }}">
@endpush
