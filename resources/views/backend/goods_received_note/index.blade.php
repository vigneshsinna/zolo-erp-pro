@extends('backend.layout.main') 

@section('content')

<style type="text/css">
    .btn-icon i { margin-right: 5px; }
    .top-fields { margin-top: 10px; position: relative; }
    .top-fields label { background: #FFF; font-size: 11px; font-weight: 600; margin-left: 10px; padding: 0 3px; position: absolute; top: -8px; z-index: 9; }
    .top-fields input { font-size: 13px; height: 45px; }
</style>

<x-success-message key="message" />
<x-error-message key="not_permitted" />

<link rel="stylesheet" href="<?php echo asset('css/commercial-entry.css') . '?v=' . (file_exists(public_path('css/commercial-entry.css')) ? filemtime(public_path('css/commercial-entry.css')) : time()) ?>" type="text/css">
<link rel="stylesheet" href="<?php echo asset('css/commercial-workspace.css') . '?v=' . (file_exists(public_path('css/commercial-workspace.css')) ? filemtime(public_path('css/commercial-workspace.css')) : time()) ?>" type="text/css">
<script>
    document.documentElement.classList.add('commercial-screen-lock');
    document.body.classList.add('commercial-screen-lock');
</script>
<div id="comm-progress-bar"></div>

<section class="commercial-workspace-view material-document-workspace">
    <div class="alert alert-danger" id="material-document-error" role="alert" style="display:none"></div>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <!-- Top Command Bar -->
    <div class="comm-command-bar">
        <div class="comm-title-group">
            <h1><i class="dripicons-download text-primary"></i> {{ __('Goods Received Note') }} Command Center</h1>
            <span class="comm-title-badge"><i class="dripicons-import"></i> Inbound Material Receipt</span>
        </div>
        <ul class="comm-nav-pills">
            <li><a class="nav-link active" href="#desk-bill-list-panel" id="material-list-link"><i class="dripicons-list"></i> {{ __('All GRNs') }}</a></li>
            <li><a class="nav-link" href="{{ route('purchases.index') }}"><i class="dripicons-card"></i> Purchase Bills</a></li>
        </ul>
        <div class="comm-actions">
            <button type="button" class="btn btn-outline-secondary py-1 px-3" id="btn-top-new" title="Add New GRN">
                <i class="dripicons-plus"></i> {{ __('New GRN') }}
            </button>
            <button type="button" class="btn btn-primary py-1 px-3" id="toggle-drawer-btn" title="Toggle GRN List Panel" style="background:#7c3aed; border-color:#7c3aed; color:#fff;">
                <i class="dripicons-view-list"></i> GRN list
            </button>
        </div>
    </div>

    <!-- 2-Column Command Center Grid: Main Entry Workspace + Side GRN List Panel -->
    <div class="comm-split-grid" id="comm-split-grid">
        <!-- Main: GRN Entry Workspace -->
        <div class="comm-entry-workspace" id="comm-entry-workspace">
            <form id="grn-entry-form" method="POST" action="{{ route('goods-received-notes.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" id="form-method" value="POST">
                <input type="hidden" name="grn_id" id="edit-grn-id" value="">

                <!-- Header Strip Card -->
                <div class="entry-header-card">
                    <div class="entry-header-top d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="entry-breadcrumb" id="entry-breadcrumb">Home / Buying / Goods Received Notes / New</span>
                            <span class="badge badge-light-primary px-2 py-1 ml-2" id="status-pill" style="font-weight:600;font-size:11px;background:#f3e8ff;color:#7c3aed;border:1px solid #d8b4fe;border-radius:4px;">Draft GRN</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-compact" id="open-transport-drawer-btn">
                                <i class="dripicons-truck"></i> Transport Details
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-compact" id="reopen-panel-btn">
                                <i class="dripicons-view-list"></i> GRN list
                            </button>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="entry-doc-icon"><i class="dripicons-download" style="color:#7c3aed;font-size:20px;"></i></div>
                            <h2 class="entry-title mb-0" id="entry-title-text" style="font-size:17px;font-weight:700;color:#0f172a;">New Goods Received Note</h2>
                        </div>
                    </div>
                </div>

                <!-- Primary Fields Form Row -->
                <div class="entry-fields-card mt-2">
                    <div class="row gx-2 gy-2">
                        <div class="col-md-2 col-sm-6">
                            <div class="comm-field">
                                <label for="grn_no">GRN No <span class="text-danger">*</span></label>
                                <input type="text" name="grn_no" id="grn_no" class="form-control form-control-sm" placeholder="Auto / GRN-..." value="">
                                <small class="text-muted" style="font-size:10px;">Leave blank for auto-numbering</small>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-6">
                            <div class="comm-field">
                                <label for="grn_date">GRN Date <span class="text-danger">*</span></label>
                                <input type="date" name="grn_date" id="grn_date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="comm-field">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label for="supplier_id">Supplier <span class="text-danger">*</span></label>
                                    <a href="javascript:void(0)" class="text-primary font-weight-bold" id="btn-quick-supplier" style="font-size:11px;">+ New</a>
                                </div>
                                <select name="supplier_id" id="supplier_id" class="form-control form-control-sm" required>
                                    <option value="">Choose Supplier...</option>
                                    @foreach($lims_supplier_list as $sup)
                                        <option value="{{ $sup->id }}" data-city="{{ $sup->city }}" data-address="{{ $sup->address }}">{{ $sup->name }} ({{ $sup->phone_number }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-6">
                            <div class="comm-field">
                                <label for="warehouse_id">Warehouse <span class="text-danger">*</span></label>
                                <select name="warehouse_id" id="warehouse_id" class="form-control form-control-sm" required>
                                    @foreach($lims_warehouse_list as $wh)
                                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="comm-field">
                                <label for="purchase_type_id">Purchase Type</label>
                                <select name="purchase_type_id" id="purchase_type_id" class="form-control form-control-sm">
                                    <option value="">Standard Purchase</option>
                                    @foreach($purchaseTypes as $pt)
                                        <option value="{{ $pt->id }}">{{ $pt->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row gx-2 gy-2 mt-1">
                        <div class="col-md-3 col-sm-6">
                            <div class="comm-field">
                                <label for="agent_id">Through Agent</label>
                                <select name="agent_id" id="agent_id" class="form-control form-control-sm">
                                    <option value="">Direct Receipt</option>
                                    @foreach($agents as $ag)
                                        <option value="{{ $ag->id }}">{{ $ag->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="comm-field">
                                <label for="order_no">Purchase Order No</label>
                                <input type="text" name="order_no" id="order_no" class="form-control form-control-sm" placeholder="PO-10023 / Reference">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="comm-field">
                                <label for="transport_name">Transporter</label>
                                <input type="text" name="transport_name" id="transport_name" class="form-control form-control-sm" placeholder="e.g. VRL Logistics, Sri Ram">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="comm-field">
                                <label for="lr_no">LR / Bale No</label>
                                <input type="text" name="lr_no" id="lr_no" class="form-control form-control-sm" placeholder="LR No / Consignment No">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Grid Section -->
                <div class="items-grid-section mt-2">
                    <div class="items-toolbar d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="items-counter font-weight-bold" id="items-counter" style="color:#0f172a;font-size:12px;">ITEMS 0 line(s)</span>
                            <div class="density-segmented ml-3">
                                <button type="button" class="density-btn" data-density="compact">Compact</button>
                                <button type="button" class="density-btn active" data-density="cozy">Cozy</button>
                                <button type="button" class="density-btn" data-density="large">Large</button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-compact" id="btn-multi-item" data-toggle="modal" data-target="#multi-item-modal">
                                <i class="dripicons-checklist"></i> Multi item
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-compact" id="btn-create-item-modal" data-toggle="modal" data-target="#quick-create-item-modal">
                                <i class="dripicons-plus"></i> Create item
                            </button>
                            <button type="button" class="btn btn-sm btn-primary btn-compact" id="btn-add-row" style="background:#7c3aed;border-color:#7c3aed;">
                                <i class="dripicons-plus"></i> Add row
                            </button>
                        </div>
                    </div>

                    <!-- Quick Barcode / Item Search Input -->
                    <div class="search-box-wrapper mb-2">
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text" style="background:#f8fafc; border-color:#cbd5e1; color:#64748b;"><i class="dripicons-search"></i></span>
                            </div>
                            <input type="text" id="lims_productcodeSearch" class="form-control form-control-sm" placeholder="Scan barcode or type item code / name... (Alt+I to focus)" autocomplete="off">
                            <div class="input-group-append">
                                <span class="input-group-text text-muted" style="background:#f8fafc; border-color:#cbd5e1; font-size:11px;">Alt+I Quick Search</span>
                            </div>
                        </div>
                    </div>

                    <!-- Purple Header 10-Column Items Table -->
                    <div class="table-responsive order-table-responsive" style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #fff;">
                        <table class="table order-table mb-0 cozy" id="order-table" style="width: 100%;">
                            <thead style="background: #7c3aed; color: #fff;">
                                <tr>
                                    <th style="width: 40px; text-align: center;">#</th>
                                    <th>ITEM</th>
                                    <th style="width: 130px;">PURCHASE TYPE</th>
                                    <th style="width: 80px;">UNIT</th>
                                    <th style="width: 90px; text-align: right;">COST</th>
                                    <th style="width: 80px; text-align: right;">QTY</th>
                                    <th style="width: 100px; text-align: right;">AMOUNT</th>
                                    <th style="width: 70px; text-align: right;">TAX %</th>
                                    <th style="width: 110px; text-align: right;">TOTAL</th>
                                    <th style="width: 60px; text-align: center;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="order-table-body">
                                <tr class="empty-row-placeholder" id="empty-row-placeholder">
                                    <td colspan="10" class="text-center py-4 text-muted" style="font-size: 13px;">
                                        <i class="dripicons-download mr-1"></i> No items added yet. Search or scan above or click "+ Add row".
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Slide-over Drawer for Transport & Remarks -->
                <div class="comm-drawer" id="comm-drawer" hidden>
                    <div class="comm-drawer-header d-flex align-items-center justify-content-between p-3" style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                        <h4 class="mb-0 font-weight-bold" style="font-size:14px; color:#0f172a;"><i class="dripicons-truck text-primary mr-1"></i> Inward Transport & Logistics Details</h4>
                        <button type="button" class="close" id="close-drawer-btn" style="font-size:18px;">&times;</button>
                    </div>
                    <div class="comm-drawer-body p-3">
                        <div class="form-group mb-2">
                            <label class="font-weight-bold" style="font-size:11px;">LR Date</label>
                            <input type="date" name="lr_date" id="lr_date" class="form-control form-control-sm">
                        </div>
                        <div class="form-group mb-2">
                            <label class="font-weight-bold" style="font-size:11px;">Remarks & Inspection Notes</label>
                            <textarea name="remarks" id="remarks" class="form-control form-control-sm" rows="3" placeholder="Condition upon receipt, carton damage, quality notes..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Hidden Financial Input Fields -->
                <input type="hidden" name="total_qty" id="input_total_qty" value="0">
                <input type="hidden" name="total_cost" id="input_total_cost" value="0">
                <input type="hidden" name="total_tax" id="input_total_tax" value="0">
                <input type="hidden" name="grand_total" id="input_grand_total" value="0">

                <!-- Fixed Bottom Summary & Action Bar -->
                <div class="entry-bottom-bar mt-3 p-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#fff; border-top:1px solid #e2e8f0; border-radius:6px; box-shadow:0 -2px 8px rgba(0,0,0,0.03);">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bottom-transport">
                            <i class="dripicons-truck"></i> Transport Details
                        </button>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <div class="summary-metric text-right">
                            <span class="text-muted" style="font-size:10px;text-transform:uppercase;">NET</span>
                            <span class="font-weight-bold ml-1" id="disp-net" style="font-size:13px;color:#0f172a;">₹ 0.00</span>
                        </div>
                        <div class="summary-metric text-right">
                            <span class="text-muted" style="font-size:10px;text-transform:uppercase;">TAX</span>
                            <span class="font-weight-bold ml-1" id="disp-tax" style="font-size:13px;color:#0f172a;">₹ 0.00</span>
                        </div>
                        <div class="summary-metric text-right">
                            <span class="text-muted" style="font-size:10px;text-transform:uppercase;">GRAND TOTAL</span>
                            <span class="font-weight-bold ml-1" id="disp-grand" style="font-size:15px;color:#7c3aed;">₹ 0.00</span>
                        </div>

                        <div class="d-flex align-items-center gap-2 ml-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-discard" title="Discard Changes">
                                <i class="dripicons-trash"></i> Discard
                            </button>
                            <button type="submit" class="btn btn-sm btn-primary" id="btn-save" style="background:#7c3aed; border-color:#7c3aed; font-weight:600; padding:6px 16px;">
                                <i class="dripicons-checkmark"></i> Save GRN
                            </button>
                            <button type="button" class="btn btn-sm btn-success" id="btn-convert-purchase" style="display:none; font-weight:600;">
                                <i class="dripicons-card"></i> Convert to Purchase
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Side GRN List Panel -->
        <div class="comm-drawer-card desk-bill-list-panel" id="desk-bill-list-panel">
            <div class="side-panel-header d-flex align-items-center justify-content-between p-2" style="border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                <div class="d-flex align-items-center gap-2">
                    <span class="font-weight-bold text-dark" style="font-size:13px;"><i class="dripicons-download text-primary mr-1"></i> Goods Received Notes</span>
                    <span class="badge badge-secondary" style="font-size:10px;">{{ count($recent_grns) }}</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-xs btn-outline-secondary" id="btn-dock-toggle" title="Dock Left/Right">⇄ Dock</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary" id="btn-close-panel" title="Close Panel">✕</button>
                </div>
            </div>

            <!-- Side Search Box -->
            <div class="side-search-box p-2" style="background:#fff; border-bottom:1px solid #f1f5f9;">
                <input type="text" id="side-search-input" class="form-control form-control-sm" placeholder="Find a GRN by no or supplier...">
            </div>

            <!-- GRN Cards List -->
            <div class="side-challan-cards" id="side-grn-cards" style="overflow-y:auto; max-height: calc(100vh - 220px); padding:8px;">
                @forelse($recent_grns as $gn)
                    <div class="bill-card p-2 mb-2" data-id="{{ $gn->id }}" data-no="{{ strtolower($gn->grn_no) }}" data-supplier="{{ strtolower($gn->supplier ? $gn->supplier->name : '') }}" style="border:1px solid #e2e8f0; border-radius:6px; background:#fff; cursor:pointer; transition:all 0.15s ease;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="font-weight-bold text-primary" style="font-size:12px;">{{ $gn->grn_no }}</span>
                            <span class="font-weight-bold" style="font-size:12px; color:#0f172a;">₹ {{ number_format((float)$gn->grand_total, 2) }}</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between text-muted" style="font-size:11px;">
                            <span>{{ $gn->supplier ? $gn->supplier->name : 'N/A' }}</span>
                            <span>{{ \Carbon\Carbon::parse($gn->grn_date)->format('d M Y') }}</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-2 pt-1" style="border-top:1px dashed #f1f5f9;">
                            <span class="badge {{ $gn->status === 'converted_to_purchase' ? 'badge-success' : ($gn->status === 'cancelled' ? 'badge-danger' : 'badge-warning') }}" style="font-size:10px;">
                                {{ ucfirst(str_replace('_', ' ', $gn->status)) }}
                            </span>
                            <div class="d-flex align-items-center gap-1">
                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 btn-load-grn" data-id="{{ $gn->id }}" title="Edit in Form">✎ Edit</button>
                                @if($gn->status === 'pending')
                                    <a href="{{ route('purchases.index', ['from_grn' => $gn->id]) }}" class="btn btn-xs btn-outline-success py-0 px-2" title="Convert to Purchase Bill">🗲 Convert</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-4 text-muted" style="font-size:12px;">
                        No goods received notes recorded yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</section>

<!-- Fast Multi-Item Batch Picker Modal -->
<div class="modal fade" id="multi-item-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:8px; border:1px solid #cbd5e1;">
            <div class="modal-header py-2 px-3" style="background:#7c3aed; color:#fff;">
                <h5 class="modal-title font-weight-bold" style="font-size:14px;"><i class="dripicons-checklist mr-1"></i> Fast Batch Item Picker</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-3">
                <input type="text" id="multi-item-search" class="form-control form-control-sm mb-2" placeholder="Search item by name or code...">
                <div class="table-responsive" style="max-height: 340px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
                    <table class="table table-hover table-sm mb-0">
                        <thead style="background:#f8fafc;">
                            <tr>
                                <th style="width:30px;"><input type="checkbox" id="select-all-multi"></th>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th class="text-right">Cost Rate</th>
                            </tr>
                        </thead>
                        <tbody id="multi-item-tbody">
                            @foreach($lims_product_list_without_variant as $prod)
                                <tr class="multi-item-row" data-id="{{ $prod->id }}" data-name="{{ $prod->name }}" data-code="{{ $prod->code }}" data-price="{{ $prod->price }}" data-cost="{{ $prod->cost }}" data-unit="{{ $prod->unit_code ?? ($prod->unit_name ?? 'Unit') }}">
                                    <td><input type="checkbox" class="multi-item-cb" value="{{ $prod->id }}"></td>
                                    <td><code>{{ $prod->code }}</code></td>
                                    <td>{{ $prod->name }}</td>
                                    <td>{{ $prod->unit_code ?? ($prod->unit_name ?? 'Unit') }}</td>
                                    <td class="text-right font-weight-bold text-success">₹ {{ number_format((float)$prod->cost, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 px-3" style="background:#f8fafc;">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btn-add-selected-items" style="background:#7c3aed; border-color:#7c3aed;">Add Selected Items</button>
            </div>
        </div>
    </div>
</div>

<!-- On-The-Fly Quick Item Creation Modal -->
<div class="modal fade" id="quick-create-item-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:8px; border:1px solid #cbd5e1;">
            <div class="modal-header py-2 px-3" style="background:#7c3aed; color:#fff;">
                <h5 class="modal-title font-weight-bold" style="font-size:14px;"><i class="dripicons-plus mr-1"></i> Quick Create Item</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="quick-create-item-form">
                <div class="modal-body p-3">
                    <div class="form-group mb-2">
                        <label class="font-weight-bold" style="font-size:11px;">Item Name <span class="text-danger">*</span></label>
                        <input type="text" id="quick-item-name" class="form-control form-control-sm" required placeholder="e.g. Cotton Grey Fabric 40s">
                    </div>
                    <div class="form-group mb-2">
                        <label class="font-weight-bold" style="font-size:11px;">Item Code / Barcode</label>
                        <input type="text" id="quick-item-code" class="form-control form-control-sm" placeholder="Auto-generated if empty">
                    </div>
                    <div class="row gx-2 gy-2">
                        <div class="col-6">
                            <div class="form-group mb-2">
                                <label class="font-weight-bold" style="font-size:11px;">Cost Rate (₹)</label>
                                <input type="number" step="0.01" id="quick-item-cost" class="form-control form-control-sm" value="0.00">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group mb-2">
                                <label class="font-weight-bold" style="font-size:11px;">Selling Rate (₹)</label>
                                <input type="number" step="0.01" id="quick-item-price" class="form-control form-control-sm" value="0.00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3" style="background:#f8fafc;">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary" style="background:#7c3aed; border-color:#7c3aed;">Save & Add to GRN</button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('backend.partials.material-party-modal', ['partyType' => 'supplier'])
@endsection

@push('scripts')
    @php
        $jsProductList = [];
        foreach($lims_product_list_without_variant as $prod) {
            $taxVal = 0;
            if (!empty($prod->tax_id)) {
                $taxObj = collect($lims_tax_list)->firstWhere('id', $prod->tax_id);
                $taxVal = $taxObj ? (float)$taxObj->rate : 0;
            }
            $jsProductList[] = [
                'id' => (int)$prod->id,
                'name' => (string)$prod->name,
                'code' => (string)$prod->code,
                'price' => (float)($prod->price ?? 0),
                'cost' => (float)($prod->cost ?? 0),
                'tax_rate' => $taxVal,
                'unit_id' => (int)$prod->unit_id,
                'unit' => (string)($prod->unit_code ?? ($prod->unit_name ?? 'Unit')),
                'value' => (string)$prod->code . '|' . (string)$prod->name,
                'label' => (string)$prod->code . ' - ' . (string)$prod->name,
            ];
        }
    @endphp

    @php
        $materialConfig = [
            'form' => 'grn-entry-form', 'documentKey' => 'grn', 'idField' => 'grn_id',
            'numberField' => 'grn_no', 'totalField' => 'total_cost', 'rateField' => 'cost',
            'purchase' => true, 'products' => $jsProductList, 'decimals' => $general_setting->decimal ?? 2,
            'typeLabel' => 'Purchase', 'label' => 'Goods Received Note', 'title' => 'New Goods Received Note',
            'breadcrumb' => 'Home / Buying / Goods Received Notes / New',
            'storeUrl' => route('goods-received-notes.store'), 'productUrl' => route('products.quick-store'),
            'partyUrl' => route('parties.quick-store'), 'token' => csrf_token(),
            'partyType' => 'supplier', 'partySelect' => '#supplier_id', 'partyButton' => '#btn-quick-supplier',
            'convertButton' => '#btn-convert-purchase', 'convertUrl' => route('purchases.index'), 'convertParam' => 'from_grn',
            'editId' => request('edit'),
        ];
    @endphp
    <script type="application/json" id="material-document-data">@json($materialConfig)</script>
    <script src="{{ asset('js/material-document.js') }}?v={{ filemtime(public_path('js/material-document.js')) }}"></script>
@endpush
