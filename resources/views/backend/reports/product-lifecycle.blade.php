@extends('backend.layout.main')
@section('title', 'Product Lifecycle & Conversion Tree - zoloERP')

@php
    $qty = static fn ($val) => number_format((float) $val, 2, '.', ',');
@endphp

@section('content')
<div id="product-lifecycle-page" class="container-fluid px-3 py-2">
    {{-- Unified Inventory Command Bar --}}
    <x-inventory-command-bar active="lifecycle" view="lifecycle">
        <div class="d-flex align-items-center u-gap-6px">
            <a href="{{ route('inventory-reports.stock-by-lot') }}" class="btn btn-sm btn-outline-secondary" title="View Tabular Stock Balances">
                <i class="dripicons-stack mr-1"></i> Stock Balances
            </a>
            @if($hasProduct && !empty($activeProduct['product_id']))
            <a href="{{ route('inventory-reports.timeline', ['product_id' => $activeProduct['product_id']]) }}" class="btn btn-sm btn-outline-primary" title="View Step-by-Step Inventory Timeline">
                <i class="dripicons-time-reverse mr-1"></i> Stock Timeline
            </a>
            @endif
        </div>
    </x-inventory-command-bar>

    {{-- Filter Toolbar Card --}}
    <div class="desk-filter-card d-print-none mb-3">
        <form method="GET" action="{{ route('inventory-reports.lifecycle') }}" class="filter-form d-flex flex-wrap align-items-end u-gap-10px">
            <div class="filter-field">
                <label for="plc-from">From</label>
                <input id="plc-from" type="date" name="from" class="form-control f-date" value="{{ $filters['from'] }}" required>
            </div>
            <div class="filter-field">
                <label for="plc-to">To</label>
                <input id="plc-to" type="date" name="to" class="form-control f-date" value="{{ $filters['to'] }}" required>
            </div>
            <div class="filter-field">
                <label for="plc-warehouse">Warehouse / Godown</label>
                <select id="plc-warehouse" name="warehouse_id" class="form-control f-pick">
                    <option value="">All branch warehouses</option>
                    @foreach($warehouses as $w)
                    <option value="{{ $w->id }}" @selected(($filters['warehouse_id'] ?? '') == $w->id)>{{ $w->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="plc-search" title="Filter by Product Lifecycle Code (PLC), name, or SKU">Search / PLC</label>
                <input id="plc-search" type="text" name="search" class="form-control f-pick" value="{{ $filters['search'] ?? '' }}" placeholder="e.g. 5IM, Z58, Cotton...">
            </div>
            <input type="hidden" name="product_id" id="hiddenProductId" value="{{ $activeProduct['product_id'] ?? '' }}">
            <input type="hidden" name="plc" id="hiddenPlc" value="{{ $filters['plc'] ?? '' }}">
            <div class="filter-actions d-flex u-gap-6px">
                <button type="submit" class="btn btn-primary"><i class="dripicons-experiment"></i> Apply</button>
                <a href="{{ route('inventory-reports.lifecycle') }}" class="btn btn-outline-secondary"><i class="dripicons-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>

    @if($errors->any())<div class="alert alert-danger py-1 mb-2" role="alert">{{ $errors->first() }}</div>@endif

    {{-- Root Product Catalog Bar ("List all the nodes first whichever product has lifecycle, give an highlight to identify that has the plc") --}}
    <div class="lifecycle-catalog-bar d-print-none">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <div>
                <h6 class="mb-0 font-weight-bold text-dark d-inline-flex align-items-center">
                    <i class="dripicons-network mr-2 text-primary"></i> Lifecycle Product Catalog
                    <span class="badge catalog-count-badge ml-2" id="catalogVisibleBadge">
                        {{ count($catalog) }} Active Products
                    </span>
                </h6>
                <div class="text-muted small">
                    Click any root product node below to expand its full branching transformation tree ("The Full Picture").
                </div>
            </div>
            <div class="d-flex align-items-center u-gap-6px mt-2 mt-md-0">
                <div class="input-group input-group-sm">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white"><i class="dripicons-search text-muted"></i></span>
                    </div>
                    <input type="text" id="catalogLiveSearch" class="form-control form-control-sm" placeholder="Filter PLC or name..." oninput="handleCatalogFilter()">
                </div>
            </div>
        </div>

        {{-- Quick PLC & Category Filter Pills --}}
        <div class="catalog-pills-row">
            <span class="text-muted small font-weight-bold mr-1"><i class="dripicons-tags mr-1"></i> PLC:</span>
            <button type="button" class="catalog-filter-btn @if(empty($filters['plc']) || strtoupper($filters['plc']) === 'ALL') active @endif" data-plc="ALL" onclick="filterCatalogByPlc('ALL', this)">
                ALL
            </button>
            @foreach($distinctPlcs as $pCode)
            <button type="button" class="catalog-filter-btn @if(strtoupper($filters['plc'] ?? '') === strtoupper($pCode)) active @endif" data-plc="{{ $pCode }}" onclick="filterCatalogByPlc('{{ $pCode }}', this)">
                {{ $pCode }}
            </button>
            @endforeach
            <span class="catalog-divider mx-2 my-1"></span>
            <button type="button" class="catalog-filter-btn active" data-category="all" onclick="filterCatalogByCategory('all', this)">
                All Categories <span class="badge">{{ count($catalog) }}</span>
            </button>
            <button type="button" class="catalog-filter-btn" data-category="converted" onclick="filterCatalogByCategory('converted', this)">
                <i class="dripicons-swap mr-1"></i> Converted <span class="badge">{{ count(array_filter($catalog, fn($c) => $c['category'] === 'converted')) }}</span>
            </button>
            <button type="button" class="catalog-filter-btn" data-category="wip" onclick="filterCatalogByCategory('wip', this)">
                <i class="dripicons-gear mr-1"></i> In Job Work WIP <span class="badge">{{ count(array_filter($catalog, fn($c) => $c['category'] === 'wip')) }}</span>
            </button>
            <button type="button" class="catalog-filter-btn" data-category="ready" onclick="filterCatalogByCategory('ready', this)">
                <i class="dripicons-home mr-1"></i> Ready Stock <span class="badge">{{ count(array_filter($catalog, fn($c) => $c['category'] === 'ready')) }}</span>
            </button>
        </div>

        {{-- Horizontal Auto-Scrollable Catalog Track (Slim Pill Design) --}}
        <div class="lifecycle-catalog-track-wrap" id="catalogTrackWrapper">
            <div class="lifecycle-product-cards-grid" id="catalogCardsGrid">
                @foreach($catalog as $item)
                @php
                    $isActiveItem = ($activeProduct['product_id'] ?? 0) === $item['product_id'];
                @endphp
                <a href="{{ route('inventory-reports.lifecycle', ['product_id' => $item['product_id'], 'from' => $filters['from'], 'to' => $filters['to'], 'warehouse_id' => $filters['warehouse_id'] ?? '']) }}" 
                   class="product-catalog-card catalog-pill-card @if($isActiveItem) active-product @endif"
                   data-pid="{{ $item['product_id'] }}"
                   data-plc="{{ strtoupper($item['plc']) }}"
                   data-category="{{ $item['category'] }}"
                   data-name="{{ strtolower($item['product_name']) }}"
                   data-code="{{ strtolower($item['product_code']) }}"
                   title="Select {{ $item['product_name'] }} [PLC: {{ $item['plc'] }}] to view full tree">
                    <div class="catalog-pill-icon">
                        @if($item['category'] === 'converted')
                            <i class="dripicons-swap"></i>
                        @elseif($item['category'] === 'wip')
                            <i class="dripicons-gear"></i>
                        @else
                            <i class="dripicons-download"></i>
                        @endif
                    </div>
                    <div class="catalog-pill-body">
                        <div class="catalog-pill-top">
                            <span class="catalog-pill-tag">PLC: {{ $item['plc'] }}</span>
                            <span class="catalog-pill-code">{{ $item['product_code'] }}</span>
                        </div>
                        <div class="catalog-pill-title" title="{{ $item['product_name'] }}">{{ $item['product_name'] }}</div>
                        <div class="catalog-pill-subtext">
                            <span>Bought: <strong>{{ $qty($item['purchased_qty']) }}</strong></span>
                            <span>· Sent: <strong>{{ $qty($item['dispatched_qty']) }}</strong></span>
                        </div>
                    </div>
                    <div class="catalog-pill-qty">
                        <span class="catalog-pill-badge" title="Physical Godown Stock">
                            {{ $qty($item['in_godown_qty']) }}
                        </span>
                        <span class="catalog-pill-status @if($item['category'] === 'wip') status-wip @elseif($item['category'] === 'converted') status-converted @else status-ready @endif">
                            {{ $item['status_label'] }}
                        </span>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </div>

    @if(!$hasProduct || empty($tree))
    <div class="alert alert-info py-4 text-center">
        <i class="dripicons-information mr-1 u-fs-18px"></i> No product lifecycle transactions found for the selected filter period. Try expanding the date range.
    </div>
    @else

    {{-- Canonical KPI Metric Strip for Active Product (AGENTS.md Section 4.4) --}}
    <div class="kpi-summary-strip d-print-none lifecycle-kpi-wrap">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">PURCHASED INWARD</div>
                <div class="kpi-number">{{ $qty($tree['totals']['purchased_inward']) }} <small class="u-fs-12px">{{ $tree['totals']['unit'] }}</small></div>
                <div class="kpi-subtext">Total raw material bought</div>
            </div>
            <div class="z-icon-tile z-tone-primary">
                <i class="dripicons-download u-fs-18px"></i>
            </div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-label">GIVEN TO JOB WORK</div>
                <div class="kpi-number">{{ $qty($tree['totals']['given_to_jobwork']) }} <small class="u-fs-12px">{{ $tree['totals']['unit'] }}</small></div>
                <div class="kpi-subtext">Dispatched via challans (DC)</div>
            </div>
            <div class="z-icon-tile z-tone-warning">
                <i class="dripicons-export u-fs-18px"></i>
            </div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-label">RETURNED / CONVERTED</div>
                <div class="kpi-number">{{ $qty($tree['totals']['returned_converted']) }} <small class="u-fs-12px">{{ $tree['totals']['unit'] }}</small></div>
                <div class="kpi-subtext">Inwarded back (same/converted)</div>
            </div>
            <div class="z-icon-tile z-tone-success">
                <i class="dripicons-checkmark u-fs-18px"></i>
            </div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-label">PROCESS SHRINKAGE</div>
                <div class="kpi-number">{{ $qty($tree['totals']['process_shrinkage']) }} <small class="u-fs-12px">{{ $tree['totals']['unit'] }}</small></div>
                <div class="kpi-subtext">Processing / dyeing loss</div>
            </div>
            <div class="z-icon-tile z-tone-danger">
                <i class="dripicons-warning u-fs-18px"></i>
            </div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-label">CURRENT GODOWN STOCK</div>
                <div class="kpi-number">{{ $qty($tree['totals']['current_godown_stock']) }} <small class="u-fs-12px">{{ $tree['totals']['unit'] }}</small></div>
                <div class="kpi-subtext">
                    @if($tree['totals']['converted_godown_stock'] > 0)
                        + {{ $qty($tree['totals']['converted_godown_stock']) }} {{ $tree['totals']['unit'] }} Finished Goods
                    @else
                        Available physical ready stock
                    @endif
                </div>
            </div>
            <div class="z-icon-tile z-tone-info">
                <i class="dripicons-home u-fs-18px"></i>
            </div>
        </div>
    </div>

    {{-- "The Full Picture" Interactive Node-Link Canvas (Matching Reference) --}}
    <div class="desk-card-wrapper p-3 mb-3" data-no-fit>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0 font-weight-bold text-dark d-inline-flex align-items-center">
                    <i class="dripicons-network mr-2 text-primary"></i> Lifecycle & Transformation Branching Tree
                    <span class="badge tree-active-product-badge ml-2">
                        {{ $activeProduct['product_name'] }} [PLC: {{ $activeProduct['plc'] }}]
                    </span>
                </h5>
                <div class="text-muted small mt-1">
                    Complete transformation lineage: Trace from purchased inward raw material into multiple job worker dispatches, finished product conversions, process shrinkage, and warehouse ready stock.
                </div>
            </div>
            <div class="d-flex align-items-center u-gap-6px mt-2 mt-md-0">
                <span class="badge badge-light border text-muted px-2 py-1">
                    <i class="dripicons-pulse mr-1 text-success"></i> 3-Level Branching Active
                </span>
            </div>
        </div>

        {{-- Canvas Container with SVG Vector Rails --}}
        <div class="lifecycle-canvas-wrapper" id="lifecycleCanvasWrapper">
            <svg class="lifecycle-svg-canvas" id="lifecycleSvgCanvas"></svg>

            <div class="tree-columns-container" id="treeColumnsContainer">
                {{-- Column 1: Level 1 - Root Raw Material Input --}}
                <div class="tree-column tree-col-root">
                    <div class="node-capsule node-raw active-node" id="node-root" onclick="inspectNode('root', '{{ addslashes($activeProduct['product_name']) }}', 'PURCHASED INWARD', '{{ $qty($tree['totals']['purchased_inward']) }} {{ $tree['totals']['unit'] }}', '{{ $activeProduct['first_purchase']['ref_no'] ?? 'Direct Inward' }}', '{{ $activeProduct['first_purchase']['supplier_name'] ?? 'Supplier' }}', '{{ $activeProduct['first_purchase']['date'] ?? $filters['from'] }}', '{{ route('inventory-reports.timeline', ['product_id' => $activeProduct['product_id']]) }}')">
                        <div class="node-port port-out" id="port-root-out"></div>
                        <div class="node-icon-circle"><i class="dripicons-download"></i></div>
                        <div class="node-body">
                            <span class="node-stage-tag">Root Raw Material (Inward)</span>
                            <span class="node-title" title="{{ $activeProduct['product_name'] }}">{{ $activeProduct['product_name'] }}</span>
                            <span class="node-subtext">{{ $activeProduct['first_purchase']['supplier_name'] ?? 'Supplier Inward' }}</span>
                        </div>
                        <span class="node-qty-badge">{{ $qty($tree['totals']['purchased_inward']) }} {{ $tree['totals']['unit'] }}</span>
                    </div>
                </div>

                {{-- Column 2: Level 2 - Operational Branches & Godown Stock --}}
                <div class="tree-column tree-col-branches">
                    @foreach($tree['branches'] as $bIdx => $branch)
                        @if($branch['type'] === 'dispatch')
                        <div class="node-capsule node-dispatch" 
                             id="node-{{ $branch['branch_id'] }}"
                             onclick="inspectNode('dispatch', '{{ addslashes($branch['title']) }}', 'JOB WORK DISPATCH', '{{ $qty($branch['dispatched_qty']) }} {{ $branch['unit'] }}', '{{ $branch['dispatch_ref'] }}', '{{ $branch['processor_name'] }}', '{{ $branch['business_date'] }}', '{{ route('inventory-reports.timeline', ['product_id' => $activeProduct['product_id']]) }}')">
                            <div class="node-port port-in" id="port-in-{{ $branch['branch_id'] }}"></div>
                            <div class="node-port port-out" id="port-out-{{ $branch['branch_id'] }}"></div>
                            <div class="node-icon-circle"><i class="dripicons-gear"></i></div>
                            <div class="node-body">
                                <span class="node-stage-tag">Job Work Dispatch</span>
                                <span class="node-title" title="{{ $branch['processor_name'] }}">{{ $branch['processor_name'] }}</span>
                                <span class="node-subtext">{{ $branch['dispatch_ref'] }} · {{ $branch['business_date'] }}</span>
                            </div>
                            <span class="node-qty-badge">{{ $qty($branch['dispatched_qty']) }} {{ $branch['unit'] }}</span>
                        </div>
                        @else
                        <div class="node-capsule node-godown" 
                             id="node-{{ $branch['branch_id'] }}"
                             onclick="inspectNode('godown', '{{ addslashes($branch['title']) }}', 'PHYSICAL GODOWN STOCK', '{{ $qty($branch['qty']) }} {{ $branch['unit'] }}', 'Physical Warehouse', '{{ $branch['warehouse_name'] }}', '{{ $filters['to'] }}', '{{ $branch['timeline_url'] }}')">
                            <div class="node-port port-in" id="port-in-{{ $branch['branch_id'] }}"></div>
                            <div class="node-icon-circle"><i class="dripicons-home"></i></div>
                            <div class="node-body">
                                <span class="node-stage-tag">Raw Stock in Godown</span>
                                <span class="node-title" title="{{ $branch['warehouse_name'] }}">{{ $branch['warehouse_name'] }}</span>
                                <span class="node-subtext">{{ $branch['subtext'] }}</span>
                            </div>
                            <span class="node-qty-badge">{{ $qty($branch['qty']) }} {{ $branch['unit'] }}</span>
                        </div>
                        @endif
                    @endforeach
                </div>

                {{-- Column 3: Level 3 - Sub-Branches (Converted Products & Shrinkage) --}}
                <div class="tree-column tree-col-subbranches">
                    @foreach($tree['branches'] as $branch)
                        @foreach($branch['sub_branches'] ?? [] as $sub)
                            @if($sub['type'] === 'converted_product')
                            <div class="node-capsule node-converted" 
                                 id="node-{{ $sub['sub_branch_id'] }}"
                                 onclick="inspectNode('converted', '{{ addslashes($sub['output_product_name']) }}', 'CONVERTED FINISHED GOOD', '{{ $qty($sub['output_qty']) }} {{ $sub['unit'] }}', '{{ $sub['receipt_ref'] }}', '{{ $branch['processor_name'] }}', '{{ $sub['date'] }}', '{{ $sub['timeline_url'] }}')">
                                <div class="node-port port-in" id="port-in-{{ $sub['sub_branch_id'] }}" data-parent-branch="{{ $branch['branch_id'] }}"></div>
                                <div class="node-icon-circle"><i class="dripicons-swap"></i></div>
                                <div class="node-body">
                                    <span class="node-stage-tag">Converted Finished Good</span>
                                    <span class="node-title" title="{{ $sub['output_product_name'] }}">{{ $sub['output_product_name'] }}</span>
                                    <span class="node-subtext">{{ $sub['receipt_ref'] }} · Ready: {{ $qty($sub['ready_godown_stock']) }} {{ $sub['unit'] }}</span>
                                </div>
                                <span class="node-qty-badge">{{ $qty($sub['output_qty']) }} {{ $sub['unit'] }}</span>
                            </div>

                            @if(($sub['loss_qty'] ?? 0) > 0)
                            <div class="node-capsule node-shrinkage" 
                                 id="node-loss-{{ $sub['sub_branch_id'] }}"
                                 onclick="inspectNode('loss', 'Process Shrinkage & Loss', 'PROCESS LOSS', '{{ $qty($sub['loss_qty']) }} {{ $sub['unit'] }}', '{{ $sub['receipt_ref'] }}', '{{ $branch['processor_name'] }}', '{{ $sub['date'] }}', '{{ $sub['timeline_url'] }}')">
                                <div class="node-port port-in" id="port-in-loss-{{ $sub['sub_branch_id'] }}" data-parent-branch="{{ $branch['branch_id'] }}"></div>
                                <div class="node-icon-circle"><i class="dripicons-warning"></i></div>
                                <div class="node-body">
                                    <span class="node-stage-tag">Process Shrinkage ({{ $sub['loss_pct'] }}%)</span>
                                    <span class="node-title">Process Loss</span>
                                    <span class="node-subtext">Weight / finishing tolerance</span>
                                </div>
                                <span class="node-qty-badge">{{ $qty($sub['loss_qty']) }} {{ $sub['unit'] }}</span>
                            </div>
                            @endif

                            @elseif($sub['type'] === 'wip')
                            <div class="node-capsule node-wip" 
                                 id="node-{{ $sub['sub_branch_id'] }}"
                                 onclick="inspectNode('wip', 'Pending at Processor (WIP)', 'PROCESSOR WIP', '{{ $qty($sub['pending_qty']) }} {{ $sub['unit'] }}', 'Awaiting Return', '{{ $sub['processor_name'] }}', '{{ $sub['date'] }}', '{{ route('inventory-reports.timeline', ['product_id' => $activeProduct['product_id']]) }}')">
                                <div class="node-port port-in" id="port-in-{{ $sub['sub_branch_id'] }}" data-parent-branch="{{ $branch['branch_id'] }}"></div>
                                <div class="node-icon-circle"><i class="dripicons-hourglass"></i></div>
                                <div class="node-body">
                                    <span class="node-stage-tag">Pending Return (WIP)</span>
                                    <span class="node-title">{{ $sub['processor_name'] }}</span>
                                    <span class="node-subtext">Awaiting delivery challan return</span>
                                </div>
                                <span class="node-qty-badge">{{ $qty($sub['pending_qty']) }} {{ $sub['unit'] }}</span>
                            </div>

                            @elseif($sub['type'] === 'godown_leaf')
                            <div class="node-capsule node-godown" 
                                 id="node-{{ $sub['sub_branch_id'] }}"
                                 onclick="inspectNode('godown', 'Physical Godown Ready Stock', 'READY GODOWN STOCK', '{{ $qty($sub['qty']) }} {{ $sub['unit'] }}', 'Main Godown', '{{ $sub['warehouse_name'] }}', '{{ $filters['to'] }}', '{{ $sub['timeline_url'] }}')">
                                <div class="node-port port-in" id="port-in-{{ $sub['sub_branch_id'] }}" data-parent-branch="{{ $branch['branch_id'] }}"></div>
                                <div class="node-icon-circle"><i class="dripicons-home"></i></div>
                                <div class="node-body">
                                    <span class="node-stage-tag">Warehouse Stock</span>
                                    <span class="node-title">{{ $sub['warehouse_name'] }}</span>
                                    <span class="node-subtext">Physical in-hand balance</span>
                                </div>
                                <span class="node-qty-badge">{{ $qty($sub['qty']) }} {{ $sub['unit'] }}</span>
                            </div>
                            @endif
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Node Inspection Spotlight Drawer --}}
        <div class="lifecycle-spotlight-drawer mt-3" id="spotlightDrawer">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                <div>
                    <h6 class="mb-0 font-weight-bold text-dark d-inline-flex align-items-center" id="spotlightTitle">
                        <i class="dripicons-network mr-2 text-primary"></i> <span id="spotlightTitleText">{{ $activeProduct['product_name'] }}</span>
                    </h6>
                    <span class="badge badge-primary ml-2 px-2 py-1" id="spotlightStageBadge">ROOT RAW MATERIAL</span>
                </div>
                <div class="d-flex align-items-center u-gap-6px mt-2 mt-md-0">
                    <a href="{{ route('inventory-reports.timeline', ['product_id' => $activeProduct['product_id']]) }}" class="btn btn-xs btn-outline-primary" id="spotlightTimelineBtn">
                        <i class="dripicons-time-reverse mr-1"></i> View Stock Timeline ➔
                    </a>
                </div>
            </div>

            <div class="spotlight-grid">
                <div class="spotlight-box">
                    <div class="spotlight-label">Impact Quantity</div>
                    <div class="spotlight-value text-primary" id="spotlightQty">{{ $qty($tree['totals']['purchased_inward']) }} {{ $tree['totals']['unit'] }}</div>
                    <div class="spotlight-sub" id="spotlightQtySub">Total Inward Received</div>
                </div>
                <div class="spotlight-box">
                    <div class="spotlight-label">Document Reference</div>
                    <div class="spotlight-value text-dark" id="spotlightDoc">{{ $activeProduct['first_purchase']['ref_no'] ?? 'Direct Inward' }}</div>
                    <div class="spotlight-sub" id="spotlightDocSub">Official Voucher</div>
                </div>
                <div class="spotlight-box">
                    <div class="spotlight-label">Party / Location</div>
                    <div class="spotlight-value text-dark" id="spotlightParty">{{ $activeProduct['first_purchase']['supplier_name'] ?? 'Supplier Inward' }}</div>
                    <div class="spotlight-sub" id="spotlightPartySub">Supplier / Processor</div>
                </div>
                <div class="spotlight-box">
                    <div class="spotlight-label">Transaction Date</div>
                    <div class="spotlight-value text-dark" id="spotlightDate">{{ $activeProduct['first_purchase']['date'] ?? $filters['from'] }}</div>
                    <div class="spotlight-sub" id="spotlightDateSub">Posting Date</div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/product-lifecycle.css') }}">
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    drawTreeConnections();
    initCatalogHoverAutoScroll();

    // Re-draw on window resize or canvas resize
    window.addEventListener('resize', debounce(drawTreeConnections, 100));

    const canvasWrapper = document.getElementById('lifecycleCanvasWrapper');
    if (canvasWrapper && window.ResizeObserver) {
        new ResizeObserver(debounce(drawTreeConnections, 80)).observe(canvasWrapper);
    }
});

