@extends('backend.layout.main') 

@section('navbar-center')
<div class="comm-navbar-center-wrap d-flex align-items-center justify-content-between w-100 u-minw-0 u-gap-8px">
    <!-- Left Title & Mode Toggles -->
    <div class="comm-navbar-title-group">
        <span class="badge comm-title-badge">
            <i class="dripicons-download text-success"></i> Purchase Command Center
        </span>
        <span class="u-c-xcbd5e1 u-fs-12px u-fw-600">/</span>
        <span id="doc-title-text" class="comm-doc-title">New Purchase Bill</span>
        <span id="doc-breadcrumb-mode" class="badge badge-primary doc-mode-pill">New</span>

    </div>

    <!-- Center Navigation Register Pills (Only on ultra-wide screens) -->
    <div class="d-none d-xxl-flex align-items-center u-gap-3px u-shrink-0">
        <a class="comm-nav-btn comm-nav-btn-secondary active" id="tab-all-purchases" href="javascript:void(0)"><i class="dripicons-list"></i> All</a>
        <a class="comm-nav-btn comm-nav-btn-secondary" href="{{ route('goods-received-notes.index') }}"><i class="dripicons-box"></i> Supplier GRN</a>
        <a class="comm-nav-btn comm-nav-btn-secondary" href="{{ route('transfers.index') }}"><i class="dripicons-swap"></i> Transfers</a>
        <a class="comm-nav-btn comm-nav-btn-secondary" href="{{ config('commercial.enabled') && config('compliance.enabled') ? url('/compliance/returns') : route('return-purchase.index') }}"><i class="dripicons-return"></i> Returns</a>
    </div>

    <!-- Right Controls: Live Total, Details, Bill List, Add New, Hide Bar -->
    <div class="comm-navbar-actions">
        <div class="header-grand-total-badge px-2">
            <span class="u-fs-9px u-c-warning u-fw-700">TOTAL:</span>
            <span id="header-grand-total-display">₹ 0.00</span>
        </div>
        <button type="button" class="comm-nav-btn comm-nav-btn-secondary" id="btn-navbar-workspace-toggle" title="Toggle between Split View (Voucher + List) and Full Width Register">
            <span id="nav-mode-icon">⛶</span> <span id="nav-mode-text">Full Width</span>
        </button>
        <button type="button" class="comm-nav-btn comm-nav-btn-secondary" id="btn-header-toggle-list" title="Toggle Purchase List Panel">
            <i class="dripicons-list"></i> Bill list
        </button>
        @unless(config('commercial.enabled'))
        <button type="button" class="comm-nav-btn comm-nav-btn-primary" id="btn-top-new" title="Add New Purchase">
            <i class="dripicons-plus"></i> New
        </button>
        @endunless
        <button type="button" class="comm-nav-btn comm-nav-btn-secondary btn-toggle-topbar" id="btn-toggle-topbar" title="Hide top bar (Focus mode • Shortcut: Ctrl+Shift+F)">
            ▲ Hide
        </button>
    </div>
</div>
@endsection

@section('content')

<x-success-message key="message" />
<x-error-message key="not_permitted" />

<script>
    document.documentElement.classList.add('commercial-screen-lock');
    document.body.classList.add('commercial-screen-lock');
</script>
<div id="comm-progress-bar"></div>

