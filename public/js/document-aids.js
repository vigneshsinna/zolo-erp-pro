/*
 * Entry aids on the normal Sales / Purchase pages: previous rates, party outstanding and pending bills,
 * copy (clone) an earlier document, inline master shortcuts, F6 new item and extended stock tracking.
 *
 * Aids only assist entry. They read through the shared commercial endpoints and write into the same form fields the
 * page already posts; nothing here changes the posting contract or touches stock/accounting.
 */
(() => {
    'use strict';
    const workspace = window.zoloDocumentWorkspace;
    const $ = window.jQuery;
    if (!workspace || !$) return;
    const config = workspace.config;
    const aids = config.aids || {};
    const kind = config.kind;
    const purchase = kind === 'purchase';
    const partySelector = purchase ? '#supplier_id' : '#customer_id';
    const money = value => '₹ ' + Number(value || 0).toFixed(2);
    const read = (path, params) => $.ajax({url: config.baseUrl + path, data: params, dataType: 'json'});
    const text = (tag, value, className) => { const node = document.createElement(tag); node.textContent = value; if (className) node.className = className; return node; };

    /* ----------------------------------------------------------- previous rates */
    // Shown as a hover title on the rate box so rows stay one line tall; Rate history holds the full list.
    if (aids.previous_rates && config.shared) {
        const cache = new Map();
        const rateOf = line => Number(purchase ? line.net_unit_cost : line.net_unit_price) || 0;
        const draw = (row, response) => {
            const line = (response.data || [])[0];
            row.find('.row-rate').attr('title', line ? 'Last rate for this party: ' + money(rateOf(line)) + ' × ' + Number(line.qty) : null);
        };
        const show = row => {
            const productId = Number(row.find('.row-product-id').val());
            const party = $(partySelector).val();
            row.find('.row-rate').removeAttr('title');
            if (!productId || !party) return;
            const key = party + ':' + productId;
            if (cache.has(key)) { draw(row, cache.get(key)); return; }
            row.attr('data-rate-query', key);
            read('/previous-rates', {party_id: party, product_id: productId}).done(response => {
                if (row.attr('data-rate-query') !== key || String($(partySelector).val()) !== String(party)) return;
                cache.set(key, response);
                draw(row, cache.get(key));
            });
        };
        $(document).on('command-center-row-product command-center-row-added', (event, row) => show($(row)));
        $(partySelector).on('change', () => { $('#order-table-body tr.order-item-row').each(function () { show($(this)); }); });
    }

    /* ---------------------------------------------------------------- copy bill */
    if (aids.clone_invoice && config.shared) {
        $('.btn-side-copy').prop('hidden', false);
        $(document).on('click', '.btn-side-copy', function (event) {
            event.preventDefault(); event.stopPropagation();
            read('/clone/' + $(this).data('id')).done(response => { workspace.openClone(response.data); })
                .fail(xhr => workspace.status((xhr.responseJSON && xhr.responseJSON.message) || 'Unable to copy this document.', true));
        });
    }

    /* ----------------------------------------------------------- inline masters */
    if (!aids.inline_party) $('.btn-open-party-modal, #btn-quick-new-party').hide();
    if (!aids.inline_item) $('#btn-create-item-modal').hide();

    // F6 creates an item from inside the document; it never fires from other screens, so accounting F4-F9 stay untouched.
    document.addEventListener('keydown', event => {
        if (event.key !== 'F6' || event.ctrlKey || event.altKey || event.shiftKey || event.metaKey || event.defaultPrevented) return;
        if (!aids.inline_item || document.querySelector('dialog[open], .modal.show')) return;
        if (!$('#' + kind + '-entry-form').is(':visible')) return;
        event.preventDefault();
        $('#btn-create-item-modal').trigger('click');
    });

    /* ----------------------------------------------------------- stock tracking */
    const tracking = config.tracking || {};
    const trackingOf = row => { try { return JSON.parse(row.getAttribute('data-tracking') || '{}') || {}; } catch (error) { return {}; } };

    /** Engine line fields for the extended tracking a row carries. Called by command-center-form.js when posting or reviewing. */
    function lineExtras(row) {
        const t = trackingOf(row), out = {};
        const number = value => (value === '' || value === undefined || value === null ? null : Number(value));
        if (number(t.variant_id)) out.variant_id = number(t.variant_id);
        if (number(t.stock_identity_id)) out.stock_identity_id = number(t.stock_identity_id);
        if (number(t.quantity_scheme_id)) out.quantity_scheme_id = number(t.quantity_scheme_id);
        if (purchase) {
            if (t.hsn_code) out.hsn_code = String(t.hsn_code);
            if (number(t.weight) !== null) out.weight = number(t.weight);
            if (number(t.landed_cost) !== null) out.landed_cost = number(t.landed_cost);
            const batch = {};
            if (t.mfg_date) batch.mfg_date = t.mfg_date;
            if (number(t.mrp) !== null) batch.mrp = number(t.mrp);
            if (Object.keys(batch).length) out.batch = batch;
            const d = t.dimensions || {};
            if (tracking.dimensions && number(d.length) && number(d.width) && number(d.thickness)) {
                out.dimensions = {length: number(d.length), width: number(d.width), thickness: number(d.thickness), pieces: number(d.pieces) || 1,
                    dimension_uom: d.dimension_uom || tracking.dimension_unit || 'mm', grade: d.grade || undefined, identity_no: d.identity_no || undefined};
            }
        }
        if (t.sph !== undefined && t.sph !== null && t.sph !== '') out.sph = String(t.sph);
        if (t.cyl !== undefined && t.cyl !== null && t.cyl !== '') out.cyl = String(t.cyl);
        if (t.axis !== undefined && t.axis !== null && t.axis !== '') out.axis = String(t.axis);
        if (t.base !== undefined && t.base !== null && t.base !== '') out.base = String(t.base);
        if (t.addition !== undefined && t.addition !== null && t.addition !== '') out.addition = String(t.addition);
        if (t.lr !== undefined && t.lr !== null && t.lr !== '') out.lr = String(t.lr);
        if (t.dia !== undefined && t.dia !== null && t.dia !== '') out.dia = String(t.dia);
        return out;
    }
    window.zoloDocumentAids = {lineExtras};

    if (aids.tracking) {
        const body = $('#row-detail-modal .modal-body');
        const group = (id, labelText, control) => {
            const wrap = $('<div class="form-group mb-2">');
            wrap.append($('<label style="font-size:11px;font-weight:700;color:#475569;margin-bottom:2px;">').attr('for', id).text(labelText), control);
            return wrap;
        };
        const input = (id, type, extra = {}) => $('<input class="form-control form-control-sm" style="height:32px;font-size:12px;">').attr({id, type, ...extra});
        const extra = $('<div id="tracking-extra">');
        const fields = [];
        const add = (key, labelText, control) => { fields.push([key, control]); extra.append(group(control.attr('id'), labelText, control)); };
        extra.append($('<div style="font-size:11px;font-weight:700;color:#6d28d9;margin:10px 0 4px;">').text('Stock tracking'));
        // Sales and issues choose a lot from what the godown holds; purchases name a new lot in the line detail instead.
        const lots = !purchase && config.lotsUrl ? $('<select id="trk-batch-id" class="form-control form-control-sm" style="height:32px;font-size:12px;">').append(new Option('No lot', '')) : null;
        add('product_batch_id', lots ? 'Lot / batch' : 'Existing batch ID', lots || input('trk-batch-id', 'number', {min: 1}));
        add('variant_id', 'Variant ID', input('trk-variant-id', 'number', {min: 1}));
        if (purchase) {
            if (tracking.profile === 'fmcg') {
                add('mfg_date', 'Manufacturing date (new batch)', input('trk-mfg-date', 'date'));
                add('mrp', 'Batch MRP', input('trk-mrp', 'number', {min: 0, step: '0.0001'}));
            }
            add('hsn_code', 'Item HSN', input('trk-hsn', 'text', {maxlength: 8, inputmode: 'numeric'}));
            add('weight', 'Line weight', input('trk-weight', 'number', {min: 0, step: '0.0001'}));
            add('landed_cost', 'Manual freight allocation', input('trk-freight', 'number', {min: 0, step: '0.0001'}));
        }
        if (tracking.dimensions) {
            if (purchase) {
                const unit = $('<select id="trk-dim-uom" class="form-control form-control-sm" style="height:32px;font-size:12px;">');
                ['mm', 'cm', 'm', 'in', 'ft'].forEach(value => unit.append(new Option(value, value, false, value === (tracking.dimension_unit || 'mm'))));
                extra.append($('<div style="font-size:11px;font-weight:700;color:#6d28d9;margin:10px 0 4px;">').text('New dimensioned piece'));
                add('dim_uom', 'Dimension unit', unit);
                add('dim_pieces', 'Number of pieces', input('trk-dim-pieces', 'number', {min: 1, step: 1, value: 1}));
                add('dim_grade', 'Grade', input('trk-dim-grade', 'text', {maxlength: 50}));
                add('dim_identity', 'Piece number', input('trk-dim-identity', 'text', {maxlength: 100}));
                add('dim_length', 'Length', input('trk-dim-length', 'number', {min: 0.000001, step: 'any'}));
                add('dim_width', 'Width', input('trk-dim-width', 'number', {min: 0.000001, step: 'any'}));
                add('dim_thickness', 'Thickness', input('trk-dim-thickness', 'number', {min: 0.000001, step: 'any'}));
            } else if (config.piecesUrl) {
                const select = $('<select id="trk-piece" class="form-control form-control-sm" style="height:32px;font-size:12px;">').append(new Option('Find pieces first', ''));
                const filter = input('trk-piece-filter', 'text', {placeholder: 'Grade or species'});
                const find = $('<button type="button" class="btn btn-sm btn-outline-secondary mb-2">Find available pieces</button>');
                extra.append($('<div style="font-size:11px;font-weight:700;color:#6d28d9;margin:10px 0 4px;">').text('Dimensioned stock'));
                extra.append(group('trk-piece-filter', 'Find piece (species, grade or dimensions)', filter), find);
                add('stock_identity_id', 'Available piece', select);
                find.on('click', () => {
                    const row = $('#order-table-body tr.order-item-row[data-row-id="' + $('#modal-target-row-id').val() + '"]');
                    $.getJSON(config.piecesUrl, {product_id: row.find('.row-product-id').val(), warehouse_id: $('#form_warehouse_id').val(), q: filter.val()}).done(result => {
                        select.empty().append(new Option((result.data || []).length ? 'Choose a piece' : 'No pieces found', ''));
                        (result.data || []).forEach(piece => select.append(new Option(
                            [piece.identity_no, piece.grade, piece.species, piece.length && (piece.length + '×' + piece.width + '×' + piece.thickness)].filter(Boolean).join(' · ') || ('#' + piece.id), piece.id)));
                    });
                });
            }
        }
        if (!purchase && (tracking.schemes || []).length) {
            const select = $('<select id="trk-scheme" class="form-control form-control-sm" style="height:32px;font-size:12px;">').append(new Option('No scheme', ''));
            tracking.schemes.forEach(scheme => select.append($('<option>').val(scheme.id).attr('data-product', scheme.product_id || '').text(scheme.name + ' · ' + scheme.buy_qty + '+' + scheme.free_qty)));
            add('quantity_scheme_id', 'Quantity scheme', select);
        }
        if (tracking.profile === 'opticals' || !purchase) {
            extra.append($('<div style="font-size:11px;font-weight:700;color:#0284c7;margin:10px 0 4px;">').text('Optical Rx Prescription'));
            const eyeSelect = $('<select id="trk-lr" class="form-control form-control-sm" style="height:32px;font-size:12px;">')
                .append(new Option('Both / Pair', 'Pair'), new Option('Right (R)', 'R'), new Option('Left (L)', 'L'));
            add('lr', 'Eye', eyeSelect);
            add('sph', 'SPH (Sphere Power)', input('trk-sph', 'text', {placeholder: '-2.50 / +1.00'}));
            add('cyl', 'CYL (Cylinder Power)', input('trk-cyl', 'text', {placeholder: '-0.75 / +0.50'}));
            add('axis', 'AXIS (Degrees)', input('trk-axis', 'number', {min: 0, max: 180, placeholder: '90 / 180'}));
            add('addition', 'ADD (Reading Addition)', input('trk-addition', 'text', {placeholder: '+2.00'}));
            add('dia', 'Diameter (mm)', input('trk-dia', 'number', {placeholder: '65 / 70'}));
            add('base', 'Base Curve', input('trk-base', 'text', {placeholder: '4.00 / 6.00'}));
        }
        body.append(extra);

        const slots = {
            product_batch_id: t => t.product_batch_id, variant_id: t => t.variant_id, mfg_date: t => t.mfg_date, mrp: t => t.mrp, hsn_code: t => t.hsn_code,
            weight: t => t.weight, landed_cost: t => t.landed_cost, quantity_scheme_id: t => t.quantity_scheme_id, stock_identity_id: t => t.stock_identity_id,
            dim_uom: t => (t.dimensions || {}).dimension_uom || tracking.dimension_unit || 'mm', dim_pieces: t => (t.dimensions || {}).pieces || 1,
            dim_grade: t => (t.dimensions || {}).grade, dim_identity: t => (t.dimensions || {}).identity_no, dim_length: t => (t.dimensions || {}).length,
            dim_width: t => (t.dimensions || {}).width, dim_thickness: t => (t.dimensions || {}).thickness,
            lr: t => t.lr, sph: t => t.sph, cyl: t => t.cyl, axis: t => t.axis, addition: t => t.addition, dia: t => t.dia, base: t => t.base,
        };
        $('#row-detail-modal').on('show.bs.modal', () => {
            const row = $('#order-table-body tr.order-item-row[data-row-id="' + $('#modal-target-row-id').val() + '"]');
            if (!row.length) return;
            const t = trackingOf(row[0]);
            if (!t.product_batch_id && row.attr('data-batch-id')) t.product_batch_id = row.attr('data-batch-id');
            fields.forEach(([key, control]) => {
                const value = slots[key](t);
                control.val(value === undefined || value === null ? '' : value);
                if (control === lots) {
                    const current = value === undefined || value === null ? '' : String(value);
                    lots.empty().append(new Option('Loading lots…', current)).val(current);
                    $.getJSON(config.lotsUrl, {product_id: row.find('.row-product-id').val(), warehouse_id: $('#form_warehouse_id').val()}).done(result => {
                        // FMCG issues the earliest-expiring stock when no lot is chosen (server-side FEFO).
                        lots.empty().append(new Option('Combined stock · auto pick available lots', ''));
                        (result.data || []).forEach(lot => lots.append(new Option(lot.batch_no + ' · ' + Number(lot.qty).toLocaleString() + (lot.expired_date ? ' · exp ' + String(lot.expired_date).slice(0, 10) : ''), lot.id)));
                        lots.val(current);
                    }).fail(() => lots.empty().append(new Option('Lots unavailable', current)).val(current));
                }
                if (key === 'quantity_scheme_id') control.find('option').each(function () {
                    const product = $(this).data('product'); $(this).prop('hidden', !!product && Number(product) !== Number(row.find('.row-product-id').val()));
                });
            });
        });
        $('#btn-save-row-detail').on('click', () => {
            const row = $('#order-table-body tr.order-item-row[data-row-id="' + $('#modal-target-row-id').val() + '"]');
            if (!row.length) return;
            const value = key => { const control = fields.find(([name]) => name === key); return control ? control[1].val() : ''; };
            const saved = {};
            ['product_batch_id', 'variant_id', 'mfg_date', 'mrp', 'hsn_code', 'weight', 'landed_cost', 'quantity_scheme_id', 'stock_identity_id', 'lr', 'sph', 'cyl', 'axis', 'addition', 'dia', 'base'].forEach(key => {
                if (fields.some(([name]) => name === key) && value(key) !== '') saved[key] = value(key);
            });
            if (fields.some(([name]) => name === 'dim_length') && value('dim_length') !== '') {
                saved.dimensions = {dimension_uom: value('dim_uom'), pieces: value('dim_pieces'), grade: value('dim_grade'), identity_no: value('dim_identity'),
                    length: value('dim_length'), width: value('dim_width'), thickness: value('dim_thickness')};
            }
            if (Object.keys(saved).length) row.attr('data-tracking', JSON.stringify(saved)); else row.removeAttr('data-tracking');
            row.attr('data-batch-id', saved.product_batch_id || '');
            workspace.touch();
        });
    }
})();
