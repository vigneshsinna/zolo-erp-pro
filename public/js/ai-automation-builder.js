(function (window, document) {
    'use strict';

    const root = document.querySelector('.ai-automation');
    const form = document.getElementById('ai-automation-form');
    if (!root || !form) return;

    const url = root.dataset.catalogUrl;
    const template = document.getElementById('ai-builder-row-template');
    const items = document.getElementById('ai-builder-items');
    const action = document.getElementById('automation-action');
    const supplierGroup = document.getElementById('ai-builder-supplier-group');
    const feedback = document.getElementById('ai-builder-feedback');
    let nextIndex = 1;

    function updateProducts() {
        const count = items.querySelectorAll('.ai-builder-item').length;
        document.getElementById('ai-builder-count').textContent = count + ' of 50 products';
        document.getElementById('ai-builder-add-item').disabled = count >= 50;
        items.querySelectorAll('.ai-builder-remove').forEach(button => {
            button.disabled = count < 2;
        });
    }

    function syncSupplier() {
        if (!supplierGroup) return;
        const required = action.value === '1';
        supplierGroup.hidden = !required;
        if (!required) {
            const id = document.getElementById('builder-supplier-id');
            id.value = '';
            const query = supplierGroup.querySelector('.ai-builder-lookup');
            query.value = '';
            supplierGroup.querySelector('.ai-builder-options').hidden = true;
        }
    }

    function showResults(input, select, entries) {
        select.replaceChildren();
        for (const item of entries) {
            const option = document.createElement('option');
            option.value = String(item.id);
            option.textContent = String(item.label);
            select.appendChild(option);
        }
        select.hidden = entries.length === 0;
        select.dataset.queryValue = input.value;
        select.selectedIndex = -1;
        input.setAttribute('aria-expanded', String(entries.length !== 0));
    }

    async function lookup(input) {
        const group = input.closest('.ai-builder-lookup-group');
        const select = group.querySelector('.ai-builder-options');
        const target = document.getElementById(input.dataset.target);
        if (!target) return;
        target.value = '';
        const text = input.value.trim();
        const version = (Number(input.dataset.lookupVersion || '0') + 1);
        input.dataset.lookupVersion = String(version);
        select.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        const request = new URL(url, window.location.origin);
        request.searchParams.set('type', input.dataset.kind);
        request.searchParams.set('q', text);
        try {
            const response = await fetch(request.toString(), {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error('Catalogue lookup unavailable.');
            const data = await response.json();
            // Ignore old, out-of-order searches and results from removed rows.
            if (input.dataset.lookupVersion !== String(version) || !input.isConnected) return;
            showResults(input, select, Array.isArray(data.items) ? data.items : []);
        } catch (error) {
            if (input.dataset.lookupVersion !== String(version) || !input.isConnected) return;
            select.hidden = true;
            feedback.textContent = 'Could not search the authorised catalogue. Check connectivity and try again.';
        }
    }

    const timers = new WeakMap();
    form.addEventListener('input', event => {
        const input = event.target.closest('.ai-builder-lookup');
        if (!input) return;
        const timer = timers.get(input);
        if (timer) window.clearTimeout(timer);
        const next = window.setTimeout(() => lookup(input), 220);
        timers.set(input, next);
    });
    form.addEventListener('focusin', event => {
        const input = event.target.closest('.ai-builder-lookup');
        if (input && !input.value) lookup(input);
    });
    form.addEventListener('change', event => {
        const select = event.target.closest('.ai-builder-options');
        if (!select) return;
        const option = select.options[select.selectedIndex];
        const target = document.getElementById(select.dataset.target);
        if (!option || !target) return;
        target.value = option.value;
        const input = select.closest('.ai-builder-lookup-group').querySelector('.ai-builder-lookup');
        input.value = option.textContent;
        // A prior in-flight search cannot overwrite the chosen value.
        input.dataset.lookupVersion = String(Number(input.dataset.lookupVersion || 0) + 1);
        select.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        feedback.textContent = '';
    });
    form.addEventListener('click', event => {
        const remove = event.target.closest('.ai-builder-remove');
        if (remove && items.querySelectorAll('.ai-builder-item').length > 1) {
            remove.closest('.ai-builder-item').remove();
            updateProducts();
        }
    });
    document.getElementById('ai-builder-add-item').addEventListener('click', () => {
        if (items.querySelectorAll('.ai-builder-item').length >= 50 || !template) return;
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
        const container = document.createElement('div');
        container.innerHTML = html;
        items.appendChild(container.firstElementChild);
        updateProducts();
        items.lastElementChild.querySelector('.ai-builder-lookup').focus();
    });
    action.addEventListener('change', syncSupplier);
    form.addEventListener('submit', event => {
        const lookups = [...form.querySelectorAll('.ai-builder-item input[type="hidden"][name$="[product_id]"]')];
        const ids = lookups.map(input => input.value);
        if (ids.some(value => !value) || new Set(ids).size !== ids.length) {
            event.preventDefault();
            feedback.textContent = 'Choose a distinct catalogue product for each line before saving.';
            return;
        }
        if (!document.getElementById('builder-warehouse-id').value ||
            (action.value === '1' && !document.getElementById('builder-supplier-id').value)) {
            event.preventDefault();
            feedback.textContent = 'Choose an authorised warehouse and supplier for the selected action.';
            return;
        }
        feedback.textContent = 'Saving a scheduled check, not posting a purchase order…';
    });
    document.querySelectorAll('[data-confirm-cancel]').forEach(button => {
        button.addEventListener('click', event => {
            if (!window.confirm('Cancel this automation? Queued checks will be revalidated.')) event.preventDefault();
        });
    });
    syncSupplier();
    updateProducts();
})(window, document);
