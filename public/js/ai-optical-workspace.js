(function () {
    'use strict';

    var panel = document.getElementById('ai-optical-lens-panel');
    if (!panel) {
        return;
    }

    var toggleBtn = document.getElementById('ai-optical-toggle-btn');
    var bodyEl = document.getElementById('ai-optical-panel-body');
    var routeEl = document.getElementById('ai-optical-route-select');
    var brandEl = document.getElementById('ai-optical-brand-input');
    var coatingEl = document.getElementById('ai-optical-coating-input');
    var partyEl = document.getElementById('ai-optical-party-input');
    var pasteEl = document.getElementById('ai-optical-bulk-paste');
    var parseBtn = document.getElementById('ai-optical-parse-btn');
    var dispatchBtn = document.getElementById('ai-optical-dispatch-btn');
    var clearBtn = document.getElementById('ai-optical-clear-btn');
    var statusBadge = document.getElementById('ai-optical-status-badge');
    var previewWrap = document.getElementById('ai-optical-grid-preview');
    var tbodyEl = document.getElementById('ai-optical-grid-tbody');
    var resultEl = document.getElementById('ai-optical-result-output');

    var parsedRows = [];

    function normalizeSignedPower(raw) {
        var val = String(raw || '').trim();
        if (val === '') {
            return '';
        }
        var upper = val.toUpperCase();
        if (upper === 'PLANO' || upper === 'PL' || val === '0' || val === '0.0' || val === '0.00' || val === '+0.00' || val === '-0.00') {
            return '+0.00';
        }
        var num = Number(val);
        if (Number.isNaN(num)) {
            return val;
        }
        if (num === 0) {
            return '+0.00';
        }
        var sign = val.charAt(0) === '-' ? '-' : '+';
        return sign + Math.abs(num).toFixed(2);
    }

    function parseBulkRows(text) {
        var lines = String(text || '').split(/\r?\n/);
        var rows = [];
        for (var i = 0; i < lines.length; i += 1) {
            var line = lines[i].trim();
            if (!line) {
                continue;
            }
            var parts = line.split(/[|,;\t]+/).map(function (p) { return p.trim(); });
            if (parts.length < 1 || !parts[0]) {
                continue;
            }
            var sph = normalizeSignedPower(parts[0]);
            var cyl = parts[1] !== undefined && parts[1] !== '' ? normalizeSignedPower(parts[1]) : '+0.00';
            var axis = parts[2] !== undefined && parts[2] !== '' ? String(parseInt(parts[2], 10) || 0) : '';
            var addition = parts[3] !== undefined && parts[3] !== '' ? normalizeSignedPower(parts[3]) : '';
            var eye = (parts[4] || 'BOTH').toUpperCase();
            var dia = parts[5] || '';
            var qty = parseFloat(parts[6] || '1');
            rows.push({
                sph: sph,
                cyl: cyl,
                axis: axis,
                addition: addition,
                eye: eye,
                dia: dia,
                qty: Number.isNaN(qty) || qty <= 0 ? 1 : qty
            });
        }
        return rows;
    }

    function renderRows(rows) {
        tbodyEl.textContent = '';
        if (!rows.length) {
            previewWrap.classList.add('d-none');
            return;
        }
        rows.forEach(function (row, idx) {
            var tr = document.createElement('tr');
            var cells = [
                String(idx + 1),
                row.sph,
                row.cyl,
                row.axis || '-',
                row.addition || '-',
                row.eye,
                row.dia || '-',
                String(row.qty)
            ];
            cells.forEach(function (val, cIdx) {
                var td = document.createElement('td');
                if ((cIdx === 1 || cIdx === 2 || cIdx === 4) && val !== '-') {
                    var span = document.createElement('span');
                    span.className = val.charAt(0) === '-' ? 'ai-optical-sign-minus' : 'ai-optical-sign-plus';
                    span.textContent = val;
                    td.appendChild(span);
                } else {
                    td.textContent = val;
                }
                tr.appendChild(td);
            });
            tbodyEl.appendChild(tr);
        });
        previewWrap.classList.remove('d-none');
    }

    function clearGrid() {
        parsedRows = [];
        if (pasteEl) {
            pasteEl.value = '';
        }
        renderRows([]);
        if (resultEl) {
            resultEl.textContent = '';
            resultEl.classList.add('d-none');
        }
        if (statusBadge) {
            statusBadge.textContent = 'Ready';
        }
    }

    function injectIntoChatComposer(text) {
        var composer = document.getElementById('prompt-input') || document.querySelector('textarea[name="message"]');
        if (!composer) {
            return;
        }
        composer.value = text;
        composer.dispatchEvent(new Event('input', { bubbles: true }));
        composer.focus();
    }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function buildSummaryPrompt() {
        var route = routeEl ? routeEl.value : 'optical.power_matrix.v1';
        var brand = brandEl ? brandEl.value.trim() : '';
        var coating = coatingEl ? coatingEl.value.trim() : '';
        var party = partyEl ? partyEl.value.trim() : '';
        var rowSummary = parsedRows.map(function (r) {
            return 'SPH ' + r.sph + ', CYL ' + r.cyl + (r.axis ? ', AXIS ' + r.axis : '') +
                (r.addition ? ', ADD ' + r.addition : '') + ', EYE ' + r.eye +
                (r.dia ? ', DIA ' + r.dia : '') + ', QTY ' + r.qty;
        }).join('; ');

        return '[Optical Route: ' + route + '] ' +
            (brand ? 'Lens: ' + brand + '. ' : '') +
            (coating ? 'Coating/Index: ' + coating + '. ' : '') +
            (party ? 'Party/Store: ' + party + '. ' : '') +
            (rowSummary ? 'Lines: ' + rowSummary : '');
    }

    if (toggleBtn && bodyEl) {
        toggleBtn.addEventListener('click', function () {
            var hidden = bodyEl.classList.contains('d-none');
            bodyEl.classList.toggle('d-none', !hidden);
            toggleBtn.setAttribute('aria-expanded', hidden ? 'true' : 'false');
        });
    }

    if (parseBtn) {
        parseBtn.addEventListener('click', function () {
            parsedRows = parseBulkRows(pasteEl ? pasteEl.value : '');
            renderRows(parsedRows);
            statusBadge.textContent = 'Parsed ' + parsedRows.length + ' row(s)';
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearGrid);
    }

    if (dispatchBtn) {
        dispatchBtn.addEventListener('click', function () {
            parsedRows = parseBulkRows(pasteEl ? pasteEl.value : '');
            renderRows(parsedRows);

            var route = routeEl ? routeEl.value : 'optical.power_matrix.v1';
            if (route === 'optical.power_matrix.v1' || route === 'optical.lens_candidates.v1') {
                statusBadge.textContent = 'Checking capability visibility...';
                fetch('/api/v1/ai/capabilities', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken()
                    }
                }).then(function (resp) {
                    return resp.json().then(function (data) {
                        return { ok: resp.ok, status: resp.status, data: data };
                    });
                }).then(function (res) {
                    var list = (res.data && Array.isArray(res.data.capabilities)) ? res.data.capabilities : [];
                    var matched = list.filter(function (item) {
                        return item && item.capability_key === route;
                    });
                    statusBadge.textContent = res.ok
                        ? (matched.length ? 'Visible (' + route + ')' : 'Ready for copilot prompt')
                        : 'Status ' + res.status;
                    resultEl.textContent = JSON.stringify({ capability: route, visible: matched.length > 0, preview_prompt: buildSummaryPrompt() }, null, 2);
                    resultEl.classList.remove('d-none');
                    injectIntoChatComposer(buildSummaryPrompt());
                }).catch(function () {
                    statusBadge.textContent = 'Composed draft prompt in chat';
                    injectIntoChatComposer(buildSummaryPrompt());
                });
                return;
            }

            injectIntoChatComposer(buildSummaryPrompt());
            statusBadge.textContent = 'Composed draft prompt in chat';
        });
    }

    document.addEventListener('zolo:ai:scope-changed', clearGrid);
    if (window.zoloAiWorkspace && typeof window.zoloAiWorkspace.onScopeChange === 'function') {
        window.zoloAiWorkspace.onScopeChange(clearGrid);
    }
})();
