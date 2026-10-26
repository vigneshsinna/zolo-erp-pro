{{-- Include once in each normal sales/purchase workspace. Entry always uses its existing grid. --}}
@if(config('commercial.enabled'))
<div class="d-none" id="commercial-orders" data-kind="{{ $orderKind }}" data-url="{{ url('/commercial/orders/'.$orderKind) }}">
    <button type="button" class="btn btn-sm btn-outline-primary" data-order-save>Save {{ $orderKind === 'sale' ? 'Sales' : 'Purchase' }} Order</button>
    <span class="small text-muted" data-order-feedback role="status" aria-live="polite"></span>
</div>
<div class="modal fade" id="commercial-orders-modal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="commercial-orders-title">
    <div class="modal-dialog modal-xl" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="commercial-orders-title">{{ $orderKind === 'sale' ? 'Sales' : 'Purchase' }} Orders</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
        <div class="modal-body">
            <p class="small text-muted">Load remaining lines into this workspace. Delivery and billing quantities are tracked separately in base units.</p>
            <div class="d-flex mb-2"><label class="mr-3"><input type="checkbox" data-orders-all> Include closed orders</label><button type="button" class="btn btn-sm btn-light" data-orders-refresh>Refresh</button></div>
            <div role="status" aria-live="polite" data-orders-status></div>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Order</th><th>Party</th><th>Status</th><th>To deliver (base)</th><th>To bill (base)</th><th>Actions</th></tr></thead><tbody data-orders-rows></tbody></table></div>
            <div class="d-flex justify-content-between"><button type="button" class="btn btn-sm btn-light" data-orders-prev>Previous page</button><button type="button" class="btn btn-sm btn-light" data-orders-next>Next page</button></div>
            <section data-order-detail hidden aria-label="Order lines"><h6 class="mt-3">Order lines and history</h6><div data-order-lines></div></section>
            <form data-order-cancel hidden class="mt-3"><input type="hidden" name="order_id"><label>Cancellation date<input type="date" name="business_date" class="form-control" required></label><label class="ml-2">Reason<input name="reason" class="form-control" required minlength="3" maxlength="500"></label><button type="submit" class="btn btn-sm btn-outline-danger ml-2">Cancel unfulfilled remainder</button></form>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div>
    </div></div>
</div>
@endif
