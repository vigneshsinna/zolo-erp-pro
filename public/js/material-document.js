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
    const refresh = select => { if ($.fn.selectpicker) $(select).selectpicker('refresh'); };

    $('#multi-item-modal, #quick-create-item-modal, #material-party-modal').appendTo('body');

    function totals() {
        let qty = 0, amount = 0, tax = 0;
        $body.find('.item-row').each(function (index) {
            const $row = $(this);
            const quantity = Number($row.find('.row-qty').val() || 0);
            const net = Number($row.find('.row-rate').val() || 0) * quantity;
            const gst = net * Number($row.find('.row-tax-rate').val() || 0) / 100;
            $row.find('.row-num').text(index + 1);
            $row.find('.row-amount').val(net.toFixed(4));
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
        $row.find('.row-product-id').val(product.id);
        $row.find('.row-unit-id').val(product.unit_id);
        $row.find('.row-item-name').val(product.name);
        $row.find('.row-product-code').text(product.code);
        $row.find('.row-unit').text(product.unit);
        $row.find('.row-rate').val(product[config.rateField] ?? product[config.purchase ? 'cost' : 'price'] ?? 0);
        $row.find('.row-tax-rate').val(product.tax_rate ?? 0);
        totals();
    }

    function search($input, select) {
        $input.autocomplete({
            minLength: 1, autoFocus: true,
            source: (request, response) => {
                const term = request.term.toLowerCase().trim();
                response(products.filter(p => (p.name + ' ' + p.code).toLowerCase().includes(term)).slice(0, 20));
            },
            select: (event, ui) => { event.preventDefault(); select(ui.item); }
        }).autocomplete('instance')._renderItem = (ul, item) => $('<li>').append($('<div>').text(item.code + ' — ' + item.name)).appendTo(ul);
    }

    function addRow(item = {}, merge = true) {
        if (merge && item.product_id) {
            const $existing = $body.find('.item-row').filter(function () {
                return String($(this).find('.row-product-id').val()) === String(item.product_id);
            }).first();
            if ($existing.length) {
                $existing.find('.row-qty').val(Number($existing.find('.row-qty').val()) + Number(item.qty ?? 1));
                totals(); return;
            }
        }
        const product = products.find(p => String(p.id) === String(item.product_id));
        const rate = item[config.rateField] ?? product?.[config.purchase ? 'cost' : 'price'] ?? 0;
        const $row = $(`<tr class="item-row">
            <td><span class="row-num"></span><input type="hidden" name="product_id[]" class="row-product-id">
                <input type="hidden" name="unit_id[]" class="row-unit-id">
                <input type="hidden" name="tax_amount[]" class="row-tax-amount"><input type="hidden" name="total[]" class="row-total"></td>
            <td><input class="form-control form-control-sm row-item-name" placeholder="Search item or scan..." autocomplete="off" required>
                <small class="row-product-code"></small><input type="hidden" name="item_remarks[]" class="row-remarks"></td>
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
        $row.find('.row-rate').val(rate);
        $row.find('.row-qty').val(item.qty ?? 1);
        $row.find('.row-tax-rate').val(item.tax_rate ?? product?.tax_rate ?? 0);
        search($row.find('.row-item-name'), p => { applyProduct($row, p); $row.find('.row-qty').focus().select(); });
        $row.find('.row-item-name').on('input', function () { $row.find('.row-product-id, .row-unit-id').val(''); });
        $body.append($row); totals();
        return $row;
    }

    search($('#lims_productcodeSearch'), p => {
        addRow({product_id: p.id}); $('#lims_productcodeSearch').val('').focus();
    });
    $('#btn-add-row').on('click', () => addRow().find('.row-item-name').focus());
    $body.on('input', '.row-rate, .row-qty, .row-tax-rate', totals);
    $body.on('click', '.btn-remove-row', function () { $(this).closest('tr').remove(); totals(); });
    $(document).on('keydown', event => {
        // Alt+I focuses item search; F2/F12 belong to the global document shortcut registry.
        if (event.altKey && !event.ctrlKey && event.key.toLowerCase() === 'i') { event.preventDefault(); $('#lims_productcodeSearch').focus().select(); }
    });
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
        $('#multi-item-tbody .multi-item-cb:checked').each(function () { addRow({product_id: this.value}); });
        $('#multi-item-modal').modal('hide').find('input[type="checkbox"]').prop('checked', false);
    });
    $('#quick-create-item-form').on('submit', function (event) {
        event.preventDefault();
        const $button = $(this).find('[type="submit"]').prop('disabled', true);
        $.ajax({url: config.productUrl, method: 'POST', dataType: 'json', data: {
            _token: config.token, name: $('#quick-item-name').val(), code: $('#quick-item-code').val(),
            price: $('#quick-item-price').val(), cost: $('#quick-item-cost').val()
        }}).done(result => {
            products.unshift(result.product);
            addRow({product_id: result.product.id});
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
        $.ajax({url: config.partyUrl, method: 'POST', dataType: 'json', data: $(this).serialize()})
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
        $body.find('.item-row').remove(); $('.bill-card').removeClass('active-editing');
        $('#entry-title-text').text(config.title); $('#entry-breadcrumb').text(config.breadcrumb);
        $('#status-pill').text('New'); $(config.convertButton).hide();
        $('#btn-save, #btn-add-row, #btn-multi-item, #btn-create-item-modal').prop('disabled', false);
        $('#comm-drawer').prop('hidden', true); $('#material-document-error').hide();
        refresh($(form).find('select')); totals();
    }
    $('#btn-discard, #btn-top-new').on('click', reset);
    $(document).on('click', '.btn-load-challan, .btn-load-grn, .bill-card', function (event) {
        if ($(event.target).closest('a').length) return;
        event.stopPropagation();
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
            $('#entry-title-text').text('Edit ' + config.label + ': ' + doc[config.numberField]);
            $('#entry-breadcrumb').text(config.breadcrumb.replace('/ New', '/ Edit'));
            $('#status-pill').text(doc.status.replaceAll('_', ' '));
            const pending = doc.status === 'pending';
            $('#btn-save, #btn-add-row, #btn-multi-item, #btn-create-item-modal').prop('disabled', !pending);
            $(config.convertButton).toggle(pending).off('click').on('click', () => {
                window.location.href = config.convertUrl + '?' + config.convertParam + '=' + doc.id;
            });
            $('.bill-card').removeClass('active-editing').filter('[data-id="' + doc.id + '"]').addClass('active-editing');
            refresh($(form).find('select'));
        }).fail(xhr => { if (xhr.statusText !== 'abort') error(xhr); }).always(() => { loading = false; });
    });
    $(form).on('submit', function (event) {
        $('#material-document-error').hide();
        if (loading || !$body.find('.item-row').length || $body.find('.row-product-id').toArray().some(input => !input.value)) {
            event.preventDefault(); $('#material-document-error').text('Select a catalog product for every row. Use Create item for a new product.').show();
        } else { totals(); }
    });
    // ?new=1 is a one-shot instruction from a document shortcut: start blank, then drop only that parameter.
    const query = new URLSearchParams(window.location.search);
    if (query.get('new') === '1' && !config.editId) {
        reset(); $('#lims_productcodeSearch').trigger('focus');
        query.delete('new');
        window.history.replaceState(null, '', window.location.pathname + (query.toString() ? '?' + query : '') + window.location.hash);
    }
    if (config.editId) $('.bill-card[data-id="' + config.editId + '"] .btn-load-challan, .bill-card[data-id="' + config.editId + '"] .btn-load-grn').trigger('click');
})();
