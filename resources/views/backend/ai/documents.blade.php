@extends('backend.layout.main')

@section('content')
<div class="container-fluid py-3" id="ai-documents" data-base-url="{{ url('ai') }}" data-csrf="{{ csrf_token() }}" data-max-kilobytes="{{ $maxKilobytes }}">
    <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Documents and product imports</h4>
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
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="document-file">File</label>
                        <input id="document-file" name="file" type="file" class="form-control-file" accept=".pdf,.png,.jpg,.jpeg,.txt,.csv,.xlsx" required aria-describedby="document-file-help">
                        <small id="document-file-help" class="form-text text-muted">PDF, PNG, JPEG or UTF-8 text. Product imports use CSV or XLSX. Maximum {{ number_format($maxKilobytes / 1024, 1) }} MB.</small>
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