function initCatalogHoverAutoScroll() {
    const wrapper = document.getElementById('catalogTrackWrapper');
    if (!wrapper) return;

    let targetScrollLeft = wrapper.scrollLeft;
    let isHovering = false;
    let animFrame = null;

    function step() {
        const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
        if (maxScroll > 0) {
            const diff = targetScrollLeft - wrapper.scrollLeft;
            if (Math.abs(diff) > 0.5) {
                wrapper.scrollLeft += diff * 0.08;
            } else {
                wrapper.scrollLeft = targetScrollLeft;
            }
        }
        if (isHovering || Math.abs(targetScrollLeft - wrapper.scrollLeft) > 1) {
            animFrame = requestAnimationFrame(step);
        } else {
            animFrame = null;
        }
    }

    function ensureLoop() {
        if (!animFrame) animFrame = requestAnimationFrame(step);
    }

    wrapper.addEventListener('mouseenter', () => {
        isHovering = true;
        targetScrollLeft = wrapper.scrollLeft;
        ensureLoop();
    });

    wrapper.addEventListener('mouseleave', () => {
        isHovering = false;
    });

    wrapper.addEventListener('mousemove', (e) => {
        const rect = wrapper.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const width = rect.width;
        const maxScroll = wrapper.scrollWidth - width;
        if (maxScroll <= 0) return;

        // Ratio from 0 to 1 across the catalog track width
        const ratio = Math.max(0, Math.min(1, mouseX / width));

        // Smooth gradual response with edge deadzone buffers
        let adjustedRatio = ratio;
        if (ratio < 0.12) {
            adjustedRatio = 0;
        } else if (ratio > 0.88) {
            adjustedRatio = 1;
        } else {
            adjustedRatio = (ratio - 0.12) / 0.76;
        }

        targetScrollLeft = adjustedRatio * maxScroll;
        ensureLoop();
    });

    // Seamless mouse wheel horizontal panning without visible scrollbar
    wrapper.addEventListener('wheel', (e) => {
        const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
        if (maxScroll <= 0) return;

        if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
            e.preventDefault();
            targetScrollLeft = Math.max(0, Math.min(maxScroll, targetScrollLeft + (e.deltaY * 1.5)));
            ensureLoop();
        } else if (Math.abs(e.deltaX) > 0) {
            e.preventDefault();
            targetScrollLeft = Math.max(0, Math.min(maxScroll, targetScrollLeft + (e.deltaX * 1.5)));
            ensureLoop();
        }
    }, { passive: false });
}

