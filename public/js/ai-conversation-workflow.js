(function ($) {
    'use strict';
    if (!window.zoloAiMultimodalConfig?.conversationEnabled) return;
    const ws = window.zoloAiWorkspace;
    if (!ws) return;
    const recordLabel = $('#btn-ai-record-audio').attr('aria-label') || 'Record voice note';
    const dictateLabel = $('#btn-dictate').attr('aria-label') || 'Start voice input';
    let active = null, busy = false, pending = null;
    const header = $('<div id="ai-active-task-header" class="ai-active-task-header" aria-live="polite" hidden></div>').insertBefore('#chat-messages');
    const errorBox = $('<div id="ai-conversation-error" role="alert" class="alert alert-warning small" hidden></div>').insertBefore('#chat-form');
    const headers = {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': ws.csrfToken, 'X-AI-Scope': ws.scopeHeader};
    const request = (method, path, body) => $.ajax({url: ws.aiBaseUrl + path, method, headers, data: body === undefined ? undefined : JSON.stringify(body)});

    function setBusy(value) {
        busy = value;
        $('#btn-send, [data-conversation-action]').prop('disabled', value);
        $('#chat-messages').attr('aria-busy', String(value));
        $('#ai-response-status').text(value ? 'Checking your reply…' : 'Connected to your ERP');
    }

    function fail(xhr) {
        const error = xhr.responseJSON || {};
        errorBox.prop('hidden', false).text(error.error_message || error.message || 'Reply failed. Your draft and input are preserved.');
        if (xhr.status === 409) { pending = null; reload(); }
    }

    function reset() {
        active = null; pending = null; header.empty().prop('hidden', true); errorBox.prop('hidden', true);
        $('#chat-input').attr('placeholder', 'Ask your ERP…');
        $('#btn-ai-record-audio').attr('aria-label', recordLabel);
        $('#btn-dictate').attr('aria-label', dictateLabel);
    }

    function render(state) {
        if (!state?.task_id) return;
        const log = $('#chat-messages');
        const wasAtEnd = log[0].scrollHeight - log.scrollTop() - log.innerHeight() < 80;
        let root = $('.ai-conversation-task').filter((_, element) => element.dataset.taskId === state.task_id).first();
        const hadFocus = root.length && root[0].contains(document.activeElement);
        if (!root.length) root = $('<section class="ai-conversation-task card p-3 mb-3" aria-label="Purchase order draft"></section>').attr('data-task-id', state.task_id).appendTo(log);
        root.empty().appendTo(log);
        const terminal = ['cancelled', 'completed'].includes(state.status);
        if (terminal) {
            root.append($('<p class="mb-0"></p>').text(state.status === 'completed' ? 'Purchase order created. See the order register for its receipt.' : 'Draft cancelled.'));
            if (active?.task_id === state.task_id) reset();
            return;
        }
        active = {...state};
        header.empty().prop('hidden', false).append($('<strong></strong>').text('Preparing purchase order — unposted draft'));
        header.append($('<button type="button" data-conversation-action class="btn btn-sm btn-link ml-2">Resume</button>').on('click', () => submit('resume')));
        header.append($('<button type="button" data-conversation-action class="btn btn-sm btn-link">Discard draft</button>').on('click', () => submit('Cancel this purchase order')));
        root.append($('<div class="small text-muted mb-2"></div>').text(state.status === 'proposal_created' ? 'Step 3/3 · Awaiting explicit approval' : 'Step 1/3 · Collecting details · ERP validation pending'));
        const question = $('<h6 tabindex="-1" class="ai-conversation-question"></h6>').text(state.message || 'Which remaining details would you like to provide?');
        root.append(question);
        const summary = $('<dl class="ai-conversation-summary small"></dl>');
        (state.confirmed_fields || []).forEach(field => summary.append($('<dt></dt>').text(field.label || field.field),
            $('<dd></dd>').text(field.display + (field.source === 'erp_default' ? ' (ERP default)' : ''))));
        root.append(summary);
        const choices = $('<div class="ai-conversation-choices" role="group" aria-label="Eligible options"></div>');
        (state.choices || []).forEach((choice, index) => choices.append($('<button type="button" data-conversation-action class="btn btn-sm btn-outline-primary mr-2 mb-2"></button>')
            .text((index + 1) + '. ' + choice.label).on('click', () => submit('Select option', {choice_set_id: state.choice_set_id, choice_id: choice.choice_id}))));
        root.append(choices);
        if (state.master_required_inputs) root.append($('<p class="small"></p>').text('Master details needed: ' + Object.keys(state.master_required_inputs).join(', ') + '. Reply with named fields, for example “address Erode” or “SKU CTN-001; unit Pairs; tax category GST 5%; HSN 5205”.'));
        (state.warnings || []).forEach(warning => root.append($('<p class="small text-warning"></p>').text(warning)));
        if (state.master_proposal) {
            const proposal = state.master_proposal;
            const preview = proposal.preview || JSON.parse(proposal.preview_json || '{}');
            const panel = $('<div class="border rounded p-2 mb-2"></div>').append($('<strong></strong>').text('Master creation review'));
            Object.entries(preview).filter(([key, value]) => !key.endsWith('_id') && value !== null && typeof value !== 'object')
                .forEach(([key, value]) => panel.append($('<div class="small"></div>').text(key.replaceAll('_', ' ') + ': ' + value)));
            panel.append($('<button type="button" data-conversation-action class="btn btn-sm btn-primary mt-2"></button>').text(proposal.status === 'completed' ? 'Resume parent draft' : 'Approve master creation').on('click', async () => {
                if (busy) return;
                setBusy(true);
                try {
                    const result = await request('POST', '/workflow-tasks/' + state.task_id + '/dependent-masters/approve-and-execute', {
                        field: state.master_schema.field, master_proposal_id: proposal.id, expected_version: proposal.version,
                    });
                    if (result.workflow) receive({workflow: result.workflow, message: result.workflow.message});
                    else errorBox.prop('hidden', false).text(result.message || 'Master created. Resume the parent draft to review remaining details.');
                } catch (error) { fail(error); } finally { setBusy(false); }
            }));
            root.append(panel);
        }
        const details = $('<div class="ai-conversation-details"></div>');
        root.append($('<button type="button" data-conversation-action class="btn btn-sm btn-outline-secondary mb-2">Detail controls</button>').on('click', async () => {
            try {
                const result = await request('GET', '/workflow-tasks/' + state.task_id);
                details.empty();
                window.zoloAiMedia?.renderUnifiedWorkflowCard({...result, status: 'needs_input', validation_pending: true, task_id: state.task_id}, '', null, details);
            } catch (error) { fail(error); }
        }), details);
        if (state.status === 'proposal_created' && state.preview) {
            window.zoloAiMedia?.renderUnifiedWorkflowCard({status: 'proposal_created', task_id: state.task_id, task_revision: state.task_revision,
                action_key: state.action_key, proposal: {id: state.proposal_id, version: state.version, action_key: state.action_key, preview: state.preview}}, '', null, details);
        }
        $('#chat-input').attr('placeholder', 'Answer or correct this purchase order…');
        $('#btn-ai-record-audio').attr('aria-label', 'Record answer for purchase order');
        $('#btn-dictate').attr('aria-label', 'Dictate answer for purchase order');
        if (hadFocus) question[0].focus({preventScroll: true});
        if (wasAtEnd) log.scrollTop(log[0].scrollHeight);
    }

    function receive(result) {
        if (result.thread_id) ws.setCurrentThreadId(result.thread_id);
        const facts = (result.facts || []).filter(fact => fact.tool !== 'workflow.clarification.v1');
        if (result.message) ws.appendMessage('assistant', result.message, result.sources, result.warnings, facts);
        if (result.workflow) render(result.workflow);
    }

    async function reload() {
        if (!active) return;
        try {
            const result = await request('GET', '/workflow-tasks/' + active.task_id);
            if (['completed', 'cancelled'].includes(result.proposal?.status || result.task.status)) return render({...active, status: result.proposal?.status || result.task.status});
            if (result.dependent_master) {
                const master = result.dependent_master;
                return render({...active, task_revision: result.task.revision, status: master.proposal ? 'master_proposal_created' : 'master_needs_input',
                    master_schema: {field: master.field, entity_type: master.entity_type, prefilled: master.draft_data || {}}, master_proposal: master.proposal,
                    message: master.proposal ? 'Review the master proposal, then resume the parent draft.' : 'Continue providing the remaining named master details.'});
            }
            if (result.evaluation?.is_ready) {
                const ready = await request('POST', '/workflow-tasks/' + active.task_id + '/prepare-proposal', {});
                render({status: 'proposal_created', task_id: active.task_id, task_revision: ready.task_revision || result.task.revision,
                    action_key: result.task.action_key, proposal_id: ready.proposal.id, version: ready.proposal.version, preview: ready.proposal.preview});
            } else render(result.clarification || {...active, task_revision: result.task.revision});
        } catch (error) { errorBox.prop('hidden', false).text('Draft could not be refreshed. Reload this conversation before continuing.'); }
    }

    async function submit(message, extra = {}) {
        if (busy || !message) return;
        setBusy(true); errorBox.prop('hidden', true);
        try {
            let threadId = ws.getCurrentThreadId();
            if (!threadId) {
                const thread = await request('POST', '/threads', {title: message.slice(0, 60)});
                threadId = thread.id; ws.setCurrentThreadId(threadId); sessionStorage.setItem('ai_active_thread', threadId); ws.loadThreads();
            }
            const body = {message, thread_id: threadId, ...extra};
            if (active) Object.assign(body, {active_task_id: active.task_id, expected_task_revision: active.task_revision});
            const fingerprint = JSON.stringify(body);
            if (!pending || pending.fingerprint !== fingerprint) pending = {fingerprint, id: crypto.randomUUID()};
            body.client_turn_id = pending.id;
            ws.appendMessage('user', message);
            const result = await request('POST', '/chat', body);
            pending = null;
            if ($('#chat-input').val().trim() === message) $('#chat-input').val('').trigger('input');
            $('.ai-conversation-voice').remove();
            receive(result);
        } catch (error) { fail(error); } finally { setBusy(false); }
    }

    function reviewVoice(result) {
        if (!active || result.task_id !== active.task_id || result.thread_id !== ws.getCurrentThreadId()) return errorBox.prop('hidden', false).text('Voice context changed. Reopen the intended draft before sending.');
        const panel = $('<div class="ai-conversation-voice border rounded p-3 mt-2"></div>');
        const input = $('<textarea id="ai-voice-reply-transcript" class="form-control mb-2" rows="3"></textarea>').val(result.extraction.extracted?.transcript_text || '');
        panel.append('<label for="ai-voice-reply-transcript">Review and correct voice transcript</label>', input);
        (result.extraction.extracted?.warnings || []).forEach(warning => panel.append($('<p class="small text-warning"></p>').text(warning)));
        panel.append($('<button type="button" data-conversation-action class="btn btn-sm btn-primary">Confirm transcript and send</button>').on('click', async () => {
            if (busy || !input.val().trim()) return;
            setBusy(true);
            try {
                const confirmed = await request('POST', '/media/' + result.media.id + '/confirm', {expected_revision: result.extraction.revision, transcript_text: input.val().trim()});
                result.extraction = confirmed.extraction;
                setBusy(false);
                await submit(input.val().trim(), {media_ids: [result.media.id], voice_extraction_revision: confirmed.extraction.revision});
            } catch (error) { fail(error); } finally { setBusy(false); }
        }));
        $('.ai-conversation-voice').remove();
        $('.ai-conversation-task').filter((_, element) => element.dataset.taskId === active.task_id).append(panel);
        input[0].focus();
    }

    window.zoloAiConversation = {
        getActiveTask: () => active, isBusy: () => busy, render, receive, reviewVoice, reload,
        interceptSubmit(message) {
            if (!active && !/\b(?:create|prepare|raise|make|start)\s+(?:a\s+|new\s+)?(?:purchase\s+order|po)\b/i.test(message)) return false;
            if (window.zoloAiMedia?.hasStagedAttachment()) {
                if (!busy) { setBusy(true); window.zoloAiMedia.submitStagedAttachment(message, ws.getCurrentThreadId(), () => setBusy(false)); }
            } else submit(message);
            return true;
        },
    };
    $(document).on('ai:conversation:reset', reset).on('ai:conversation:history', reload);
    $(document).on('ai:workflow:card-state', (_, state) => {
        if (!active || state.task_id !== active.task_id) return;
        if (state.status === 'completed') reset();
        else if (state.task_revision && state.task_revision !== active.task_revision) reload();
    });
})(jQuery);
