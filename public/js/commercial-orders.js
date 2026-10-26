/* Orders use the normal document grid and its draft/posting contract. */
(function ($) {
    'use strict';
    $(function () {
        const root = document.getElementById('commercial-orders');
        if (!root) return;
        
        const kind = root.dataset.kind, base = root.dataset.url, form = document.getElementById(kind + '-entry-form');
        const modal = $('#commercial-orders-modal');
        const feedback = message => {
            const $fb = $(root).find('[data-order-feedback]');
            if ($fb.length) $fb.text(message);
            else if (window.toastr) window.toastr.info(message);
        };
        const error = xhr => xhr.responseJSON?.message || 'Order action failed. Try again.';
        const token = document.querySelector('meta[name="csrf-token"]')?.content || form?.querySelector('[name="_token"]')?.value;
        let page = 1, lastPage = 1, pending = false, generation = 0;
        let activeCommercialMode = 'bills';
        const cell = value => $('<td>').text(value ?? '');

        // Order registration / fetch into side panel
        function fetchSideOrders() {
            const current = ++generation;
            const $status = $('#side-orders-status');
            const $cards = $('#side-orders-cards');
            $status.text('Loading orders…');
            $cards.empty();
            
            const includeAll = $('#side-orders-all-toggle').prop('checked');
            const partyId = form?.querySelector('[name="' + (kind === 'sale' ? 'customer_id' : 'supplier_id') + '"]')?.value || '';

            $.getJSON(base, {page, party_id: partyId, status: includeAll ? 'all' : 'pending'})
                .done(data => {
                    if (current !== generation) return;
                    lastPage = data.last_page;
                    let count = 0;
                    (data.data || []).forEach(order => {
                        if (!includeAll && (order.reversed || ['cancelled', 'fulfilled'].includes(order.status))) return;
                        count++;
                        const statusClass = (order.status === 'fulfilled' ? 'received' : (order.status === 'partial' ? 'partial' : (order.status === 'cancelled' ? 'due' : 'ordered')));
                        const $card = $('<div class="side-bill-card side-order-card">').attr('data-order-id', order.id);
                        $card.html(`
                            <div class="side-card-top">
                                <strong class="side-card-ref">${order.reference_no}</strong>
                                <span class="side-card-status ${statusClass}">${order.status}</span>
                            </div>
                            <div class="side-card-party">
                                <i class="dripicons-user u-fs-10px u-c-text-muted"></i> ${order.party || (kind === 'sale' ? 'Customer' : 'Supplier')}
                            </div>
                            <div class="side-card-bottom">
                                <span class="side-card-date">To bill: <strong>${order.remaining_billing_base}</strong> base</span>
                                <span class="side-card-date">To deliver: <strong>${order.remaining_delivery_base}</strong> base</span>
                            </div>
                            <div class="side-card-actions">
                                ${(!order.reversed && order.remaining_billing_base > 0) ? `
                                    <a href="javascript:void(0)" class="side-action-btn edit btn-side-load-order" data-order-id="${order.id}" title="Load remaining items into ${kind === 'sale' ? 'Sales' : 'Purchase'} Bill">
                                        <i class="dripicons-download"></i> Load to bill
                                    </a>
                                ` : ''}
                                <a href="javascript:void(0)" class="side-action-btn view btn-side-order-details" data-order-id="${order.id}" title="View order lines">
                                    <i class="dripicons-preview"></i> Details
                                </a>
                                ${(!order.reversed && (order.remaining_billing_base > 0 || order.remaining_delivery_base > 0)) ? `
                                    <a href="javascript:void(0)" class="side-action-btn reverse btn-side-cancel-order" data-order-id="${order.id}" title="Cancel remainder">
                                        <i class="dripicons-cross"></i> Cancel
                                    </a>
                                ` : ''}
                            </div>
                        `);
                        $cards.append($card);
                    });
                    $status.text(count ? 'Showing ' + count + ' orders' : 'No orders on this page.');
                })
                .fail(xhr => {
                    if (current === generation) $status.text(error(xhr));
                });
        }

        function switchCommercialTab(mode) {
            activeCommercialMode = mode;
            const isOrders = (mode === 'orders');
            
            // 1. Update Tabs Active Indicator
            const $billsTab = $('[data-nav-tab="bills"]');
            const $ordersTab = $('[data-nav-tab="orders"], [data-order-register]');
            
            if (isOrders) {
                $billsTab.removeAttr('aria-current');
                $ordersTab.attr('aria-current', 'page');
                if (window.history && window.history.pushState) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('orders', '1');
                    url.searchParams.delete('view');
                    window.history.pushState({ mode: 'orders' }, '', url.toString());
                }
            } else {
                $ordersTab.removeAttr('aria-current');
                $billsTab.attr('aria-current', 'page');
                if (window.history && window.history.pushState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('orders');
                    url.searchParams.delete('view');
                    window.history.pushState({ mode: 'bills' }, '', url.toString());
                }
            }

            // 2. Breadcrumbs & Title
            const orderDocTitle = (kind === 'sale' ? 'New Sales Order' : 'New Purchase Order');
            const billDocTitle = (kind === 'sale' ? 'New Sales Bill' : 'New Purchase Bill');
            $('#doc-title-text').text(isOrders ? orderDocTitle : billDocTitle);
            $('#doc-breadcrumb-mode').text(isOrders ? 'Order' : 'New');

            // 3. Document Field Labels
            $('label[for="reference_no"]').text(isOrders ? (kind === 'sale' ? 'Our Order No' : 'Our PO No') : 'Our Bill No');
            $('#reference_no').attr('placeholder', isOrders ? 'Auto series (PO-...)' : 'Auto series');
            $('label[for="supplier_invoice_no"]').text(isOrders ? 'Quotation / Ref #' : 'Supplier Bill No');
            $('label[for="supplier_invoice_date"]').text(isOrders ? 'PO Date' : 'Bill Date');
            $('label[for="created_at"]').text(isOrders ? 'Delivery Date' : 'Entry Date');
            $('label[for="issue_date"]').text(isOrders ? 'Order Date' : 'Bill Date');
            $('label[for="entry_date"]').text(isOrders ? 'Delivery Date' : 'Entry Date');

            // 4. Form status value & Cash toggle
            const statusVal = isOrders ? (kind === 'sale' ? '2' : '4') : '1';
            $('#purchase-status-val, #sale-status-val').val(statusVal);
            $(form).find('[name="status"], [name="sale_status"]').val(statusVal);
            if (isOrders) {
                $('#input-paying-method').val('Credit');
                $('#hidden-paid-amount').val('0');
                $('.payment-type-segmented [data-payment-type="Credit"]').addClass('active');
                $('.payment-type-segmented [data-payment-type="Cash"]').removeClass('active').addClass('u-op-50pct u-pe-none');
            } else {
                $('.payment-type-segmented [data-payment-type="Cash"]').removeClass('u-op-50pct u-pe-none');
            }

            // 5. Save Button Styling & Text
            const saveText = isOrders
                ? '<i class="dripicons-document-edit"></i> Save ' + (kind === 'sale' ? 'Sales Order' : 'Purchase Order')
                : '<i class="dripicons-document-edit"></i> Save';
            $('#btn-form-save').html(saveText);

            // 6. Side Drawer Content
            if (isOrders) {
                $('.comm-drawer-title').html('<i class="dripicons-clipboard text-primary"></i> ' + (kind === 'sale' ? 'Sales Orders' : 'Purchase Orders'));
                $('#side-bill-list').addClass('d-none');
                $('.side-dropdown-filters, .side-panel-filter-label, .side-panel-series-filter, .side-filter-tabs').addClass('d-none');
                $('#side-order-list').removeClass('d-none');
                fetchSideOrders();
            } else {
                $('.comm-drawer-title').html('<i class="dripicons-view-list text-success"></i> ' + (kind === 'sale' ? 'Sales list' : 'Purchase list'));
                $('#side-order-list').addClass('d-none');
                $('.side-dropdown-filters, .side-panel-filter-label, .side-panel-series-filter, .side-filter-tabs').removeClass('d-none');
                $('#side-bill-list').removeClass('d-none');
            }
        }

        // Details function
        function details(id) {
            $.getJSON(base + '/' + id).done(({data}) => {
                const modal = $('#commercial-orders-modal');
                const container = modal.find('[data-order-lines]').empty();
                const edit = $('<form>');
                const table = $('<table class="table table-sm">').append('<thead><tr><th>Item</th><th>Unit</th><th>Ordered</th><th>Rate</th><th>Delivered (base)</th><th>Billed (base)</th><th>Cancelled (base)</th></tr></thead>');
                const body = $('<tbody>');
                data.items.forEach(item => {
                    const quantity = $('<input type="number" class="form-control form-control-sm" step="0.0001" min="0.0001" required>').val(item.qty).attr('aria-label', 'Ordered quantity for ' + (item.product?.name || item.product_id));
                    const rate = $('<input type="number" class="form-control form-control-sm" step="0.0001" min="0" required>').val(item.unit_rate).attr('aria-label', 'Order rate for ' + (item.product?.name || item.product_id));
                    rate.prop('readOnly', item.delivered_qty_base > 0 || item.billed_qty_base > 0);
                    const row = $('<tr>').attr('data-order-line', item.id).append(cell(item.product?.name), cell(item.unit?.unit_name || item.uom_id), $('<td>').append(quantity), $('<td>').append(rate), cell(item.delivered_qty_base), cell(item.billed_qty_base), cell(item.cancelled_qty_base));
                    body.append(row);
                });
                table.append(body).appendTo(edit);
                if (data.items.length && !data.order.reversed_at && data.order.status !== 'cancelled') {
                    const date = $('<input type="date" name="business_date" class="form-control" required>').val(form?.querySelector('[name="created_at"], [name="business_date"]')?.value || new Date().toLocaleDateString('en-CA'));
                    const reason = $('<input name="reason" class="form-control" required minlength="3" maxlength="500">');
                    $('<label class="mr-2">Amendment date</label>').append(date).appendTo(edit);
                    $('<label class="mr-2">Reason</label>').append(reason).appendTo(edit);
                    $('<button type="submit" class="btn btn-sm btn-outline-primary">Save order changes</button>').appendTo(edit);
                    const key = crypto.randomUUID();
                    edit.on('submit', function (event) {
                        event.preventDefault(); if (pending || !this.reportValidity()) return;
                        const items = edit.find('[data-order-line]').toArray().map(row => ({order_item_id: Number(row.dataset.orderLine), qty: row.querySelectorAll('input')[0].value, unit_rate: row.querySelectorAll('input')[1].value}));
                        pending = true;
                        $.ajax({url: base + '/' + id, method: 'PUT', headers: {'X-CSRF-TOKEN': token}, data: {items, business_date: date.val(), reason: reason.val(), version: data.order.attributes_json?.order_version || 1, idempotency_key: key}})
                            .done(() => { fetchSideOrders(); details(id); modal.find('[data-orders-status]').text('Order changes saved.'); }).fail(xhr => modal.find('[data-orders-status]').text(error(xhr))).always(() => { pending = false; });
                    });
                }
                edit.appendTo(container);
                data.events.forEach(event => $('<p class="small">').text(event.created_at + ' · ' + event.event + ' · ' + event.reason).appendTo(container));
                modal.find('[data-order-detail]').prop('hidden', false);
                modal.modal('show');
            }).fail(xhr => feedback(error(xhr)));
        }

        // Load order lines into bill
        function load(id) {
            const grid = window.commandCenterGrid;
            if (!grid || !window.zoloDocumentWorkspace) { feedback('Document workspace is still loading.'); return; }
            $.getJSON(base + '/' + id).done(async ({data}) => {
                const open = data.items.filter(item => item.remaining_qty > 0);
                if (!open.length) { feedback('Order has no remaining billing quantity.'); return; }
                if (!(await window.zoloDocumentWorkspace.newDocument({}))) return;
                
                // Switch tab back to Bills
                switchCommercialTab('bills');
                grid.switchWorkspaceMode?.('voucher');
                
                const order = data.order, field = kind === 'sale' ? 'sales_order_id' : 'purchase_order_id';
                $(form).find('[name="sales_order_id"], [name="purchase_order_id"]').remove();
                $('<input type="hidden">').attr('name', field).val(order.id).appendTo(form);
                const party = kind === 'sale' ? 'customer_id' : 'supplier_id';
                $(form).find('[name="' + party + '"]').val(order[party]).trigger('change');
                $(form).find('[name="warehouse_id"]').val(order.warehouse_id).trigger('change');
                $('#order-table-body .order-item-row, #order-table-body .empty-placeholder-row').remove();
                open.forEach(item => {
                    grid.addProductRow({preserve_line: true, product_id: item.product_id, product_name: item.product?.name,
                        product_code: item.product?.code, qty: item.remaining_qty, price: item.unit_rate, cost: item.unit_rate, rate: item.unit_rate,
                        unit: item.unit?.unit_name, variant_id: item.variant_id,
                        product_batch_id: item.product_batch_id, batch_no: item.batch_no});
                    $('#order-table-body tr.order-item-row').last().attr('data-order-item-id', item.id).attr('data-variant-id', item.variant_id || '').attr('data-order-batch-no', item.batch_no || '');
                });
                window.zoloDocumentWorkspace.config.context = {[field]: Number(order.id)};
                grid.recalcTableSummary?.();
                $(form).trigger('change');
                window.zoloDocumentWorkspace.touch();
                $('#doc-title-text').text((kind === 'sale' ? 'New Sales Bill' : 'New Purchase Bill') + ' (from ' + order.reference_no + ')');
                feedback('Loaded ' + order.reference_no + '. Review quantities before saving.');
            }).fail(xhr => feedback(error(xhr)));
        }

        // New order reset
        function resetNewOrder() {
            if (window.commandCenterGrid?.resetFormToNew) {
                window.commandCenterGrid.resetFormToNew();
            }
            switchCommercialTab('orders');
        }

        // TAB CLICK HANDLERS (In-place tab replacement, ZERO popups)
        $(document).on('click', '[data-order-register], [data-nav-tab="orders"]', function (event) {
            event.preventDefault();
            switchCommercialTab('orders');
        });

        $(document).on('click', '[data-nav-tab="bills"]', function (event) {
            event.preventDefault();
            switchCommercialTab('bills');
        });

        // Side panel action handlers
        $(document).on('click', '.btn-side-load-order', function (e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            if (orderId) load(orderId);
        });

        $(document).on('click', '.btn-side-order-details', function (e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            if (orderId) details(orderId);
        });

        $(document).on('click', '.btn-side-cancel-order', function (e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            const reason = prompt('Reason for cancelling unfulfilled remainder:');
            if (!reason) return;
            const businessDate = form?.querySelector('[name="created_at"], [name="business_date"]')?.value || new Date().toLocaleDateString('en-CA');
            $.ajax({
                url: base + '/' + orderId + '/cancel',
                method: 'POST',
                headers: {'X-CSRF-TOKEN': token},
                data: {business_date: businessDate, reason: reason}
            }).done(() => {
                feedback('Order remainder cancelled.');
                fetchSideOrders();
            }).fail(xhr => feedback(error(xhr)));
        });

        $(document).on('click', '#btn-side-new-order', function (e) {
            e.preventDefault();
            resetNewOrder();
        });

        $(document).on('change', '#side-orders-all-toggle', function () {
            fetchSideOrders();
        });

        // Intercept form submission when in orders mode to guarantee order status and payment=0
        $('#btn-form-save').on('click', function (e) {
            if (activeCommercialMode === 'orders') {
                $('#input-paying-method').val('Credit');
                $('#hidden-paid-amount').val('0');
                const statusVal = (kind === 'sale' ? '2' : '4');
                $('#purchase-status-val, #sale-status-val').val(statusVal);
                $(form).find('[name="status"], [name="sale_status"]').val(statusVal);
            }
        });

        // Window popstate navigation
        window.addEventListener('popstate', function (event) {
            const isOrders = new URLSearchParams(window.location.search).get('orders') === '1' || new URLSearchParams(window.location.search).get('view') === 'orders';
            switchCommercialTab(isOrders ? 'orders' : 'bills');
        });

        // Initialize state on page load
        const params = new URLSearchParams(window.location.search);
        const linked = params.get(kind === 'sale' ? 'sales_order_id' : 'purchase_order_id');
        if (linked) {
            load(Number(linked));
        } else if (params.get('orders') === '1' || params.get('view') === 'orders') {
            switchCommercialTab('orders');
        }

        window.switchCommercialTab = switchCommercialTab;
    });
})(window.jQuery);
