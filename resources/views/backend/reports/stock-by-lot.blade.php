@extends('backend.layout.main')
@section('charts', true)
@section('content')
@php
    // Quantities print without padding zeros (1,200 · 9.1 · 0.125); values keep two decimals.
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
    $page = collect($rows->items());
    $isSimplifiedView = !empty($isSimplified);
    $quantities = ['opening_qty' => 'Opening', 'inward_qty' => 'Inward', 'outward_qty' => 'Outward', 'closing_qty' => 'Closing',
        'current_qty' => 'Warehouse now', 'custody_qty' => 'Custody now', 'custody_value' => 'GDNI value', 'owned_current_qty' => 'Owned now'];
    $optional = ['custody_qty', 'custody_value'];
    $zero = (!$isSimplifiedView && $page->isNotEmpty()) ? array_values(array_filter($optional, fn ($column) => $page->every(fn ($row) => (float) ($row->$column ?? 0) == 0.0))) : [];
    $shown = array_diff_key($quantities, array_flip($zero));
    $oneGodown = (!$isSimplifiedView && $page->isNotEmpty() && $page->pluck('warehouse')->unique()->count() === 1) ? $page->first()->warehouse : null;
@endphp
<x-inventory-command-bar active="stock" view="{{ $isSimplifiedView ? 'simplified' : 'balances' }}"></x-inventory-command-bar>
<section id="stock-lot-report" class="product-workspace-view" data-no-fit>
    <div class="desk-filter-card">
        <form class="lot-filters" method="get">
            <div class="filter-field">
                <label for="lot-group">Stock view</label>
                <select id="lot-group" name="group_by" class="form-control">
                    <option value="simplified" @selected(($filters['group_by'] ?? '') === 'simplified')>Simplified Flow (Purchased → Job Work → Final)</option>
                    <option value="product" @selected(($filters['group_by'] ?? 'product') === 'product')>Combined by product</option>
                    <option value="lot" @selected(($filters['group_by'] ?? '') === 'lot')>Individual lots</option>
                </select>
            </div>
            <div class="filter-field"><label for="lot-from">From</label><input id="lot-from" type="date" name="from" class="form-control f-date" value="{{ $filters['from'] }}" required></div>
            <div class="filter-field"><label for="lot-to">To</label><input id="lot-to" type="date" name="to" class="form-control f-date" value="{{ $filters['to'] }}" required></div>
            <div class="filter-field"><label for="lot-product">Product</label><select id="lot-product" name="product_id" class="form-control f-pick"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(($filters['product_id'] ?? '') == $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            @unless($isSimplifiedView)
            <div class="filter-field"><label for="lot-warehouse">Warehouse / Godown</label><select id="lot-warehouse" name="warehouse_id" class="form-control f-pick"><option value="">All branch warehouses</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            @endunless
            <div class="filter-field"><label for="lot-number">Lot (exact)</label><input id="lot-number" name="lot" maxlength="100" class="form-control f-pick" value="{{ $filters['lot'] ?? '' }}" placeholder="__unbatched__ for no lot"></div>
            <div class="filter-field"><label for="lot-search" title="Filter by Product Lifecycle Code (PLC) / Handle, series code, SKU, or party">Search / Lifecycle Code (PLC)</label><input id="lot-search" type="text" name="search" class="form-control f-pick" value="{{ $filters['search'] ?? '' }}" placeholder="e.g. 5IM, Z58, Cotton..."></div>
            <div class="filter-actions d-flex u-gap-6px">
                <button type="submit" class="btn btn-primary"><i class="dripicons-experiment"></i> Apply</button>
                <button type="submit" name="export" value="csv" class="btn btn-outline-primary"><i class="dripicons-export"></i> Export CSV</button>
                <a href="{{ route('inventory-reports.lifecycle', ['plc' => $filters['search'] ?? '']) }}" class="btn btn-outline-primary" title="Open Dedicated Product Lifecycle & Conversion Tree"><i class="dripicons-network mr-1"></i> Product Lifecycle</a>
                <a href="{{ route('inventory-reports.timeline', ['product_id' => $filters['product_id'] ?? '']) }}" class="btn btn-outline-secondary" title="View step-by-step visual lifecycle timeline"><i class="dripicons-time-reverse"></i> Visual Timeline</a>
            </div>
        </form>
    </div>

    @if($errors->any())<div class="alert alert-danger py-1 mb-2" role="alert">{{ $errors->first() }}</div>@endif

    {{-- Dedicated Product Lifecycle Link Strip --}}
    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
        <div class="d-flex align-items-center u-gap-6px">
            <span class="text-muted small font-weight-bold"><i class="dripicons-network mr-1 text-primary"></i> Product Lifecycle:</span>
            <a href="{{ route('inventory-reports.lifecycle') }}" class="badge badge-light border text-primary px-2 py-1" title="Open full visual branching tree for all products">
                <i class="dripicons-network mr-1"></i> Open Product Lifecycle Tree ➔
            </a>
        </div>
        @if(!empty($stockGraphData['labels']))
        <div>
            <button class="btn btn-xs btn-outline-primary" type="button" data-toggle="collapse" data-target="#collapseStockGraphs" aria-expanded="false" id="btnToggleChartModal">
                <i class="dripicons-graph-bar mr-1"></i> Toggle Movement Charts
            </button>
        </div>
        @endif
    </div>

    {{-- Optional Movement Charts Collapse (Available via Toggle) --}}
    @if(!empty($stockGraphData['labels']))
    <div class="desk-card-wrapper stock-graph-wrapper mb-3 p-3 collapse" id="collapseStockGraphs" data-no-fit>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h5 class="mb-0 font-weight-bold text-dark d-inline-flex align-items-center">
                    <i class="dripicons-graph-bar mr-1"></i> Inventory Movement & Distribution Overview
                    <span class="badge badge-light border text-muted ml-2 font-weight-normal u-fs-11px">Top 8 Products by Volume</span>
                </h5>
                <div class="text-muted small">Visual comparison across top active products: Inward (Come) vs Sold Out (Sales) vs Outward / Job Work (Gone) vs Returned vs Ready Stock. Filter by product above to isolate individual items.</div>
            </div>
            <button class="btn btn-xs btn-outline-secondary" type="button" data-toggle="collapse" data-target="#collapseStockGraphs" aria-expanded="false">
                <i class="dripicons-cross"></i> Close Charts
            </button>
        </div>
        <div class="row pt-2">
            <div class="col-lg-8 mb-3 mb-lg-0">
                <div class="stock-overview-chart-canvas">
                    <canvas id="mainStockMovementChart"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="stock-overview-chart-canvas">
                    <canvas id="mainStockDistributionDoughnut"></canvas>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if($isSimplifiedView)
    {{-- Executive Simplified Flow View (Canonical zoloERP KPI Strip) --}}
    <div class="kpi-summary-strip d-print-none mb-2">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Purchased Inward</div>
                <div class="kpi-number">{{ $qty($totals['total_purchased'] ?? 0) }}</div>
                <div class="kpi-subtext">Total bought from suppliers</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-download u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Given to Job Work</div>
                <div class="kpi-number text-warning">{{ $qty($totals['total_given_to_job_work'] ?? 0) }}</div>
                <div class="kpi-subtext">Dispatched via DCs</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-export u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Returned after Job Work</div>
                <div class="kpi-number text-success">{{ $qty($totals['total_returned'] ?? 0) }}</div>
                <div class="kpi-subtext">Inwarded back (same/converted)</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">In Godown</div>
                <div class="kpi-number text-success">{{ $qty($totals['total_in_godown'] ?? 0) }}</div>
                <div class="kpi-subtext">Available ready stock</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-home u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">In Quarantine</div>
                <div class="kpi-number text-danger">{{ $qty($totals['total_in_quarantine'] ?? 0) }}</div>
                <div class="kpi-subtext">Damage / inspection / loss</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-warning u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">At Processors (WIP)</div>
                <div class="kpi-number text-warning">{{ $qty($totals['total_at_job_workers'] ?? 0) }}</div>
                <div class="kpi-subtext">Pending with job workers</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-gear u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Final Owned Stock</div>
                <div class="kpi-number text-primary">{{ $qty($totals['total_final_stock'] ?? 0) }}</div>
                <div class="kpi-subtext">Godown + Quarantine + WIP</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-box u-fs-18px"></i>
            </div>
        </div>
    </div>

    <div class="desk-card-wrapper lot-card simplified-table-card" data-no-fit>
        <p class="lot-note" title="Business Flow: What was purchased, sent to processors, returned after processing, and current total stock owned.">
            <i class="dripicons-information"></i> <strong>Simplified Business Flow:</strong> Tracks original purchases, dispatches to job workers, returned/converted stock, and live godown balances without virtual warehouse confusion.
        </p>
        <div class="table-responsive" data-no-fit>
            <table class="table table-sm table-simplified" data-no-fit>
                <caption>{{ $rows->total() }} products · Reconciled business view</caption>
                <thead>
                    <tr>
                        <th class="th-prod">Product & SKU</th>
                        <th class="th-lot">Lot</th>
                        <th class="th-unit">Unit</th>
                        <th class="text-right th-kpi-inward">Purchased Inward</th>
                        <th class="text-right th-kpi-dc">Given to Job Work</th>
                        <th class="text-right th-kpi-grn">Returned after Job Work</th>
                        <th class="text-right th-kpi-main">In Godown</th>
                        <th class="text-right th-kpi-loss">In Quarantine</th>
                        <th class="text-right th-kpi-wip">At Processors (WIP)</th>
                        <th class="text-right th-kpi-final">Final Stock</th>
                        <th class="text-center th-act">Lifecycle</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                    <tr>
                        <td class="lot-product" title="{{ $row->product }} {{ $row->product_code }}">
                            <div class="d-flex align-items-center flex-wrap">
                                @if(!empty($row->handle))
                                <span class="badge badge-primary lifecycle-code-badge mr-1 cursor-pointer" onclick="filterByLifecycleCode('{{ $row->handle }}')" title="Product Lifecycle Code (PLC) / Handle: {{ $row->handle }}">
                                    PLC: {{ $row->handle }}
                                </span>
                                @endif
                                <span class="product-name-title">{{ $row->product }}</span>
                            </div>
                            <small class="product-code-sub">{{ $row->product_code }}</small>
                            {{-- Connected Row Mini-Stepper Pipeline (Shirt reference adapted to ERP table cells) --}}
                            <div class="row-mini-stepper">
                                <span class="rms-pill pill-in" title="Purchased Inward"><i class="dripicons-download"></i> {{ $qty($row->purchased_qty) }}</span>
                                <span class="rms-arr">&rarr;</span>
                                <span class="rms-pill pill-dc" title="Given to Job Work"><i class="dripicons-export"></i> {{ $qty($row->given_to_job_work_qty) }}</span>
                                <span class="rms-arr">&rarr;</span>
                                <span class="rms-pill pill-grn" title="Returned after Job Work"><i class="dripicons-checkmark"></i> {{ $qty($row->returned_after_job_work_qty) }}</span>
                                <span class="rms-arr">&rarr;</span>
                                <span class="rms-pill pill-stock" title="Final Owned Stock"><i class="dripicons-box"></i> {{ $qty($row->final_stock_qty) }}</span>
                            </div>
                        </td>
                        <td><span class="badge badge-light lot-pill">{{ $row->lot ?: 'Unbatched' }}</span></td>
                        <td>{{ $row->base_unit }}</td>
                        <td class="text-right cell-inward">{{ $qty($row->purchased_qty) }}</td>
                        <td class="text-right cell-dc">{{ $qty($row->given_to_job_work_qty) }}</td>
                        <td class="text-right cell-grn">{{ $qty($row->returned_after_job_work_qty) }}</td>
                        <td class="text-right cell-godown font-weight-bold">{{ $qty($row->in_godown_qty) }}</td>
                        <td class="text-right cell-quarantine">{{ $qty($row->in_quarantine_qty) }}</td>
                        <td class="text-right cell-wip">{{ $qty($row->at_job_workers_qty) }}</td>
                        <td class="text-right cell-final font-weight-bold">{{ $qty($row->final_stock_qty) }}</td>
                        <td class="text-center cell-action">
                            <a href="{{ route('inventory-reports.timeline', ['product_id' => $row->product_id, 'lot' => $row->lot !== 'Combined lots' ? $row->lot : '']) }}" 
                               class="btn btn-xs btn-outline-primary timeline-btn" 
                               title="View interactive journey of this product">
                                <i class="dripicons-time-reverse"></i> Timeline
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">No stock movements or products match these filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </div>

    @else
    {{-- Standard Detailed Warehouse Ledger / Lot View --}}
    <div class="desk-card-wrapper lot-card" data-no-fit>
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
            <span class="small font-weight-bold text-dark">
                <i class="dripicons-calendar"></i> Movement Period: <strong>{{ $filters['from'] }}</strong> to <strong>{{ $filters['to'] }}</strong>
            </span>
            <span class="small text-muted" title="Historical owned totals are unavailable. Owned now = warehouse now + customer custody now. GDNI is carried accrual value, not independent lot costing; inventory uses item weighted-average valuation.">
                <i class="dripicons-information"></i> Custody, GDNI and owned columns are <strong>current balances only</strong>. Historical owned totals are unavailable. Opening, Inward, Outward and Closing reflect this period.
            </span>
        </div>
        <div class="table-responsive" data-no-fit>
            <table class="table table-sm" data-no-fit>
                <caption>
                    {{ $rows->total() }} rows
                    @if($oneGodown) · Godown: <strong>{{ $oneGodown }}</strong>@endif
                    @if($zero) · {{ implode(' and ', array_map(fn ($column) => $quantities[$column], $zero)) }} hidden (zero on every row)@endif
                </caption>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Lot</th>
                        @unless($oneGodown)<th>Warehouse / Godown</th>@endunless
                        <th>Unit</th>
                        @foreach($shown as $label)<th class="text-right">{{ $label }}</th>@endforeach
                        <th class="text-center">Timeline</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                    <tr>
                        <td class="lot-product" title="{{ $row->product }} {{ $row->product_code }}">
                            {{ $row->product }}
                            <small>{{ $row->product_code }}</small>
                            @foreach(($stockNameNotes[$row->product_id.'|'.$row->warehouse_id.'|'.$row->lot] ?? []) as $stockNote)
                                <small class="lot-stock-link">{{ $stockNote }}</small>
                            @endforeach
                        </td>
                        <td>{{ $row->lot ?: 'Unbatched' }}</td>
                        @unless($oneGodown)<td>{{ $row->warehouse }}</td>@endunless
                        <td>{{ $row->base_unit }}</td>
                        @foreach(array_keys($shown) as $column)
                            <td class="text-right">{{ $column === 'custody_value' ? number_format((float) ($row->$column ?? 0), 2) : $qty($row->$column ?? 0) }}</td>
                        @endforeach
                        <td class="text-center">
                            <a href="{{ route('inventory-reports.timeline', ['product_id' => $row->product_id, 'lot' => $row->lot ?: '']) }}" 
                               class="btn btn-xs btn-outline-primary" title="View product timeline">
                                <i class="dripicons-time-reverse"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ 5 + count($shown) }}">No stock movements or current customer custody match these filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </div>
    @endif
