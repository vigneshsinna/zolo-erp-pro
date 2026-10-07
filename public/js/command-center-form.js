(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('command-center-form-data').textContent);
    const $ = window.jQuery;
    const form = document.getElementById(config.kind + '-entry-form');
    const grid = window.commandCenterGrid;
    const purchase = config.kind === 'purchase';
    let busy = false, draft = null, paymentError = '';
    const $message = $('<div class="alert" role="status" style="display:none">').insertBefore(form);
    const show = (text, failed = false) => $message.removeClass('alert-danger alert-success').addClass(failed ? 'alert-danger' : 'alert-success').text(text).show();
    const failure = xhr => show(xhr.responseJSON?.message || xhr.message || 'Unable to complete this action.', true);
    const $reason = $('<div class="form-group" hidden><label for="replacement-reason">Reason for replacement *</label><input id="replacement-reason" name="reason" class="form-control" maxlength="500"></div>').prependTo(form);
    const token = () => $(form).find('[name="_token"]').val();
    for (const name of ['shipping_cost', 'order_discount']) $('<input type="hidden">').attr({name, id: 'hidden-' + name.replaceAll('_', '-')}).val(0).appendTo(form);
    const api = (path, data, method = 'POST') => $.ajax({url: config.baseUrl + path, method, dataType: 'json', data: {...data, _token: token()}});

    function fields() {
        const values = {};
        $(form).serializeArray().forEach(({name, value}) => {
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
            if (!Number(id)) {
                id = grid.products.find(p => p.name.toLowerCase() === $row.find('.row-item-name').val().trim().toLowerCase())?.id;
            }
            if (!Number(id)) throw new Error('Select a catalog product for every row. Use Create item for a new product.');
            const unit = config.units.find(u => u.unit_name === $row.find('.row-unit-select').val());
            return {
                product_id: id, product_name: $row.find('.row-item-name').val(), product_code: $row.find('.row-product-code').val(),
                qty: $row.find('.row-qty').val(), [purchase ? 'net_unit_cost' : 'net_unit_price']: $row.find('.row-rate').val(),
                [purchase ? 'purchase_unit_id' : 'sale_unit_id']: unit?.id,
                discount: $row.find('.row-discount-val').val() || 0,
                imei_number: $row.find('.row-imei-val').val() || '',
                product_batch_id: $row.attr('data-batch-id') || null,
                ...(purchase && $row.find('.row-batch-val').val() ? {batch: {batch_no: $row.find('.row-batch-val').val(), expired_date: $row.find('.row-expire-val').val() || null}} : {})
            };
        });
        if (!items.length) throw new Error('Add at least one item.');
        for (const key of Object.keys(values)) if (Array.isArray(values[key])) delete values[key];
        for (const key of ['_method', 'pos', 'grand_total', 'order_tax', 'total_tax', 'total_price', 'total_cost', 'total_qty', 'total_discount']) delete values[key];
        const series = config.series.find(s => String(s.id) === String(values.series_id));
        const context = ['project_id', 'purchase_order_id', 'exchange_return_id'].reduce((refs, key) => config.context?.[key] ? {...refs, [key]: config.context[key]} : refs, {});
        return {...values, ...context, items, business_date: values.created_at,
            transport_name: values.transporter_name || '', series_code: series?.code,
            ...(draft ? {draft_id: draft.id} : {})};
    }
    function preview(data) {
        return api('/preview', data).then(result => {
            const totals = result.data;
            $('#order-table-body tr.order-item-row').each(function (i) {
                const line = totals.items[i];
                const $row = $(this), $tax = $row.find('.row-tax-rate');
                if (!$tax.find('option').toArray().some(option => Number(option.value) === Number(line.tax_rate))) $tax.append(new Option(line.tax_rate + '%', line.tax_rate));
                $tax.val(String(Number(line.tax_rate)));
                $row.find('.row-tax-amount-input').val(line.tax.toFixed(4));
                $row.find('.row-subtotal-input, .row-total').val(line.total.toFixed(4));
            });
            grid.recalcTableSummary();
            const net = totals.items.reduce((sum, line) => sum + Number(line.total) - Number(line.tax), 0);
            $('#display-net-amount').text('₹ ' + net.toFixed(2));
            $('#display-tax-amount').text('₹ ' + Number(totals.total_tax).toFixed(2));
            $('#display-grand-total, #header-grand-total-display').text('₹ ' + Number(totals.grand_total).toFixed(2));
            $('#hidden-total-cost, #hidden-total-price').val(net.toFixed(4));
            $('#hidden-total-tax').val(totals.total_tax);
            $('#hidden-grand-total').val(totals.grand_total);
            return {...data, items: totals.items, grand_total: totals.grand_total};
        });
    }
    $('#btn-form-review').on('click', () => {
        if (!config.shared) { show('Review total: ₹ ' + $('#hidden-grand-total').val()); return; }
        try { preview(payload()).done(data => show('Reviewed by server. Grand total: ₹ ' + Number(data.grand_total).toFixed(2))).fail(failure); }
        catch (error) { failure(error); }
    });
    $('#btn-form-submit').on('click', () => {
        $(form).find('[name="' + (purchase ? 'status' : 'sale_status') + '"]').val(1);
        form.requestSubmit();
    });
    $('#btn-form-save-as').text(config.shared ? 'Save draft' : 'Save as draft').on('click', () => {
        if (!config.shared) {
            $(form).find('[name="' + (purchase ? 'status' : 'sale_status') + '"]').val(3); form.requestSubmit(); return;
        }
        if (busy) return;
        try {
            const data = payload(); busy = true;
            api('/drafts', {id: draft?.id, version: draft?.version || 0, payload: {...data, command_center_fields: fields()}})
                .done(result => { draft = result.data; setDraftId(draft.id); show('Draft saved.'); loadDrafts(); }).fail(failure).always(() => { busy = false; });
        } catch (error) { failure(error); }
    });
    $(form).on('submit', function (event) {
        if (!config.shared) return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        try {
            const data = payload(); busy = true;
            $(form).find('[type="submit"]').prop('disabled', true);
            preview(data).then(checked => $.ajax({url: config.context?.post_url || form.action, method: $('#entry-form-method').val() === 'PUT' ? 'PUT' : 'POST',
                dataType: 'json', data: {...checked, _token: token()}}))
                .done(() => { window.location.href = config.listUrl; }).fail(failure)
                .always(() => { busy = false; $(form).find('[type="submit"]').prop('disabled', false); });
        } catch (error) { busy = false; $(form).find('[type="submit"]').prop('disabled', false); failure(error); }
    });
    $(document).on('input', '.row-item-name', function () {
        $(this).closest('tr').attr('data-product-id', 0).attr('data-batch-id', '').find('.row-product-id').val(0);
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
    $(document).on('click', '.btn-side-view, .btn-side-print', function (event) {
        event.preventDefault(); event.stopPropagation();
        const print = $(this).hasClass('btn-side-print');
        $.getJSON(config.listUrl + '/' + $(this).data('id') + (purchase ? '' : '/json')).done(result => {
            const doc = result[config.kind], $body = $details.find('tbody').empty();
            const $heading = $('<div>');
            $('<strong>').text(doc.reference_no).appendTo($heading);
            $('<p>').text((doc[purchase ? 'supplier' : 'customer']?.name || '') + ' · ' + String(doc.created_at).slice(0, 10)).appendTo($heading);
            $('#' + config.kind + '-content').empty().append($heading);
            result.items.forEach((item, i) => {
                const $row = $('<tr>').appendTo($body);
                [i + 1, item.product_name, item.batch_no || '—', item.qty + ' ' + item.unit_code, item.return_qty || 0,
                    item[purchase ? 'net_unit_cost' : 'net_unit_price'], item.tax, item.discount, item.total]
                    .forEach(value => $('<td>').text(value).appendTo($row));
            });
            $('#' + config.kind + '-footer').text('Grand total: ₹ ' + Number(doc.grand_total).toFixed(2) + ' · Paid: ₹ ' + Number(doc.paid_amount || 0).toFixed(2));
            if (print) $details.one('shown.bs.modal', printDetails);
            $details.modal('show');
        }).fail(failure);
    });
    $('#print-btn, #sale-print-btn').on('click', printDetails);
    $('#btn-save-drawer-details').on('click', () => {
        for (const name of ['shipping-cost', 'order-discount']) {
            if ($('#drawer-' + name).length) $('#hidden-' + name).val($('#drawer-' + name).val() || 0);
        }
        grid.recalcTableSummary();
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
    $('#side-export-print, #side-export-pdf').on('click', () => {
        $('body').addClass('command-center-printing-list'); window.print();
    });
    $('#side-export-excel, #side-export-csv').on('click', () => {
        const rows = [['Bill number', purchase ? 'Supplier' : 'Customer', 'Date', 'Status', 'Amount']];
        $('#side-bill-list .side-bill-card:visible').each(function () {
            const $card = $(this);
            rows.push([$card.find('.side-card-ref').text().trim(), $card.find('.side-card-party').text().trim(),
                $card.attr('data-date'), $card.find('.side-card-status').text().trim(), $card.find('.side-card-amount').text().replace(/[^\d.-]/g, '')]);
        });
        const csv = rows.map(row => row.map(value => '"' + String(value ?? '').replace(/^[=+@-]/, "'$&").replaceAll('"', '""') + '"').join(',')).join('\r\n');
        const url = URL.createObjectURL(new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8;'}));
        const link = document.createElement('a'); link.href = url; link.download = config.kind + '-register.csv'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    $(document).on('command-center-reset', () => {
        draft = null; paymentError = ''; $message.hide(); $reason.prop('hidden', true).find('input').prop('required', false).val('');
        $(form).find('[name="delivery_challan_id"], [name="goods_received_note_id"], [name="draft_id"]').remove();
        $(form).find('[name="idempotency_key"]').val(crypto.randomUUID());
        $(form).find('[name="status"], [name="sale_status"]').val(1);
        $('#charges-drawer').find('input, textarea').val('');
        $(form).find('[id^="hidden-"][type="text"], [id^="hidden-"][type="date"]').val('');
        $('#drawer-paid-amount, #hidden-paid-amount').val(0);
        $('#hidden-shipping-cost, #hidden-order-discount, #drawer-shipping-cost, #drawer-order-discount').val(0);
        $('#input-paying-method, #drawer-paying-method').val('Credit'); $('[data-mode]').removeClass('active').filter('[data-mode="Credit"]').addClass('active');
        $('#drawer-account-id').val($('#hidden-account-id').val());
        $('#drawer-paying-method, #drawer-account-id').selectpicker('refresh');
        grid.recalcTableSummary();
    });
    $(document).on('command-center-loaded', (event, doc) => {
        $(document).trigger('command-center-reset');
        if (config.shared) $reason.prop('hidden', false).find('input').prop('required', true);
        for (const field of ['bale_no', 'no_of_bales', 'lr_no', 'lr_date', 'station_to', 'order_no', 'credit_days']) {
            const value = doc[field] || doc.attributes_json?.[field] || '';
            $('#hidden-' + field.replaceAll('_', '-') + ', #drawer-' + field.replaceAll('_', '-')).val(field.endsWith('date') ? String(value).slice(0, 10) : value);
        }
        $('#hidden-transporter-name, #drawer-transporter-name').val(doc.transport_name || '');
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
        grid.recalcTableSummary();
    });

    function setDraftId(id) {
        $(form).find('[name="draft_id"]').remove();
        $('<input type="hidden" name="draft_id">').val(id).appendTo(form);
    }
    function loadDrafts() {
        if (!config.shared) return;
        api('/drafts', {}, 'GET').done(result => {
            $('.command-center-drafts').remove();
            const $list = $('<div class="command-center-drafts">').prependTo('#side-bill-list');
            result.data.forEach(record => {
                $('<button type="button" class="btn btn-sm btn-outline-secondary d-block mb-1">').text('Resume draft #' + record.id).appendTo($list).on('click', () => {
                    grid.resetFormToNew(); grid.switchWorkspaceMode('voucher');
                    draft = record; setDraftId(record.id); const data = JSON.parse(record.payload_json);
                    for (const [name, value] of Object.entries(data.command_center_fields || data)) {
                        const input = form.elements.namedItem(name);
                        if (input && typeof value !== 'object' && !['_token', '_method', 'idempotency_key'].includes(name)) input.value = value;
                    }
                    const editingId = data.command_center_fields?.[config.kind + '_id'];
                    if (editingId) {
                        form.action = config.listUrl + '/' + editingId;
                        $('#entry-form-method').val('PUT');
                        $reason.prop('hidden', false).find('input').prop('required', true);
                        $('#doc-title-text').text('Edit ' + config.kind + ' #' + editingId + ' (draft)');
                    }
                    for (const name of ['delivery_challan_id', 'goods_received_note_id']) {
                        if (data[name]) $('<input type="hidden">').attr('name', name).val(data[name]).appendTo(form);
                    }
                    $('[id^="drawer-"]').each(function () {
                        const hidden = document.getElementById(this.id.replace('drawer-', 'hidden-'));
                        if (hidden) $(this).val(hidden.value);
                    });
                    $('#drawer-paying-method').val($('#input-paying-method').val()).trigger('change');
                    $('#drawer-paying-method, #drawer-account-id').selectpicker('refresh');
                    $('#order-table-body tr.order-item-row').remove();
                    (data.items || []).forEach(item => {
                        const product = grid.products.find(p => String(p.id) === String(item.product_id));
                        const unitId = item[purchase ? 'purchase_unit_id' : 'sale_unit_id'];
                        grid.addProductRow({...item, preserve_line: true, product_name: item.product_name || product?.name || '',
                            [purchase ? 'cost' : 'price']: item[purchase ? 'net_unit_cost' : 'net_unit_price'],
                            batch_no: item.batch?.batch_no || '', expired_date: item.batch?.expired_date || '',
                            unit: config.units.find(u => u.id === Number(unitId))?.unit_name || product?.unit});
                    });
                    $('#customer_id, #supplier_id').val(data[purchase ? 'supplier_id' : 'customer_id']).trigger('change');
                    $('.selectpicker').selectpicker('refresh'); grid.recalcTableSummary(); show('Draft #' + record.id + ' loaded.');
                });
            });
        }).fail(failure);
    }
    loadDrafts();
})();
