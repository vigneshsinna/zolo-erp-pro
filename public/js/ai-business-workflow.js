(function ($) {
    'use strict';
    if (!window.zoloAiBusinessWorkflowConfig?.enabled || !window.zoloAiWorkspace) return;

    const ws = window.zoloAiWorkspace;
    const panel = $('<section class="card mb-3 ai-business-knowledge-panel" aria-label="Business workflow timeline"></section>')
        .insertBefore('#chat-messages');
    const head = $('<div class="card-header d-flex align-items-center justify-content-between"></div>').appendTo(panel);
    head.append($('<strong></strong>').text('Business workflow plans'));
    const refresh = $('<button type="button" class="btn btn-sm btn-outline-secondary">Refresh</button>').appendTo(head);
    const content = $('<div class="card-body small"></div>').appendTo(panel);
    const error = $('<div class="text-danger small" role="alert" hidden></div>').appendTo(panel);
    let current = null;
    let working = false;

    const processes = [
        {key: 'process.purchase_to_pay.v1', label: 'Purchase to pay'},
        {key: 'process.direct_goods_bill.v1', label: 'Direct goods bill'},
        {key: 'process.service_payable.v1', label: 'Service payable'},
        {key: 'process.expense_payable.v1', label: 'Expense payable'},
        {key: 'process.job_work_outward.v1', label: 'Outward job work'},
        {key: 'process.job_work_inward.v1', label: 'Customer-owned job work'},
    ];

    function request(method, path, data) {
        return $.ajax({
            url: ws.aiBaseUrl + '/business-workflows' + path,
            method,
            headers: {
                'X-CSRF-TOKEN': ws.csrfToken,
                'X-AI-Scope': ws.scopeHeader,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            data: data === undefined ? undefined : JSON.stringify(data),
        });
    }

    function setBusy(value) {
        working = value;
        panel.find('button,input,select').prop('disabled', value);
        panel.attr('aria-busy', String(value));
    }

    async function perform(callback) {
        if (working) return;
        setBusy(true);
        error.prop('hidden', true).empty();
        try {
            await callback();
        } catch (xhr) {
            const body = xhr.responseJSON || {};
            error.prop('hidden', false).text(body.message || body.error_message ||
                (xhr.status === 409 ? 'The plan changed. Refresh its timeline.' : 'Workflow operation failed; no ERP action was executed.'));
            if (xhr.status === 409 && current?.workflow_id) {
                try { renderWorkflow(await request('GET', '/' + encodeURIComponent(current.workflow_id))); } catch (_) { /* preserve error */ }
            }
        } finally {
            setBusy(false);
        }
    }

    function labeledField(labelText, value, type = 'text') {
        const label = $('<label class="d-block mb-2"></label>');
        label.append($('<span class="d-block font-weight-bold"></span>').text(labelText));
        const input = $('<input class="form-control form-control-sm">').attr({type, maxlength: '250'});
        input.val(value || '');
        label.append(input);
        return {label, input};
    }

    function renderPreview(preview) {
        content.empty();
        content.append($('<p class="mb-2"></p>').text(preview.message || 'Business process preview'));
        content.append($('<div class="small text-muted mb-2"></div>')
            .text('Source: ' + preview.definition_status + ' knowledge · Plan-only preview; no ERP write'));
        const ordered = $('<ol class="pl-3 mb-2"></ol>');
        (preview.steps || []).forEach(step => {
            ordered.append($('<li class="mb-1"></li>').text(
                step.step_key.replaceAll('_', ' ') + ' · ' + step.status +
                (step.requires_verified_external_event ? ' · requires real event' : '') +
                (step.requires_separate_approval ? ' · separate approval required' : '')
            ));
        });
        content.append(ordered);
        if (!preview.can_start) {
            content.append($('<p class="text-warning mb-0"></p>')
                .text('This process cannot start until its definition is reviewed and every action is supported.'));
            return;
        }
        content.append($('<button type="button" class="btn btn-primary btn-sm">Create plan (no ERP posting)</button>')
            .on('click', () => perform(async () => {
                const result = await request('POST', '/', {
                    process_id: preview.process_id, thread_id: ws.getCurrentThreadId() || null,
                });
                renderWorkflow(result);
            })));
    }

    function statusButton(label, method, path, payload, onComplete) {
        return $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-2"></button>')
            .text(label).on('click', () => perform(async () => {
                const state = await request(method, path, payload);
                onComplete ? onComplete(state) : renderWorkflow(state);
            }));
    }

    function renderWorkflow(workflow) {
        current = workflow;
        content.empty();
        content.append($('<h6></h6>').text(workflow.process_id.replaceAll('_', ' ')));
        content.append($('<p class="text-muted"></p>').text(
            'Status: ' + workflow.status + ' · Revision ' + workflow.revision +
            ' · No step executes automatically'
        ));
        const path = '/' + encodeURIComponent(workflow.workflow_id);
        if (workflow.status !== 'cancelled') {
            if (workflow.status === 'paused') {
                content.append(statusButton('Resume', 'POST', path + '/resume',
                    {expected_revision: workflow.revision}));
            } else {
                content.append(statusButton('Pause', 'POST', path + '/pause',
                    {expected_revision: workflow.revision}));
            }
            content.append(statusButton('Cancel plan', 'POST', path + '/cancel',
                {expected_revision: workflow.revision}));
            content.append(statusButton('Recheck verified steps', 'POST', path + '/reconcile',
                {expected_revision: workflow.revision}));
        }
        const timeline = $('<ol class="pl-3 mb-3" aria-label="Workflow steps"></ol>');
        (workflow.steps || []).forEach(step => {
            const item = $('<li class="mb-2"></li>').appendTo(timeline);
            item.append($('<strong></strong>').text(step.step_key.replaceAll('_', ' ')));
            item.append($('<div class="small text-muted"></div>')
                .text(step.status + (step.action_key ? ' · ' + step.action_key : '')));
            if (step.kind === 'reviewed_action') {
                item.append($('<p class="mb-1 small"></p>')
                    .text(step.proposal_id
                        ? 'Linked proposal: ' + step.proposal_id + '. Review/approve through the existing approval controls.'
                        : 'Requires its own separate proposal and explicit human approval.'));
                if (step.status === 'awaiting_proposal') {
                    const {label, input} = labeledField('Existing reviewed proposal UUID', '', 'text');
                    item.append(label);
                    item.append($('<button type="button" class="btn btn-sm btn-outline-primary">Link proposal</button>')
                        .on('click', () => {
                            const id = String(input.val() || '').trim();
                            if (!/^[0-9a-f-]{36}$/i.test(id)) return error.prop('hidden', false).text('Enter a valid proposal UUID.');
                            perform(async () => renderWorkflow(await request('POST', path + '/link-proposal', {
                                expected_revision: workflow.revision, step_key: step.step_key, proposal_id: id,
                            })));
                        }));
                }
            }
            if (step.kind === 'external_event' && step.step_key === 'physical_return'
                && step.status === 'awaiting_real_event') {
                const {label, input} = labeledField('Posted, approved job-work GRN ID', '', 'number');
                item.append(label);
                item.append($('<button type="button" class="btn btn-sm btn-outline-primary">Verify actual receipt</button>')
                    .on('click', () => {
                        const receiptId = Number(input.val());
                        if (!Number.isSafeInteger(receiptId) || receiptId < 1) {
                            return error.prop('hidden', false).text('Enter a positive receipt ID.');
                        }
                        perform(async () => renderWorkflow(await request('POST', path + '/verify-return', {
                            expected_revision: workflow.revision, step_key: step.step_key, job_work_receipt_id: receiptId,
                        })));
                    }));
            }
        });
        content.append(timeline);

        if (workflow.status !== 'cancelled') {
            const {label, input} = labeledField('Edit planning notes (not an ERP field)', workflow.facts?.notes || '');
            content.append(label);
            content.append($('<button type="button" class="btn btn-sm btn-outline-primary">Save planning notes</button>')
                .on('click', () => perform(async () => renderWorkflow(await request('PATCH', path + '/facts', {
                    expected_revision: workflow.revision, patch: {notes: String(input.val() || '').slice(0, 250)},
                }))));
        }
        content.append($('<p class="small text-muted mt-2"></p>')
            .text('Planned, pending, approved and completed mean different things. Only the ERP execution receipt proves a posted business action.'));
    }

    async function showList() {
        current = null;
        const data = await request('GET', '/');
        content.empty();
        const chooser = $('<label class="d-block"></label>')
            .append($('<span class="d-block font-weight-bold">Review a process</span>'));
        const select = $('<select class="form-control form-control-sm"></select>')
            .append($('<option value="">Choose a process…</option>'));
        processes.forEach(p => select.append($('<option></option>').attr('value', p.key).text(p.label)));
        chooser.append(select);
        content.append(chooser);
        select.on('change', () => {
            const id = select.val();
            if (id) perform(async () => renderPreview(await request('GET', '/definitions/' + encodeURIComponent(id))));
        });
        const workflows = data.workflows || [];
        content.append($('<strong class="d-block mt-2">My recent plans</strong>'));
        if (workflows.length === 0) {
            content.append($('<p class="text-muted">No active business plans yet.</p>'));
        } else {
            workflows.forEach(workflow => {
                const button = $('<button type="button" class="btn btn-sm btn-light d-block mb-1 text-left"></button>')
                    .text(workflow.process_id + ' · ' + workflow.status)
                    .on('click', () => perform(async () => renderWorkflow(await request('GET', '/' + workflow.id))));
                content.append(button);
            });
        }
        const metrics = await request('GET', '/metrics');
        content.append($('<div class="small text-muted mt-2"></div>').text(
            'Workflow states: ' + JSON.stringify(metrics.workflow_status_counts) +
            '. ERP action completion is counted from real execution receipts, not generated plans.'
        ));
    }

    refresh.on('click', () => perform(showList));
    $(document).on('click', '#btn-new-thread, .thread-item', () => {
        // Changing a chat does not grant access to another plan; refresh the actor-scoped list.
        perform(showList);
    });
    perform(showList);
})(jQuery);
