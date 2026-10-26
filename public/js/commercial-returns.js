/* Returns tab: a debit (purchase) or credit (sale) note against one posted bill, shown in place of the bill voucher. */
(function ($) {
    'use strict';
    $(function () {
        const root = document.getElementById('return-workspace');
        if (!root) return;

        const kind = root.dataset.kind, base = root.dataset.base, sale = kind === 'sale';
        const grid = document.getElementById('comm-split-grid');
        const form = document.getElementById('return-entry-form');
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const noteName = sale ? 'credit note' : 'debit note';
        const conditions = {restock: 'Back to stock', damaged: 'Damaged', expired: 'Expired', quality_reject: 'Quality reject', quarantine: 'Quarantine'};
        const $lines = $('#return-lines'), $search = $('#return-bill-search'), $results = $('#return-bill-results');
        const $feedback = $('#return-feedback'), defaultFeedback = $feedback.text();
        let source = null, type = 'quantity', key = newKey(), saving = false, searchTimer = null, searchGen = 0, active = -1;

        function newKey() {
            return window.crypto?.randomUUID ? window.crypto.randomUUID()
                : 'r-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
        }
        const money = n => '₹ ' + Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const qtyText = n => Number(n || 0).toLocaleString('en-IN', {maximumFractionDigits: 4});
        const error = xhr => {
            const errors = xhr.responseJSON?.errors;
            if (errors) return Object.values(errors).flat()[0];
            return xhr.responseJSON?.message || 'Could not save. Try again.';
        };
        const say = (message, tone) => $feedback.text(message).removeClass('is-error is-ok').addClass(tone ? 'is-' + tone : '');

        // ---- Tab mode -------------------------------------------------------------------------
        function setUrl(on) {
            if (!window.history?.pushState) return;
            const url = new URL(window.location.href);
            if (on) { url.searchParams.set('returns', '1'); url.searchParams.delete('orders'); url.searchParams.delete('view'); }
            else url.searchParams.delete('returns');
            window.history.pushState({mode: on ? 'returns' : 'bills'}, '', url.toString());
        }

        function enter(push) {
            grid.classList.add('is-returns');
            $('.command-center-nav a').removeAttr('aria-current');
            $('[data-nav-tab="returns"]').attr('aria-current', 'page');
            $('#doc-title-text').text('New ' + (sale ? 'Sales' : 'Purchase') + ' Return');
            $('#doc-breadcrumb-mode').text(sale ? 'Credit Note' : 'Debit Note');
            $('.comm-drawer-title').html('<i class="dripicons-return text-primary"></i> ' + (sale ? 'Sales' : 'Purchase') + ' Returns');
            if (push) setUrl(true);
            loadNotes();
            if (!source) setTimeout(() => $search.trigger('focus'), 50);
        }

        function exit() {
            grid.classList.remove('is-returns');
            $('[data-nav-tab="returns"]').removeAttr('aria-current');
            closeResults();
        }

        // ---- Bill picker ----------------------------------------------------------------------
        function closeResults() {
            clearTimeout(searchTimer); searchGen++; // a search still in flight must not reopen the list
            $results.prop('hidden', true).empty(); $search.attr('aria-expanded', 'false'); active = -1;
        }

        function searchBills() {
            const current = ++searchGen;
            $.getJSON(base + '/' + kind + '/returnable', {q: $search.val().trim()}).done(({data}) => {
                if (current !== searchGen) return;
                $results.empty();
                if (!data.length) $('<li class="is-empty">').text('No posted bills match.').appendTo($results);
                data.forEach(bill => {
                    $('<li role="option">').attr('data-id', bill.id).append(
                        $('<strong>').text(bill.reference_no), $('<span>').text(money(bill.grand_total)),
                        $('<small>').text(bill.party || ''), $('<small>').text(bill.date)
                    ).appendTo($results);
                });
                active = -1;
                $results.prop('hidden', false);
                $search.attr('aria-expanded', 'true');
            }).fail(xhr => say(error(xhr), 'error'));
        }

        function highlight(step) {
            const $items = $results.children('[data-id]');
            if (!$items.length) return;
            active = (active + step + $items.length) % $items.length;
            $items.attr('aria-selected', 'false').eq(active).attr('aria-selected', 'true')[0].scrollIntoView({block: 'nearest'});
        }

        $search.on('focus', () => { if ($results.prop('hidden')) searchBills(); });
        $search.on('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(searchBills, 200); });
        $search.on('keydown', e => {
            if (e.key === 'ArrowDown') { e.preventDefault(); if ($results.prop('hidden')) searchBills(); else highlight(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(-1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                const $pick = active >= 0 ? $results.children('[data-id]').eq(active) : $results.children('[data-id]').first();
                if ($pick.length) loadBill($pick.data('id'));
            } else if (e.key === 'Escape') closeResults();
        });
        $results.on('mousedown', 'li[data-id]', function (e) { e.preventDefault(); loadBill($(this).data('id')); });
        $search.on('blur', () => setTimeout(closeResults, 150));

        // ---- Lines ----------------------------------------------------------------------------
        function loadBill(id) {
            closeResults();
            say('Loading bill…');
            $.getJSON(base + '/' + kind + '/' + id + '/return').done(({data}) => {
                source = data; key = newKey();
                $search.val(data.source.reference_no);
                $('#return-party').text(data.source.party || '—');
                $('#return-bill-meta').text('Bill ' + data.source.date + ' · ' + money(data.source.grand_total));
                $('#return-date').attr('min', data.source.date);
                renderLines();
                say(defaultFeedback);
                $lines.find('.return-input:enabled').first().trigger('focus');
            }).fail(xhr => say(error(xhr), 'error'));
        }

        function quarantineWarehouse() { return source.warehouses.find(w => w.quarantine)?.id || null; }

        function renderLines() {
            $lines.empty();
            const valueMode = type !== 'quantity';
            $('#return-input-head').text(valueMode ? 'AMOUNT (PRE-TAX)' : 'RETURN QTY');
            source.lines.forEach((line, i) => {
                const open = Math.max(0, line.qty - line.returned);
                const done = !valueMode && open <= 0;
                const rate = line.qty ? (line.total - line.tax) / line.qty : 0;
                const $input = $('<input type="number" class="form-control return-input" min="0" step="any" placeholder="0">')
                    .attr('aria-label', (valueMode ? 'Amount for ' : 'Return quantity for ') + line.product)
                    .prop('disabled', done);
                if (!valueMode) $input.attr('max', open);
                const $item = $('<td>').append($('<strong>').text(line.product || ('Line ' + line.id)), ' ', $('<small class="text-muted">').text(line.code || ''));
                if (!valueMode && line.identities.length) {
                    const $ids = $('<div class="return-identities">');
                    line.identities.forEach(identity => $('<label>').append(
                        $('<input type="checkbox" class="return-identity">').val(identity.id), document.createTextNode(identity.label)).appendTo($ids));
                    $item.append($ids);
                }
                const $row = $('<tr class="return-line-row">').attr('data-index', i).toggleClass('is-done', done).append(
                    $('<td class="u-ta-center">').text(i + 1), $item, $('<td>').text(line.unit),
                    $('<td class="u-ta-right">').text(qtyText(line.qty)), $('<td class="u-ta-right">').text(qtyText(line.returned)),
                    $('<td class="u-ta-right">').text(money(rate)), $('<td>').append($input));
                if (sale) {
                    const $condition = $('<select class="form-control return-condition">').attr('aria-label', 'Condition of ' + line.product).prop('disabled', valueMode || done);
                    Object.entries(conditions).forEach(([value, label]) => $('<option>').val(value).text(label).appendTo($condition));
                    $row.append($('<td>').append($condition));
                }
                $row.append($('<td class="u-ta-right return-line-tax">').text(money(0)), $('<td class="u-ta-right return-line-total">').text(money(0)));
                $lines.append($row);
            });
            $('#return-line-count').text(source.lines.length + ' line(s)');
            recalc();
        }

        function lineValues($row) {
            const line = source.lines[$row.data('index')];
            const value = parseFloat($row.find('.return-input').val()) || 0;
            if (value <= 0) return {base: 0, tax: 0, value: 0};
            if (type === 'quantity') {
                const share = line.qty ? value / line.qty : 0;
                return {base: (line.total - line.tax) * share, tax: line.tax * share, value};
            }
            return {base: value, tax: value * line.tax_rate / 100, value};
        }

        function recalc() {
            let net = 0, tax = 0;
            $lines.find('.return-line-row').each(function () {
                const $row = $(this), v = lineValues($row);
                net += v.base; tax += v.tax;
                $row.toggleClass('is-picked', v.value > 0);
                $row.find('.return-line-tax').text(money(v.tax));
                $row.find('.return-line-total').text(money(v.base + v.tax));
            });
            $('#return-net').text(money(net));
            $('#return-tax').text(money(tax));
            $('#return-total').text(money(net + tax));
        }

        $lines.on('input change', '.return-input', function () {
            const max = parseFloat(this.max);
            if (type === 'quantity' && !isNaN(max) && parseFloat(this.value) > max) this.value = max;
            recalc();
        });
        $lines.on('keydown', '.return-input', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const $inputs = $lines.find('.return-input:enabled'), next = $inputs.index(this) + 1;
            if (next < $inputs.length) $inputs.eq(next).trigger('focus').trigger('select');
            else $('#return-reason').trigger('focus');
        });

        $('[data-return-type]').on('click', function () {
            type = this.dataset.returnType;
            $('[data-return-type]').removeClass('active');
            $(this).addClass('active');
            if (source) renderLines();
        });

        $('#btn-return-all').on('click', () => {
            if (!source || type !== 'quantity') return;
            $lines.find('.return-line-row').each(function () {
                const line = source.lines[$(this).data('index')];
                const open = Math.max(0, line.qty - line.returned);
                if (open > 0) $(this).find('.return-input').val(open);
            });
            recalc();
        });
        $('#btn-return-none').on('click', () => { $lines.find('.return-input').val(''); recalc(); });

        function reset() {
            source = null; key = newKey();
            $search.val(''); $('#return-reason').val('');
            $('#return-party').text('—'); $('#return-bill-meta').text('Pick a bill to load its items');
            $('#return-date').removeAttr('min');
            $lines.html('<tr class="empty-placeholder-row"><td colspan="' + (sale ? 10 : 9) + '" class="text-center text-muted py-4 u-fs-12px">Pick the bill above (or press <strong>Return</strong> on a bill in the list). Its items load here; enter what comes back.</td></tr>');
            $('#return-line-count').text('0 line(s)');
            recalc();
        }
        $('#btn-return-discard, #btn-side-new-return').on('click', () => { reset(); say(defaultFeedback); $search.trigger('focus'); });

        // ---- Save -----------------------------------------------------------------------------
        $(form).on('submit', function (e) {
            e.preventDefault();
            if (saving) return;
            if (!source) { say('Pick the bill first.', 'error'); $search.trigger('focus'); return; }
            const reason = $('#return-reason').val().trim();
            const items = [];
            let problem = null;
            $lines.find('.return-line-row').each(function () {
                const $row = $(this), line = source.lines[$row.data('index')], value = parseFloat($row.find('.return-input').val()) || 0;
                if (value <= 0) return;
                const item = {line_id: line.id};
                if (type === 'quantity') {
                    item.qty = value;
                    const ids = $row.find('.return-identity:checked').map((_, el) => Number(el.value)).get();
                    if (ids.length) item.stock_line_ids = ids;
                    if (sale) {
                        item.disposition = $row.find('.return-condition').val();
                        item.warehouse_id = item.disposition === 'restock' ? source.source.warehouse_id : quarantineWarehouse();
                        if (!item.warehouse_id) problem = 'Damaged or rejected goods need a quarantine warehouse. Add one in Warehouse settings, or choose "Back to stock".';
                    }
                } else item.amount = value;
                items.push(item);
            });
            if (problem) { say(problem, 'error'); return; }
            if (!items.length) { say(type === 'quantity' ? 'Enter the quantity coming back on at least one item.' : 'Enter the amount on at least one item.', 'error'); return; }
            if (reason.length < 3) { say('Enter a reason (at least 3 letters).', 'error'); $('#return-reason').trigger('focus'); return; }

            saving = true;
            $('#btn-return-save').prop('disabled', true);
            say('Saving ' + noteName + '…');
            $.ajax({
                url: base + '/' + kind + '/' + source.source.id + '/notes', method: 'POST', contentType: 'application/json',
                headers: {'X-CSRF-TOKEN': token, 'Idempotency-Key': key, Accept: 'application/json'},
                data: JSON.stringify({business_date: $('#return-date').val(), reason, note_type: sale ? 'credit' : 'debit', adjustment_type: type, items}),
            }).done(({data}) => {
                const posted = !!data.posted_at;
                const ref = posted ? data.reference_no : 'for ' + source.source.reference_no;
                reset();
                say((sale ? 'Credit' : 'Debit') + ' note ' + ref + (posted ? ' posted — stock and accounts updated.' : ' saved, waiting for approval (see the list on the right).'), 'ok');
                loadNotes();
            }).fail(xhr => say(error(xhr), 'error'))
              .always(() => { saving = false; $('#btn-return-save').prop('disabled', false); });
        });

        // ---- Side list of notes ---------------------------------------------------------------
        function loadNotes() {
            const $status = $('#side-returns-status'), $cards = $('#side-returns-cards');
            $status.text('Loading…');
            $.getJSON(root.dataset.listUrl).done(({data}) => {
                const notes = (sale ? data.sales : data.purchases) || [];
                $cards.empty();
                notes.forEach(note => {
                    const awaiting = note.status === 'awaiting_approval';
                    const docKind = kind + '_note';
                    const $card = $('<div class="side-bill-card side-return-card">').attr('data-note-id', note.id);
                    $card.append(
                        $('<div class="side-card-top">').append($('<strong class="side-card-ref">').text(note.posted_at ? note.reference_no : 'Pending note'),
                            $('<span class="side-card-amount">').text(money(note.grand_total))),
                        $('<div class="side-card-party">').text('Against ' + (note.attributes_json?.source_no || '—') + ' · ' + (note.return_note || '')),
                        $('<div class="side-card-bottom">').append($('<span class="side-card-date">').text(note.created_at ? new Date(note.created_at).toLocaleDateString('en-CA') : ''),
                            $('<span class="side-card-status">').addClass(awaiting ? 'awaiting' : 'posted').text(awaiting ? 'Awaiting approval' : (note.posted_at ? 'Posted' : note.status))));
                    const $actions = $('<div class="side-card-actions">');
                    if (awaiting) $('<a href="javascript:void(0)" class="side-action-btn edit btn-side-approve-note">').attr('data-id', note.id).html('<i class="dripicons-checkmark"></i> Approve').appendTo($actions);
                    if (note.posted_at) {
                        $('<a href="javascript:void(0)" class="side-action-btn view btn-side-view-note">').attr({'data-id': note.id, 'data-reason': note.return_note || ''}).html('<i class="dripicons-preview"></i> View').appendTo($actions);
                        $('<a href="javascript:void(0)" class="side-action-btn print btn-side-print-note">').attr('data-id', note.id).html('<i class="dripicons-print"></i> Print').appendTo($actions);
                    }
                    $card.append($actions).appendTo($cards);
                });
                $status.text(notes.length ? notes.length + ' note(s) this year' : 'No returns recorded yet.');
            }).fail(xhr => $status.text(error(xhr)));
        }

        $(document).on('click', '.btn-side-approve-note', function (e) {
            e.preventDefault();
            const $btn = $(this).addClass('u-pe-none');
            $.ajax({url: base + '/' + kind + '/notes/' + $btn.data('id') + '/approve', method: 'POST', headers: {'X-CSRF-TOKEN': token, Accept: 'application/json'}})
                .done(({data}) => { say((sale ? 'Credit' : 'Debit') + ' note ' + data.reference_no + ' approved and posted.', 'ok'); loadNotes(); })
                .fail(xhr => { say(error(xhr), 'error'); $btn.removeClass('u-pe-none'); });
        });

        // View opens on this page, like a bill's View; Print opens the A4 note, like a bill's Print.
        const $noteModal = $('#return-note-modal').appendTo('body');
        const printUrl = id => base + '/documents/' + kind + '_note/' + id + '/print?format=a4';
        let viewing = null;
        $(document).on('click', '.btn-side-view-note', function (e) {
            e.preventDefault(); e.stopPropagation();
            const id = $(this).data('id'), reason = $(this).data('reason');
            $.getJSON(base + '/documents/' + kind + '_note/' + id).done(({data}) => {
                const doc = data.document, field = name => $noteModal.find('[data-note="' + name + '"]');
                viewing = id;
                $('#return-note-title').text(doc.title + ' ' + doc.number);
                field('party').text(doc.party_name || '—');
                field('gstin').text(doc.party_gstin ? 'GSTIN ' + doc.party_gstin : '');
                field('source').text(doc.source_number || '—');
                field('date').text(doc.date);
                field('reason').text(reason || '—');
                const $body = field('lines').empty();
                let taxable = 0;
                doc.lines.forEach((line, i) => {
                    taxable += Number(line.taxable_value || 0);
                    $('<tr>').append(
                        $('<td>').text(i + 1), $('<td>').text(line.name + (line.code ? ' · ' + line.code : '')), $('<td>').text(line.hsn_sac || ''),
                        $('<td class="u-ta-right">').text(qtyText(line.qty) + ' ' + (line.unit || '')), $('<td class="u-ta-right">').text(money(line.price)),
                        $('<td class="u-ta-right">').text(money(line.taxable_value)), $('<td class="u-ta-right">').text(money(line.tax) + ' (' + line.tax_rate + '%)'),
                        $('<td class="u-ta-right">').text(money(line.total))).appendTo($body);
                });
                field('taxable').text(money(taxable));
                field('tax').text(money(doc.total_tax));
                field('total').text(money(doc.grand_total));
                $noteModal.modal('show');
            }).fail(xhr => say(error(xhr), 'error'));
        });
        $(document).on('click', '.btn-side-print-note', function (e) {
            e.preventDefault(); e.stopPropagation();
            window.open(printUrl($(this).data('id')), '_blank');
        });
        $('#btn-return-note-print').on('click', () => { if (viewing) window.open(printUrl(viewing), '_blank'); });

        // ---- Entry points ---------------------------------------------------------------------
        $(document).on('click', '[data-nav-tab="returns"]', e => { e.preventDefault(); enter(true); });
        $(document).on('click', '[data-nav-tab="bills"], [data-nav-tab="orders"], [data-order-register]', exit);
        $(document).on('click', '.btn-side-return', function (e) {
            e.preventDefault();
            e.stopPropagation(); // the card itself opens the bill for editing
            enter(true);
            loadBill($(this).data('id'));
        });
        window.addEventListener('popstate', () => {
            if (new URLSearchParams(window.location.search).get('returns') === '1') enter(false); else exit();
        });

        const params = new URLSearchParams(window.location.search);
        if (params.get('returns') === '1') {
            enter(false);
            if (params.get('bill')) loadBill(Number(params.get('bill')));
        }
    });
})(window.jQuery);
