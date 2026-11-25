@php
    $isLabCompany = !empty($opticalContext['has_lab_capability']);
    $profileSubtype = (string) ($opticalContext['subtype'] ?? 'wholesale_distributor');
@endphp

<section id="ai-optical-lens-panel"
         class="desk-card-wrapper ai-optical-panel d-print-none"
         data-optical-profile="{{ $opticalContext['profile'] ?? 'opticals_lens_supplier' }}"
         data-optical-subtype="{{ $profileSubtype }}"
         data-optical-lab="{{ $isLabCompany ? '1' : '0' }}">
    <div class="ai-optical-panel-header">
        <div class="ai-optical-panel-title">
            <i class="dripicons-view-apps"></i>
            <span>Optical Lens Desk</span>
            <span class="badge badge-primary">{{ $opticalContext['profile'] ?? 'opticals_lens_supplier' }}</span>
            <span class="badge badge-secondary">{{ $profileSubtype }}</span>
            @if ($isLabCompany)
                <span class="badge badge-success">Rx Lab Active</span>
            @endif
        </div>
        <div class="d-flex align-items-center flex-wrap ai-optical-actions-row">
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    id="ai-optical-toggle-btn"
                    aria-expanded="false"
                    aria-controls="ai-optical-panel-body">
                Toggle Optical Grid
            </button>
        </div>
    </div>

    <div id="ai-optical-panel-body" class="d-none mt-2">
        <div class="ai-optical-controls-grid">
            <div>
                <label for="ai-optical-route-select" class="small font-weight-bold mb-1 d-block">Route / Action</label>
                <select id="ai-optical-route-select" class="form-control form-control-sm">
                    <option value="optical.power_matrix.v1">Check Power Matrix &amp; Stock</option>
                    <option value="optical.lens_candidates.v1">Resolve Lens Candidates</option>
                    <option value="sales_order.create.v1">Draft Dealer Sales Order</option>
                    <option value="purchase_order.create.v1">Draft Supplier Purchase Order</option>
                    <option value="purchase_bill.create.v1">Draft Direct Supplier Purchase Bill (No PO)</option>
                    <option value="delivery_challan.create.v1">Draft Delivery Challan</option>
                    <option value="stock_transfer.create.v1">Draft Same-GSTIN Store Transfer</option>
                    @if ($isLabCompany)
                        <option value="optical.rx_job.prompt">Draft Rx Lab Job Request</option>
                    @endif
                </select>
            </div>
            <div>
                <label for="ai-optical-brand-input" class="small font-weight-bold mb-1 d-block">Lens Brand / Query</label>
                <input type="text" id="ai-optical-brand-input" class="form-control form-control-sm" placeholder="e.g. Essilor 1.67 BlueCut">
            </div>
            <div>
                <label for="ai-optical-coating-input" class="small font-weight-bold mb-1 d-block">Coating / Index</label>
                <input type="text" id="ai-optical-coating-input" class="form-control form-control-sm" placeholder="e.g. HMC / 1.67 / Photochromic">
            </div>
            <div>
                <label for="ai-optical-party-input" class="small font-weight-bold mb-1 d-block">Party / Store Code (Optional)</label>
                <input type="text" id="ai-optical-party-input" class="form-control form-control-sm" placeholder="Dealer / Supplier / Warehouse">
            </div>
        </div>

        <div class="mt-2">
            <label for="ai-optical-bulk-paste" class="small font-weight-bold mb-1 d-block">
                Bulk Power / Prescription Paste
                <span class="text-muted font-weight-normal">(Format: SPH | CYL | AXIS | ADD | EYE | DIA | QTY, preserves +/- signs)</span>
            </label>
            <textarea id="ai-optical-bulk-paste"
                      class="ai-optical-paste-box"
                      placeholder="-1.25 | -0.50 | 90 | | RE | 70 | 4&#10;+2.00 | 0.00 | 0 | +1.75 | PAIR | 65 | 2"></textarea>
        </div>

        <div class="d-flex flex-wrap align-items-center mt-2 ai-optical-actions-row">
            <button type="button" id="ai-optical-parse-btn" class="btn btn-sm btn-outline-primary">
                <i class="dripicons-checklist"></i> Parse Powers
            </button>
            <button type="button" id="ai-optical-dispatch-btn" class="btn btn-sm btn-primary">
                <i class="dripicons-direction"></i> Run Deterministic Check / Compose
            </button>
            <button type="button" id="ai-optical-clear-btn" class="btn btn-sm btn-outline-secondary">
                Clear Grid
            </button>
            <span id="ai-optical-status-badge" class="badge badge-light ml-auto">Ready</span>
        </div>

        <div id="ai-optical-grid-preview" class="table-responsive ai-optical-preview-table d-none">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>SPH</th>
                        <th>CYL</th>
                        <th>AXIS</th>
                        <th>ADD</th>
                        <th>EYE</th>
                        <th>DIA</th>
                        <th>QTY</th>
                    </tr>
                </thead>
                <tbody id="ai-optical-grid-tbody"></tbody>
            </table>
        </div>

        <pre id="ai-optical-result-output" class="mt-2 small d-none mb-0"></pre>
    </div>
</section>
