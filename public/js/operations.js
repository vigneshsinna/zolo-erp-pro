(() => {
    'use strict';

    // Delegated event listeners for dynamic add/remove lines across all forms
    document.addEventListener('click', event => {
        const add = event.target.closest('[data-add-line]');
        if (add) {
            const container = add.closest('.border') || add.previousElementSibling;
            const table = container?.querySelector('[data-lines]');
            const body = table?.querySelector('tbody');
            if (!body) return;
            const row = body.firstElementChild.cloneNode(true);
            row.querySelectorAll('input').forEach(input => {
                input.value = input.type === 'number' && input.name.endsWith('[cost_weight]') ? '1' : '';
                input.checked = false;
            });
            row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
            row.querySelectorAll('[data-dimension-fields]').forEach(group => {
                group.hidden = true;
                group.querySelectorAll('input, select').forEach(input => { input.disabled = true; });
            });
            body.append(row);
            renumber(body);
            row.querySelector('select, input')?.focus();
        }
        const remove = event.target.closest('[data-remove-line]');
        if (remove) {
            const row = remove.closest('[data-line]');
            const body = row?.parentElement;
            if (body && body.children.length > 1) {
                row.remove();
                renumber(body);
                body.lastElementChild.querySelector('select, input')?.focus();
            }
        }
    });

    function renumber(body) {
        Array.from(body.children).forEach((row, index) => {
            row.querySelectorAll('[name]').forEach(input => {
                input.name = input.name.replace(/\[\d+\]/, `[${index}]`);
            });
        });
    }

    document.addEventListener('change', event => {
        if (event.target.matches('[data-optional-lines]')) {
            const group = event.target.closest('section')?.querySelector('[data-optional-group]');
            if (group) {
                group.hidden = !event.target.checked;
                group.disabled = !event.target.checked;
            }
        }
        if (event.target.matches('[data-dimensions]')) {
            const group = event.target.closest('details')?.querySelector('[data-dimension-fields]');
            if (group) {
                group.hidden = !event.target.checked;
                group.querySelectorAll('input, select').forEach(input => { input.disabled = !event.target.checked; });
            }
        }
    });

    // Reusable initialization function for initial load and SPA navigation
    window.initOperations = function() {
        document.querySelectorAll('form:not(#attribute-form)').forEach(form => {
            if (form.dataset.bound) return;
            form.dataset.bound = 'true';
            form.addEventListener('submit', event => {
                if (form.id.startsWith('profile-') && !confirm('Apply this profile and subtype? Optional modules and product attributes reset to the profile preset. Existing products, stock and accounting values stay unchanged.')) {
                    event.preventDefault();
                    return;
                }
                form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => { button.disabled = true; });
            });
            form.addEventListener('keydown', event => {
                if (event.ctrlKey && event.key === 'Enter') {
                    event.preventDefault();
                    form.requestSubmit();
                }
            });
        });

        const product = document.getElementById('attribute-product');
        const form = document.getElementById('attribute-form');
        if (product && form && !product.dataset.bound) {
            product.dataset.bound = 'true';
            const fields = document.getElementById('attribute-fields');
            const status = document.getElementById('attribute-status');
            let sequence = 0;

            async function request(values) {
                const response = await fetch(`/operations/product/${product.value}/attributes`, {
                    method: values ? 'POST' : 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    ...(values ? {body: JSON.stringify({attributes: values})} : {})
                });
                const result = await response.json();
                if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message);
                return result.data;
            }

            product.addEventListener('change', async () => {
                const current = ++sequence;
                fields.replaceChildren();
                form.querySelector('button').disabled = true;
                if (!product.value) return;
                status.textContent = 'Loading attributes…';
                try {
                    const attributes = await request();
                    if (current !== sequence) return;
                    attributes.forEach(attribute => {
                        const label = document.createElement('label');
                        label.textContent = attribute.label;
                        const input = document.createElement('input');
                        input.name = attribute.key;
                        input.value = attribute.value ?? '';
                        input.type = attribute.type === 'text' ? 'text' : 'number';
                        if (input.type === 'number') {
                            input.min = '0';
                            input.step = attribute.type === 'integer' ? '1' : 'any';
                        }
                        label.append(input);
                        fields.append(label);
                    });
                    form.querySelector('button').disabled = attributes.length === 0;
                    status.textContent = attributes.length ? 'Edit attributes, then save.' : 'No profile attributes enabled.';
                } catch (error) {
                    if (current === sequence) status.textContent = error.message;
                }
            });

            form.addEventListener('submit', async event => {
                event.preventDefault();
                const values = Object.fromEntries(Array.from(new FormData(form)).filter(([,value]) => value !== ''));
                form.querySelector('button').disabled = true;
                try {
                    await request(values);
                    status.textContent = 'Attributes saved.';
                } catch(error) {
                    status.textContent = error.message;
                } finally {
                    form.querySelector('button').disabled = false;
                }
            });
        }
        // A failed job work action comes back with its panel open, so the operator sees the form and the error together.
        const workspace = document.querySelector('.jw-workspace[data-open-form]');
        if (workspace && !workspace.dataset.opened) {
            workspace.dataset.opened = 'true';
            const panel = document.getElementById(workspace.dataset.openForm)?.closest('.jw-panel');
            if (panel) togglePanel(panel.id, true);
        }
        document.querySelector('.ops-errors')?.focus();

        if (document.querySelector('.jw-workspace, .ops-hub-view')) {
            document.documentElement.classList.add('jw-zero-scroll-html');
            document.body.classList.add('jw-zero-scroll-body');
        } else {
            document.documentElement.classList.remove('jw-zero-scroll-html');
            document.body.classList.remove('jw-zero-scroll-body');
        }
    };

    // Job work actions (Receive, Return material, Send for rework, Service bill, Reverse) open their form in place, one at a time per document.
    function togglePanel(id, open) {
        const panel = document.getElementById(id);
        if (!panel) return;
        const show = open ?? panel.hidden;
        const scope = panel.parentElement;
        scope.querySelectorAll(':scope > .jw-panel').forEach(other => { if (other !== panel) other.hidden = true; });
        panel.hidden = !show;
        document.querySelectorAll('[data-jw-toggle]').forEach(button => {
            const target = document.getElementById(button.dataset.jwToggle);
            button.setAttribute('aria-expanded', target && !target.hidden ? 'true' : 'false');
        });
        if (show) {
            if (id === 'new-dc') {
                const grid = document.getElementById('jw-content-grid');
                if (grid && grid.classList.contains('mode-grn')) {
                    switchJwTab('tab-dc');
                }
            }
            panel.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            panel.querySelector('input:not([type="hidden"]), select')?.focus({preventScroll: true});
            const billForm = panel.querySelector('[data-bill-preview]')?.closest('form');
            if (billForm) previewServiceBill(billForm);
        }
    }

    function switchJwTab(tabId) {
        const grid = document.getElementById('jw-content-grid');
        if (!grid) return;

        document.querySelectorAll('[data-jw-tab]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.jwTab === tabId);
        });

        grid.classList.remove('mode-dc', 'mode-grn', 'mode-split');
        if (tabId === 'tab-grn') {
            grid.classList.add('mode-grn');
        } else if (tabId === 'tab-split') {
            grid.classList.add('mode-split');
        } else {
            grid.classList.add('mode-dc');
        }

        try { localStorage.setItem('zolo_jw_tab', tabId); } catch(e) {}
    }

    document.addEventListener('click', event => {
        const tabBtn = event.target.closest('[data-jw-tab]');
        if (tabBtn) {
            event.preventDefault();
            switchJwTab(tabBtn.dataset.jwTab);
            return;
        }

        const stepItem = event.target.closest('.jw-pipeline-stepper li[data-target-tab]');
        if (stepItem) {
            event.preventDefault();
            switchJwTab(stepItem.dataset.targetTab);
            return;
        }

        const closeBtn = event.target.closest('[data-jw-close]');
        if (closeBtn) {
            event.preventDefault();
            togglePanel(closeBtn.dataset.jwClose, false);
            return;
        }

        const toggle = event.target.closest('[data-jw-toggle]');
        if (toggle) {
            event.preventDefault();
            togglePanel(toggle.dataset.jwToggle);
            return;
        }
    });

    // Service bill: the server prices the charge with the job worker's GST (same rules as the bill it will post).
    const previewTimers = new WeakMap();
    function previewServiceBill(form) {
        const out = form.querySelector('[data-bill-preview]');
        if (!out) return;
        const field = name => form.querySelector(`[name="${name}"]`)?.value || '';
        const item = {product_id: field('items[0][product_id]'), qty: field('items[0][qty]'), net_unit_cost: field('items[0][net_unit_cost]')};
        const gstField = form.querySelector('[data-bill-gst]'), totalField = form.querySelector('[data-bill-total]');
        const setFields = (gst, total) => { if (gstField) gstField.value = gst; if (totalField) totalField.value = total; };
        if (!item.product_id || !item.qty || item.net_unit_cost === '') {
            out.className = 'small mt-2 mb-0 text-muted';
            out.textContent = 'Pick the processing charge item and enter the rate to see GST and the bill total.';
            setFields('', '');
            return;
        }
        const money = n => '₹' + Number(n).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        fetch(out.dataset.url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
            body: JSON.stringify({supplier_id: out.dataset.supplier, business_date: field('business_date'), items: [item]})})
            .then(response => response.json().then(body => ({ok: response.ok, body})))
            .then(({ok, body}) => {
                if (!ok) { out.className = 'small mt-2 mb-0 text-danger'; out.textContent = body.message || 'Could not price this bill.'; setFields('', ''); return; }
                const line = body.data.items?.[0] || {}, snap = line._tax_snapshot || {};
                const split = Number(snap.igst) > 0 ? 'IGST' : (Number(snap.cgst) > 0 ? `CGST ${line.tax_rate / 2}% + SGST ${line.tax_rate / 2}%` : '');
                setFields(`${line.tax_rate ?? 0}% · ${money(body.data.total_tax)}`, money(body.data.grand_total));
                out.className = 'small mt-2 mb-0';
                out.textContent = `Charge ${money(Number(body.data.grand_total) - Number(body.data.total_tax))} + GST ${line.tax_rate ?? 0}%${split ? ' (' + split + ')' : ''} ${money(body.data.total_tax)} = Bill total ${money(body.data.grand_total)}`;
            })
            .catch(() => { out.textContent = ''; });
    }
    document.addEventListener('input', event => {
        const form = event.target.closest('form');
        if (!form?.querySelector('[data-bill-preview]')) return;
        clearTimeout(previewTimers.get(form));
        previewTimers.set(form, setTimeout(() => previewServiceBill(form), 300));
    });
    document.addEventListener('change', event => {
        const form = event.target.closest('form');
        if (form?.querySelector('[data-bill-preview]')) previewServiceBill(form);
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const panel = event.target.closest?.('.jw-panel');
        if (!panel || panel.hidden) return;
        togglePanel(panel.id, false);
        document.querySelector(`[data-jw-toggle="${panel.id}"]`)?.focus();
    });

    // Auto-init on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.initOperations();
            try {
                const saved = localStorage.getItem('zolo_jw_tab');
                if (saved && ['tab-dc', 'tab-grn', 'tab-split'].includes(saved)) {
                    switchJwTab(saved);
                }
            } catch(e) {}
        });
    } else {
        window.initOperations();
        try {
            const saved = localStorage.getItem('zolo_jw_tab');
            if (saved && ['tab-dc', 'tab-grn', 'tab-split'].includes(saved)) {
                switchJwTab(saved);
            }
        } catch(e) {}
    }
})();
