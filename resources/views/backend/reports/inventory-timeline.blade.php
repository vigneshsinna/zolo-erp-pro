@extends('backend.layout.main')
@section('charts', true)
@section('content')
@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
@endphp
<x-inventory-command-bar active="stock" view="timeline"></x-inventory-command-bar>

<section id="inventory-timeline-page" class="product-workspace-view" data-no-fit>
    {{-- Sleek & Slim Horizontal Filter Toolbar (Single-line compact design matching ERP standard) --}}
    <div class="desk-filter-card d-print-none">
        <form class="timeline-filters" method="get">
            <div class="filter-field">
                <label for="tl-view">Stock View</label>
                <select id="tl-view" name="group_by" class="form-control f-pick">
                    <option value="product" @selected(($filters['group_by'] ?? 'product') === 'product')>Combined by product</option>
                    <option value="lot" @selected(($filters['group_by'] ?? '') === 'lot')>By Individual Lots</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="tl-from">From</label>
                <input id="tl-from" type="date" name="from" class="form-control f-date" value="{{ $filters['from'] }}" required>
            </div>
            <div class="filter-field">
                <label for="tl-to">To</label>
                <input id="tl-to" type="date" name="to" class="form-control f-date" value="{{ $filters['to'] }}" required>
            </div>
            <div class="filter-field filter-field-product">
                <label for="tl-product">Product</label>
                <select id="tl-product" name="product_id" class="form-control selectpicker" data-live-search="true" required>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected(($filters['product_id'] ?? ($product->id ?? null)) == $p->id)>
                            {{ $p->name }} ({{ $p->code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="tl-wh">Warehouse / Godown</label>
                <select id="tl-wh" name="warehouse_id" class="form-control f-pick">
                    <option value="">All Branch Warehouses</option>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected(($filters['warehouse_id'] ?? null) == $w->id)>
                            {{ $w->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="tl-lot">Lot (Exact)</label>
                <input id="tl-lot" name="lot" maxlength="100" class="form-control f-lot" value="{{ $filters['lot'] ?? '' }}" placeholder="Unbatched / Lot No...">
            </div>
            <div class="filter-actions d-flex u-gap-6px">
                <button type="submit" class="btn btn-primary"><i class="dripicons-experiment"></i> Apply</button>
                <a href="{{ route('inventory-reports.stock-by-lot', ['group_by' => 'simplified']) }}" class="btn btn-outline-secondary">
                    <i class="dripicons-list"></i> Simplified Balances
                </a>
            </div>
        </form>
    </div>

    @if($hasProduct && $product)
    {{-- Canonical 5-Card KPI Summary Strip (100% zoloERP design system reuse; zero AI bespoke card styles) --}}
    <div class="kpi-summary-strip d-print-none mb-3">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Closing Stock</div>
                <div class="kpi-number text-primary">{{ $qty($summary['final_owned'] ?? 0) }} <small class="text-muted">{{ $summary['unit'] }}</small></div>
                <div class="kpi-subtext">Godown: {{ $qty($summary['in_godown'] ?? 0) }} · WIP: {{ $qty($summary['at_processors_wip'] ?? 0) }} · Quat: {{ $qty($summary['in_quarantine'] ?? 0) }}</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-box u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Total Inward</div>
                <div class="kpi-number text-success">{{ $qty($summary['total_inward'] ?? 0) }} <small class="text-muted">{{ $summary['unit'] }}</small></div>
                <div class="kpi-subtext">Purchased: {{ $qty($summary['total_purchased'] ?? 0) }} · Returned: {{ $qty($summary['total_returned'] ?? 0) }}</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-download u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Total Outward</div>
                <div class="kpi-number text-warning">{{ $qty($summary['total_outward'] ?? 0) }} <small class="text-muted">{{ $summary['unit'] }}</small></div>
                <div class="kpi-subtext">Job Work: {{ $qty($summary['total_dispatched'] ?? 0) }} · Sales Outward</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-export u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">Net Movement</div>
                <div class="kpi-number {{ ($summary['net_movement'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ ($summary['net_movement'] ?? 0) >= 0 ? '+' : '' }}{{ $qty($summary['net_movement'] ?? 0) }} <small class="text-muted">{{ $summary['unit'] }}</small>
                </div>
                <div class="kpi-subtext">Period Inward minus Outward</div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-graph-bar u-fs-18px"></i>
            </div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">No. of Transactions</div>
                <div class="kpi-number">{{ $summary['transaction_count'] ?? count($events) }}</div>
                <div class="kpi-subtext">{{ $filters['from'] }} to {{ $filters['to'] }}</div>
            </div>
            <div class="z-icon-tile z-tone-neutral">
                <i class="dripicons-stack u-fs-18px"></i>
            </div>
        </div>
    </div>

    {{-- Combined Sleek Process Lifecycle & Milestone Chronology (Zero Horizontal Scroll; Fully Responsive Grid) --}}
    <div class="desk-card-wrapper combined-lifecycle-card mb-3">
        <div class="stepper-header d-flex justify-content-between align-items-center flex-wrap mb-2">
            <div>
                <div class="d-flex align-items-center flex-wrap mb-1">
                    <h5 class="mb-0 mr-2 font-weight-bold text-dark"><i class="dripicons-swap"></i> Connected Process Lifecycle & Milestone Chronology</h5>
                    <span class="badge badge-primary px-2 py-1"><i class="dripicons-time-reverse"></i> Inventory Traceability Journey</span>
                </div>
                <div class="text-muted small">Sequential manufacturing flow and transaction milestones from purchase inward through processing, conversion, and ready stock.</div>
            </div>
            @if(!empty($convertedToProducts) || !empty($convertedFromProducts))
                <div class="mt-2 mt-md-0 d-flex flex-wrap align-items-center u-gap-6px">
                    @foreach($convertedFromProducts as $fp)
                        <a href="{{ route('inventory-reports.timeline', ['product_id' => $fp['id'], 'from' => $filters['from'], 'to' => $filters['to']]) }}" class="btn btn-xs btn-outline-info" title="Trace back to raw source material">
                            <i class="dripicons-arrow-thin-left"></i> Trace Raw Source: <strong>{{ $fp['name'] }}</strong>
                        </a>
                    @endforeach
                    @foreach($convertedToProducts as $cp)
                        <a href="{{ route('inventory-reports.timeline', ['product_id' => $cp['id'], 'from' => $filters['from'], 'to' => $filters['to']]) }}" class="btn btn-xs btn-outline-primary" title="Trace forward to converted finished goods">
                            <i class="dripicons-arrow-thin-right"></i> Trace Converted: <strong>{{ $cp['name'] }}</strong>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="sleek-lifecycle-grid">
            @foreach($lifecycleStages as $index => $stage)
            <div class="sleek-stage-card stage-tone-{{ $stage['key'] }} stage-status-{{ $stage['status'] }}"
                 data-stage="{{ $stage['key'] }}"
                 data-ref="{{ $stage['ref_no'] ?? '' }}"
                 onclick="selectTimelineStage('{{ $stage['key'] }}', this)"
                 title="Click to highlight this stage and its milestones">
                <div class="ssc-top">
                    <span class="badge {{ $stage['badge_class'] }} ssc-pill">
                        <i class="{{ $stage['icon'] }}"></i> {{ $stage['label'] }}
                    </span>
                    @if($stage['status'] === 'completed')
                        <span class="ssc-status-icon text-success" title="Completed"><i class="dripicons-checkmark"></i></span>
                    @elseif($stage['status'] === 'active')
                        <span class="ssc-status-icon text-info" title="In Progress"><i class="dripicons-pulse"></i></span>
                    @endif
                </div>
                <div class="ssc-qty font-weight-bold">
                    {{ $qty($stage['qty']) }} <small>{{ $stage['unit'] }}</small>
                </div>
                @if(!empty($stage['date']))
                <div class="ssc-milestone text-truncate">
                    <span class="ssc-date"><i class="dripicons-calendar"></i> {{ $stage['date'] }}</span>
                    @if(!empty($stage['ref_no']))
                        &nbsp;&middot;&nbsp;<strong class="ssc-ref">{{ $stage['ref_no'] }}</strong>
                    @endif
                </div>
                @endif
                @if(!empty($stage['party']))
                    <div class="ssc-party text-truncate" title="{{ $stage['party'] }}">{{ $stage['party'] }}</div>
                @endif
                <div class="ssc-subtext text-muted small mt-1">{{ $stage['subtext'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Interactive Milestone Axis Line (Line Timeline with Nodes & Spotlight Inspector) --}}
        @if(!empty($chart['points_meta']) && count($chart['points_meta']) > 1)
        <div class="interactive-timeline-axis-wrap mt-3 pt-2">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                <span class="small font-weight-bold text-muted text-uppercase letter-spacing-1">
                    <i class="dripicons-graph-line"></i> Interactive Milestone Axis & Traceability Timeline
                </span>
                <span class="small text-muted" id="axis-hint-text">
                    <i class="dripicons-information"></i> Click any milestone node along the line or stage box to inspect why stock moved
                </span>
            </div>

            <div class="interactive-axis-rail">
                <div class="axis-line-bar"></div>
                <div class="axis-nodes-container">
                    @foreach($chart['points_meta'] as $idx => $pm)
                    <div class="axis-milestone-node node-stage-{{ $pm['stage'] }} {{ $idx === 1 ? 'is-active' : '' }}"
                         data-index="{{ $idx }}"
                         data-date="{{ $pm['date'] }}"
                         data-title="{{ $pm['title'] }}"
                         data-ref="{{ $pm['ref_no'] }}"
                         data-party="{{ $pm['party'] }}"
                         data-movement="{{ $pm['movement'] }}"
                         data-balance="{{ $pm['godown_balance'] }}"
                         data-wip="{{ $pm['wip_balance'] }}"
                         data-why="{{ $pm['why'] }}"
                         onclick="selectTimelineMilestone({{ $idx }}, this)"
                         title="{{ $pm['why'] }}">
                        <div class="axis-node-dot">
                            <span class="dot-inner"></span>
                        </div>
                        <div class="axis-node-content">
                            <div class="axis-node-date">{{ $pm['date'] }}</div>
                            <div class="axis-node-badge">{{ $pm['ref_no'] }}</div>
                            <div class="axis-node-qty {{ $pm['qty_class'] ?? ($pm['movement'] > 0 ? 'text-success' : ($pm['movement'] < 0 ? 'text-danger' : 'text-primary')) }}">
                                {{ $pm['qty_label'] ?? (($pm['movement'] > 0 ? '+' : '') . $qty($pm['movement']) . ' ' . $summary['unit']) }}
                            </div>
                        </div>

                        {{-- In-Place Milestone Callout Details (Opens directly at this clicked dot) --}}
                        <div class="axis-inplace-callout">
                            <div class="callout-arrow"></div>
                            <div class="callout-inner">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="badge {{ $pm['qty_class'] ?? 'badge-primary' }} callout-badge">
                                        {{ $pm['title'] }}
                                    </span>
                                    <span class="callout-date text-muted small font-weight-bold">
                                        <i class="dripicons-calendar"></i> {{ $pm['date'] }}
                                    </span>
                                </div>
                                <div class="callout-statement font-weight-bold mb-2">
                                    {{ $pm['why'] }}
                                </div>
                                <div class="callout-metrics-grid">
                                    <div class="cm-item">
                                        <span class="cm-lbl">Godown Stock:</span>
                                        <strong class="cm-val text-primary">{{ $qty($pm['godown_balance']) }} {{ $summary['unit'] }}</strong>
                                    </div>
                                    <div class="cm-item">
                                        <span class="cm-lbl">Processor WIP:</span>
                                        <strong class="cm-val text-warning">{{ $qty($pm['wip_balance']) }} {{ $summary['unit'] }}</strong>
                                    </div>
                                    <div class="cm-item">
                                        <span class="cm-lbl">Document Ref:</span>
                                        <strong class="cm-val">{{ $pm['ref_no'] }}</strong>
                                    </div>
                                    @if(!empty($pm['party']) && $pm['party'] !== '-' && $pm['party'] !== 'Main Godown')
                                    <div class="cm-item">
                                        <span class="cm-lbl">Party:</span>
                                        <strong class="cm-val text-truncate" title="{{ $pm['party'] }}">{{ $pm['party'] }}</strong>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Interactive Stock Evolution Spline Chart & Compact Product Sidebar (Mockup 1:1 Implementation) --}}
    <div class="row mb-3">
        {{-- Left 8-Column: Interactive Chart Container --}}
        <div class="col-lg-8 mb-3 mb-lg-0">
            <div class="desk-card-wrapper chart-panel-card h-100">
                <div class="chart-header-bar d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark">Stock Timeline &ndash; {{ $product->name }}</h4>
                        <div class="small text-muted">
                            Warehouse: <strong>{{ !empty($filters['warehouse_id']) ? ($warehouses->firstWhere('id', $filters['warehouse_id'])->name ?? 'Selected') : 'All Branch Warehouses' }}</strong>
                            &nbsp;|&nbsp; Unit: <strong>{{ $summary['unit'] }}</strong>
                        </div>
                    </div>
                    <div class="chart-controls d-flex align-items-center u-gap-6px mt-2 mt-md-0">
                        <div class="btn-group btn-group-sm chart-mode-toggle" role="group">
                            <button type="button" class="btn btn-outline-primary active" id="btnChartMultiProcess" title="Compare Godown Stock, Processor WIP, and Purchases together">
                                <i class="dripicons-graph-line"></i> Multi-Process Flow
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnChartBalance" title="Single Godown Physical Stock spline">
                                <i class="dripicons-pulse"></i> Stock Level
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnChartInOut" title="Inward vs Outward Bar Chart">
                                <i class="dripicons-swap"></i> Inward / Outward
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnChartCumulative" title="Cumulative Net Stock Evolution">
                                <i class="dripicons-graph-bar"></i> Cumulative
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Chart Canvas --}}
                <div class="chart-canvas-container">
                    <canvas id="stockEvolutionChart" height="260"></canvas>
                </div>
            </div>
        </div>

        {{-- Right 4-Column: Compact Product Details Card --}}
        <div class="col-lg-4">
            <div class="desk-card-wrapper product-sidebar-card h-100">
                <div class="product-sidebar-head d-flex align-items-center mb-3">
                    <div class="product-avatar-tile mr-3">
                        <i class="dripicons-box u-fs-22px"></i>
                    </div>
                    <div class="u-min-w-0 flex-grow-1">
                        <h5 class="mb-0 font-weight-bold text-truncate" title="{{ $product->name }}">{{ $product->name }}</h5>
                        <div class="small text-muted">{{ $product->code }}</div>
                    </div>
                    <span class="text-warning"><i class="dripicons-star"></i></span>
                </div>

                <div class="product-sidebar-metrics">
                    <div class="psm-row">
                        <span class="psm-lbl"><i class="dripicons-home"></i> Warehouse</span>
                        <strong class="psm-val text-truncate">{{ !empty($filters['warehouse_id']) ? ($warehouses->firstWhere('id', $filters['warehouse_id'])->name ?? 'Selected') : 'All Warehouses' }}</strong>
                    </div>
                    <div class="psm-row">
                        <span class="psm-lbl"><i class="dripicons-scale"></i> Unit</span>
                        <strong class="psm-val">{{ $summary['unit'] }}</strong>
                    </div>
                    <div class="psm-row">
                        <span class="psm-lbl"><i class="dripicons-calendar"></i> Opening Stock</span>
                        <strong class="psm-val">{{ $qty($summary['opening_stock'] ?? 0) }}</strong>
                    </div>
                    <div class="psm-row">
                        <span class="psm-lbl"><i class="dripicons-download"></i> Total Inward</span>
                        <strong class="psm-val text-success">+{{ $qty($summary['total_inward'] ?? 0) }}</strong>
                    </div>
                    <div class="psm-row">
                        <span class="psm-lbl"><i class="dripicons-export"></i> Total Outward</span>
                        <strong class="psm-val text-danger">-{{ $qty($summary['total_outward'] ?? 0) }}</strong>
                    </div>
                </div>

                {{-- Highlighted Closing Stock Box --}}
                <div class="closing-highlight-box mt-3 text-center">
                    <div class="chb-label">Reconciled Final Inventory Balance</div>
                    <div class="chb-number">{{ $qty($summary['final_owned'] ?? 0) }} <small>{{ $summary['unit'] }}</small></div>
                    <div class="chb-subtext">Reconciled balance as of {{ $filters['to'] }}</div>
                </div>

                @if(!empty($convertedToProducts))
                    <div class="mt-3 pt-2 border-top">
                        <div class="small font-weight-bold text-muted text-uppercase mb-1">Converted Product Stock</div>
                        @foreach($convertedToProducts as $cp)
                            <div class="small d-flex justify-content-between align-items-center py-1">
                                <span class="text-truncate">{{ $cp['name'] }}</span>
                                <span class="badge badge-success">{{ $qty($cp['qty']) }} {{ $summary['unit'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabbed Transaction Ledger Table (Mockup Reference Tabs & Purple Table Header) --}}
    <div class="desk-card-wrapper ledger-table-card" data-no-fit>
        <div class="ledger-tab-bar d-flex justify-content-between align-items-center flex-wrap mb-3 border-bottom pb-2">
            <ul class="nav nav-pills ledger-nav-pills" id="inventoryLedgerTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="tab-timeline-btn" data-toggle="pill" href="#tab-timeline-pane" role="tab">
                        <i class="dripicons-list"></i> Timeline (Transactions)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-daily-btn" data-toggle="pill" href="#tab-daily-pane" role="tab">
                        <i class="dripicons-calendar"></i> Daily Stock
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-summary-btn" data-toggle="pill" href="#tab-summary-pane" role="tab">
                        <i class="dripicons-pie-chart"></i> Movement Summary
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-vertical-btn" data-toggle="pill" href="#tab-vertical-pane" role="tab">
                        <i class="dripicons-time-reverse"></i> Detailed Vertical Feed
                    </a>
                </li>
            </ul>
            <div class="ledger-actions text-muted small">
                Showing all chronological balance impact events
            </div>
        </div>

        <div class="tab-content" id="inventoryLedgerContent">
            {{-- TAB 1: Timeline (Transactions) Table with Purple Header --}}
            <div class="tab-pane fade show active" id="tab-timeline-pane" role="tabpanel">
                <div class="table-responsive" data-no-fit>
                    <table class="table table-sm timeline-purple-table" data-no-fit>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date & Time</th>
                                <th>Transaction Type</th>
                                <th>Reference No.</th>
                                <th>Warehouse / Godown</th>
                                <th class="text-right">Inward ({{ $summary['unit'] }})</th>
                                <th class="text-right">Outward ({{ $summary['unit'] }})</th>
                                <th class="text-right">Balance ({{ $summary['unit'] }})</th>
                                <th>Remarks / Party</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Opening Stock Baseline Row --}}
                            <tr class="tr-opening-row">
                                <td>-</td>
                                <td>{{ $filters['from'] }} 00:00</td>
                                <td><span class="badge badge-secondary"><i class="dripicons-media-play"></i> Opening Balance</span></td>
                                <td>&ndash;</td>
                                <td>{{ !empty($filters['warehouse_id']) ? ($warehouses->firstWhere('id', $filters['warehouse_id'])->name ?? 'Warehouse') : 'All Warehouses' }}</td>
                                <td class="text-right">&ndash;</td>
                                <td class="text-right">&ndash;</td>
                                <td class="text-right font-weight-bold text-dark">{{ $qty($summary['opening_stock'] ?? 0) }}</td>
                                <td class="text-muted small">Period Baseline Balance</td>
                            </tr>

                            @forelse($events as $index => $ev)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ $ev['timestamp'] }}</td>
                                <td>
                                    <span class="badge {{ $ev['badge_class'] }}">
                                        <i class="{{ $ev['icon'] }}"></i> {{ $ev['badge'] }}
                                    </span>
                                </td>
                                <td>
                                    <strong>{{ $ev['ref_no'] }}</strong>
                                    @if(!empty($ev['jwo_ref']))
                                        <span class="badge badge-light border">Order: {{ $ev['jwo_ref'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $ev['warehouse'] }}</td>
                                <td class="text-right">
                                    @if($ev['inward_qty'] > 0)
                                        <strong class="text-success">+{{ $qty($ev['inward_qty']) }}</strong>
                                    @else
                                        <span class="text-muted">&ndash;</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($ev['outward_qty'] > 0)
                                        <strong class="text-danger">-{{ $qty($ev['outward_qty']) }}</strong>
                                    @else
                                        <span class="text-muted">&ndash;</span>
                                    @endif
                                </td>
                                <td class="text-right font-weight-bold text-dark">
                                    {{ $qty($ev['running_balance']) }}
                                </td>
                                <td>
                                    <strong>{{ $ev['party'] }}</strong>
                                    <div class="small text-muted">{{ $ev['description'] }}</div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    No transaction movements recorded for {{ $product->name }} within the selected date range.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 2: Daily Stock Summary --}}
            <div class="tab-pane fade" id="tab-daily-pane" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-sm timeline-purple-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Activity Count</th>
                                <th class="text-right">Total Inward ({{ $summary['unit'] }})</th>
                                <th class="text-right">Total Outward ({{ $summary['unit'] }})</th>
                                <th class="text-right">Net Movement</th>
                                <th class="text-right">Day Closing Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dailySummary as $ds)
                            <tr>
                                <td><strong>{{ $ds['date'] }}</strong></td>
                                <td><span class="badge badge-light border">{{ $ds['tx_count'] }} transactions</span></td>
                                <td class="text-right text-success font-weight-bold">+{{ $qty($ds['inward']) }}</td>
                                <td class="text-right text-danger font-weight-bold">-{{ $qty($ds['outward']) }}</td>
                                <td class="text-right font-weight-bold {{ ($ds['inward'] - $ds['outward']) >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ ($ds['inward'] - $ds['outward']) >= 0 ? '+' : '' }}{{ $qty($ds['inward'] - $ds['outward']) }}
                                </td>
                                <td class="text-right font-weight-bold text-dark">{{ $qty($ds['closing']) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No daily transaction activity to summarize.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 3: Movement Summary --}}
            <div class="tab-pane fade" id="tab-summary-pane" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-sm timeline-purple-table">
                        <thead>
                            <tr>
                                <th>Movement Stage</th>
                                <th>Count</th>
                                <th class="text-right">Total Inward Qty</th>
                                <th class="text-right">Total Outward Qty</th>
                                <th class="text-right">Net Qty Effect</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($movementSummary as $ms)
                            <tr>
                                <td>
                                    <span class="badge {{ $ms['badge_class'] }}">
                                        <i class="{{ $ms['icon'] }}"></i> {{ $ms['badge'] }}
                                    </span>
                                </td>
                                <td>{{ $ms['count'] }} entries</td>
                                <td class="text-right text-success font-weight-bold">{{ $ms['inward'] > 0 ? '+'.$qty($ms['inward']) : '&ndash;' }}</td>
                                <td class="text-right text-danger font-weight-bold">{{ $ms['outward'] > 0 ? '-'.$qty($ms['outward']) : '&ndash;' }}</td>
                                <td class="text-right font-weight-bold {{ ($ms['inward'] - $ms['outward']) >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ ($ms['inward'] - $ms['outward']) >= 0 ? '+' : '' }}{{ $qty($ms['inward'] - $ms['outward']) }} {{ $summary['unit'] }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No movement stages recorded.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 4: Detailed Vertical Feed --}}
            <div class="tab-pane fade" id="tab-vertical-pane" role="tabpanel">
                <div class="timeline-track pt-2">
                    @foreach($events as $index => $event)
                    <div class="timeline-node timeline-node-{{ $event['stage'] }}">
                        <div class="node-marker">
                            <span class="node-icon"><i class="{{ $event['icon'] }}"></i></span>
                            <span class="node-step">{{ $index + 1 }}</span>
                        </div>
                        <div class="node-content">
                            <div class="node-header">
                                <div class="node-badges">
                                    <span class="badge {{ $event['badge_class'] }}">{{ $event['badge'] }}</span>
                                    <strong class="node-ref">{{ $event['ref_no'] }}</strong>
                                </div>
                                <span class="node-date"><i class="dripicons-calendar"></i> {{ $event['date'] }}</span>
                            </div>
                            <h4 class="node-title">{{ $event['title'] }}</h4>
                            <p class="node-desc">{{ $event['description'] }}</p>
                            <div class="node-footer-details">
                                <div class="nfd-item">
                                    <span class="nfd-lbl">Party:</span>
                                    <strong class="nfd-val">{{ $event['party'] }}</strong>
                                </div>
                                <div class="nfd-item">
                                    <span class="nfd-lbl">Location:</span>
                                    <span class="nfd-val">{{ $event['warehouse'] }}</span>
                                </div>
                                <div class="nfd-item nfd-qty-box">
                                    <span class="nfd-lbl">Movement Qty:</span>
                                    <strong class="nfd-val qty-highlight">{{ $qty($event['qty']) }} {{ $event['unit'] }}</strong>
                                </div>
                                <div class="nfd-item">
                                    <span class="nfd-lbl">Balance After:</span>
                                    <strong class="nfd-val text-primary">{{ $qty($event['running_balance']) }} {{ $event['unit'] }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @else
    <div class="desk-card-wrapper lot-card text-center py-5">
        <div class="empty-icon-wrap"><i class="dripicons-box"></i></div>
        <h3>Select a Product to View Timeline</h3>
        <p class="text-muted">Choose a product from the filter bar above to trace its entire purchase, job work, and conversion journey.</p>
    </div>
    @endif
</section>
@endsection

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/reports-stock-by-lot.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/inventory-timeline.css') }}">
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('natural-scroll-page');
    document.querySelectorAll('#inventory-timeline-page .table-responsive, #inventory-timeline-page .shell-fit-scroll').forEach(function(box) {
        box.classList.remove('shell-fit-scroll');
        box.style.maxHeight = 'none';
        box.style.overflowY = 'visible';
    });
    @if(!empty($chart) && !empty($chart['labels']))
    const chartLabels = {!! json_encode($chart['labels']) !!};
    const chartBalances = {!! json_encode($chart['balances']) !!};
    const chartGodownSeries = {!! json_encode($chart['godown_series'] ?? $chart['balances']) !!};
    const chartWipSeries = {!! json_encode($chart['wip_series'] ?? []) !!};
    const chartInwardSeries = {!! json_encode($chart['inward_series'] ?? []) !!};
    const chartReturnedSeries = {!! json_encode($chart['returned_series'] ?? []) !!};
    const chartInwards = {!! json_encode($chart['inwards']) !!};
    const chartOutwards = {!! json_encode($chart['outwards']) !!};
    const chartCumulative = {!! json_encode($chart['cumulative']) !!};
    const chartAnnotations = {!! json_encode($chart['annotations']) !!};
    const chartPointsMeta = {!! json_encode($chart['points_meta'] ?? []) !!};
    const unitLabel = {!! json_encode($summary['unit'] ?? 'Pc') !!};

    const canvas = document.getElementById('stockEvolutionChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    // Create purple gradient fill
    const gradient = ctx.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(124, 18, 116, 0.28)');
    gradient.addColorStop(1, 'rgba(124, 18, 116, 0.00)');

    let currentMode = 'multi';

    function buildConfig(mode) {
        if (mode === 'inout') {
            return {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Inward (' + unitLabel + ')',
                            data: chartInwards,
                            backgroundColor: 'rgba(22, 163, 74, 0.85)',
                            borderColor: '#16a34a',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Outward (' + unitLabel + ')',
                            data: chartOutwards,
                            backgroundColor: 'rgba(220, 38, 38, 0.85)',
                            borderColor: '#dc2626',
                            borderWidth: 1,
                            borderRadius: 4
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
                    layout: { padding: { top: 22, right: 8, left: 8, bottom: 4 } },
                    legend: { position: 'top', labels: { boxWidth: 12, fontSize: 11 } },
                    scales: {
                        yAxes: [{
                            ticks: { beginAtZero: true, fontColor: '#64748b' },
                            gridLines: { color: 'rgba(226, 232, 240, 0.6)' }
                        }],
                        xAxes: [{
                            ticks: { fontColor: '#64748b' },
                            gridLines: { display: false }
                        }]
                    }
                }
            };
        }

        if (mode === 'multi') {
            return {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Godown Stock in Hand (' + unitLabel + ')',
                            data: chartGodownSeries,
                            backgroundColor: gradient,
                            borderColor: '#7c1274',
                            borderWidth: 3,
                            pointBackgroundColor: '#7c1274',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            lineTension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Material at Job Worker (WIP) (' + unitLabel + ')',
                            data: chartWipSeries,
                            borderColor: '#f59e0b',
                            backgroundColor: 'transparent',
                            borderWidth: 2.5,
                            borderDash: [5, 4],
                            pointBackgroundColor: '#f59e0b',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            lineTension: 0.3,
                            fill: false
                        },
                        {
                            label: 'Cumulative Inward Purchased (' + unitLabel + ')',
                            data: chartInwardSeries,
                            borderColor: '#16a34a',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: [2, 2],
                            pointBackgroundColor: '#16a34a',
                            pointRadius: 4,
                            lineTension: 0.2,
                            fill: false
                        },
                        {
                            label: 'Cumulative Returned Goods (' + unitLabel + ')',
                            data: chartReturnedSeries,
                            borderColor: '#0284c7',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            pointBackgroundColor: '#0284c7',
                            pointRadius: 4,
                            lineTension: 0.2,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { boxWidth: 12, fontSize: 11, fontColor: '#64748b' }
                    },
                    tooltips: {
                        backgroundColor: '#0f172a',
                        titleFontFamily: 'inherit',
                        titleFontSize: 13,
                        titleFontColor: '#38bdf8',
                        bodyFontFamily: 'inherit',
                        bodyFontSize: 12,
                        bodySpacing: 4,
                        xPadding: 12,
                        yPadding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            title: function(tooltipItems, data) {
                                const idx = tooltipItems[0].index;
                                const meta = chartPointsMeta[idx] || {};
                                return (meta.date || tooltipItems[0].xLabel) + ' · ' + (meta.title || 'Stock Milestone');
                            },
                            label: function(tooltipItem, data) {
                                const dataset = data.datasets[tooltipItem.datasetIndex];
                                return ' ' + dataset.label + ': ' + tooltipItem.yLabel + ' ' + unitLabel;
                            },
                            afterBody: function(tooltipItems, data) {
                                const idx = tooltipItems[0].index;
                                const meta = chartPointsMeta[idx] || {};
                                return meta.why ? [meta.why] : [];
                            }
                        }
                    },
                    scales: {
                        yAxes: [{
                            ticks: { beginAtZero: true, fontColor: '#64748b' },
                            gridLines: { color: 'rgba(226, 232, 240, 0.6)', drawBorder: false }
                        }],
                        xAxes: [{
                            ticks: { fontColor: '#64748b' },
                            gridLines: { display: false }
                        }]
                    }
                }
            };
        }

        const dataSeries = mode === 'cumulative' ? chartCumulative : chartBalances;
        const seriesLabel = mode === 'cumulative' ? 'Cumulative Flow (' + unitLabel + ')' : 'Godown Stock Balance (' + unitLabel + ')';

        return {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: seriesLabel,
                    data: dataSeries,
                    backgroundColor: gradient,
                    borderColor: '#7c1274',
                    borderWidth: 3,
                    pointBackgroundColor: '#7c1274',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    lineTension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                tooltips: {
                    backgroundColor: '#0f172a',
                    titleFontFamily: 'inherit',
                    titleFontSize: 13,
                    titleFontColor: '#38bdf8',
                    bodyFontFamily: 'inherit',
                    bodyFontSize: 12,
                    bodySpacing: 4,
                    xPadding: 12,
                    yPadding: 10,
                    cornerRadius: 6,
                    callbacks: {
                        title: function(tooltipItems, data) {
                            const idx = tooltipItems[0].index;
                            const meta = chartPointsMeta[idx] || {};
                            return (meta.date || tooltipItems[0].xLabel) + ' · ' + (meta.title || 'Stock Milestone');
                        },
                        label: function (tooltipItem, data) {
                            const val = tooltipItem.yLabel;
                            const idx = tooltipItem.index;
                            const note = chartAnnotations[idx] ? ' (' + chartAnnotations[idx] + ')' : '';
                            return ' Balance: ' + val + ' ' + unitLabel + note;
                        },
                        afterBody: function(tooltipItems, data) {
                            const idx = tooltipItems[0].index;
                            const meta = chartPointsMeta[idx] || {};
                            return meta.why ? [meta.why] : [];
                        }
                    }
                },
                scales: {
                    yAxes: [{
                        ticks: { beginAtZero: true, fontColor: '#64748b' },
                        gridLines: { color: 'rgba(226, 232, 240, 0.6)', drawBorder: false }
                    }],
                    xAxes: [{
                        ticks: { fontColor: '#64748b' },
                        gridLines: { display: false }
                    }]
                }
            }
        };
    }

    let chartInstance = new Chart(ctx, buildConfig('multi'));

    // Mode Toggle Buttons
    const btnMulti = document.getElementById('btnChartMultiProcess');
    const btnBalance = document.getElementById('btnChartBalance');
    const btnInOut = document.getElementById('btnChartInOut');
    const btnCumulative = document.getElementById('btnChartCumulative');

    function setMode(mode, activeBtn) {
        currentMode = mode;
        [btnMulti, btnBalance, btnInOut, btnCumulative].filter(Boolean).forEach(b => b.classList.remove('active'));
        if (activeBtn) activeBtn.classList.add('active');

        chartInstance.destroy();
        chartInstance = new Chart(ctx, buildConfig(mode));
    }

    if (btnMulti) btnMulti.addEventListener('click', () => setMode('multi', btnMulti));
    if (btnBalance) btnBalance.addEventListener('click', () => setMode('balance', btnBalance));
    if (btnInOut) btnInOut.addEventListener('click', () => setMode('inout', btnInOut));
    if (btnCumulative) btnCumulative.addEventListener('click', () => setMode('cumulative', btnCumulative));

    // Interactive Milestone Selection Handlers
    window.selectTimelineMilestone = function(idx, elem) {
        const meta = chartPointsMeta[idx] || {};

        document.querySelectorAll('.axis-milestone-node').forEach(n => n.classList.remove('is-active'));
        if (elem) elem.classList.add('is-active');

        // Dynamically ensure callout stays 100% inside the card (zero "out of box" overflow)
        if (elem) {
            const callout = elem.querySelector('.axis-inplace-callout');
            const rail = document.querySelector('.interactive-axis-rail');
            if (callout && rail) {
                const calloutHeight = callout.offsetHeight || 135;
                rail.style.paddingBottom = Math.max(200, calloutHeight + 40) + 'px';
            }
        }

        // Update Spotlight Inspector
        const badge = document.getElementById('spotlightBadge');
        const title = document.getElementById('spotlightTitle');
        const metaSpan = document.getElementById('spotlightMeta');
        const whyText = document.getElementById('spotlightWhy');
        const godownVal = document.getElementById('spotlightGodown');
        const wipVal = document.getElementById('spotlightWip');
        const partyVal = document.getElementById('spotlightParty');
        const refVal = document.getElementById('spotlightRef');

        if (badge) badge.textContent = meta.title || 'Milestone Inspector';
        if (title) title.textContent = (meta.ref_no && meta.ref_no !== '-' ? meta.ref_no + ' · ' : '') + (meta.party || 'Stock Movement');
        if (metaSpan) metaSpan.textContent = 'Date: ' + (meta.date || '');
        if (whyText) whyText.textContent = meta.why || 'Stock movement recorded on this date.';
        if (godownVal) godownVal.textContent = (meta.godown_balance !== undefined ? meta.godown_balance : '-') + ' ' + unitLabel;
        if (wipVal) wipVal.textContent = (meta.wip_balance !== undefined ? meta.wip_balance : '0') + ' ' + unitLabel;
        if (partyVal) partyVal.textContent = meta.party || '-';
        if (refVal) refVal.textContent = meta.ref_no || '-';

        highlightTableRow(meta.ref_no);
    };

    window.selectTimelineStage = function(stageKey, elem) {
        document.querySelectorAll('.sleek-stage-card').forEach(c => c.classList.remove('is-stage-active'));
        if (elem) elem.classList.add('is-stage-active');

        const matchedIdx = chartPointsMeta.findIndex(m => m.stage === stageKey);
        if (matchedIdx !== -1) {
            const node = document.querySelector('.axis-milestone-node[data-index="' + matchedIdx + '"]');
            selectTimelineMilestone(matchedIdx, node);
        }
    };

    function highlightTableRow(refNo) {
        if (!refNo || refNo === '-' || refNo === 'Reconciled') return;
        document.querySelectorAll('.timeline-purple-table tbody tr').forEach(row => {
            row.classList.remove('table-row-selected');
            if (row.textContent.includes(refNo)) {
                row.classList.add('table-row-selected');
            }
        });
    }

    // Canvas click to select node
    canvas.onclick = function(evt) {
        const activePoints = chartInstance.getElementsAtEvent(evt);
        if (activePoints.length > 0) {
            const idx = activePoints[0]._index;
            const node = document.querySelector('.axis-milestone-node[data-index="' + idx + '"]');
            selectTimelineMilestone(idx, node);
        }
    };
    @endif

    // Eliminate any parent height or overflow clamping
    function removeTimelineClamps() {
        document.querySelectorAll('#inventory-timeline-page .desk-card-wrapper, #inventory-timeline-page .combined-lifecycle-card, #inventory-timeline-page .interactive-axis-rail').forEach(el => {
            el.style.overflow = 'visible';
            el.style.overflowY = 'visible';
            el.style.maxHeight = 'none';
            el.style.height = 'auto';
            el.classList.remove('shell-fit-scroll');
        });
    }
    removeTimelineClamps();
    window.addEventListener('resize', removeTimelineClamps);
    setTimeout(removeTimelineClamps, 150);
});
</script>
@endpush