</section>
@endsection

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/reports-stock-by-lot.css') }}">
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('natural-scroll-page');

    // Ensure all cards and tables expand naturally to full height with zero inside scrolling
    function removeScrollClamps() {
        document.querySelectorAll('#stock-lot-report .table-responsive, #stock-lot-report .shell-fit-scroll, #stock-lot-report .desk-card-wrapper, #stock-lot-report .lot-card').forEach(function(box) {
            box.classList.remove('shell-fit-scroll');
            box.style.maxHeight = 'none';
            box.style.height = 'auto';
            box.style.overflowY = 'visible';
        });
    }
    removeScrollClamps();
    window.addEventListener('resize', removeScrollClamps);
    setTimeout(removeScrollClamps, 100);
    setTimeout(removeScrollClamps, 350);

    @if(!empty($stockGraphData['labels']))
    const movementCanvas = document.getElementById('mainStockMovementChart');
    if (movementCanvas) {
        var pData = {!! json_encode($stockGraphData['purchased']) !!};
        var sData = {!! json_encode($stockGraphData['sold'] ?? []) !!};
        var dData = {!! json_encode($stockGraphData['dispatched']) !!};
        var rData = {!! json_encode($stockGraphData['returned']) !!};
        var fData = {!! json_encode($stockGraphData['final_stock']) !!};
        var allVals = [].concat(pData, sData, dData, rData, fData).map(Number).filter(function(v) { return !isNaN(v); });
        var maxVal = allVals.length ? Math.max.apply(null, allVals) : 100;
        var topMax = maxVal > 0 ? Math.ceil(maxVal * 1.18) : 10;

        new Chart(movementCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: {!! json_encode($stockGraphData['labels']) !!},
                datasets: [
                    {
                        label: 'Inward (Come)',
                        data: pData,
                        backgroundColor: 'rgba(22, 163, 74, 0.85)',
                        borderColor: '#16a34a',
                        borderWidth: 1,
                        borderRadius: 3
                    },
                    {
                        label: 'Sold Out (Sales)',
                        data: sData,
                        backgroundColor: 'rgba(239, 68, 68, 0.85)',
                        borderColor: '#ef4444',
                        borderWidth: 1,
                        borderRadius: 3
                    },
                    {
                        label: 'Outward / Job Work (Gone)',
                        data: dData,
                        backgroundColor: 'rgba(245, 158, 11, 0.85)',
                        borderColor: '#f59e0b',
                        borderWidth: 1,
                        borderRadius: 3
                    },
                    {
                        label: 'Returned after Processing',
                        data: rData,
                        backgroundColor: 'rgba(6, 182, 212, 0.85)',
                        borderColor: '#06b6d4',
                        borderWidth: 1,
                        borderRadius: 3
                    },
                    {
                        label: 'Closing Stock (In Hand)',
                        data: fData,
                        backgroundColor: 'rgba(124, 18, 116, 0.85)',
                        borderColor: '#7c1274',
                        borderWidth: 1,
                        borderRadius: 3
                    }
                ]
            },
            plugins: [{
                afterDatasetsDraw: function(chart) {
                    var ctx = chart.ctx;
                    ctx.save();
                    ctx.font = 'bold 11px system-ui, -apple-system, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    chart.data.datasets.forEach(function(dataset, i) {
                        var meta = chart.getDatasetMeta(i);
                        if (!meta.hidden) {
                            meta.data.forEach(function(bar, index) {
                                var val = dataset.data[index];
                                if (val !== null && val !== undefined && Number(val) > 0) {
                                    ctx.fillStyle = '#0f172a';
                                    ctx.fillText(Number(val).toLocaleString(), bar._model.x, bar._model.y - 3);
                                }
                            });
                        }
                    });
                    ctx.restore();
                }
            }],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        top: 22,
                        right: 8,
                        left: 8,
                        bottom: 4
                    }
                },
                scales: {
                    yAxes: [{
                        ticks: { 
                            beginAtZero: true,
                            suggestedMax: topMax,
                            fontColor: '#64748b'
                        },
                        gridLines: { color: 'rgba(226, 232, 240, 0.6)' }
                    }],
                    xAxes: [{
                        ticks: { fontColor: '#64748b' },
                        gridLines: { display: false }
                    }]
                }
            }
        });
    }

    const doughnutCanvas = document.getElementById('mainStockDistributionDoughnut');
    if (doughnutCanvas && {!! !empty($stockGraphData['doughnut']['values']) ? 'true' : 'false' !!}) {
        new Chart(doughnutCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode($stockGraphData['doughnut']['labels'] ?? []) !!},
                datasets: [{
                    data: {!! json_encode($stockGraphData['doughnut']['values'] ?? []) !!},
                    backgroundColor: ['#16a34a', '#ef4444', '#f59e0b', '#7c1274'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, fontSize: 11 }
                }
            }
        });
    }
    @endif
});
</script>
@endpush

