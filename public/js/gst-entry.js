/* GST mode for the Sales and Purchase entry pages.
 * The server decides every line's GST (rate from the product unless the user changed it, CGST+SGST or IGST from the states). This script asks the
 * shared preview endpoint for those figures, shows them on the rows and in the footer, and holds Save until they are fresh. */
(function ($) {
    'use strict';
    var config = window.ZoloGstEntry;
    if (!config || !config.enabled) return;

    var purchase = config.kind === 'purchase';
    var $form = $(purchase ? '#purchase-entry-form' : '#sale-entry-form');
    if (!$form.length) return;
    var decimals = Number(config.decimals || 2);
    var timer = null, inflight = null, sequence = 0;

    function money(value) { return '₹ ' + Number(value || 0).toFixed(decimals); }

    // 1. The header "Tax Classification" no longer sets rates under GST; place of supply and reverse charge replace it.
    var $classification = $(purchase ? '#purchase_type_id' : '#sale_type_id');
    $classification.val('0').trigger('change');
    var $slot = $classification.closest('.field-compact-item');
    $slot.hide();
    var stateOptions = Object.keys(config.states).map(function (code) {
        return '<option value="' + code + '">' + code + ' · ' + $('<span>').text(config.states[code]).html() + '</option>';
    }).join('');
    var $gstField = $(
        '<div class="field-compact-item" id="gst-entry-field" style="width:250px;flex-shrink:0;">' +
            '<label for="gst-place-of-supply" data-help="State where the supply takes place. Same state as your company' +
                ' = CGST+SGST, another state = IGST. Auto works it out from the GSTINs.|33 · Tamil Nadu">GST · Place of supply <span id="gst-mode-badge" class="badge badge-light" style="font-size:10px;"></span></label>' +
            '<div class="d-flex align-items-center" style="gap:6px;">' +
                '<select id="gst-place-of-supply" name="gst[place_of_supply]" class="form-control">' +
                    '<option value="">Auto (' + (purchase ? 'your state' : 'party state') + ')</option>' + stateOptions +
                '</select>' +
                (purchase ? '<label class="mb-0 d-flex align-items-center" style="gap:3px;font-size:11px;white-space:nowrap;" data-help="Reverse charge: you pay the GST to the government instead of the supplier, and claim it back as input credit.|Goods transport (GTA) freight, advocate fees">' +
                    '<input type="checkbox" id="gst-reverse-charge" name="gst[reverse_charge]" value="1"> RCM</label>' : '') +
            '</div>' +
        '</div>');
    $slot.after($gstField);

    // 2. Row rates start at the product's GST rate; the user may pick another of the company's slabs (line-tax-override.js warns).
    $form.addClass('gst-mode');
    $('<style>' +
        '#gst-breakdown{font-size:10.5px;color:#64748b;line-height:1.3;}#gst-preview-status{font-size:11px;}</style>').appendTo('head');
    var $taxDisplay = $('#display-tax-amount');
    $taxDisplay.parent().append('<div id="gst-breakdown"></div>');
    var $status = $('<div id="gst-preview-status" class="text-danger" style="display:none;"></div>');
    $taxDisplay.closest('div').parent().append($status);

    function rows() { return $('#order-table-body tr.order-item-row'); }

    function payload() {
        var items = [], map = [];
        rows().each(function (index) {
            var $row = $(this);
            var productId = parseInt($row.find('.row-product-id').val(), 10);
            var qty = parseFloat($row.find('.row-qty').val());
            var rate = parseFloat($row.find('.row-rate').val());
            if (!(productId > 0) || !(qty > 0) || isNaN(rate)) return;
            var line = { product_id: productId, qty: qty };
            line[purchase ? 'net_unit_cost' : 'net_unit_price'] = rate;
            var discount = parseFloat($row.find('.row-discount-val').val());
            if (discount > 0) line.discount = discount;
            var override = window.zoloLineTax ? window.zoloLineTax.override(this) : undefined;
            if (override !== undefined) line.tax_rate_override = override;
            items.push(line);
            map.push(index);
        });
        var party = $(purchase ? '#supplier_id' : '#customer_id').val();
        var data = { items: items, business_date: $form.find('[name="created_at"]').val() || undefined,
            gst: { place_of_supply: $('#gst-place-of-supply').val() || undefined, reverse_charge: $('#gst-reverse-charge').is(':checked') ? 1 : 0 } };
        data[purchase ? 'supplier_id' : 'customer_id'] = party || undefined;
        var discount = parseFloat($form.find('[name="order_discount"]').val());
        if (discount > 0) data.order_discount = discount;
        var shipping = parseFloat($form.find('[name="shipping_cost"]').val());
        if (shipping > 0) data.shipping_cost = shipping;
        return { data: data, map: map, party: party };
    }

    function showStatus(message) {
        if (message) $status.text(message).show(); else $status.text('').hide();
    }

    function apply(result, map) {
        var cgst = 0, sgst = 0, igst = 0, cess = 0, place = null;
        var $rows = rows();
        (result.items || []).forEach(function (item, i) {
            var $row = $rows.eq(map[i]);
            if (!$row.length) return;
            var rate = Number(item.tax_rate || 0);
            var $rate = $row.find('.row-tax-rate');
            if (!$rate.find('option').filter(function () { return Number(this.value) === rate; }).length) {
                $rate.append('<option value="' + rate + '">' + rate + '%</option>');
            }
            $rate.find('option').filter(function () { return Number(this.value) === rate; }).first().prop('selected', true);
            $row.find('.row-tax-amount-input').val(Number(item.tax || 0).toFixed(4));
            $row.find('.row-subtotal-input').val(Number(item.total || 0).toFixed(4));
            $row.find('.row-total').val(Number(item.total || 0).toFixed(decimals));
            var snapshot = item._tax_snapshot;
            if (snapshot && snapshot.product_rate !== undefined && window.zoloLineTax) window.zoloLineTax.productRate($row, snapshot.product_rate);
            if (snapshot) {
                cgst += Number(snapshot.cgst || 0); sgst += Number(snapshot.sgst || 0);
                igst += Number(snapshot.igst || 0); cess += Number(snapshot.cess || 0);
                place = snapshot.place_of_supply;
            }
        });
        var tax = Number(result.total_tax || 0), grand = Number(result.grand_total || 0);
        $taxDisplay.text(money(tax));
        $('#display-grand-total, #header-grand-total-display').text(money(grand));
        $('#hidden-total-tax').val(tax.toFixed(4));
        $('#hidden-grand-total').val(grand.toFixed(4));
        var parts = [];
        if (cgst || sgst) parts.push('CGST ' + cgst.toFixed(decimals), 'SGST ' + sgst.toFixed(decimals));
        if (igst) parts.push('IGST ' + igst.toFixed(decimals));
        if (cess) parts.push('Cess ' + cess.toFixed(decimals));
        var roundOff = Number(result.round_off || 0);
        if (roundOff) parts.push('Round off ' + (roundOff > 0 ? '+' : '') + roundOff.toFixed(2));
        $('#gst-breakdown').text(parts.join(' · '));
        $('#gst-mode-badge').text(place ? (place === config.company_state ? 'CGST+SGST' : 'IGST') : '');
        if ($('#gst-reverse-charge').is(':checked') && tax === 0 && (cgst || sgst || igst)) {
            $('#gst-breakdown').append(' (reverse charge: you pay this)');
        }
    }

    function refresh() {
        clearTimeout(timer); timer = null;
        var request = payload();
        var mine = ++sequence;
        if (!request.data.items.length) {
            showStatus(''); $('#gst-breakdown').text(''); return $.Deferred().resolve().promise();
        }
        if (!request.party) {
            showStatus('Select a ' + (purchase ? 'supplier' : 'customer') + ' to calculate GST.');
            return $.Deferred().resolve().promise();
        }
        inflight = $.ajax({ url: config.preview_url, method: 'POST', contentType: 'application/json', data: JSON.stringify(request.data),
            headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json' } })
            .done(function (res) {
                if (mine !== sequence) return;
                showStatus(''); apply(res.data || {}, request.map);
            })
            .fail(function (xhr) {
                if (mine !== sequence) return;
                var body = xhr.responseJSON || {};
                var errors = body.errors ? Object.values(body.errors).flat() : [];
                showStatus(errors[0] || body.message || 'GST could not be calculated.');
            })
            .always(function () { if (mine === sequence) inflight = null; });
        return inflight;
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 350);
    }

    $(document).on('input change', '#order-table-body input, #order-table-body select', schedule);
    $(document).on('change', (purchase ? '#supplier_id' : '#customer_id') + ', #gst-place-of-supply, #gst-reverse-charge, [name="created_at"]', schedule);
    var body = document.getElementById('order-table-body');
    if (body && window.MutationObserver) new MutationObserver(schedule).observe(body, { childList: true });

    // Save itself re-previews on the server (command-center-form.js) and posts those figures, so no submit hook is needed here.
    schedule();
    window.ZoloGstEntry.refresh = refresh;
})(jQuery);
