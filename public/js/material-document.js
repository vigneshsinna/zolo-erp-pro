(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('material-document-data').textContent);
    const $ = window.jQuery;
    const form = document.getElementById(config.form);
    const products = config.products;
    const $body = $('#order-table-body');
    let loading = false;
    let loadRequest;
    const money = value => Number(value || 0).toFixed(config.decimals);
    const escape = value => $('<span>').text(value ?? '').html();
    const error = xhr => $('#material-document-error').text(xhr?.responseJSON?.message || 'Unable to complete this action. Please try again.').show();
    function openReturn(doc, items) {
        const node = document.createElement('dialog'); node.className = 'commercial-aid-dialog'; node.setAttribute('aria-label', 'Return unbilled goods');
        const $dialog = $(node), key = crypto.randomUUID();
        $('<h3>').text('Return unbilled goods · ' + doc[config.numberField]).appendTo($dialog);
        const $returnForm = $('<form>'), $rows = $('<div>'); $returnForm.append($rows);
        items.filter(item => Number(item.open_qty ?? item.qty) > 0).forEach(item => {
            const product = products.find(product => String(product.id) === String(item.product_id));
            const variant = product?.variants?.find(variant => String(variant.id) === String(item.variant_id));
            const identity = [item.product_name, item.variant_id ? (variant?.name || 'Variant #' + item.variant_id) : '', item.batch_no ? 'Lot ' + item.batch_no : ''].filter(Boolean).join(' · ');
            const open = Number(item.open_qty ?? item.qty), label = $('<label class="commercial-source-line">').text(identity + ' · open ' + open + ' ' + (item.unit || ''));
            $('<input type="number" min="0" step="0.0001" required class="form-control">').attr({ max: open, 'data-item': item.id }).val(open).appendTo(label); $rows.append(label);
        });
        const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        $returnForm.append($('<label>').text('Return date').append($('<input type="date" name="business_date" required class="form-control">').val(today)));
        $returnForm.append($('<label>').text('Reason').append('<input name="reason" required minlength="3" maxlength="500" class="form-control">'));
        const $status = $('<p role="status">'), $save = $('<button type="submit" class="btn btn-primary">Return selected quantities</button>');
        $returnForm.append($status, $save); $dialog.append($returnForm, $('<button type="button" class="btn btn-light">Close</button>').on('click', () => node.close()));
        node.addEventListener('close', () => { node.remove(); $(config.returnButton).trigger('focus'); });
        $returnForm.on('submit', function (event) {
            event.preventDefault(); if (!this.reportValidity() || $save.prop('disabled')) return;
            const quantities = {}; $rows.find('[data-item]').each(function () { quantities[this.dataset.item] = Number(this.value); });
            if (!Object.values(quantities).some(qty => qty > 0)) { $status.text('Enter at least one positive quantity.'); return; }
            $save.prop('disabled', true);
            $.ajax({
                url: config.storeUrl + '/' + doc.id + '/return', method: 'POST', dataType: 'json', data: {
                    _token: config.token, business_date: this.business_date.value, reason: this.reason.value, items: quantities, idempotency_key: key
                }
            })
                .done(() => window.location.reload()).fail(xhr => $status.text(xhr.responseJSON?.message || 'Return failed. Try again.')).always(() => $save.prop('disabled', false));
        });
        document.body.append(node); node.showModal();
    }
    const refresh = select => { if ($.fn.selectpicker) $(select).filter('.selectpicker').selectpicker('refresh'); };

    $('#multi-item-modal, #quick-create-item-modal, #material-party-modal').appendTo('body');

    function totals() {
        let qty = 0, amount = 0, tax = 0;
        $body.find('.item-row').each(function (index) {
            const $row = $(this);
            const quantity = Number($row.find('.row-qty').val() || 0);
            const net = Number($row.find('.row-rate').val() || 0) * quantity;
            const gst = net * Number($row.find('.row-tax-rate').val() || 0) / 100;
            $row.find('.row-num').text(index + 1);
            $row.find('.row-amount').val(net.toFixed(2)); // display only; the server recomputes line amounts
            $row.find('.row-tax-amount').val(gst.toFixed(4));
            $row.find('.row-total').val((net + gst).toFixed(4));
            $row.find('.row-total-disp').text('₹ ' + money(net + gst));
            qty += quantity; amount += net; tax += gst;
        });
        const count = $body.find('.item-row').length;
        $('#empty-row-placeholder').toggle(count === 0);
        $('#items-counter').text('ITEMS ' + count + ' line(s)');
        $('#input_total_qty').val(qty);
        $(form).find('[name="' + config.totalField + '"]').val(amount.toFixed(4));
        $('#input_total_tax').val(tax.toFixed(4));
        $('#input_grand_total').val((amount + tax).toFixed(4));
        $('#disp-net').text('₹ ' + money(amount));
        $('#disp-tax').text('₹ ' + money(tax));
        $('#disp-grand').text('₹ ' + money(amount + tax));
    }

    function applyProduct($row, product) {
        if (String($row.find('.row-product-id').val()) !== String(product.id)) $row.find('.row-batch').val('');
        $row.attr('data-product-id', product.id || '');
        $row.find('.row-item-input-wrap').toggleClass('has-product', Number(product.id) > 0);
        $row.find('.row-product-id').val(product.id);
        $row.find('.row-unit-id').val(product.unit_id);
        $row.find('.row-item-name').val(product.name);
        $row.find('.row-product-code').text(product.code);
        $row.find('.row-unit').text(product.unit);
        $row.find('.row-rate').val(product[config.rateField] ?? product[config.purchase ? 'cost' : 'price'] ?? 0);
        $row.find('.row-tax-rate').val(product.tax_rate ?? 0);
        $row.find('.row-order-item-id').val('');
        variants($row, product);
        totals();
    }

    function variants($row, product, selected = '') {
        const select = $row.find('.row-variant').empty().append($('<option>').val('').text('Select variant'));
        (product?.variants || []).forEach(variant => select.append($('<option>').val(variant.id).text(variant.name + (variant.code ? ' · ' + variant.code : ''))));
        if (selected && !select.find('option').toArray().some(option => String(option.value) === String(selected))) {
            select.append($('<option>').val(selected).text('Retained variant #' + selected));
        }
        select.val(selected || '').prop('required', !!product?.is_variant).prop('hidden', !product?.is_variant && !selected);
    }

    function search($input, select) {
        $input.autocomplete({
            minLength: 1, autoFocus: false,
            source: (request, response) => {
                const term = (request.term || '').trim().toLowerCase();
                const matches = products.filter(p => (p.name + ' ' + (p.code || '')).toLowerCase().includes(term)).slice(0, 20);
                if (term) {
                    matches.push({
                        is_create_action: true,
                        term: request.term.trim(),
                        id: 0,
                        code: '+',
                        name: 'Create "' + request.term.trim() + '" as new product'
                    });
                }
                response(matches);
            },
            focus: (event, ui) => {
                if (ui && ui.item && ui.item.is_create_action) {
                    event.preventDefault();
                    return false;
                }
            },
            select: (event, ui) => {
                event.preventDefault();
                if (ui.item && ui.item.is_create_action) {
                    const $row = $input.closest('tr.item-row');
                    if (window.zoloOpenQuickCreateProduct) {
                        window.zoloOpenQuickCreateProduct(ui.item.term, $row.length ? $row : null);
                    } else if (window.zoloUnknownProduct) {
                        window.zoloUnknownProduct(ui.item.term, $row.length ? $row : null, true);
                    }
                    return;
                }
                select(ui.item);
            }
        }).autocomplete('instance')._renderItem = (ul, item) => {
            if (item.is_create_action) {
                return $('<li>').addClass('ui-autocomplete-action-item').append(`
                    <div class="custom-ac-item ac-quick-create-card">
                        <div class="d-flex align-items-center">
                            <span class="ac-quick-create-icon">+</span>
                            <div>
                                <div class="ac-quick-create-title">Create "<strong>${$('<span>').text(item.term).html()}</strong>" as new product</div>
                            </div>
                        </div>
                        <span class="ac-quick-create-pill">+ Quick Create</span>
                    </div>
                `).appendTo(ul);
            }
            return $('<li>').append($('<div>').text(item.code + ' — ' + item.name)).appendTo(ul);
        };
    }

    function addRow(item = {}, merge = true) {
        if (merge && item.product_id) {
            const $existing = $body.find('.item-row').filter(function () {
                const row = $(this);
                return String(row.find('.row-product-id').val()) === String(item.product_id)
                    && String(row.find('.row-variant').val() || '') === String(item.variant_id || '')
                    && String(row.find('.row-batch').val() || '') === String(item.batch_no || '')
                    && (!item.unit_id || String(row.find('.row-unit-id').val()) === String(item.unit_id))
                    && String(row.find('.row-order-item-id').val() || '') === String(item.order_item_id || '');
            }).first();
            if ($existing.length) {
                $existing.find('.row-qty').val(Number($existing.find('.row-qty').val()) + Number(item.qty ?? 1));
                totals(); return;
            }
        }
        const product = products.find(p => String(p.id) === String(item.product_id));
        const rate = item[config.rateField] ?? product?.[config.purchase ? 'cost' : 'price'] ?? 0;
        const boundPid = item.product_id ?? product?.id ?? '';
        const $row = $(`<tr class="item-row" data-product-id="${boundPid}">
            <td><span class="row-num"></span><input type="hidden" name="product_id[]" class="row-product-id">
                <input type="hidden" name="unit_id[]" class="row-unit-id">
                <input type="hidden" name="order_item_id[]" class="row-order-item-id">
                <input type="hidden" name="tax_amount[]" class="row-tax-amount"><input type="hidden" name="total[]" class="row-total"></td>
            <td><div class="row-item-input-wrap ${Number(boundPid) > 0 ? 'has-product' : ''}">
                    <input class="form-control form-control-sm row-item-name" placeholder="Search item or scan..." autocomplete="off" required>
                    <button type="button" class="btn-quick-edit-product" title="Edit product master" aria-label="Edit Product">
                        <i class="dripicons-document-edit"></i><span>Edit</span>
                    </button>
                </div>
                <small class="row-product-code"></small><input type="hidden" name="item_remarks[]" class="row-remarks">
                <select name="variant_id[]" class="form-control form-control-sm row-variant mt-1" aria-label="Item variant" hidden></select>
                <input name="batch_no[]" class="form-control form-control-sm row-batch mt-1" maxlength="100" placeholder="Lot / batch (if tracked)" autocomplete="off"></td>
            <td>${escape(config.typeLabel)}</td><td><span class="row-unit"></span></td>
            <td><input type="number" min="0" step="0.0001" name="${config.rateField}[]" class="form-control form-control-sm row-rate text-right" required></td>
            <td><input type="number" min="0.000001" step="any" name="qty[]" class="form-control form-control-sm row-qty text-right" required></td>
            <td><input name="amount[]" class="form-control form-control-sm row-amount text-right" readonly></td>
            <td><input type="number" min="0" max="100" step="0.0001" name="tax_rate[]" class="form-control form-control-sm row-tax-rate text-right" required></td>
            <td><span class="row-total-disp"></span></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger btn-remove-row" title="Remove Item" aria-label="Remove Item"><i class="dripicons-trash"></i></button></td>
        </tr>`);
        $row.find('.row-product-id').val(item.product_id ?? '');
        $row.find('.row-unit-id').val(item.unit_id ?? product?.unit_id ?? '');
        $row.find('.row-item-name').val(item.product_name ?? product?.name ?? '');
        $row.find('.row-product-code').text(item.product_code ?? product?.code ?? '');
        $row.find('.row-unit').text(item.unit ?? product?.unit ?? '');
        $row.find('.row-remarks').val(item.remarks ?? '');
        $row.find('.row-batch').val(item.batch_no ?? '');
        $row.find('.row-order-item-id').val(item.order_item_id ?? '');
        variants($row, product, item.variant_id);
        $row.find('.row-rate').val(rate);
        $row.find('.row-qty').val(item.qty ?? 1);
        $row.find('.row-tax-rate').val(item.tax_rate ?? product?.tax_rate ?? 0);
        search($row.find('.row-item-name'), p => { applyProduct($row, p); $row.find('.row-qty').focus().select(); });
        $row.find('.row-item-name').on('input', function () {
            var curVal = this.value.trim().toLowerCase();
            var curProd = products.find(p => String(p.id) === String($row.find('.row-product-id').val()));
            if (!curProd || (curProd.name.toLowerCase() !== curVal && (curProd.code || '').toLowerCase() !== curVal)) {
                $row.removeAttr('data-product-id').find('.row-item-input-wrap').removeClass('has-product');
                $row.find('.row-product-id, .row-unit-id, .row-order-item-id, .row-batch').val('');
                $row.find('.row-product-code').text('');
                variants($row, null);
            }
        });
        $row.find('.row-item-name').on('change', function () {
            const val = this.value.trim();
            if (!val) {
                $row.removeAttr('data-product-id').find('.row-item-input-wrap').removeClass('has-product');
                $row.find('.row-product-id, .row-unit-id, .row-order-item-id, .row-batch').val('');
                $row.find('.row-product-code').text('');
                variants($row, null);
                totals();
                return;
            }
            if ($row.find('.row-product-id').val()) return;
            const matches = products.filter(p => p.name.toLowerCase() === val.toLowerCase() || (p.code && p.code.toLowerCase() === val.toLowerCase()));
            if (matches.length === 1) {
                applyProduct($row, matches[0]);
                $row.find('.row-qty').focus().select();
            } else if (matches.length === 0) {
                if (window.zoloOpenQuickCreateProduct && window.zoloOpenQuickCreateProduct(val, $row)) {
                    return;
                } else if (window.zoloUnknownProduct && window.zoloUnknownProduct(val, $row, true)) {
                    return;
                }
            }
        });
        $row.find('.row-item-name').on('keydown', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                const val = this.value.trim();
                if (!val) return;
                const matches = products.filter(p => p.name.toLowerCase() === val.toLowerCase() || (p.code && p.code.toLowerCase() === val.toLowerCase()));
                if (matches.length === 1) {
                    applyProduct($row, matches[0]);
                    $row.find('.row-qty').focus().select();
                } else if (matches.length === 0) {
                    if (window.zoloOpenQuickCreateProduct && window.zoloOpenQuickCreateProduct(val, $row)) {
                        return;
                    } else if (window.zoloUnknownProduct && window.zoloUnknownProduct(val, $row, true)) {
                        return;
                    }
                    $row.find('.row-qty').focus().select();
                } else {
                    $row.find('.row-item-name').autocomplete('search', val);
                }
            }
        });
        $body.append($row); totals();
        return $row;
    }

    search($('#lims_productcodeSearch'), p => {
        addRow({ product_id: p.id }); $('#lims_productcodeSearch').val('').focus();
    });
    $('#btn-add-row').on('click', () => addRow().find('.row-item-name').focus());
    $body.on('input', '.row-rate, .row-qty, .row-tax-rate', totals);
    $body.on('click', '.btn-remove-row', function () { $(this).closest('tr').remove(); totals(); });
    // Capture Alt+I before bootstrap-select treats I as option search; F2/F12 start document drafts.
    document.addEventListener('keydown', event => {
        if (event.altKey && !event.ctrlKey && !event.shiftKey && !event.metaKey && event.key.toLowerCase() === 'i'
            && !event.defaultPrevented && !event.isComposing && $('#lims_productcodeSearch').is(':visible')
            && !document.querySelector('dialog[open], .modal.show, [data-owns-keys]')) {
            event.preventDefault();
            event.stopPropagation();
            $('#lims_productcodeSearch').focus().select();
        }
    }, true);
    $('.density-btn').on('click', function () {
        $('.density-btn').removeClass('active'); $(this).addClass('active');
        $('#order-table').removeClass('compact cozy large').addClass($(this).data('density'));
    });
    $('#multi-item-search').on('input', function () {
        const term = this.value.toLowerCase();
        $('#multi-item-tbody tr').each(function () { $(this).toggle($(this).text().toLowerCase().includes(term)); });
    });
    $('#select-all-multi').on('change', function () { $('#multi-item-tbody tr:visible .multi-item-cb').prop('checked', this.checked); });
    $('#btn-add-selected-items').on('click', () => {
        $('#multi-item-tbody .multi-item-cb:checked').each(function () { addRow({ product_id: this.value }); });
        $('#multi-item-modal').modal('hide').find('input[type="checkbox"]').prop('checked', false);
    });
    $(document).on('zolo:product-updated', function (e, p, prevMaster) {
        if (!p || !p.id) return;
        const idx = products.findIndex(item => String(item.id) === String(p.id));
        if (idx !== -1) products[idx] = $.extend({}, products[idx], p);
        else products.unshift(p);

        const rateKey = config.purchase ? 'cost' : 'price';
        const prevRate = prevMaster ? Number(prevMaster[rateKey] || 0) : null;
        const nextRate = Number(p[rateKey] ?? 0);

        $body.find('.item-row').filter(function () {
            return String($(this).find('.row-product-id').val()) === String(p.id);
        }).each(function () {
            const $row = $(this);
            $row.find('.row-item-name').val(p.name);
            $row.find('.row-product-code').text(p.code);
            $row.find('.row-unit').text(p.unit);
            if (p.unit_id) $row.find('.row-unit-id').val(p.unit_id);
            const curRate = parseFloat($row.find('.row-rate').val());
            if (isNaN(curRate) || curRate === 0 || (prevRate !== null && Math.abs(curRate - prevRate) < 0.0001)) {
                $row.find('.row-rate').val(nextRate);
            }
            if (p.tax_rate !== undefined && p.tax_rate !== null) {
                $row.find('.row-tax-rate').val(p.tax_rate);
            }
        });
        totals();
    });

    $('#quick-create-item-form').on('submit', function (event) {
        event.preventDefault();
        if ($('#quick-edit-product-id').val()) {
            if (window.zoloSubmitQuickEditProduct) window.zoloSubmitQuickEditProduct(this);
            return;
        }
        const $button = $(this).find('[type="submit"]').prop('disabled', true);
        const formArray = $(this).serializeArray();
        const postData = { _token: config.token };
        formArray.forEach(f => { postData[f.name] = f.value; });
        if (!postData.name) postData.name = $('#quick-item-name').val();
        if (!postData.code) postData.code = $('#quick-item-code').val();
        if (!postData.price) postData.price = $('#quick-item-price').val() || '0';
        if (!postData.cost) postData.cost = $('#quick-item-cost').val() || '0';

        $.ajax({ url: config.productUrl, method: 'POST', dataType: 'json', data: postData }).done(result => {
            products.unshift(result.product);
            const $targetRow = window.zoloUnknownProductRow ? window.zoloUnknownProductRow() : null;
            if ($targetRow && $targetRow.length && $targetRow.closest('body').length) {
                applyProduct($targetRow, result.product);
                $targetRow.find('.row-qty').focus().select();
            } else {
                addRow({ product_id: result.product.id });
            }
            $('#multi-item-tbody').prepend($('<tr class="multi-item-row">').append(
                $('<td>').append($('<input type="checkbox" class="multi-item-cb">').val(result.product.id)),
                ...[result.product.code, result.product.name, result.product.unit, money(result.product[config.purchase ? 'cost' : 'price'])].map(value => $('<td>').text(value))
            ));
            this.reset(); $('#quick-create-item-modal').modal('hide');
        }).fail(xhr => { $('#quick-create-item-modal').modal('hide'); error(xhr); }).always(() => $button.prop('disabled', false));
    });
    $(config.partyButton).on('click', () => $('#material-party-modal').modal('show'));
    $('#material-party-form').on('submit', function (event) {
        event.preventDefault();
        const $button = $(this).find('[type="submit"]').prop('disabled', true);
        $.ajax({ url: config.partyUrl, method: 'POST', dataType: 'json', data: $(this).serialize() })
            .done(result => {
                const party = result[config.partyType];
                $(config.partySelect).append($('<option>').val(party.id).text(party.name)).val(party.id).trigger('change');
                refresh(config.partySelect); this.reset(); $('#material-party-modal').modal('hide');
            }).fail(xhr => $('#material-party-error').text(xhr.responseJSON?.message || 'Unable to save party.').show())
            .always(() => $button.prop('disabled', false));
    });

    function panel(open) { $('#comm-split-grid').toggleClass('drawer-collapsed', !open); }
    $('#toggle-drawer-btn').on('click', () => panel($('#comm-split-grid').hasClass('drawer-collapsed')));
    $('#reopen-panel-btn, #material-list-link').on('click', event => { event.preventDefault(); panel(true); });
    $('#btn-close-panel').on('click', () => panel(false));
    $('#btn-dock-toggle').on('click', () => $('#comm-split-grid').toggleClass('dock-left'));
    $('#open-transport-drawer-btn, #btn-bottom-transport').on('click', () => $('#comm-drawer').prop('hidden', false));
    $('#close-drawer-btn').on('click', () => $('#comm-drawer').prop('hidden', true));
    $('#side-search-input').on('input', function () {
        const term = this.value.toLowerCase();
        $('.bill-card').each(function () { $(this).toggle($(this).text().toLowerCase().includes(term)); });
    });

    function reset() {
        loadRequest?.abort(); loading = false;
        form.reset(); $('#form-method').val('POST'); form.action = config.storeUrl;
        $(form).find('[name="' + config.idField + '"]').val('');
        $(form).find('[name="sales_order_id"], [name="purchase_order_id"]').remove();
        $body.find('.item-row').remove(); $('.bill-card').removeClass('active-editing');
        $('#entry-title-text').text(config.title); $('#entry-breadcrumb').text(config.breadcrumb);
        $('#status-pill').text('New'); $(config.convertButton + ', ' + config.returnButton).hide();
        $('#btn-save, #btn-add-row, #btn-multi-item, #btn-create-item-modal').prop('disabled', false);
        $('#comm-drawer').prop('hidden', true); $('#material-document-error').hide();
        refresh($(form).find('select')); totals();
    }
    $(document).on('click', '.btn-load-challan, .btn-load-grn, .bill-card', async function (event) {
        if ($(event.target).closest('a').length) return;
        event.stopPropagation();
        // Opening a saved document must not silently replace unsaved work: keep it as a draft first.
        if (drafts && !(await drafts.leaveActive())) return;
        drafts?.detachNow();
        loadRequest?.abort(); loading = true;
        loadRequest = $.getJSON(config.storeUrl + '/' + $(this).data('id')).done(result => {
            const doc = result[config.documentKey];
            form.reset(); form.action = config.storeUrl + '/' + doc.id; $('#form-method').val('PUT');
            $(form).find('[name="' + config.idField + '"]').val(doc.id);
            for (const [name, value] of Object.entries(doc)) {
                const input = form.elements.namedItem(name);
                if (input && !name.startsWith('_') && !['total_qty', config.totalField, 'total_tax', 'grand_total'].includes(name)) {
                    input.value = input.type === 'date' ? String(value ?? '').slice(0, 10) : (value ?? '');
                }
            }
            $body.find('.item-row').remove();
            result.items.forEach(item => addRow(item, false)); totals();
            const linkedOrder = result.items.find(item => item.order_id);
            $(form).find('[name="sales_order_id"], [name="purchase_order_id"]').remove();
            if (linkedOrder) $('<input type="hidden">').attr('name', config.purchase ? 'purchase_order_id' : 'sales_order_id').val(linkedOrder.order_id).appendTo(form);
            $('#entry-title-text').text('Edit ' + config.label + ': ' + doc[config.numberField]);
            $('#entry-breadcrumb').text(config.breadcrumb.replace('/ New', '/ Edit'));
            $('#status-pill').text(doc.status.replaceAll('_', ' '));
            $('#material-source-history').remove();
            if (result.history?.length) {
                $('<button type="button" id="material-source-history" class="btn btn-sm btn-outline-secondary">History / linked bills</button>').insertAfter('#status-pill').on('click', () => {
                    const node = document.createElement('dialog'); node.className = 'commercial-aid-dialog'; node.setAttribute('aria-label', 'Source document history');
                    const box = $(node); $('<h3>').text('History · ' + doc[config.numberField]).appendTo(box);
                    result.history.forEach(entry => {
                        const row = $('<p>').text(entry.type + ' · ' + entry.reference_no + ' · ' + entry.qty_base + ' base units · ' + (entry.released_at ? 'Reversed' : 'Active'));
                        if (entry.url) $('<a class="ml-2">Open bill</a>').attr('href', entry.url).appendTo(row); box.append(row);
                    });
                    $('<button type="button" class="btn btn-light">Close</button>').on('click', () => node.close()).appendTo(box);
                    node.addEventListener('close', () => node.remove()); document.body.append(node); node.showModal();
                });
            }
            // Editable only before anything is billed or returned; billable and returnable while goods stay unbilled.
            const pending = doc.status === 'pending';
            const open = pending || doc.status === 'partially_billed';
            $('#btn-save, #btn-add-row, #btn-multi-item, #btn-create-item-modal').prop('disabled', !pending);
            $(config.convertButton).toggle(open).off('click').on('click', () => {
                window.location.href = config.convertUrl + '?' + config.convertParam + '=' + doc.id;
            });
            $(config.returnButton).toggle(open && !!doc.posted_at).off('click').on('click', () => {
                openReturn(doc, result.items);
            });
            $('.bill-card').removeClass('active-editing').filter('[data-id="' + doc.id + '"]').addClass('active-editing');
            refresh($(form).find('select'));
            drafts?.detach();
        }).fail(xhr => { if (xhr.statusText !== 'abort') error(xhr); }).always(() => { loading = false; });
    });
    $(form).on('submit', function (event) {
        $('#material-document-error').hide();

        // Check if any row has an item-name typed but not resolved
        const unresolvedRow = $body.find('.item-row').filter(function () {
            return !$(this).find('.row-product-id').val() && $(this).find('.row-item-name').val().trim();
        }).first();
        if (unresolvedRow.length) {
            const typedName = unresolvedRow.find('.row-item-name').val().trim();
            const matches = products.filter(p => p.name.toLowerCase() === typedName.toLowerCase() || (p.code && p.code.toLowerCase() === typedName.toLowerCase()));
            if (matches.length === 1) {
                applyProduct(unresolvedRow, matches[0]);
                totals();
            } else if (window.zoloUnknownProduct && window.zoloUnknownProduct(typedName, unresolvedRow)) {
                event.preventDefault();
                return;
            }
        }

        if (loading || !$body.find('.item-row').length || $body.find('.row-product-id').toArray().some(input => !input.value)) {
            event.preventDefault(); $('#material-document-error').text('Select a catalog product for every row. Use Create item for a new product.').show();
        } else { totals(); }
    });
    /* ------------------------------------------------------------- draft tabs */
    // Every edit is kept as a server draft; the shortcut key or "New" opens one more draft (document-drafts.js).
    const SKIP = new Set(['_token', 'draft_id']);
    const fieldControls = () => Array.from(form.querySelectorAll('input[name], select[name], textarea[name]'))
        .filter(element => !SKIP.has(element.name) && element.type !== 'file' && !element.closest('#order-table-body'));
    function snapshot() {
        const fields = {};
        fieldControls().forEach(element => { fields[element.name] = element.value; });
        const lines = $body.find('.item-row').toArray().map(row => {
            const $row = $(row);
            return {
                product_id: $row.find('.row-product-id').val(), unit_id: $row.find('.row-unit-id').val(), product_name: $row.find('.row-item-name').val(),
                variant_id: $row.find('.row-variant').val(), order_item_id: $row.find('.row-order-item-id').val(),
                product_code: $row.find('.row-product-code').text(), unit: $row.find('.row-unit').text(), remarks: $row.find('.row-remarks').val(), batch_no: $row.find('.row-batch').val(),
                [config.rateField]: $row.find('.row-rate').val(), qty: $row.find('.row-qty').val(), tax_rate: $row.find('.row-tax-rate').val()
            };
        });
        const ui = { action: form.getAttribute('action') || '', title: $('#entry-title-text').text(), status: $('#status-pill').text() };
        return { schema_version: 1, document_kind: config.draftKind, form: { fields, lines, ui, context: {} } };
    }
    function restore(payload) {
        const state = payload.form || {};
        reset();
        ['sales_order_id', 'purchase_order_id'].forEach(name => {
            if (state.fields?.[name]) $('<input type="hidden">').attr('name', name).val(state.fields[name]).appendTo(form);
        });
        fieldControls().forEach(element => {
            if (Object.prototype.hasOwnProperty.call(state.fields || {}, element.name)) element.value = state.fields[element.name] ?? '';
        });
        const ui = state.ui || {};
        if (ui.action) form.action = ui.action;
        if (ui.title) $('#entry-title-text').text(ui.title);
        if (ui.status) $('#status-pill').text(ui.status);
        (state.lines || []).forEach(line => addRow(line, false));
        refresh($(form).find('select')); totals();
    }
    function party() {
        const select = document.querySelector(config.partySelect);
        const option = select && select.selectedOptions[0];
        return select && select.value && option ? { party_id: Number(select.value), party_name: option.textContent.replace(/\s*\(.*\)\s*$/, '').trim() } : {};
    }
    const drafts = config.draftUrl && window.ZoloDocumentDrafts ? window.ZoloDocumentDrafts.attach({
        kind: config.draftKind, label: config.label, baseUrl: config.draftUrl, form, snapshot, restore, reset, party,
        focus: () => $('#lims_productcodeSearch').trigger('focus'),
        inDocument: target => !!target.closest && !!target.closest('#' + config.form) && target.id !== 'lims_productcodeSearch',
        rows: $body[0], handles: entry => entry.id === config.shortcut,
        localShortcuts: [{ key: 'Alt+I', label: 'Focus item search' }],
    }) : null;
    $('#btn-top-new').on('click', () => (drafts ? drafts.newDocument() : reset()));
    $('#btn-discard').on('click', () => (drafts ? drafts.discardActive() : reset()));

    // ?new=1 is a one-shot instruction from a document shortcut: start blank, then drop only that parameter.
    const query = new URLSearchParams(window.location.search);
    if (!drafts && query.get('new') === '1' && !config.editId) {
        reset(); $('#lims_productcodeSearch').trigger('focus');
        query.delete('new');
        window.history.replaceState(null, '', window.location.pathname + (query.toString() ? '?' + query : '') + window.location.hash);
    }
    if (config.editId) $('.bill-card[data-id="' + config.editId + '"] .btn-load-challan, .bill-card[data-id="' + config.editId + '"] .btn-load-grn').trigger('click');
})();