function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function drawTreeConnections() {
    const svg = document.getElementById('lifecycleSvgCanvas');
    const container = document.getElementById('lifecycleCanvasWrapper');
    const columnsContainer = document.getElementById('treeColumnsContainer');
    if (!svg || !container || !columnsContainer) return;

    // Dynamically adjust SVG canvas dimensions to exact content dimensions
    const cWidth = Math.max(container.scrollWidth, columnsContainer.offsetWidth + 64);
    const cHeight = Math.max(container.scrollHeight, columnsContainer.offsetHeight + 48);
    svg.setAttribute('width', cWidth);
    svg.setAttribute('height', cHeight);
    svg.style.width = cWidth + 'px';
    svg.style.height = cHeight + 'px';

    // Reset SVG paths
    svg.innerHTML = '';
    const containerRect = container.getBoundingClientRect();
    const scrollLeft = container.scrollLeft || 0;
    const scrollTop = container.scrollTop || 0;

    // 1. Connect Root Node -> Each Level 2 Branch
    const rootPortOut = document.getElementById('port-root-out');
    if (!rootPortOut) return;

    const rootRect = rootPortOut.getBoundingClientRect();
    const x1 = rootRect.left - containerRect.left + scrollLeft + (rootRect.width / 2);
    const y1 = rootRect.top - containerRect.top + scrollTop + (rootRect.height / 2);

    const level2PortsIn = document.querySelectorAll('.tree-col-branches .node-port.port-in');
    level2PortsIn.forEach(portIn => {
        const inRect = portIn.getBoundingClientRect();
        const x2 = inRect.left - containerRect.left + scrollLeft + (inRect.width / 2);
        const y2 = inRect.top - containerRect.top + scrollTop + (inRect.height / 2);

        const isGodown = portIn.closest('.node-godown') !== null;
        const lineClass = isGodown ? 'line-godown' : 'line-primary';

        drawBezierPath(svg, x1, y1, x2, y2, lineClass);
    });

    // 2. Connect Each Level 2 Dispatch Node -> Matching Level 3 Sub-Branches
    const level2PortsOut = document.querySelectorAll('.tree-col-branches .node-port.port-out');
    level2PortsOut.forEach(portOut => {
        const branchId = portOut.id.replace('port-out-', '');
        const outRect = portOut.getBoundingClientRect();
        const bx1 = outRect.left - containerRect.left + scrollLeft + (outRect.width / 2);
        const by1 = outRect.top - containerRect.top + scrollTop + (outRect.height / 2);

        // Find Level 3 ports matching this branch
        const matchingSubPorts = document.querySelectorAll(`.tree-col-subbranches .node-port[data-parent-branch="${branchId}"]`);
        matchingSubPorts.forEach(subPort => {
            const subRect = subPort.getBoundingClientRect();
            const bx2 = subRect.left - containerRect.left + scrollLeft + (subRect.width / 2);
            const by2 = subRect.top - containerRect.top + scrollTop + (subRect.height / 2);

            let lineClass = 'line-success';
            if (subPort.closest('.node-shrinkage')) lineClass = 'line-warning';
            else if (subPort.closest('.node-wip')) lineClass = 'line-primary';
            else if (subPort.closest('.node-godown')) lineClass = 'line-godown';

            drawBezierPath(svg, bx1, by1, bx2, by2, lineClass);
        });
    });
}

