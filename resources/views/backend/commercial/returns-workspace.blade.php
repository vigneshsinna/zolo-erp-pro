{{-- Returns tab of the Purchase / Sales command center: a debit or credit note against one posted bill, in place of the bill voucher. --}}
@php
    $returnSale = $kind === 'sale';
    $returnNote = $returnSale ? 'Credit Note' : 'Debit Note';
@endphp
<div class="comm-entry-workspace return-workspace" id="return-workspace" data-kind="{{ $kind }}" data-base="{{ url('/compliance') }}"
     data-list-url="{{ url('/compliance/returns?side='.$kind) }}" aria-label="{{ $returnSale ? 'Sales' : 'Purchase' }} return">
    <form id="return-entry-form" novalidate>
        <div class="desk-card doc-primary-fields compact-fields mb-1">
            <div class="fields-compact-row doc-row-a">
                <div class="field-compact-item return-bill-field">
                    <label for="return-bill-search" data-help="The posted {{ $returnSale ? 'sales' : 'purchase' }} bill the goods came on. Search by bill number or {{ $returnSale ? 'customer' : 'supplier' }}.|{{ $returnSale ? 'SAL-00012' : 'PUR-00002' }}">Against bill *</label>
                    <input type="text" id="return-bill-search" class="form-control" placeholder="Bill no or {{ $returnSale ? 'customer' : 'supplier' }}…" autocomplete="off"
                           role="combobox" aria-expanded="false" aria-controls="return-bill-results" aria-autocomplete="list">
                    <ul class="return-bill-results" id="return-bill-results" role="listbox" hidden></ul>
                </div>
                <div class="field-compact-item doc-party-details">
                    <label>{{ $returnSale ? 'Customer' : 'Supplier' }}</label>
                    <div class="party-info-compact-inline u-d-flex">
                        <span class="u-fs-11px u-fw-700 u-c-text u-ws-nowrap" id="return-party">—</span>
                        <span class="u-fs-10_5px u-c-text-2 u-ws-nowrap u-ml-auto" id="return-bill-meta">Pick a bill to load its items</span>
                    </div>
                </div>
                <div class="field-compact-item u-w-140px u-shrink-0">
                    <label for="return-date" data-help="Date of the {{ strtolower($returnNote) }}. Stock and accounts post on this date. It cannot be before the bill date.|Today">Return date</label>
                    <input type="date" id="return-date" class="form-control" value="{{ date('Y-m-d') }}" required>
                </div>
            </div>
            <div class="fields-compact-row doc-row-b">
                <div class="field-compact-item doc-seg-item">
                    <label data-help="Goods return = items go back and stock changes. Rate difference / Discount = value only, no stock.|Goods return for damaged pieces">Type</label>
                    <div class="pill-segmented-compact doc-segment" role="group" aria-label="Return type">
                        <button type="button" class="segment-btn active" data-return-type="quantity">Goods return</button>
                        <button type="button" class="segment-btn" data-return-type="rate_difference">Rate difference</button>
                        <button type="button" class="segment-btn" data-return-type="discount">Discount</button>
                    </div>
                </div>
                <div class="field-compact-item return-reason-field">
                    <label for="return-reason" data-help="Why the goods came back or the value changed. Printed on the note.|Damaged in transit">Reason *</label>
                    <input type="text" id="return-reason" class="form-control" minlength="3" maxlength="500" placeholder="e.g. Damaged in transit" required>
                </div>
            </div>
        </div>

        <div class="desk-card items-container mb-1 u-r-6px u-p-0 u-ov-hidden u-flex-1-1-auto u-d-flex u-dir-column u-minh-0">
            <div class="items-section-header">
                <div class="items-counter-group u-d-flex u-items-center u-gap-6px">
                    <span class="items-title u-fw-700 u-fs-11_5px u-c-text">ITEMS ON BILL</span>
                    <span class="items-meta-badge text-muted u-fs-10_5px" id="return-line-count">0 line(s)</span>
                </div>
                <div class="items-controls-group">
                    <button type="button" class="btn btn-sm btn-outline-secondary comm-items-btn" id="btn-return-all">Return all</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary comm-items-btn" id="btn-return-none">Clear</button>
                </div>
            </div>
            <div class="table-responsive u-flex-1-1-auto u-ovy-auto u-h-100pct u-m-0">
                <table class="desk-grid-table cozy table table-sm mb-0 u-w-100pct return-grid">
                    <thead>
                        <tr class="u-bg-ink u-c-xffffff u-fs-11px">
                            <th class="u-w-36px u-ta-center">#</th>
                            <th>ITEM</th>
                            <th class="u-w-75px">UNIT</th>
                            <th class="u-w-80px u-ta-right">BILLED</th>
                            <th class="u-w-80px u-ta-right">RETURNED</th>
                            <th class="u-w-95px u-ta-right">RATE</th>
                            <th class="u-w-110px u-ta-center" id="return-input-head">RETURN QTY</th>
                            @if($returnSale)<th class="u-w-130px">CONDITION</th>@endif
                            <th class="u-w-95px u-ta-right">TAX</th>
                            <th class="u-w-105px u-ta-right">TOTAL</th>
                        </tr>
                    </thead>
                    <tbody id="return-lines">
                        <tr class="empty-placeholder-row">
                            <td colspan="{{ $returnSale ? 10 : 9 }}" class="text-center text-muted py-4 u-fs-12px">
                                Pick the bill above (or press <strong>Return</strong> on a bill in the list). Its items load here; enter what comes back.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="desk-summary-bottom-bar">
            <div class="d-flex align-items-center u-gap-10px">
                <span class="text-muted u-fs-11px" id="return-feedback" role="status">High-value or late notes wait for approval; stock and accounts change only once posted.</span>
            </div>
            <div class="bottom-action-buttons">
                <button type="button" class="btn btn-outline-secondary comm-bottom-btn" id="btn-return-discard">
                    <i class="dripicons-clockwise"></i> Discard
                </button>
                <button type="submit" class="btn btn-primary comm-bottom-btn comm-bottom-btn-primary" id="btn-return-save">
                    <i class="dripicons-document-edit"></i> Save {{ $returnNote }}
                </button>
            </div>
            <div class="bottom-summary-totals">
                <div class="u-ta-right">
                    <div class="u-fs-9_5px u-fw-700 u-c-text-muted u-ls-0_04em">NET</div>
                    <div id="return-net" class="u-fs-13px u-fw-700 u-c-text">₹ 0.00</div>
                </div>
                <div class="u-ta-right">
                    <div class="u-fs-9_5px u-fw-700 u-c-text-muted u-ls-0_04em">GST / TAX</div>
                    <div id="return-tax" class="u-fs-13px u-fw-700 u-c-text">₹ 0.00</div>
                </div>
                <div class="u-ta-right u-bl-1px-solid-border-strong u-pl-14px">
                    <div class="u-fs-9_5px u-fw-800 u-c-warning u-ls-0_04em">{{ strtoupper($returnNote) }}</div>
                    <div id="return-total" class="u-fs-15px u-fw-800 u-c-warning">₹ 0.00</div>
                </div>
            </div>
        </div>
    </form>
</div>

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/commercial-returns.css') }}">
@endpush
@push('scripts')
    <script src="{{ versioned_asset('js/commercial-returns.js') }}"></script>
@endpush
