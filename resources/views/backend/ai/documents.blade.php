@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ asset('css/pages/ai-documents.css') }}?v={{ filemtime(public_path('css/pages/ai-documents.css')) }}">
@endpush

@section('content')
<div class="container-fluid py-3" id="ai-documents" data-base-url="{{ url('ai') }}" data-csrf="{{ csrf_token() }}" data-max-kilobytes="{{ $maxKilobytes }}">
    <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Documents and reviewed imports</h4>
            <p class="text-muted small mb-0">Company {{ $companyId }} · Branch {{ $branchId }} · Financial year {{ $financialYearId }}</p>
        </div>
        <a class="btn btn-outline-primary btn-sm mt-2" href="{{ url('ai') }}">Back to chat</a>
    </div>
    <div class="alert alert-danger d-none" id="document-error" role="alert"></div>
    <div class="row">
        <div class="col-lg-5 mb-3">
            <div class="card h-100"><div class="card-body">
                <h5>Upload a document</h5>
                <p class="small text-muted">Files stay private to your account and company. Scanning must pass before text or spreadsheet parsing.</p>
                <form id="document-upload-form">
                    @csrf
                    <div class="form-group">
                        <label for="document-classification">Document purpose</label>
                        <select id="document-classification" name="classification" class="form-control">
                            <option value="purchase_bill">Supplier invoice</option>
                            <option value="sales_order">Customer order</option>
                            <option value="sop">SOP or company guide</option>
                            <option value="product_import">Product spreadsheet</option>
                            <option value="opening_import">Opening stock or balances spreadsheet</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="document-file">File</label>
                        <input id="document-file" name="file" type="file" class="form-control-file" accept=".pdf,.png,.jpg,.jpeg,.txt,.csv,.xlsx" required aria-describedby="document-file-help">
                        <small id="document-file-help" class="form-text text-muted">PDF, PNG, JPEG or UTF-8 text. Product and opening imports use CSV or XLSX. Maximum {{ number_format($maxKilobytes / 1024, 1) }} MB.</small>
                    </div>
                    <button class="btn btn-primary" type="submit">Upload to quarantine</button>
                </form>
                <hr>
                <p class="small mb-2" id="document-status" role="status" aria-live="polite">No document selected.</p>
                <div class="d-flex flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm mr-2 mb-2" id="document-process" disabled>Scan and extract</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-2" id="document-refresh" disabled>Refresh status</button>
                    <button type="button" class="btn btn-outline-danger btn-sm mb-2" id="document-revoke" disabled>Revoke access</button>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm d-none" id="document-import-preview">Validate product rows</button>
                @if ($knowledgeEnabled)
                    <button type="button" class="btn btn-outline-primary btn-sm d-none" id="document-approve-knowledge">Review and approve SOP indexing</button>
                @endif
            </div></div>
        </div>
        <div class="col-lg-7 mb-3">
            <div class="card h-100"><div class="card-body">
                <h5>Review extracted content</h5>
                <p class="small text-muted">Check source dates, products, quantities and taxes. Document text provides draft hints; it cannot authorize ERP changes.</p>
                <pre class="pre-scrollable text-wrap border rounded p-3 small" id="document-extraction" tabindex="0">Text appears after a successful scan and extraction.</pre>
                <p class="small text-muted mb-0">Supplier invoice hints need manual confirmation before preparing a bill in chat.</p>
            </div></div>
        </div>
    </div>
    <div class="card mb-3 d-none" id="document-opening-panel"><div class="card-body">
        <h5>Opening stock and balances review</h5>
        <p class="small text-muted">Use a clean company with no posted stock or accounting activity. Spreadsheet columns: source_key, kind, payload. Each payload is a JSON object from the reviewed opening template. Map every source key to an existing company record and confirm source totals. The dry-run does not post entries.</p>
        <form id="document-opening-form">
            <div class="form-row">
                <div class="form-group col-md-4"><label for="opening-action">Opening type</label><select id="opening-action" class="form-control"><option value="setup.opening_stock.create.v1">Opening stock</option><option value="setup.opening_balance.create.v1">Opening financial balances</option></select></div>
                <div class="form-group col-md-4"><label for="opening-source-name">Source description</label><input id="opening-source-name" class="form-control" maxlength="100" required></div>
                <div class="form-group col-md-2"><label for="opening-source-key">Source reference</label><input id="opening-source-key" class="form-control" maxlength="100" required></div>
                <div class="form-group col-md-2"><label for="opening-date">Opening date</label><input id="opening-date" type="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
            </div>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th scope="col">Record type</th><th scope="col">Source key</th><th scope="col">Company record ID</th><th scope="col">Remove</th></tr></thead><tbody id="opening-mappings"></tbody></table></div>
            <button type="button" id="opening-add-mapping" class="btn btn-outline-secondary btn-sm mb-3">Add mapping</button>
            <div class="form-row">
                <div class="form-group col-md-3"><label for="opening-stock-qty">Expected stock quantity</label><input id="opening-stock-qty" type="number" step="0.0001" min="0" value="0" class="form-control" required></div>
                <div class="form-group col-md-3"><label for="opening-stock-value">Expected stock value</label><input id="opening-stock-value" type="number" step="0.0001" min="0" value="0" class="form-control" required></div>
                <div class="form-group col-md-3"><label for="opening-ar">Expected customer balance</label><input id="opening-ar" type="number" step="0.0001" value="0" class="form-control" required></div>
                <div class="form-group col-md-3"><label for="opening-ap">Expected supplier balance</label><input id="opening-ap" type="number" step="0.0001" value="0" class="form-control" required></div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Dry-run and prepare for approval</button>
            <a class="btn btn-outline-primary btn-sm" href="{{ url('ai/action-review') }}">Open action review</a>
            <p class="small mt-2 mb-0" id="opening-result" role="status" aria-live="polite">No stock or financial entries are posted before approval.</p>
        </form>
    </div></div>
    <div class="card mb-3 d-none" id="document-import-panel"><div class="card-body">
        <h5>Product import review</h5>
        <p class="small text-muted" id="document-import-summary"></p>
        <div class="table-responsive">
            <table class="table table-sm"><thead><tr><th scope="col">Select</th><th scope="col">Row</th><th scope="col">Product</th><th scope="col">Code</th><th scope="col">Status</th><th scope="col">Review details</th></tr></thead><tbody id="document-import-rows"></tbody></table>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="document-import-propose">Prepare selected rows for approval</button>
        <a class="btn btn-outline-secondary btn-sm" id="document-import-errors" href="#">Download row errors</a>
        <a class="btn btn-outline-primary btn-sm" href="{{ url('ai/action-review') }}">Open action review</a>
        <p class="small text-muted mt-2 mb-0" id="document-import-result" role="status" aria-live="polite">Products are created only after action approval.</p>
    </div></div>
    @if ($knowledgeEnabled)
        <div class="card"><div class="card-body">
            <h5>Search approved guides</h5>
            <form id="document-knowledge-form" class="form-row">
                <div class="col-md-10 mb-2"><label class="sr-only" for="document-knowledge-question">Question about a company guide</label><input id="document-knowledge-question" class="form-control" maxlength="2000" placeholder="Ask about an approved SOP or guide" required></div>
                <div class="col-md-2 mb-2"><button class="btn btn-outline-primary" type="submit">Find excerpts</button></div>
            </form>
            <div id="document-citations" role="status" aria-live="polite"></div>
        </div></div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/ai-documents.js') }}?v={{ filemtime(public_path('js/ai-documents.js')) }}"></script>
@endpush
