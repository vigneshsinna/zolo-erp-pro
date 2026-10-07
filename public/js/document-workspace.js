/*
 * Document workspace lifecycle for the normal Sales and Purchase pages.
 *
 * Owns: whole-form snapshots (serialize / rebuild), dirty detection, "new document" with the
 * Save draft / Discard / Cancel guard, and the one-shot ?new=1 start. Shortcut routing lives in
 * document-shortcuts.js and only calls window.zoloDocumentWorkspace; it never touches form state.
 *
 * The page keeps posting through its normal form contract (command-center-form.js -> legacy adapter ->
 * shared commercial services). This file never changes that contract.
 */
(() => {
    'use strict';
    const configNode = document.getElementById('command-center-form-data');
    const $ = window.jQuery;
    if (!configNode || !$) return;
    const config = JSON.parse(configNode.textContent);
    const form = document.getElementById(config.kind + '-entry-form');
    const grid = window.commandCenterGrid;
    if (!form || !grid) return;
    const purchase = config.kind === 'purchase';
    const partySelector = purchase ? '#supplier_id' : '#customer_id';
    const workspace = {kind: config.kind, version: 1};

    /* ---------------------------------------------------------------- snapshot */
    // Controls that never belong to a document: tokens, per-attempt keys and the draft pointer.
    const VOLATILE = new Set(['_token', 'idempotency_key', 'draft_id']);
    const containers = () => [form, document.getElementById('charges-drawer')].filter(Boolean);
    const keyOf = element => element.id || element.name;
    const skipped = element => !keyOf(element) || ['button', 'submit', 'file', 'image', 'reset'].includes(element.type)
        || VOLATILE.has(element.name) || element.closest('#order-table-body, .bs-searchbox, .dropdown-menu, #table-search-row')
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

    function captureLines() {
        return $('#order-table-body tr.order-item-row').toArray().map(row => ({
            data: Object.fromEntries(Array.from(row.attributes).filter(a => a.name.startsWith('data-')).map(a => [a.name, a.value])),
            controls: Array.from(row.querySelectorAll('input, select')).map(element => ({name: element.name || '', value: element.value})),
        }));
    }

    function captureUi() {
        const mode = document.querySelector('.pill-segmented-compact button[data-mode].active');
        const nature = document.querySelector('.pill-segmented-compact button[data-nature].active');
        return {
            payment_mode: mode ? mode.dataset.mode : '', nature: nature ? nature.dataset.nature : '',
            action: form.getAttribute('action') || '',
            editing: !!(document.getElementById('entry-form-method') || {}).value && document.getElementById('entry-form-method').value === 'PUT',
        };
    }

    /** The complete, versioned state of the document on screen. References only; totals are recalculated, never trusted. */
    function snapshot() {
        return {
            schema_version: 1, document_kind: config.kind,
            form: {fields: captureFields(), lines: captureLines(), ui: captureUi(), context: references()},
        };
    }

    function references() {
        const refs = {};
        for (const key of ['project_id', 'exchange_return_id', 'purchase_order_id']) {
            if (config.context && config.context[key]) refs[key] = config.context[key];
        }
        return refs;
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
        for (const line of lines) {
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
            Object.entries(line.data).forEach(([name, value]) => row.setAttribute(name, value));
            const controls = Array.from(row.querySelectorAll('input, select'));
            controls.forEach((element, index) => {
                const stored = line.controls[index];
                if (stored && stored.name === (element.name || '')) setControl(element, stored.value);
            });
        }
    }

    /** Replace the entire form with a snapshot. Never merges into whatever was on screen before. */
    function restore(stored) {
        const state = stored.form || {};
        grid.resetFormToNew();
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
        if ($.fn.selectpicker) $('.selectpicker').selectpicker('refresh');
        // Header controls may drive per-row defaults, so rows are rebuilt last and keep their stored values.
        $(partySelector).trigger('change');
        $('#order-table-body tr.order-item-row').remove();
        restoreLines(state.lines || []);
        if (ui.payment_mode) $('.pill-segmented-compact button[data-mode="' + ui.payment_mode + '"]').trigger('click');
        if (ui.nature) $('.pill-segmented-compact button[data-nature="' + ui.nature + '"]').trigger('click');
        if (ui.editing) {
            $('#replacement-reason').closest('.form-group').prop('hidden', false).find('input').prop('required', true);
            $('#doc-title-text').text('Edit ' + config.kind + ' (restored)');
        }
        grid.recalcTableSummary();
        markClean();
    }

    /* ------------------------------------------------------------------- dirty */
    const stable = state => JSON.stringify([state.form.fields, state.form.lines.map(line => line.controls.map(control => control.value))]);
    let baseline = '';
    function markClean() { baseline = stable(snapshot()); }
    function isDirty() { return stable(snapshot()) !== baseline; }

    /* ---------------------------------------------------------------- new / start */
    function ask() {
        return new Promise(resolve => {
            const dialog = document.createElement('dialog');
            dialog.setAttribute('data-owns-keys', '');
            dialog.setAttribute('aria-label', 'Unsaved bill');
            dialog.innerHTML = '<h3>Unsaved ' + config.kind + ' bill</h3><p>This bill has changes that are not posted. What should happen to them?</p>';
            const done = value => { dialog.remove(); resolve(value); };
            const add = (label, value, cls) => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-sm ' + cls; button.textContent = label;
                button.addEventListener('click', () => done(value));
                dialog.appendChild(button);
                return button;
            };
            if (workspace.canSaveDraft && workspace.canSaveDraft()) add('Save draft', 'save', 'btn-primary');
            add('Discard', 'discard', 'btn-outline-danger');
            const cancel = add('Cancel', 'cancel', 'btn-outline-secondary');
            dialog.addEventListener('cancel', event => { event.preventDefault(); done('cancel'); });
            document.body.appendChild(dialog);
            if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
            cancel.focus();
        });
    }

    function focusFirst() {
        const select = document.querySelector(partySelector);
        const toggle = select && select.parentElement ? select.parentElement.querySelector('button.dropdown-toggle') : null;
        (toggle || select || document.getElementById('reference_no'))?.focus();
    }

    function applyContext() {
        const context = config.context || {};
        const partyId = context.party_id || context.project_customer;
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

    function start(options = {}) {
        grid.resetFormToNew();
        grid.switchWorkspaceMode('voucher');
        applyContext();
        applyDocumentMode(options.doc);
        markClean();
        focusFirst();
    }

    async function newDocument(options = {}) {
        if (isDirty()) {
            const choice = await ask();
            if (choice === 'cancel') return false;
            if (choice === 'save' && !(await workspace.saveDraft())) return false;
        }
        start(options);
        return true;
    }

    workspace.handles = entry => entry.id === config.kind || (entry.id === 'purchase-order' && purchase && !!config.purchaseOrders);
    workspace.newDocument = options => newDocument({doc: options && options.shortcut === 'purchase-order' ? 'order' : undefined, ...options});
    workspace.isDirty = isDirty;
    workspace.snapshot = snapshot;
    workspace.restore = restore;
    workspace.markClean = markClean;
    workspace.start = start;
    workspace.ask = ask;
    workspace.config = config;
    workspace.saveDraft = async () => false;
    workspace.localShortcuts = [
        {key: 'Alt+I', label: 'Focus item search'}, {key: 'Ctrl+Shift+F', label: 'Hide / show the top bar'},
    ];
    window.zoloDocumentWorkspace = workspace;

    $(document).on('command-center-loaded', () => setTimeout(markClean, 0));

    /* --------------------------------------------------------------- ?new=1 start */
    $(() => {
        const query = new URLSearchParams(window.location.search);
        if (query.get('new') === '1') {
            start({doc: query.get('doc') === 'order' ? 'order' : undefined});
            query.delete('new');
            window.history.replaceState(null, '', window.location.pathname + (query.toString() ? '?' + query : '') + window.location.hash);
        } else {
            markClean();
        }
        workspace.ready = true;
        document.dispatchEvent(new CustomEvent('zolo-workspace-ready'));
    });
})();