function drawBezierPath(svg, x1, y1, x2, y2, lineClass) {
    const dx = Math.abs(x2 - x1) * 0.5;
    const d = `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`;

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', d);
    path.setAttribute('class', `tree-svg-line ${lineClass}`);
    svg.appendChild(path);
}

function inspectNode(stageKey, title, stageName, qty, doc, party, date, timelineUrl) {
    document.querySelectorAll('.node-capsule').forEach(n => n.classList.remove('active-node'));

    // Highlight clicked node
    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('active-node');
    }

    const titleEl = document.getElementById('spotlightTitleText');
    if (titleEl) titleEl.textContent = title;

    const stageBadge = document.getElementById('spotlightStageBadge');
    if (stageBadge) {
        stageBadge.textContent = stageName;
        stageBadge.className = 'badge px-2 py-1 ml-2 ' + 
            (stageKey === 'root' ? 'badge-info' : 
            (stageKey === 'dispatch' ? 'badge-warning' : 
            (stageKey === 'converted' ? 'badge-success' : 
            (stageKey === 'loss' ? 'badge-danger' : 'badge-primary'))));
    }

    const qtyEl = document.getElementById('spotlightQty');
    if (qtyEl) qtyEl.textContent = qty;

    const docEl = document.getElementById('spotlightDoc');
    if (docEl) docEl.textContent = doc || '—';

    const partyEl = document.getElementById('spotlightParty');
    if (partyEl) partyEl.textContent = party || '—';

    const dateEl = document.getElementById('spotlightDate');
    if (dateEl) dateEl.textContent = date || '—';

    const btn = document.getElementById('spotlightTimelineBtn');
    if (btn && timelineUrl) {
        btn.href = timelineUrl;
    }
}

