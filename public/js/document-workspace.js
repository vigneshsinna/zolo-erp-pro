/*
 * Sales / Purchase page adapter for the shared draft-tab engine (document-drafts.js).
 *
 * Owns: whole-form snapshots of the command-center form (serialize / rebuild), the blank-bill start, the register /
 * voucher switch, copy-into-new-draft, and the legacy fast-entry draft conversion. Tabs, autosave, conflicts and the
 * "new document" flow live in the engine.
 *
 * Shortcut routing lives in document-shortcuts.js and only calls window.zoloDocumentWorkspace; it never touches form state.
 * The page keeps posting through its normal form contract (command-center-form.js -> legacy adapter ->
 * shared commercial services). This file never changes that contract. A draft is an unfinished workspace
 * state only: it never posts stock, accounting, payments, open items or a document number.
 */
(() => {
    'use strict';
    const configNode = document.getElementById('command-center-form-data');
    const $ = window.jQuery;
    if (!configNode || !$ || !window.ZoloDocumentDrafts) return;
    const config = JSON.parse(configNode.textContent);
    const form = document.getElementById(config.kind + '-entry-form');
    const grid = window.commandCenterGrid;
    if (!form || !grid) return;
    const purchase = config.kind === 'purchase';
    const shared = !!config.shared;
    const partySelector = purchase ? '#supplier_id' : '#customer_id';

    /* ---------------------------------------------------------------- snapshot */
    // Controls that never belong to a document: tokens, per-attempt keys and the draft pointer.
    const VOLATILE = new Set(['_token', 'idempotency_key', 'draft_id']);
    // Hidden inputs the pages append to the form when a document is started from a challan / goods receipt.
    const LINK_FIELDS = ['delivery_challan_id', 'goods_received_note_id', 'sales_order_id', 'purchase_order_id'];
    const containers = () => [form, document.getElementById('charges-drawer')].filter(Boolean);
    const keyOf = element => element.id || element.name;
    const skipped = element => !keyOf(element) || ['button', 'submit', 'file', 'image', 'reset'].includes(element.type)
        || VOLATILE.has(element.name) || LINK_FIELDS.includes(element.name)
        || element.closest('#order-table-body, .bs-searchbox, .dropdown-menu, #table-search-row')
        || element.id === 'lims_productcodeSearch';

    function captureFields() {
        const fields = {};
        for (const container of containers()) {
            container.querySelectorAll('input, select, textarea').forEach(element => {
                if (skipped(element)) return;
                const key = keyOf(element);
                if (element.type === 'checkbox' || element.type === 'radio') fields[key] = {checked: element.checked, value: element.value};
                else if (element.multiple) fields[key] = Array.from(element.selectedOptions).map(option => option.value);
                else fields[key] = element.value;
            });
        }
        return fields;
    }

    // A row with neither a product nor a typed item name is an unused blank row: never kept in a draft.
    const blankRow = row => !Number($(row).find('.row-product-id').val()) && !String($(row).find('.row-item-name').val() || '').trim();
    const blankLine = line => {
        const get = name => (line.controls.find(control => control.name === name) || {}).value;
        return !Number(get('product_id[]')) && !String(get('product_name_text[]') || '').trim();
    };

    function captureLines() {
        return $('#order-table-body tr.order-item-row').toArray().filter(row => !blankRow(row)).map(row => ({
            data: Object.fromEntries(Array.from(row.attributes).filter(a => a.name.startsWith('data-')).map(a => [a.name, a.value])),
            controls: Array.from(row.querySelectorAll('input, select')).map(element => ({name: element.name || '', value: element.value})),
        }));
    }

    function captureUi() {
        const mode = document.querySelector('.pill-segmented-compact button[data-mode].active');
        const nature = document.querySelector('.pill-segmented-compact button[data-nature].active');
        const method = document.getElementById('entry-form-method');
        const links = {};
        LINK_FIELDS.forEach(name => { const input = form.querySelector('[name="' + name + '"]'); if (input && input.value) links[name] = input.value; });
        return {
            payment_mode: mode ? mode.dataset.mode : '', nature: nature ? nature.dataset.nature : '',
            action: form.getAttribute('action') || '', editing: !!method && method.value === 'PUT', links,
            title: (document.getElementById('doc-title-text') || {}).textContent || '',
        };
    }

    function references() {
        const refs = {};
        for (const key of ['project_id', 'exchange_return_id', 'purchase_order_id', 'sales_order_id']) {
            if (config.context && config.context[key]) refs[key] = config.context[key];
        }
        return refs;
    }

    /** The complete, versioned state of the document on screen. References only; totals are recalculated, never trusted. */
    function snapshot() {
        return {
            schema_version: 1, document_kind: config.kind,
            form: {fields: captureFields(), lines: captureLines(), ui: captureUi(), context: references()},
        };
    }

    function setControl(element, stored) {
        if (!element) return;
        if (element.type === 'checkbox' || element.type === 'radio') { element.checked = !!(stored && stored.checked); return; }
        if (element.multiple && Array.isArray(stored)) {
            Array.from(element.options).forEach(option => { option.selected = stored.includes(option.value); });
            return;
        }
        const value = stored === null || stored === undefined ? '' : String(stored);
        if (element.tagName === 'SELECT' && value !== '' && !Array.from(element.options).some(option => option.value === value)) {
            element.appendChild(new Option(/^[\d.]+$/.test(value) ? value + '%' : value, value));
        }
        element.value = value;
    }

    function restoreLines(lines) {
        for (const line of lines.filter(line => !blankLine(line))) {
            const get = name => (line.controls.find(control => control.name === name) || {}).value;
            const rate = Number(get(purchase ? 'net_unit_cost[]' : 'net_unit_price[]')) || 0;
            grid.addProductRow({
                preserve_line: true, product_id: Number(get('product_id[]')) || 0, product_name: get('product_name_text[]') || '',
                product_code: get('product_code[]') || '', qty: Number(get('qty[]')) || 1, cost: rate, price: rate, rate,
                tax_rate: Number(get('tax_rate[]')) || 0, unit: get(purchase ? 'purchase_unit[]' : 'sale_unit[]'),
                discount: get('discount[]') || 0, batch_no: get('batch_no[]') || '', expired_date: get('expired_date[]') || '',
                imei_number: get('imei_number[]') || '', product_batch_id: line.data['data-batch-id'] || '',
            });
            const row = $('#order-table-body tr.order-item-row').last()[0];
            if (!row) continue;
            // A null attribute (no batch) must stay absent: setAttribute would store the string "null" and post it as an id.
            Object.entries(line.data).forEach(([name, value]) => value === null ? row.removeAttribute(name) : row.setAttribute(name, value));
            Array.from(row.querySelectorAll('input, select')).forEach((element, index) => {
                const stored = line.controls[index];
                if (stored && stored.name === (element.name || '')) setControl(element, stored.value);
            });
        }
    }

    /** Replace the entire form with a snapshot. Never merges into whatever was on screen before. */
    function restore(stored) {
        const state = stored.form || {};
        grid.resetFormToNew();
        config.context = {...(state.context || {})};
        grid.switchWorkspaceMode('voucher');
        const ui = state.ui || {};
        if (ui.action) form.setAttribute('action', ui.action);
        for (const container of containers()) {
            container.querySelectorAll('input, select, textarea').forEach(element => {
                if (skipped(element)) return;
                const key = keyOf(element);
                if (Object.prototype.hasOwnProperty.call(state.fields || {}, key)) setControl(element, state.fields[key]);
            });
        }
        Object.entries(ui.links || {}).forEach(([name, value]) => $('<input type="hidden">').attr('name', name).val(value).appendTo(form));
        if ($.fn.selectpicker) $('.selectpicker').selectpicker('refresh');
        // Header controls may drive per-row defaults, so rows are rebuilt last and keep their stored values.
        $(partySelector).trigger('change');
        $('#order-table-body tr.order-item-row').remove();
        restoreLines(state.lines || []);
        if (ui.payment_mode) $('.pill-segmented-compact button[data-mode="' + ui.payment_mode + '"]').trigger('click');
        if (ui.nature) $('.pill-segmented-compact button[data-nature="' + ui.nature + '"]').trigger('click');
        if (ui.title) $('#doc-title-text').text(ui.title);
        if (ui.editing) {
            $('#replacement-reason').closest('.form-group').prop('hidden', false).find('input').prop('required', true);
        }
        grid.recalcTableSummary();
    }

    const stable = state => JSON.stringify([state.form.fields, state.form.lines.map(line => line.controls.map(control => control.value))]);

    /** Drafts written by the retired fast-entry screen hold an engine payload; rebuild the same bill as a workspace snapshot. */
    function legacySnapshot(old) {
        const fields = {};
        Object.entries(old.command_center_fields || {}).forEach(([name, value]) => {
            if (value !== null && typeof value !== 'object' && !VOLATILE.has(name) && !['_method'].includes(name)) fields[name] = value;
        });
        const partyKey = purchase ? 'supplier_id' : 'customer_id';
        if (old[partyKey] && !fields[partyKey]) fields[partyKey] = String(old[partyKey]);
        const rateName = purchase ? 'net_unit_cost[]' : 'net_unit_price[]';
        const lines = (old.items || []).map(item => {
            const unit = (config.units || []).find(u => u.id === Number(item[purchase ? 'purchase_unit_id' : 'sale_unit_id']));
            const pairs = {'product_id[]': item.product_id, 'product_name_text[]': item.product_name || '', 'product_code[]': item.product_code || '', 'qty[]': item.qty,
                [rateName]: item[purchase ? 'net_unit_cost' : 'net_unit_price'], 'tax_rate[]': item.tax_rate || 0, 'discount[]': item.discount || 0,
                'batch_no[]': (item.batch || {}).batch_no || '', 'expired_date[]': (item.batch || {}).expired_date || '', 'imei_number[]': item.imei_number || '',
                [purchase ? 'purchase_unit[]' : 'sale_unit[]']: unit ? unit.unit_name : ''};
            return {data: item.product_batch_id ? {'data-batch-id': String(item.product_batch_id)} : {}, controls: Object.entries(pairs).map(([name, value]) => ({name, value}))};
        });
        return {schema_version: 1, document_kind: config.kind, form: {fields, lines, ui: {editing: !!fields[config.kind + '_id']}, context: {}}};
    }

    /* ---------------------------------------------------------------- new / start */
    function partyMeta() {
        const select = document.querySelector(partySelector);
        const option = select && select.selectedOptions && select.selectedOptions[0];
        if (!select || !select.value || !option) return {};
        return {party_id: Number(select.value), party_name: (option.textContent || '').replace(/\s*\(.*\)\s*$/, '').trim()};
    }

    function focusFirst() {
        grid.switchWorkspaceMode('voucher');
        const select = document.querySelector(partySelector);
        const toggle = select && select.parentElement ? select.parentElement.querySelector('button.dropdown-toggle') : null;
        (toggle || select || document.getElementById('reference_no'))?.focus();
    }

    function applyContext() {
        const context = config.context || {};
        const partyId = context.party_id;
        if (partyId) { $(partySelector).val(String(partyId)).trigger('change'); if ($.fn.selectpicker) $('.selectpicker').selectpicker('refresh'); }
        if (context.warehouse_id) $('#form_warehouse_id').val(String(context.warehouse_id));
    }

    // Purchase Orders reuse the normal purchase form with status 4 (no stock, no accounting); only offered when enabled.
    function applyDocumentMode(doc) {
        if (doc === 'order' && purchase && config.purchaseOrders) {
            $('#purchase-status-val').val(4);
            $('#doc-title-text').text('New Purchase Order');
            $('#doc-breadcrumb-mode').text('Order');
        }
    }

    function reset(options = {}) {
        grid.resetFormToNew();
        grid.switchWorkspaceMode('voucher');
        applyContext();
        applyDocumentMode(options.doc);
    }

    const inDocument = target => !!target.closest && !target.closest('#table-search-row') && !!(target.closest('#' + form.id) || target.closest('#charges-drawer'));

    const workspace = window.ZoloDocumentDrafts.attach({
        kind: config.kind, label: purchase ? 'Purchase' : 'Sales', baseUrl: config.baseUrl, enabled: shared, form,
        snapshot, restore, stable, reset, party: partyMeta, focus: focusFirst, inDocument, legacy: legacySnapshot,
        // No party and no item yet: only defaults on screen (payment pill, dates), so no auto-saved draft for it.
        empty: state => !state.form.fields[partySelector.slice(1)] && !state.form.lines.length
            && !['supplier_invoice_no', 'reference_no'].some(key => String(state.form.fields[key] || '').trim()),
        rows: document.getElementById('order-table-body'),
        handles: entry => entry.id === config.kind || (entry.id === 'purchase-order' && purchase && !!config.purchaseOrders),
        // Split view (blank bill + bill list) is the home screen: opening the page or closing a tab lands there,
        // never on the full-width register. The blank bill becomes a draft tab only once the user types into it.
        closed: () => { grid.resetFormToNew(); grid.switchWorkspaceMode('voucher'); },
        idle: () => grid.switchWorkspaceMode('voucher'),
        startOptions: query => ({doc: query.get('doc') === 'order' ? 'order' : undefined}),
        localShortcuts: [
            {key: 'Alt+I', label: 'Focus item search'}, {key: 'F6', label: 'Create a new item'}, {key: 'Ctrl+Shift+F', label: 'Hide / show the top bar'},
        ],
    });
    workspace.version = 2;
    workspace.config = config;
    workspace.snapshot = snapshot;
    workspace.restore = restore;
    const newDocument = workspace.newDocument;
    workspace.newDocument = options => newDocument({doc: options && options.shortcut === 'purchase-order' ? 'order' : undefined, ...(options || {})});
    workspace.openClone = payload => cloneInto(payload);

    /** Copy an existing document into a brand-new draft tab (party, warehouse and lines only). */
    async function cloneInto(copy) {
        if (!(await workspace.newDocument({}))) return false;
        workspace.mute(() => {
            const partyKey = purchase ? 'supplier_id' : 'customer_id';
            if (copy[partyKey]) { $(partySelector).val(String(copy[partyKey])).trigger('change'); if ($.fn.selectpicker) $('.selectpicker').selectpicker('refresh'); }
            if (copy.warehouse_id) $('#form_warehouse_id').val(String(copy.warehouse_id));
            (copy.items || []).forEach(item => {
                const unit = (config.units || []).find(u => u.id === Number(item[purchase ? 'purchase_unit_id' : 'sale_unit_id']));
                const rate = Number(item[purchase ? 'net_unit_cost' : 'net_unit_price']) || 0;
                grid.addProductRow({preserve_line: true, product_id: item.product_id, product_name: item.name, product_code: item.code,
                    qty: Number(item.qty) || 1, cost: rate, price: rate, rate, unit: unit ? unit.unit_name : undefined});
            });
            grid.recalcTableSummary();
        });
        workspace.touch();
        return true;
    }

    // A saved document opened for editing is a fresh, untabbed form until it is changed.
    $(document).on('command-center-loaded', () => workspace.detach());

    /* -------------------------------------------------------------- page wiring */
    // The normal "Save as" button becomes an explicit Save draft; nothing is posted.
    if (shared) {
        $('#btn-form-save-as').text('Save draft').off('click').on('click', async () => {
            const ok = await workspace.saveDraft();
            if (ok) workspace.status('Draft saved.');
        });
        // The page's own New / Discard buttons go through the draft tabs, so clearing the form never overwrites a draft.
        $('#btn-side-new, #btn-top-new, #btn-fullwidth-new').off('click').on('click', event => { event.preventDefault(); workspace.newDocument({}); });
        $('#btn-form-discard').off('click').on('click', event => { event.preventDefault(); workspace.discardActive(); });
        // Opening a saved bill must not silently replace unsaved work.
        for (const name of ['loadPurchaseToForm', 'loadSaleToForm']) {
            const original = window[name];
            if (typeof original !== 'function') continue;
            window[name] = async function (id) { if (await workspace.leaveActive()) { workspace.detachNow(); return original.call(this, id); } };
        }
    }
})();
