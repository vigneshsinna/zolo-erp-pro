/**
 * Consolidated conversational Purchase Order workspace.
 *
 * All changes use the scoped, revisioned workflow-task API. Browse and versioned
 * choice selection use the existing conversation router. This never posts a PO.
 */
(function ($) {
    'use strict';

    const names = {supplier: 'Supplier', product: 'Product', warehouse: 'Destination Warehouse', unit: 'Unit'};
    const plurals = {supplier: 'suppliers', product: 'products', warehouse: 'warehouses', unit: 'units'};
    const editable = ['supplier', 'product', 'warehouse'];
    const safeLineId = /^[a-zA-Z0-9_-]+$/;

    function badge(field, state) {
        const issue = (state.required_inputs || []).find(item => item.field === field);
        if (issue) {
            return $('<span class="ai-po-field-state ai-po-field-required"></span>')
                .text(issue.status === 'invalid' ? 'Needs correction' : 'Required');
        }
        const confirmed = (state.confirmed_fields || []).find(item => item.field === field);
        if (confirmed) {
            return $('<span class="ai-po-field-state ai-po-field-set"></span>')
                .text(confirmed.source === 'erp_default' ? 'Suggested default' : 'Selected');
        }
        return $('<span class="ai-po-field-state"></span>').text('Optional');
    }

    function labelFor(field, state) {
        return (state.confirmed_fields || []).find(item => item.field === field)?.display || '';
    }

    function fieldBlock(name, field, state) {
        const section = $('<section class="ai-po-section"></section>');
        const head = $('<div class="ai-po-section-head"></div>');
        head.append($('<strong></strong>').text(name), badge(field, state));
        section.append(head);
        return section;
    }

    function showChoices(section, type, state, submit) {
        const directory = (state.directories || []).find(item => item.entity_type === type);
        const choices = (state.choices || []).filter(item => item.entity_type === type);
        const panel = $('<div class="ai-po-record-picker"></div>');
        if (choices.length) {
            const group = $('<div class="ai-po-choice-grid" role="group"></div>')
                .attr('aria-label', 'Select an existing ' + type);
            choices.forEach(choice => group.append(
                $('<button type="button" class="btn btn-sm btn-outline-primary ai-po-choice" data-conversation-action></button>')
                    .text(choice.label)
                    .on('click', () => submit('Select option', {
                        choice_set_id: state.choice_set_id, choice_id: choice.choice_id,
                    }))
            ));
            panel.append(group);
        } else if (directory?.items?.length) {
            // A directory without an authoritative signed choice must never
            // become an unsafe ID-selection widget.
            const list = $('<ul class="small mb-2 pl-3"></ul>');
            directory.items.forEach(item => list.append($('<li></li>').text(item.name + (item.code ? ' (' + item.code + ')' : ''))));
            panel.append(list);
        }
        const search = $('<form class="ai-po-record-search"></form>');
        const term = $('<input class="form-control form-control-sm" type="search" autocomplete="off" maxlength="120">')
            .attr('aria-label', 'Search existing ' + plurals[type])
            .attr('placeholder', 'Search existing ' + plurals[type] + (type === 'product' ? ' by name or SKU' : ''));
        const button = $('<button type="submit" class="btn btn-sm btn-outline-secondary" data-conversation-action>Search</button>');
        search.append(term, button).on('submit', event => {
            event.preventDefault();
            const value = term.val().trim();
            submit(value ? 'search ' + plurals[type] + ' for ' + value : 'show ' + plurals[type]);
        });
        panel.append(search);
        if (directory) {
            panel.append($('<p class="ai-po-hint"></p>').text(
                'Showing ' + (directory.items || []).length + ' of ' + (directory.total || 0) + ' eligible records'
                + (directory.has_more ? '. Search to see other matches.' : '.')
            ));
            if (directory.can_create && editable.includes(type)) {
                const newForm = $('<form class="ai-po-record-search" hidden></form>');
                const newName = $('<input type="text" required maxlength="100" class="form-control form-control-sm" autocomplete="off">')
                    .attr('placeholder', 'New ' + type + ' name')
                    .attr('aria-label', 'New ' + type + ' name');
                newForm.append(newName, $('<button type="submit" class="btn btn-sm btn-primary" data-conversation-action>Prepare new record</button>'))
                    .on('submit', event => {
                        event.preventDefault();
                        const value = newName.val().trim();
                        if (value) submit('create new ' + type + ' ' + value);
                    });
                const toggle = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action></button>')
                    .text('Create new ' + type)
                    .on('click', () => {
                        newForm.prop('hidden', !newForm.prop('hidden'));
                        if (!newForm.prop('hidden')) newName.trigger('focus');
                    });
                panel.append(toggle, newForm,
                    $('<p class="ai-po-hint"></p>').text('The new master requires separate approval. No record is created by entering its name.'));
            }
        } else {
            panel.prepend($('<p class="ai-po-hint"></p>').text('Search to see eligible records and approved creation options.'));
        }
        section.append(panel);
    }

    function oneInput(section, title, value, opts, onChange) {
        const wrap = $('<label class="ai-po-edit-field"></label>');
        const input = $('<input class="form-control form-control-sm">')
            .attr({type: opts.type || 'text', min: opts.min, max: opts.max, step: opts.step, placeholder: opts.placeholder || ''})
            .val(value === null || value === undefined ? '' : value);
        wrap.append($('<span></span>').text(title), input);
        if (opts.help) wrap.append($('<small></small>').text(opts.help));
        input.on('change input', () => onChange(input.val()));
        section.append(wrap);
        return input;
    }

    function render({state, mount, request, submit, fail, setBusy, isBusy, refreshAfterPatch}) {
        if (state.action_key && state.action_key !== 'purchase_order.create.v1') {
            mount.append($('<p role="alert"></p>').text('The current draft is not a Purchase Order. No PO fields were updated.'));
            return;
        }

        const heading = $('<div class="ai-po-workspace-head"></div>');
        heading.append($('<strong></strong>').text('Purchase Order Workspace'));
        heading.append($('<span class="ai-po-field-state"></span>').text('Unposted draft'));
        mount.append(heading,
            $('<p class="ai-po-hint"></p>').text(
                'Complete any fields in any order. Select existing ERP records, request a new master, type values here, or use chat/dictation/recorded voice below.'
            ));

        const missing = (state.required_inputs || []).filter(issue =>
            ['missing', 'ambiguous', 'invalid', 'blocked_by_dependency'].includes(issue.status));
        const count = $('<p class="ai-po-status" role="status"></p>')
            .text(missing.length ? missing.length + ' details need attention before the PO proposal is ready.' :
                'All visible requirements are satisfied. The ERP will validate the proposal before approval.');
        mount.append(count);

        const grid = $('<div class="ai-po-workspace-grid"></div>');
        const supplier = fieldBlock('Supplier *', 'supplier_id', state);
        const supplierName = labelFor('supplier_id', state);
        if (supplierName) supplier.append($('<p class="ai-po-selected"></p>').text(supplierName));
        showChoices(supplier, 'supplier', state, submit);
        grid.append(supplier);

        const warehouse = fieldBlock('Destination Warehouse *', 'warehouse_id', state);
        const warehouseName = labelFor('warehouse_id', state);
        if (warehouseName) warehouse.append($('<p class="ai-po-selected"></p>').text(warehouseName));
        showChoices(warehouse, 'warehouse', state, submit);
        grid.append(warehouse);
        mount.append(grid);

        const product = fieldBlock('Products, Quantities & Rates *', 'items', state);
        product.append($('<p class="ai-po-hint"></p>').text(
            'Rate is the purchase rate per unit used on the normal ERP Purchase Order screen. Existing master costs are reference information, not approved rates.'
        ));
        const lines = $('<div class="ai-po-lines" role="group" aria-label="Purchase Order items"></div>');
        product.append(lines);
        showChoices(product, 'product', state, submit);
        mount.append(product);

        const terms = fieldBlock('Payment Terms *', 'payment_terms_type', state);
        const payments = $('<div class="ai-po-payment-mode" role="group" aria-label="Cash or Credit"></div>');
        const cash = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action>Cash</button>')
            .on('click', () => submit('Cash'));
        const credit = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action>Credit</button>');
        payments.append(cash, credit);
        terms.append(payments);
        const creditWrap = $('<div class="ai-po-credit-days"></div>');
        terms.append(creditWrap);
        mount.append(terms);

        const date = fieldBlock('Order Date *', 'date', state);
        mount.append(date);

        const unresolved = $('<section class="ai-po-missing-list"></section>');
        if (missing.length) {
            unresolved.append($('<strong></strong>').text('Remaining questions'));
            const list = $('<ul></ul>');
            const seen = new Set();
            missing.forEach(item => {
                if (seen.has(item.field)) return;
                seen.add(item.field);
                list.append($('<li></li>').text((item.label || item.field) + ': ' + (item.reason || 'Please confirm this field.')));
            });
            unresolved.append(list);
        }
        mount.append(unresolved);

        const actions = $('<div class="ai-po-workspace-actions"></div>');
        const update = $('<button type="button" class="btn btn-primary" data-conversation-action>Apply details & revalidate</button>')
            .prop('disabled', true);
        const status = $('<span class="ai-po-hint" role="status"></span>').text('No unsaved changes');
        actions.append(update, status);
        mount.append(actions);

        const modes = $('<div class="ai-po-voice-tools"></div>');
        modes.append($('<strong></strong>').text('Or update through conversation'));
        modes.append($('<p class="ai-po-hint"></p>').text(
            'Example: “Set rate to 600, quantity 10, use Main Warehouse and Credit 30 days.”'
        ));
        const focus = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action>Type a correction</button>')
            .on('click', () => $('#chat-input').trigger('focus'));
        const dictate = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action>Dictate a correction</button>')
            .on('click', () => $('#btn-dictate').trigger('click'));
        const record = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action>Record a voice instruction</button>')
            .on('click', () => $('#btn-ai-record-audio').trigger('click'));
        modes.append(focus);
        if ($('#btn-dictate').length && !$('#btn-dictate').prop('hidden')) modes.append(dictate);
        if ($('#btn-ai-record-audio').length) modes.append(record);
        mount.append(modes);

        const dirty = new Map();
        const change = (field, value) => {
            dirty.set(field, value);
            update.prop('disabled', false);
            status.text(dirty.size + ' unsaved change' + (dirty.size === 1 ? '' : 's'));
        };
        let loadedRevision = null;
        request('GET', '/workflow-tasks/' + state.task_id).then(response => {
            if (!mount[0]?.isConnected) return;
            const slots = response.task?.slots || {};
            loadedRevision = Number(response.task?.revision || 0);
            const stale = loadedRevision !== Number(state.task_revision);
            if (stale) {
                status.text('This draft has changed. Refresh it before editing.');
                return;
            }
            const products = Array.isArray(slots.items) ? slots.items : [];
            if (!products.length) lines.append($('<p class="ai-po-hint"></p>').text('No product selected yet. Browse existing products or create a new one below.'));
            products.forEach((line, index) => {
                if (!safeLineId.test(String(line.client_line_id || ''))) return;
                const lineId = line.client_line_id;
                const panel = $('<div class="ai-po-line"></div>');
                panel.append($('<strong></strong>').text('Item ' + (index + 1) + ': ' +
                    (labelFor('items[' + lineId + '].product_id', state) || line.product_query || 'Selected product')));
                const inputs = $('<div class="ai-po-line-inputs"></div>');
                oneInput(inputs, 'Quantity *', line.qty, {type:'number', min:'0.000001', step:'any'}, val =>
                    change('items[' + lineId + '].qty', val));
                oneInput(inputs, 'Rate (₹) *', line.net_unit_cost ?? line.net_unit_price,
                    {type:'number', min:'0', step:'any'}, val =>
                        change('items[' + lineId + '].net_unit_cost', val));
                panel.append(inputs);
                panel.append($('<button type="button" class="btn btn-sm btn-link text-danger" data-conversation-action>Remove item</button>')
                    .on('click', () => change('items[' + lineId + ']._destroy', true)));
                lines.append(panel);
            });
            const currentTerms = String(slots.payment_terms_type || '');
            if (currentTerms === 'cash') cash.addClass('active');
            if (currentTerms === 'credit') credit.addClass('active');
            creditWrap.empty();
            let daysInput = null;
            const setTerms = value => {
                cash.toggleClass('active', value === 'cash');
                credit.toggleClass('active', value === 'credit');
                change('payment_terms_type', value);
                creditWrap.empty();
                if (value === 'cash') {
                    change('credit_days', 0);
                } else {
                    daysInput = oneInput(creditWrap, 'Credit Days *',
                        slots.credit_days ?? '', {type:'number', min:'0', max:'3650', step:'1'},
                        val => change('credit_days', val));
                }
            };
            cash.off('click').on('click', () => setTerms('cash'));
            credit.off('click').on('click', () => setTerms('credit'));
            if (currentTerms === 'credit') {
                daysInput = oneInput(creditWrap, 'Credit Days *', slots.credit_days ?? '',
                    {type:'number', min:'0', max:'3650', step:'1'}, val => change('credit_days', val));
            } else {
                creditWrap.append($('<p class="ai-po-hint"></p>').text('Select Credit to enter credit days.'));
            }
            oneInput(date, 'Purchase Order date *', slots.date || labelFor('date', state),
                {type:'date'}, val => change('date', val));
        }).catch(() => status.text('Could not load the latest draft. Refresh before editing.'));

        update.on('click', async () => {
            if (isBusy() || loadedRevision === null || dirty.size === 0) return;
            if (loadedRevision !== Number(state.task_revision)) {
                status.text('The task changed. Refresh before applying edits.');
                return;
            }
            const corrections = [];
            for (const [field, value] of dirty) {
                if (value === '' || value === null) {
                    status.text('Fill every changed required field before applying the draft.');
                    return;
                }
                if (/\.qty$/.test(field) && (!Number.isFinite(Number(value)) || Number(value) <= 0)) {
                    status.text('Quantity must be greater than zero.');
                    return;
                }
                if (/\.net_unit_cost$/.test(field) && (!Number.isFinite(Number(value)) || Number(value) < 0)) {
                    status.text('Rate must be a nonnegative number.');
                    return;
                }
                corrections.push({field, value, confirmation:'user_edited'});
            }
            setBusy(true);
            status.text('Validating draft changes…');
            try {
                const result = await request('PATCH', '/workflow-tasks/' + state.task_id + '/inputs', {
                    expected_revision: loadedRevision, corrections,
                });
                dirty.clear();
                loadedRevision = Number(result.task?.revision || 0);
                status.text('Saved. Updating ERP validation…');
                setBusy(false);
                await refreshAfterPatch(loadedRevision);
            } catch (error) {
                setBusy(false);
                fail(error);
                status.text('Changes were not applied. Review the error and retry.');
            }
        });
    }

    window.zoloAiPoWorkspace = {render};
})(jQuery);