<section class="commercial-workspace-view">
@include('backend.partials.command-center-nav', ['kind' => 'purchase'])
    <!-- 2-Column Command Center Grid: Main Entry Workspace + Side Bill List Panel -->
    <div class="comm-split-grid" id="comm-split-grid">
        <!-- Main: Purchase Entry Workspace (Matching Screenshot 3) -->
        <div class="comm-entry-workspace" id="comm-entry-workspace">
            <form id="purchase-entry-form" method="POST" action="{{ route('purchases.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" id="entry-form-method" value="POST">
                <input type="hidden" name="purchase_id" id="edit-purchase-id" value="">
                <input type="hidden" name="status" id="purchase-status-val" value="1">
                <input type="hidden" name="exchange_rate" id="exchange-rate-val" value="{{ $currency->exchange_rate ?? 1 }}">
                <input type="hidden" name="currency_id" id="currency-id-val" value="{{ $currency->id ?? 1 }}">
                <input type="hidden" name="paying_method" id="input-paying-method" value="Credit">
                <input type="hidden" name="total_qty" id="hidden-total-qty" value="0">
                <input type="hidden" name="total_discount" id="hidden-total-discount" value="0">
                <input type="hidden" name="total_tax" id="hidden-total-tax" value="0">
                <input type="hidden" name="total_cost" id="hidden-total-cost" value="0">
                <input type="hidden" name="order_tax" id="hidden-order-tax" value="0">
                <input type="hidden" name="grand_total" id="hidden-grand-total" value="0">
                <input type="hidden" name="payment_status" id="hidden-payment-status" value="1">
                <input type="hidden" name="paid_amount" id="hidden-paid-amount" value="0">
                @if(config('commercial.enabled'))<input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">@endif

                <!-- 2. Primary Document Fields (Ultra-Compact 2-Row Layout, ~52px height, >60% reduced) -->
                <div class="desk-card doc-primary-fields compact-fields mb-1">
                    <!-- Compact Row 1: Bill No, Supplier Inv No, Dates, Party & Inline Info -->
                    <div class="fields-compact-row doc-row-a">
                        <div class="field-compact-item u-w-140px u-shrink-0">
                            <label for="reference_no" data-help="Your own number for this purchase, given by the Series. Leave blank to auto-number.|PUR-00002">Our Bill No</label>
                            <input type="text" id="reference_no" name="reference_no" class="form-control" placeholder="Auto series" autocomplete="off">
                        </div>
                        <div class="field-compact-item u-w-165px u-shrink-0">
                            <label for="supplier_invoice_no" data-help="Invoice number printed on the supplier's paper bill. Used to match GSTR-2B and stop double entry.|SMF/1187">Supplier Bill No</label>
                            <input type="text" id="supplier_invoice_no" name="supplier_invoice_no" class="form-control" placeholder="Paper invoice #">
                        </div>
                        <div class="field-compact-item u-w-125px u-shrink-0">
                            <label for="supplier_invoice_date" data-help="Date printed on the supplier's invoice. GST input credit is claimed against this date.|24-10-2026">Bill Date</label>
                            <input type="date" id="supplier_invoice_date" name="supplier_invoice_date" class="form-control" value="{{ date('Y-m-d') }}">
                        </div>
                        <div class="field-compact-item u-w-125px u-shrink-0">
                            <label for="created_at" data-help="Date the goods enter your books. Stock and accounts post on this date and the credit due date counts from it.|Today, or the day goods arrived">Entry Date</label>
                            <input type="date" id="created_at" name="created_at" class="form-control" value="{{ date('Y-m-d') }}">
                        </div>
                        <div class="field-compact-item u-w-250px u-shrink-0">
                            <div class="d-flex align-items-center justify-content-between">
                                <label for="supplier_id" data-help="Supplier you bought from. Picking one fills GSTIN, address and the supplier's standard terms (payment, items, GST, remarks).|Sri Murugan Flexi Packs">Party *</label>
                                <button type="button" class="btn btn-link p-0 btn-open-party-modal u-fs-9_5px u-c-primary u-fw-700 u-tdec-none u-b-none u-bg-none u-cur-pointer" id="btn-quick-new-party" data-party-type="supplier">+ New Party</button>
                            </div>
                            <select id="supplier_id" name="supplier_id" class="form-control selectpicker" data-live-search="true" data-container="body" title="Select Supplier" required>
                                @foreach($lims_supplier_list as $supplier)
                                    <option value="{{ $supplier->id }}"
                                        data-tax-no="{{ $supplier->tax_no ?: ($supplier->vat_number ?? '') }}"
                                        data-address="{{ $supplier->address ?? '' }}"
                                        data-city="{{ $supplier->city ?? '' }}"
                                        data-state="{{ $supplier->state ?? '' }}"
                                        data-postal="{{ $supplier->postal_code ?? '' }}"
                                        data-credit-days="{{ $supplier->credit_days ?? 0 }}"
                                        data-defaults="{{ json_encode($supplier->purchase_defaults ?: (object) []) }}">
                                        {{ $supplier->name }} ({{ $supplier->company_name ?? 'Individual' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-compact-item doc-party-details">
                        <label data-help="Read from the supplier master. Credit = the supplier's standard credit days. Edit the supplier to change them.|GSTIN 33AAPFS9902L1ZM · Tiruppur">Party details</label>
                        <div class="party-info-compact-inline u-d-flex" id="party-info-card">
                            <span class="u-fs-10px u-fw-700 u-c-text-2 u-ws-nowrap">GSTIN: <span class="party-gst-badge" id="party-gst-badge">—</span></span>
                            <span id="party-address-text" class="u-fs-10_5px u-c-text-2 u-ov-hidden u-tov-ellipsis u-ws-nowrap u-flex-1">Select a party to see GSTIN and address</span>
                            <span class="badge u-bg-info-soft u-c-info u-fs-9_5px u-fw-600 u-ws-nowrap" id="party-credit-badge">Credit: 0 days</span>
                        </div>
                        </div>
                    </div>

                    <!-- Compact Row 2: Tax Classification, Series, Warehouse, Quick Logistics Note -->
                    <div class="fields-compact-row doc-row-b">
                        <div class="field-compact-item doc-seg-item">
                            <label data-help="Cash = paid now, no due date. Credit = pay later within the Terms days.|Credit for monthly suppliers, Cash for counter buys">Payment</label>
                            <div class="pill-segmented-compact doc-segment" role="group" aria-label="Payment Mode">
                                <button type="button" class="segment-btn" id="pill-mode-cash" data-mode="Cash">Cash</button>
                                <button type="button" class="segment-btn active" id="pill-mode-credit" data-mode="Credit">Credit</button>
                            </div>
                        </div>
                        <div class="field-compact-item doc-seg-item">
                            <label data-help="Product = stock goods, adds to inventory. Service = no stock (freight, labour, repairs). Mixed = both on one bill.|Product for pouches and bottles">Items</label>
                            <div class="pill-segmented-compact doc-segment" role="group" aria-label="Product Mode">
                                <button type="button" class="segment-btn active" data-nature="product">Product</button>
                                <button type="button" class="segment-btn" data-nature="service">Service</button>
                                <button type="button" class="segment-btn" data-nature="mixed">Mixed</button>
                            </div>
                        </div>
                        <div class="field-compact-item u-w-230px u-shrink-0">
                            <label for="purchase_type_id">Tax Classification *</label>
                            <select id="purchase_type_id" name="purchase_type_id" class="form-control">
                                <option value="0" data-tax-rate="" data-is-multi="1">GST • Multiple rates</option>
                                @foreach($purchaseTypes as $pt)
                                    <option value="{{ $pt->id }}"
                                        data-tax-rate="{{ (float)$pt->tax_rate }}"
                                        data-code="{{ $pt->code }}"
                                        data-is-multi="{{ (in_array($pt->code, ['PMULTI', 'PIMULTI']) || (float)$pt->tax_rate == 0) ? '1' : '0' }}">
                                        {{ $pt->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-compact-item u-w-160px u-shrink-0">
                            <label for="series_id" data-help="Numbering series that gives Our Bill No. Use separate series to keep e.g. imports apart.|Purchase → PUR-00001, PUR-00002">Series</label>
                            <select id="series_id" name="series_id" class="form-control">
                                <option value="0">Purchase</option>
                                @foreach($documentSeries as $ds)
                                    <option value="{{ $ds->id }}">{{ $ds->prefix ?? $ds->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-compact-item u-w-230px u-shrink-0">
                            <label for="form_warehouse_id" data-help="Godown where the received stock is added.|Main godown">{{ \App\Services\Industry\IndustryProfileService::label('warehouse', 'Warehouse') }} *</label>
                            <select id="form_warehouse_id" name="warehouse_id" class="form-control" required>
                                @foreach($lims_warehouse_list as $wh)
                                    <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-compact-item doc-terms">
                        <label for="header-credit-days" data-help="Credit days for this bill only; due date = entry date + days. Starts from the supplier's standard days. Cash bills have no credit.|30">Terms</label>
                        <div class="terms-compact-inline">
                            <span id="terms-credit">Credit <input type="number" id="header-credit-days" class="terms-days-input" min="0" max="3650" step="1" inputmode="numeric" placeholder="—" aria-label="Credit days"> days</span>
                            <span id="terms-cash" hidden><strong>Cash</strong> · paid now, no due date</span>
                            <a href="javascript:void(0)" id="btn-quick-logistics" class="u-c-primary u-fw-600 u-ml-auto u-tdec-none u-ws-nowrap">
                                + Transport &amp; Remarks &rarr;
                            </a>
                        </div>
                        </div>
                    </div>
                </div>

                <!-- 3. ITEMS Section (Expanded to Fill All Available Viewport Space) -->
                <div class="desk-card items-container mb-1 u-r-6px u-p-0 u-ov-hidden u-flex-1-1-auto u-d-flex u-dir-column u-minh-0">
                    <div class="items-section-header">
                        <div class="items-counter-group u-d-flex u-items-center u-gap-6px">
                            <span class="items-title u-fw-700 u-fs-11_5px u-c-text">ITEMS</span>
                            <span class="items-meta-badge text-muted u-fs-10_5px" id="items-meta-count">0 line(s)</span>
                        </div>

                        <div class="items-controls-group">
                            <div class="density-segmented">
                                <button type="button" class="density-btn" data-density="compact">Compact</button>
                                <button type="button" class="density-btn active" data-density="cozy">Cozy</button>
                                <button type="button" class="density-btn" data-density="large">Large</button>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary comm-items-btn" id="btn-multi-item" data-toggle="modal" data-target="#multi-item-modal">
                                <i class="dripicons-menu"></i> Multi item
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary comm-items-btn" id="btn-create-item-modal" data-toggle="modal" data-target="#quick-create-item-modal">
                                <i class="dripicons-plus"></i> Create item
                            </button>
                            <button type="button" class="btn btn-sm btn-primary comm-items-btn comm-items-btn-primary" id="btn-add-item-row">
                                <i class="dripicons-plus"></i> Add row
                            </button>
                        </div>
                    </div>

                    <!-- Items Grid Table (Expands to bottom summary bar) -->
                    <div class="table-responsive u-flex-1-1-auto u-ovy-auto u-minh-200px u-h-100pct u-m-0">
                        <table class="desk-grid-table cozy table table-sm mb-0 u-w-100pct" id="order-table">
                            <thead>
                                <tr class="u-bg-ink u-c-xffffff u-fs-11px">
                                    <th class="u-w-36px u-ta-center">#</th>
                                    <th class="u-minw-240px">ITEM</th>
                                    <th title="Purchase type" class="u-w-110px">TYPE</th>
                                    <th class="u-w-75px">UNIT</th>
                                    <th class="u-w-95px u-ta-right">RATE</th>
                                    <th class="u-w-70px u-ta-center">QTY</th>
                                    <th class="u-w-100px u-ta-right">AMOUNT</th>
                                    <th class="u-w-90px">TAX</th>
                                    <th class="u-w-105px u-ta-right">TOTAL</th>
                                    <th class="u-w-65px u-ta-center">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="order-table-body">
                                <!-- Integrated In-Table Item Search Row -->
                                <tr class="table-search-row" id="table-search-row">
                                    <td class="u-ta-center u-va-middle u-w-36px">
                                        <i class="fa fa-barcode u-fs-15px u-c-primary" title="Scan Barcode (Alt+I)"></i>
                                    </td>
                                    <td class="u-va-middle">
                                        <div class="table-search-input-wrap">
                                            <i class="fa fa-search u-pos-absolute u-left-9px u-top-50pct u-tf-translatey-50pct u-fs-11px u-c-primary u-pe-none"></i>
                                            <input type="text" id="lims_productcodeSearch" class="form-control" placeholder="Scan barcode or enter item code / name... (Alt+I)" autocomplete="off">
                                            <span class="table-search-f2-badge">Alt+I</span>
                                        </div>
                                    </td>
                                    <td colspan="8" class="u-va-middle u-fs-11px u-c-primary-hover u-fst-italic">
                                        <span class="d-none d-md-inline">&larr; Scan barcode or type above to add item to voucher. Press Enter to add.</span>
                                    </td>
                                </tr>
                                <tr class="empty-placeholder-row">
                                    <td colspan="10" class="text-center text-muted py-4 u-fs-12px">
                                        No items added yet. Scan barcode or search above, or click "+ Add row".
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>


                <!-- 4. Fixed Bottom Action & Summary Bar (Matching Screenshot 3) -->
                <div class="desk-summary-bottom-bar">
                    <div class="d-flex align-items-center u-gap-10px">
                        <button type="button" class="btn btn-outline-secondary comm-bottom-btn" id="btn-bottom-charges">
                            <span>Charges & remarks</span>
                            <span class="badge badge-light ml-1" id="charges-badge-count">0</span>
                        </button>
                        <span class="text-muted d-none d-lg-inline u-fs-11px" id="remarks-summary-preview">Remarks: Add transport, LR and bale details</span>
                    </div>

                    <div class="bottom-action-buttons">
                        <button type="button" class="btn btn-outline-secondary comm-bottom-btn" id="btn-form-discard">
                            <i class="dripicons-clockwise"></i> Discard
                        </button>
                        <button type="button" class="btn btn-outline-secondary comm-bottom-btn" id="btn-form-save-as">
                            <i class="dripicons-copy"></i> Save as
                        </button>
                        <button type="submit" class="btn btn-primary comm-bottom-btn comm-bottom-btn-primary" id="btn-form-save">
                            <i class="dripicons-document-edit"></i> Save
                        </button>
                        <button type="button" class="btn btn-outline-secondary comm-bottom-btn" id="btn-form-review">
                            Review
                        </button>
                    </div>

                    <div class="bottom-summary-totals">
                        <div class="u-ta-right">
                            <div class="u-fs-9_5px u-fw-700 u-c-text-muted u-ls-0_04em">NET</div>
                            <div id="display-net-amount" class="u-fs-13px u-fw-700 u-c-text">₹ 0.00</div>
                        </div>
                        <div class="u-ta-right">
                            <div class="u-fs-9_5px u-fw-700 u-c-text-muted u-ls-0_04em">GST / TAX</div>
                            <div id="display-tax-amount" class="u-fs-13px u-fw-700 u-c-text">₹ 0.00</div>
                        </div>
                        <div class="u-ta-right u-bl-1px-solid-border-strong u-pl-14px">
                            <div class="u-fs-9_5px u-fw-800 u-c-warning u-ls-0_04em">GRAND TOTAL</div>
                            <div id="display-grand-total" class="u-fs-15px u-fw-800 u-c-warning">₹ 0.00</div>
                        </div>
                    </div>
                </div>

                <!-- Hidden inputs for Optech details -->
                <div style="display:none">
                    <input type="text" name="bale_no" id="hidden-bale-no">
                    <input type="number" name="no_of_bales" id="hidden-no-of-bales">
                    <input type="number" name="bundle_count" id="hidden-bundle-count">
                    <input type="text" name="vehicle_number" id="hidden-vehicle-number">
                    <input type="text" name="lr_no" id="hidden-lr-no">
                    <input type="date" name="lr_date" id="hidden-lr-date">
                    <input type="text" name="transporter_name" id="hidden-transporter-name">
                    <input type="text" name="station_to" id="hidden-station-to">
                    <input type="text" name="order_no" id="hidden-order-no">
                    <input type="number" name="credit_days" id="hidden-credit-days">
                    <input type="number" name="shipping_cost" id="hidden-shipping-cost" step="0.0001" value="0">
                    <input type="number" name="order_discount" id="hidden-order-discount" step="0.0001" value="0">
                    <input type="hidden" name="capitalise_services" id="hidden-capitalise-services" value="">
                    <input type="number" name="cash_discount_percent" id="hidden-cd-percent" step="0.01">
                    <input type="number" name="cash_discount_days" id="hidden-cd-days">
                    <input type="hidden" name="landed_cost_method" id="hidden-landed-cost-method" value="value">
                    <input type="text" name="note" id="hidden-note">
                    <select name="account_id" id="hidden-account-id">
                        @foreach($lims_account_list as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>

        <!-- Right / Dockable Side Panel: Purchase List with Screenshot 2 Filters & Dropdowns -->
        <aside class="comm-drawer-card desk-bill-list-panel" id="comm-drawer" aria-label="Transaction List Panel">
            <div class="comm-drawer-header">
                <div class="comm-drawer-title-wrap">
                    <h3 class="comm-drawer-title"><i class="dripicons-view-list text-success"></i> Purchase list</h3>
                </div>
                <div class="comm-drawer-tools">
                    <button type="button" class="side-icon-btn" id="btn-panel-fullscreen" title="Toggle Full Width Register View">
                        <span id="fullscreen-icon">⛶</span>
                        <span class="dock-tooltip" id="fullscreen-tooltip">Full Width</span>
                    </button>
                    <button type="button" class="side-icon-btn" id="btn-dock-toggle" title="Arrange Panel Side (Dock Left / Dock Right)">
                        <span id="dock-icon">⇄</span>
                        <span class="dock-tooltip" id="dock-label">Dock Left</span>
                    </button>
                    <button type="button" class="side-icon-btn text-muted" id="close-drawer-btn" title="Collapse Panel">✕</button>
                </div>
            </div>

            <div class="comm-drawer-body">
                <!-- Toolbar Export & Action Icons (Screenshot 2) -->
                <div class="side-toolbar-actions">
                    <button type="button" class="side-tool-btn text-danger" id="side-export-pdf" title="Export PDF"><i class="fa fa-file-pdf-o"></i></button>
                    <button type="button" class="side-tool-btn text-success" id="side-export-excel" title="Export for Excel (CSV)"><i class="fa fa-file-excel-o"></i></button>
                    <button type="button" class="side-tool-btn text-info" id="side-export-csv" title="Export CSV"><i class="fa fa-file-text-o"></i></button>
                    <button type="button" class="side-tool-btn text-primary" id="side-export-print" title="Print Register"><i class="fa fa-print"></i></button>
                    <button type="button" class="side-tool-btn text-danger" id="side-filter-reset" title="Reset Filters"><i class="fa fa-times"></i></button>
                </div>

                <!-- Search Filter -->
                <div class="side-panel-search">
                    <input type="text" id="side-search-input" class="form-control" placeholder="Find a purchase..." autocomplete="off">
                </div>

                <!-- Series / Bill Number Filter -->
                <div class="side-panel-filter-label">BILL NUMBER</div>
                <div class="side-panel-series-filter">
                    <input type="text" id="side-filter-series" class="form-control" placeholder="Series or edited number" autocomplete="off">
                </div>

                <!-- Filter Tabs -->
                <div class="side-filter-tabs">
                    <button type="button" class="side-tab active" data-filter="all">All</button>
                    <button type="button" class="side-tab" data-filter="draft">Draft</button>
                    <button type="button" class="side-tab" data-filter="date">Date</button>
                    <button type="button" class="side-tab" data-filter="range">Range</button>
                </div>

                <!-- Date Inputs (revealed if Date/Range selected) -->
                <div class="side-date-picker-box u-p-4px-0" id="side-date-picker-box" style="display:none">
                    <input aria-label="From date" type="date" id="side-filter-date-val" class="form-control u-h-28px u-fs-11px" value="{{ date('Y-m-d') }}" />
                    <input aria-label="To date" type="date" id="side-filter-date-end" class="form-control u-h-28px u-fs-11px" style="display:none" value="{{ date('Y-m-d') }}" />
                </div>

                <!-- Dropdown Filters (Moved from Top Bar) -->
                <div class="side-dropdown-filters">
                    <div class="side-filter-group">
                        <label>{{ \App\Services\Industry\IndustryProfileService::label('warehouse', 'Warehouse') }}</label>
                        <select id="side-filter-warehouse" class="form-control">
                            <option value="0">All Warehouse</option>
                            @foreach($lims_warehouse_list as $wh)
                                <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="side-filter-group">
                        <label>Purchase Status</label>
                        <select id="side-filter-status" class="form-control">
                            <option value="0">All</option>
                            <option value="1">Received</option>
                            <option value="2">Partial</option>
                            <option value="3">Pending</option>
                            <option value="4">Ordered</option>
                        </select>
                    </div>
                    <div class="side-filter-group">
                        <label>Payment Status</label>
                        <select id="side-filter-payment" class="form-control">
                            <option value="0">All</option>
                            <option value="1">Due</option>
                            <option value="2">Paid</option>
                        </select>
                    </div>
                </div>

                <!-- Recent Purchases List -->
                <div class="side-list-container" id="side-bill-list">
                    @php
                        // The server checks again on the reversal form; this only hides a link the user cannot use.
                        $canReverseBills = false;
                        if (config('commercial.enabled')) {
                            try {
                                $canReverseBills = app(\App\Services\Commercial\CommercialPermission::class)->allows('purchases-delete',
                                    app(\App\Services\Platform\CompanyContextResolver::class)->forActor(), (int) auth()->id());
                            } catch (\Illuminate\Auth\Access\AuthorizationException) {}
                        }
                    @endphp
                    @forelse($recent_bills as $bill)
                        @php
                            $isPaid = ($bill->grand_total > 0 && $bill->paid_amount >= $bill->grand_total);
                            $isPartial = ($bill->paid_amount > 0 && $bill->paid_amount < $bill->grand_total);
                            $isDraft = (!config('commercial.enabled') && $bill->status == 3);
                            $statusLabel = $bill->reversed_at ? 'Reversed' : ($isDraft ? 'Draft' : ($isPaid ? 'Paid' : ($isPartial ? 'Partial' : 'Due')));
                            $statusClass = $isDraft ? 'draft' : ($isPaid ? 'paid' : ($isPartial ? 'partial' : 'due'));
                            $supplierName = $bill->supplier->name ?? 'Default Supplier';
                        @endphp
                        <div class="side-bill-card" data-reversed="{{ $bill->reversed_at ? 1 : 0 }}" data-bill-id="{{ $bill->id }}" data-ref="{{ strtolower($bill->reference_no ?? '') }}" data-party="{{ strtolower($supplierName) }}" data-status="{{ strtolower($statusLabel) }}" data-status-id="{{ $bill->status }}" data-payment-status-id="{{ $isPaid ? 2 : 1 }}" data-warehouse-id="{{ $bill->warehouse_id }}" data-date="{{ substr($bill->created_at ?? '', 0, 10) }}">
                            <div class="side-card-top">
                                <strong class="side-card-ref">{{ $bill->reference_no ?? ('#'.$bill->id) }}</strong>
                                <span class="side-card-amount">₹ {{ number_format($bill->grand_total ?? 0, 2) }}</span>
                            </div>
                            <div class="side-card-party">
                                <i class="dripicons-user u-fs-10px u-c-text-muted"></i> {{ $supplierName }}
                            </div>
                            <div class="side-card-bottom">
                                <span class="side-card-date"><i class="dripicons-calendar u-fs-10px"></i> {{ substr($bill->created_at ?? '', 0, 10) }}</span>
                                <span class="side-card-status {{ $statusClass }}">{{ $statusLabel }}</span>
                            </div>
                            <div class="side-card-actions">
                                @unless($bill->reversed_at)
                                <a href="javascript:void(0)" class="side-action-btn edit btn-side-load-edit" data-id="{{ $bill->id }}" title="Edit this purchase in main area">
                                    <i class="dripicons-document-edit"></i> Edit
                                </a>
                                @endunless
                                <a href="javascript:void(0)" class="side-action-btn view btn-side-view" data-id="{{ $bill->id }}" title="View purchase details">
                                    <i class="dripicons-preview"></i> View
                                </a>
                                <a href="javascript:void(0)" class="side-action-btn print btn-side-print" data-id="{{ $bill->id }}" title="Print purchase">
                                    <i class="dripicons-print"></i> Print
                                </a>
                                <a href="javascript:void(0)" class="side-action-btn copy btn-side-copy" data-id="{{ $bill->id }}" title="Copy this purchase into a new draft" hidden>
                                    <i class="dripicons-copy"></i> Copy
                                </a>
                                @if($canReverseBills && !$bill->reversed_at && !$isDraft && $bill->branch_id)
                                <a href="{{ url('/commercial/purchase/'.$bill->id.'/reverse') }}" class="side-action-btn reverse" title="Reverse: cancel this purchase, its stock goes out and its journal is reversed; the bill stays in history" aria-label="Reverse purchase">
                                    <i class="dripicons-return"></i>
                                </a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="side-empty-state">
                            <p>No purchases match these filters</p>
                        </div>
                    @endforelse
                </div>

                <!-- Purchase Orders Side List (Active in Purchase Orders mode) -->
                <div class="side-list-container d-none" id="side-order-list">
                    <div class="side-orders-header d-flex justify-content-between align-items-center mb-2 px-1">
                        <label class="mb-0 small text-muted font-weight-bold">
                            <input type="checkbox" id="side-orders-all-toggle"> Include closed
                        </label>
                        <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold" id="btn-side-new-order">
                            <i class="dripicons-plus"></i> New Order
                        </button>
                    </div>
                    <div id="side-orders-status" class="small text-muted mb-2 px-1" role="status"></div>
                    <div id="side-orders-cards"></div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Full Width Register View (Initial Setup Before Changing to New UI) -->
    <div class="comm-fullwidth-register" id="fullwidth-register-view" style="display:none">
        <!-- Top Return & Action Bar -->
        <div class="comm-fullwidth-header-strip">
            <div class="d-flex align-items-center gap-2">
                <h5 class="mb-0 font-weight-bold u-fs-14px u-c-text">
                    <i class="dripicons-list text-success mr-1"></i> {{ __('db.Purchase') }} Register &amp; Inward GRN
                </h5>
                <span class="badge badge-secondary u-fs-10px u-p-3px-7px">Full Register View</span>
            </div>
            <div class="d-flex align-items-center u-gap-8px">
                <button type="button" class="btn btn-sm btn-primary py-1 px-3 font-weight-bold u-bg-primary u-bc-primary u-c-xffffff" id="btn-switch-voucher-mode" title="Return to Split View (Voucher Entry + Bill List)">
                    <i class="dripicons-view-thumb mr-1"></i> ⇄ Split View
                </button>
                <button type="button" class="btn btn-sm btn-outline-success py-1 px-3 font-weight-bold" id="btn-fullwidth-new" title="Create New Purchase Bill">
                    <i class="dripicons-plus"></i> + New Purchase
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" id="toggle-filter" title="Toggle Filters">
                    <i class="dripicons-experiment"></i> Filter
                </button>
            </div>
        </div>

        <!-- KPI Summary Strip -->
        <div class="comm-kpi-strip">
            <div class="comm-kpi-box">
                <div class="comm-kpi-info">
                    <span class="comm-kpi-title">{{ __("Today's Purchases") }}</span>
                    <span class="comm-kpi-value">{{ $todayPurchasesCount ?? 0 }} <small class="u-fs-11px u-fw-600 u-c-text-muted">({{ number_format($todayPurchasesAmount ?? 0, 2) }})</small></span>
                </div>
                <div class="comm-kpi-icon u-bg-success-soft u-c-success">
                    <i class="dripicons-cart"></i>
                </div>
            </div>
            <div class="comm-kpi-box">
                <div class="comm-kpi-info">
                    <span class="comm-kpi-title">{{ __("Paid Outflow") }}</span>
                    <span class="comm-kpi-value u-c-info">{{ number_format($totalPaid ?? 0, 2) }}</span>
                </div>
                <div class="comm-kpi-icon u-bg-info-soft u-c-info">
                    <i class="dripicons-checkmark"></i>
                </div>
            </div>
            <div class="comm-kpi-box">
                <div class="comm-kpi-info">
                    <span class="comm-kpi-title">{{ __("Due Payables") }}</span>
                    <span class="comm-kpi-value u-c-warning">{{ number_format($totalDue ?? 0, 2) }}</span>
                </div>
                <div class="comm-kpi-icon u-bg-warning-soft u-c-warning">
                    <i class="dripicons-warning"></i>
                </div>
            </div>
            <div class="comm-kpi-box">
                <div class="comm-kpi-info">
                    <span class="comm-kpi-title">{{ __("Total Invoiced") }}</span>
                    <span class="comm-kpi-value">{{ number_format(($totalPaid ?? 0) + ($totalDue ?? 0), 2) }}</span>
                </div>
                <div class="comm-kpi-icon u-bg-primary-soft u-c-primary">
                    <i class="dripicons-archive"></i>
                </div>
            </div>
        </div>

        <!-- Main Register Card -->
        <div class="comm-register-card">
            <!-- Sleek Inline Filter Bar -->
            <div class="comm-table-filter-bar" id="filter-card">
                <div class="filter-item">
                    <label><i class="dripicons-calendar text-muted"></i></label>
                    <input type="text" class="daterangepicker-field form-control" value="{{$starting_date}} To {{$ending_date}}" required />
                    <input type="hidden" name="starting_date" value="{{$starting_date}}" />
                    <input type="hidden" name="ending_date" value="{{$ending_date}}" />
                </div>
                <div class="filter-item @if(\Auth::user()->role_id > 2){{'d-none'}}@endif">
                    <label>{{__('db.Warehouse')}}:</label>
                    <select id="warehouse_id" name="warehouse_id" class="form-control u-w-130px">
                        <option value="0">{{__('db.All Warehouse')}}</option>
                        @foreach($lims_warehouse_list as $warehouse)
                            <option value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-item">
                    <label>{{__('db.Purchase Status')}}:</label>
                    <select id="purchase-status" class="form-control u-w-110px" name="purchase_status">
                        <option value="0">{{__('db.All')}}</option>
                        <option value="1">{{__('db.Recieved')}}</option>
                        <option value="2">{{__('db.Partial')}}</option>
                        <option value="3">{{__('db.Pending')}}</option>
                        <option value="4" @selected(request('view') === 'orders')>{{__('db.Ordered')}}</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>{{__('db.Payment Status')}}:</label>
                    <select id="payment-status" class="form-control u-w-100px" name="payment_status">
                        <option value="0">{{__('db.All')}}</option>
                        <option value="1">{{__('db.Due')}}</option>
                        <option value="2">{{__('db.Paid')}}</option>
                    </select>
                </div>
                <button type="button" class="btn btn-sm btn-outline-success ml-auto" id="btn-quick-refresh" title="Reload Register">
                    <i class="dripicons-clockwise"></i>
                </button>
            </div>

            <!-- Sticky Header Table Viewport -->
            <div class="comm-table-viewport">
                <table id="purchase-table" class="table purchase-list mt-0 u-w-100pct">
                    <thead>
                        <tr>
                            <th class="not-exported"></th>
                            <th>{{__('db.date')}}</th>
                            <th>{{__('db.reference')}}</th>
                            <th>{{__('db.Created By')}}</th>
                            <th>{{__('db.Supplier')}}</th>
                            @if ($general_setting->show_products_details_in_purchase_table)
                                <th>{{ __('db.Products') }}</th>
                                <th>{{ __('db.Quantity') }}</th>
                            @endif
                            <th>{{__('db.Purchase Status')}}</th>
                            <th>{{__('db.grand total')}}</th>
                            <th>{{__('db.Returned Amount')}}</th>
                            <th>{{__('db.Paid')}}</th>
                            <th>{{__('db.Due')}}</th>
                            <th>{{__('db.Payment Status')}}</th>
                            @foreach($custom_fields as $fieldName)
                            <th>{{$fieldName}}</th>
                            @endforeach
                            <th class="not-exported">{{__('db.action')}}</th>
                        </tr>
                    </thead>
                    <tfoot class="tfoot active">
                        <th></th>
                        <th>{{__('db.Total')}}</th>
                        <th></th>
                        <th></th>
                        <th></th>
                        @if ($general_setting->show_products_details_in_purchase_table)
                            <th></th>
                            <th></th>
                        @endif
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        @foreach($custom_fields as $fieldName)
                        <th></th>
                        @endforeach
                        <th></th>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- CHARGES, TRANSPORT & REMARKS MODAL DRAWER -->
<div id="charges-drawer" class="modal fade text-left" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold"><i class="dripicons-gear text-primary"></i> Charges, Transport, Sundries &amp; Remarks</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="chargesTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-transport" data-toggle="tab" href="#content-transport" role="tab">Transport &amp; Bales</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-sundries" data-toggle="tab" href="#content-sundries" role="tab">Charges</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-remarks" data-toggle="tab" href="#content-remarks" role="tab">Remarks &amp; Notes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-settlement" data-toggle="tab" href="#content-settlement" role="tab">Settlement</a>
                    </li>
                </ul>
                <div class="tab-content" id="chargesTabContent">
                    <!-- Transport & Bales -->
                    <div class="tab-pane fade show active" id="content-transport" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>Bale No</label>
                                <input type="text" id="drawer-bale-no" class="form-control" placeholder="Bale / Package identifier">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>No of Bales / Packages</label>
                                <input type="number" id="drawer-no-of-bales" class="form-control" placeholder="Count of bales">
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="drawer-bundle-count">No of Bundles</label>
                                <input type="number" id="drawer-bundle-count" class="form-control" min="0" placeholder="Count of bundles">
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="drawer-vehicle-number">Vehicle No</label>
                                <input type="text" id="drawer-vehicle-number" class="form-control" maxlength="30" placeholder="e.g. TN39 AB 1234">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>LR No / Consignment Note</label>
                                <input type="text" id="drawer-lr-no" class="form-control" placeholder="LR tracking number">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>LR Date</label>
                                <input type="date" id="drawer-lr-date" class="form-control">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Transporter Name</label>
                                <input type="text" id="drawer-transporter-name" class="form-control" placeholder="Logistics carrier">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Station To / Destination</label>
                                <input type="text" id="drawer-station-to" class="form-control" placeholder="Delivery destination">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Order No / Purchase Order</label>
                                <input type="text" id="drawer-order-no" class="form-control" placeholder="PO reference">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Credit Days</label>
                                <input type="number" id="drawer-credit-days" class="form-control" placeholder="e.g. 30">
                            </div>
                            <div class="col-md-3 form-group">
                                <label for="drawer-cd-percent">Cash discount %</label>
                                <input type="number" id="drawer-cd-percent" class="form-control" min="0" max="100" step="0.01" placeholder="e.g. 2">
                            </div>
                            <div class="col-md-3 form-group">
                                <label for="drawer-cd-days">If paid within (days)</label>
                                <input type="number" id="drawer-cd-days" class="form-control" min="0" max="365" placeholder="e.g. 7">
                            </div>
                        </div>
                    </div>

                    <!-- Charges use the shared pricing and landed-cost contract. -->
                    <div class="tab-pane fade" id="content-sundries" role="tabpanel">
                        <div class="form-group">
                            <label for="drawer-shipping-cost">Freight / Transport Charge (₹)</label>
                            <input type="number" id="drawer-shipping-cost" class="form-control" min="0" step="0.0001" value="0">
                        </div>
                        <div class="form-group">
                            <label for="drawer-order-discount">Bill Discount (₹)</label>
                            <input type="number" id="drawer-order-discount" class="form-control" min="0" step="0.0001" value="0">
                        </div>
                        <div class="form-group mb-1">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="drawer-capitalise-services">
                                <label class="custom-control-label" for="drawer-capitalise-services">Add freight to item cost</label>
                            </div>
                            <small class="text-muted">Under GST, enter freight / loading as a service line (e.g. SAC 996511) in the grid. Ticked, its value is spread over the stock items instead of going to expense; its GST stays as input credit.</small>
                        </div>
                        <div class="form-group">
                            <label for="drawer-landed-cost-method">Split freight by</label>
                            <select id="drawer-landed-cost-method" class="form-control">
                                <option value="value">Item value</option>
                                <option value="quantity">Quantity (metres / pieces)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Remarks & Notes -->
                    <div class="tab-pane fade" id="content-remarks" role="tabpanel">
                        <div class="form-group">
                            <label>Standard Remark</label>
                            <select id="drawer-standard-remark" class="form-control">
                                <option value="">-- Select Predefined Remark --</option>
                                @foreach($standardRemarks as $rm)
                                    <option value="{{ $rm->remark }}">{{ $rm->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Bill Remarks &amp; Notes</label>
                            <textarea id="drawer-note" class="form-control" rows="4" placeholder="Enter notes or shipping instructions..."></textarea>
                        </div>
                    </div>

                    <!-- Settlement -->
                    <div class="tab-pane fade" id="content-settlement" role="tabpanel">
                        <div class="form-group mb-2">
                            <label for="drawer-paying-method" class="text-muted font-weight-bold">Payment Method</label>
                            <select id="drawer-paying-method" class="form-control form-control-sm">
                                <option value="Credit">Credit / Unpaid</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Credit Card">Credit Card</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>Settlement Account</label>
                                <select id="drawer-account-id" class="form-control">
                                    @foreach($lims_account_list as $acc)
                                        <option value="{{ $acc->id }}">{{ $acc->name }} [{{ $acc->account_no }}]</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Paid Amount</label>
                                <input type="number" id="drawer-paid-amount" class="form-control" value="0" step="0.01">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="btn-save-drawer-details" data-dismiss="modal">Apply &amp; Close</button>
            </div>
        </div>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div id="purchase-details" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="container mt-3 pb-2 border-bottom">
            <div class="row">
                <div class="col-md-6 d-print-none">
                    <button id="print-btn" type="button" class="btn btn-default btn-sm"><i class="dripicons-print"></i> {{__('db.Print')}}</button>
                </div>
                <div class="col-md-6 d-print-none">
                    <button type="button" id="close-btn" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
                </div>
                <div class="col-md-12">
                    <h3 id="exampleModalLabel" class="modal-title text-center container-fluid">{{$general_setting->site_title}}</h3>
                </div>
                <div class="col-md-12 text-center">
                    <i class="u-fs-15px">{{__('db.Purchase Details')}}</i>
                </div>
            </div>
        </div>
        <div id="purchase-content" class="modal-body"></div>
        <br>
        <div class="table-responsive document-lines px-3" tabindex="0" role="region" aria-label="{{__('db.Purchase Details')}}">
            <table class="table table-bordered product-purchase-list">
                <thead>
                    <th>#</th>
                    <th>{{__('db.product')}}</th>
                    <th>{{__('db.Batch No')}}</th>
                    <th>Qty</th>
                    <th>{{__('db.Returned')}}</th>
                    <th>{{__('db.Unit Cost')}}</th>
                    <th>{{__('db.Tax')}}</th>
                    <th>{{__('db.Discount')}}</th>
                    <th>{{__('db.Subtotal')}}</th>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
        <div id="purchase-footer" class="modal-body"></div>
      </div>
    </div>
</div>

<div id="view-payment" tabindex="-1" role="dialog" aria-labelledby="viewPaymentLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="viewPaymentLabel" class="modal-title font-weight-bold">{{__('db.All Payment')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-hover payment-list">
                    <thead>
                        <tr>
                            <th>{{__('db.date')}}</th>
                            <th>{{__('db.Reference No')}}</th>
                            <th>{{__('db.Account')}}</th>
                            <th>{{__('db.Amount')}}</th>
                            <th>{{__('db.Paid By')}}</th>
                            <th>{{__('db.Payment Date')}}</th>
                            <th>{{__('db.action')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="add-payment" tabindex="-1" role="dialog" aria-labelledby="addPaymentLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="addPaymentLabel" class="modal-title font-weight-bold">{{__('db.Add Payment')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                {!! Form::open(['route' => 'purchase.add-payment', 'method' => 'post', 'class' => 'payment-form' ]) !!}
                @if(config('commercial.enabled'))<input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">@endif
                    <div class="row">
                        <input type="hidden" name="balance">
                        <div class="col-md-6">
                            <label>{{__('db.Recieved Amount')}} *</label>
                            <input type="text" name="paying_amount" class="form-control numkey" step="any" required>
                        </div>
                        <div class="col-md-6">
                            <label>{{__('db.Paying Amount')}} *</label>
                            <input type="text" id="amount" name="amount" class="form-control" step="any" required>
                        </div>
                        <div class="col-md-6 mt-1">
                            <label>{{__('db.Change')}} : </label>
                            <p class="change ml-2">{{number_format(0, $general_setting->decimal, '.', '')}}</p>
                        </div>
                        <div class="col-md-6">
                            <label for="add-payment-discount">Cash discount (₹)</label>
                            <input type="number" id="add-payment-discount" name="discount" class="form-control" min="0" step="0.01" value="0"
                                title="Prompt-payment discount. Within the % and days on the bill it is allowed; otherwise give a reason. GST on the bill does not change.">
                        </div>

                        <div class="col-md-6">
                            <label for="add-payment-discount-reason">Discount reason</label>
                            <input type="text" id="add-payment-discount-reason" name="discount_reason" class="form-control" maxlength="255" placeholder="Only if the bill has no cash discount terms">
                        </div>
                        <div class="col-md-6 mt-1">
                            <label>{{__('db.Paid By')}}</label>
                            <select name="paid_by_id" class="form-control">
                                <option value="1">{{ __('db.Cash') }}</option>
                                <option value="3">{{ __('db.Credit Card') }}</option>
                                <option value="4">{{ __('db.Cheque') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mt-2">
                        <div class="card-element" class="form-control">
                        </div>
                        <div class="card-errors" role="alert"></div>
                    </div>
                    <div id="cheque">
                        <div class="form-group">
                            <label>{{__('db.Cheque Number')}} *</label>
                            <input type="text" name="cheque_no" class="form-control">
                        </div>
                    </div>
                    <div class="form-group row">
                        <div class="col-md-6">
                            <label> {{__('db.Account')}}</label>
                            <select class="form-control selectpicker" name="account_id">
                                @foreach($lims_account_list as $account)
                                    @if($account->is_default)
                                    <option selected value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                                    @else
                                    <option value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>{{ __('db.Payment Date') }}</label>
                            <input type="text" name="payment_at" id="payment_at" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>{{__('db.Payment Note')}}</label>
                        <textarea rows="3" class="form-control" name="payment_note"></textarea>
                    </div>

                    <input type="hidden" name="purchase_id">

                    <button type="submit" class="btn btn-primary">{{__('db.submit')}}</button>
                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

<div id="edit-payment" tabindex="-1" role="dialog" aria-labelledby="editPaymentLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="editPaymentLabel" class="modal-title font-weight-bold">{{__('db.Update Payment')}}</h5>
                <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                {!! Form::open(['route' => 'purchase.update-payment', 'method' => 'post', 'class' => 'payment-form' ]) !!}
                    <div class="row">
                        <div class="col-md-6">
                            <label>{{__('db.Recieved Amount')}} *</label>
                            <input type="text" name="edit_paying_amount" class="form-control numkey" step="any" required>
                        </div>
                        <div class="col-md-6">
                            <label>{{__('db.Paying Amount')}} *</label>
                            <input type="text" name="edit_amount" class="form-control" step="any" required>
                        </div>
                        <div class="col-md-6 mt-1">
                            <label>{{__('db.Change')}} : </label>
                            <p class="change ml-2">{{number_format(0, $general_setting->decimal, '.', '')}}</p>
                        </div>
                        <div class="col-md-6 mt-1">
                            <label>{{__('db.Paid By')}}</label>
                            <select name="edit_paid_by_id" class="form-control selectpicker">
                                <option value="1">{{ __('db.Cash') }}</option>
                                <option value="3">{{ __('db.Credit Card') }}</option>
                                <option value="4">{{ __('db.Cheque') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mt-2">
                        <div class="card-element" class="form-control">
                        </div>
                        <div class="card-errors" role="alert"></div>
                    </div>
                    <div id="edit-cheque">
                        <div class="form-group">
                            <label>{{__('db.Cheque Number')}} *</label>
                            <input type="text" name="edit_cheque_no" class="form-control">
                        </div>
                    </div>
                    <div class="form-group row">
                        <div class="col-md-6">
                            <label> {{__('db.Account')}}</label>
                            <select class="form-control selectpicker" name="account_id">
                            @foreach($lims_account_list as $account)
                                <option value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                            @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>{{ __('db.Payment Date') }}</label>
                            <input type="text" name="payment_at" id="edit_payment_at" class="form-control" value="" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>{{__('db.Payment Note')}}</label>
                        <textarea rows="3" class="form-control" name="edit_payment_note"></textarea>
                    </div>

                    <input type="hidden" name="payment_id">

                    <button type="submit" class="btn btn-primary">{{__('db.update')}}</button>
                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

<!-- Row Detail Modal (Batch, Expiry, Serial/IMEI, Discount) -->
<div id="row-detail-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left">
    <div class="modal-dialog u-maxw-480px">
        <div class="modal-content u-r-10px u-b-1px-solid-border-strong">
            <div class="modal-header d-flex align-items-center justify-content-between u-bg-surface-2 u-p-12px-18px u-bb-1px-solid-border">
                <h5 class="modal-title u-fs-14px u-fw-700 u-c-text u-d-flex u-items-center u-gap-8px">
                    <i class="dripicons-pencil u-c-primary"></i> <span id="row-detail-modal-title">Item Details</span>
                </h5>
                <button type="button" class="close u-fs-20px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body u-p-16px-18px">
                <input type="hidden" id="modal-target-row-id" value="">
                <div class="form-group mb-2">
                    <label class="u-fs-11px u-fw-700 u-c-text-2 u-mb-2px">Batch Number</label>
                    <input type="text" id="modal-row-batch" class="form-control form-control-sm u-h-32px u-fs-12px" placeholder="e.g. BATCH-2026-01">
                </div>
                <div class="form-group mb-2">
                    <label class="u-fs-11px u-fw-700 u-c-text-2 u-mb-2px">Expiry Date</label>
                    <input type="date" id="modal-row-expire" class="form-control form-control-sm u-h-32px u-fs-12px">
                </div>
                <div class="form-group mb-2">
                    <label class="u-fs-11px u-fw-700 u-c-text-2 u-mb-2px">IMEI / Serial Numbers</label>
                    <textarea id="modal-row-imei" class="form-control form-control-sm u-fs-12px" rows="2" placeholder="Comma separated IMEIs or serials"></textarea>
                </div>
                <div class="form-group mb-0">
                    <label class="u-fs-11px u-fw-700 u-c-text-2 u-mb-2px">Item Discount (₹)</label>
                    <input type="number" id="modal-row-discount" class="form-control form-control-sm u-h-32px u-fs-12px" placeholder="0.00" step="0.01">
                </div>
            </div>
            <div class="modal-footer d-flex align-items-center justify-content-between u-bg-surface-2 u-p-10px-18px u-bt-1px-solid-border">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary u-bg-primary u-bc-primary u-fw-600" id="btn-save-row-detail">
                    Save Details
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Multi-Item Selection Modal (Optech Grid) -->
<div id="multi-item-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left">
    <div class="modal-dialog modal-lg u-maxw-850px">
        <div class="modal-content u-r-10px u-b-1px-solid-border-strong">
            <div class="modal-header d-flex align-items-center justify-content-between u-bg-surface-2 u-p-12px-18px u-bb-1px-solid-border">
                <div>
                    <h5 class="modal-title u-fs-14px u-fw-700 u-c-text u-d-flex u-items-center u-gap-8px u-m-0">
                        <i class="dripicons-menu u-c-primary"></i> Multi-Item Fast Batch Picker
                    </h5>
                    <div class="u-fs-11_5px u-c-text-muted u-mt-2px">
                        Select multiple products with quantities and insert them all at once into the voucher table.
                    </div>
                </div>
                <button type="button" class="close u-fs-20px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body u-p-14px-18px">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div class="u-pos-relative u-flex-1">
                        <i class="fa fa-search text-muted u-pos-absolute u-left-10px u-top-50pct u-tf-translatey-50pct u-fs-12px"></i>
                        <input type="text" id="multi-item-filter" class="form-control form-control-sm u-pl-30px u-h-32px u-fs-12px" placeholder="Filter items by name or code...">
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge badge-light border u-fs-11px u-p-5px-8px" id="multi-item-count-badge">0 selected</span>
                    </div>
                </div>
                <div class="table-responsive u-maxh-360px u-ovy-auto u-b-1px-solid-border u-r-6px">
                    <table class="table table-sm table-hover mb-0 u-fs-12px" id="multi-item-table">
                        <thead class="u-bg-surface-3 u-pos-sticky u-top-0 u-z-10">
                            <tr>
                                <th class="u-w-36px u-ta-center">
                                    <input type="checkbox" id="multi-item-select-all">
                                </th>
                                <th class="u-minw-240px">Item Name</th>
                                <th class="u-w-130px">Item Code</th>
                                <th class="u-w-110px u-ta-right">Cost</th>
                                <th class="u-w-90px u-ta-center">Qty</th>
                                <th class="u-w-70px u-ta-center">Unit</th>
                            </tr>
                        </thead>
                        <tbody id="multi-item-tbody">
                            <!-- Populated dynamically via JS from allProducts -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer d-flex align-items-center justify-content-between u-bg-surface-2 u-p-10px-18px u-bt-1px-solid-border">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary u-bg-primary u-bc-primary u-fw-600" id="btn-add-selected-items">
                    + Add Selected Items to Voucher
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Executive Spacious Add Product Modal (Catalog Master) -->
<div id="quick-create-item-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left compact-add-product-modal">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="modal-title font-weight-bold u-fs-15px u-c-text u-d-flex u-items-center u-gap-8px u-m-0">
                        <i class="fa fa-plus-circle u-c-primary u-fs-18px"></i> Add Product (Catalog Master)
                    </h5>
                    <span class="u-fs-11_5px u-c-text-muted">The field labels marked with * are required. Once created, the item is immediately selected and added to the voucher.</span>
                </div>
                <button type="button" class="close u-fs-22px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="quick-create-item-form">
                <div class="modal-body">
                    <!-- Section 1: Identification (3 columns) -->
                    <div class="compact-modal-section-title">
                        <i class="dripicons-information"></i> 1. Product Identification
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Product Type *</label>
                                <select id="quick-item-type" name="type" class="form-control" required>
                                    <option value="standard" selected>Standard</option>
                                    <option value="combo">Combo</option>
                                    <option value="digital">Digital</option>
                                    <option value="service">Service</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Product Name *</label>
                                <input type="text" id="quick-item-name" name="name" class="form-control" placeholder="e.g. Cotton Grey Yarn 40s" required autocomplete="off">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Product Code *</label>
                                <div class="input-group">
                                    <input type="text" id="quick-item-code" name="code" class="form-control" placeholder="e.g. ITM-1002" required autocomplete="off">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary u-h-34px u-lh-32px u-p-0-12px u-fs-11_5px" id="btn-quick-gen-code" title="Generate Random Code"><i class="fa fa-refresh"></i> Auto</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Barcode Symbology *</label>
                                <select id="quick-item-symbology" name="barcode_symbology" class="form-control" required>
                                    <option value="C128" selected>Code 128</option>
                                    <option value="C39">Code 39</option>
                                    <option value="UPCA">UPC-A</option>
                                    <option value="UPCE">UPC-E</option>
                                    <option value="EAN8">EAN-8</option>
                                    <option value="EAN13">EAN-13</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Brand</label>
                                <div class="input-group">
                                    <select id="quick-item-brand" name="brand_id" class="form-control">
                                        <option value="">Select Brand...</option>
                                        @if(isset($lims_brand_list))
                                            @foreach($lims_brand_list as $b)
                                                <option value="{{ $b->id }}">{{ $b->title }}</option>
                                            @endforeach
                                        @endif
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary u-p-0-10px" id="btn-quick-add-brand" title="Create Brand Inline"><i class="dripicons-plus"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Category *</label>
                                <div class="input-group">
                                    <select id="quick-item-category" name="category_id" class="form-control" required>
                                        <option value="" disabled selected>Select Category...</option>
                                        @if(isset($lims_category_list))
                                            @foreach($lims_category_list as $cat)
                                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                            @endforeach
                                        @endif
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary u-p-0-10px" id="btn-quick-add-category" title="Create Category Inline"><i class="dripicons-plus"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @include('backend.product.partials.stock-policy', ['product' => null])
                    <!-- Section 2: Units of Measure (3 columns) -->
                    <div class="compact-modal-section-title">
                        <i class="dripicons-box"></i> 2. Units of Measure
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Product Unit *</label>
                                <select id="quick-item-unit" name="unit_id" class="form-control" required>
                                    @if(isset($lims_unit_list) && count($lims_unit_list))
                                        @foreach($lims_unit_list as $u)
                                            <option value="{{ $u->id }}" {{ $loop->first ? 'selected' : '' }}>{{ $u->unit_name }} ({{ $u->unit_code }})</option>
                                        @endforeach
                                    @else
                                        <option value="1" selected>Piece (Pc)</option>
                                        <option value="2">Kilogram (Kg)</option>
                                        <option value="3">Meter (Mtr)</option>
                                        <option value="4">Box (Box)</option>
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Sale Unit</label>
                                <select id="quick-item-sale-unit" name="sale_unit_id" class="form-control">
                                    @if(isset($lims_unit_list) && count($lims_unit_list))
                                        @foreach($lims_unit_list as $u)
                                            <option value="{{ $u->id }}">{{ $u->unit_name }} ({{ $u->unit_code }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Purchase Unit</label>
                                <select id="quick-item-purchase-unit" name="purchase_unit_id" class="form-control">
                                    @if(isset($lims_unit_list) && count($lims_unit_list))
                                        @foreach($lims_unit_list as $u)
                                            <option value="{{ $u->id }}">{{ $u->unit_name }} ({{ $u->unit_code }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Cost, Pricing & Margins (4 columns) -->
                    <div class="compact-modal-section-title">
                        <i class="dripicons-tag"></i> 3. Cost, Pricing &amp; Margins
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Product Cost (₹) *</label>
                                <input type="number" id="quick-item-cost" name="cost" class="form-control" placeholder="0.00" step="any" min="0" required value="0.00">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Profit Margin</label>
                                <div class="input-group">
                                    <div class="input-group-prepend u-w-52px">
                                        <select id="quick-item-margin-type" name="profit_margin_type" class="form-control u-p-4px-6px">
                                            <option value="percentage" selected>%</option>
                                            <option value="flat">₹</option>
                                        </select>
                                    </div>
                                    <input type="number" id="quick-item-margin" name="profit_margin" class="form-control" placeholder="25.00" step="0.01" value="25.00">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Product Price (₹) *</label>
                                <input type="number" id="quick-item-price" name="price" class="form-control" placeholder="0.00" step="any" min="0" required value="0.00">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Wholesale Price (₹)</label>
                                <input type="number" id="quick-item-wholesale" name="wholesale_price" class="form-control" placeholder="0.00" step="any" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Daily Sale Objective</label>
                                <input type="number" id="quick-item-daily-sale" name="daily_sale_objective" class="form-control" placeholder="e.g. 10" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Alert Quantity</label>
                                <input type="number" id="quick-item-alert-qty" name="alert_quantity" class="form-control" placeholder="10" step="any">
                            </div>
                        </div>
                        @php $quickGstRates = \App\Services\Tax\GstSetupService::usesGst() ? app(\App\Services\Tax\GstSetupService::class)->currentRates() : null; @endphp
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>{{ $quickGstRates !== null ? 'GST Rate' : 'Product Tax' }}</label>
                                @if($quickGstRates !== null)
                                <select id="quick-item-tax" name="tax_category_id" class="form-control" data-gst="1">
                                    <option value="" data-rate="0">Not set</option>
                                    @foreach($quickGstRates as $rateId => $gstRate)
                                        <option value="{{ $rateId }}" data-rate="{{ $gstRate['rate'] }}" {{ $gstRate['name'] === 'GST 18%' ? 'selected' : '' }}>{{ $gstRate['name'] }}</option>
                                    @endforeach
                                </select>
                                @else
                                <select id="quick-item-tax" name="tax_id" class="form-control">
                                    <option value="" data-rate="0">No Tax (0%)</option>
                                    @if(isset($lims_tax_list))
                                        @foreach($lims_tax_list as $tax)
                                            <option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" {{ $tax->rate == 18 ? 'selected' : '' }}>{{ $tax->name }} ({{ $tax->rate }}%)</option>
                                        @endforeach
                                    @endif
                                </select>
                                @endif
                            </div>
                        </div>
                        @if($quickGstRates !== null)
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>HSN / SAC</label>
                                <input type="text" id="quick-item-hsn" name="hsn_code" class="form-control" maxlength="8" inputmode="numeric" placeholder="e.g. 3004 or 998313">
                            </div>
                        </div>
                        @endif
                        <div class="col-md-3">
                            <div class="compact-field-block">
                                <label>Tax Method</label>
                                <select id="quick-item-tax-method" name="tax_method" class="form-control">
                                    <option value="1" selected>Exclusive</option>
                                    <option value="2">Inclusive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Warranty & Guarantee -->
                    <div class="compact-modal-section-title">
                        <i class="dripicons-shield"></i> 4. Warranty &amp; Guarantee
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="compact-field-block">
                                <label>Warranty</label>
                                <div class="input-group">
                                    <input type="number" id="quick-item-warranty" name="warranty" class="form-control" placeholder="e.g. 1" min="1">
                                    <div class="input-group-append u-w-105px">
                                        <select id="quick-item-warranty-type" name="warranty_type" class="form-control">
                                            <option value="months" selected>Months</option>
                                            <option value="years">Years</option>
                                            <option value="days">Days</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="compact-field-block">
                                <label>Guarantee</label>
                                <div class="input-group">
                                    <input type="number" id="quick-item-guarantee" name="guarantee" class="form-control" placeholder="e.g. 1" min="1">
                                    <div class="input-group-append u-w-105px">
                                        <select id="quick-item-guarantee-type" name="guarantee_type" class="form-control">
                                            <option value="months" selected>Months</option>
                                            <option value="years">Years</option>
                                            <option value="days">Days</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 5: Inventory Tracking & POS Options -->
                    <div class="compact-modal-section-title">
                        <i class="dripicons-checklist"></i> 5. Inventory Tracking &amp; POS Options
                    </div>
                    <div class="compact-modal-checkbox-group">
                        <label class="compact-modal-checkbox-item">
                            <input type="checkbox" id="quick-item-is-batch" name="is_batch" value="1">
                            <span>Batch &amp; Expired Date</span>
                        </label>
                        <label class="compact-modal-checkbox-item">
                            <input type="checkbox" id="quick-item-is-imei" name="is_imei" value="1">
                            <span>IMEI or Serial Numbers</span>
                        </label>
                        <label class="compact-modal-checkbox-item">
                            <input type="checkbox" id="quick-item-featured" name="featured" value="1">
                            <span>Featured in POS</span>
                        </label>
                        <label class="compact-modal-checkbox-item">
                            <input type="checkbox" id="quick-item-is-embeded" name="is_embeded" value="1">
                            <span>Embedded Barcode (Scale)</span>
                        </label>
                        <label class="compact-modal-checkbox-item">
                            <input type="checkbox" id="quick-item-is-online" name="is_online" value="1" checked>
                            <span>Sell Online</span>
                        </label>
                        <label class="compact-modal-checkbox-item">
                            <input type="checkbox" id="quick-item-in-stock" name="in_stock" value="1" checked>
                            <span>In Stock</span>
                        </label>
                    </div>

                    <!-- Section 6: Product Details -->
                    <div class="compact-modal-section-title">
                        <i class="dripicons-document"></i> 6. Product Details &amp; Specification
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="compact-field-block mb-0">
                                <textarea id="quick-item-details" name="product_details" class="form-control u-h-auto u-minh-56px" rows="2" placeholder="Enter specifications, notes, or product description..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex align-items-center justify-content-between">
                    <button type="button" class="btn btn-sm btn-secondary comm-bottom-btn" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary comm-bottom-btn comm-bottom-btn-primary u-bg-primary u-bc-primary u-fw-600" id="btn-quick-create-submit">
                        <i class="dripicons-checkmark"></i> 💾 Save &amp; Add to Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Create Category Modal (Inline) -->
<div id="quick-create-category-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left u-z-1065">
    <div class="modal-dialog modal-dialog-centered u-maxw-380px">
        <div class="modal-content u-r-10px u-b-1px-solid-border-strong u-sh-0-12px-30px-rgba-0-0-0-0_25">
            <div class="modal-header d-flex align-items-center justify-content-between u-bg-surface-2 u-p-12px-18px u-bb-1px-solid-border">
                <h5 class="modal-title font-weight-bold u-fs-13_5px u-c-text u-m-0">
                    <i class="dripicons-plus text-primary mr-1"></i> Create New Category
                </h5>
                <button type="button" class="close u-fs-18px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body u-p-16px-18px">
                <div class="form-group mb-0">
                    <label class="u-fs-11_5px u-fw-600 u-c-text-2 u-mb-4px">Category Name *</label>
                    <input type="text" id="quick-cat-name-input" class="form-control u-h-34px u-fs-12_5px" placeholder="e.g. Raw Material, Yarn...">
                </div>
            </div>
            <div class="modal-footer d-flex align-items-center justify-content-between u-bg-surface-2 u-p-10px-18px u-bt-1px-solid-border">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary u-bg-primary u-bc-primary u-fw-600" id="btn-submit-quick-cat">
                    Save Category
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Create Brand Modal (Inline) -->
<div id="quick-create-brand-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left u-z-1065">
    <div class="modal-dialog modal-dialog-centered u-maxw-380px">
        <div class="modal-content u-r-10px u-b-1px-solid-border-strong u-sh-0-12px-30px-rgba-0-0-0-0_25">
            <div class="modal-header d-flex align-items-center justify-content-between u-bg-surface-2 u-p-12px-18px u-bb-1px-solid-border">
                <h5 class="modal-title font-weight-bold u-fs-13_5px u-c-text u-m-0">
                    <i class="dripicons-plus text-primary mr-1"></i> Create New Brand
                </h5>
                <button type="button" class="close u-fs-18px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body u-p-16px-18px">
                <div class="form-group mb-0">
                    <label class="u-fs-11_5px u-fw-600 u-c-text-2 u-mb-4px">Brand Title *</label>
                    <input type="text" id="quick-brand-title-input" class="form-control u-h-34px u-fs-12_5px" placeholder="e.g. Raymond, Vardhman...">
                </div>
            </div>
            <div class="modal-footer d-flex align-items-center justify-content-between u-bg-surface-2 u-p-10px-18px u-bt-1px-solid-border">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary u-bg-primary u-bc-primary u-fw-600" id="btn-submit-quick-brand">
                    Save Brand
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Create Party Modal (Inline Popup with All Options) -->
<div id="quick-create-party-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left u-z-1065">
    <div class="modal-dialog modal-dialog-centered u-maxw-820px">
        <div class="modal-content u-r-10px u-b-1px-solid-border-strong u-sh-0-16px-40px-rgba-0-0-0-0_22 u-ov-hidden">
            <div class="modal-header d-flex align-items-center justify-content-between u-bg-surface-2 u-p-12px-20px u-bb-1px-solid-border">
                <div>
                    <h5 class="modal-title font-weight-bold u-fs-14_5px u-c-text u-m-0">
                        <span id="quick-party-modal-title"><i class="fa fa-truck text-primary mr-1"></i> Add Supplier</span>
                    </h5>
                    <small class="text-muted italic d-block u-fs-11px u-mt-2px">The field labels marked with * are required input fields.</small>
                </div>
                <button type="button" class="close u-fs-20px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="quick-create-party-form">
                @csrf
                <input type="hidden" id="party_type_input" name="party_type" value="supplier">
                <div class="modal-body u-p-16px-22px u-maxh-calc-85vh-120px u-ovy-auto">
                    
                    <!-- Top Options: Dual Nature & Customer Group (Cleanly Aligned Checkbox) -->
                    <div class="d-flex align-items-center justify-content-between mb-3 px-3 py-2 u-bg-surface-2 u-r-8px u-b-1px-solid-border">
                        <label for="party-both-checkbox" class="d-flex align-items-center mb-0 u-cur-pointer u-gap-9px u-us-none">
                            <input type="checkbox" id="party-both-checkbox" name="both" value="1" class="u-w-16px u-h-16px u-m-0 u-acc-primary u-cur-pointer">
                            <span class="font-weight-bold u-fs-12_5px u-c-text">Both Customer and Supplier</span>
                            <small class="text-muted ml-1 u-fs-11px">(creates linked account for reciprocal trade)</small>
                        </label>
                        <div id="party-customer-group-col" style="display:none">
                            <div class="d-flex align-items-center u-gap-8px">
                                <label class="u-fs-11_5px u-fw-700 u-c-text-2 u-ws-nowrap u-m-0">Customer Group *</label>
                                <select id="party-customer-group-id" name="customer_group_id" class="form-control form-control-sm u-h-30px u-fs-12px u-w-150px">
                                    @if(isset($lims_customer_group_all) && count($lims_customer_group_all))
                                        @foreach($lims_customer_group_all as $cg)
                                            <option value="{{ $cg->id }}">{{ $cg->name }}</option>
                                        @endforeach
                                    @else
                                        <option value="1">General</option>
                                    @endif
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section 1: GSTIN Auto-Fill & Verification Hero Panel -->
                    <div class="mb-3 p-3 u-bg-surface-3 u-r-8px u-b-1px-solid-border-strong">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="font-weight-bold text-uppercase mb-0 u-fs-11px u-ls-0_4px u-c-text-2">
                                <i class="fa fa-bolt text-warning mr-1"></i> GSTIN Auto-Fill & Statutory Verification
                            </label>
                            <span id="party-modal-gst-badge" class="badge u-fs-10_5px u-p-3px-8px" style="display:none"></span>
                        </div>
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <input type="text" id="party-vat-number" name="vat_number" class="form-control text-uppercase u-h-36px u-fs-13px u-ff-monospace u-fw-700 u-ls-0_5px" placeholder="Enter 15-char GSTIN (e.g. 33AIUPN6412D1ZA)" maxlength="15" autocomplete="off">
                                    <div class="input-group-append">
                                        <button type="button" id="btn-fetch-gstin" class="btn btn-primary u-bg-primary u-bc-primary u-h-36px u-fs-12px u-p-0-14px u-fw-700 u-d-flex u-items-center u-gap-5px" title="1-Click Auto-Fill all details from GST registry">
                                            <i class="fa fa-bolt"></i> <span>Fetch Details</span>
                                        </button>
                                        <a href="https://services.gst.gov.in/services/searchtp" target="_blank" id="btn-open-gst-portal" class="btn btn-outline-secondary u-h-36px u-fs-12px u-p-0-11px u-d-flex u-items-center" title="Verify on official Government GST Portal">
                                            <i class="fa fa-external-link"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center u-gap-6px">
                                    <span class="text-muted u-fs-11_5px u-fw-600 u-ws-nowrap">PAN:</span>
                                    <input type="text" id="party-pan-display" readonly placeholder="Auto from GSTIN" class="form-control form-control-sm text-uppercase u-h-36px u-fs-12_5px u-ff-monospace u-fw-700 u-bg-surface-3 u-c-text">
                                </div>
                            </div>
                        </div>

                        <!-- Full-Width GSTIN Details & Badges Strip -->
                        <div id="party-gst-preview-strip" class="mt-2 p-2 u-r-6px u-b-1px-solid-border-strong u-bg-surface" style="display:none">
                            <div class="d-flex align-items-center flex-wrap u-gap-6px">
                                <span id="gst-strip-state" class="badge badge-info u-fs-11px u-fw-600 u-p-4px-8px"></span>
                                <span id="gst-strip-const" class="badge badge-dark u-fs-11px u-fw-600 u-p-4px-8px"></span>
                                <span id="gst-strip-tax" class="badge badge-primary u-fs-11px u-fw-600 u-p-4px-8px"></span>
                                <span id="gst-strip-status" class="badge u-fs-11px u-fw-700 u-p-4px-8px" style="display:none"></span>
                                <span id="gst-strip-taxpayer" class="badge badge-secondary u-fs-11px u-fw-600 u-p-4px-8px" style="display:none"></span>
                                <span id="gst-strip-regdate" class="badge badge-light border u-fs-11px u-fw-600 u-p-4px-8px" style="display:none"></span>
                                <span id="gst-strip-cancdate" class="badge badge-danger u-fs-11px u-fw-600 u-p-4px-8px" style="display:none"></span>
                                <span id="gst-strip-filing" class="badge badge-light border u-fs-11px u-fw-600 u-p-4px-8px" style="display:none"></span>
                                <span id="gst-strip-nature" class="badge badge-light border u-fs-11px u-fw-600 u-p-4px-8px" style="display:none"></span>
                            </div>
                            <div id="gst-strip-msg" class="text-muted mt-2 u-fs-11_5px u-lh-1_4"></div>
                        </div>
                    </div>

                    <!-- Section 2: Business & Identification Details -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="compact-field-block">
                                <label>Name / Contact Person *</label>
                                <input type="text" id="party-name" name="name" class="form-control u-h-34px u-fs-12_5px" placeholder="Contact or Person Name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="compact-field-block">
                                <label>Company / Trade Name *</label>
                                <input type="text" id="party-company-name" name="company_name" class="form-control u-h-34px u-fs-12_5px" placeholder="Company / Trade Name" required>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Contact Details -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Phone Number *</label>
                                <input type="text" id="party-phone-number" name="phone_number" class="form-control u-h-34px u-fs-12_5px" placeholder="Primary Phone" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Email</label>
                                <input type="email" id="party-email" name="email" class="form-control u-h-34px u-fs-12_5px" placeholder="email@example.com">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>WhatsApp Number</label>
                                <input type="text" id="party-wa-number" name="wa_number" class="form-control u-h-34px u-fs-12_5px" placeholder="WhatsApp Number">
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Registered Address Details (Auto-Filled) -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="compact-field-block">
                                <label>Street Address *</label>
                                <input type="text" id="party-address" name="address" class="form-control u-h-34px u-fs-12_5px" placeholder="Building / Street / Door No / Locality" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>City *</label>
                                <input type="text" id="party-city" name="city" class="form-control u-h-34px u-fs-12_5px" placeholder="City" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>State *</label>
                                <input type="text" id="party-state" name="state" class="form-control u-h-34px u-fs-12_5px" placeholder="State">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Postal / PIN Code</label>
                                <input type="text" id="party-postal-code" name="postal_code" class="form-control u-h-34px u-fs-12_5px" placeholder="PIN Code">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Country</label>
                                <input type="text" id="party-country" name="country" class="form-control u-h-34px u-fs-12_5px" value="India" placeholder="Country">
                            </div>
                        </div>
                    </div>

                    <!-- Section 5: Credit Terms & Balance -->
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Credit Days</label>
                                <input type="number" id="party-credit-days" name="credit_days" class="form-control u-h-34px u-fs-12_5px" value="30" min="0" placeholder="30">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="compact-field-block">
                                <label>Opening Balance (Due) <i class="fa fa-info-circle text-muted" title="Amount due at start"></i></label>
                                <input type="number" id="party-opening-balance" name="opening_balance" class="form-control u-h-34px u-fs-12_5px" value="0" step="any" min="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div id="party-form-error" class="alert alert-danger mb-0 py-1 px-2 u-fs-11px u-r-6px" style="display:none"></div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer d-flex align-items-center justify-content-between u-bg-surface-2 u-p-12px-20px u-bt-1px-solid-border">
                    <button type="button" class="btn btn-sm btn-secondary u-h-34px u-p-0-16px u-r-6px u-fw-600" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary u-bg-primary u-bc-primary u-h-34px u-p-0-22px u-r-6px u-fw-700 u-fs-13px" id="btn-save-party">
                        <i class="dripicons-checkmark mr-1"></i> Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('backend.commercial.orders', ['orderKind' => 'purchase'])
@endsection

@push('scripts')
<script src="{{ asset('js/commercial-product-lookup.js') }}"></script>
<script type="text/javascript">
(function() {
    'use strict';

    // --- Product List Autocomplete & Item Master Data ---
    @php
        $gstRates = app(\App\Services\Tax\GstSetupService::class)->currentRates();
        $jsProductList = [];
        // Rates on the bill are before tax; a product priced tax-inclusive is shown at its pre-tax rate.
        $exclusive = fn ($amount, $prod, $rate) => (int) ($prod->tax_method ?? 1) === 2 && $rate > 0 ? round($amount * 100 / (100 + $rate), 4) : $amount;
        foreach($lims_product_list_without_variant as $prod) {
            $taxVal = 0;
            if (!empty($prod->tax_category_id) && isset($gstRates[$prod->tax_category_id])) {
                $taxVal = $gstRates[$prod->tax_category_id]['rate'];
            } elseif (!empty($prod->tax_id)) {
                $taxObj = collect($lims_tax_list)->firstWhere('id', $prod->tax_id);
                $taxVal = $taxObj ? (float)$taxObj->rate : 0;
            }
            $jsProductList[] = [
                'id' => (int)$prod->id,
                'type' => (string)($prod->type ?? 'standard'),
                'name' => (string)$prod->name,
                'code' => (string)$prod->code,
                'price' => $exclusive((float)($prod->price ?? 0), $prod, $taxVal),
                'cost' => $exclusive((float)($prod->cost ?? 0), $prod, $taxVal),
                'tax_rate' => $taxVal,
                'unit' => (string)($prod->unit_code ?? ($prod->unit_name ?? 'Unit')),
                'value' => (string)$prod->code . '|' . (string)$prod->name,
                'label' => (string)$prod->code . ' - ' . (string)$prod->name,
            ];
        }
        foreach($lims_product_list_with_variant as $prod) {
            $taxVal = 0;
            if (!empty($prod->tax_category_id) && isset($gstRates[$prod->tax_category_id])) {
                $taxVal = $gstRates[$prod->tax_category_id]['rate'];
            } elseif (!empty($prod->tax_id)) {
                $taxObj = collect($lims_tax_list)->firstWhere('id', $prod->tax_id);
                $taxVal = $taxObj ? (float)$taxObj->rate : 0;
            }
            $jsProductList[] = [
                'id' => (int)$prod->id,
                'type' => (string)($prod->type ?? 'standard'),
                'name' => (string)$prod->name,
                'code' => (string)($prod->item_code ?? $prod->code),
                'price' => $exclusive((float)(($prod->price ?? 0) + ($prod->additional_price ?? 0)), $prod, $taxVal),
                'cost' => $exclusive((float)(($prod->cost ?? 0) + ($prod->additional_cost ?? 0)), $prod, $taxVal),
                'tax_rate' => $taxVal,
                'unit' => (string)($prod->unit_code ?? ($prod->unit_name ?? 'Unit')),
                'value' => (string)($prod->item_code ?? $prod->code) . '|' . (string)$prod->name,
                'label' => (string)($prod->item_code ?? $prod->code) . ' - ' . (string)$prod->name,
            ];
        }
    @endphp

    CommercialProductLookup.enabled = @json(config('commercial.enabled'));
    var allProducts = @json($jsProductList);
    function matchesProductMode(product) {
        var mode = $('[data-nature].active').data('nature') || 'mixed';
        return mode === 'mixed' || (mode === 'service' ? ['service', 'digital'].includes(product.type) : !['service', 'digital'].includes(product.type));
    }
    var lims_product_code = allProducts.map(function(p) { return p.value; });

    // Append modals directly to body to avoid container clipping
    $(function() {
        $('#multi-item-modal, #quick-create-item-modal, #row-detail-modal, #charges-drawer, #purchase-details, #quick-create-category-modal, #quick-create-brand-modal, #quick-create-party-modal, #add-payment, #view-payment, #edit-payment').appendTo('body');
    });

    // Register "Add Payment": the bill's id and due amount (restored after the command-center redesign dropped it).
    $(document).on('click', 'table.purchase-list tbody .add-payment', function () {
        var due = parseFloat($(this).data('due')) || 0, $form = $('#add-payment');
        $form.find('input[name="purchase_id"]').val(String($(this).data('id')));
        $form.find('input[name="balance"], input[name="amount"], input[name="paying_amount"]').val(due.toFixed(2));
        $form.find('.change').text('0.00');
        $('#currency_id').val($(this).data('currency_id')); $('#exchange_rate').val(parseFloat($(this).data('exchange_rate')) || 1);
    });

    // --- State variables ---
    var rowCounter = 0;
    var taxList = @json($lims_tax_list);
    var unitList = @json($lims_unit_list ?? \App\Models\Unit::where('is_active', true)->get());
    function escapeHtml(value) { return $('<span>').text(value ?? '').html().replaceAll('"', '&quot;').replaceAll("'", '&#39;'); }
    var decimalPlaces = {{ $general_setting->decimal ?? 2 }};

    // --- Table Density Switcher & Persistence ---
    $('.density-btn').on('click', function() {
        $('.density-btn').removeClass('active');
        $(this).addClass('active');
        var density = $(this).data('density');
        $('#order-table').removeClass('compact cozy large').addClass(density);
        localStorage.setItem('zolo_voucher_density', density);
    });
    var savedDensity = localStorage.getItem('zolo_voucher_density') || 'cozy';
    $('.density-btn[data-density="' + savedDensity + '"]').addClass('active').siblings().removeClass('active');
    $('#order-table').removeClass('compact cozy large').addClass(savedDensity);

    // --- Autocomplete setup with Custom Render Item ---
    var $productSearch = $('#lims_productcodeSearch');
    $productSearch.autocomplete({
        minLength: 1,
        autoFocus: false,
        source: function(request, response) {
            CommercialProductLookup.search(request.term, allProducts, 'purchase', matchesProductMode).then(response).catch(function(error) { response([]); console.warn(error.message); });
        },
        select: function(event, ui) {
            if (ui && ui.item) {
                addProductRow({
                    product_id: ui.item.id,
                    product_name: ui.item.name,
                    product_code: ui.item.code,
                    price: ui.item.price,
                    cost: ui.item.cost,
                    tax_rate: ui.item.tax_rate,
                    unit: ui.item.unit,
                    qty: 1
                });
            }
            $(this).val('');
            return false;
        }
    });

    if ($productSearch.data('ui-autocomplete')) {
        $productSearch.data('ui-autocomplete')._renderItem = function(ul, item) {
            var rateStr = '₹ ' + (parseFloat(item.cost || item.price || 0)).toFixed(decimalPlaces);
            return $("<li>")
                .append(`
                    <div class="custom-ac-item d-flex align-items-center justify-content-between">
                        <div class="u-flex-1 u-minw-0 u-pr-8px">
                            <div class="item-title">${item.name}</div>
                            <div class="u-fs-11px u-c-text-muted u-d-flex u-items-center u-gap-6px u-mt-2px">
                                <span class="item-code-badge">${item.code}</span>
                                <span>•</span>
                                <span class="item-rate">${rateStr}</span>
                                <span>•</span>
                                <span>${item.unit || 'Unit'}</span>
                            </div>
                        </div>
                        <div class="u-shrink-0">
                            <span class="badge u-bg-primary-soft u-c-primary u-fs-10px u-fw-600 u-p-2px-6px u-r-4px">+ Add</span>
                        </div>
                    </div>
                `)
                .appendTo(ul);
        };
    }

    $productSearch.on('keydown', async function(e) {
        if (e.which === 13) {
            if (e.isDefaultPrevented()) return;
            e.preventDefault();
            var val = $(this).val().trim();
            if (val) {
                if (this.dataset.productLookupPending) return;
                    this.dataset.productLookupPending = '1';
                    var matches;
                    try { matches = await CommercialProductLookup.search(val, allProducts, 'purchase', matchesProductMode); }
                    catch (error) { alert(error.message); return; }
                    finally { delete this.dataset.productLookupPending; }
                    if (CommercialProductLookup.normalize(this.value) !== CommercialProductLookup.normalize(val)) return;
                    var exact = CommercialProductLookup.exact(matches, val);
                    if (!exact && matches.length) {
                        $(this).autocomplete('search', val);
                        return;
                    }
                if (exact) {
                    addProductRow({
                        product_id: exact.id,
                        product_name: exact.name,
                        product_code: exact.code,
                        price: exact.price,
                        cost: exact.cost,
                        tax_rate: exact.tax_rate,
                        unit: exact.unit,
                        qty: 1
                    });
                    $(this).val('');
                } else {
                    fetchProductAndAddRow(val);
                    $(this).val('');
                }
            }
        }
    });

    // Capture Alt+I before bootstrap-select treats I as option search; F12 starts a purchase draft.
    document.addEventListener('keydown', function(e) {
        if (e.altKey && !e.ctrlKey && !e.shiftKey && !e.metaKey && e.key.toLowerCase() === 'i'
            && !e.defaultPrevented && !e.isComposing && $productSearch.is(':visible')
            && !document.querySelector('dialog[open], .modal.show, [data-owns-keys]')) {
            e.preventDefault();
            e.stopPropagation();
            $productSearch.focus();
        }
    }, true);

    // --- Fetch Product via AJAX & Add Row ---
    function fetchProductAndAddRow(searchTerm) {
        $.ajax({
            type: 'GET',
            url: '{{ route("product_purchase.search") }}',
            data: { data: searchTerm },
            success: function(data) {
                if (data && data.length) {
                    addProductRow({
                        product_id: data[9],
                        product_name: data[0],
                        product_code: data[1],
                        cost: parseFloat(data[2]) || 0,
                        tax_rate: parseFloat(data[3]) || 0,
                        unit: (data[6] ? data[6].split(',')[0] : 'Unit'),
                        qty: 1
                    });
                } else {
                    alert('Product not found: ' + searchTerm);
                }
            },
            error: function() {
                // Fallback manual line
                addProductRow({
                    product_id: 0,
                    product_name: searchTerm.split('|')[1] || searchTerm,
                    product_code: searchTerm.split('|')[0] || '',
                    cost: 0,
                    tax_rate: 0,
                    unit: 'Unit',
                    qty: 1
                });
            }
        });
    }

    // --- Apply Selected Product to an Existing Table Row ---
    function applyProductToRow(tr, p) {
        tr.attr('data-product-id', p.id);
        tr.find('.row-product-id').val(p.id);
        tr.find('.row-product-code').val(p.code);
        tr.find('.row-item-name').val(p.name).attr('title', p.code ? 'Code: ' + p.code : '');

        var rate = Number(p.cost ?? p.price ?? 0);
        tr.find('.row-rate').val(rate);
        tr.find('.row-unit-cost-input').val(rate);
        tr.find('.row-net-unit-price').val(rate);

        // Match or add Unit
        if (p.unit) {
            var selectedUnit = unitList.find(u => u.unit_name === p.unit || u.unit_code === p.unit);
            var productUnit = selectedUnit?.unit_name || p.unit;
            var uSel = tr.find('.row-unit-select');
            var matched = false;
            uSel.find('option').each(function() {
                if ($(this).val().toLowerCase() === productUnit.toLowerCase() || $(this).text().toLowerCase() === productUnit.toLowerCase()) {
                    uSel.val($(this).val());
                    matched = true;
                    return false;
                }
            });
            if (!matched) {
                uSel.append(`<option value="${escapeHtml(productUnit)}" selected>${escapeHtml(productUnit)}</option>`);
            }
        }

        // Match Tax Rate based on Header Tax Classification or Multi-Tax
        var $headerTaxSel = $('#purchase_type_id');
        var headerOpt = $headerTaxSel.find('option:selected');
        var isHeaderMulti = headerOpt.data('is-multi') == '1' || headerOpt.data('is-multi') === 1;
        var headerRateAttr = headerOpt.data('tax-rate');
        var headerRate = (headerRateAttr !== undefined && headerRateAttr !== '' && headerRateAttr !== null) ? parseFloat(headerRateAttr) : null;
        var tSel = tr.find('.row-tax-rate');

        if (!isHeaderMulti && headerRate !== null && !isNaN(headerRate)) {
            // Locked single GST classification (e.g. 18%)
            var matched = false;
            tSel.find('option').each(function() {
                if (parseFloat($(this).val()) === headerRate) {
                    tSel.val($(this).val());
                    matched = true;
                    return false;
                }
            });
            if (!matched) {
                tSel.append(`<option value="${headerRate}">${headerRate}%</option>`);
                tSel.val(headerRate);
            }
            tSel.css({
                'pointer-events': 'none',
                'background-color': '#f1f5f9',
                'color': '#475569',
                'cursor': 'not-allowed'
            });
        } else {
            // Multi-tax: freely selectable tax rates per row
            tSel.css({
                'pointer-events': 'auto',
                'background-color': '',
                'color': '',
                'cursor': 'pointer'
            });
            if (p.tax_rate !== undefined && p.tax_rate !== null) {
                var tVal = parseFloat(p.tax_rate);
                var matched = false;
                tSel.find('option').each(function() {
                    if (parseFloat($(this).val()) === tVal) {
                        tSel.val($(this).val());
                        matched = true;
                        return false;
                    }
                });
                if (!matched) {
                    tSel.append(`<option value="${tVal}">${tVal}%</option>`);
                    tSel.val(tVal);
                }
            }
        }

        // Recalculate Row Totals
        var qty = parseFloat(tr.find('.row-qty').val()) || 1;
        var taxRate = parseFloat(tr.find('.row-tax-rate').val()) || 0;
        var amount = rate * qty;
        var taxAmount = amount * (taxRate / 100);
        var lineTotal = amount + taxAmount;

        tr.find('.row-amount').val(amount.toFixed(decimalPlaces));
        tr.find('.row-subtotal-input').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-tax-amount-input').val(taxAmount.toFixed(decimalPlaces));
        tr.find('.row-total').val(lineTotal.toFixed(decimalPlaces));

        recalcTableSummary();
        $(document).trigger('command-center-row-product', [tr]);
    }

    // --- Searchable Item Name in Table Rows ---
    function initRowItemAutocomplete($input) {
        if (!$input || !$input.length) return;

        if ($input.data('ui-autocomplete')) {
            $input.autocomplete('destroy');
        }

        $input.autocomplete({
            minLength: 1,
            autoFocus: false,
            source: function(request, response) {
                CommercialProductLookup.search(request.term, allProducts, 'purchase', matchesProductMode).then(response).catch(function(error) { response([]); console.warn(error.message); });
            },
            select: function(event, ui) {
                if (ui && ui.item) {
                    var tr = $(this).closest('tr');
                    applyProductToRow(tr, ui.item);
                    setTimeout(function() {
                        tr.find('.row-qty').focus().select();
                    }, 50);
                }
                return false;
            }
        });

        if ($input.data('ui-autocomplete')) {
            $input.data('ui-autocomplete')._renderItem = function(ul, item) {
                var rateStr = '₹ ' + (parseFloat(item.cost || item.price || 0)).toFixed(decimalPlaces);
                return $("<li>")
                    .append(`
                        <div class="custom-ac-item d-flex align-items-center justify-content-between">
                            <div class="u-flex-1 u-minw-0 u-pr-8px">
                                <div class="item-title">${item.name}</div>
                                <div class="u-fs-11px u-c-text-muted u-d-flex u-items-center u-gap-6px u-mt-2px">
                                    <span class="item-code-badge">${item.code}</span>
                                    <span>•</span>
                                    <span class="item-rate">${rateStr}</span>
                                    <span>•</span>
                                    <span>${item.unit || 'Unit'}</span>
                                </div>
                            </div>
                            <div class="u-shrink-0">
                                <span class="badge u-bg-primary-soft u-c-primary u-fs-10px u-fw-600 u-p-2px-6px u-r-4px">Select</span>
                            </div>
                        </div>
                    `)
                    .appendTo(ul);
            };
        }

        $input.on('keydown', async function(e) {
            if (e.which === 13) {
                if (e.isDefaultPrevented()) return;
                e.preventDefault();
                var val = $(this).val().trim();
                var tr = $(this).closest('tr');
                if (val) {
                    if (this.dataset.productLookupPending) return;
                    this.dataset.productLookupPending = '1';
                    var matches;
                    try { matches = await CommercialProductLookup.search(val, allProducts, 'purchase', matchesProductMode); }
                    catch (error) { alert(error.message); return; }
                    finally { delete this.dataset.productLookupPending; }
                    if (CommercialProductLookup.normalize(this.value) !== CommercialProductLookup.normalize(val)) return;
                    var exact = CommercialProductLookup.exact(matches, val);
                    if (!exact && matches.length) {
                        $(this).autocomplete('search', val);
                        return;
                    }
                    if (exact) {
                        applyProductToRow(tr, exact);
                        tr.find('.row-qty').focus().select();
                    } else {
                        $.ajax({
                            type: 'GET',
                            url: '{{ route("product_purchase.search") }}',
                            data: { data: val },
                            success: function(data) {
                                if (data && data.length) {
                                    applyProductToRow(tr, {
                                        id: data[9],
                                        name: data[0],
                                        code: data[1],
                                        cost: parseFloat(data[2]) || 0,
                                        tax_rate: parseFloat(data[3]) || 0,
                                        unit: data[6] ? data[6].split(',')[0] : 'Unit'
                                    });
                                }
                                tr.find('.row-qty').focus().select();
                            },
                            error: function() {
                                tr.find('.row-qty').focus().select();
                            }
                        });
                    }
                } else {
                    tr.find('.row-qty').focus().select();
                }
            }
        });
    }

    // --- Add Row to Items Grid Table ---
    function addProductRow(item) {
        // Remove empty placeholder row if exists
        $('#order-table-body .empty-placeholder-row').remove();

        // If product already in grid and not manual edit, increment qty
        if (item.product_id && item.product_id > 0 && !item.is_manual && !item.preserve_line) {
            var existing = $('#order-table-body tr.order-item-row[data-product-id="' + item.product_id + '"]');
            if (existing.length) {
                var qtyInput = existing.find('.row-qty');
                var currentQty = parseFloat(qtyInput.val()) || 0;
                qtyInput.val((currentQty + (item.qty || 1)).toFixed(2)).trigger('input');
                existing.css('background-color', '#f5f3ff');
                setTimeout(function() { existing.css('background-color', ''); }, 400);
                return;
            }
        }

        rowCounter++;
        var rate = Number(item.cost ?? item.price ?? item.rate ?? 0);
        var qty = Number(item.qty ?? 1);

        // Check Header Tax Classification Mode
        var $headerTaxSel = $('#purchase_type_id');
        var headerOpt = $headerTaxSel.find('option:selected');
        var isHeaderMulti = headerOpt.data('is-multi') == '1' || headerOpt.data('is-multi') === 1;
        var headerRateAttr = headerOpt.data('tax-rate');
        var headerRate = (headerRateAttr !== undefined && headerRateAttr !== '' && headerRateAttr !== null) ? parseFloat(headerRateAttr) : null;

        var taxRate = item.tax_rate !== undefined ? parseFloat(item.tax_rate) : 0;
        var isTaxLocked = false;
        if (!item.preserve_line && !isHeaderMulti && headerRate !== null && !isNaN(headerRate)) {
            taxRate = headerRate;
            isTaxLocked = true;
        }

        var amount = rate * qty;
        var taxAmount = amount * (taxRate / 100);
        var lineTotal = amount + taxAmount;
        var unitVal = item.unit || item.unit_code || 'Pc';

        var tr = $(`
            <tr class="order-item-row" data-row-id="${rowCounter}" data-product-id="${item.product_id || 0}" data-batch-id="${Number(item.product_batch_id) || ''}">
                <td class="u-ta-center u-fw-600 u-c-text-muted u-va-middle">${$('#order-table-body tr.order-item-row').length + 1}</td>
                <td>
                    <input type="text" name="product_name_text[]" class="form-control form-control-sm row-item-name" value="${escapeHtml(item.product_name || '')}" placeholder="Search item or scan..." title="${item.product_code ? 'Code: ' + item.product_code : ''}" autocomplete="off">
                    <input type="hidden" name="product_id[]" class="row-product-id" value="${item.product_id || 0}">
                    <input type="hidden" name="product_code[]" class="row-product-code" value="${escapeHtml(item.product_code || '')}">
                </td>
                <td>
                    <select name="purchase_type_line[]" class="form-control form-control-sm row-type-select">
                        <option value="Purchase" selected>Purchase</option>
                        <option value="Taxable">Taxable</option>
                        <option value="Exempt">Exempt</option>
                        <option value="Zero Rated">Zero</option>
                    </select>
                </td>
                <td>
                    <select name="purchase_unit[]" class="form-control form-control-sm row-unit-select">
                        ${unitList.map(u => `<option value="${escapeHtml(u.unit_name)}" ${u.unit_name === unitVal || u.unit_code === unitVal ? 'selected' : ''}>${escapeHtml(u.unit_name)}</option>`).join('')}
                    </select>
                </td>
                <td class="u-ta-right">
                    <input type="number" name="net_unit_cost[]" class="form-control form-control-sm row-rate text-right" value="${rate}" step="0.0001" min="0" required>
                    <input type="hidden" name="unit_cost[]" class="row-unit-cost-input" value="${rate.toFixed(decimalPlaces)}">
                    <input type="hidden" name="net_unit_price[]" class="row-net-unit-price" value="${rate.toFixed(decimalPlaces)}">
                    <input type="hidden" name="net_unit_margin[]" value="0">
                    <input type="hidden" name="net_unit_margin_type[]" value="percentage">
                </td>
                <td class="u-ta-center">
                    <input type="number" name="qty[]" class="form-control form-control-sm row-qty text-center" value="${qty}" step="any" min="0.01">
                    <input type="hidden" name="recieved[]" class="row-recieved-input" value="${qty}">
                </td>
                <td class="u-ta-right">
                    <input type="number" name="row_amount[]" class="form-control form-control-sm row-amount text-right" value="${amount.toFixed(decimalPlaces)}" step="0.01">
                    <input type="hidden" name="subtotal[]" class="row-subtotal-input" value="${lineTotal.toFixed(decimalPlaces)}">
                </td>
                <td>
                    <select name="tax_rate[]" class="form-control form-control-sm row-tax-rate ${isTaxLocked ? 'is-locked' : ''}">
                        <option value="0" ${taxRate == 0 ? 'selected' : ''}>0%</option>
                        ${(function() {
                            var opts = '';
                            if (taxList && taxList.length) {
                                taxList.forEach(function(t) {
                                    var r = parseFloat(t.rate) || 0;
                                    if (r > 0) {
                                        opts += `<option value="${r}" ${r === taxRate ? 'selected' : ''}>${r}%</option>`;
                                    }
                                });
                            }
                            if (taxRate > 0 && !(taxList || []).some(function(t){ return parseFloat(t.rate) === taxRate; })) {
                                opts += `<option value="${taxRate}" selected>${taxRate}%</option>`;
                            }
                            return opts;
                        })()}
                    </select>
                    <input type="hidden" name="tax[]" class="row-tax-amount-input" value="${taxAmount.toFixed(decimalPlaces)}">
                </td>
                <td class="u-ta-right">
                    <input type="number" class="form-control form-control-sm row-total text-right u-bg-surface-2 u-fw-700 u-c-success" value="${lineTotal.toFixed(decimalPlaces)}" step="0.01" readonly>
                </td>
                <td class="u-ta-center u-va-middle">
                    <div class="u-d-flex u-items-center u-justify-center u-gap-2px">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-row" title="Edit row details (batch, expiry, serial)">
                            <i class="dripicons-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-row" title="Delete Row">
                            <i class="dripicons-trash"></i>
                        </button>
                    </div>
                    <input type="hidden" name="discount[]" class="row-discount-val" value="${item.discount || 0}">
                    <input type="hidden" name="batch_no[]" class="row-batch-val" value="${escapeHtml(item.batch_no || '')}">
                    <input type="hidden" name="expired_date[]" class="row-expire-val" value="${escapeHtml(item.expired_date || '')}">
                    <input type="hidden" name="imei_number[]" class="row-imei-val" value="${escapeHtml(item.imei_number || '')}">
                </td>
            </tr>
        `);

        $('#order-table-body').append(tr);
        initRowItemAutocomplete(tr.find('.row-item-name'));
        if (item.is_manual) {
            tr.find('.row-item-name').focus();
        }
        recalcTableSummary();
        $('#items-meta-count').text($('#order-table-body tr.order-item-row').length + ' line(s)');
        $(document).trigger('command-center-row-added', [tr]);
    }

    // Manual Add Row (+ Add row button)
    $('#btn-add-item-row').on('click', function() {
        addProductRow({
            product_id: 0,
            product_name: '',
            product_code: '',
            price: 0,
            cost: 0,
            tax_rate: 0,
            unit: 'Unit',
            qty: 1,
            is_manual: true
        });
    });

    // Multi-Item Modal Batch Picker
    function populateMultiItemModal() {
        var tbody = $('#multi-item-tbody');
        tbody.empty();
        allProducts.filter(matchesProductMode).forEach(function(p) {
            tbody.append(`
                <tr class="multi-item-row" data-id="${p.id}" data-name="${escapeHtml((p.name || '').toLowerCase())}" data-code="${escapeHtml((p.code || '').toLowerCase())}">
                    <td class="u-ta-center">
                        <input type="checkbox" class="multi-item-check" data-product-id="${p.id}">
                    </td>
                    <td>
                        <div class="u-fw-600 u-c-text">${escapeHtml(p.name)}</div>
                    </td>
                    <td>
                        <span class="badge badge-light border">${escapeHtml(p.code)}</span>
                    </td>
                    <td class="u-ta-right u-fw-600 u-c-success">
                        ₹ ${Number(p.cost ?? p.price ?? 0).toFixed(decimalPlaces)}
                    </td>
                    <td class="u-ta-center">
                        <input type="number" class="form-control form-control-sm multi-item-qty text-center u-h-24px u-w-60px u-m-auto u-fs-11px" value="1" min="1">
                    </td>
                    <td class="u-ta-center u-c-text-muted u-fs-11px">
                        ${escapeHtml(p.unit || 'Unit')}
                    </td>
                </tr>
            `);
        });
        updateMultiItemCount();
    }

    $('#multi-item-modal').on('show.bs.modal', function() {
        populateMultiItemModal();
        $('#multi-item-filter').val('');
        $('#multi-item-select-all').prop('checked', false);
    });

    $('#multi-item-filter').on('input', function() {
        var term = $(this).val().toLowerCase().trim();
        $('#multi-item-tbody tr').each(function() {
            var name = $(this).data('name') || '';
            var code = $(this).data('code') || '';
            if (name.includes(term) || code.includes(term)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    $('#multi-item-select-all').on('change', function() {
        var checked = $(this).is(':checked');
        $('#multi-item-tbody tr:visible .multi-item-check').prop('checked', checked);
        updateMultiItemCount();
    });

    $(document).on('change', '.multi-item-check', function() {
        updateMultiItemCount();
    });

    function updateMultiItemCount() {
        var count = $('.multi-item-check:checked').length;
        $('#multi-item-count-badge').text(count + ' selected');
        $('#btn-add-selected-items').text('+ Add ' + count + ' Selected Item' + (count === 1 ? '' : 's') + ' to Voucher');
    }

    $('#btn-add-selected-items').on('click', function() {
        $('.multi-item-check:checked').each(function() {
            var productId = $(this).data('product-id');
            var tr = $(this).closest('tr');
            var qty = parseFloat(tr.find('.multi-item-qty').val()) || 1;
            var prod = allProducts.find(p => String(p.id) === String(productId));
            if (prod) {
                addProductRow({
                    product_id: prod.id,
                    product_name: prod.name,
                    product_code: prod.code,
                    price: prod.price,
                    cost: prod.cost,
                    tax_rate: prod.tax_rate,
                    unit: prod.unit,
                    qty: qty
                });
            }
        });
        $('#multi-item-modal').modal('hide');
        $('.multi-item-check').prop('checked', false);
        $('#multi-item-select-all').prop('checked', false);
        updateMultiItemCount();
        $productSearch.focus();
    });

    // Quick Create Item - Full Compact Add Product Modal Handling
    function calcQuickPrice() {
        var c = parseFloat($('#quick-item-cost').val()) || 0;
        var m = parseFloat($('#quick-item-margin').val()) || 0;
        var type = $('#quick-item-margin-type').val() || 'percentage';
        var p = c;
        if (type === 'percentage') {
            p = c + (c * (m / 100));
        } else {
            p = c + m;
        }
        $('#quick-item-price').val(p.toFixed(decimalPlaces));
    }
    $('#quick-item-cost, #quick-item-margin, #quick-item-margin-type').on('input change', calcQuickPrice);
    $('#quick-item-price').on('input', function() {
        var c = parseFloat($('#quick-item-cost').val()) || 0;
        var p = parseFloat($(this).val()) || 0;
        var type = $('#quick-item-margin-type').val() || 'percentage';
        if (type === 'percentage') {
            if (c > 0) {
                var m = ((p - c) / c) * 100;
                $('#quick-item-margin').val(m.toFixed(2));
            }
        } else {
            $('#quick-item-margin').val((p - c).toFixed(decimalPlaces));
        }
    });

    $('#btn-quick-gen-code').on('click', function() {
        $('#quick-item-code').val('ITM-' + Math.floor(100000 + Math.random() * 900000));
    });

    // Inline Category Creation Handlers
    $('#btn-quick-add-category').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#quick-cat-name-input').val('');
        $('#quick-create-category-modal').modal('show');
    });

    $('#btn-submit-quick-cat').on('click', function() {
        var name = $('#quick-cat-name-input').val().trim();
        if (!name) {
            alert('Please enter a category name');
            return;
        }
        var btn = $(this).prop('disabled', true).text('Saving...');
        $.ajax({
            type: 'POST',
            url: '{{ route("categories.quick-store") }}',
            data: {
                _token: '{{ csrf_token() }}',
                name: name
            },
            success: function(res) {
                btn.prop('disabled', false).text('Save Category');
                if (res && res.category) {
                    var newOpt = `<option value="${res.category.id}" selected>${res.category.name}</option>`;
                    $('#quick-item-category').append(newOpt).val(res.category.id);
                    $('#quick-create-category-modal').modal('hide');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Save Category');
                alert('Failed to save category');
            }
        });
    });

    // Inline Brand Creation Handlers
    $('#btn-quick-add-brand').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#quick-brand-title-input').val('');
        $('#quick-create-brand-modal').modal('show');
    });

    $('#btn-submit-quick-brand').on('click', function() {
        var title = $('#quick-brand-title-input').val().trim();
        if (!title) {
            alert('Please enter a brand title');
            return;
        }
        var btn = $(this).prop('disabled', true).text('Saving...');
        $.ajax({
            type: 'POST',
            url: '{{ route("brands.quick-store") }}',
            data: {
                _token: '{{ csrf_token() }}',
                title: title
            },
            success: function(res) {
                btn.prop('disabled', false).text('Save Brand');
                if (res && res.brand) {
                    var newOpt = `<option value="${res.brand.id}" selected>${res.brand.title}</option>`;
                    $('#quick-item-brand').append(newOpt).val(res.brand.id);
                    $('#quick-create-brand-modal').modal('hide');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Save Brand');
                alert('Failed to save brand');
            }
        });
    });

    // --- Inline Party Creation Handlers ---
    $(document).on('click', '.btn-open-party-modal, #btn-quick-new-party', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var partyType = $(this).data('party-type') || 'supplier';
        $('#party_type_input').val(partyType);
        if (partyType === 'supplier') {
            $('#quick-party-modal-title').html('<i class="fa fa-truck text-primary mr-1"></i> Add Supplier');
            $('#party-both-checkbox').prop('checked', false);
            $('#party-customer-group-col').hide();
        } else {
            $('#quick-party-modal-title').html('<i class="fa fa-user-plus text-primary mr-1"></i> Add Customer');
            $('#party-both-checkbox').prop('checked', false);
            $('#party-customer-group-col').show();
        }
        $('#party-form-error').hide().empty();
        $('#quick-create-party-modal').modal('show');
        setTimeout(function() {
            $('#party-name').focus();
        }, 300);
    });

    $('#party-both-checkbox').on('change', function() {
        if ($(this).is(':checked') || $('#party_type_input').val() === 'customer') {
            $('#party-customer-group-col').slideDown(200);
        } else {
            $('#party-customer-group-col').slideUp(200);
        }
    });

    // --- GSTIN 1-Click Auto-Fill & Instant Statutory Decoding (100% Free) ---
    var GST_STATE_MAP = {
        '01': 'Jammu and Kashmir', '02': 'Himachal Pradesh', '03': 'Punjab', '04': 'Chandigarh',
        '05': 'Uttarakhand', '06': 'Haryana', '07': 'Delhi', '08': 'Rajasthan',
        '09': 'Uttar Pradesh', '10': 'Bihar', '11': 'Sikkim', '12': 'Arunachal Pradesh',
        '13': 'Nagaland', '14': 'Manipur', '15': 'Mizoram', '16': 'Tripura',
        '17': 'Meghalaya', '18': 'Assam', '19': 'West Bengal', '20': 'Jharkhand',
        '21': 'Odisha', '22': 'Chhattisgarh', '23': 'Madhya Pradesh', '24': 'Gujarat',
        '26': 'Dadra and Nagar Haveli and Daman and Diu', '27': 'Maharashtra',
        '28': 'Andhra Pradesh (Old)', '29': 'Karnataka', '30': 'Goa', '31': 'Lakshadweep',
        '32': 'Kerala', '33': 'Tamil Nadu', '34': 'Puducherry', '35': 'Andaman and Nicobar Islands',
        '36': 'Telangana', '37': 'Andhra Pradesh', '38': 'Ladakh', '97': 'Other Territory'
    };

    var GST_PAN_CONSTITUTIONS = {
        'P': 'Proprietorship / Individual', 'C': 'Company / Corporation', 'F': 'Partnership Firm / LLP',
        'H': 'Hindu Undivided Family (HUF)', 'A': 'Association of Persons (AOP)', 'B': 'Body of Individuals (BOI)',
        'G': 'Government Agency', 'J': 'Artificial Juridical Person', 'L': 'Local Authority', 'T': 'Trust'
    };

    function validateGstinChecksum(gstin) {
        if (!/^(0[1-9]|[12][0-9]|3[0-8])[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(gstin)) {
            return false;
        }
        var alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        var sum = 0, factor = 2;
        for (var i = 13; i >= 0; i--) {
            var n = alphabet.indexOf(gstin[i]) * factor;
            sum += Math.floor(n / 36) + (n % 36);
            factor = (factor === 2) ? 1 : 2;
        }
        return alphabet[(36 - (sum % 36)) % 36] === gstin[14];
    }

    function updateGstClientInsight(gstin) {
        gstin = (gstin || '').trim().toUpperCase();
        if (gstin.length < 2) {
            $('#party-modal-gst-badge').hide();
            $('#party-gst-preview-strip').hide();
            $('#party-pan-display').val('');
            return;
        }
        var stateCode = gstin.substring(0, 2);
        var stateName = GST_STATE_MAP[stateCode];

        // Auto-fill State input field immediately
        if (stateName && !$('#party-state').val()) {
            $('#party-state').val(stateName);
        }

        // Full 10-char PAN extraction (characters 2 to 12)
        var pan = gstin.length >= 12 ? gstin.substring(2, 12) : (gstin.length > 2 ? gstin.substring(2) : '');
        $('#party-pan-display').val(pan);
        var panType = pan.length >= 4 ? pan[3] : '';
        var constitution = GST_PAN_CONSTITUTIONS[panType] || '';

        // Supply type compared to Tamil Nadu (33)
        var isLocal = (stateCode === '33');
        var taxRule = isLocal ? 'Intra-State (CGST+SGST)' : 'Inter-State (IGST)';

        if (stateName) {
            $('#gst-strip-state').text(stateCode + ' - ' + stateName).show();
            $('#gst-strip-const').text(constitution || 'Business Entity').toggle(!!constitution);
            $('#gst-strip-tax').text(taxRule).show();
            $('#party-gst-preview-strip').slideDown(150);
        }

        if (gstin.length === 15) {
            var isValid = validateGstinChecksum(gstin);
            if (isValid) {
                $('#party-modal-gst-badge').removeClass('badge-danger badge-secondary').addClass('badge-success').text('✔ Valid GSTIN').show();
                $('#gst-strip-msg').html('<span class="text-success font-weight-bold">✔ Luhn Mod-36 Checksum verified.</span> Click <strong>Fetch Details</strong> to load full registered name, address, and live filing status.');
            } else {
                $('#party-modal-gst-badge').removeClass('badge-success badge-secondary').addClass('badge-danger').text('✖ Invalid Checksum').show();
                $('#gst-strip-msg').html('<span class="text-danger font-weight-bold">GSTIN checksum does not match official statutory algorithm.</span>');
            }
        } else {
            $('#party-modal-gst-badge').removeClass('badge-success badge-danger').addClass('badge-secondary').text(gstin.length + '/15').show();
            $('#gst-strip-msg').text('Enter full 15 characters to verify official checksum and fetch details.');
        }
    }

    $('#party-vat-number').on('input change', function() {
        var val = $(this).val().toUpperCase();
        $(this).val(val);
        updateGstClientInsight(val);
    });

    function executeGstLookup() {
        var gstin = $('#party-vat-number').val().trim().toUpperCase();
        if (!gstin) {
            alert('Please enter a GSTIN first.');
            $('#party-vat-number').focus();
            return;
        }

        var $btn = $('#btn-fetch-gstin');
        var originalBtn = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i> Fetching...');

        $.ajax({
            type: 'POST',
            url: '{{ route("parties.gst-lookup") }}',
            data: {
                _token: '{{ csrf_token() }}',
                gstin: gstin
            },
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false).html(originalBtn);
                if (res && res.success) {
                    if (res.pan) {
                        $('#party-pan-display').val(res.pan);
                    }
                    if (res.state_name) {
                        $('#party-state').val(res.state_name);
                    }
                    if (res.is_valid) {
                        $('#party-modal-gst-badge').removeClass('badge-danger badge-secondary').addClass('badge-success').text('✔ Valid GSTIN').show();
                    } else if (res.error) {
                        $('#party-modal-gst-badge').removeClass('badge-success badge-secondary').addClass('badge-danger').text('✖ Invalid Checksum').show();
                    }

                    $('#gst-strip-state').text((res.state_code || '') + ' - ' + (res.state_name || '')).show();
                    $('#gst-strip-const').text(res.constitution || 'Business Entity').toggle(!!res.constitution);
                    $('#gst-strip-tax').text(res.tax_rule || '').show();
                    $('#party-gst-preview-strip').slideDown(150);

                    var p = res.party || res.gst_details;
                    if (p) {
                        // Populate Input Fields
                        if (p.name) $('#party-name').val(p.name);
                        if (p.company_name) $('#party-company-name').val(p.company_name);
                        if (p.address) $('#party-address').val(p.address);
                        if (p.city) $('#party-city').val(p.city);
                        if (p.state) $('#party-state').val(p.state);
                        if (p.postal_code) $('#party-postal-code').val(p.postal_code);
                        if (p.country) $('#party-country').val(p.country);
                        if (p.phone_number && !$('#party-phone-number').val()) $('#party-phone-number').val(p.phone_number);
                        if (p.email && !$('#party-email').val()) $('#party-email').val(p.email);

                        // Populate Badges
                        var status = p.status || p.registration_status;
                        if (status) {
                            var isAct = status.toLowerCase() === 'active' || status.toLowerCase() === 'act';
                            var isCnl = status.toLowerCase().indexOf('cancel') !== -1;
                            var badgeClass = isAct ? 'badge-success' : (isCnl ? 'badge-danger' : 'badge-warning');
                            var statusLabel = '● ' + status;
                            if (p.cancellation_date) {
                                statusLabel += ' (' + p.cancellation_date + ')';
                            }
                            $('#gst-strip-status').removeClass('badge-success badge-danger badge-warning').addClass(badgeClass).text(statusLabel).show();
                        } else {
                            $('#gst-strip-status').hide();
                        }

                        if (p.taxpayer_type) {
                            $('#gst-strip-taxpayer').text(p.taxpayer_type).show();
                        } else {
                            $('#gst-strip-taxpayer').hide();
                        }

                        if (p.registration_date) {
                            $('#gst-strip-regdate').text('Reg: ' + p.registration_date).show();
                        } else {
                            $('#gst-strip-regdate').hide();
                        }

                        if (p.gstr3b_filed_till) {
                            $('#gst-strip-filing').text('GSTR-3B: ' + p.gstr3b_filed_till).show();
                        } else if (p.filing_frequency) {
                            $('#gst-strip-filing').text('Filing: ' + p.filing_frequency).show();
                        } else {
                            $('#gst-strip-filing').hide();
                        }

                        if (p.nature_of_business) {
                            var nat = p.nature_of_business;
                            if (nat.length > 40) nat = nat.substring(0, 38) + '...';
                            $('#gst-strip-nature').text('Business: ' + nat).attr('title', p.nature_of_business).show();
                        } else {
                            $('#gst-strip-nature').hide();
                        }

                        var sourceLabel = p.source === 'live_api' ? '✔ Live GST Registry match!' : '✔ Matched existing party in ERP!';
                        var detailMsg = '<span class="text-success font-weight-bold">' + sourceLabel + '</span> Registered trade name and full address loaded.';
                        if (p.legal_name && p.trade_name && p.legal_name !== p.trade_name) {
                            detailMsg += ' <span class="text-muted d-block mt-1 u-fs-11px">Legal: <em>' + p.legal_name + '</em> | Trade: <em>' + p.trade_name + '</em></span>';
                        }
                        $('#gst-strip-msg').html(detailMsg);
                    } else if (res.provider_message) {
                        $('#gst-strip-msg').html('<span class="text-warning font-weight-bold">⚠ Provider note:</span> ' + res.provider_message);
                    } else {
                        $('#gst-strip-msg').html('<span class="text-info font-weight-bold">✔ Statutory Verified:</span> ' + res.state_name + ' (' + res.constitution + '). State set automatically.');
                    }
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html(originalBtn);
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'GST lookup failed.';
                alert(msg);
            }
        });
    }

    $('#btn-fetch-gstin').on('click', function(e) {
        e.preventDefault();
        executeGstLookup();
    });

    $('#party-vat-number').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            executeGstLookup();
        }
    });

    $('#btn-open-gst-portal').on('click', function(e) {
        var gstin = $('#party-vat-number').val().trim().toUpperCase();
        if (gstin && navigator.clipboard) {
            navigator.clipboard.writeText(gstin);
        }
    });

    $('#quick-create-party-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#btn-save-party');
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i> Saving...');
        $('#party-form-error').hide().empty();

        var formData = $(this).serialize();

        $.ajax({
            type: 'POST',
            url: '{{ route("parties.quick-store") }}',
            data: formData,
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false).html(originalText);
                if (res && res.success) {
                    var party = res.supplier || res.customer;
                    if (party && $('#supplier_id').length) {
                        var label = party.name + ' (' + (party.company_name || 'Individual') + ')';
                        var newOpt = $('<option>', {
                            value: party.id,
                            text: label,
                            'data-tax-no': party.vat_number || party.tax_no || '',
                            'data-address': party.address || '',
                            'data-city': party.city || '',
                            'data-state': party.state || '',
                            'data-postal': party.postal_code || '',
                            'data-credit-days': party.credit_days || 0
                        });
                        $('#supplier_id').append(newOpt);
                        $('#supplier_id').val(party.id).trigger('change');
                        if ($.fn.selectpicker) {
                            $('#supplier_id').selectpicker('refresh');
                        }
                    }

                    $('#quick-create-party-modal').modal('hide');
                    $('#quick-create-party-form')[0].reset();
                } else {
                    $('#party-form-error').text((res && res.message) ? res.message : 'Failed to save party').show();
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html(originalText);
                var errText = 'An error occurred while saving party.';
                if (xhr.responseJSON && xhr.responseJSON.errors) {
                    var msgs = [];
                    $.each(xhr.responseJSON.errors, function(k, v) {
                        msgs.push(v[0]);
                    });
                    errText = msgs.join('<br>');
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    errText = xhr.responseJSON.message;
                }
                $('#party-form-error').html(errText).show();
            }
        });
    });

    $('#quick-create-item-form').on('submit', function(e) {
        e.preventDefault();
        var name = $('#quick-item-name').val().trim();
        var code = $('#quick-item-code').val().trim();
        var cost = parseFloat($('#quick-item-cost').val()) || 0;
        var price = parseFloat($('#quick-item-price').val()) || cost;
        var unitId = $('#quick-item-unit').val();
        var taxOption = $('#quick-item-tax option:selected');
        var taxRate = parseFloat(taxOption.data('rate')) || 0;

        if (!name) return;

        var btn = $('#btn-quick-create-submit');
        btn.prop('disabled', true).text('Saving...');

        var postData = {
            _token: '{{ csrf_token() }}',
            name: name,
            code: code,
            type: $('#quick-item-type').val() || 'standard',
            barcode_symbology: $('#quick-item-symbology').val() || 'C128',
            brand_id: $('#quick-item-brand').val() || null,
            category_id: $('#quick-item-category').val() || null,
            unit_id: unitId,
            sale_unit_id: $('#quick-item-sale-unit').val() || unitId,
            purchase_unit_id: $('#quick-item-purchase-unit').val() || unitId,
            cost: cost,
            price: price,
            profit_margin: parseFloat($('#quick-item-margin').val()) || null,
            profit_margin_type: $('#quick-item-margin-type').val() || 'percentage',
            wholesale_price: parseFloat($('#quick-item-wholesale').val()) || null,
            daily_sale_objective: parseFloat($('#quick-item-daily-sale').val()) || null,
            alert_quantity: parseFloat($('#quick-item-alert-qty').val()) || null,
            tax_id: $('#quick-item-tax').data('gst') ? null : ($('#quick-item-tax').val() || null),
            tax_category_id: $('#quick-item-tax').data('gst') ? ($('#quick-item-tax').val() || null) : undefined,
            hsn_code: $('#quick-item-hsn').val() || undefined,
            negative_stock_policy: $('#negative-stock-policy').val() || '',
            tax_method: $('#quick-item-tax-method').val() || 1,
            warranty: $('#quick-item-warranty').val() || null,
            warranty_type: $('#quick-item-warranty-type').val() || 'months',
            guarantee: $('#quick-item-guarantee').val() || null,
            guarantee_type: $('#quick-item-guarantee-type').val() || 'months',
            is_batch: $('#quick-item-is-batch').is(':checked') ? 1 : 0,
            is_imei: $('#quick-item-is-imei').is(':checked') ? 1 : 0,
            featured: $('#quick-item-featured').is(':checked') ? 1 : 0,
            is_embeded: $('#quick-item-is-embeded').is(':checked') ? 1 : 0,
            is_online: $('#quick-item-is-online').is(':checked') ? 1 : 0,
            in_stock: $('#quick-item-in-stock').is(':checked') ? 1 : 0,
            product_details: $('#quick-item-details').val() || null,
        };

        $.ajax({
            type: 'POST',
            url: '{{ route("products.quick-store") }}',
            data: postData,
            success: function(res) {
                btn.prop('disabled', false).html('<i class="dripicons-checkmark"></i> 💾 Save & Add to Voucher');
                var p = (res && res.product) ? res.product : {
                    id: 0,
                    name: name,
                    code: code || ('ITM-' + Math.floor(100000 + Math.random() * 900000)),
                    price: price,
                    cost: cost,
                    tax_rate: taxRate,
                    unit: $('#quick-item-unit option:selected').text().split('(')[0].trim() || 'Unit',
                    value: (code || name) + '|' + name,
                    label: (code || name) + ' - ' + name
                };

                allProducts.unshift(p);
                lims_product_code.unshift(p.value);

                addProductRow({
                    product_id: p.id,
                    product_name: p.name,
                    product_code: p.code,
                    price: p.cost || cost,
                    cost: p.cost || cost,
                    tax_rate: p.tax_rate || taxRate,
                    unit: p.unit,
                    qty: 1
                });

                $('#quick-create-item-modal').modal('hide');
                $('#quick-create-item-form')[0].reset();
                $('#quick-item-code').val('ITM-' + Math.floor(100000 + Math.random() * 900000));
                $productSearch.focus();
            },
            error: function(err) {
                btn.prop('disabled', false).html('<i class="dripicons-checkmark"></i> 💾 Save & Add to Voucher');
                var msg = 'Failed to create product.';
                if (err && err.responseJSON && err.responseJSON.message) {
                    msg += ' ' + err.responseJSON.message;
                }
                alert(msg);
            }
        });
    });


    // Delete Row
    $(document).on('click', '.btn-delete-row', function() {
        $(this).closest('tr').remove();
        reindexRows();
        recalcTableSummary();
        $('#items-meta-count').text($('#order-table-body tr.order-item-row').length + ' line(s)');
    });

    // Row Rate Change -> Updates Amount & Total
    $(document).on('input change', '.row-rate', function() {
        var tr = $(this).closest('tr');
        var rate = parseFloat($(this).val()) || 0;
        var qty = parseFloat(tr.find('.row-qty').val()) || 0;
        var taxRate = parseFloat(tr.find('.row-tax-rate').val()) || 0;

        var amount = rate * qty;
        var taxAmount = amount * (taxRate / 100);
        var lineTotal = amount + taxAmount;

        tr.find('.row-amount').val(amount.toFixed(decimalPlaces));
        tr.find('.row-tax-amount-input').val(taxAmount.toFixed(decimalPlaces));
        tr.find('.row-subtotal-input').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-total').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-unit-cost-input').val(rate.toFixed(decimalPlaces));
        tr.find('.row-net-unit-price').val(rate.toFixed(decimalPlaces));
        recalcTableSummary();
    });

    // Row Qty Change -> Updates Amount & Total
    $(document).on('input change', '.row-qty', function() {
        var tr = $(this).closest('tr');
        var qty = parseFloat($(this).val()) || 0;
        var rate = parseFloat(tr.find('.row-rate').val()) || 0;
        var taxRate = parseFloat(tr.find('.row-tax-rate').val()) || 0;

        var amount = rate * qty;
        var taxAmount = amount * (taxRate / 100);
        var lineTotal = amount + taxAmount;

        tr.find('.row-amount').val(amount.toFixed(decimalPlaces));
        tr.find('.row-tax-amount-input').val(taxAmount.toFixed(decimalPlaces));
        tr.find('.row-subtotal-input').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-total').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-recieved-input').val(qty);
        recalcTableSummary();
    });

    // Row Amount Change -> Two-Way Reactive Recalculation of Rate & Total
    $(document).on('input change', '.row-amount', function() {
        var tr = $(this).closest('tr');
        var amount = parseFloat($(this).val()) || 0;
        var qty = parseFloat(tr.find('.row-qty').val()) || 0;
        var taxRate = parseFloat(tr.find('.row-tax-rate').val()) || 0;

        var rate = qty > 0 ? (amount / qty) : 0;
        var taxAmount = amount * (taxRate / 100);
        var lineTotal = amount + taxAmount;

        tr.find('.row-rate').val(rate.toFixed(decimalPlaces));
        tr.find('.row-unit-cost-input').val(rate.toFixed(decimalPlaces));
        tr.find('.row-net-unit-price').val(rate.toFixed(decimalPlaces));
        tr.find('.row-tax-amount-input').val(taxAmount.toFixed(decimalPlaces));
        tr.find('.row-subtotal-input').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-total').val(lineTotal.toFixed(decimalPlaces));
        recalcTableSummary();
    });

    // Row Tax Rate Change -> Updates Tax Amount & Total
    $(document).on('input change', '.row-tax-rate', function() {
        var tr = $(this).closest('tr');
        var amount = parseFloat(tr.find('.row-amount').val()) || 0;
        var taxRate = parseFloat($(this).val()) || 0;

        var taxAmount = amount * (taxRate / 100);
        var lineTotal = amount + taxAmount;

        tr.find('.row-tax-amount-input').val(taxAmount.toFixed(decimalPlaces));
        tr.find('.row-subtotal-input').val(lineTotal.toFixed(decimalPlaces));
        tr.find('.row-total').val(lineTotal.toFixed(decimalPlaces));
        recalcTableSummary();
    });

    // --- Reactive Tax Classification Sync to Items Grid ---
    function syncTaxClassificationToRows() {
        var $sel = $('#purchase_type_id');
        var selectedOption = $sel.find('option:selected');
        var isMulti = selectedOption.data('is-multi') == '1' || selectedOption.data('is-multi') === 1;
        var taxRateAttr = selectedOption.data('tax-rate');
        var fixedRate = (taxRateAttr !== undefined && taxRateAttr !== '' && taxRateAttr !== null) ? parseFloat(taxRateAttr) : null;

        if (!isMulti && fixedRate !== null && !isNaN(fixedRate)) {
            // Apply single GST classification across all table rows & lock select
            $('#order-table-body tr.order-item-row').each(function() {
                var tr = $(this);
                var tSel = tr.find('.row-tax-rate');
                var matched = false;
                tSel.find('option').each(function() {
                    if (parseFloat($(this).val()) === fixedRate) {
                        tSel.val($(this).val());
                        matched = true;
                        return false;
                    }
                });
                if (!matched) {
                    tSel.append(`<option value="${fixedRate}">${fixedRate}%</option>`);
                    tSel.val(fixedRate);
                }
                tSel.css({
                    'pointer-events': 'none',
                    'background-color': '#f1f5f9',
                    'color': '#475569',
                    'cursor': 'not-allowed'
                });

                var amount = parseFloat(tr.find('.row-amount').val()) || 0;
                var taxAmount = amount * (fixedRate / 100);
                var lineTotal = amount + taxAmount;
                tr.find('.row-tax-amount-input').val(taxAmount.toFixed(decimalPlaces));
                tr.find('.row-subtotal-input').val(lineTotal.toFixed(decimalPlaces));
                tr.find('.row-total').val(lineTotal.toFixed(decimalPlaces));
            });
            recalcTableSummary();
        } else {
            // Multi-tax: Unlock so user can freely select any tax rate per row
            $('#order-table-body tr.order-item-row').each(function() {
                var tr = $(this);
                var tSel = tr.find('.row-tax-rate');
                tSel.css({
                    'pointer-events': 'auto',
                    'background-color': '',
                    'color': '',
                    'cursor': 'pointer'
                });
            });
        }
    }

    $('#purchase_type_id').on('change', syncTaxClassificationToRows);

    // Open Row Detail Modal on Pencil Click
    $(document).on('click', '.btn-edit-row', function(e) {
        e.preventDefault();
        var tr = $(this).closest('tr');
        var rowId = tr.data('row-id');
        var itemName = tr.find('.row-item-name').val() || 'Item';
        $('#modal-target-row-id').val(rowId);
        $('#row-detail-modal-title').text('Item Details: ' + itemName);
        $('#modal-row-batch').val(tr.find('.row-batch-val').val() || '');
        $('#modal-row-expire').val(tr.find('.row-expire-val').val() || '');
        $('#modal-row-imei').val(tr.find('.row-imei-val').val() || '');
        $('#modal-row-discount').val(tr.find('.row-discount-val').val() || 0);
        $('#row-detail-modal').modal('show');
    });

    // Save Row Detail Modal
    $('#btn-save-row-detail').on('click', function() {
        var rowId = $('#modal-target-row-id').val();
        var tr = $('#order-table-body tr.order-item-row[data-row-id="' + rowId + '"]');
        if (tr.length) {
            tr.find('.row-batch-val').val($('#modal-row-batch').val());
            tr.find('.row-expire-val').val($('#modal-row-expire').val());
            tr.find('.row-imei-val').val($('#modal-row-imei').val());
            tr.find('.row-discount-val').val($('#modal-row-discount').val());
        }
        $('#row-detail-modal').modal('hide');
    });

    // Explicit Modal Open Handlers
    $('#btn-create-item-modal').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#quick-create-item-form')[0].reset();
        $('#btn-quick-gen-code').trigger('click');
        $('#quick-create-item-modal').modal('show');
    });

    $('#btn-multi-item').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        populateMultiItemModal();
        $('#multi-item-modal').modal('show');
    });


    function reindexRows() {
        var rows = $('#order-table-body tr.order-item-row');
        $('#order-table-body .empty-placeholder-row').remove();
        if (rows.length === 0) {
            $('#order-table-body').append(`
                <tr class="empty-placeholder-row">
                    <td colspan="10" class="text-center text-muted py-4 u-fs-12px">
                        No items added yet. Search or scan above or click "+ Add row".
                    </td>
                </tr>
            `);
        } else {
            rows.each(function(index) {
                $(this).find('td:first').text(index + 1);
            });
        }
    }

    // --- Recalculate Table Summary Totals ---
    function recalcTableSummary() {
        var net = 0;
        var tax = 0;
        var grand = 0;
        var totalQty = 0;
        var count = 0;

        $('#order-table-body tr.order-item-row').each(function() {
            count++;
            var rate = parseFloat($(this).find('.row-rate').val()) || 0;
            var qty = parseFloat($(this).find('.row-qty').val()) || 0;
            var taxRate = parseFloat($(this).find('.row-tax-rate').val()) || 0;
            var amount = rate * qty;
            var taxAmount = amount * (taxRate / 100);

            net += amount;
            tax += taxAmount;
            grand += (amount + taxAmount);
            totalQty += qty;
        });

        grand += (Number($('#hidden-shipping-cost').val()) || 0) - (Number($('#hidden-order-discount').val()) || 0);

        $('#display-net-amount').text('₹ ' + net.toFixed(decimalPlaces));
        $('#display-tax-amount').text('₹ ' + tax.toFixed(decimalPlaces));
        $('#display-grand-total').text('₹ ' + grand.toFixed(decimalPlaces));
        $('#header-grand-total-display').text('₹ ' + grand.toFixed(decimalPlaces));
        $('#items-meta-count').text(count + ' line(s) • 7 per page');

        $('#hidden-total-qty').val(totalQty);
        $('#hidden-total-cost').val(net.toFixed(decimalPlaces));
        $('#hidden-total-tax').val(tax.toFixed(decimalPlaces));
        $('#hidden-grand-total').val(grand.toFixed(decimalPlaces));
    }

    // --- Supplier Selection / Party Card Updates ---
    function updatePartyCard() {
        var opt = $('#supplier_id option:selected');
        if (opt.length && opt.val() && opt.val() != '0') {
            var gstin = opt.data('tax-no') || '';
            var addr = opt.data('address') || '';
            var city = opt.data('city') || '';
            var state = opt.data('state') || '';
            var postal = opt.data('postal') || '';
            var credit = opt.data('credit-days') || 0;

            var fullAddr = [addr, city, state, postal].filter(Boolean).join(', ');
            if (!fullAddr && !gstin) {
                fullAddr = opt.text();
            }

            $('#party-gst-badge').text(gstin || 'No GSTIN recorded');
            $('#party-credit-badge').text('Credit: ' + credit + ' days');
            $('#party-address-text').text(fullAddr || 'Address not registered');
            $('#party-info-card').slideDown(150);
            $('#header-credit-days').attr('placeholder', credit);
        } else {
            $('#party-gst-badge').text('—');
            $('#party-address-text').text('Select a party to see GSTIN and address');
            $('#party-credit-badge').text('Credit: — days');
            $('#header-credit-days').attr('placeholder', '—');
        }
    }
    $('#supplier_id').on('change', updatePartyCard);

    // --- Terms: credit days for this bill. Blank posts nothing, so the server uses the supplier's standard days. ---
    function setCreditDays(value) {
        $('#header-credit-days, #hidden-credit-days, #drawer-credit-days').val(value);
    }
    $('#header-credit-days').on('input change', function () {
        $('#hidden-credit-days, #drawer-credit-days').val(this.value);
    });
    $('#drawer-credit-days').on('input change', function () {
        $('#header-credit-days').val(this.value);
    });
    function syncTerms() {
        var method = $('#input-paying-method').val() || 'Credit', cash = method !== 'Credit';
        $('#terms-credit').prop('hidden', cash);
        $('#terms-cash').prop('hidden', !cash).find('strong').text(method);
        $('#header-credit-days').prop('disabled', cash);
        if (cash) {
            if (!$('#header-credit-days').is('[data-credit-before-cash]')) $('#header-credit-days').attr('data-credit-before-cash', $('#header-credit-days').val());
            $('#hidden-credit-days, #drawer-credit-days').val(0);
        } else if ($('#header-credit-days').is('[data-credit-before-cash]')) {
            setCreditDays($('#header-credit-days').attr('data-credit-before-cash'));
            $('#header-credit-days').removeAttr('data-credit-before-cash');
        }
    }
    // The drawer's own handler copies its method into the form first, so read it on the next tick.
    $('#drawer-paying-method').on('change', function () { setTimeout(syncTerms); });
    $(document).on('command-center-reset', function () { $('#header-credit-days').removeAttr('data-credit-before-cash'); setCreditDays(''); setTimeout(syncTerms); });
    $(document).on('command-center-loaded', function () { setTimeout(function () { $('#header-credit-days').val($('#hidden-credit-days').val()); syncTerms(); }); });

    // --- Supplier standard terms: applied only when the user picks a supplier, never when a saved bill or draft restores one. ---
    function applyPartyDefaults() {
        var opt = $('#supplier_id option:selected');
        if (!opt.val()) return;
        var d = opt.data('defaults') || {};
        $('#header-credit-days').removeAttr('data-credit-before-cash');
        setCreditDays(opt.data('credit-days') || 0);
        $('.pill-segmented-compact button[data-mode="' + (d.payment_mode || 'Credit') + '"]').trigger('click');
        $('.pill-segmented-compact button[data-nature="' + (d.nature || 'product') + '"]').trigger('click');
        $('#gst-place-of-supply').val(d.place_of_supply || '').trigger('change');
        $('#gst-reverse-charge').prop('checked', !!d.reverse_charge).trigger('change');
        if (d.note && !$('#drawer-note').val()) {
            $('#drawer-note, #hidden-note').val(d.note);
            $('#remarks-summary-preview').text('Remarks: ' + d.note.slice(0, 40) + (d.note.length > 40 ? '...' : ''));
        }
    }
    // bootstrap-select passes the clicked index only for a user pick; programmatic .val().trigger('change') passes none.
    $('#supplier_id').on('changed.bs.select', function (event, clickedIndex) {
        if (typeof clickedIndex === 'number') applyPartyDefaults();
    });
    // No party is preselected: drop the browser's implicit first-option pick so the strip matches the "Select Supplier" title.
    if (!$('#supplier_id option[selected]').length) $('#supplier_id').prop('selectedIndex', -1);
    updatePartyCard();

    // --- Workspace Mode Switching (Split View vs Full Width Register) ---
    function switchWorkspaceMode(mode) {
        if (mode === 'register') {
            $('#comm-split-grid').hide();
            $('#fullwidth-register-view').show();
            $('#fullscreen-icon').text('⇄');
            $('#fullscreen-tooltip').text('Split View');
            $('#nav-mode-icon').text('⇄');
            $('#nav-mode-text').text('Split View');
            $('#btn-navbar-workspace-toggle').addClass('btn-mode-split').attr('title', 'Switch to Split View (Voucher Entry + Bill List)');
            if ($.fn.DataTable.isDataTable('#purchase-table')) {
                $('#purchase-table').DataTable().columns.adjust().draw(false);
            } else {
                initPurchaseDataTable();
            }
        } else {
            $('#fullwidth-register-view').hide();
            $('#comm-split-grid').show();
            $('#fullscreen-icon').text('⛶');
            $('#fullscreen-tooltip').text('Full Width');
            $('#nav-mode-icon').text('⛶');
            $('#nav-mode-text').text('Full Width');
            $('#btn-navbar-workspace-toggle').removeClass('btn-mode-split').attr('title', 'Switch to Full Width Register');
        }
    }

    $('#btn-panel-fullscreen, #btn-navbar-workspace-toggle').on('click', function(e) {
        e.preventDefault();
        var current = $('#fullwidth-register-view').is(':visible') ? 'register' : 'voucher';
        switchWorkspaceMode(current === 'register' ? 'voucher' : 'register');
    });

    $('#btn-switch-voucher-mode').on('click', function(e) {
        e.preventDefault();
        switchWorkspaceMode('voucher');
    });

    $('#btn-fullwidth-new').on('click', function(e) {
        e.preventDefault();
        resetFormToNew();
        switchWorkspaceMode('voucher');
    });

    $('#btn-top-new').on('click', function(e) {
        if ($('#fullwidth-register-view').is(':visible')) {
            e.preventDefault();
            resetFormToNew();
            switchWorkspaceMode('voucher');
        }
    });

    // Date range picker for full width register
    if ($.fn.daterangepicker) {
        $('.daterangepicker-field').daterangepicker({
            callback: function(startDate, endDate, period) {
                var starting_date = startDate.format('YYYY-MM-DD');
                var ending_date = endDate.format('YYYY-MM-DD');
                var title = starting_date + ' To ' + ending_date;
                $(this).val(title);
                $('#fullwidth-register-view input[name="starting_date"]').val(starting_date);
                $('#fullwidth-register-view input[name="ending_date"]').val(ending_date);
                if ($.fn.DataTable.isDataTable('#purchase-table')) {
                    $('#purchase-table').DataTable().draw();
                }
            }
        });
    }

    function initPurchaseDataTable() {
        if ($.fn.DataTable.isDataTable('#purchase-table')) return;

        var all_permission = @json($all_permission ?? []);
        var show_purchase_product_details = {{ $general_setting->show_products_details_in_purchase_table ?? 0 }};
        var decimal_places = {{ $general_setting->decimal ?? 2 }};

        var columns = [
            {"data": "key"},
            {"data": "date"},
            {"data": "reference_no"},
            {"data": "created_by"},
            {"data": "supplier"}
        ];
        if (show_purchase_product_details == 1) {
            columns.push({"data": "products"});
            columns.push({"data": "products_qty"});
        }
        columns.push({"data": "purchase_status"});
        columns.push({"data": "grand_total"});
        columns.push({"data": "returned_amount"});
        columns.push({"data": "paid_amount"});
        columns.push({"data": "due"});
        columns.push({"data": "payment_status"});

        var field_name = @json($field_name ?? []);
        for (var i = 0; i < field_name.length; i++) {
            columns.push({"data": field_name[i]});
        }
        columns.push({"data": "options"});

        var buttons = [
            {
                extend: 'pdf',
                text: '<i title="export to pdf" class="fa fa-file-pdf-o"></i>',
                exportOptions: { columns: ':visible:Not(.not-exported)', rows: ':visible' },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'excel',
                text: '<i title="export to excel" class="fa fa-file-excel-o"></i>',
                exportOptions: { columns: ':visible:Not(.not-exported)', rows: ':visible' },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'csv',
                text: '<i title="export to csv" class="fa fa-file-text-o"></i>',
                exportOptions: { columns: ':visible:Not(.not-exported)', rows: ':visible' },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'print',
                text: '<i title="print" class="fa fa-print"></i>',
                exportOptions: { columns: ':visible:Not(.not-exported)', rows: ':visible' },
                action: function(e, dt, button, config) {
                    datatable_sum(dt, true);
                    $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                    datatable_sum(dt, false);
                },
                footer: true
            },
            {
                extend: 'colvis',
                text: '<i title="column visibility" class="fa fa-eye"></i>',
                columns: ':gt(0)'
            }
        ];

        var purchaseTable = $('#purchase-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ url('purchases/purchase-data') }}",
                type: "POST",
                data: function (d) {
                    d._token = '{{ csrf_token() }}';
                    d.all_permission = all_permission;
                    d.starting_date = $('#fullwidth-register-view input[name=starting_date]').val();
                    d.ending_date = $('#fullwidth-register-view input[name=ending_date]').val();
                    d.warehouse_id = $('#fullwidth-register-view #warehouse_id').val();
                    d.purchase_status = $('#fullwidth-register-view #purchase-status').val();
                    d.payment_status = $('#fullwidth-register-view #payment-status').val();
                }
            },
            createdRow: function(row, data, dataIndex) {
                $(row).addClass('purchase-link').attr('data-purchase', data['purchase']);
            },
            columns: columns,
            order: [['1', 'desc']],
            dom: '<"row align-items-center mb-2"<"col-sm-6 d-flex align-items-center gap-2"lB><"col-sm-6 text-right"f>>rtip',
            buttons: buttons,
            drawCallback: function () {
                var api = this.api();
                datatable_sum(api, false);
            }
        });

        function datatable_sum(dt_selector, is_calling_first) {
            var baseCol = (show_purchase_product_details == 1) ? 8 : 6;
            for (var c = baseCol; c <= baseCol + 3; c++) {
                if ($(dt_selector.column(c).footer()).length) {
                    var total = dt_selector.column(c, {page: 'current'}).data().sum();
                    $(dt_selector.column(c).footer()).html(parseFloat(total || 0).toFixed(decimal_places));
                }
            }
        }

        $('#fullwidth-register-view #warehouse_id, #fullwidth-register-view #purchase-status, #fullwidth-register-view #payment-status').on('change', function() {
            purchaseTable.draw();
        });

        $('#btn-quick-refresh').on('click', function() {
            purchaseTable.draw();
        });

        // Clicking Edit on any row in DataTables switches to Voucher Entry mode and loads that bill
        $(document).on('click', '#purchase-table a', function(e) {
            var href = $(this).attr('href') || '';
            var match = href.match(/purchases\/(\d+)\/edit/);
            if (match && match[1]) {
                e.preventDefault();
                switchWorkspaceMode('voucher');
                loadPurchaseToForm(match[1]);
            }
        });
    }

    // --- Auto-retract all dropdowns when clicking anywhere outside ---
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.bootstrap-select, .dropdown, .ui-autocomplete, #lims_productcodeSearch, .row-item-name').length) {
            $('.bootstrap-select.open, .bootstrap-select.show, .dropdown.show').removeClass('open show');
            $('.bootstrap-select .dropdown-menu.show, .dropdown-menu.show').removeClass('show');
            if ($productSearch.data('ui-autocomplete')) {
                $productSearch.autocomplete('close');
            }
            $('.row-item-name').each(function() {
                if ($(this).data('ui-autocomplete')) {
                    $(this).autocomplete('close');
                }
            });
        }
    });

    // Initialize row autocompletes for any pre-existing rows
    $('#order-table-body tr.order-item-row .row-item-name').each(function() {
        initRowItemAutocomplete($(this));
    });

    $(document).on('show.bs.dropdown show.bs.select', function(e) {
        $('.bootstrap-select.show, .dropdown.show').not($(e.target).closest('.bootstrap-select, .dropdown')).removeClass('show open').find('.dropdown-menu.show').removeClass('show');
    });

    // --- Load Purchase To Form for In-Place Editing ---
    window.loadPurchaseToForm = function(id) {
        $('#comm-split-grid').removeClass('panel-fullwidth');
        $('#fullscreen-icon').text('⛶');
        $('#fullscreen-tooltip').text('Full Width');

        $('#comm-progress-bar').addClass('active');
        $.ajax({
            type: 'GET',
            url: '{{ url("purchases") }}/' + id,
            dataType: 'json',
            success: function(res) {
                $('#comm-progress-bar').removeClass('active').addClass('done');
                setTimeout(() => $('#comm-progress-bar').removeClass('done'), 300);

                if (!res || !res.purchase) {
                    alert('Could not load purchase details.');
                    return;
                }

                var p = res.purchase;
                // Switch Form to Update Mode
                $('#entry-form-method').val('PUT');
                $('#edit-purchase-id').val(p.id);
                $('#purchase-entry-form').attr('action', '{{ url("purchases") }}/' + p.id);

                // Update UI Labels
                $('#doc-breadcrumb-mode').text('Edit: ' + (p.reference_no || '#' + p.id)).removeClass('text-primary').addClass('text-success');
                $('#doc-title-text').text('Edit Purchase Bill: ' + (p.reference_no || '#' + p.id));
                $('#doc-sub-text').text('Editing saved purchase record');

                // Populate Header Inputs
                $('#reference_no').val(p.reference_no || '');
                $('#supplier_invoice_no').val(p.supplier_invoice_no || '');
                if (p.supplier_invoice_date) $('#supplier_invoice_date').val(p.supplier_invoice_date.slice(0, 10));
                if (p.created_at) $('#created_at').val(p.created_at.slice(0, 10));
                
                $('#supplier_id').val(p.supplier_id).trigger('change');
                $('.selectpicker').selectpicker('refresh');
                if (p.warehouse_id) $('#form_warehouse_id').val(p.warehouse_id);
                if (p.purchase_type_id) {
                    $('#purchase_type_id').val(p.purchase_type_id);
                } else {
                    $('#purchase_type_id').val('0');
                }

                // Highlight active card in side list
                $('.side-bill-card').removeClass('active-editing');
                $('.side-bill-card[data-bill-id="' + id + '"]').addClass('active-editing');

                // Populate Items
                $('#order-table-body .order-item-row, #order-table-body .empty-placeholder-row').remove();
                rowCounter = 0;
                if (res.items && res.items.length) {
                    res.items.forEach(function(item) {
                        addProductRow({
                            ...item, preserve_line: true,
                            product_id: item.product_id,
                            product_name: item.product_name,
                            product_code: item.product_code,
                            cost: parseFloat(item.net_unit_cost) || 0,
                            tax_rate: parseFloat(item.tax_rate) || 0,
                            unit: item.unit_code || 'Unit',
                            qty: parseFloat(item.qty) || 1
                        });
                    });
                } else {
                    reindexRows();
                }

                recalcTableSummary();
                $(document).trigger("command-center-loaded", [res.purchase]);

                // Scroll smoothly to top of form
                $('#comm-entry-workspace').animate({ scrollTop: 0 }, 200);
            },
            error: function() {
                $('#comm-progress-bar').removeClass('active');
                alert('Failed to fetch purchase details from server.');
            }
        });
    };

    // --- Reset Form to Blank New Purchase ---
    window.resetFormToNew = function() {
        $('#comm-split-grid').removeClass('panel-fullwidth');
        $('#fullscreen-icon').text('⛶');
        $('#fullscreen-tooltip').text('Full Width');

        $('#entry-form-method').val('POST');
        $('#edit-purchase-id').val('');
        $('#purchase-entry-form').attr('action', '{{ route("purchases.store") }}');

        $('#doc-breadcrumb-mode').text('New').removeClass('text-success').addClass('text-primary');
        $('#doc-title-text').text('New Purchase Bill');
        $('#doc-sub-text').text('Purchase • New bill');

        $('#reference_no').val('');
        $('#supplier_invoice_no').val('');
        $('#supplier_invoice_date').val('{{ date("Y-m-d") }}');
        $('#created_at').val('{{ date("Y-m-d") }}');
        $('#supplier_id').val('').trigger('change');
        $('.selectpicker').selectpicker('refresh');
        $('#purchase_type_id').val('0');

        $('.side-bill-card').removeClass('active-editing');
        $('#order-table-body .order-item-row, #order-table-body .empty-placeholder-row').remove();
        $productSearch.val('');
        rowCounter = 0;
        reindexRows();
        syncTaxClassificationToRows();
        recalcTableSummary();
        $(document).trigger("command-center-reset");
    };

    // Actions triggering reset to new
    $('#btn-side-new, #btn-top-new, #btn-form-discard').on('click', function(e) {
        e.preventDefault();
        resetFormToNew();
    });

    // Card click & edit button click
    $(document).on('click', '.side-bill-card', function(e) {
        if ($(this).data('reversed')) return;
        if ($(e.target).closest('.side-action-btn.view, .side-action-btn.print, .side-action-btn.reverse').length) return;
        var id = $(this).data('bill-id');
        if (id) loadPurchaseToForm(id);
    });

    $(document).on('click', '.btn-side-load-edit', function(e) {
        e.stopPropagation();
        var id = $(this).data('id');
        if (id) loadPurchaseToForm(id);
    });

    // --- Slideover Drawer Handlers ---
    $('#btn-open-details, #btn-bottom-charges, #btn-quick-logistics').on('click', function() {
        $('#charges-drawer').modal('show');
    });

    $('#btn-save-drawer-details').on('click', function() {
        $('#hidden-bale-no').val($('#drawer-bale-no').val());
        $('#hidden-no-of-bales').val($('#drawer-no-of-bales').val());
        $('#hidden-bundle-count').val($('#drawer-bundle-count').val());
        $('#hidden-vehicle-number').val($('#drawer-vehicle-number').val());
        $('#hidden-lr-no').val($('#drawer-lr-no').val());
        $('#hidden-lr-date').val($('#drawer-lr-date').val());
        $('#hidden-transporter-name').val($('#drawer-transporter-name').val());
        $('#hidden-station-to').val($('#drawer-station-to').val());
        $('#hidden-order-no').val($('#drawer-order-no').val());
        $('#hidden-credit-days').val($('#drawer-credit-days').val());
        $('#hidden-shipping-cost').val(Number($('#drawer-shipping-cost').val()) || 0);
        $('#hidden-order-discount').val(Number($('#drawer-order-discount').val()) || 0);
        $('#hidden-capitalise-services').val($('#drawer-capitalise-services').is(':checked') ? 1 : '');
        $('#hidden-landed-cost-method').val($('#drawer-landed-cost-method').val());
        $('#hidden-cd-percent').val($('#drawer-cd-percent').val());
        $('#hidden-cd-days').val($('#drawer-cd-days').val());
        $('#hidden-note').val($('#drawer-note').val());
        $('#hidden-account-id').val($('#drawer-account-id').val());
        $('#input-paying-method').val($('#drawer-paying-method').val());
        $('#hidden-paid-amount').val($('#drawer-paid-amount').val());

        var count = 0;
        if ($('#drawer-bale-no').val()) count++;
        if ($('#drawer-lr-no').val()) count++;
        if ($('#drawer-note').val()) count++;
        if (parseFloat($('#drawer-paid-amount').val()) > 0) count++;
        $('#charges-badge-count').text(count);
        if ($('#drawer-note').val()) {
            $('#remarks-summary-preview').text('Remarks: ' + $('#drawer-note').val().slice(0, 40) + '...');
        }
    });

    // Pill Segmented Mode Toggle (Cash/Credit)
    $('.pill-segmented-compact button[data-mode]').on('click', function() {
        $('.pill-segmented-compact button[data-mode]').removeClass('active');
        $(this).addClass('active');
        var mode = $(this).data('mode');
        $('#input-paying-method, #drawer-paying-method').val(mode);
        $('#drawer-paying-method').selectpicker('refresh');
        syncTerms();
    });

    // --- Side Panel Filtering Functions ---
    function filterSidePurchases() {
        var query = $('#side-search-input').val().toLowerCase().trim();
        var seriesQuery = $('#side-filter-series').val().toLowerCase().trim();
        var activeTab = $('.side-filter-tabs .side-tab.active').data('filter') || 'all';
        var dateVal = $('#side-filter-date-val').val();
        var whId = $('#side-filter-warehouse').val();
        var statusId = $('#side-filter-status').val();
        var paymentId = $('#side-filter-payment').val();

        var visibleCount = 0;
        $('#side-bill-list .side-bill-card').each(function() {
            var $c = $(this);
            var ref = $c.data('ref') || '';
            var party = $c.data('party') || '';
            var status = $c.data('status') || '';
            var cardStatusId = String($c.data('status-id') || '');
            var cardPayId = String($c.data('payment-status-id') || '');
            var cardWhId = String($c.data('warehouse-id') || '');
            var cardDate = $c.data('date') || '';

            var matchSearch = !query || ref.indexOf(query) !== -1 || party.indexOf(query) !== -1;
            var matchSeries = !seriesQuery || ref.indexOf(seriesQuery) !== -1;
            var matchTab = true;

            if (activeTab === 'draft') matchTab = (status === 'draft');
            else if (activeTab === 'date') matchTab = (dateVal && cardDate === dateVal);
            else if (activeTab === 'range') matchTab = (!dateVal || cardDate >= dateVal) && (!$('#side-filter-date-end').val() || cardDate <= $('#side-filter-date-end').val());

            var matchWh = (!whId || whId == '0' || cardWhId === whId);
            var matchStatus = (!statusId || statusId == '0' || cardStatusId === statusId);
            var matchPayment = (!paymentId || paymentId == '0' || cardPayId === paymentId);

            if (matchSearch && matchSeries && matchTab && matchWh && matchStatus && matchPayment) {
                $c.show();
                visibleCount++;
            } else {
                $c.hide();
            }
        });

        $('.side-empty-state').toggle(visibleCount === 0);
    }

    $('#side-search-input, #side-filter-series').on('input', filterSidePurchases);
    $('#side-filter-warehouse, #side-filter-status, #side-filter-payment').on('change', filterSidePurchases);
    $('#side-filter-date-val, #side-filter-date-end').on('change', filterSidePurchases);

    $('.side-filter-tabs .side-tab').on('click', function() {
        $('.side-filter-tabs .side-tab').removeClass('active');
        $(this).addClass('active');
        var f = $(this).data('filter');
        $('#side-date-picker-box').toggle(f === 'date' || f === 'range');
        $('#side-filter-date-end').toggle(f === 'range');
        filterSidePurchases();
    });

    // Reset toolbar button
    $('#side-filter-reset').on('click', function() {
        $('#side-search-input').val('');
        $('#side-filter-series').val('');
        $('#side-filter-warehouse').val('0');
        $('#side-filter-status').val('0');
        $('#side-filter-payment').val('0');
        $('.side-filter-tabs .side-tab').removeClass('active');
        $('.side-filter-tabs .side-tab[data-filter="all"]').addClass('active');
        $('#side-date-picker-box').hide();
        filterSidePurchases();
    });

    // --- Docking & Drawer Collapse Mechanics ---
    var STORAGE_DOCK_KEY = 'zolo_bill_panel_dock';
    var STORAGE_OPEN_KEY = 'zolo_bill_panel_open';

    function initPanelState() {
        var dock = localStorage.getItem(STORAGE_DOCK_KEY) || 'right';
        var open = true; // Split view on arrival: the bill list starts open; closing it lasts for this visit.
        applyDock(dock);
        applyDrawer(open);
    }

    function applyDock(dock) {
        var grid = document.getElementById('comm-split-grid');
        var label = document.getElementById('dock-label');
        if (!grid) return;
        if (dock === 'left') {
            grid.classList.add('dock-left');
            if (label) label.textContent = 'Dock Right';
        } else {
            grid.classList.remove('dock-left');
            if (label) label.textContent = 'Dock Left';
        }
        localStorage.setItem(STORAGE_DOCK_KEY, dock);
    }

    function applyDrawer(open) {
        var grid = document.getElementById('comm-split-grid');
        var drawer = document.getElementById('comm-drawer');
        if (!grid || !drawer) return;
        if (open) {
            grid.classList.remove('drawer-collapsed');
            drawer.classList.remove('collapsed');
        } else {
            grid.classList.add('drawer-collapsed');
            drawer.classList.add('collapsed');
        }
        localStorage.setItem(STORAGE_OPEN_KEY, open ? 'true' : 'false');
    }

    $('#btn-dock-toggle').on('click', function(e) {
        e.preventDefault();
        var current = localStorage.getItem(STORAGE_DOCK_KEY) || 'right';
        applyDock(current === 'right' ? 'left' : 'right');
    });

    $('#close-drawer-btn').on('click', function(e) {
        e.preventDefault();
        applyDrawer(false);
    });

    $('#toggle-drawer-btn, #btn-header-toggle-list').on('click', function(e) {
        e.preventDefault();
        if ($('#fullwidth-register-view').is(':visible')) {
            switchWorkspaceMode('voucher');
            applyDrawer(true);
            return;
        }
        var isOpen = localStorage.getItem(STORAGE_OPEN_KEY) !== 'false';
        applyDrawer(!isOpen);
    });

    // --- Auto load Goods Received Note if from_grn param present ---
    var urlParams = new URLSearchParams(window.location.search);
    var fromGrnId = urlParams.get('from_grn');
    if (fromGrnId) {
        switchWorkspaceMode('voucher');
        $.getJSON('/goods-received-notes/' + fromGrnId, function(res) {
            if (res && res.grn) {
                var gn = res.grn;
                $('#supplier_id').val(gn.supplier_id).trigger('change');
                if (gn.warehouse_id) $('#form_warehouse_id').val(gn.warehouse_id).trigger('change');
                if (gn.purchase_type_id) $('#purchase_type_id').val(gn.purchase_type_id);
                if (gn.agent_id) $('#agent_id').val(gn.agent_id);
                if (gn.transport_name) $('#hidden-transporter-name, #drawer-transporter-name').val(gn.transport_name);
                if (gn.lr_no) $('#hidden-lr-no, #drawer-lr-no').val(gn.lr_no);
                if (gn.lr_date) $('#hidden-lr-date, #drawer-lr-date').val(gn.lr_date.substring(0, 10));
                if (gn.order_no) $('#supplier_invoice_no').val(gn.order_no);
                if (gn.remarks) $('#hidden-note, #drawer-note').val(gn.remarks);

                if (!$('#goods_received_note_id_input').length) {
                    $('#purchase-entry-form').append('<input type="hidden" name="goods_received_note_id" id="goods_received_note_id_input" value="' + gn.id + '">');
                } else {
                    $('#goods_received_note_id_input').val(gn.id);
                }

                if (res.items && res.items.length) {
                    $('#order-table-body tr.order-item-row').remove();
                    // Only what is still unbilled on the GRN; the stock is already in, so the bill settles it.
                    res.items.forEach(function(item) {
                        var open = item.open_qty != null ? parseFloat(item.open_qty) : parseFloat(item.qty);
                        if (open > 0) {
                            addProductRow({...item, qty: open, preserve_line: true});
                            var row = $('#order-table-body tr.order-item-row').last();
                            if (item.variant_id) row.attr('data-variant-id', item.variant_id);
                            if (item.base_factor) row.attr({'data-material-document-id': gn.id, 'data-material-item-id': item.id, 'data-material-base-factor': item.base_factor, 'data-material-unit-id': item.unit_id});
                        }
                    });
                }
                $('.selectpicker').selectpicker('refresh');
                recalcTableSummary();
                $('#doc-title-text').text('New Purchase Bill (from GRN #' + gn.grn_no + ')');
            }
        });
    }

    // --- Convert a quotation: same entry engine, the posted bill marks the quotation converted ---
    var fromQuotationId = urlParams.get('from_quotation');
    if (fromQuotationId) {
        switchWorkspaceMode('voucher');
        $.getJSON('/quotations/' + fromQuotationId + '/conversion', function(res) {
            if (!res || !res.quotation) return;
            var q = res.quotation;
            if (q.supplier_id) $('#supplier_id').val(q.supplier_id).trigger('change');
            if (q.warehouse_id) $('#form_warehouse_id').val(q.warehouse_id).trigger('change');
            if (q.note) $('#hidden-note, #drawer-note').val(q.note);
            $('#quotation_id_input').remove();
            $('#purchase-entry-form').append('<input type="hidden" name="quotation_id" id="quotation_id_input" value="' + q.id + '">');
            $('#order-table-body tr.order-item-row').remove();
            (res.items || []).forEach(function(item) { addProductRow(item); });
            $('.selectpicker').selectpicker('refresh');
            recalcTableSummary();
            $('#doc-title-text').text('New Purchase Bill (from Quotation ' + q.reference_no + ')');
        });
    }

    // Bills always open in split view (entry + bill list); full width is a switch for this visit only.
    // Only a register link opens the full-width list.
    if (@json(request('view') === 'orders') && !fromGrnId && !fromQuotationId) {
        switchWorkspaceMode('register');
    }

    initPanelState();
    window.commandCenterGrid = {addProductRow, resetFormToNew, recalcTableSummary, switchWorkspaceMode, products: allProducts};
})();
</script>
@include('backend.partials.gst-entry', ['kind' => 'purchase'])
@endpush

@include('backend.partials.command-center-form', ['kind' => 'purchase'])

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-purchase-index.css') }}">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
@endpush
