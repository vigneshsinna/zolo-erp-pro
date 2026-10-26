{{-- Returns tab drawer: recent debit / credit notes of this side. Filled by commercial-returns.js. --}}
<div class="side-list-container" id="side-return-list">
    <div class="side-orders-header d-flex justify-content-between align-items-center mb-2 px-1">
        <span class="small text-muted font-weight-bold">Recent {{ $kind === 'sale' ? 'credit' : 'debit' }} notes</span>
        <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold" id="btn-side-new-return">
            <i class="dripicons-plus"></i> New Return
        </button>
    </div>
    <div id="side-returns-status" class="small text-muted mb-2 px-1" role="status"></div>
    <div id="side-returns-cards"></div>
</div>

{{-- View of one note on this page (moved to <body> by commercial-returns.js). --}}
<div class="modal fade return-note-modal" id="return-note-modal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="return-note-title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="return-note-title">{{ $kind === 'sale' ? 'Credit note' : 'Debit note' }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="return-note-head">
                    <div><span>{{ $kind === 'sale' ? 'Customer' : 'Supplier' }}</span><strong data-note="party"></strong><small data-note="gstin"></small></div>
                    <div><span>Against bill</span><strong data-note="source"></strong></div>
                    <div><span>Date</span><strong data-note="date"></strong></div>
                    <div><span>Reason</span><strong data-note="reason"></strong></div>
                </div>
                <table class="table table-sm return-note-lines">
                    <thead><tr><th>#</th><th>Item</th><th>HSN/SAC</th><th class="u-ta-right">Qty</th><th class="u-ta-right">Rate</th><th class="u-ta-right">Taxable</th><th class="u-ta-right">GST</th><th class="u-ta-right">Total</th></tr></thead>
                    <tbody data-note="lines"></tbody>
                    <tfoot>
                        <tr><td colspan="5"></td><td class="u-ta-right" data-note="taxable"></td><td class="u-ta-right" data-note="tax"></td><td class="u-ta-right"><strong data-note="total"></strong></td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-primary" id="btn-return-note-print"><i class="dripicons-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>
