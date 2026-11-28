/**
 * zoloERP Pro — Six-Stage Asynchronous Copilot Media Progress Card & Polling Controller (R17)
 * Renders live server-driven stage state, recovers lost upload responses by client_request_id,
 * enforces monotonic state_version + scope epoch guards, and opens existing ERP review once.
 */
(function (window, $) {
    'use strict';

    const STATE_BADGES = {
        queued: { text: 'Queued', cls: 'is-processing' },
        processing: { text: 'Processing', cls: 'is-processing' },
        retry_scheduled: { text: 'Retrying', cls: 'is-needs-input' },
        needs_input: { text: 'Input Required', cls: 'is-needs-input' },
        needs_confirmation: { text: 'Confirmation Required', cls: 'is-needs-input' },
        review_ready: { text: 'Review Ready', cls: 'is-ready' },
        failed: { text: 'Failed', cls: 'is-failed' },
        cancel_requested: { text: 'Cancelling', cls: 'is-needs-input' },
        cancelled: { text: 'Cancelled', cls: 'is-needs-input' },
        expired: { text: 'Expired', cls: 'is-failed' },
    };

    const STAGE_STATUS_TEXT = {
        completed: 'Completed',
        running: 'In progress',
        pending: 'Pending',
        needs_input: 'Needs input',
        failed: 'Failed',
        retry_wait: 'Waiting to retry',
        skipped: 'Not applicable',
    };

    function formatSize(bytes) {
        const b = Number(bytes || 0);
        if (b <= 0) return '';
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function formatLocalTime(isoUtc) {
        if (!isoUtc) return '';
        try {
            const d = new Date(isoUtc);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        } catch (_) {
            return '';
        }
    }

    function isAwaitingHumanState(state) {
        return state === 'review_ready' || state === 'needs_input' || state === 'needs_confirmation';
    }

    function isTerminalState(state) {
        return isAwaitingHumanState(state) || state === 'failed' || state === 'cancelled' || state === 'expired';
    }

    function createController(deps) {
        const tasksById = new Map();
        const tasksByRequestId = new Map();
        let visibilityBound = false;

        function bindVisibilityListeners() {
            if (visibilityBound) return;
            visibilityBound = true;

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    tasksById.forEach(function (entry) {
                        if (!isTerminalState(entry.lastState) && !entry.inFlightXhr) {
                            clearTimeout(entry.pollTimer);
                            scheduleNextPoll(entry, 150);
                        }
                    });
                }
            });

            window.addEventListener('online', function () {
                tasksById.forEach(function (entry) {
                    if (!isTerminalState(entry.lastState) && !entry.inFlightXhr) {
                        clearTimeout(entry.pollTimer);
                        scheduleNextPoll(entry, 100);
                    }
                });
            });
        }

        function mountCard(initialMeta, mountEl) {
            bindVisibilityListeners();
            const wrap = $('<div class="ai-message ai-message-assistant ai-media-progress-wrap"></div>');
            wrap.append($('<div class="ai-message-label"></div>').text('zoloERP Copilot'));
            const card = $('<div class="ai-media-progress-card" role="region" aria-label="Attachment processing status"></div>');
            const liveRegion = $('<div class="sr-only" aria-live="polite"></div>');
            card.append(liveRegion);

            const header = $('<div class="ai-media-progress-header"></div>');
            const headerLeft = $('<div></div>');
            const titleEl = $('<h2 class="ai-media-progress-title"></h2>').text(initialMeta.headerTitle || 'Preparing your document');
            const metaEl = $('<div class="ai-media-progress-meta"></div>');
            headerLeft.append(titleEl).append(metaEl);

            const badgeEl = $('<span class="ai-task-state-pill is-processing"></span>').text('Uploading');
            header.append(headerLeft).append(badgeEl);
            card.append(header);

            const stageList = $('<ul class="ai-media-stage-list"></ul>');
            card.append(stageList);

            const bannerEl = $('<div class="ai-media-progress-banner d-none"></div>');
            card.append(bannerEl);

            const previewBox = $('<div class="ai-media-source-preview d-none"></div>');
            card.append(previewBox);

            const footer = $('<div class="ai-media-progress-footer"></div>');
            const footerNote = $('<span></span>').text('You can continue working while processing.');
            const footerActions = $('<div class="ai-media-progress-actions"></div>');
            footer.append(footerNote).append(footerActions);
            card.append(footer);

            wrap.append(card);
            $('.ai-welcome-box').remove();
            (mountEl || $('#chat-messages')).append(wrap);
            const feed = $('#chat-messages')[0];
            if (feed) {
                feed.scrollTop = feed.scrollHeight;
            }

            const entry = {
                clientRequestId: initialMeta.clientRequestId || null,
                taskId: null,
                epoch: deps.getScopeEpoch(),
                lastVersion: 0,
                lastState: 'uploading',
                lastSnapshot: null,
                renderedReviewKey: null,
                networkErrors: 0,
                pollTimer: null,
                inFlightXhr: null,
                actionBusy: false,
                wrap: wrap,
                card: card,
                liveRegion: liveRegion,
                titleEl: titleEl,
                metaEl: metaEl,
                badgeEl: badgeEl,
                stageList: stageList,
                bannerEl: bannerEl,
                previewBox: previewBox,
                footerNote: footerNote,
                footerActions: footerActions,
                companionText: initialMeta.companionText || null,
                stagedRef: initialMeta.stagedRef || null,
            };

            renderMetaLine(entry, {
                file_name: initialMeta.fileName || 'document',
                file_size: initialMeta.fileSize || 0,
                preview_url: null,
            });
            renderStages(entry, buildNeutralInitialStages(initialMeta.isAudio));

            if (entry.clientRequestId) {
                tasksByRequestId.set(entry.clientRequestId, entry);
            }

            return entry;
        }

        function buildNeutralInitialStages(isAudio) {
            return [
                { key: 'upload', label: isAudio ? 'Voice note uploaded' : 'Attachment uploaded', state: 'running' },
                { key: 'preprocess', label: isAudio ? 'Audio normalized and validated' : 'File optimized and validated', state: 'pending' },
                { key: 'extract', label: isAudio ? 'Transcribing voice instruction' : 'Extracting document details', state: 'pending' },
                { key: 'resolve', label: 'Resolving party and items', state: 'pending' },
                { key: 'validate', label: 'Checking stock, GST and pricing', state: 'pending' },
                { key: 'prepare', label: 'Preparing ERP draft review', state: 'pending' },
            ];
        }

        function renderMetaLine(entry, snapshot) {
            entry.metaEl.empty();
            const parts = [];
            if (snapshot.file_name) parts.push(snapshot.file_name);
            const sizeStr = formatSize(snapshot.file_size);
            if (sizeStr) parts.push(sizeStr);
            if (snapshot.page_count && snapshot.page_count > 1) {
                parts.push(snapshot.page_count + ' pages');
            }
            entry.metaEl.append($('<span></span>').text(parts.join(' · ')));

            if (snapshot.preview_url) {
                entry.metaEl.append($('<span aria-hidden="true">·</span>'));
                const btnSource = $('<button type="button" class="ai-media-progress-source-btn"></button>').text('View source');
                btnSource.on('click', function () {
                    toggleSourcePreview(entry, snapshot);
                });
                entry.metaEl.append(btnSource);
            }
        }

        function toggleSourcePreview(entry, snapshot) {
            if (!snapshot.preview_url) return;
            if (!entry.previewBox.hasClass('d-none')) {
                entry.previewBox.addClass('d-none').empty();
                return;
            }
            entry.previewBox.empty().removeClass('d-none');
            if (snapshot.source_kind === 'audio') {
                entry.previewBox.append($('<audio controls class="w-100"></audio>').attr('src', snapshot.preview_url));
            } else if (snapshot.source_kind === 'pdf') {
                entry.previewBox.append($('<iframe class="w-100 border-0 rounded" style="height:320px;" title="Source PDF preview"></iframe>').attr('src', snapshot.preview_url));
            } else {
                entry.previewBox.append($('<img alt="Source document preview">').attr('src', snapshot.preview_url));
            }
        }

        function renderStages(entry, stages, snapshot) {
            entry.stageList.empty();
            const model = snapshot && snapshot.model ? snapshot.model : null;

            (stages || []).forEach(function (st) {
                const state = st.state || 'pending';
                const li = $('<li class="ai-media-stage-row"></li>').addClass('is-' + state);

                const iconWrap = $('<span class="ai-stage-icon" aria-hidden="true"></span>');
                if (state === 'completed') {
                    iconWrap.append($('<span class="ai-stage-icon-done">✓</span>'));
                } else if (state === 'running') {
                    iconWrap.append($('<span class="ai-stage-icon-running"></span>'));
                } else if (state === 'needs_input' || state === 'retry_wait') {
                    iconWrap.append($('<span class="ai-stage-icon-warning">!</span>'));
                } else if (state === 'failed') {
                    iconWrap.append($('<span class="ai-stage-icon-failed">✕</span>'));
                } else if (state === 'skipped') {
                    iconWrap.append($('<span class="ai-stage-icon-skipped">–</span>'));
                } else {
                    iconWrap.append($('<span class="ai-stage-icon-pending"></span>'));
                }

                const bodyCol = $('<div></div>');
                bodyCol.append($('<div class="ai-stage-label"></div>').text(st.label || st.key));

                const subParts = [];
                if (st.key === 'extract' && model && model.display_name && (state === 'running' || state === 'completed' || state === 'retry_wait')) {
                    subParts.push(model.display_name);
                }
                if (st.page_progress && st.page_progress.total > 1) {
                    subParts.push('Page ' + st.page_progress.completed + ' of ' + st.page_progress.total);
                }
                if (st.detail) {
                    subParts.push(st.detail);
                }
                if (state === 'retry_wait' && snapshot && snapshot.next_retry_at) {
                    const sec = Math.max(0, Math.ceil((new Date(snapshot.next_retry_at).getTime() - Date.now()) / 1000));
                    if (!isNaN(sec)) {
                        subParts.push('Next retry in ' + sec + 's');
                    }
                }
                if (subParts.length > 0) {
                    bodyCol.append($('<div class="ai-stage-subtext"></div>').text(subParts.join(' · ')));
                }

                const statusCol = $('<span class="ai-stage-status-col"></span>');
                let statusText = STAGE_STATUS_TEXT[state] || state;
                if (state === 'completed' && st.completed_at) {
                    const t = formatLocalTime(st.completed_at);
                    if (t) statusText = 'Completed · ' + t;
                }
                statusCol.text(statusText);

                li.append(iconWrap).append(bodyCol).append(statusCol);
                entry.stageList.append(li);
            });
        }

        function renderBanner(entry, snapshot) {
            entry.bannerEl.addClass('d-none').removeClass('is-warning is-error').empty();

            if (snapshot.state === 'queued' && snapshot.worker_health && snapshot.worker_health.stale) {
                entry.bannerEl.removeClass('d-none').addClass('is-warning')
                    .text('Queued — worker unavailable. Waiting for background worker heartbeat before extraction starts.');
                return;
            }

            if (snapshot.state === 'retry_scheduled' && snapshot.error) {
                entry.bannerEl.removeClass('d-none').addClass('is-warning')
                    .text(snapshot.error.message || 'Temporary provider wait. Automatic retry is scheduled.');
                return;
            }

            if (snapshot.state === 'failed' || snapshot.state === 'expired' || snapshot.state === 'cancelled') {
                const err = snapshot.error || {};
                const lines = [];
                lines.push(err.message || ('Processing ' + snapshot.state + '.'));
                if (err.saved_media_available) {
                    lines.push('Your attachment is saved safely.');
                }
                if (err.correlation_id || snapshot.correlation_id) {
                    lines.push('Ref: ' + (err.correlation_id || snapshot.correlation_id));
                }
                if (err.requires_new_model_call && snapshot.retryable) {
                    lines.push('Retrying extraction may consume AI credits.');
                }
                entry.bannerEl.removeClass('d-none').addClass(snapshot.state === 'cancelled' ? 'is-warning' : 'is-error')
                    .text(lines.join(' '));
            }
        }

        function renderFooterActions(entry, snapshot) {
            entry.footerActions.empty();

            if (isAwaitingHumanState(snapshot.state)) {
                entry.footerNote.text('Processing finished. Review details below.');
                const toggleBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary"></button>')
                    .text(entry.card.hasClass('is-expanded') ? 'Hide steps' : 'Show steps');
                toggleBtn.on('click', function () {
                    entry.card.toggleClass('is-expanded');
                    toggleBtn.text(entry.card.hasClass('is-expanded') ? 'Hide steps' : 'Show steps');
                });
                entry.footerActions.append(toggleBtn);
                return;
            }

            if (snapshot.cancellable) {
                entry.footerNote.text('You can continue working while processing.');
                const cancelBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary"></button>')
                    .prop('disabled', entry.actionBusy || snapshot.state === 'cancel_requested')
                    .text(snapshot.state === 'cancel_requested' ? 'Cancelling…' : 'Cancel');
                cancelBtn.on('click', function () {
                    cancelTask(entry);
                });
                entry.footerActions.append(cancelBtn);
                return;
            }

            if (snapshot.state === 'failed' || snapshot.state === 'expired' || snapshot.state === 'cancelled') {
                entry.footerNote.text('Attachment preserved in scoped storage.');
                if (snapshot.retryable) {
                    const retryLabel = (snapshot.error && snapshot.error.failed_stage === 'extract') || snapshot.error?.requires_new_model_call
                        ? 'Retry extraction'
                        : 'Retry preparation';
                    const retryBtn = $('<button type="button" class="btn btn-sm btn-primary"></button>')
                        .prop('disabled', entry.actionBusy)
                        .text(retryLabel);
                    retryBtn.on('click', function () {
                        retryTask(entry, false);
                    });
                    entry.footerActions.append(retryBtn);
                }

                const manualBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary"></button>')
                    .text('Enter details manually');
                manualBtn.on('click', function () {
                    $('#chat-input').focus();
                });
                entry.footerActions.append(manualBtn);

                const dismissBtn = $('<button type="button" class="btn btn-sm btn-link text-muted p-0"></button>')
                    .text('Dismiss');
                dismissBtn.on('click', function () {
                    stopPolling(entry);
                    entry.wrap.remove();
                });
                entry.footerActions.append(dismissBtn);
            }
        }

        function applySnapshot(entry, snapshot) {
            if (!snapshot || !snapshot.processing_task_id) return;
            if (!deps.isResponseCurrent(entry.epoch, snapshot.thread_id)) {
                stopPolling(entry);
                return;
            }

            const incomingVer = Number(snapshot.state_version || 0);
            if (incomingVer > 0 && incomingVer < entry.lastVersion) {
                return;
            }
            if (incomingVer === entry.lastVersion && entry.lastState === snapshot.state) {
                return;
            }

            entry.taskId = snapshot.processing_task_id;
            tasksById.set(entry.taskId, entry);
            entry.lastVersion = incomingVer;
            entry.lastState = snapshot.state;
            entry.lastSnapshot = snapshot;
            entry.networkErrors = 0;

            entry.card.attr('data-task-id', entry.taskId).attr('data-state', snapshot.state);
            entry.titleEl.text(snapshot.header_title || 'Preparing your document');

            const badgeMeta = STATE_BADGES[snapshot.state] || { text: snapshot.state, cls: 'is-processing' };
            entry.badgeEl.attr('class', 'ai-task-state-pill ' + badgeMeta.cls).text(badgeMeta.text);

            renderMetaLine(entry, snapshot);
            renderStages(entry, snapshot.stages || [], snapshot);
            renderBanner(entry, snapshot);

            if (isAwaitingHumanState(snapshot.state)) {
                entry.card.addClass('is-compact');
            } else {
                entry.card.removeClass('is-compact is-expanded');
            }

            renderFooterActions(entry, snapshot);
            entry.liveRegion.text((snapshot.header_title || 'Attachment') + ' — ' + badgeMeta.text);

            if (isAwaitingHumanState(snapshot.state) && snapshot.result) {
                stopPolling(entry);
                const wfRev = snapshot.result.task?.revision || snapshot.result.task_revision || 0;
                const dedupeKey = entry.taskId + ':' + incomingVer + ':' + wfRev;
                if (entry.renderedReviewKey !== dedupeKey) {
                    entry.renderedReviewKey = dedupeKey;
                    deps.onTerminalResult(snapshot, entry);
                }
                return;
            }

            if (isTerminalState(snapshot.state)) {
                stopPolling(entry);
                return;
            }

            scheduleNextPoll(entry, computePollDelay(entry, snapshot));
        }

        function computePollDelay(entry, snapshot) {
            const base = Number(snapshot?.poll_after_ms || deps.pollAfterMs || 2000);
            if (document.hidden) {
                return Math.min(15000, Math.max(base * 3, 6000));
            }
            if (entry.networkErrors > 0) {
                return Math.min(12000, base * Math.pow(2, Math.min(entry.networkErrors, 3)));
            }
            return base;
        }

        function stopPolling(entry) {
            if (entry.pollTimer) {
                clearTimeout(entry.pollTimer);
                entry.pollTimer = null;
            }
            if (entry.inFlightXhr) {
                try { entry.inFlightXhr.abort(); } catch (_) {}
                entry.inFlightXhr = null;
            }
        }

        function scheduleNextPoll(entry, delayMs) {
            if (entry.pollTimer) {
                clearTimeout(entry.pollTimer);
            }
            if (!entry.taskId || isTerminalState(entry.lastState)) {
                return;
            }
            entry.pollTimer = setTimeout(function () {
                pollTask(entry);
            }, delayMs);
        }

        function pollTask(entry) {
            if (!entry.taskId || isTerminalState(entry.lastState)) return;
            if (!deps.isResponseCurrent(entry.epoch, entry.lastSnapshot?.thread_id)) {
                stopPolling(entry);
                return;
            }
            if (entry.inFlightXhr) return;

            const statusUrl = deps.processingTaskUrl(entry.taskId);
            const headers = deps.baseHeaders(false);
            if (entry.lastVersion > 0) {
                headers['If-None-Match'] = '"task-' + entry.taskId + '-v' + entry.lastVersion + '"';
            }

            entry.inFlightXhr = $.ajax({
                url: statusUrl,
                method: 'GET',
                headers: headers,
                success: function (res, _textStatus, xhr) {
                    entry.inFlightXhr = null;
                    if (xhr && xhr.status === 304) {
                        entry.networkErrors = 0;
                        scheduleNextPoll(entry, computePollDelay(entry, entry.lastSnapshot));
                        return;
                    }
                    applySnapshot(entry, res);
                },
                error: function (xhr) {
                    entry.inFlightXhr = null;
                    if (xhr && xhr.statusText === 'abort') return;
                    if (xhr && (xhr.status === 403 || xhr.status === 404 || xhr.status === 401)) {
                        stopPolling(entry);
                        entry.badgeEl.attr('class', 'ai-task-state-pill is-failed').text('Access Denied');
                        entry.bannerEl.removeClass('d-none').addClass('is-error')
                            .text('This processing task is no longer accessible in the active scope.');
                        return;
                    }
                    entry.networkErrors += 1;
                    entry.bannerEl.removeClass('d-none').addClass('is-warning')
                        .text('Connection interrupted — checking status…');
                    scheduleNextPoll(entry, computePollDelay(entry, entry.lastSnapshot));
                }
            });
        }

        function submitAsync(staged, companionText, threadId, mountEl, onAdmitted, onAdmissionError) {
            const clientRequestId = staged.clientRequestId || (window.crypto && crypto.randomUUID
                ? crypto.randomUUID()
                : ('req-' + Date.now() + '-' + Math.random().toString(16).slice(2)));
            staged.clientRequestId = clientRequestId;

            const entry = mountCard({
                clientRequestId: clientRequestId,
                fileName: staged.fileName,
                fileSize: staged.fileSize,
                isAudio: Boolean(staged.isAudio),
                companionText: companionText,
                stagedRef: staged,
            }, mountEl);

            function sendAdmissionRequest() {
                const fd = new FormData();
                fd.append('file', staged.file, staged.fileName);
                fd.append('client_request_id', clientRequestId);
                fd.append('async', '1');
                if (companionText) fd.append('companion_text', companionText);
                if (threadId) fd.append('thread_id', threadId);

                const replyTask = window.zoloAiConversation?.getActiveTask?.();
                if (replyTask && (staged.isAudio || replyTask.action_key === 'setup.supplier.create.v1')) {
                    fd.append('workflow_task_id', replyTask.task_id);
                    fd.append('expected_task_revision', replyTask.task_revision);
                }

                entry.inFlightXhr = $.ajax({
                    url: deps.processingTasksBaseUrl(),
                    method: 'POST',
                    headers: deps.baseHeaders(false),
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        entry.inFlightXhr = null;
                        applySnapshot(entry, res);
                        if (onAdmitted) onAdmitted(res, entry);
                    },
                    error: function (xhr) {
                        entry.inFlightXhr = null;
                        if (xhr && xhr.statusText === 'abort') return;

                        // Lost response recovery for network/gateway failure
                        if (!xhr || xhr.status === 0 || xhr.status === 502 || xhr.status === 504) {
                            recoverLostAdmission(entry, clientRequestId, sendAdmissionRequest, onAdmitted, onAdmissionError);
                            return;
                        }

                        const err = xhr.responseJSON || {};
                        const msg = err.error_message || err.message || 'Upload could not be admitted.';
                        const ref = err.correlation_id ? (' Ref: ' + err.correlation_id) : '';
                        entry.badgeEl.attr('class', 'ai-task-state-pill is-failed').text('Upload Rejected');
                        entry.stageList.find('li:first').removeClass('is-running').addClass('is-failed');
                        entry.bannerEl.removeClass('d-none').addClass('is-error').text(msg + ref);
                        entry.footerActions.empty().append(
                            $('<button type="button" class="btn btn-sm btn-link text-muted p-0"></button>')
                                .text('Dismiss')
                                .on('click', function () { entry.wrap.remove(); })
                        );
                        if (onAdmissionError) onAdmissionError(xhr, entry);
                    }
                });
            }

            sendAdmissionRequest();
            return entry;
        }

        function recoverLostAdmission(entry, clientRequestId, resendFn, onAdmitted, onAdmissionError) {
            entry.badgeEl.attr('class', 'ai-task-state-pill is-needs-input').text('Checking status');
            entry.bannerEl.removeClass('d-none').addClass('is-warning').text('Checking previous submission…');

            $.ajax({
                url: deps.processingTasksBaseUrl() + '?client_request_id=' + encodeURIComponent(clientRequestId),
                method: 'GET',
                headers: deps.baseHeaders(false),
                success: function (res) {
                    const found = Array.isArray(res?.tasks) && res.tasks.length > 0 ? res.tasks[0] : null;
                    if (found) {
                        applySnapshot(entry, found);
                        if (onAdmitted) onAdmitted(found, entry);
                        return;
                    }
                    offerSafeResend();
                },
                error: function () {
                    offerSafeResend();
                }
            });

            function offerSafeResend() {
                entry.badgeEl.attr('class', 'ai-task-state-pill is-needs-input').text('Connection Interrupted');
                entry.bannerEl.removeClass('d-none').addClass('is-warning')
                    .text('Upload confirmation was lost. Retry safely with the same request key (no duplicate submission).');
                entry.footerActions.empty();
                const resendBtn = $('<button type="button" class="btn btn-sm btn-primary"></button>').text('Retry upload');
                resendBtn.on('click', function () {
                    resendBtn.prop('disabled', true);
                    entry.bannerEl.addClass('d-none');
                    resendFn();
                });
                entry.footerActions.append(resendBtn);
                if (onAdmissionError) onAdmissionError({ status: 0 }, entry);
            }
        }

        function retryTask(entry, forceReextract) {
            if (!entry.taskId || entry.actionBusy) return;
            entry.actionBusy = true;
            renderFooterActions(entry, entry.lastSnapshot || {});

            const opKey = (window.crypto && crypto.randomUUID)
                ? crypto.randomUUID()
                : ('retry-' + Date.now() + '-' + Math.random().toString(16).slice(2));

            $.ajax({
                url: deps.processingTaskUrl(entry.taskId) + '/retry',
                method: 'POST',
                headers: deps.baseHeaders(true),
                data: JSON.stringify({
                    expected_state_version: entry.lastVersion,
                    operation_key: opKey,
                    force_reextract: Boolean(forceReextract),
                }),
                success: function (res) {
                    entry.actionBusy = false;
                    entry.renderedReviewKey = null;
                    applySnapshot(entry, res);
                },
                error: function (xhr) {
                    entry.actionBusy = false;
                    const err = xhr.responseJSON || {};
                    entry.bannerEl.removeClass('d-none').addClass('is-error')
                        .text(err.error_message || err.message || 'Retry request failed.');
                    renderFooterActions(entry, entry.lastSnapshot || {});
                }
            });
        }

        function cancelTask(entry) {
            if (!entry.taskId || entry.actionBusy) return;
            entry.actionBusy = true;
            renderFooterActions(entry, entry.lastSnapshot || {});

            $.ajax({
                url: deps.processingTaskUrl(entry.taskId) + '/cancel',
                method: 'POST',
                headers: deps.baseHeaders(true),
                data: JSON.stringify({
                    expected_state_version: entry.lastVersion,
                }),
                success: function (res) {
                    entry.actionBusy = false;
                    applySnapshot(entry, res);
                },
                error: function (xhr) {
                    entry.actionBusy = false;
                    const err = xhr.responseJSON || {};
                    entry.bannerEl.removeClass('d-none').addClass('is-warning')
                        .text(err.error_message || err.message || 'Could not cancel task.');
                    renderFooterActions(entry, entry.lastSnapshot || {});
                }
            });
        }

        function restoreThreadTasks(threadId, mountEl) {
            if (!threadId) return;
            const reqEpoch = deps.getScopeEpoch();
            $.ajax({
                url: deps.processingTasksBaseUrl() + '?thread_id=' + encodeURIComponent(threadId),
                method: 'GET',
                headers: deps.baseHeaders(false),
                success: function (res) {
                    if (!deps.isResponseCurrent(reqEpoch, threadId)) return;
                    const list = Array.isArray(res?.tasks) ? res.tasks.slice().reverse() : [];
                    list.forEach(function (snapshot) {
                        if (!snapshot.processing_task_id) return;
                        if (tasksById.has(snapshot.processing_task_id)) {
                            applySnapshot(tasksById.get(snapshot.processing_task_id), snapshot);
                            return;
                        }
                        if ($('[data-task-id="' + snapshot.processing_task_id + '"]').length > 0) {
                            return;
                        }
                        const entry = mountCard({
                            clientRequestId: snapshot.client_request_id,
                            fileName: snapshot.file_name,
                            fileSize: snapshot.file_size,
                            isAudio: snapshot.source_kind === 'audio',
                            headerTitle: snapshot.header_title,
                        }, mountEl);
                        applySnapshot(entry, snapshot);
                    });
                }
            });
        }

        function disposeAll() {
            tasksById.forEach(function (entry) {
                stopPolling(entry);
                entry.wrap.remove();
            });
            tasksByRequestId.forEach(function (entry) {
                stopPolling(entry);
                entry.wrap.remove();
            });
            tasksById.clear();
            tasksByRequestId.clear();
        }

        return {
            mountCard: mountCard,
            submitAsync: submitAsync,
            restoreThreadTasks: restoreThreadTasks,
            retryTask: retryTask,
            cancelTask: cancelTask,
            applySnapshot: applySnapshot,
            disposeAll: disposeAll,
            getActiveTasks: function () { return tasksById; },
        };
    }

    window.zoloAiMediaProgress = {
        createController: createController,
    };
})(window, jQuery);