let activeCatalogPlc = '{{ strtoupper($filters["plc"] ?? "ALL") }}';
let activeCatalogCat = 'all';

function filterCatalogByPlc(plc, btn) {
    activeCatalogPlc = plc.toUpperCase();
    document.querySelectorAll('.catalog-filter-btn[data-plc]').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    applyCatalogFilter();
}

function filterCatalogByCategory(cat, btn) {
    activeCatalogCat = cat;
    document.querySelectorAll('.catalog-filter-btn[data-category]').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    applyCatalogFilter();
}

function handleCatalogFilter() {
    applyCatalogFilter();
}

function applyCatalogFilter() {
    const query = (document.getElementById('catalogLiveSearch')?.value || '').toLowerCase().trim();
    const cards = document.querySelectorAll('.product-catalog-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const plc = (card.dataset.plc || '').toUpperCase();
        const cat = card.dataset.category || '';
        const name = card.dataset.name || '';
        const code = card.dataset.code || '';

        const matchPlc = (activeCatalogPlc === 'ALL' || plc === activeCatalogPlc);
        let matchCat = true;
        if (activeCatalogCat === 'converted') matchCat = (cat === 'converted');
        else if (activeCatalogCat === 'wip') matchCat = (cat === 'wip');
        else if (activeCatalogCat === 'ready') matchCat = (cat === 'ready');

        let matchSearch = true;
        if (query.length > 0) {
            matchSearch = name.includes(query) || code.includes(query) || plc.includes(query);
        }

        if (matchPlc && matchCat && matchSearch) {
            card.classList.remove('d-none');
            visibleCount++;
        } else {
            card.classList.add('d-none');
        }
    });

    const badge = document.getElementById('catalogVisibleBadge');
    if (badge) {
        badge.textContent = `${visibleCount} Products`;
    }
}
</script>
@endpush
