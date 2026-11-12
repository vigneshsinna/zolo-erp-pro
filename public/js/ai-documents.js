(function ($) {
    'use strict';
    const root = $('#ai-documents');
    if (!root.length) return;
    const base = root.attr('data-base-url');
    let document = null;
    let batch = null;
    let busy = false;
    let poll = null;

    function error(message) {
        $('#document-error').text(message).removeClass('d-none');
    }
    function failure(xhr) {
        const data = xhr.responseJSON || {};
        const fields = data.errors ? Object.values(data.errors).flat() : [];
        const message = fields[0] || data.message || 'Document request failed. Refresh status and try again.';
        error(message);
    }
    function request(path, method, data, options) {
        return $.ajax(Object.assign({url: base + path, method: method, data: data,
            headers: {'X-CSRF-TOKEN': root.attr('data-csrf'), 'X-Requested-With': 'XMLHttpRequest'},
            dataType: 'json'}, options || {}));
    }
    function update(data) {
        document = data;
        $('#document-status').text('Document ' + data.id + ' · ' + data.status + ' · Expires ' + data.expires_at);
        $('#document-process, #document-refresh, #document-revoke').prop('disabled', false);
        $('#document-extraction').text(data.extraction ? JSON.stringify(data.extraction, null, 2) : 'Document remains quarantined until scanning completes.');
        $('#document-import-preview').toggleClass('d-none', !(data.classification === 'product_import' && data.status === 'scanned'));
        $('#document-approve-knowledge').toggleClass('d-none', !(data.classification === 'sop' && data.status === 'extracted'));
    }
    function refresh() {
        if (!document) return $.Deferred().resolve().promise();
        return request('/documents/' + encodeURIComponent(document.id), 'GET').done(function (result) { update(result.data); });
    }
    $('#document-upload-form').on('submit', function (event) {
        event.preventDefault();
        if (busy) return;
        const file = $('#document-file')[0].files[0];
        if (!file) return;
        if (file.size > Number(root.attr('data-max-kilobytes')) * 1024) {
            error('File exceeds the upload limit. Choose a smaller file.');
            return;
        }
        $('#document-error').addClass('d-none');
        busy = true;
        clearTimeout(poll);
        request('/documents', 'POST', new FormData(this), {processData: false, contentType: false})
            .done(function (result) { batch = null; $('#document-import-panel').addClass('d-none'); update(result.data); })
            .fail(failure).always(function () { busy = false; });
    });
    $('#document-process').on('click', function () {
        if (!document || busy) return;
        busy = true;
        $('#document-error').addClass('d-none');
        request('/documents/' + encodeURIComponent(document.id) + '/process', 'POST')
            .done(function () {
                $('#document-status').text('Processing queued. Refresh status after the document worker finishes.');
                refresh().fail(failure);
                let attempts = 0;
                function tick() {
                    if (!document || ['scanned', 'extracted', 'approved'].includes(document.status) || ++attempts > 20) return;
                    refresh().done(function () { poll = setTimeout(tick, 3000); }).fail(failure);
                }
                poll = setTimeout(tick, 3000);
            }).fail(failure).always(function () { busy = false; });
    });
    $('#document-refresh').on('click', function () { refresh().fail(failure); });
    $('#document-revoke').on('click', function () {
        if (!document || busy || !window.confirm('Revoke access to this document and its knowledge excerpts?')) return;
        busy = true;
        request('/documents/' + encodeURIComponent(document.id), 'DELETE').done(function () {
            clearTimeout(poll); document = null; batch = null;
            $('#document-status').text('Document access revoked.');
            $('#document-process, #document-refresh, #document-revoke').prop('disabled', true);
            $('#document-import-panel, #document-import-preview, #document-approve-knowledge').addClass('d-none');
            $('#document-extraction').text('Access revoked.');
        }).fail(failure).always(function () { busy = false; });
    });
    $('#document-approve-knowledge').on('click', function () {
        if (!document || busy || !window.confirm('Confirm you reviewed this SOP. Index its text using the configured embedding provider?')) return;
        busy = true;
        request('/documents/' + encodeURIComponent(document.id) + '/approve-knowledge', 'POST', {confirm: true})
            .done(function () { refresh().fail(failure); }).fail(failure).always(function () { busy = false; });
    });
    $('#document-import-preview').on('click', function () {
        if (!document || busy) return;
        busy = true;
        request('/product-imports', 'POST', {document_id: document.id}).done(function (result) {
            batch = result.data;
            const body = $('#document-import-rows').empty();
            batch.rows.forEach(function (row) {
                const tr = $('<tr>');
                const check = $('<input>', {type: 'checkbox', class: 'document-import-select', value: row.row,
                    'aria-label': 'Include row ' + row.row}).prop('disabled', row.status !== 'valid').prop('checked', row.status === 'valid');
                tr.append($('<td>').append(check));
                [row.row, row.data.name || '', row.data.code || '', row.status,
                    Object.values(row.errors || {}).flat().join(' ')].forEach(function (value) { tr.append($('<td>').text(value)); });
                body.append(tr);
            });
            $('#document-import-summary').text(batch.rows.length + ' rows · ' + JSON.stringify(batch.counts) + ' · No products created');
            $('#document-import-errors').attr('href', base + '/product-imports/' + encodeURIComponent(batch.id) + '/errors');
            $('#document-import-result').text('Select valid rows, then prepare an action for review and approval.');
            $('#document-import-panel').removeClass('d-none');
        }).fail(failure).always(function () { busy = false; });
    });
    $('#document-import-propose').on('click', function () {
        if (!batch || busy) return;
        const rows = $('.document-import-select:checked').map(function () { return Number(this.value); }).get();
        if (!rows.length) { error('Select at least one validated row.'); return; }
        busy = true;
        request('/proposals', 'POST', JSON.stringify({action_key: 'setup.products.import.v1',
            slots: {batch_id: batch.id, accepted_rows: rows, review_hash: batch.review_hash}}), {contentType: 'application/json'})
            .done(function (result) { $('#document-import-result').text('Proposal ' + result.proposal.id + ' prepared. Open action review to approve.'); })
            .fail(failure).always(function () { busy = false; });
    });
    $('#document-knowledge-form').on('submit', function (event) {
        event.preventDefault();
        if (busy) return;
        busy = true;
        request('/knowledge/search', 'POST', {question: $('#document-knowledge-question').val(), limit: 5}).done(function (result) {
            const list = $('#document-citations').empty();
            if (!result.data.citations.length) list.text('No accessible approved excerpts matched.');
            result.data.citations.forEach(function (citation) {
                list.append($('<p>').addClass('small text-muted').text('Document ' + citation.document_id + ' · Version ' + citation.source_version + ' · Page ' + citation.page));
                list.append($('<blockquote>').addClass('border-left pl-3').text(citation.excerpt));
            });
        }).fail(failure).always(function () { busy = false; });
    });
})(window.jQuery);
