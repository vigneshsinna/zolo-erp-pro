/**
 * Report and list printing: every report prints as a document, not a screenshot of the screen.
 *
 * - DataTables' Print button (and every page action that calls it) prints all matching rows, not just the
 *   page on screen, as a clean table under the company letterhead, report title and the filters used.
 * - Command-bar buttons marked [data-erp-print] print the page's table the same way; pages without a
 *   table fall back to window.print(), where erp-print.css lays the page out under the same letterhead.
 * Output goes through a hidden iframe, so no popup window is needed.
 */
(function ($) {
    'use strict';

    var source = document.currentScript;
    var styles = (source.dataset.css || '').split(' ').filter(Boolean);
    var print = $.fn.dataTable && $.fn.dataTable.ext.buttons.print;
    var NUMBER = /^[-+(]?\s*(₹|Rs\.?|INR)?\s*[-+]?[\d,]*\.?\d+\s*%?\)?$/i;
    var SKIP_HEADER = /^(action|actions|option|options)?$/i;
    var IDENTIFIER = /phone|mobile|code|\bno\b|number|\bid\b|year|pin|gst|hsn|sac|ref/i;

    function esc(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function clean(text) {
        return String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
    }

    /** Cell HTML as text, with line breaks inside a cell ("Name<br>Code") kept apart by a dot. */
    function text(html) {
        var holder = document.createElement('template');
        holder.innerHTML = String(html == null ? '' : html).replace(/<br\s*\/?>|<\/(div|p|li)>/gi, ' · ');
        return clean(holder.content.textContent).replace(/^(·\s*)+|(\s*·)+$/g, '').replace(/·(\s*·)+/g, '·');
    }

    function title() {
        // Workspace bars (Settings, Products, Purchase) name the workspace in the heading and the screen in the current tab.
        var current = document.querySelector('#content .comm-nav-pills [aria-current="page"]');
        if (current && clean(current.textContent)) return clean(current.textContent);
        var heading = document.querySelector('#content .comm-title-group h1, #content h1, #content .page-title');
        var text = clean(heading ? heading.textContent : document.title).replace(/\s*command\s*cent(er|re)\s*/i, ' ');
        return clean(text) || document.title;
    }

    /** "Label: value" for each filter the report was run with, read from the page's filter card. */
    function filters() {
        var seen = {}, out = [];
        $('#content .desk-filter-card, #content .report-filter-card').find('select, input').each(function () {
            if (/^(hidden|submit|button|checkbox|radio|search)$/i.test(this.type) || $(this).closest('.bootstrap-select .dropdown-menu').length) return;
            // Label text without its help icon, and "Choose your date" reads "Date".
            var label = $(this).closest('.form-group, [class*="col-"]').find('label').first().clone();
            label.find('i, svg, button, [class*="help"]').remove();
            label = clean(label.text() || $(this).attr('placeholder') || '').replace(/^choose\s+(your\s+)?/i, '');
            label = label.charAt(0).toUpperCase() + label.slice(1);
            var value = this.tagName === 'SELECT'
                ? $(this).find('option:selected').map(function () { return clean(this.textContent); }).get().join(', ')
                : clean(this.value);
            if (!label || !value || seen[label]) return;
            seen[label] = true;
            out.push('<span><b>' + esc(label.replace(/[:*]+$/, '')) + ':</b> ' + esc(value) + '</span>');
        });
        return out.join('');
    }

    /** The letterhead, filled in for this print. */
    function header(name) {
        var head = document.querySelector('.page .erp-print-header');
        if (!head) return '';
        $(head).find('[data-erp-print-title]').text(name || title());
        $(head).find('[data-erp-print-meta]').html(filters());
        $(head).find('[data-erp-print-time]').text(new Date().toLocaleString('en-IN', {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
        }));
        return head.outerHTML;
    }

    function printHtml(body, wide, name) {
        var frame = document.createElement('iframe');
        frame.className = 'erp-print-frame';
        frame.setAttribute('aria-hidden', 'true');
        document.body.appendChild(frame);
        var doc = frame.contentDocument;
        doc.open();
        doc.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(name || title()) + '</title>'
            + styles.map(function (href) { return '<link rel="stylesheet" href="' + esc(href) + '">'; }).join('')
            + '</head><body class="erp-print-doc' + (wide ? ' erp-print-wide' : '') + '">' + body + '</body></html>');
        doc.close();
        var done = false;
        function go() {
            if (done) return;
            done = true;
            frame.contentWindow.focus();
            frame.contentWindow.print();
            setTimeout(function () { frame.remove(); }, 500);
        }
        frame.onload = go;
        setTimeout(go, 2000);
    }

    function render(dt, config) {
        var data = dt.buttons.exportData($.extend({columns: ':visible:not(.not-exported)'}, config.exportOptions, {stripHtml: false, decodeEntities: false}));
        var rows = function (list) { return list.map(function (value) { return text(value); }); };
        table(rows(data.header), data.body.map(rows), config.footer !== false && data.footer ? rows(data.footer) : null);
    }

    /** Prints rows of plain text under the letterhead; name overrides the page's report title. */
    function table(head, body, footer, name) {
        // Drop the checkbox and action columns: no header text, or "Action".
        var cols = head.map(function (title, i) { return i; }).filter(function (i) { return !SKIP_HEADER.test(head[i]); });
        var numeric = cols.map(function (i) {
            var cells = body.map(function (row) { return row[i]; }).filter(Boolean);
            return cells.length > 0 && cells.every(function (value) { return NUMBER.test(value); });
        });
        // Amounts and quantities get Indian digit grouping; codes, phone numbers and the like stay as typed.
        var grouped = cols.map(function (i, n) { return numeric[n] && !IDENTIFIER.test(head[i]); });
        var cell = function (tag, value, n) {
            if (grouped[n] && /^-?\d+(\.\d+)?$/.test(value)) {
                var decimals = (value.split('.')[1] || '').length;
                value = Number(value).toLocaleString('en-IN', {minimumFractionDigits: decimals, maximumFractionDigits: decimals});
            }
            return '<' + tag + (numeric[n] ? ' class="num"' : '') + '>' + esc(value) + '</' + tag + '>';
        };
        var html = '<table class="erp-print-table"><thead><tr>'
            + cols.map(function (i, n) { return cell('th', head[i], n); }).join('') + '</tr></thead><tbody>';
        html += body.length
            ? body.map(function (row) { return '<tr>' + cols.map(function (i, n) { return cell('td', row[i], n); }).join('') + '</tr>'; }).join('')
            : '<tr><td class="erp-print-empty" colspan="' + cols.length + '">No records for the selected filters.</td></tr>';
        html += '</tbody>';
        if (footer && footer.some(Boolean)) {
            html += '<tfoot><tr>' + cols.map(function (i, n) { return cell('td', footer[i], n); }).join('') + '</tr></tfoot>';
        }
        html += '</table><div class="erp-print-end"><span>' + body.length + ' record' + (body.length === 1 ? '' : 's') + '</span><span>— End of report —</span></div>';
        printHtml(header(name) + html, cols.length > 7, name);
    }

    window.ErpPrint = {table: table};

    if (print) {
        // Server-side tables only hold the page on screen: load every matching row, print, then put the page back.
        print.action = function (e, dt, button, config) {
            var info = dt.page.info();
            var selected = dt.rows({selected: true}).any && dt.rows({selected: true}).any();
            if (!info.serverSide || selected || info.length === -1 || info.recordsDisplay <= info.end - info.start) {
                render(dt, config);
                return;
            }
            var length = info.length, page = info.page;
            dt.one('draw', function () {
                render(dt, config);
                dt.page.len(length).page(page).draw('page');
            });
            dt.page.len(-1).draw('page');
        };
    }

    $(document).on('click', '[data-erp-print]', function (event) {
        event.preventDefault();
        var tables = $.fn.dataTable ? $.fn.dataTable.tables({visible: true}) : [];
        if (tables.length) {
            var dt = $(tables[0]).DataTable();
            var button = dt.buttons ? dt.buttons('.buttons-print') : null;
            if (button && button.length) button.trigger();
            else print.action.call(dt, event, dt, null, {footer: true});
            return;
        }
        header();
        window.print();
    });

    window.addEventListener('beforeprint', function () { header(); });
})(jQuery);
