/*
 * Document workspace lifecycle for the normal Sales and Purchase pages.
 *
 * Owns: whole-form snapshots (serialize / rebuild), dirty detection, server-side draft tabs
 * (create, autosave, switch, discard, conflict handling, the 10-tab limit), "new document" with the
 * Save draft / Discard / Cancel guard, and the one-shot ?new=1 start.
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
    if (!configNode || !$) return;
    const config = JSON.parse(configNode.textContent);
    const form = document.getElementById(config.kind + '-entry-form');
    const grid = window.commandCenterGrid;
    if (!form || !grid) return;
    const purchase = config.kind === 'purchase';
    const shared = !!config.shared;
    const partySelector = purchase ? '#supplier_id' : '#customer_id';
    const kindLabel = purchase ? 'Purchase' : 'Sales';
    const workspace = {kind: config.kind, version: 2, config};

    /* ---------------------------------------------------------------- snapshot */
    // Controls that never belong to a document: tokens, per-attempt keys and the draft pointer.
    const VOLATILE = new Set(['_token', 'idempotency_key', 'draft_id']);
    // Hidden inputs the pages append to the form when a document is started from a challan / goods receipt.
    const LINK_FIELDS = ['delivery_challan_id', 'goods_received_note_id'];
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

    function captureLines() {
        return $('#order-table-body tr.order-item-row').toArray().map(row => ({
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
        for (const key of ['project_id', 'exchange_return_id', 'purchase_order_id']) {
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
            Array.from(row.querySelectorAll('input, select')).forEach((element, index) => {
                const stored = line.controls[index];
                if (stored && stored.name === (element.name || '')) setControl(element, stored.value);
            });
        }
    }

    let muted = false;
    /** Replace the entire form with a snapshot. Never merges into whatever was on screen before. */
    function restore(stored) {
        const state = stored.form || {};
        muted = true;
        try {
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
        } finally {
            muted = false;
        }
    }

    const stable = state => JSON.stringify([state.form.fields, state.form.lines.map(line => line.controls.map(control => control.value))]);
    const hash = () => stable(snapshot());

    /* -------------------------------------------------------------------- tabs */
    const tabs = [];
    let activeId = null;
    let untabbedBaseline = '';
    let counter = 0;
    let limit = 10;
    let inflight = Promise.resolve(true);
    const byId = id => tabs.find(tab => tab.id === id) || null;
    const current = () => byId(activeId);

    function markClean() {
        const tab = current();
        if (tab) tab.baseline = hash(); else untabbedBaseline = hash();
    }
    function isDirty() {
        const tab = current();
        return hash() !== (tab ? tab.baseline : untabbedBaseline);
    }

    const strip = document.getElementById('document-tabs');
    const list = document.getElementById('document-tab-list');
    const banner = document.getElementById('document-draft-banner');
    const statusLine = document.getElementById('document-tab-status');
    let statusTimer = null;
    function status(text, failed = false) {
        if (!statusLine) return;
        statusLine.textContent = text;
        statusLine.classList.toggle('is-error', failed);
        clearTimeout(statusTimer);
        if (text && !failed) statusTimer = setTimeout(() => { statusLine.textContent = ''; }, 4000);
    }

    function showBanner(text, actions = [], failed = true) {
        if (!banner) { status(text, failed); return; }
        banner.hidden = !text;
        banner.className = 'document-draft-banner ' + (failed ? 'is-error' : 'is-info');
        banner.replaceChildren();
        if (!text) return;
        const message = document.createElement('span');
        message.textContent = text;
        banner.appendChild(message);
        actions.forEach(([label, handler]) => {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'btn btn-sm btn-outline-secondary'; button.textContent = label;
            button.addEventListener('click', handler);
            banner.appendChild(button);
        });
    }

    function label(tab) {
        return (tab.title || (tab.draftId ? 'Draft #' + tab.draftId : 'New draft')) + (tab.partyName ? ' · ' + tab.partyName : '');
    }

    function render() {
        if (!list) return;
        if (strip) strip.hidden = !shared || tabs.length === 0;
        list.replaceChildren();
        tabs.forEach(tab => {
            const item = document.createElement('div');
            item.className = 'document-tab' + (tab.id === activeId ? ' is-active' : '') + (tab.conflict ? ' has-conflict' : '');
            item.setAttribute('role', 'presentation');
            const open = document.createElement('button');
            open.type = 'button'; open.className = 'document-tab-open'; open.setAttribute('role', 'tab');
            open.setAttribute('aria-selected', tab.id === activeId ? 'true' : 'false');
            open.textContent = label(tab); open.title = label(tab);
            open.addEventListener('click', () => { workspace.openTab(tab.id); });
            const close = document.createElement('button');
            close.type = 'button'; close.className = 'document-tab-close';
            close.setAttribute('aria-label', 'Discard ' + (tab.title || 'draft')); close.title = 'Discard this draft'; close.textContent = '×';
            close.addEventListener('click', () => { workspace.discardTab(tab.id); });
            item.append(open, close);
            list.appendChild(item);
        });
        const add = document.getElementById('document-tab-add');
        if (add) add.disabled = tabs.length >= limit;
        const count = document.getElementById('document-tab-count');
        if (count) count.textContent = tabs.length + '/' + limit;
    }

    function setDraftId(id) {
        $(form).find('[name="draft_id"]').remove();
        if (id) $('<input type="hidden" name="draft_id">').val(id).appendTo(form);
    }

    function partyMeta() {
        const select = document.querySelector(partySelector);
        const option = select && select.selectedOptions && select.selectedOptions[0];
        if (!select || !select.value || !option) return {};
        return {party_id: Number(select.value), party_name: (option.textContent || '').replace(/\s*\(.*\)\s*$/, '').trim()};
    }

    function limitMessage() {
        return 'You already have ' + limit + ' open ' + kindLabel + ' drafts. Close or post one before starting another.';
    }

    function request(method, path, body) {
        const options = {url: config.baseUrl + path, method, dataType: 'json'};
        if (body !== undefined) {
            options.contentType = 'application/json';
            options.data = JSON.stringify({...body, _token: $(form).find('[name="_token"]').val()});
        }
        return $.ajax(options);
    }
    const messageOf = xhr => (xhr.responseJSON && (xhr.responseJSON.errors && Object.values(xhr.responseJSON.errors)[0][0] || xhr.responseJSON.message)) || 'Unable to complete this action.';

    /** A tab always exists for a form with changes, so edits can never live outside a persisted workspace. */
    function ensureTab() {
        let tab = current();
        if (!tab) {
            tab = {id: 'tab-' + (++counter), draftId: null, version: 0, title: '', partyName: '', snapshot: null, baseline: untabbedBaseline,
                conflict: false, blocking: [], warnings: []};
            tabs.push(tab); activeId = tab.id;
        }
        return tab;
    }

    /** Persist the active tab. Returns true when the server holds what is on screen (or there is nothing to keep). */
    function saveActive({explicit = false} = {}) {
        if (!shared) return Promise.resolve(false);
        inflight = inflight.catch(() => false).then(() => persist(explicit));
        return inflight;
    }

    function persist(explicit) {
        const state = hash();
        let tab = current();
        if (!tab) {
            if (state === untabbedBaseline && !explicit) return Promise.resolve(true);
            if (tabs.length >= limit) { showBanner(limitMessage()); return Promise.resolve(false); }
            tab = ensureTab();
        }
        if (tab.conflict) return Promise.resolve(false);
        if (tab.blocking.length) { showBanner(tab.blocking.join(' ')); return Promise.resolve(false); }
        if (!explicit && state === tab.baseline) return Promise.resolve(true);
        if (!tab.draftId && tabs.filter(other => other.draftId).length >= limit) { showBanner(limitMessage()); return Promise.resolve(false); }
        const meta = partyMeta();
        const sent = snapshot();
        return new Promise(resolve => {
            request('POST', '/drafts', {payload: sent, id: tab.draftId, version: tab.version || 0, ...meta}).done(response => {
                const saved = response.data;
                tab.draftId = saved.id; tab.version = saved.version; tab.title = saved.title;
                tab.partyName = saved.party_name_snapshot || ''; tab.baseline = stable(sent);
                setDraftId(tab.draftId);
                if ((response.warnings || []).length) showBanner(response.warnings.join(' '), [], false); else if (banner && !banner.hidden && !tab.conflict) showBanner('');
                render(); status(explicit ? 'Draft saved.' : '');
                resolve(true);
            }).fail(xhr => {
                if (xhr.status === 409) { tab.conflict = true; conflict(tab); }
                else if (xhr.status === 404) {
                    tab.draftId = null; tab.version = 0; setDraftId(null);
                    showBanner('This draft no longer exists (it may have been posted or removed in another window). Your changes are still here and will be saved as a new draft.', [], false);
                } else showBanner(messageOf(xhr));
                render(); resolve(false);
            });
        });
    }

    function conflict(tab) {
        showBanner('Draft changed in another window.', [
            ['Load latest', () => loadLatest(tab)],
            ['Keep mine as a new tab', () => keepMine(tab)],
        ]);
    }

    function fetchDraft(tab) {
        return new Promise((resolve, reject) => request('GET', '/drafts/' + tab.draftId).done(response => resolve(response.data)).fail(reject));
    }

    function loadLatest(tab) {
        confirmBox('Load latest?', 'Your unsaved changes in this tab will be discarded and replaced by the latest saved version.', 'Load latest').then(ok => {
            if (!ok) return;
            fetchDraft(tab).then(data => { applyDraft(tab, data); showBanner(''); }).catch(xhr => showBanner(messageOf(xhr)));
        });
    }

    function keepMine(tab) {
        if (tabs.filter(other => other.draftId).length >= limit) {
            showBanner('You already have ' + limit + ' open ' + kindLabel + ' drafts. Close one first, then choose "Keep mine as a new tab" again.', [
                ['Load latest', () => loadLatest(tab)], ['Keep mine as a new tab', () => keepMine(tab)]]);
            return;
        }
        const sent = snapshot(); const meta = partyMeta();
        request('POST', '/drafts', {payload: sent, id: null, version: 0, ...meta}).done(response => {
            const saved = response.data;
            // The conflicting draft stays exactly as the other window saved it; this tab becomes a new draft.
            const original = {...tab, id: 'tab-' + (++counter), snapshot: null, baseline: '', conflict: false};
            tabs.splice(tabs.indexOf(tab), 0, original);
            Object.assign(tab, {draftId: saved.id, version: saved.version, title: saved.title, partyName: saved.party_name_snapshot || '',
                conflict: false, baseline: stable(sent)});
            setDraftId(tab.draftId); showBanner('Your copy was kept as ' + tab.title + '. The other window\'s draft is unchanged.', [], false); render();
        }).fail(xhr => showBanner(messageOf(xhr)));
    }

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

    function applyDraft(tab, data) {
        if (data.legacy) {
            data = {...data, payload: legacySnapshot(data.payload || {})};
        }
        tab.snapshot = data.payload; tab.version = data.draft.version; tab.title = data.draft.title;
        tab.partyName = data.draft.party_name_snapshot || ''; tab.blocking = data.blocking || []; tab.warnings = data.warnings || [];
        config.context = {...(config.context || {}), ...Object.fromEntries(Object.entries((data.payload.form || {}).context || {}))};
        restore(data.payload);
        activeId = tab.id; setDraftId(tab.draftId); tab.baseline = hash(); tab.conflict = false;
        if (tab.blocking.length) showBanner(tab.blocking.join(' '));
        else if (tab.warnings.length) showBanner(tab.warnings.join(' '), [], false);
        else showBanner('');
        render();
    }

    /** Save what is on screen (if anything changed) before the form is replaced. */
    async function leaveActive() {
        if (!shared) return true;
        const tab = current();
        const dirty = isDirty();
        if (tab && !tab.draftId && !dirty) { tabs.splice(tabs.indexOf(tab), 1); activeId = null; render(); return true; }
        if (!dirty) return true;
        return saveActive();
    }

    workspace.openTab = async id => {
        const tab = byId(id);
        if (!tab || id === activeId) return;
        if (!(await leaveActive())) return;
        const target = byId(id);
        if (!target) return;
        try {
            const data = target.snapshot && !target.conflict ? {payload: target.snapshot, draft: {version: target.version, title: target.title, party_name_snapshot: target.partyName}, blocking: target.blocking, warnings: target.warnings}
                : await fetchDraft(target);
            applyDraft(target, data);
            target.snapshot = null;
        } catch (xhr) {
            showBanner(xhr && xhr.status === 404 ? 'This draft no longer exists.' : messageOf(xhr));
            if (xhr && xhr.status === 404) { tabs.splice(tabs.indexOf(target), 1); render(); }
        }
    };

    workspace.discardTab = async id => {
        const tab = byId(id);
        if (!tab) return;
        const ok = await confirmBox('Discard ' + (tab.title || 'this draft') + '?', 'This draft and everything entered in it will be permanently removed.', 'Discard');
        if (!ok) return;
        const finish = () => {
            const wasActive = tab.id === activeId;
            tabs.splice(tabs.indexOf(tab), 1);
            if (wasActive) { activeId = null; muted = true; grid.resetFormToNew(); muted = false; setDraftId(null); untabbedBaseline = hash(); grid.switchWorkspaceMode('register'); showBanner(''); }
            render();
        };
        if (!tab.draftId) { finish(); return; }
        request('DELETE', '/drafts/' + tab.draftId).done(finish).fail(xhr => { if (xhr.status === 404) finish(); else showBanner(messageOf(xhr)); });
    };

    /* ----------------------------------------------------------------- dialogs */
    function dialogBase(title, text) {
        const dialog = document.createElement('dialog');
        dialog.setAttribute('data-owns-keys', '');
        dialog.setAttribute('aria-label', title);
        const heading = document.createElement('h3'); heading.textContent = title;
        const paragraph = document.createElement('p'); paragraph.textContent = text;
        dialog.append(heading, paragraph);
        return dialog;
    }
    function present(dialog, focusTarget) {
        document.body.appendChild(dialog);
        if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
        focusTarget.focus();
    }
    function confirmBox(title, text, yes) {
        return new Promise(resolve => {
            const dialog = dialogBase(title, text);
            const done = value => { dialog.remove(); resolve(value); };
            const add = (label, value, cls) => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-sm ' + cls; button.textContent = label;
                button.addEventListener('click', () => done(value)); dialog.appendChild(button); return button;
            };
            add(yes, true, 'btn-danger'); const cancel = add('Cancel', false, 'btn-outline-secondary');
            dialog.addEventListener('cancel', event => { event.preventDefault(); done(false); });
            present(dialog, cancel);
        });
    }
    function ask() {
        return new Promise(resolve => {
            const dialog = dialogBase('Unsaved ' + config.kind + ' bill', 'This bill has changes that are not saved. What should happen to them?');
            const done = value => { dialog.remove(); resolve(value); };
            const add = (label, value, cls) => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-sm ' + cls; button.textContent = label;
                button.addEventListener('click', () => done(value)); dialog.appendChild(button); return button;
            };
            if (shared) add('Save draft', 'save', 'btn-primary');
            add('Discard', 'discard', 'btn-outline-danger');
            const cancel = add('Cancel', 'cancel', 'btn-outline-secondary');
            dialog.addEventListener('cancel', event => { event.preventDefault(); done('cancel'); });
            present(dialog, cancel);
        });
    }

    /* ---------------------------------------------------------------- new / start */
    function focusFirst() {
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

    /** Blank form in a blank, client-only tab. No server draft exists until the first meaningful edit. */
    function start(options = {}) {
        muted = true;
        try {
            grid.resetFormToNew();
            grid.switchWorkspaceMode('voucher');
            setDraftId(null);
            applyContext();
            applyDocumentMode(options.doc);
        } finally { muted = false; }
        if (shared) {
            const tab = {id: 'tab-' + (++counter), draftId: null, version: 0, title: '', partyName: '', snapshot: null, baseline: '',
                conflict: false, blocking: [], warnings: []};
            tabs.push(tab); activeId = tab.id; tab.baseline = hash();
        } else {
            activeId = null; untabbedBaseline = hash();
        }
        showBanner('');
        render();
        focusFirst();
    }

    async function newDocument(options = {}) {
        if (shared) refreshLimit();
        const tab = current();
        const dirty = isDirty();
        if (dirty) {
            const choice = await ask();
            if (choice === 'cancel') return false;
            if (choice === 'save' && !(await saveActive({explicit: true}))) return false;
            if (choice === 'discard' && tab && tab.draftId) {
                await new Promise(resolve => request('DELETE', '/drafts/' + tab.draftId).always(resolve));
                tabs.splice(tabs.indexOf(tab), 1); activeId = null;
            } else if (choice === 'discard' && tab) { tabs.splice(tabs.indexOf(tab), 1); activeId = null; }
        } else if (tab && !tab.draftId) {
            // An untouched blank tab is simply reused.
            tabs.splice(tabs.indexOf(tab), 1); activeId = null;
        }
        if (shared && tabs.length >= limit) { showBanner(limitMessage()); render(); return false; }
        start(options);
        return true;
    }

    function refreshLimit() { render(); }

    workspace.handles = entry => entry.id === config.kind || (entry.id === 'purchase-order' && purchase && !!config.purchaseOrders);
    workspace.newDocument = options => newDocument({doc: options && options.shortcut === 'purchase-order' ? 'order' : undefined, ...options});
    workspace.isDirty = isDirty;
    workspace.snapshot = snapshot;
    workspace.restore = restore;
    workspace.markClean = markClean;
    workspace.start = start;
    workspace.saveDraft = options => saveActive({explicit: true, ...options});
    workspace.canSaveDraft = () => shared;
    workspace.activeDraftId = () => (current() || {}).draftId || null;
    workspace.activeBlocking = () => (current() || {blocking: []}).blocking;
    workspace.tabs = tabs;
    workspace.ask = ask;
    workspace.confirmBox = confirmBox;
    workspace.status = status;
    workspace.localShortcuts = [
        {key: 'Alt+I', label: 'Focus item search'}, {key: 'F6', label: 'Create a new item'}, {key: 'Ctrl+Shift+F', label: 'Hide / show the top bar'},
    ];
    workspace.openClone = payload => cloneInto(payload);
    workspace.touch = () => scheduleAutosave();
    window.zoloDocumentWorkspace = workspace;

    /** Copy an existing document into a brand-new draft tab (party, warehouse and lines only). */
    async function cloneInto(copy) {
        if (!(await newDocument({}))) return false;
        muted = true;
        try {
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
        } finally { muted = false; }
        touched = true; scheduleAutosave();
        return true;
    }

    /* ------------------------------------------------------------------ autosave */
    let touched = false;
    let timer = null;
    function scheduleAutosave() {
        if (!shared || muted) return;
        touched = true;
        clearTimeout(timer);
        timer = setTimeout(() => { if (touched) { touched = false; saveActive(); } }, 2000);
    }
    const inDocument = target => !!target.closest && (target.closest('#' + form.id) || target.closest('#charges-drawer'));
    document.addEventListener('input', event => { if (inDocument(event.target) && !event.target.closest('#table-search-row')) scheduleAutosave(); }, true);
    document.addEventListener('change', event => { if (inDocument(event.target)) scheduleAutosave(); }, true);
    const body = document.getElementById('order-table-body');
    if (body && window.MutationObserver) new MutationObserver(() => scheduleAutosave()).observe(body, {childList: true});
    $(document).on('command-center-loaded', () => {
        // A saved document opened for editing is a fresh, untabbed form until it is changed.
        if (muted) return;
        setTimeout(() => { activeId = null; setDraftId(null); showBanner(''); render(); untabbedBaseline = hash(); }, 0);
    });

    // Unloading is best effort only: the request may not finish, so the UI never claims a save for it.
    function flushOnHide() {
        if (!shared || muted) return;
        const tab = current();
        const state = hash();
        if (!tab && state === untabbedBaseline) return;
        if (tab && (tab.conflict || tab.blocking.length || state === tab.baseline)) return;
        if (!tab && tabs.length >= limit) return;
        const target = tab || ensureTab();
        try {
            fetch(config.baseUrl + '/drafts', {method: 'POST', keepalive: true, credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': ($('meta[name="csrf-token"]').attr('content') || $(form).find('[name="_token"]').val() || '')},
                body: JSON.stringify({payload: snapshot(), id: target.draftId, version: target.version || 0, ...partyMeta()})});
        } catch (error) { /* nothing to report while the page is going away */ }
    }
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushOnHide(); });
    window.addEventListener('pagehide', flushOnHide);

    /* -------------------------------------------------------------- page wiring */
    // The normal "Save as" button becomes an explicit Save draft; nothing is posted.
    if (shared) {
        $('#btn-form-save-as').text('Save draft').off('click').on('click', async () => {
            const ok = await saveActive({explicit: true});
            if (ok) status('Draft saved.');
        });
        $('#document-tab-add').on('click', () => { newDocument({}); });
        // A draft whose required references are gone cannot be posted; the server refuses it too.
        form.addEventListener('submit', event => {
            const blocking = workspace.activeBlocking();
            if (blocking.length) { event.preventDefault(); event.stopImmediatePropagation(); showBanner(blocking.join(' ')); }
        }, true);
        // Opening a saved bill must not silently replace unsaved work.
        for (const name of ['loadPurchaseToForm', 'loadSaleToForm']) {
            const original = window[name];
            if (typeof original !== 'function') continue;
            window[name] = async function (id) { if (await leaveActive()) { activeId = null; render(); return original.call(this, id); } };
        }
    }

    function loadList() {
        if (!shared) return Promise.resolve();
        return new Promise(resolve => {
            request('GET', '/drafts').done(response => {
                limit = response.limit || limit;
                (response.data || []).forEach(row => tabs.push({id: 'tab-' + (++counter), draftId: row.id, version: row.version, title: row.title,
                    partyName: row.party_name_snapshot || '', snapshot: null, baseline: '', conflict: false, blocking: [], warnings: []}));
                render(); resolve();
            }).fail(xhr => { status(messageOf(xhr), true); resolve(); });
        });
    }

    /* --------------------------------------------------------------- ?new=1 start */
    $(async () => {
        await loadList();
        const query = new URLSearchParams(window.location.search);
        if (query.get('new') === '1') {
            query.delete('new');
            window.history.replaceState(null, '', window.location.pathname + (query.toString() ? '?' + query : '') + window.location.hash);
            if (shared && tabs.length >= limit) { grid.switchWorkspaceMode('register'); showBanner(limitMessage()); }
            else start({doc: query.get('doc') === 'order' ? 'order' : undefined});
        } else if (tabs.length) {
            // Existing drafts are listed, but the register stays the workspace until one is chosen.
            grid.switchWorkspaceMode('register');
            untabbedBaseline = hash();
        } else {
            untabbedBaseline = hash();
        }
        workspace.ready = true;
        document.dispatchEvent(new CustomEvent('zolo-workspace-ready'));
    });
})();
