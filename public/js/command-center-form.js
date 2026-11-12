(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('command-center-form-data').textContent);
    const $ = window.jQuery;
    const form = document.getElementById(config.kind + '-entry-form');
    const grid = new Proxy({}, {
        get(_target, prop) {
            const instance = window.commandCenterGrid;
            if (!instance) {
                if (prop === 'products') return [];
                if (['recalcTableSummary', 'applyProductToRow', 'addProductRow', 'resetFormToNew', 'switchWorkspaceMode'].includes(prop)) {
                    return (...args) => window.commandCenterGrid?.[prop]?.(...args);
                }
                return undefined;
            }
            const val = instance[prop];
            return typeof val === 'function' ? val.bind(instance) : val;
        }
    });
    const purchase = config.kind === 'purchase';
    let busy = false, paymentError = '';
    const $message = $('<div class="alert" role="status" style="display:none">').insertBefore(form);
    const show = (text, failed = false) => $message.removeClass('alert-danger alert-success').addClass(failed ? 'alert-danger' : 'alert-success').text(text).show();
    const failure = xhr => show(xhr.responseJSON?.message || xhr.message || 'Unable to complete this action.', true);
    const $reason = $('<div class="form-group" hidden><label for="replacement-reason">Reason for replacement *</label><input id="replacement-reason" name="reason" class="form-control" maxlength="500"></div>').prependTo(form);
    const token = () => $(form).find('[name="_token"]').val();
    for (const name of ['shipping_cost', 'order_discount']) $('<input type="hidden">').attr({ name, id: 'hidden-' + name.replaceAll('_', '-') }).val(0).appendTo(form);
    const api = (path, data, method = 'POST') => $.ajax({ url: config.baseUrl + path, method, dataType: 'json', data: { ...data, _token: token() } });

    function fields() {
        const values = {};
        $(form).serializeArray().forEach(({ name, value }) => {
            if (name.endsWith('[]')) (values[name.slice(0, -2)] ??= []).push(value);
            else values[name] = value;
        });
        return values;
    }
    function payload() {
        if (paymentError) throw new Error(paymentError);
        $('#btn-save-drawer-details').trigger('click');
        const values = fields();
        const items = $('#order-table-body tr.order-item-row').toArray().map(row => {
            const $row = $(row);
            let id = $row.find('.row-product-id').val();
            const typedName = ($row.find('.row-item-name').val() || '').trim();

            // When a product was loaded (e.g. from GRN or existing row), but the user changed the name:
            if (id && typedName) {
                const bound = grid.products.find(p => String(p.id) === String(id));
                if (bound && bound.name.toLowerCase() !== typedName.toLowerCase() && (bound.code || '').toLowerCase() !== typedName.toLowerCase()) {
                    id = null;
                    $row.find('.row-product-id').val('');
                    $row.removeAttr('data-product-id data-material-document-id data-material-item-id data-material-unit-id data-material-base-factor');
                }
            }

            if (!Number(id) && typedName) {
                const matches = grid.products.filter(p => p.name.toLowerCase() === typedName.toLowerCase() || (p.code && p.code.toLowerCase() === typedName.toLowerCase()));
                if (matches.length > 1) throw new Error('Several products have this name. Select the correct product code from the list.');
                if (matches.length === 1) {
                    grid.applyProductToRow($row, matches[0]);
                    id = matches[0].id;
                }
            }
            if (!Number(id)) {
                if (typedName && (window.zoloOpenQuickCreateProduct?.(typedName, $row) || window.zoloUnknownProduct?.(typedName, $row, true))) throw new Error('Save the new product "' + typedName + '", then save the bill again.');
                throw new Error('Select a catalog product for every row. Use Create item for a new product.');
            }
            const unit = config.units.find(u => u.unit_name === $row.find('.row-unit-select').val());
            const line = {
                product_id: id, product_name: $row.find('.row-item-name').val(), product_code: $row.find('.row-product-code').val(),
                qty: $row.find('.row-qty').val(), [purchase ? 'net_unit_cost' : 'net_unit_price']: $row.find('.row-rate').val(),
                [purchase ? 'purchase_unit_id' : 'sale_unit_id']: unit?.id,
                discount: $row.find('.row-discount-val').val() || 0,
                imei_number: $row.find('.row-imei-val').val() || '',
                product_batch_id: $row.attr('data-batch-id') || null,
                ...(purchase && $row.find('.row-batch-val').val() ? { batch: { batch_no: $row.find('.row-batch-val').val(), expired_date: $row.find('.row-expire-val').val() || null } } : {})
            };
            const taxOverride = window.zoloLineTax?.override(row);
            if (taxOverride !== undefined) line.tax_rate_override = taxOverride;
            if ($row.attr('data-order-item-id')) line.order_item_id = Number($row.attr('data-order-item-id'));
            if ($row.attr('data-variant-id')) line.variant_id = Number($row.attr('data-variant-id'));
            const orderBatch = $row.attr('data-order-batch-no');
            if (orderBatch && !line.batch) line.batch = { batch_no: orderBatch };
            if ($row.attr('data-material-item-id')) {
                if ($row.attr('data-material-unit-id') && Number($row.attr('data-material-unit-id')) !== Number(line[purchase ? 'purchase_unit_id' : 'sale_unit_id'])) throw new Error('Keep the selected source unit, or remove this source line and load it again.');
                line.material_sources = [{
                    document_id: Number($row.attr('data-material-document-id')),
                    item_id: Number($row.attr('data-material-item-id')), qty_base: Number(line.qty) * Number($row.attr('data-material-base-factor'))
                }];
            }
            // Extended tracking (variant, piece, HSN, freight, dimensions, batch MRP/mfg date) is added by document-aids.js.
            const extras = window.zoloDocumentAids ? window.zoloDocumentAids.lineExtras(row) : {};
            if (extras.batch) { if (line.batch) line.batch = { ...line.batch, ...extras.batch }; delete extras.batch; }
            
            // Optical Rx fields from direct inline inputs or extras
            const sphVal = $row.find('.row-sph').val() || extras.sph || null;
            const cylVal = $row.find('.row-cyl').val() || extras.cyl || null;
            const axisVal = $row.find('.row-axis').val() || extras.axis || null;
            const additionVal = $row.find('.row-addition').val() || extras.addition || null;
            const lrVal = $row.find('.row-lr').val() || extras.lr || null;
            const baseVal = $row.find('.row-base').val() || extras.base || null;
            const diaVal = $row.find('.row-dia').val() || extras.dia || null;
            if (sphVal) extras.sph = sphVal;
            if (cylVal) extras.cyl = cylVal;
            if (axisVal) extras.axis = axisVal;
            if (additionVal) extras.addition = additionVal;
            if (lrVal) extras.lr = lrVal;
            if (baseVal) extras.base = baseVal;
            if (diaVal) extras.dia = diaVal;

            return { ...line, ...extras };
        });
        if (!items.length) throw new Error('Add at least one item.');
        for (const key of Object.keys(values)) if (Array.isArray(values[key])) delete values[key];
        for (const key of ['_method', 'pos', 'grand_total', 'order_tax', 'total_tax', 'total_price', 'total_cost', 'total_qty', 'total_discount']) delete values[key];
        const series = config.series.find(s => String(s.id) === String(values.series_id));
        const entryContext = window.zoloDocumentWorkspace?.config.context || config.context;
        const context = ['project_id', 'purchase_order_id', 'sales_order_id', 'exchange_return_id'].reduce((refs, key) => entryContext?.[key] ? { ...refs, [key]: entryContext[key] } : refs, {});
        const sourceIds = [...new Set(items.flatMap(item => (item.material_sources || []).map(source => source.document_id)))];
        if (sourceIds.length) {
            values[purchase ? 'goods_received_note_ids' : 'delivery_challan_ids'] = sourceIds;
            items.forEach(item => { item.material_sources ??= []; });
        }
        const product_id = items.map(i => i.product_id);
        const product_code = items.map(i => i.product_code);
        const qty = items.map(i => i.qty);
        const net_unit_cost = items.map(i => i.net_unit_cost || 0);
        const net_unit_price = items.map(i => i.net_unit_price || 0);
        const subtotal = items.map(i => i.total || (Number(i.qty) * Number(i.net_unit_cost || i.net_unit_price || 0)));
        const tax = items.map(i => i.tax || 0);
        const tax_rate = items.map(i => i.tax_rate || 0);
        const purchase_unit = items.map(i => i.purchase_unit || 'Unit');
        const sale_unit = items.map(i => i.sale_unit || 'Unit');

        const end_user_name = $('#hidden-end-user-name').val() || $('#drawer-end-user-name').val() || values.end_user_name || null;
        const end_user_phone = $('#hidden-end-user-phone').val() || $('#drawer-end-user-phone').val() || values.end_user_phone || null;
        const end_user_email = $('#hidden-end-user-email').val() || $('#drawer-end-user-email').val() || values.end_user_email || null;
        const end_user_address = $('#hidden-end-user-address').val() || $('#drawer-end-user-address').val() || values.end_user_address || null;
        const fitting_charge = $('#hidden-fitting-charge').val() || $('#drawer-fitting-charge').val() || values.fitting_charge || 0;
        const tinting_cost = $('#hidden-tinting-cost').val() || $('#drawer-tinting-cost').val() || values.tinting_cost || 0;
        const customer_order_no = $('#hidden-customer-order-no').val() || $('#drawer-customer-order-no').val() || values.customer_order_no || null;

        return {
            ...values, ...context, items,
            product_id, product_code, qty,
            net_unit_cost, net_unit_price, subtotal, tax, tax_rate,
            purchase_unit, sale_unit,
            sph: items.map(i => i.sph || null),
            cyl: items.map(i => i.cyl || null),
            axis: items.map(i => i.axis || null),
            base: items.map(i => i.base || null),
            addition: items.map(i => i.addition || null),
            lr: items.map(i => i.lr || null),
            dia: items.map(i => i.dia || null),
            end_user_name, end_user_phone, end_user_email, end_user_address,
            fitting_charge, tinting_cost, customer_order_no,
            business_date: values.created_at,
            transport_name: values.transporter_name || '', series_code: series?.code,
        };
    }
    function preview(data) {
        return api('/preview', data).then(result => {
            const totals = result.data;
            $('#order-table-body tr.order-item-row').each(function (i) {
                const line = totals.items[i];
                const $row = $(this), $tax = $row.find('.row-tax-rate');
                if (!$tax.find('option').toArray().some(option => Number(option.value) === Number(line.tax_rate))) $tax.append(new Option(line.tax_rate + '%', line.tax_rate));
                $tax.val(String(Number(line.tax_rate)));
                const productRate = line._tax_snapshot?.product_rate ?? line.product_tax_rate ?? (line.tax_rate_override === undefined ? line.tax_rate : undefined);
                if (productRate !== undefined) window.zoloLineTax?.productRate(this, productRate);
                $row.find('.row-tax-amount-input').val(line.tax.toFixed(4));
                $row.find('.row-subtotal-input, .row-total').val(line.total.toFixed(4));
            });
            grid.recalcTableSummary?.();
            const net = totals.items.reduce((sum, line) => sum + Number(line.total) - Number(line.tax), 0);
            $('#display-net-amount').text('₹ ' + net.toFixed(2));
            $('#display-tax-amount').text('₹ ' + Number(totals.total_tax).toFixed(2));
            $('#display-grand-total, #header-grand-total-display').text('₹ ' + Number(totals.grand_total).toFixed(2));
            $('#hidden-total-cost, #hidden-total-price').val(net.toFixed(4));
            $('#hidden-total-tax').val(totals.total_tax);
            $('#hidden-grand-total').val(totals.grand_total);
            return { ...data, items: totals.items, grand_total: totals.grand_total };
        });
    }
    $('#btn-form-review').on('click', () => {
        if (!config.shared) { show('Review total: ₹ ' + $('#hidden-grand-total').val()); return; }
        try { preview(payload()).done(data => show('Reviewed by server. Grand total: ₹ ' + Number(data.grand_total).toFixed(2))).fail(failure); }
        catch (error) { failure(error); }
    });
    // Shared mode: document-workspace.js turns this into Save draft (server draft tabs). Legacy mode posts a status-3 draft bill.
    $('#btn-form-save-as').text(config.shared ? 'Save draft' : 'Save as draft').on('click', () => {
        if (config.shared) return;
        $(form).find('[name="' + (purchase ? 'status' : 'sale_status') + '"]').val(3); form.requestSubmit();
    });
    $(form).on('submit', function (event) {
        if (!config.shared) return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        try {
            const data = payload(); busy = true;
            const ordersMode = form.dataset.ordersMode === '1';
            // A sales order is its own document (SalesOrder), not a pending bill; its Delivery Date is the promised date.
            const salesOrder = ordersMode && !purchase;
            $(form).find('[type="submit"]').prop('disabled', true);
            preview(data).then(checked => salesOrder
                ? $.ajax({
                    url: config.ordersUrl, method: 'POST', dataType: 'json',
                    data: { ...checked, promised_date: fields().entry_date || null, note: fields().sale_note || '', _token: token() }
                })
                : $.ajax({
                    url: config.context?.post_url || form.action, method: $('#entry-form-method').val() === 'PUT' ? 'PUT' : 'POST',
                    dataType: 'json', data: { ...checked, _token: token() }
                }))
                .done(() => { window.zoloDocumentWorkspace?.leave?.(); window.location.href = config.listUrl + (ordersMode ? '?orders=1' : ''); }).fail(failure)
                .always(() => { busy = false; $(form).find('[type="submit"]').prop('disabled', false); });
        } catch (error) { busy = false; $(form).find('[type="submit"]').prop('disabled', false); failure(error); }
    });
    $(document).on('input', '.row-item-name', function () {
        const row = $(this).closest('tr');
        row.attr('data-product-id', 0).attr('data-batch-id', '').find('.row-product-id').val(0);
        row.removeAttr('data-tracking data-variant-id data-order-item-id data-order-batch-no data-material-item-id data-material-document-id data-material-unit-id data-material-base-factor');
        row.find('.row-batch-val, .row-expire-val, .row-imei-val').val('');
    });

    const $details = $('#' + config.kind + '-details').appendTo('body');
    function printDetails() {
        $details.addClass('command-center-print-document');
        $('body').addClass('command-center-printing-document');
        window.print();
    }
    window.addEventListener('afterprint', () => {
        $('body').removeClass('command-center-printing-document command-center-printing-list');
        $details.removeClass('command-center-print-document');
    });
    // Posted GST documents print as the A4 tax invoice; anything else prints the details modal.
    let shown = null;
    // Purchase orders (status 4) print on the same A4 layout, built from their current lines.
    const invoiceUrl = doc => config.printUrl && ((doc?.posted_at && doc.document_snapshot_json) || (purchase && Number(doc?.status) === 4 && !doc.reversed_at))
        ? config.printUrl + '/' + doc.id + '/print?format=a4' : null;
    $(document).on('click', '.btn-side-print', function (event) {
        event.preventDefault(); event.stopPropagation();
        const print = true;
        // Opened during the click so popup blockers allow it; filled or closed once the document is known.
        const tab = print && config.printUrl ? window.open('', '_blank') : null;
        $.getJSON(config.listUrl + '/' + $(this).data('id') + (purchase ? '' : '/json')).done(result => {
            const doc = result[config.kind], $body = $details.find('tbody').empty();
            shown = doc;
            if (tab && invoiceUrl(doc)) { tab.location.href = invoiceUrl(doc); return; }
            tab?.close();
            const order = purchase && Number(doc.status) === 4;
            const $heading = $('<div>');
            if (order) $('<p class="text-uppercase font-weight-bold mb-1">').text('Purchase Order').appendTo($heading);
            $('<strong>').text(doc.reference_no).appendTo($heading);
            $('<p>').text((doc[purchase ? 'supplier' : 'customer']?.name || '') + ' · ' + (result.business_date || String(doc.created_at).slice(0, 10))).appendTo($heading);
            $('#' + config.kind + '-content').empty().append($heading);
            result.items.forEach((item, i) => {
                const $row = $('<tr>').appendTo($body);
                [i + 1, item.product_name, item.batch_no || '—', item.qty + ' ' + item.unit_code, item.return_qty || 0,
                item[purchase ? 'net_unit_cost' : 'net_unit_price'], item.tax, item.discount, item.total]
                    .forEach(value => $('<td>').text(value).appendTo($row));
            });
            $('#' + config.kind + '-footer').text('Grand total: ₹ ' + Number(doc.grand_total).toFixed(2) + (order ? '' : ' · Paid: ₹ ' + Number(doc.paid_amount || 0).toFixed(2)));
            if (doc.attributes_json?.source_documents?.length) $('#' + config.kind + '-footer').append($('<p>').text('Sources: ' + doc.attributes_json.source_documents.map(source => source.reference_no).join(', ')));
            const history = doc.attributes_json?.item_change_history;
            if (history) $('#' + config.kind + '-footer').append($('<p>').text('Replaces ' + history.original_bill + '. Previous items: ' + history.before.map(item => item.name).join(', ') + '. Current items: ' + history.after.map(item => item.name).join(', ')));
            if (config.canPayment && doc.posted_at && !doc.reversed_at && Number(doc.grand_total) > Number(doc.paid_amount || 0)) {
                const actions = $('<div class="command-center-detail-actions">').appendTo('#' + config.kind + '-footer');
                $('<a class="btn btn-primary bill-payment-action">').attr('href', config.paymentUrl + '?bill=' + doc.id).append($('<i class="dripicons-wallet" aria-hidden="true">'), document.createTextNode(' Add payment')).appendTo(actions);
            }
            if (print) $details.one('shown.bs.modal', printDetails);
            $details.modal('show');
        }).fail(xhr => { tab?.close(); failure(xhr); });
    });
    $('#print-btn, #sale-print-btn').on('click', () => invoiceUrl(shown) ? window.open(invoiceUrl(shown), '_blank') : printDetails());
    $('#btn-save-drawer-details').on('click', () => {
        for (const name of ['shipping-cost', 'order-discount']) {
            if ($('#drawer-' + name).length) $('#hidden-' + name).val($('#drawer-' + name).val() || 0);
        }
        grid.recalcTableSummary?.();
    });
    $('#drawer-standard-remark').on('change', function () { $('#drawer-note').val(this.value); });
    $('#drawer-paying-method').on('change', function () {
        $('#input-paying-method').val(this.value);
        $('[data-mode]').removeClass('active').filter('[data-mode="' + this.value + '"]').addClass('active');
    });
    $('[data-nature]').on('click', function () {
        $('[data-nature]').removeClass('active'); $(this).addClass('active');
    });
    $('#quick-create-item-modal').on('show.bs.modal', () => {
        $('#quick-item-type').val($('[data-nature].active').data('nature') === 'service' ? 'service' : 'standard');
        $('#quick-item-type').selectpicker('refresh');
    });
    // The bills shown in the side list, header row first: the register that prints and exports.
    const registerRows = () => {
        const rows = [['Bill number', purchase ? 'Supplier' : 'Customer', 'Date', 'Status', 'Amount']];
        const textile = !purchase && config.textile;
        if (textile) rows[0].push('Bale No', 'Transporter');
        $('#side-bill-list .side-bill-card:visible').each(function () {
            const $card = $(this);
            const row = [$card.find('.side-card-ref').text().trim(), $card.find('.side-card-party').text().trim(),
            $card.attr('data-date'), $card.find('.side-card-status').text().trim(), $card.find('.side-card-amount').text().replace(/[^\d.-]/g, '')];
            if (textile) row.push($card.find('.side-card-transport').attr('data-bale-no') || '', $card.find('.side-card-transport').attr('data-transporter') || '');
            rows.push(row);
        });
        return rows;
    };
    $('#side-export-print, #side-export-pdf').on('click', () => {
        const [head, ...body] = registerRows();
        if (!window.ErpPrint) { $('body').addClass('command-center-printing-list'); window.print(); return; }
        const total = body.reduce((sum, row) => sum + (Number(row[4]) || 0), 0);
        const footer = head.map((title, i) => i === 0 ? 'Total' : i === 4 ? total.toFixed(2) : '');
        window.ErpPrint.table(head, body.map(row => row.map(value => String(value ?? ''))), footer, (purchase ? 'Purchase' : 'Sales') + ' Register');
    });
    $('#side-export-excel, #side-export-csv').on('click', () => {
        const rows = registerRows();
        const csv = rows.map(row => row.map(value => '"' + String(value ?? '').replace(/^[=+@-]/, "'$&").replaceAll('"', '""') + '"').join(',')).join('\r\n');
        const url = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a'); link.href = url; link.download = config.kind + '-register.csv'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    $(document).on('command-center-reset', () => {
        paymentError = ''; $message.hide(); $reason.prop('hidden', true).find('input').prop('required', false).val('');
        $(form).find('[name="delivery_challan_id"], [name="goods_received_note_id"], [name="sales_order_id"], [name="purchase_order_id"], [name="quotation_id"], [name="draft_id"], [name="replaces_order_id"]').remove();
        $(form).find('[name="idempotency_key"]').val(crypto.randomUUID());
        if (window.zoloDocumentWorkspace?.config) window.zoloDocumentWorkspace.config.context = {};
        $(form).find('[name="status"], [name="sale_status"]').val(1);
        $('#charges-drawer').find('input, textarea').val('');
        $(form).find('[id^="hidden-"][type="text"], [id^="hidden-"][type="date"], #hidden-no-of-bales, #hidden-bundle-count').val('');
        $('#drawer-paid-amount, #hidden-paid-amount').val(0);
        $('#hidden-shipping-cost, #hidden-order-discount, #drawer-shipping-cost, #drawer-order-discount').val(0);
        $('#input-paying-method, #drawer-paying-method').val('Credit'); $('[data-mode]').removeClass('active').filter('[data-mode="Credit"]').addClass('active');
        $('#drawer-account-id').val($('#hidden-account-id').val());
        $('#drawer-paying-method, #drawer-account-id').selectpicker('refresh');
        grid.recalcTableSummary?.();
    });
    $(document).on('command-center-loaded', (event, doc) => {
        $(document).trigger('command-center-reset');
        if (config.shared) $reason.prop('hidden', false).find('input').prop('required', true);
        for (const field of ['bale_no', 'no_of_bales', 'bundle_count', 'vehicle_number', 'lr_no', 'lr_date', 'station_to', 'order_no', 'credit_days', 'end_user_name', 'end_user_phone', 'end_user_email', 'end_user_address', 'fitting_charge', 'tinting_cost', 'customer_order_no']) {
            const value = doc[field] || doc.attributes_json?.[field] || '';
            $('#hidden-' + field.replaceAll('_', '-') + ', #drawer-' + field.replaceAll('_', '-')).val(field.endsWith('date') ? String(value).slice(0, 10) : value);
        }
        $('#hidden-transporter-name, #drawer-transporter-name').val(doc.attributes_json?.transport_name ?? doc.transport_name ?? '');
        $('#hidden-note, #drawer-note').val(doc[purchase ? 'note' : 'sale_note'] || '');
        for (const name of ['shipping_cost', 'order_discount']) $('#hidden-' + name.replaceAll('_', '-') + ', #drawer-' + name.replaceAll('_', '-')).val(doc[name] || 0);
        $('#drawer-paid-amount, #hidden-paid-amount').val(doc.paid_amount || 0);
        if (Number(doc.paid_amount) > 0) {
            const payments = doc.payments || [], payment = payments[0];
            if (!payment || payments.some(p => p.paying_method !== payment.paying_method || p.account_id !== payment.account_id)
                || !['Cash', 'Bank', 'Cheque', 'Credit Card'].includes(payment.paying_method)) {
                paymentError = 'This document requires reviewed settlement before replacement because its payments cannot be represented by one method and account.';
                show(paymentError, true);
            } else {
                $('#drawer-paying-method').val(payment.paying_method).trigger('change');
                $('#drawer-account-id, #hidden-account-id').val(payment.account_id);
            }
        }
        $('#drawer-paying-method, #drawer-account-id').selectpicker('refresh');
        grid.recalcTableSummary?.();
    });

    // Draft tabs (create, autosave, switch, discard) live in document-workspace.js; this file only posts.
})();
