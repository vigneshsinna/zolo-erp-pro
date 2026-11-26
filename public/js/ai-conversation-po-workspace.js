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
                'One question at a time. Use the chat below to answer by typing, dictation or voice, including several details in one reply.'
            ));

        const missing = (state.required_inputs || []).filter(issue =>
            ['missing', 'ambiguous', 'invalid', 'blocked_by_dependency'].includes(issue.status));
        const count = $('<p class="ai-po-status" role="status"></p>')
            .text(missing.length ? 'More details needed before review. Your answers stay saved in this draft.' :
                'Required fields look complete. The ERP will validate the proposal before approval.');
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
        // Never accept a payment choice against an unloaded/stale draft.
        cash.prop('disabled', true);
        credit.prop('disabled', true);
        payments.append(cash, credit);
        terms.append(payments);
        const creditWrap = $('<div class="ai-po-credit-days"></div>');
        terms.append(creditWrap);
        mount.append(terms);

        const date = fieldBlock('Order Date *', 'date', state);
        mount.append(date);

        const extras = fieldBlock('Additional Purchase Order Details', 'note', state);
        extras.append($('<p class="ai-po-hint"></p>').text(
            'Optional. Tax and totals are calculated and validated by the ERP, not guessed by the AI.'
        ));
        mount.append(extras);

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

        // Progressive disclosure: only one editable section is visible by
        // default. The underlying task, corrections and signed ERP options are
        // unchanged; direct edits and multi-field spoken replies still work.
        const stages = [
            {id: 'supplier', title: 'Supplier', node: supplier,
                matches: field => field === 'supplier_id' || field === 'supplier_query'},
            {id: 'products', title: 'Products & Rate', node: product,
                matches: field => field === 'items' || /^items(?:\[|\.)/.test(field)},
            {id: 'warehouse', title: 'Warehouse', node: warehouse,
                matches: field => field === 'warehouse_id' || field === 'warehouse_query'},
            {id: 'terms', title: 'Cash / Credit', node: terms,
                matches: field => field === 'payment_terms_type' || field === 'credit_days'},
            {id: 'date', title: 'Order Date', node: date,
                matches: field => field === 'date'},
            {id: 'extras', title: 'Other details (optional)', node: extras,
                matches: () => false},
        ];
        const isUnresolved = stage => missing.some(issue => stage.matches(String(issue.field || '')));
        const countRequired = stages.slice(0, 5).filter(stage => isUnresolved(stage)).length;
        const navigator = $('<nav class="ai-po-step-nav" aria-label="Purchase Order steps"></nav>');
        const stepCounter = $('<span class="ai-po-step-counter"></span>');
        const pickerLabel = $('<label class="ai-po-step-picker-label"></label>')
            .append($('<span class="sr-only">Choose a Purchase Order step</span>'));
        const picker = $('<select class="form-control form-control-sm ai-po-step-picker"></select>')
            .attr('aria-label', 'Choose a Purchase Order step');
        stages.forEach(stage => {
            const suffix = isUnresolved(stage) ? ' • needed' : '';
            picker.append($('<option></option>').val(stage.id).text(stage.title + suffix));
        });
        pickerLabel.append(picker);
        const previous = $('<button type="button" class="btn btn-sm btn-outline-secondary" data-conversation-action>Previous</button>');
        const next = $('<button type="button" class="btn btn-sm btn-outline-primary" data-conversation-action>Next</button>');
        const all = $('<button type="button" class="btn btn-sm btn-link" data-conversation-action>Show all fields</button>')
            .attr('aria-expanded', 'false');
        navigator.append(stepCounter, pickerLabel, previous, next, all);
        heading.after(navigator);

        const issueFocus = (state.message || '').toLowerCase();
        const requestedTypes = (state.directories || []).map(item => item.entity_type);
        let currentId = requestedTypes.length === 1 && requestedTypes[0] === 'product' ? 'products'
            : requestedTypes.length === 1 && requestedTypes[0] === 'warehouse' ? 'warehouse'
            : requestedTypes.length === 1 && requestedTypes[0] === 'supplier' ? 'supplier'
            : /which product|what quantity|purchase rate|product line|line item/.test(issueFocus) ? 'products'
            : /destination warehouse/.test(issueFocus) ? 'warehouse'
            : /cash or credit|credit days/.test(issueFocus) ? 'terms'
            : /order date/.test(issueFocus) ? 'date'
            : (stages.find(stage => isUnresolved(stage)) || stages[0]).id;
        let expanded = false;
        const showCurrent = () => {
            const index = stages.findIndex(stage => stage.id === currentId);
            stages.forEach(stage => stage.node.prop('hidden', !expanded && stage.id !== currentId));
            grid.prop('hidden', !expanded && !['supplier', 'warehouse'].includes(currentId));
            picker.val(currentId);
            stepCounter.text(expanded
                ? (countRequired ? countRequired + ' required sections remaining' : 'Ready to review')
                : 'Step ' + (index + 1) + ' of ' + stages.length);
            previous.prop('disabled', expanded || index <= 0);
            next.prop('disabled', expanded || index === stages.length - 1);
            all.text(expanded ? 'Show one step' : 'Show all fields')
                .attr('aria-expanded', String(expanded));
        };
        picker.on('change', () => { currentId = picker.val(); expanded = false; showCurrent(); });
        previous.on('click', () => { const index = stages.findIndex(x => x.id === currentId);
            if (index > 0) { currentId = stages[index - 1].id; showCurrent(); } });
        next.on('click', () => { const index = stages.findIndex(x => x.id === currentId);
            if (index < stages.length - 1) { currentId = stages[index + 1].id; showCurrent(); } });
        all.on('click', () => { expanded = !expanded; showCurrent(); });
        showCurrent();

        // Keep the detailed validation list available but not expanded by
        // default; the current conversation question remains the primary guide.
        if (missing.length) {
            const detailsToggle = $('<button type="button" class="btn btn-sm btn-link ai-po-missing-toggle" data-conversation-action></button>')
                .text('View ' + missing.length + ' outstanding checks')
                .attr('aria-expanded', 'false')
                .on('click', () => {
                    const show = unresolved.prop('hidden');
                    unresolved.prop('hidden', !show);
                    detailsToggle.attr('aria-expanded', String(show))
                        .text(show ? 'Hide outstanding checks' : 'View ' + missing.length + ' outstanding checks');
                });
            unresolved.prop('hidden', true);
            mount.append(detailsToggle);
        }

        const actions = $('<div class="ai-po-workspace-actions"></div>');
        const update = $('<button type="button" class="btn btn-primary" data-conversation-action>Apply details & revalidate</button>')
            .prop('disabled', true);
        const status = $('<span class="ai-po-hint" role="status"></span>').text('No unsaved changes');
        actions.append(update, status);
        mount.append(actions);

        // The sticky composer already provides Type, Dictate and Record;
        // repeating its controls inside every question made the page too long.
        mount.append($('<p class="ai-po-hint ai-po-composer-help"></p>').text(
            'Reply below by typing, dictating or attaching a recording. You can provide several details together.'
        ));

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
                oneInput(inputs, 'Purchase Unit', line.uom ||
                    labelFor('items[' + lineId + '].purchase_unit_id', state),
                    {type:'text', placeholder:'e.g. Pairs'}, val =>
                        change('items[' + lineId + '].uom', val));
                panel.append(inputs);
                panel.append($('<button type="button" class="btn btn-sm btn-link text-danger" data-conversation-action>Remove item</button>')
                    .on('click', () => change('items[' + lineId + ']._destroy', true)));
                lines.append(panel);
            });
            const currentTerms = String(slots.payment_terms_type || '');
            cash.prop('disabled', false);
            credit.prop('disabled', false);
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
            oneInput(extras, 'Order Discount (₹)', slots.order_discount ?? '',
                {type:'number', min:'0', step:'any'}, val => change('order_discount', val));
            oneInput(extras, 'Shipping / Freight (₹)', slots.shipping_cost ?? '',
                {type:'number', min:'0', step:'any'}, val => change('shipping_cost', val));
            oneInput(extras, 'Remarks / Reference', slots.note ?? '',
                {type:'text', placeholder:'Optional order instructions'}, val => change('note', val));
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
