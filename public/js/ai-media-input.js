/**
 * zoloERP Phase 2.3 — Multimodal AI Input, Voice Recorder & Extraction Review Card
 * Strictly uses session-authenticated private endpoints and never stores media in localStorage.
 * Supports staged attachments (separate attachment from extraction) and displays recorded perception model.
 */
(function ($) {
    'use strict';

    const cfg = window.zoloAiMultimodalConfig || {};
    if (!cfg.enabled && !cfg.conversationEnabled) {
        return;
    }

    let mediaRecorder = null;
    let mediaStream = null;
    let recordedChunks = [];
    let recordedBlob = null;
    let recordedMime = 'audio/wav';
    let recordedUrl = null;
    let recordTimerInterval = null;
    let recordSeconds = 0;

    let audioContext = null;
    let audioProcessor = null;
    let audioSource = null;
    let audioPcmChunks = [];
    let isPcmRecording = false;

    function stopAudioContext() {
        isPcmRecording = false;
        if (audioProcessor) {
            try { audioProcessor.disconnect(); } catch (_) {}
            audioProcessor = null;
        }
        if (audioSource) {
            try { audioSource.disconnect(); } catch (_) {}
            audioSource = null;
        }
        if (audioContext && audioContext.state !== 'closed') {
            try { audioContext.close(); } catch (_) {}
            audioContext = null;
        }
    }

    function encodePcm16Wav(pcmChunks, sampleRate) {
        let totalSamples = 0;
        for (let i = 0; i < pcmChunks.length; i++) {
            totalSamples += pcmChunks[i].length;
        }
        if (totalSamples === 0) return null;

        const merged = new Float32Array(totalSamples);
        let offset = 0;
        for (let i = 0; i < pcmChunks.length; i++) {
            merged.set(pcmChunks[i], offset);
            offset += pcmChunks[i].length;
        }

        const targetRate = 16000;
        let resampled;
        if (sampleRate === targetRate) {
            resampled = merged;
        } else {
            const ratio = sampleRate / targetRate;
            const newLength = Math.round(totalSamples / ratio);
            resampled = new Float32Array(newLength);
            for (let i = 0; i < newLength; i++) {
                const srcIdx = i * ratio;
                const left = Math.floor(srcIdx);
                const right = Math.min(left + 1, totalSamples - 1);
                const frac = srcIdx - left;
                resampled[i] = merged[left] * (1 - frac) + merged[right] * frac;
            }
        }

        const numFrames = resampled.length;
        const dataByteCount = numFrames * 2;
        const buffer = new ArrayBuffer(44 + dataByteCount);
        const view = new DataView(buffer);

        function writeString(idx, s) {
            for (let i = 0; i < s.length; i++) {
                view.setUint8(idx + i, s.charCodeAt(i));
            }
        }

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + dataByteCount, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, targetRate, true);
        view.setUint32(28, targetRate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        writeString(36, 'data');
        view.setUint32(40, dataByteCount, true);

        let dataOffset = 44;
        for (let i = 0; i < numFrames; i++) {
            const s = Math.max(-1, Math.min(1, resampled[i]));
            const val = s < 0 ? s * 0x8000 : s * 0x7FFF;
            view.setInt16(dataOffset, val, true);
            dataOffset += 2;
        }

        return new Blob([view], { type: 'audio/wav' });
    }

    // Staged attachment waiting for user prompt & send click
    let stagedAttachment = null;

    // Media IDs attached to chat turns once confirmed
    const attachedMedia = new Map();

    function getWs() {
        return window.zoloAiWorkspace || null;
    }

    function escapeText(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function formatFileSize(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function formatModelDisplayName(modelKey, provider) {
        if (!modelKey) {
            return provider ? (String(provider).toUpperCase() + ' Perception') : 'NVIDIA Nemotron 3 Nano Omni';
        }
        const cleanKey = String(modelKey).toLowerCase();
        if (cleanKey.includes('nemotron-3-nano-omni') || cleanKey.includes('nano-omni')) {
            return 'NVIDIA Nemotron 3 Nano Omni';
        }
        if (cleanKey.includes('nemotron-3.5-lightning') || cleanKey.includes('lightning')) {
            return 'Nemotron 3.5 Lightning';
        }
        if (cleanKey.includes('gemini-2.0-flash')) {
            return 'Google Gemini 2.0 Flash';
        }
        if (cleanKey.includes('gemini-1.5-pro')) {
            return 'Google Gemini 1.5 Pro';
        }
        if (cleanKey.includes('whisper')) {
            return 'OpenAI Whisper';
        }
        const parts = modelKey.split('/');
        const base = parts[parts.length - 1];
        return base.replace(/[-_]/g, ' ').replace(/\b\w/g, function (l) { return l.toUpperCase(); });
    }

    function baseHeaders(isJson) {
        const ws = getWs();
        const csrf = (ws && ws.csrfToken) || $('meta[name="csrf-token"]').attr('content') || '';
        const h = {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (ws && ws.scopeHeader) {
            h['X-AI-Scope'] = ws.scopeHeader;
        }
        if (isJson) {
            h['Content-Type'] = 'application/json';
        }
        return h;
    }

    function resolveAiBaseUrl() {
        const ws = getWs();
        const base = (ws && ws.aiBaseUrl) ? ws.aiBaseUrl : (cfg.mediaBaseUrl || '/ai');
        return base.replace(/\/$/, '');
    }

    function mediaUrl(suffix) {
        return resolveAiBaseUrl() + '/media' + (suffix || '');
    }

    function formatTimer(sec) {
        const m = String(Math.floor(sec / 60)).padStart(2, '0');
        const s = String(sec % 60).padStart(2, '0');
        return m + ':' + s;
    }

    function showRecorderStatus(msg, isError) {
        const box = $('#ai-voice-recorder-status');
        box.removeClass('text-danger text-muted text-success');
        box.addClass(isError ? 'text-danger' : 'text-muted').text(msg);
    }

    function stopStreamTracks() {
        if (mediaStream) {
            mediaStream.getTracks().forEach(function (track) {
                try {
                    track.stop();
                } catch (_err) {
                    // Ignore stop failures on already-ended tracks
                }
            });
            mediaStream = null;
        }
    }

    function resetRecorderState(hideTray) {
        if (recordTimerInterval) {
            clearInterval(recordTimerInterval);
            recordTimerInterval = null;
        }
        stopAudioContext();
        stopStreamTracks();
        audioPcmChunks = [];
        if (recordedUrl) {
            try {
                URL.revokeObjectURL(recordedUrl);
            } catch (_err) {
                // Ignore
            }
            recordedUrl = null;
        }
        mediaRecorder = null;
        recordedChunks = [];
        recordedBlob = null;
        recordedMime = 'audio/wav';
        recordSeconds = 0;

        $('#ai-voice-timer').text('00:00');
        $('#ai-voice-replay').addClass('d-none').removeAttr('src');
        $('#btn-ai-voice-stop').addClass('d-none');
        $('#btn-ai-voice-upload').addClass('d-none').prop('disabled', false);
        $('#btn-ai-record-audio').prop('disabled', false).removeClass('active');
        if (hideTray) {
            $('#ai-voice-recorder-tray').addClass('d-none');
        }
    }

    function renderAttachedChips() {
        const bar = $('#ai-media-attached-bar');
        bar.empty();

        if (!stagedAttachment && attachedMedia.size === 0) {
            bar.addClass('d-none');
            return;
        }
        bar.removeClass('d-none');

        // 1) Render staged attachment (selected, ready to send with instruction)
        if (stagedAttachment) {
            const chip = $('<div class="d-inline-flex align-items-center bg-white border border-primary rounded p-1 px-2 mr-2 mb-1 shadow-sm ai-staged-chip"></div>');

            if (stagedAttachment.previewUrl && !stagedAttachment.isAudio) {
                const thumb = $('<img class="rounded mr-2" style="width:28px; height:28px; object-fit:cover;" alt="preview">')
                    .attr('src', stagedAttachment.previewUrl);
                chip.append(thumb);
            } else {
                const icon = $('<i class="mr-2 text-primary font-weight-bold"></i>')
                    .addClass(stagedAttachment.isAudio ? 'dripicons-microphone' : 'dripicons-document');
                chip.append(icon);
            }

            const info = $('<div class="d-flex flex-column mr-2" style="line-height:1.2;"></div>');
            info.append($('<span class="font-weight-bold small text-dark"></span>').text(stagedAttachment.fileName));
            info.append($('<span class="text-muted" style="font-size:11px;"></span>').text(
                (stagedAttachment.isAudio ? 'Voice instruction · ' : 'Attachment · ') + formatFileSize(stagedAttachment.fileSize) + ' (ready to send)'
            ));
            chip.append(info);

            const removeBtn = $('<button type="button" class="btn btn-sm btn-link text-danger p-0 ml-1" title="Remove attachment" aria-label="Remove attachment">&times;</button>');
            removeBtn.on('click', function () {
                clearStagedAttachment();
            });
            chip.append(removeBtn);
            bar.append(chip);
        }

        // 2) Render confirmed attached media (context for subsequent chat turns)
        attachedMedia.forEach(function (entry, id) {
            const chip = $('<span class="badge badge-pill ai-media-chip mr-2 mb-1 d-inline-flex align-items-center py-1 px-2"></span>');
            chip.append($('<i class="mr-1"></i>').addClass(entry.sourceKind === 'audio' ? 'dripicons-microphone' : 'dripicons-document'));
            chip.append($('<span></span>').text(entry.label + ' (Verified Context)'));

            const removeBtn = $('<button type="button" class="close ml-2 ai-chip-remove" aria-label="Remove attached media">&times;</button>');
            removeBtn.on('click', function () {
                attachedMedia.delete(id);
                renderAttachedChips();
            });
            chip.append(removeBtn);
            bar.append(chip);
        });
    }

    function stageFile(file, fileName) {
        if (!file) return;
        clearStagedAttachment();
        const isAudio = (file.type && file.type.startsWith('audio/')) || /\.(wav|webm|mp3|ogg|flac|m4a)$/i.test(fileName || file.name || '');
        const isImage = (file.type && file.type.startsWith('image/')) || /\.(png|jpe?g|webp)$/i.test(fileName || file.name || '');
        let previewUrl = null;
        if (isImage || isAudio) {
            try {
                previewUrl = URL.createObjectURL(file);
            } catch (_) {}
        }
        stagedAttachment = {
            file: file,
            fileName: fileName || file.name || 'document',
            fileSize: file.size || 0,
            mimeType: file.type || (isAudio ? 'audio/wav' : 'application/octet-stream'),
            isAudio: isAudio,
            previewUrl: previewUrl,
        };
        renderAttachedChips();
        $('#chat-input').focus();
    }

    function clearStagedAttachment() {
        if (stagedAttachment) {
            if (stagedAttachment.previewUrl) {
                try {
                    URL.revokeObjectURL(stagedAttachment.previewUrl);
                } catch (_) {}
            }
            stagedAttachment = null;
        }
        renderAttachedChips();
    }

    function attachMediaForChat(media, extraction) {
        const label = media.file_name || (media.source_kind === 'audio' ? 'Voice Note' : 'Document');
        attachedMedia.set(media.id, {
            id: media.id,
            sourceKind: media.source_kind,
            label: label,
            revision: extraction ? extraction.revision : 1,
        });
        renderAttachedChips();
    }

    function submitStagedAttachment(companionText, threadId, callback) {
        if (!stagedAttachment) {
            if (callback) callback(new Error('No staged attachment.'));
            return;
        }
        const targetStaged = stagedAttachment;
        stagedAttachment = null;
        renderAttachedChips();

        const ws = getWs();
        const promptText = companionText || (targetStaged.isAudio ? 'Analyze this voice instruction' : 'Extract and analyze ' + targetStaged.fileName);

        if (ws && ws.appendMessage) {
            ws.appendMessage('user', promptText, null, null, null, [targetStaged]);
        }

        const placeholderId = 'ai-media-loading-' + Date.now();
        const perceptionName = formatModelDisplayName(cfg.multimodalModel || 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', 'nvidia');
        const loadingCard = $('<div class="d-flex mb-3 justify-content-start" id="' + placeholderId + '"></div>')
            .append('<div class="chat-bubble chat-assistant shadow-sm small"><span class="spinner-border spinner-border-sm mr-2" role="status"></span>Validating document, resolving records, and automatically preparing ERP proposal with ' + escapeText(perceptionName) + '...</div>');
        $('.ai-welcome-box').remove();
        $('#chat-messages').append(loadingCard);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        function prepareInThread(effectiveThreadId) {
        const fd = new FormData();
        fd.append('file', targetStaged.file, targetStaged.fileName);
        fd.append('prepare_now', '1');
        if (companionText) {
            fd.append('companion_text', companionText);
        }
        if (effectiveThreadId) {
            fd.append('thread_id', effectiveThreadId);
        }
        const replyTask = targetStaged.isAudio && window.zoloAiConversation?.getActiveTask();
        if (replyTask) {
            fd.append('workflow_task_id', replyTask.task_id);
            fd.append('expected_task_revision', replyTask.task_revision);
        }

        $.ajax({
            url: mediaUrl('/prepare'),
            method: 'POST',
            headers: baseHeaders(false),
            data: fd,
            processData: false,
            contentType: false,
            success: function (res) {
                $('#' + placeholderId).remove();
                if (targetStaged.previewUrl) {
                    try { URL.revokeObjectURL(targetStaged.previewUrl); } catch (_) {}
                }
                try {
                    if (res.status === 'voice_intent_review_ready' && window.zoloAiConversation) {
                        window.zoloAiConversation.reviewInitialVoice(res);
                    } else if (res.status === 'voice_reply_ready' && window.zoloAiConversation) {
                        window.zoloAiConversation.reviewVoice(res);
                    } else if (targetStaged.isAudio && cfg.conversationEnabled) {
                        throw new Error('Voice instruction requires explicit transcript review. No ERP action was staged.');
                    } else {
                        renderUnifiedWorkflowCard(res, companionText, targetStaged);
                    }
                    if (ws && ws.loadTelemetry) {
                        ws.loadTelemetry();
                    }
                } catch (cardErr) {
                    console.error('Error rendering unified workflow card:', cardErr);
                    if (ws && ws.appendMessage) {
                        ws.appendMessage('assistant', 'Could not display the verified ERP workflow: ' + (cardErr.message || 'Unknown error'));
                    }
                } finally {
                    if (callback) callback(null, res);
                }
            },
            error: function (xhr) {
                $('#' + placeholderId).remove();
                try {
                    const err = xhr.responseJSON || {};
                    const code = err.error_code || ('HTTP_' + xhr.status);
                    const msg = err.error_message || err.message || 'Media upload or preparation failed.';
                    const details = err.details || {};

                    // UI-01: Preserve staged attachment on upload/extraction failure
                    if (xhr.status !== 413) {
                        stagedAttachment = targetStaged;
                        renderAttachedChips();
                    } else if (targetStaged.previewUrl) {
                        try { URL.revokeObjectURL(targetStaged.previewUrl); } catch (_) {}
                    }

                    let errMsg = '[' + code + '] ' + msg;
                    if (details.failures && Array.isArray(details.failures) && details.failures.length > 0) {
                        const failCodes = details.failures.map(function (f) { return 'P' + f.page + ':' + f.error_code; }).join(', ');
                        errMsg += ' (' + failCodes + ')';
                    }

                    if (ws && ws.appendMessage) {
                        const msgEl = ws.appendMessage('assistant', errMsg);

                        // UI-02: Add Retry Preparation on existing valid media object
                        if (details.media_id && details.is_retryable !== false) {
                            const retryBtn = $('<button type="button" class="btn btn-sm btn-outline-primary mt-2 d-inline-flex align-items-center"><i class="dripicons-clockwise mr-1"></i> Retry Preparation</button>');
                            retryBtn.on('click', function () {
                                retryBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span> Retrying...');
                                retryPreparation(details.media_id, companionText, function () {
                                    retryBtn.remove();
                                });
                            });
                            if (msgEl && msgEl.find) {
                                msgEl.find('.chat-bubble').append($('<div class="mt-2"></div>').append(retryBtn));
                            }
                        }
                    }
                } finally {
                    if (callback) callback(xhr.responseJSON || new Error('Upload or preparation failed'));
                }
            }
        });
        } // prepareInThread

        const effectiveThreadId = threadId || (ws && ws.getCurrentThreadId && ws.getCurrentThreadId());
        if (targetStaged.isAudio && cfg.conversationEnabled && !effectiveThreadId) {
            $.ajax({
                url: resolveAiBaseUrl() + '/threads',
                method: 'POST',
                headers: baseHeaders(true),
                data: JSON.stringify({title: 'Voice instruction'}),
                success: function (thread) {
                    ws.setCurrentThreadId(thread.id);
                    sessionStorage.setItem('ai_active_thread', thread.id);
                    ws.loadThreads();
                    prepareInThread(thread.id);
                },
                error: function (xhr) {
                    $('#' + placeholderId).remove();
                    stagedAttachment = targetStaged;
                    renderAttachedChips();
                    if (ws && ws.appendMessage) {
                        ws.appendMessage('assistant', 'Unable to create a scoped conversation for this voice note. No ERP action was staged.');
                    }
                    if (callback) callback(xhr.responseJSON || new Error('Conversation creation failed'));
                },
            });
            return;
        }
        prepareInThread(effectiveThreadId);
    }

    function retryPreparation(mediaId, companionText, onDone) {
        const ws = getWs();
        const placeholderId = 'ai-media-loading-' + Date.now();
        const perceptionName = formatModelDisplayName(cfg.multimodalModel || 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', 'nvidia');
        const loadingCard = $('<div class="d-flex mb-3 justify-content-start" id="' + placeholderId + '"></div>')
            .append('<div class="chat-bubble chat-assistant shadow-sm small"><span class="spinner-border spinner-border-sm mr-2" role="status"></span>Retrying preparation with ' + escapeText(perceptionName) + '...</div>');
        $('#chat-messages').append(loadingCard);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        $.ajax({
            url: mediaUrl('/' + encodeURIComponent(mediaId) + '/prepare'),
            method: 'POST',
            headers: baseHeaders(true),
            data: JSON.stringify({
                force: true,
                companion_text: companionText || null,
            }),
            success: function (res) {
                $('#' + placeholderId).remove();
                if (onDone) onDone();
                clearStagedAttachment();
                renderUnifiedWorkflowCard(res, companionText);
                if (ws && ws.loadTelemetry) {
                    ws.loadTelemetry();
                }
            },
            error: function (xhr) {
                $('#' + placeholderId).remove();
                if (onDone) onDone();
                const err = xhr.responseJSON || {};
                const code = err.error_code || ('HTTP_' + xhr.status);
                const msg = err.error_message || err.message || 'Retry preparation failed.';
                if (ws && ws.appendMessage) {
                    ws.appendMessage('assistant', '[' + code + '] ' + msg);
                }
            }
        });
    }

    function retryExtraction(mediaId, companionText, onDone) {
        const ws = getWs();
        const placeholderId = 'ai-media-loading-' + Date.now();
        const perceptionName = formatModelDisplayName(cfg.multimodalModel || 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', 'nvidia');
        const loadingCard = $('<div class="d-flex mb-3 justify-content-start" id="' + placeholderId + '"></div>')
            .append('<div class="chat-bubble chat-assistant shadow-sm small"><span class="spinner-border spinner-border-sm mr-2" role="status"></span>Retrying extraction with ' + escapeText(perceptionName) + '...</div>');
        $('#chat-messages').append(loadingCard);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        $.ajax({
            url: mediaUrl('/' + encodeURIComponent(mediaId) + '/extract'),
            method: 'POST',
            headers: baseHeaders(true),
            data: JSON.stringify({
                force: true,
                companion_text: companionText || null,
            }),
            success: function (res) {
                $('#' + placeholderId).remove();
                if (onDone) onDone();
                clearStagedAttachment();
                if (res && res.media && res.extraction) {
                    renderExtractionCard(res.media, res.extraction, companionText);
                    const ext = res.extraction.extracted || {};
                    const partyName = ext.supplier_name || ext.buyer_name || 'party';
                    const totalAmt = ext.total_amount ? ('₹' + ext.total_amount) : '';
                    const docLabel = (ext.document_type || 'document').replace('_', ' ');
                    const modelBadge = formatModelDisplayName(res.extraction.model_key, res.extraction.model_provider);

                    let summaryMsg = 'Extraction succeeded on retry for ' + docLabel + ' from **' + escapeText(partyName) + '**';
                    if (totalAmt) {
                        summaryMsg += ' totaling **' + escapeText(totalAmt) + '**';
                    }
                    summaryMsg += ' using **' + escapeText(modelBadge) + '**.\n\nPlease review the extracted fields above and click **Confirm Extraction** to proceed.';
                    if (ws && ws.appendMessage) {
                        ws.appendMessage('assistant', summaryMsg);
                    }
                }
                if (ws && ws.loadTelemetry) {
                    ws.loadTelemetry();
                }
            },
            error: function (xhr) {
                $('#' + placeholderId).remove();
                if (onDone) onDone();
                const err = xhr.responseJSON || {};
                const code = err.error_code || ('HTTP_' + xhr.status);
                const msg = err.error_message || err.message || 'Retry extraction failed.';
                if (ws && ws.appendMessage) {
                    ws.appendMessage('assistant', '[' + code + '] ' + msg);
                }
            }
        });
    }

    function formatCurrencyVal(val) {
        if (val == null || val === '') return '—';
        const num = Number(val);
        if (isNaN(num)) return String(val);
        return '₹' + num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function renderUnifiedWorkflowCard(res, companionText, stagedRef, mount) {
        const ws = getWs();
        const aiBaseUrl = resolveAiBaseUrl();
        const actionCandidates = [
            res.action_key,
            res.task?.action_key,
            res.clarification?.action_key,
            res.proposal?.action_key,
            res.proposal?.preview?.action_key,
            res.normalized_input?.intent_key,
        ].filter(Boolean);
        const actionTypes = new Set(actionCandidates);
        const allowedActions = new Set([
            'purchase_order.create.v1', 'purchase_bill.create.v1',
            'sales_order.create.v1', 'sales_invoice.create.v1',
        ]);
        if (actionTypes.size !== 1 || !allowedActions.has(actionCandidates[0])) {
            const warning = $('<section class="alert alert-warning my-3" role="alert"></section>')
                .text('The requested ERP transaction type is missing or conflicting. Nothing was prepared or approved. Confirm the intended transaction in chat.');
            (mount || $('#chat-messages')).append(warning);
            return;
        }
        const verifiedActionKey = actionCandidates[0];
        const media = res.media || {};
        const extraction = res.extraction || {};
        const taskId = res.task_id || (res.task && res.task.id) || media.workflow_task_id;

        const container = $('<div class="d-flex mb-3 justify-content-start ai-unified-workflow-wrap"></div>');
        const bubble = $('<div class="chat-bubble chat-assistant shadow-sm w-100 ai-media-review-bubble"></div>');
        const card = $('<div class="card border shadow-none ai-unified-card" data-media-id="' + escapeText(media.id || '') + '" data-task-id="' + escapeText(taskId || '') + '"></div>');

        bubble.append(card);
        container.append(bubble);
        $('.ai-welcome-box').remove();
        (mount || $('#chat-messages')).append(container);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        let currentRes = res;

        function refreshView(stateName, stateData) {
            card.empty();
            if (stateName === 'booked') {
                renderBookedView(stateData);
            } else if (stateName === 'edit') {
                renderEditView();
            } else if (stateName === 'clarification') {
                renderClarificationView(stateData || currentRes);
            } else {
                renderProposalView(stateData || currentRes);
            }
            $(document).trigger('ai:workflow:card-state', [{task_id: taskId,
                task_revision: stateData?.task?.revision || stateData?.task_revision || stateData?.clarification?.task_revision,
                status: stateName === 'booked' ? 'completed' : stateName}]);
            $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
        }

        function renderProposalView(dataPayload) {
            currentRes = dataPayload;
            const proposal = currentRes.proposal || {};
            let preview = proposal.preview;
            if (!preview && proposal.preview_json) {
                try {
                    preview = typeof proposal.preview_json === 'string' ? JSON.parse(proposal.preview_json) : proposal.preview_json;
                } catch (_) { preview = {}; }
            }
            preview = preview || {};

            let payload = proposal.payload;
            if (!payload && proposal.payload_json) {
                try {
                    payload = typeof proposal.payload_json === 'string' ? JSON.parse(proposal.payload_json) : proposal.payload_json;
                } catch (_) { payload = {}; }
            }
            payload = payload || {};

            const ext = (currentRes.extraction && currentRes.extraction.extracted) || (res.extraction && res.extraction.extracted) || {};
            const actionKey = currentRes.action_key || proposal.action_key || (preview && preview.action_key) || (currentRes.task && currentRes.task.action_key) || verifiedActionKey;
            const isPb = (actionKey === 'purchase_bill.create.v1');
            const isPo = (actionKey === 'purchase_order.create.v1');
            const isPurchase = isPb || isPo || actionKey.startsWith('purchase_');

            let docTitle = preview.document_type;
            if (!docTitle) {
                if (isPb) docTitle = 'Purchase Bill Review';
                else if (isPo) docTitle = 'Purchase Order Review';
                else docTitle = 'Commercial Action Review';
            } else if (!docTitle.toLowerCase().includes('review')) {
                docTitle += ' Review';
            }

            const proposalId = proposal.proposal_id || proposal.id;
            const version = proposal.version || 1;

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            const titleWrap = $('<div></div>');
            titleWrap.append($('<h5 class="font-weight-bold mb-1 text-dark"></h5>').html('<i class="dripicons-document mr-2 text-primary"></i>' + escapeText(docTitle)));
            const perceptionName = formatModelDisplayName((currentRes.extraction && currentRes.extraction.model_key) || media.model_key || 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', 'nvidia');
            titleWrap.append($('<div class="small text-muted"></div>').text(media.id ? 'Extracted by ' + perceptionName + ' · Verified by ERP Validation' : 'Prepared from conversation · Verified by ERP Validation'));
            topRow.append(titleWrap);
            topRow.append($('<span class="badge badge-success px-2 py-1 font-weight-bold"><i class="dripicons-checkmark mr-1"></i>Validation passed</span>'));
            header.append(topRow);
            card.append(header);

            const body = $('<div class="card-body p-3 bg-white"></div>');

            // Collapsible Document Preview
            if (media.preview_url) {
                const prevWrap = $('<div class="mb-3"></div>');
                const btnToggle = $('<button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 mb-2"><i class="dripicons-preview mr-1"></i><span>Preview Document</span></button>');
                const prevBox = $('<div class="border rounded p-2 bg-light text-center mb-2 d-none"></div>');
                if (media.source_kind === 'audio') {
                    prevBox.append($('<audio controls class="w-100"></audio>').attr('src', media.preview_url));
                } else {
                    prevBox.append($('<img class="img-fluid rounded" style="max-height: 280px;" alt="Document preview">').attr('src', media.preview_url));
                }
                btnToggle.on('click', function () {
                    prevBox.toggleClass('d-none');
                    $(this).find('span').text(prevBox.hasClass('d-none') ? 'Preview Document' : 'Hide Preview');
                });
                prevWrap.append(btnToggle).append(prevBox);
                body.append(prevWrap);
            }

            // 4-grid Metadata box (Image 3)
            const metaBox = $('<div class="row no-gutters ai-meta-grid p-2 mb-3 border rounded bg-light"></div>');
            const partyLabel = isPurchase ? 'Supplier' : 'Customer';
            const supplierName = (preview.supplier && (preview.supplier.name || preview.supplier.company_name)) || ext.supplier_name || '—';
            const refLabel = isPb ? 'Invoice number' : (isPo ? 'Order Reference' : 'Reference number');
            const invoiceNo = preview.supplier_invoice_no || preview.reference_no || ext.invoice_number || (isPo ? 'Auto-generated on booking' : '—');
            const invoiceDate = preview.date || ext.invoice_date || '—';
            const warehouseName = (preview.warehouse && preview.warehouse.name) || (res.task && res.task.slots && (res.task.slots.warehouse_name || res.task.slots.warehouse_query)) || 'Not selected';

            metaBox.append('<div class="col-6 mb-2"><small class="text-muted d-block">' + escapeText(partyLabel) + '</small><strong class="text-dark">' + escapeText(supplierName) + '</strong></div>');
            metaBox.append('<div class="col-6 mb-2 text-right"><small class="text-muted d-block">' + escapeText(refLabel) + '</small><strong class="text-dark">' + escapeText(invoiceNo) + '</strong></div>');
            metaBox.append('<div class="col-6"><small class="text-muted d-block">Document date</small><strong class="text-dark">' + escapeText(invoiceDate) + '</strong></div>');
            metaBox.append('<div class="col-6 text-right"><small class="text-muted d-block">Warehouse</small><strong class="text-dark">' + escapeText(warehouseName) + '</strong></div>');
            body.append(metaBox);

            if (preview.payment_terms) {
                body.append($('<div class="alert alert-light border small ai-po-terms"></div>').text(
                    'Commercial terms: ' + preview.payment_terms.payment_terms_type + ' · ' + preview.payment_terms.credit_days +
                    ' days · ' + preview.payment_terms.due_date + '. No payment is posted by this order.'));
            }

            // Table of Line Items (Image 3)
            const items = preview.items || ext.lines || [];
            const tableWrap = $('<div class="table-responsive my-2"></div>');
            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
            table.append('<thead class="thead-light"><tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>');
            const tbody = $('<tbody></tbody>');

            if (items.length > 0) {
                items.forEach(function (item) {
                    const tr = $('<tr></tr>');
                    const prodName = item.product_name || item.description || 'Product';
                    const prodCode = item.product_code || item.sku_or_hsn || '';
                    const tdProd = $('<td></td>').append($('<div class="font-weight-bold"></div>').text(prodName));
                    if (prodCode) tdProd.append($('<small class="text-muted"></small>').text(prodCode));
                    tr.append(tdProd);
                    tr.append($('<td class="text-right"></td>').text(item.qty != null ? item.qty : (item.quantity != null ? item.quantity : '—')));
                    const unitRate = item.net_unit_cost ?? item.net_unit_price ?? item.unit_price;
                    tr.append($('<td class="text-right"></td>').text(formatCurrencyVal(unitRate)));
                    const lineAmt = item.line_total ?? item.total;
                    tr.append($('<td class="text-right font-weight-bold"></td>').text(formatCurrencyVal(lineAmt)));
                    tbody.append(tr);
                });
            } else {
                tbody.append('<tr><td colspan="4" class="text-center text-muted small py-2">No product lines found.</td></tr>');
            }
            table.append(tbody);
            tableWrap.append(table);
            body.append(tableWrap);

            // Totals Block (Matching Image 3 Right-Stacked Spec)
            const totals = preview.totals || {};
            const subtotalVal = totals.subtotal ?? ext.subtotal;
            const taxVal = totals.tax_total ?? ext.tax_amount;
            const grandTotalVal = totals.grand_total ?? ext.total_amount;

            const totalsDiv = $('<div class="row justify-content-end no-gutters mt-2 mb-2"></div>');
            const totalsCol = $('<div class="col-sm-6 col-md-5 text-right"></div>');
            totalsCol.append('<div class="d-flex justify-content-between small text-muted mb-1"><span>Subtotal</span><strong class="text-dark font-weight-normal">' + formatCurrencyVal(subtotalVal) + '</strong></div>');
            totalsCol.append('<div class="d-flex justify-content-between small text-muted mb-2"><span>GST</span><strong class="text-dark font-weight-normal">' + formatCurrencyVal(taxVal) + '</strong></div>');
            totalsCol.append('<div class="d-flex justify-content-between align-items-baseline border-top pt-2"><span class="font-weight-bold text-dark">Total</span><span class="h5 mb-0 font-weight-bold text-dark">' + formatCurrencyVal(grandTotalVal) + '</span></div>');
            totalsDiv.append(totalsCol);
            body.append(totalsDiv);

            // Footnote
            body.append($('<div class="small text-muted border-top pt-2 mt-2 font-italic"></div>').text(isPo
                ? 'Purchase order pricing validated by ERP. Payment and goods receipt require separate billing and receiving workflows.'
                : 'Original invoice number and document type verified by ERP validation. Real financial writes require human authorization.'));

            // Action Feedback
            const feedbackBox = $('<div class="small mt-2 mb-1 ai-action-feedback"></div>');
            body.append(feedbackBox);

            // Action Buttons (Image 3)
            const footer = $('<div class="d-flex justify-content-between align-items-center pt-3 border-top mt-2 flex-wrap"></div>');
            const btnEdit = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 py-1 px-3 ai-btn-edit-bill"></button>').text(isPo ? 'Edit PO' : 'Edit Bill');
            const rightWrap = $('<div class="d-flex align-items-center flex-wrap"></div>');
            const pdfUrl = aiBaseUrl + '/proposals/' + proposalId + '/pdf';
            const btnDraft = $('<button type="button" class="btn btn-sm btn-outline-primary mr-2 py-1 px-2 btn-preview-pdf" data-pdf-url="' + pdfUrl + '"><i class="dripicons-document mr-1"></i>Draft PDF</button>');
            const approveBtnLabel = isPb ? 'Approve & Create Bill' : (isPo ? 'Approve & Create PO' : 'Approve & Create');
            const btnApprove = $('<button type="button" class="btn btn-sm btn-success px-3 py-1 font-weight-bold ai-btn-approve-bill"><i class="dripicons-checkmark mr-1"></i>' + escapeText(approveBtnLabel) + '</button>');

            btnEdit.on('click', function () {
                refreshView('edit');
            });

            btnApprove.on('click', function () {
                btnApprove.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Approving...');
                btnEdit.prop('disabled', true);
                feedbackBox.empty();

                $.ajax({
                    url: aiBaseUrl + '/proposals/' + proposalId + '/approve',
                    method: 'POST',
                    headers: baseHeaders(true),
                    data: JSON.stringify({ expected_version: version }),
                    success: function (resApprove) {
                        if (resApprove.status === 'completed') {
                            refreshView('booked', (resApprove.execution && resApprove.execution.receipt) || {});
                        } else if (resApprove.status === 'queued') {
                            feedbackBox.html('<span class="badge badge-info p-2">Execution queued in background. Check back in a moment.</span>');
                        } else {
                            feedbackBox.html('<span class="badge badge-success p-2">Approved.</span>');
                        }
                    },
                    error: function (xhr) {
                        btnApprove.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>' + escapeText(approveBtnLabel));
                        btnEdit.prop('disabled', false);
                        const err = xhr.responseJSON || {};
                        const msg = err.message || err.error_message || 'Approval failed.';
                        feedbackBox.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(msg) + '</div>');
                    }
                });
            });

            rightWrap.append(btnDraft).append(btnApprove);
            footer.append(btnEdit).append(rightWrap);
            body.append(footer);
            card.append(body);
        }

        function renderBookedView(receipt) {
            const refNo = (receipt && receipt.details && receipt.details.reference_no) || (receipt && receipt.resource_id) || 'PR-BOOKED';
            const actionKey = currentRes.action_key || (currentRes.proposal && currentRes.proposal.action_key) || verifiedActionKey;
            const isPo = (actionKey === 'purchase_order.create.v1');
            const resId = (receipt && receipt.resource_id) || (receipt && receipt.details && (receipt.details.purchase_id || receipt.details.purchase_order_id || receipt.details.id)) || (receipt && receipt.details && receipt.details.reference_no);
            const docLabel = isPo ? 'Purchase Order' : 'Purchase Bill';
            const pdfDocType = isPo ? 'purchase-order' : 'purchase';
            const pdfUrl = resId ? (aiBaseUrl + '/documents/' + pdfDocType + '/' + encodeURIComponent(resId) + '/pdf') : null;
            const downloadUrl = pdfUrl ? (pdfUrl + (pdfUrl.indexOf('?') === -1 ? '?download=1' : '&download=1')) : null;

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            topRow.append($('<h5 class="font-weight-bold mb-0 text-success"></h5>').html('<i class="dripicons-checkmark mr-2"></i>ERP ' + escapeText(docLabel) + ' Created'));
            topRow.append($('<span class="badge badge-success px-2 py-1 font-weight-bold"><i class="dripicons-checkmark mr-1"></i>Booked</span>'));
            header.append(topRow);
            card.append(header);

            const body = $('<div class="card-body p-3 bg-white"></div>');
            const successBox = $('<div class="alert alert-success p-3 border-success mb-2"></div>');
            successBox.append('<h6 class="font-weight-bold text-success mb-1">Voucher Booked: ' + escapeText(refNo) + '</h6>');
            const bookedWarehouse = (receipt && receipt.details && receipt.details.warehouse_name) || (currentRes && currentRes.proposal && currentRes.proposal.preview && currentRes.proposal.preview.warehouse && currentRes.proposal.preview.warehouse.name) || (preview && preview.warehouse && preview.warehouse.name) || 'warehouse';
            const impactDesc = isPo ? ('Status: Successfully posted to Purchase Orders, allocations updated in ' + escapeText(bookedWarehouse) + '.') : ('Status: Successfully posted to Accounts Payable, inventory updated in ' + escapeText(bookedWarehouse) + '.');
            successBox.append('<div class="small text-muted mb-3">' + escapeText(impactDesc) + '</div>');

            const btnRow = $('<div class="d-flex align-items-center flex-wrap"></div>');
            if (pdfUrl) {
                const downloadFilename = (refNo ? String(refNo).replace(/[^a-zA-Z0-9_\-]/g, '_') : 'Receipt') + '.pdf';
                btnRow.append($('<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 mr-2 mb-1 btn-preview-pdf"><i class="dripicons-preview mr-1"></i>View PDF in Chat</button>').attr('data-pdf-url', pdfUrl).attr('data-title', refNo));
                btnRow.append($('<a class="btn btn-sm btn-primary py-1 px-2 mb-1" target="_blank"><i class="dripicons-download mr-1"></i>Download PDF Receipt</a>').attr('href', downloadUrl).attr('download', downloadFilename));
            }
            successBox.append(btnRow);
            body.append(successBox);
            card.append(body);
        }

        function renderClarificationView(dataPayload) {
            currentRes = dataPayload;
            const taskObj = currentRes.task || {};
            const clarifyObj = currentRes.clarification || {};
            const currentTaskId = currentRes.task_id || (taskObj && taskObj.id) || taskId;
            const proposal = currentRes.proposal || {};
            const preview = proposal.preview || {};
            const ext = (currentRes.extraction && currentRes.extraction.extracted) || (res.extraction && res.extraction.extracted) || {};
            const mediaObj = currentRes.media || res.media || {};
            const confirmed = clarifyObj.confirmed_fields || (taskObj && taskObj.confirmed_fields) || {};
            const norm = (taskObj && taskObj.normalized_input) || {};
            const actionKey = currentRes.action_key || proposal.action_key || (taskObj && (taskObj.action_key || taskObj.intent_action)) || (currentRes.task && currentRes.task.action_key) || verifiedActionKey;
            const isPb = (actionKey === 'purchase_bill.create.v1');
            const isPo = (actionKey === 'purchase_order.create.v1');
            const isPurchase = isPb || isPo || actionKey.indexOf('purchase') !== -1;
            const docTitle = isPb ? 'Purchase Bill Review' : (isPo ? 'Purchase Order Review' : 'Commercial Action Review');

            const reqInputs = clarifyObj.required_inputs || [];

            // Card Header
            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            const titleWrap = $('<div></div>');
            titleWrap.append($('<h5 class="font-weight-bold mb-1 text-dark"></h5>').html('<i class="dripicons-warning mr-2 text-warning"></i>' + escapeText(docTitle)));
            const stepNotice = 'Step 2 of 3 • Resolve details · ' + reqInputs.length + ' item' + (reqInputs.length === 1 ? '' : 's') + ' need attention';
            titleWrap.append($('<div class="small text-muted"></div>').text(stepNotice));
            topRow.append(titleWrap);
            topRow.append($('<span class="badge badge-warning px-2 py-1 font-weight-bold"><i class="dripicons-hourglass mr-1"></i>Details Required</span>'));
            header.append(topRow);
            card.append(header);

            // Card Body
            const body = $('<div class="card-body p-3 bg-white"></div>');
            const proposalDesc = isPo ? 'purchase order' : (isPb ? 'purchase bill' : 'commercial action');
            body.append('<div class="alert alert-warning py-2 px-3 small border-warning mb-3">Please resolve the missing details below to generate the ' + escapeText(proposalDesc) + ' proposal.</div>');

            // Two-column layout grid: 7-column preview / 5-column resolution for more spacious table viewing
            const grid = $('<div class="row no-gutters ai-clarification-grid"></div>');
            const leftCol = $('<div class="col-lg-7 col-xl-7 pr-lg-3 ai-clarify-preview-col"></div>');
            const rightCol = $('<div class="col-lg-5 col-xl-5 pl-lg-3 ai-clarify-resolution-col"></div>');
            grid.append(leftCol).append(rightCol);
            body.append(grid);

            // === LEFT COLUMN: PREVIEW ===
            const previewHeaderTitle = (isPo ? 'Purchase Order' : (isPb ? 'Purchase Bill' : 'Commercial Action')) + ' Preview';
            const prevHeaderRow = $('<div class="d-flex justify-content-between align-items-center mb-2"></div>');
            prevHeaderRow.append('<h6 class="font-weight-bold text-uppercase text-muted small mb-0"><i class="dripicons-document mr-1"></i>' + escapeText(previewHeaderTitle) + '</h6>');

            const btnExpandTable = $('<button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 ai-btn-toggle-table-expand" title="Toggle wider table space"><i class="dripicons-expand mr-1"></i><span>Wider Table</span></button>');
            btnExpandTable.on('click', function () {
                grid.toggleClass('ai-table-expanded');
                const isExp = grid.hasClass('ai-table-expanded');
                $(this).find('span').text(isExp ? 'Split View' : 'Wider Table');
                $(this).find('i').attr('class', isExp ? 'dripicons-contract mr-1' : 'dripicons-expand mr-1');
            });
            prevHeaderRow.append(btnExpandTable);
            leftCol.append(prevHeaderRow);

            // Collapsible Document Preview if media exists
            if (mediaObj.preview_url) {
                const prevWrap = $('<div class="mb-3"></div>');
                const btnToggle = $('<button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 mb-2"><i class="dripicons-preview mr-1"></i><span>Preview Document</span></button>');
                const prevBox = $('<div class="border rounded p-2 bg-light text-center mb-2 d-none"></div>');
                if (mediaObj.source_kind === 'audio') {
                    prevBox.append($('<audio controls class="w-100"></audio>').attr('src', mediaObj.preview_url));
                } else {
                    prevBox.append($('<img class="img-fluid rounded" style="max-height: 240px;" alt="Document preview">').attr('src', mediaObj.preview_url));
                }
                btnToggle.on('click', function () {
                    prevBox.toggleClass('d-none');
                    $(this).find('span').text(prevBox.hasClass('d-none') ? 'Preview Document' : 'Hide Preview');
                });
                prevWrap.append(btnToggle).append(prevBox);
                leftCol.append(prevWrap);
            }

            // Metadata Box
            const metaBox = $('<div class="row no-gutters ai-meta-grid p-2 mb-3 border rounded bg-light"></div>');
            const supplierName = (preview.supplier && preview.supplier.name) || confirmed.supplier_name || norm.supplier_name || ext.supplier_name || '—';
            const invoiceNo = preview.supplier_invoice_no || preview.reference_no || confirmed.supplier_invoice_no || norm.supplier_invoice_no || ext.invoice_number || (isPo ? 'Auto-generated on booking' : '—');
            const invoiceDate = preview.date || confirmed.date || norm.date || ext.invoice_date || '—';
            let initialWhName = (preview.warehouse && preview.warehouse.name) || confirmed.warehouse_name || (Array.isArray(confirmed) && (confirmed.find(function(c) { return c.field === 'warehouse_id'; }) || {}).display) || null;

            metaBox.append('<div class="col-6 mb-2"><small class="text-muted d-block">' + escapeText(isPurchase ? 'Supplier' : 'Customer') + '</small><strong class="text-dark">' + escapeText(supplierName) + '</strong></div>');
            const refLabel = isPb ? 'Invoice number' : (isPo ? 'Order Reference' : 'Reference number');
            metaBox.append('<div class="col-6 mb-2 text-right"><small class="text-muted d-block">' + escapeText(refLabel) + '</small><strong class="text-dark">' + escapeText(invoiceNo) + '</strong></div>');
            metaBox.append('<div class="col-6"><small class="text-muted d-block">Document date</small><strong class="text-dark">' + escapeText(invoiceDate) + '</strong></div>');

            const whCol = $('<div class="col-6 text-right"><small class="text-muted d-block">Warehouse</small></div>');
            const leftWhBadge = $('<span class="badge py-1 px-2 font-weight-normal ai-preview-warehouse-badge"></span>');
            if (initialWhName) {
                leftWhBadge.addClass('badge-primary').text(initialWhName);
            } else {
                leftWhBadge.addClass('badge-warning').html('<i class="dripicons-warning mr-1"></i>Unresolved');
            }
            whCol.append(leftWhBadge);
            metaBox.append(whCol);
            leftCol.append(metaBox);

            // Table of Line Items
            const items = preview.items || clarifyObj.line_items || norm.lines || ext.lines || [];
            const tableWrap = $('<div class="table-responsive my-2 ai-preview-table-wrap border rounded shadow-sm bg-white"></div>');
            const table = $('<table class="table table-hover mb-0 ai-fact-table"></table>');
            table.append('<thead class="thead-light"><tr><th style="min-width: 170px;">Product / Item</th><th class="text-right" style="width: 80px;">Qty</th><th class="text-right" style="width: 110px;">Rate (₹)</th><th class="text-right" style="width: 120px;">Amount (₹)</th></tr></thead>');
            const tbody = $('<tbody></tbody>');

            if (items.length > 0) {
                items.forEach(function (item) {
                    const tr = $('<tr></tr>');
                    const prodName = item.product_name || item.description || 'Product';
                    const prodCode = item.product_code || item.sku_or_hsn || '';
                    const tdProd = $('<td></td>').append($('<div class="font-weight-bold"></div>').text(prodName));
                    if (prodCode) tdProd.append($('<small class="text-muted"></small>').text(prodCode));
                    tr.append(tdProd);
                    tr.append($('<td class="text-right"></td>').text(item.qty != null ? item.qty : (item.quantity != null ? item.quantity : '—')));
                    const unitRate = item.net_unit_cost ?? item.net_unit_price ?? item.unit_price;
                    tr.append($('<td class="text-right"></td>').text(formatCurrencyVal(unitRate)));
                    const lineAmt = item.line_total ?? item.total;
                    tr.append($('<td class="text-right font-weight-bold"></td>').text(formatCurrencyVal(lineAmt)));
                    tbody.append(tr);
                });
            } else {
                tbody.append('<tr><td colspan="4" class="text-center text-muted small py-2">No product lines found.</td></tr>');
            }
            table.append(tbody);
            tableWrap.append(table);
            leftCol.append(tableWrap);

            // Totals
            const totals = preview.totals || clarifyObj.totals || {};
            const subtotalVal = totals.subtotal ?? ext.subtotal;
            const taxVal = totals.tax_total ?? totals.tax_amount ?? ext.tax_amount;
            const grandTotalVal = totals.grand_total ?? totals.total_amount ?? ext.total_amount;

            const totalsDiv = $('<div class="row justify-content-end no-gutters mt-2 mb-2"></div>');
            const totalsCol = $('<div class="col-sm-8 text-right"></div>');
            totalsCol.append('<div class="d-flex justify-content-between small text-muted mb-1"><span>Subtotal</span><strong class="text-dark font-weight-normal">' + formatCurrencyVal(subtotalVal) + '</strong></div>');
            totalsCol.append('<div class="d-flex justify-content-between small text-muted mb-2"><span>GST</span><strong class="text-dark font-weight-normal">' + formatCurrencyVal(taxVal) + '</strong></div>');
            totalsCol.append('<div class="d-flex justify-content-between align-items-baseline border-top pt-2"><span class="font-weight-bold text-dark">Total</span><span class="h5 mb-0 font-weight-bold text-dark">' + formatCurrencyVal(grandTotalVal) + '</span></div>');
            totalsDiv.append(totalsCol);
            leftCol.append((currentRes.validation_pending || (cfg.conversationEnabled && mount)) ? $('<p class="small text-muted mt-2"></p>').text('Draft details — not calculated. ERP validation pending.') : totalsDiv);

            // Footnote
            leftCol.append('<div class="small text-muted border-top pt-2 mt-2 font-italic"><i class="dripicons-shield mr-1 text-warning"></i>Unresolved selections cannot be booked. Real financial writes require human authorization.</div>');

            // === RIGHT COLUMN: RESOLUTION PANEL ===
            rightCol.append('<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="font-weight-bold text-uppercase text-dark small mb-0"><i class="dripicons-checklist mr-1 text-warning"></i>Resolve Missing Details</h6><span class="badge badge-light border small text-muted font-weight-normal">' + reqInputs.length + ' required</span></div>');

            const inputsForm = $('<div class="ai-clarification-form mb-3"></div>');
            const submitBtn = $('<button type="button" class="btn btn-sm btn-primary px-3 ai-btn-submit-clarify"><i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview</button>');

            if (reqInputs.length > 0) {
                reqInputs.forEach(function (inp) {
                    const isLineItems = (inp.field === 'items' || inp.control === 'line_items');
                    const isEntity = !isLineItems && ((inp.entity_type != null) || (inp.field === 'warehouse_id' || inp.field === 'supplier_id' || inp.field === 'customer_id' || inp.field === 'product_id' || (typeof inp.field === 'string' && inp.field.indexOf('.product_id') !== -1)));
                    const entityType = inp.entity_type || (inp.field === 'warehouse_id' ? 'warehouse' : (inp.field === 'supplier_id' ? 'supplier' : (inp.field === 'customer_id' ? 'customer' : (inp.field === 'product_id' || (typeof inp.field === 'string' && inp.field.indexOf('.product_id') !== -1) ? 'product' : 'warehouse'))));

                    const grp = $('<div class="form-group mb-3 border p-2 rounded bg-light"></div>');
                    grp.append($('<label class="font-weight-bold small mb-1 text-dark"></label>').text(inp.label || inp.field));
                    if (inp.reason || inp.help_text) {
                        grp.append($('<small class="text-muted d-block mb-2"></small>').text(inp.reason || inp.help_text));
                    }

                    if (isLineItems) {
                        // Interactive Line Items Builder
                        grp.addClass('p-3 bg-white shadow-sm border-primary');
                        grp.find('label').remove();

                        const linesHeader = $('<div class="d-flex justify-content-between align-items-center mb-2"></div>');
                        linesHeader.append('<div><h6 class="font-weight-bold text-dark mb-0"><i class="dripicons-cart mr-1 text-primary"></i>Purchase Items & Rates</h6><small class="text-muted">Select products to auto-fill cost rates, adjust quantities, or enter custom rates.</small></div>');
                        grp.append(linesHeader);

                        const tableContainer = $('<div class="table-responsive mb-2"></div>');
                        const linesTable = $('<table class="table table-sm table-bordered ai-clarify-lines-table mb-0"></table>');
                        linesTable.append('<thead class="thead-light"><tr><th style="min-width: 200px;">Product / Item</th><th style="width: 80px;" class="text-right">Qty</th><th style="width: 105px;" class="text-right">Rate (₹)</th><th style="width: 105px;" class="text-right">Amount (₹)</th><th style="width: 36px;"></th></tr></thead>');
                        const linesTbody = $('<tbody></tbody>');
                        linesTable.append(linesTbody);

                        const linesTfoot = $('<tfoot><tr class="bg-light"><td colspan="3" class="text-right font-weight-bold small">Items Subtotal:</td><td class="text-right font-weight-bold text-primary ai-lines-subtotal">₹0.00</td><td></td></tr></tfoot>');
                        linesTable.append(linesTfoot);
                        tableContainer.append(linesTable);
                        grp.append(tableContainer);

                        function recalcTotals() {
                            let total = 0;
                            linesTbody.find('tr').each(function () {
                                const q = parseFloat($(this).find('.ai-line-qty').val()) || 0;
                                const r = parseFloat($(this).find('.ai-line-rate').val()) || 0;
                                const lineTotal = q * r;
                                $(this).find('.ai-line-total').text(formatCurrencyVal(lineTotal));
                                total += lineTotal;
                            });
                            linesTfoot.find('.ai-lines-subtotal').text(formatCurrencyVal(total));
                        }

                        let lineCounter = 0;
                        function addLineRow(initialData) {
                            lineCounter++;
                            const lineId = (initialData && initialData.client_line_id) || ('line-' + lineCounter);
                            const pId = (initialData && (initialData.product_id || initialData.id)) || '';
                            const pName = (initialData && (initialData.product_name || initialData.name || initialData.product_query || initialData.description)) || '';
                            const qty = (initialData && (initialData.qty || initialData.quantity)) || 1;
                            const rate = (initialData && (initialData.net_unit_cost ?? initialData.net_unit_price ?? initialData.cost ?? initialData.price ?? initialData.unit_price)) ?? '';

                            const tr = $('<tr class="ai-line-item-row"></tr>')
                                .attr('data-client-line-id', lineId)
                                .attr('data-product-id', pId)
                                .attr('data-product-name', pName);

                            const tdProd = $('<td class="position-relative"></td>');
                            const prodWrap = $('<div class="position-relative"></div>');
                            const prodInput = $('<input type="text" class="form-control form-control-sm ai-line-product-search" placeholder="Type or search product...">')
                                .val(pName);
                            prodWrap.append(prodInput);

                            const prodDropdown = $('<div class="ai-line-product-dropdown border rounded bg-white shadow p-1 position-absolute w-100 d-none" style="z-index: 1050; max-height: 200px; overflow-y: auto; top: 100%; left: 0;"></div>');
                            prodWrap.append(prodDropdown);
                            tdProd.append(prodWrap);

                            const tdQty = $('<td></td>');
                            const qtyInput = $('<input type="number" min="0.01" step="any" class="form-control form-control-sm text-right ai-line-qty" value="' + escapeText(qty) + '">');
                            tdQty.append(qtyInput);

                            const tdRate = $('<td></td>');
                            const rateInput = $('<input type="number" min="0" step="any" class="form-control form-control-sm text-right ai-line-rate" placeholder="0.00" value="' + escapeText(rate) + '">');
                            tdRate.append(rateInput);

                            const initAmount = (parseFloat(qty) || 0) * (parseFloat(rate) || 0);
                            const tdAmount = $('<td class="text-right align-middle font-weight-bold text-dark ai-line-total"></td>').text(formatCurrencyVal(initAmount));

                            const tdAction = $('<td class="text-center align-middle"></td>');
                            const btnRemove = $('<button type="button" class="btn btn-xs btn-link text-danger p-0 ai-line-remove-btn" title="Remove line"><i class="dripicons-trash"></i></button>');
                            tdAction.append(btnRemove);

                            tr.append(tdProd).append(tdQty).append(tdRate).append(tdAmount).append(tdAction);
                            linesTbody.append(tr);

                            let prodDebounce = null;
                            prodInput.on('input', function () {
                                const q = $(this).val().trim();
                                tr.attr('data-product-name', q);
                                if (!q) {
                                    tr.attr('data-product-id', '');
                                    prodDropdown.addClass('d-none').empty();
                                    return;
                                }
                                clearTimeout(prodDebounce);
                                prodDebounce = setTimeout(function () {
                                    prodDropdown.html('<div class="p-2 small text-muted"><span class="spinner-border spinner-border-sm mr-1"></span>Searching...</div>').removeClass('d-none');
                                    $.ajax({
                                        url: aiBaseUrl + '/entity-options',
                                        method: 'GET',
                                        headers: baseHeaders(false),
                                        data: {
                                            type: 'product',
                                            task_id: currentTaskId,
                                            q: q
                                        },
                                        success: function (resOpts) {
                                            prodDropdown.empty();
                                            const items = resOpts.items || [];
                                            if (items.length === 0) {
                                                const noMatch = $('<div class="p-2 small text-muted"></div>');
                                                noMatch.append('<div>No products match "<strong>' + escapeText(q) + '</strong>".</div>');
                                                noMatch.append('<div class="mt-1 text-primary cursor-pointer font-weight-bold">+ Use as custom product name</div>');
                                                noMatch.on('click', function () {
                                                    tr.attr('data-product-id', '');
                                                    tr.attr('data-product-name', q);
                                                    prodDropdown.addClass('d-none');
                                                    if (!rateInput.val()) {
                                                        rateInput.focus();
                                                    }
                                                });
                                                prodDropdown.append(noMatch);
                                                return;
                                            }

                                            items.forEach(function (opt) {
                                                const optItem = $('<div class="p-2 border-bottom cursor-pointer hover-bg-light small"></div>');
                                                const titleRow = $('<div class="d-flex justify-content-between"></div>');
                                                titleRow.append('<strong class="text-dark">' + escapeText(opt.name) + '</strong>');
                                                const costDisplay = (opt.cost > 0) ? ('Cost: ₹' + opt.cost) : (opt.price > 0 ? ('Price: ₹' + opt.price) : 'No cost');
                                                titleRow.append('<span class="badge badge-light border">' + escapeText(costDisplay) + '</span>');
                                                optItem.append(titleRow);

                                                if (opt.code) {
                                                    optItem.append('<div class="text-muted" style="font-size: 11px;">SKU: ' + escapeText(opt.code) + '</div>');
                                                }

                                                optItem.on('click', function () {
                                                    tr.attr('data-product-id', opt.id);
                                                    tr.attr('data-product-name', opt.name);
                                                    prodInput.val(opt.name);
                                                    prodDropdown.addClass('d-none');

                                                    const fetchedCost = (opt.cost > 0) ? opt.cost : (opt.price > 0 ? opt.price : 0);
                                                    rateInput.val(fetchedCost);
                                                    recalcTotals();
                                                    submitBtn.html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                                                });

                                                prodDropdown.append(optItem);
                                            });
                                        },
                                        error: function () {
                                            prodDropdown.html('<div class="p-2 small text-danger">Failed to search products.</div>');
                                        }
                                    });
                                }, 250);
                            });

                            $(document).on('click.prod_dropdown_' + lineId, function (e) {
                                if (!prodWrap.is(e.target) && prodWrap.has(e.target).length === 0) {
                                    prodDropdown.addClass('d-none');
                                }
                            });

                            qtyInput.on('input change', recalcTotals);
                            rateInput.on('input change', recalcTotals);

                            btnRemove.on('click', function () {
                                $(document).off('click.prod_dropdown_' + lineId);
                                tr.remove();
                                if (linesTbody.find('tr').length === 0) {
                                    addLineRow();
                                }
                                recalcTotals();
                            });

                            recalcTotals();
                        }

                        const existingLines = (Array.isArray(inp.current_value) && inp.current_value.length > 0)
                            ? inp.current_value
                            : ((items && items.length > 0) ? items : []);

                        if (existingLines.length > 0) {
                            existingLines.forEach(function (l) {
                                addLineRow(l);
                            });
                        } else if (inp.current_value && typeof inp.current_value === 'string' && inp.current_value.trim() !== '') {
                            addLineRow({ product_name: inp.current_value.trim(), qty: 1, net_unit_cost: '' });
                        } else {
                            addLineRow();
                        }

                        const addBtnRow = $('<div class="d-flex justify-content-between align-items-center mt-2"></div>');
                        const btnAddLine = $('<button type="button" class="btn btn-xs btn-outline-primary py-1 px-2 ai-btn-add-line"><i class="dripicons-plus mr-1"></i>+ Add Line Item</button>');
                        addBtnRow.append(btnAddLine);
                        grp.append(addBtnRow);

                        btnAddLine.on('click', function () {
                            addLineRow();
                        });
                    } else if (window.AiEntityPicker && (inp.field === 'warehouse_id' || inp.field === 'supplier_id')) {
                        const entityType = inp.field === 'warehouse_id' ? 'warehouse' : 'supplier';
                        const hiddenInput = $('<input type="hidden" class="ai-clarify-select" />')
                            .attr('data-field', inp.field)
                            .val(inp.current_value || '');
                        grp.append(hiddenInput);

                        const pickerSlot = $('<div class="ai-entity-picker-slot mt-2"></div>');
                        grp.append(pickerSlot);

                        const renderPickerView = function () {
                            pickerSlot.empty();
                            window.AiEntityPicker.renderPicker(pickerSlot, {
                                entityType: entityType,
                                taskId: currentTaskId,
                                taskRevision: (taskObj && taskObj.revision) || 1,
                                fieldPath: inp.field,
                                selectedId: hiddenInput.val() || null,
                                initialQuery: inp.current_value || '',
                                aiBaseUrl: aiBaseUrl,
                                onSelect: function (entity) {
                                    hiddenInput.val(entity.id);
                                    renderSummaryView(entity);
                                },
                                onCreateRequested: function () {
                                    if (window.AiMasterCreatePanel) {
                                        window.AiMasterCreatePanel.render(pickerSlot, {
                                            taskId: currentTaskId,
                                            field: inp.field,
                                            entityType: entityType,
                                            initialName: inp.current_value || '',
                                            aiBaseUrl: aiBaseUrl,
                                            baseHeaders: baseHeaders,
                                            onCompleted: function (res) {
                                                if (res && res.proposal) {
                                                    renderProposalView(res);
                                                } else if (res && res.task) {
                                                    renderClarificationView(res);
                                                } else {
                                                    $.ajax({
                                                        url: aiBaseUrl + '/workflow-tasks/' + currentTaskId,
                                                        method: 'GET',
                                                        headers: baseHeaders(true),
                                                        dataType: 'json'
                                                    }).done(function (latestTaskRes) {
                                                        if (latestTaskRes && latestTaskRes.task) {
                                                            currentRes = latestTaskRes;
                                                            if (latestTaskRes.evaluation && latestTaskRes.evaluation.is_ready) {
                                                                $.ajax({
                                                                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/prepare-proposal',
                                                                    method: 'POST',
                                                                    headers: baseHeaders(true),
                                                                    dataType: 'json'
                                                                }).done(function (pRes) {
                                                                    renderProposalView(pRes);
                                                                });
                                                            } else {
                                                                renderClarificationView(latestTaskRes);
                                                            }
                                                        }
                                                    });
                                                }
                                            },
                                            onCancel: function () {
                                                renderPickerView();
                                            }
                                        });
                                    }
                                },
                                onCancel: function () {
                                    if (hiddenInput.val()) {
                                        renderSummaryView({ id: hiddenInput.val(), name: inp.current_label || ('Selected ' + entityType) });
                                    }
                                }
                            });
                        };

                        const renderSummaryView = function (ent) {
                            pickerSlot.empty();
                            window.AiEntityPicker.renderSelectionSummary(pickerSlot, {
                                label: inp.label || inp.field,
                                entityName: ent.name || ent.display,
                                metadata: ent.branch_label || ent.location || (ent.branch_name ? 'Branch: ' + ent.branch_name : ''),
                                reason: 'User selected',
                                onChangeRequested: function () {
                                    renderPickerView();
                                }
                            });
                        };

                        if (inp.current_value && inp.current_label) {
                            renderSummaryView({ id: inp.current_value, name: inp.current_label });
                        } else {
                            renderPickerView();
                        }
                    } else if (Array.isArray(inp.candidates) && inp.candidates.length > 0) {
                        const sel = $('<select class="form-control form-control-sm ai-clarify-select"></select>').attr('data-field', inp.field);
                        sel.append('<option value="">-- Choose ' + escapeText(inp.label || 'Candidate') + ' --</option>');
                        inp.candidates.forEach(function (c) {
                            sel.append($('<option></option>').attr('value', c.id).text(c.display || c.name || c.code));
                        });
                        grp.append(sel);
                    } else if (isEntity) {
                        // Rich AiEntityPicker
                        const hiddenInput = $('<input type="hidden" class="ai-clarify-entity-value">')
                            .attr('data-field', inp.field)
                            .val(inp.current_value || '');
                        grp.append(hiddenInput);

                        // Selected Pill (starts hidden unless current_value present)
                        const selectedPill = $('<div class="ai-selection-pill d-none justify-content-between align-items-center mb-2"></div>');
                        selectedPill.append('<span><i class="dripicons-checkmark text-success mr-1"></i>Selected: <strong class="ai-selected-name">—</strong></span>');
                        const btnChange = $('<a href="javascript:void(0)" class="ai-change-btn text-primary font-weight-bold small ml-2">Change</a>');
                        selectedPill.append(btnChange);
                        grp.append(selectedPill);

                        // Search Input Group
                        const searchGroup = $('<div class="input-group input-group-sm mb-2"></div>');
                        const searchInput = $('<input type="text" class="form-control form-control-sm ai-entity-search" placeholder="Search ' + escapeText(inp.label || entityType) + 's...">');
                        searchGroup.append(searchInput);
                        searchGroup.append('<div class="input-group-append"><span class="input-group-text bg-white"><i class="dripicons-search text-muted"></i></span></div>');
                        grp.append(searchGroup);

                        // Options Container
                        const optionsList = $('<div class="ai-entity-options-list mb-2 border rounded p-2 bg-white"></div>');
                        grp.append(optionsList);

                        function selectOption(item) {
                            optionsList.find('.ai-entity-card').removeClass('selected').find('input[type="radio"]').prop('checked', false);
                            const activeCard = optionsList.find('.ai-entity-card[data-id="' + item.id + '"]');
                            activeCard.addClass('selected').find('input[type="radio"]').prop('checked', true);

                            hiddenInput.val(item.id);
                            selectedPill.removeClass('d-none').addClass('d-flex').find('.ai-selected-name').text(item.name);
                            optionsList.slideUp(150);

                            if (inp.field === 'warehouse_id') {
                                leftWhBadge.removeClass('badge-warning').addClass('badge-primary').html('<i class="dripicons-checkmark mr-1"></i>' + escapeText(item.name));
                            }
                            submitBtn.html('<i class="dripicons-direction mr-1"></i>Use Selected & Generate Preview');
                        }

                        btnChange.on('click', function () {
                            optionsList.slideDown(150);
                        });

                        function fetchEntityOptions(q) {
                            optionsList.html('<div class="text-center text-muted small py-3"><span class="spinner-border spinner-border-sm mr-2 text-primary" role="status"></span>Loading ' + escapeText(entityType) + 's...</div>');

                            $.ajax({
                                url: aiBaseUrl + '/entity-options',
                                method: 'GET',
                                headers: baseHeaders(false),
                                data: {
                                    type: entityType,
                                    task_id: currentTaskId,
                                    field: inp.field,
                                    q: q || ''
                                },
                                success: function (resOpts) {
                                    optionsList.empty();
                                    const items = resOpts.items || [];
                                    if (items.length === 0) {
                                        optionsList.html('<div class="text-center text-muted small py-3"><i class="dripicons-information mr-1"></i>No matching ' + escapeText(entityType) + 's found in active branch.</div>');
                                        return;
                                    }

                                    items.forEach(function (opt) {
                                        const isRec = (resOpts.recommended_id && opt.id === resOpts.recommended_id);
                                        const isSelected = (hiddenInput.val() && String(hiddenInput.val()) === String(opt.id));

                                        const optCard = $('<div class="card mb-2 ai-entity-card p-2 cursor-pointer"></div>')
                                            .attr('data-id', opt.id)
                                            .attr('data-name', opt.name);
                                        if (isSelected) optCard.addClass('selected');

                                        const rowEl = $('<div class="d-flex align-items-start"></div>');
                                        const radio = $('<input type="radio" class="mr-2 mt-1 ai-entity-radio">')
                                            .attr('name', 'entity_radio_' + inp.field)
                                            .val(opt.id)
                                            .prop('checked', isSelected);
                                        rowEl.append(radio);

                                        const detailsCol = $('<div class="flex-grow-1"></div>');
                                        const titleRow = $('<div class="d-flex justify-content-between align-items-center"></div>');
                                        titleRow.append($('<strong class="text-dark small entity-name"></strong>').text(opt.name));
                                        if (isRec) {
                                            titleRow.append($('<span class="badge badge-primary font-weight-normal small"></span>').text(resOpts.recommendation_reason || 'Recommended'));
                                        }
                                        detailsCol.append(titleRow);

                                        const subParts = [];
                                        if (opt.branch_name) subParts.push('Branch ' + opt.branch_name);
                                        if (opt.code) subParts.push('Code: ' + opt.code);
                                        if (opt.cost > 0) subParts.push('Cost: ₹' + opt.cost);
                                        if (opt.price > 0) subParts.push('Price: ₹' + opt.price);
                                        if (opt.location) subParts.push(opt.location);
                                        if (opt.phone) subParts.push(opt.phone);
                                        if (subParts.length > 0) {
                                            detailsCol.append($('<div class="small text-muted"></div>').text(subParts.join(' · ')));
                                        }

                                        rowEl.append(detailsCol);
                                        optCard.append(rowEl);

                                        optCard.on('click', function () {
                                            selectOption(opt);
                                        });

                                        optionsList.append(optCard);

                                        if (isSelected) {
                                            selectedPill.removeClass('d-none').addClass('d-flex').find('.ai-selected-name').text(opt.name);
                                        }
                                    });
                                },
                                error: function (xhrOpts) {
                                    optionsList.html('<div class="text-center text-danger small py-3"><i class="dripicons-warning mr-1"></i>Could not load ' + escapeText(entityType) + 's.</div>');
                                }
                            });
                        }

                        let searchDebounce = null;
                        searchInput.on('input', function () {
                            const val = $(this).val();
                            clearTimeout(searchDebounce);
                            searchDebounce = setTimeout(function () {
                                fetchEntityOptions(val);
                            }, 300);
                        });

                        // Initial fetch
                        fetchEntityOptions('');

                        // Three-Way Disambiguation: Master Creation Flow
                        const optCreate = Array.isArray(inp.resolution_options) ? inp.resolution_options.find(function (o) { return o.key === 'create_new'; }) : null;
                        if (optCreate && optCreate.can_create !== false) {
                            const createBtnWrap = $('<div class="mt-2 text-right"></div>');
                            const btnCreateMaster = $('<button type="button" class="btn btn-xs btn-outline-primary py-1 px-2"><i class="dripicons-plus mr-1"></i>Create New ' + escapeText(inp.label || 'Master') + '</button>');
                            createBtnWrap.append(btnCreateMaster);
                            grp.append(createBtnWrap);

                            const masterPanel = $('<div class="ai-inline-master-panel mt-2 p-2 border rounded bg-white d-none"></div>');
                            grp.append(masterPanel);

                            btnCreateMaster.on('click', function () {
                                btnCreateMaster.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Loading form...');
                                masterPanel.removeClass('d-none').html('<div class="small text-muted py-1"><i class="dripicons-loading mr-1"></i> Loading requirements...</div>');

                                $.ajax({
                                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/dependent-masters/spawn',
                                    method: 'POST',
                                    headers: baseHeaders(true),
                                    data: JSON.stringify({ field: inp.field }),
                                    success: function (spawnRes) {
                                        btnCreateMaster.prop('disabled', false).html('<i class="dripicons-plus mr-1"></i>Create New ' + escapeText(inp.label || 'Master'));
                                        masterPanel.empty();

                                        const pre = spawnRes.prefilled || {};
                                        const formBody = $('<div class="small"></div>');
                                        formBody.append('<div class="font-weight-bold text-dark mb-2">Create New ' + escapeText(spawnRes.entity_type) + '</div>');

                                        if (spawnRes.entity_type === 'warehouse') {
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Warehouse Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + escapeText(pre.name || '') + '"></div>');
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Phone</label><input type="text" class="form-control form-control-sm mf-phone" value="' + escapeText(pre.phone || '') + '"></div>');
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Address / Location *</label><input type="text" class="form-control form-control-sm mf-address" value="' + escapeText(pre.address || '') + '"></div>');
                                        } else if (spawnRes.entity_type === 'supplier') {
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Supplier Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + escapeText(pre.name || '') + '"></div>');
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Company Name</label><input type="text" class="form-control form-control-sm mf-company-name" value="' + escapeText(pre.company_name || '') + '"></div>');
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">GSTIN / VAT</label><input type="text" class="form-control form-control-sm mf-vat" value="' + escapeText(pre.vat_number || '') + '"></div>');
                                        } else if (spawnRes.entity_type === 'product') {
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Product Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + escapeText(pre.name || '') + '"></div>');
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Product Code / SKU *</label><input type="text" class="form-control form-control-sm mf-code" value="' + escapeText(pre.code || '') + '"></div>');
                                            formBody.append('<div class="row no-gutters"><div class="col-6 pr-1"><div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Cost Price *</label><input type="number" step="any" class="form-control form-control-sm mf-cost" value="' + escapeText(pre.cost || '0.00') + '"></div></div><div class="col-6 pl-1"><div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Selling Price *</label><input type="number" step="any" class="form-control form-control-sm mf-price" value="' + escapeText(pre.price || '0.00') + '"></div></div></div>');
                                        } else {
                                            formBody.append('<div class="form-group mb-2"><label class="small mb-1 font-weight-bold">Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + escapeText(pre.name || '') + '"></div>');
                                        }

                                        const masterFeedback = $('<div class="small mb-2 ai-master-feedback"></div>');
                                        formBody.append(masterFeedback);

                                        const actionRow = $('<div class="d-flex justify-content-end align-items-center mt-2"></div>');
                                        const btnCancelM = $('<button type="button" class="btn btn-xs btn-outline-secondary mr-2">Cancel</button>');
                                        const btnSaveM = $('<button type="button" class="btn btn-xs btn-success font-weight-bold py-1 px-3"><i class="dripicons-checkmark mr-1"></i>Approve & Create</button>');

                                        btnCancelM.on('click', function () {
                                            masterPanel.addClass('d-none').empty();
                                        });

                                        btnSaveM.on('click', function () {
                                            btnSaveM.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Creating...');
                                            masterFeedback.empty();

                                            const masterData = { name: formBody.find('.mf-name').val().trim() };
                                            if (spawnRes.entity_type === 'warehouse') {
                                                masterData.phone = formBody.find('.mf-phone').val().trim();
                                                masterData.address = formBody.find('.mf-address').val().trim() || '-';
                                            } else if (spawnRes.entity_type === 'supplier') {
                                                masterData.company_name = formBody.find('.mf-company-name').val().trim() || null;
                                                masterData.vat_number = formBody.find('.mf-vat').val().trim() || null;
                                            } else if (spawnRes.entity_type === 'product') {
                                                masterData.code = formBody.find('.mf-code').val().trim();
                                                masterData.cost = parseFloat(formBody.find('.mf-cost').val()) || 0;
                                                masterData.price = parseFloat(formBody.find('.mf-price').val()) || masterData.cost;
                                            }

                                            $.ajax({
                                                url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/dependent-masters/preview',
                                                method: 'POST',
                                                headers: baseHeaders(true),
                                                data: JSON.stringify({ field: inp.field, data: masterData }),
                                                success: function (prevRes) {
                                                    const prop = prevRes.proposal;
                                                    $.ajax({
                                                        url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/dependent-masters/approve-and-execute',
                                                        method: 'POST',
                                                        headers: baseHeaders(true),
                                                        data: JSON.stringify({
                                                            field: inp.field,
                                                            master_proposal_id: prop.id,
                                                            expected_version: prop.version || 1
                                                        }),
                                                        success: function (resExec) {
                                                            const newId = resExec.newly_created && resExec.newly_created.resource_id;
                                                            const newName = (resExec.newly_created && resExec.newly_created.display_name) || masterData.name;

                                                            hiddenInput.val(newId);
                                                            selectedPill.removeClass('d-none').addClass('d-flex').find('.ai-selected-name').text(newName);
                                                            masterPanel.addClass('d-none').empty();

                                                            if (inp.field === 'warehouse_id') {
                                                                leftWhBadge.removeClass('badge-warning').addClass('badge-primary').html('<i class="dripicons-checkmark mr-1"></i>' + escapeText(newName));
                                                            }

                                                            if (resExec.can_prepare_proposal) {
                                                                $.ajax({
                                                                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/prepare-proposal',
                                                                    method: 'POST',
                                                                    headers: baseHeaders(true),
                                                                    data: JSON.stringify({}),
                                                                    success: function (resPrep) {
                                                                        refreshView('proposal', {
                                                                            status: 'proposal_created',
                                                                            proposal: resPrep.proposal,
                                                                            task: resPrep.task,
                                                                            media: mediaObj,
                                                                            extraction: ext
                                                                        });
                                                                    }
                                                                });
                                                            } else {
                                                                refreshView('clarification', resExec);
                                                            }
                                                        },
                                                        error: function (xhrExec) {
                                                            btnSaveM.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Approve & Create');
                                                            const err = xhrExec.responseJSON || {};
                                                            masterFeedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Execution failed.') + '</div>');
                                                        }
                                                    });
                                                },
                                                error: function (xhrPrev) {
                                                    btnSaveM.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Approve & Create');
                                                    const err = xhrPrev.responseJSON || {};
                                                    masterFeedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Preview failed.') + '</div>');
                                                }
                                            });
                                        });

                                        actionRow.append(btnCancelM).append(btnSaveM);
                                        formBody.append(actionRow);
                                        masterPanel.append(formBody);
                                    },
                                    error: function (xhrSpawn) {
                                        btnCreateMaster.prop('disabled', false).html('<i class="dripicons-plus mr-1"></i>Create New ' + escapeText(inp.label || 'Master'));
                                        const err = xhrSpawn.responseJSON || {};
                                        masterPanel.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Failed to load master form.') + '</div>');
                                    }
                                });
                            });
                        }
                    } else {
                        // Standard non-entity input
                        const isCostField = (inp.field && inp.field.indexOf('net_unit_cost') !== -1);
                        const isQtyField = (inp.field && inp.field.indexOf('qty') !== -1);

                        let fieldLabel = inp.label || inp.field;
                        let fieldHelp = inp.reason || inp.help_text || '';
                        let defaultVal = inp.current_value || '';

                        if (isCostField) {
                            fieldLabel = 'Unit Cost / Rate (₹)';
                            fieldHelp = 'Specify the unit purchase rate, or enter 0.00 if rate is pending supplier invoice.';
                            if (!defaultVal || defaultVal === '') {
                                defaultVal = '0.00';
                            }
                        } else if (isQtyField) {
                            fieldLabel = 'Quantity';
                            if (!defaultVal || defaultVal === '') {
                                defaultVal = '1.00';
                            }
                        }

                        grp.find('label').text(fieldLabel);
                        if (fieldHelp) {
                            if (grp.find('small').length > 0) {
                                grp.find('small').text(fieldHelp);
                            } else {
                                grp.append($('<small class="text-muted d-block mb-2"></small>').text(fieldHelp));
                            }
                        }

                        const txt = $('<input type="text" class="form-control form-control-sm ai-clarify-input">')
                            .attr('data-field', inp.field)
                            .val(defaultVal);
                        grp.append(txt);
                    }

                    inputsForm.append(grp);
                });
            } else {
                inputsForm.append('<div class="small text-muted mb-2">' + escapeText(clarifyObj.message || 'Please check and complete the missing records.') + '</div>');
            }
            rightCol.append(inputsForm);

            const feedback = $('<div class="small mb-2 ai-clarify-feedback"></div>');
            rightCol.append(feedback);

            const footer = $('<div class="pt-2 border-top d-flex justify-content-end"></div>');

            submitBtn.on('click', function () {
                submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Submitting...');
                feedback.empty();

                const corrections = [];

                // 1. Gather line items from interactive Line Items Builder if present
                const linesTable = inputsForm.find('.ai-clarify-lines-table');
                if (linesTable.length > 0) {
                    const lines = [];
                    linesTable.find('tbody tr.ai-line-item-row').each(function (idx) {
                        const row = $(this);
                        const pId = row.attr('data-product-id') ? parseInt(row.attr('data-product-id'), 10) : null;
                        const pName = row.find('.ai-line-product-search').val().trim();
                        const qty = parseFloat(row.find('.ai-line-qty').val()) || 1;
                        const rate = parseFloat(row.find('.ai-line-rate').val()) || 0;
                        if (pName !== '') {
                            lines.push({
                                client_line_id: row.attr('data-client-line-id') || ('line-' + idx),
                                product_id: pId,
                                product_name: pName,
                                product_query: pName,
                                qty: qty,
                                net_unit_cost: rate,
                                net_unit_price: rate
                            });
                        }
                    });

                    if (lines.length > 0) {
                        corrections.push({
                            field: 'items',
                            value: lines,
                            confirmation: 'user_selected'
                        });
                    }
                }

                // 2. Gather scalar and entity inputs
                inputsForm.find('.ai-clarify-entity-value, .ai-clarify-select, .ai-clarify-input').each(function () {
                    const f = $(this).attr('data-field');
                    const v = $(this).val();
                    if (f && v !== '' && f !== 'items') {
                        corrections.push({
                            field: f,
                            value: v,
                            confirmation: 'user_selected'
                        });
                    }
                });

                if (corrections.length === 0) {
                    submitBtn.prop('disabled', false).html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                    feedback.html('<div class="alert alert-warning py-1 px-2 mb-0">Please resolve the missing details before submitting.</div>');
                    return;
                }
                const expectedRevision = (taskObj && taskObj.revision) || (currentRes.task && currentRes.task.revision) || 1;

                $.ajax({
                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/inputs',
                    method: 'PATCH',
                    headers: baseHeaders(true),
                    data: JSON.stringify({
                        expected_revision: expectedRevision,
                        corrections: corrections
                    }),
                    success: function (resPatch) {
                        if (resPatch && resPatch.task) {
                            currentRes.task = resPatch.task;
                            if (taskObj) {
                                taskObj.revision = resPatch.task.revision;
                            }
                        }
                        const isReady = !!(resPatch && resPatch.evaluation && resPatch.evaluation.is_ready);
                        if (isReady) {
                            $.ajax({
                                url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/prepare-proposal',
                                method: 'POST',
                                headers: baseHeaders(true),
                                data: JSON.stringify({}),
                                success: function (resPrep) {
                                    if (resPrep && resPrep.proposal) {
                                        refreshView('proposal', {
                                            status: 'proposal_created',
                                            proposal: resPrep.proposal,
                                            task: resPrep.task,
                                            media: mediaObj,
                                            extraction: ext
                                        });
                                    } else if (resPrep && resPrep.status === 'needs_input') {
                                        refreshView('clarification', resPrep);
                                    }
                                },
                                error: function (xhrPrep) {
                                    submitBtn.prop('disabled', false).html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                                    const err = xhrPrep.responseJSON || {};
                                    feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Failed to prepare proposal.') + '</div>');
                                }
                            });
                        } else {
                            submitBtn.prop('disabled', false).html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                            refreshView('clarification', resPatch);
                        }
                    },
                    error: function (xhrPatch) {
                        submitBtn.prop('disabled', false).html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                        const err = xhrPatch.responseJSON || {};
                        if (xhrPatch.status === 409) {
                            feedback.html('<div class="alert alert-warning py-1 px-2 mb-2"><i class="dripicons-warning mr-1"></i>Task was modified in another session (revision conflict). Refreshing latest task details...</div>');
                            $.ajax({
                                url: aiBaseUrl + '/workflow-tasks/' + currentTaskId,
                                method: 'GET',
                                headers: baseHeaders(false),
                                success: function (latestTaskRes) {
                                    refreshView('clarification', latestTaskRes);
                                    $('.ai-clarify-feedback').html('<div class="alert alert-warning py-1 px-2 mb-2"><i class="dripicons-warning mr-1"></i>Task revision updated to latest version (' + (latestTaskRes.task && latestTaskRes.task.revision) + '). Please review and re-submit your details.</div>');
                                },
                                error: function () {
                                    feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Task conflict occurred. Please reload the page.') + '</div>');
                                }
                            });
                            return;
                        }
                        feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Failed to update inputs.') + '</div>');
                    }
                });
            });

            footer.append(submitBtn);
            rightCol.append(footer);
            card.append(body);
        }

        function renderEditView() {
            const proposal = currentRes.proposal || {};
            let preview = proposal.preview;
            if (!preview && proposal.preview_json) {
                try {
                    preview = typeof proposal.preview_json === 'string' ? JSON.parse(proposal.preview_json) : proposal.preview_json;
                } catch (_) { preview = {}; }
            }
            preview = preview || {};
            const ext = (currentRes.extraction && currentRes.extraction.extracted) || (res.extraction && res.extraction.extracted) || {};
            const currentTaskId = currentRes.task_id || (currentRes.task && currentRes.task.id) || taskId;
            const actionKey = currentRes.action_key || proposal.action_key || (preview && preview.action_key) || (currentRes.task && currentRes.task.action_key) || verifiedActionKey;
            const isPb = (actionKey === 'purchase_bill.create.v1');
            const isPo = (actionKey === 'purchase_order.create.v1');
            const isPurchase = isPb || isPo || actionKey.startsWith('purchase_');

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            topRow.append($('<h5 class="font-weight-bold mb-0 text-dark"></h5>').html('<i class="dripicons-pencil mr-2 text-primary"></i>Edit ' + escapeText(isPb ? 'Bill' : (isPo ? 'Purchase Order' : 'Commercial Action')) + ' Details'));
            topRow.append($('<span class="badge badge-secondary px-2 py-1">Draft Revision</span>'));
            header.append(topRow);
            card.append(header);

            const body = $('<div class="card-body p-3 bg-white"></div>');
            const formGrid = $('<div class="form-row mb-3"></div>');

            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">' + escapeText(isPurchase ? 'Supplier' : 'Customer') + '</label><input type="text" class="form-control form-control-sm ai-edit-supplier" value="' + escapeText((preview.supplier && (preview.supplier.name || preview.supplier.company_name)) || ext.supplier_name || '') + '"></div>');
            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">' + escapeText(isPb ? 'Invoice Number' : (isPo ? 'Order Reference' : 'Reference Number')) + '</label><input type="text" class="form-control form-control-sm ai-edit-invoice-no" value="' + escapeText(preview.supplier_invoice_no || preview.reference_no || ext.invoice_number || '') + '"></div>');
            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">Document Date</label><input type="date" class="form-control form-control-sm ai-edit-date" value="' + escapeText(preview.date || ext.invoice_date || '') + '"></div>');
            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">Warehouse</label><div class="input-group input-group-sm"><input type="hidden" class="ai-edit-warehouse-id" value="' + escapeText((preview.warehouse && preview.warehouse.id) || '') + '"><input type="text" class="form-control form-control-sm ai-edit-warehouse" value="' + escapeText((preview.warehouse && preview.warehouse.name) || '') + '" placeholder="Enter warehouse name or ID"><div class="input-group-append"><button type="button" class="btn btn-outline-secondary ai-btn-browse-warehouse" title="Browse warehouses"><i class="dripicons-search"></i></button></div></div><div class="ai-edit-picker-container mt-2" style="display: none;"></div></div>');
            body.append(formGrid);

            const editPickerBox = body.find('.ai-edit-picker-container');
            body.find('.ai-btn-browse-warehouse').on('click', function () {
                if (editPickerBox.is(':visible')) {
                    editPickerBox.slideUp('fast').empty();
                } else if (window.AiEntityPicker) {
                    editPickerBox.slideDown('fast');
                    window.AiEntityPicker.renderPicker(editPickerBox, {
                        entityType: 'warehouse',
                        taskId: currentTaskId,
                        taskRevision: (currentRes.task && currentRes.task.revision) || 1,
                        fieldPath: 'warehouse_id',
                        selectedId: body.find('.ai-edit-warehouse-id').val() || null,
                        initialQuery: body.find('.ai-edit-warehouse').val() || '',
                        aiBaseUrl: aiBaseUrl,
                        onSelect: function (entity) {
                            body.find('.ai-edit-warehouse-id').val(entity.id);
                            body.find('.ai-edit-warehouse').val(entity.name || entity.display);
                            editPickerBox.slideUp('fast').empty();
                        },
                        onCancel: function () {
                            editPickerBox.slideUp('fast').empty();
                        }
                    });
                }
            });

            const tableWrap = $('<div class="table-responsive my-2"></div>');
            const table = $('<table class="table table-sm table-bordered mb-0"></table>');
            table.append('<thead class="thead-light"><tr><th>Product</th><th style="width: 100px;">Qty</th><th style="width: 120px;">Rate</th></tr></thead>');
            const tbody = $('<tbody></tbody>');

            const items = preview.items || ext.lines || [];
            items.forEach(function (item, idx) {
                const tr = $('<tr></tr>');
                tr.append('<td><input type="text" class="form-control form-control-sm ai-edit-item-desc" value="' + escapeText(item.product_name || item.description || '') + '"></td>');
                tr.append('<td><input type="number" step="any" class="form-control form-control-sm ai-edit-item-qty text-right" value="' + (item.qty ?? item.quantity ?? 1) + '"></td>');
                const costVal = item.net_unit_cost ?? item.net_unit_price ?? item.unit_price ?? 0;
                tr.append('<td><input type="number" step="any" class="form-control form-control-sm ai-edit-item-rate text-right" value="' + costVal + '"></td>');
                tbody.append(tr);
            });
            table.append(tbody);
            tableWrap.append(table);
            body.append(tableWrap);

            const feedback = $('<div class="small mt-2 mb-1 ai-edit-feedback"></div>');
            body.append(feedback);

            const footer = $('<div class="pt-3 border-top d-flex justify-content-between align-items-center"></div>');
            const btnCancel = $('<button type="button" class="btn btn-sm btn-outline-secondary">Cancel</button>');
            const btnSave = $('<button type="button" class="btn btn-sm btn-success px-3 font-weight-bold"><i class="dripicons-checkmark mr-1"></i>Update & Revalidate</button>');

            btnCancel.on('click', function () {
                refreshView('proposal');
            });

            btnSave.on('click', function () {
                btnSave.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Revalidating...');
                feedback.empty();

                const corrections = [];

                const supplierVal = body.find('.ai-edit-supplier').val();
                if (supplierVal !== undefined && supplierVal.trim() !== '') {
                    corrections.push({
                        field: isPurchase ? 'supplier_id' : 'customer_id',
                        value: supplierVal.trim(),
                        confirmation: 'user_edited'
                    });
                }

                const invoiceNoVal = body.find('.ai-edit-invoice-no').val();
                if (invoiceNoVal !== undefined && invoiceNoVal.trim() !== '') {
                    corrections.push({
                        field: isPb ? 'supplier_invoice_no' : (isPo ? 'reference_no' : 'invoice_no'),
                        value: invoiceNoVal.trim(),
                        confirmation: 'user_edited'
                    });
                }

                const dateVal = body.find('.ai-edit-date').val();
                if (dateVal !== undefined && dateVal.trim() !== '') {
                    corrections.push({
                        field: 'date',
                        value: dateVal.trim(),
                        confirmation: 'user_edited'
                    });
                }

                const whIdVal = body.find('.ai-edit-warehouse-id').val();
                const warehouseVal = body.find('.ai-edit-warehouse').val();
                if (whIdVal && !isNaN(whIdVal) && Number(whIdVal) > 0) {
                    corrections.push({
                        field: 'warehouse_id',
                        value: Number(whIdVal),
                        confirmation: 'user_selected'
                    });
                } else if (warehouseVal !== undefined && warehouseVal.trim() !== '') {
                    corrections.push({
                        field: 'warehouse_id',
                        value: warehouseVal.trim(),
                        confirmation: 'user_edited'
                    });
                }

                const updatedItems = [];
                tbody.find('tr').each(function (idx) {
                    const desc = $(this).find('.ai-edit-item-desc').val().trim();
                    const qty = parseFloat($(this).find('.ai-edit-item-qty').val()) || 1;
                    const rate = parseFloat($(this).find('.ai-edit-item-rate').val()) || 0;
                    updatedItems.push({
                        client_line_id: 'line-' + idx,
                        product_query: desc,
                        qty: qty,
                        net_unit_cost: rate
                    });
                });

                if (updatedItems.length > 0) {
                    corrections.push({
                        field: 'items',
                        value: updatedItems,
                        confirmation: 'user_edited'
                    });
                }

                if (corrections.length === 0) {
                    btnSave.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Update & Revalidate');
                    feedback.html('<div class="alert alert-warning py-1 px-2 mb-0">No changes detected to update.</div>');
                    return;
                }

                const expectedRevision = (currentRes.task && currentRes.task.revision) || (currentRes.task_revision) || (taskObj && taskObj.revision) || 1;

                $.ajax({
                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/inputs',
                    method: 'PATCH',
                    headers: baseHeaders(true),
                    data: JSON.stringify({
                        expected_revision: expectedRevision,
                        corrections: corrections
                    }),
                    success: function (resPatch) {
                        if (resPatch && resPatch.task) {
                            currentRes.task = resPatch.task;
                            if (taskObj) {
                                taskObj.revision = resPatch.task.revision;
                            }
                        }
                        const isReady = !!(resPatch && resPatch.evaluation && resPatch.evaluation.is_ready);
                        if (isReady) {
                            $.ajax({
                                url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/prepare-proposal',
                                method: 'POST',
                                headers: baseHeaders(true),
                                data: JSON.stringify({}),
                                success: function (resPrep) {
                                    refreshView('proposal', {
                                        status: 'proposal_created',
                                        proposal: resPrep.proposal,
                                        task: resPrep.task,
                                        media: mediaObj,
                                        extraction: ext
                                    });
                                },
                                error: function (xhrPrep) {
                                    btnSave.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Update & Revalidate');
                                    const err = xhrPrep.responseJSON || {};
                                    feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Validation failed.') + '</div>');
                                }
                            });
                        } else {
                            btnSave.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Update & Revalidate');
                            refreshView('clarification', resPatch);
                        }
                    },
                    error: function (xhrPatch) {
                        btnSave.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Update & Revalidate');
                        const err = xhrPatch.responseJSON || {};
                        if (xhrPatch.status === 409) {
                            feedback.html('<div class="alert alert-warning py-1 px-2 mb-2"><i class="dripicons-warning mr-1"></i>Task was modified in another session (revision conflict). Refreshing latest task details...</div>');
                            $.ajax({
                                url: aiBaseUrl + '/workflow-tasks/' + currentTaskId,
                                method: 'GET',
                                headers: baseHeaders(false),
                                success: function (latestTaskRes) {
                                    refreshView('clarification', latestTaskRes);
                                    $('.ai-clarify-feedback').html('<div class="alert alert-warning py-1 px-2 mb-2"><i class="dripicons-warning mr-1"></i>Task revision updated to latest version (' + (latestTaskRes.task && latestTaskRes.task.revision) + '). Please review and re-submit your details.</div>');
                                },
                                error: function () {
                                    feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Task conflict occurred. Please reload the page.') + '</div>');
                                }
                            });
                            return;
                        }
                        feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Failed to update details.') + '</div>');
                    }
                });
            });

            footer.append(btnCancel).append(btnSave);
            body.append(footer);
            card.append(body);
        }

        // Initialize view based on initial status
        const initialStatus = res.status || ((res.clarification || (res.task && res.task.status === 'needs_input')) ? 'needs_input' : 'proposal_created');
        if (initialStatus === 'needs_input') {
            refreshView('clarification', res);
        } else {
            refreshView('proposal', res);
        }
    }

    function renderExtractionCard(media, extraction, companionText) {
        const ws = getWs();
        const container = $('<div class="d-flex mb-3 justify-content-start"></div>');
        const bubble = $('<div class="chat-bubble chat-assistant shadow-sm w-100 ai-media-review-bubble"></div>');
        const card = $('<div class="card border shadow-none ai-media-review-card" data-media-id="' + escapeText(media.id) + '"></div>');

        const isAudio = media.source_kind === 'audio';
        let data = extraction.extracted || {};
        let currentRevision = Number(extraction.revision || 1);
        let confirmationStatus = extraction.confirmation_status || 'needs_review';

        const perceptionModelKey = extraction.model_key || media.model_key || cfg.multimodalModel || 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning';
        const perceptionProvider = extraction.model_provider || 'nvidia';
        const perceptionDisplayName = formatModelDisplayName(perceptionModelKey, perceptionProvider);

        // Dynamic Document Title based on extraction classification
        let docTypeTitle = 'Document Extraction Review';
        if (isAudio) {
            docTypeTitle = 'Voice Instruction Review';
        } else if (data.document_type === 'purchase_bill') {
            docTypeTitle = 'Purchase Bill Review';
        } else if (data.document_type === 'purchase_order') {
            docTypeTitle = 'Purchase Order Review';
        } else if (data.document_type === 'sales_invoice') {
            docTypeTitle = 'Sales Invoice Review';
        } else if (data.document_type === 'sales_order') {
            docTypeTitle = 'Sales Order Review';
        }

        const subtitleText = [
            data.invoice_number,
            data.supplier_name || data.buyer_name || data.party_name || media.file_name || 'Document'
        ].filter(Boolean).join(' · ');

        // Card Header (Title, Subtitle, Status Pill, Model Attribution)
        const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
        const topRow = $('<div class="d-flex justify-content-between align-items-start flex-wrap"></div>');
        const titleWrap = $('<div></div>');
        titleWrap.append($('<h5 class="font-weight-bold mb-1 text-dark"></h5>').text(docTypeTitle));
        if (subtitleText) {
            titleWrap.append($('<div class="text-muted small ai-card-subtitle"></div>').text(subtitleText));
        }
        topRow.append(titleWrap);

        const statusBadge = $('<span class="ai-status-pill"></span>')
            .addClass(confirmationStatus === 'confirmed' ? 'ai-status-pill-confirmed' : 'ai-status-pill-awaiting')
            .text(confirmationStatus === 'confirmed' ? 'Confirmed' : 'Awaiting Review');
        topRow.append(statusBadge);
        header.append(topRow);

        const modelLine = $('<div class="small text-muted mt-2 d-flex align-items-center"></div>');
        modelLine.append('<i class="dripicons-view-thumb mr-1 text-primary" aria-hidden="true"></i>');
        modelLine.append(document.createTextNode('Extracted by ' + perceptionDisplayName));
        header.append(modelLine);
        card.append(header);

        const body = $('<div class="card-body p-3 bg-white"></div>');

        // KPI Summary Tiles (Subtotal, GST, Total)
        let kpiRow = null;
        if (!isAudio) {
            kpiRow = $('<div class="ai-kpi-summary-row"></div>');
            kpiRow.append($('<div class="ai-kpi-card"></div>')
                .append('<div class="ai-kpi-label">Subtotal</div>')
                .append($('<div class="ai-kpi-val ai-kpi-subtotal"></div>').text(formatCurrencyVal(data.subtotal))));

            kpiRow.append($('<div class="ai-kpi-card"></div>')
                .append('<div class="ai-kpi-label">GST</div>')
                .append($('<div class="ai-kpi-val ai-kpi-gst"></div>').text(formatCurrencyVal(data.tax_amount))));

            kpiRow.append($('<div class="ai-kpi-card"></div>')
                .append('<div class="ai-kpi-label">Total</div>')
                .append($('<div class="ai-kpi-val ai-kpi-total"></div>').text(formatCurrencyVal(data.total_amount))));

            body.append(kpiRow);
        }

        // Collapsible Document / Audio Preview
        const previewWrap = $('<div class="mb-2"></div>');
        const btnTogglePreview = $('<button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 mb-2 ai-btn-toggle-preview"><i class="dripicons-preview mr-1"></i><span>Preview Document</span></button>');
        const previewBox = $('<div class="border rounded p-2 bg-light text-center ai-media-preview-collapsible mb-3 d-none"></div>');
        if (isAudio) {
            btnTogglePreview.html('<i class="dripicons-microphone mr-1"></i><span>Listen to Voice Note</span>');
            const audioEl = $('<audio controls class="w-100"></audio>').attr('src', media.preview_url);
            previewBox.append(audioEl);
            previewBox.append($('<div class="small text-muted mt-1"></div>').text('Normalized 16kHz Mono WAV · ' + (media.duration_seconds || '?') + 's'));
        } else {
            const imgEl = $('<img class="img-fluid rounded ai-media-preview-img" alt="Private document preview">').attr('src', media.preview_url);
            previewBox.append(imgEl);
            if (Array.isArray(media.pages) && media.pages.length > 1) {
                const pager = $('<div class="btn-group btn-group-sm mt-2 flex-wrap" role="group" aria-label="Document pages"></div>');
                media.pages.forEach(function (pg) {
                    const pBtn = $('<button type="button" class="btn btn-outline-secondary"></button>')
                        .toggleClass('active', pg.page === 1)
                        .text('Pg ' + pg.page);
                    pBtn.on('click', function () {
                        pager.find('button').removeClass('active');
                        $(this).addClass('active');
                        imgEl.attr('src', pg.preview_url);
                    });
                    pager.append(pBtn);
                });
                previewBox.append(pager);
            }
        }
        btnTogglePreview.on('click', function () {
            previewBox.toggleClass('d-none');
            const isShown = !previewBox.hasClass('d-none');
            $(this).find('span').text(isShown ? 'Hide Preview' : (isAudio ? 'Listen to Voice Note' : 'Preview Document'));
        });
        previewWrap.append(btnTogglePreview).append(previewBox);
        body.append(previewWrap);

        // Content Area (Full-Width View Table + Hidden Edit Pane)
        const formCol = $('<div class="w-100"></div>');
        if (isAudio) {
            renderAudioEditor(formCol, data);
        } else {
            renderInvoiceEditor(formCol, data);
        }
        body.append(formCol);

        // Warnings Section (Company Mismatch, Unverified Checks)
        const warnContainer = $('<div class="ai-media-warnings mt-2 mb-2"></div>');
        renderWarnings(warnContainer, data.warnings || []);
        body.append(warnContainer);

        // Companion Instruction Input
        const companionGroup = $('<div class="form-group mt-3 mb-2"></div>');
        companionGroup.append('<label class="small font-weight-bold text-muted mb-1">Optional Instruction / Warehouse Note</label>');
        const initialCompanion = companionText || (data.companion_text || '');
        const companionInput = $('<input type="text" class="form-control form-control-sm ai-media-companion-input" placeholder="e.g., Read this bill and prepare a Purchase Order">')
            .val(initialCompanion);
        companionGroup.append(companionInput);
        body.append(companionGroup);

        const isPurchaseBill = (data.document_type === 'purchase_bill');
        const canDirectBill = !!(cfg && cfg.allowDirectPurchaseBill);

        // Direct Purchase Bill Posting Restriction Notice
        const restrictionAlert = $('<div class="alert alert-warning py-2 px-3 small border-warning mt-2 mb-2" style="background-color: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 6px;"></div>')
            .html('Direct Purchase Bill posting from AI is not enabled yet. Review the extracted bill above, or explicitly ask to draft a Purchase Order (<code>purchase_order.create.v1</code>) instead. (To enable direct bills, set <code>AI_MULTIMODAL_ALLOW_DIRECT_PURCHASE_BILL=true</code> in <code>.env</code>).');
        if (isPurchaseBill && !canDirectBill) {
            restrictionAlert.removeClass('d-none');
        } else {
            restrictionAlert.addClass('d-none');
        }
        body.append(restrictionAlert);

        // Action Feedback Area
        const actionFeedback = $('<div class="ai-media-action-feedback small mb-2"></div>');
        body.append(actionFeedback);

        // Footer Actions
        const footer = $('<div class="d-flex flex-wrap justify-content-between align-items-center pt-3 border-top mt-2"></div>');
        const leftBtns = $('<div class="d-flex flex-wrap align-items-center"></div>');
        const rightBtns = $('<div class="d-flex flex-wrap align-items-center"></div>');

        const btnConfirm = $('<button type="button" class="btn btn-sm btn-primary mr-2 mb-1 ai-btn-media-confirm">Confirm Extraction</button>')
            .prop('disabled', confirmationStatus === 'confirmed');

        const btnPreparePo = $('<button type="button" class="btn btn-sm btn-primary mr-2 mb-1 ai-btn-media-prepare-po">Prepare Purchase Order</button>')
            .prop('disabled', confirmationStatus !== 'confirmed')
            .toggleClass('d-none', !isPurchaseBill || canDirectBill);

        const handoffLabel = (isPurchaseBill && canDirectBill) ? 'Stage Purchase Bill Proposal' : 'Stage ERP Proposal';
        const btnHandoff = $('<button type="button" class="btn btn-sm btn-success mr-2 mb-1 ai-btn-media-handoff">' + handoffLabel + '</button>')
            .prop('disabled', confirmationStatus !== 'confirmed')
            .toggleClass('d-none', isPurchaseBill && !canDirectBill);

        const btnToggleEdit = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-1 ai-btn-media-toggle-edit">Edit Details</button>');
        const btnSave = $('<button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-1 ai-btn-media-save d-none">Save Edits</button>');
        const btnAttach = $('<button type="button" class="btn btn-sm btn-outline-info mr-2 mb-1 ai-btn-media-attach">Attach to Chat</button>');
        const btnDiscard = $('<button type="button" class="btn btn-sm btn-outline-danger mb-1 ai-btn-media-discard">Discard Media</button>');

        leftBtns.append(btnConfirm).append(btnPreparePo).append(btnHandoff).append(btnToggleEdit).append(btnSave);
        rightBtns.append(btnAttach).append(btnDiscard);
        footer.append(leftBtns).append(rightBtns);
        body.append(footer);
        card.append(body);
        bubble.append(card);
        container.append(bubble);

        $('.ai-welcome-box').remove();
        $('#chat-messages').append(container);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        function refreshCardState(updatedMedia, updatedExtraction) {
            currentRevision = Number(updatedExtraction.revision || currentRevision);
            confirmationStatus = updatedExtraction.confirmation_status || confirmationStatus;
            data = updatedExtraction.extracted || data;

            statusBadge
                .removeClass('ai-status-pill-awaiting ai-status-pill-confirmed')
                .addClass(confirmationStatus === 'confirmed' ? 'ai-status-pill-confirmed' : 'ai-status-pill-awaiting')
                .text(confirmationStatus === 'confirmed' ? 'Confirmed' : 'Awaiting Review');

            btnConfirm.prop('disabled', confirmationStatus === 'confirmed');

            const isPb = (data.document_type === 'purchase_bill');
            if (isPb && !canDirectBill) {
                restrictionAlert.removeClass('d-none');
                btnPreparePo.prop('disabled', confirmationStatus !== 'confirmed').removeClass('d-none');
                btnHandoff.addClass('d-none');
            } else if (isPb && canDirectBill) {
                restrictionAlert.addClass('d-none');
                btnPreparePo.addClass('d-none');
                btnHandoff.prop('disabled', confirmationStatus !== 'confirmed').removeClass('d-none').text('Stage Purchase Bill Proposal');
            } else {
                restrictionAlert.addClass('d-none');
                btnPreparePo.addClass('d-none');
                btnHandoff.prop('disabled', confirmationStatus !== 'confirmed').removeClass('d-none').text('Stage ERP Proposal');
            }

            // Refresh KPI Tiles
            card.find('.ai-kpi-subtotal').text(formatCurrencyVal(data.subtotal));
            card.find('.ai-kpi-gst').text(formatCurrencyVal(data.tax_amount));
            card.find('.ai-kpi-total').text(formatCurrencyVal(data.total_amount));

            renderWarnings(warnContainer, data.warnings || []);
        }

        btnToggleEdit.on('click', function () {
            const editPane = formCol.find('.ai-extraction-edit-pane');
            const viewPane = formCol.find('.ai-extraction-view-pane');
            editPane.toggleClass('d-none');
            viewPane.toggleClass('d-none');
            const isEditing = !editPane.hasClass('d-none');
            btnToggleEdit.text(isEditing ? 'Done Editing' : 'Edit Details');
            btnSave.toggleClass('d-none', !isEditing);
        });

        // Inline link in view table to open editor
        formCol.on('click', '.ai-btn-inline-toggle-edit', function (e) {
            e.preventDefault();
            btnToggleEdit.trigger('click');
        });

        btnSave.on('click', function () {
            const payload = collectEditorPatch(formCol, isAudio);
            payload.expected_revision = currentRevision;
            payload.companion_text = companionInput.val().trim() || null;

            btnSave.prop('disabled', true).text('Saving...');
            actionFeedback.empty();

            $.ajax({
                url: mediaUrl('/' + encodeURIComponent(media.id) + '/extraction'),
                method: 'PATCH',
                headers: baseHeaders(true),
                data: JSON.stringify(payload),
                success: function (res) {
                    refreshCardState(res.media, res.extraction);
                    if (isAudio) {
                        renderAudioEditor(formCol, res.extraction.extracted || {});
                    } else {
                        renderInvoiceEditor(formCol, res.extraction.extracted || {});
                    }
                    actionFeedback.html('<span class="text-success font-weight-medium">Saved Revision ' + currentRevision + '.</span>');
                },
                error: function (xhr) {
                    handleMediaActionError(xhr, actionFeedback, media.id, refreshCardState, formCol, isAudio);
                },
                complete: function () {
                    btnSave.prop('disabled', false).text('Save Edits');
                }
            });
        });

        btnConfirm.on('click', function () {
            const payload = collectEditorPatch(formCol, isAudio);
            payload.expected_revision = currentRevision;
            payload.companion_text = companionInput.val().trim() || null;

            btnConfirm.prop('disabled', true).text('Confirming...');
            actionFeedback.empty();

            $.ajax({
                url: mediaUrl('/' + encodeURIComponent(media.id) + '/confirm'),
                method: 'POST',
                headers: baseHeaders(true),
                data: JSON.stringify(payload),
                success: function (res) {
                    refreshCardState(res.media, res.extraction);
                    attachMediaForChat(res.media, res.extraction);
                    if (isAudio) {
                        renderAudioEditor(formCol, res.extraction.extracted || {});
                    } else {
                        renderInvoiceEditor(formCol, res.extraction.extracted || {});
                    }
                    const nextAction = (data.document_type === 'purchase_bill' && !canDirectBill) ? 'Prepare Purchase Order' : (data.document_type === 'purchase_bill' ? 'Stage Purchase Bill Proposal' : 'Stage ERP Proposal');
                    actionFeedback.html('<span class="text-success font-weight-bold">Confirmed at Revision ' + currentRevision + '. You can now click "' + nextAction + '" or ask Copilot below.</span>');
                },
                error: function (xhr) {
                    handleMediaActionError(xhr, actionFeedback, media.id, refreshCardState, formCol, isAudio);
                },
                complete: function () {
                    btnConfirm.prop('disabled', confirmationStatus === 'confirmed').text('Confirm Extraction');
                }
            });
        });

        function executeHandoff(actionKey) {
            const isPO = (actionKey === 'purchase_order.create.v1');
            const activeBtn = isPO ? btnPreparePo : btnHandoff;
            const originalText = isPO ? 'Prepare Purchase Order' : ((data.document_type === 'purchase_bill' && canDirectBill) ? 'Stage Purchase Bill Proposal' : 'Stage ERP Proposal');
            activeBtn.prop('disabled', true).text('Staging...');
            actionFeedback.empty();

            const bodyPayload = {
                companion_text: companionInput.val().trim() || null,
                thread_id: (ws && ws.getCurrentThreadId && ws.getCurrentThreadId()) || null,
            };
            if (actionKey) {
                bodyPayload.action_key = actionKey;
            }

            $.ajax({
                url: mediaUrl('/' + encodeURIComponent(media.id) + '/handoff'),
                method: 'POST',
                headers: baseHeaders(true),
                data: JSON.stringify(bodyPayload),
                success: function (res) {
                    if (res.status === 'needs_input' && res.clarification && ws && ws.appendMessage) {
                        ws.appendMessage(
                            'assistant',
                            res.clarification.message || 'Additional details are required before staging this proposal.',
                            [],
                            [],
                            [{ tool: 'workflow.clarification.v1', as_of: '', data: res.clarification }]
                        );
                    } else if (res.status === 'proposal_created' && res.proposal && ws && ws.appendMessage) {
                        ws.appendMessage(
                            'assistant',
                            'Prepared a draft `' + (res.action_key || 'workflow') + '` proposal from verified extraction for your human approval.',
                            [],
                            [],
                            [{
                                tool: res.action_key,
                                as_of: (res.proposal.preview && res.proposal.preview.date) || '',
                                data: Object.assign({}, res.proposal.preview || {}, {
                                    proposal_id: res.proposal.proposal_id,
                                    version: res.proposal.version,
                                    action_key: res.action_key,
                                    task_id: res.task_id
                                })
                            }]
                        );
                    } else if (res.message) {
                        actionFeedback.append($('<div class="text-info"></div>').text(res.message));
                    }
                },
                error: function (xhr) {
                    const err = xhr.responseJSON || {};
                    const msg = err.error_message || err.message || 'Workflow handoff failed.';
                    actionFeedback.empty().append($('<div class="alert alert-warning py-1 px-2 mb-0"></div>').text(msg));
                },
                complete: function () {
                    activeBtn.prop('disabled', confirmationStatus !== 'confirmed').text(originalText);
                }
            });
        }

        btnPreparePo.on('click', function () {
            executeHandoff('purchase_order.create.v1');
        });

        btnHandoff.on('click', function () {
            const isPb = (data.document_type === 'purchase_bill');
            executeHandoff(isPb && canDirectBill ? 'purchase_bill.create.v1' : null);
        });

        btnAttach.on('click', function () {
            attachMediaForChat(media, { revision: currentRevision });
            actionFeedback.html('<span class="text-info">Attached to your next chat message.</span>');
        });

        btnDiscard.on('click', function () {
            btnDiscard.prop('disabled', true).text('Deleting...');
            $.ajax({
                url: mediaUrl('/' + encodeURIComponent(media.id)),
                method: 'DELETE',
                headers: baseHeaders(true),
                success: function () {
                    attachedMedia.delete(media.id);
                    renderAttachedChips();
                    card.replaceWith('<div class="text-muted small py-2"><i class="dripicons-trash mr-1"></i>Media asset purged from private storage.</div>');
                },
                error: function () {
                    btnDiscard.prop('disabled', false).text('Discard Media');
                }
            });
        });
    }

    function handleMediaActionError(xhr, feedbackBox, mediaId, refreshCallback, formCol, isAudio) {
        const err = xhr.responseJSON || {};
        if (xhr.status === 409 && err.error_code === 'REVISION_CONFLICT') {
            feedbackBox.empty().append(
                $('<div class="alert alert-danger py-1 px-2 mb-0"></div>')
                    .text((err.error_message || 'Revision conflict detected.') + ' Reloading latest saved revision...')
            );
            $.ajax({
                url: mediaUrl('/' + encodeURIComponent(mediaId) + '/extraction'),
                method: 'GET',
                headers: baseHeaders(false),
                success: function (latest) {
                    if (latest && latest.extraction) {
                        refreshCallback(latest.media, latest.extraction);
                        formCol.empty();
                        if (isAudio) {
                            renderAudioEditor(formCol, latest.extraction.extracted || {});
                        } else {
                            renderInvoiceEditor(formCol, latest.extraction.extracted || {});
                        }
                    }
                }
            });
            return;
        }
        feedbackBox.empty().append(
            $('<div class="alert alert-danger py-1 px-2 mb-0"></div>').text(err.error_message || err.message || 'Update failed.')
        );
    }

    function renderWarnings(container, warnings) {
        container.empty();
        if (!Array.isArray(warnings) || warnings.length === 0) {
            return;
        }
        warnings.forEach(function (w) {
            const callout = $('<div class="ai-warning-callout d-flex align-items-center mb-2"></div>');
            callout.append('<i class="dripicons-warning mr-2 text-warning" aria-hidden="true"></i>');
            callout.append($('<span></span>').text(w));
            container.append(callout);
        });
    }

    function renderInvoiceEditor(formCol, data) {
        formCol.empty();

        // 1. Clean View Table (matching mockup media_1794636475790_42e57597.png)
        const viewPane = $('<div class="ai-extraction-view-pane"></div>');
        const viewTableWrap = $('<div class="ai-extraction-table-wrapper"></div>');
        const viewTable = $('<table class="table table-borderless table-sm ai-extraction-table mb-0"></table>');
        viewTable.append('<thead><tr><th style="min-width: 180px;">Product</th><th class="text-right" style="width: 80px;">Qty</th><th class="text-right" style="width: 110px;">Rate</th></tr></thead>');
        const viewTbody = $('<tbody></tbody>');

        const lines = Array.isArray(data.lines) ? data.lines : [];
        if (lines.length > 0) {
            lines.forEach(function (line) {
                const tr = $('<tr></tr>');
                tr.append($('<td class="font-weight-medium"></td>').text(line.description || 'Unnamed item'));
                tr.append($('<td class="text-right"></td>').text(line.quantity == null ? '—' : String(line.quantity)));
                tr.append($('<td class="text-right"></td>').text(line.unit_price == null ? '—' : formatCurrencyVal(line.unit_price)));
                viewTbody.append(tr);
            });
        } else {
            viewTbody.append('<tr><td colspan="3" class="text-center text-muted small py-2">No product lines detected in document.</td></tr>');
        }
        viewTable.append(viewTbody);
        viewTableWrap.append(viewTable);
        viewPane.append(viewTableWrap);

        const editLinkWrap = $('<div class="text-right mb-2"></div>');
        editLinkWrap.append('<a href="javascript:void(0);" class="small text-muted ai-btn-inline-toggle-edit"><i class="dripicons-pencil mr-1"></i> Edit extracted fields and lines</a>');
        viewPane.append(editLinkWrap);
        formCol.append(viewPane);

        // 2. Full Edit Pane (initially hidden, revealed on Edit Details)
        const editPane = $('<div class="ai-extraction-edit-pane border rounded p-3 bg-light mb-3 d-none"></div>');
        editPane.append('<h6 class="font-weight-bold text-dark small mb-2"><i class="dripicons-pencil mr-1 text-primary"></i> Edit Document Details</h6>');

        const grid = $('<div class="form-row"></div>');
        const fields = [
            { key: 'document_type', label: 'Document Type', col: 'col-sm-4' },
            { key: 'supplier_name', label: 'Supplier / Party', col: 'col-sm-8' },
            { key: 'invoice_number', label: 'Invoice / Ref #', col: 'col-sm-4' },
            { key: 'invoice_date', label: 'Date (YYYY-MM-DD)', col: 'col-sm-4' },
            { key: 'supplier_gstin', label: 'Supplier GSTIN', col: 'col-sm-4' },
            { key: 'subtotal', label: 'Subtotal', col: 'col-sm-4' },
            { key: 'tax_amount', label: 'Tax Amount', col: 'col-sm-4' },
            { key: 'total_amount', label: 'Printed Total', col: 'col-sm-4' },
        ];

        fields.forEach(function (f) {
            const grp = $('<div class="form-group mb-2"></div>').addClass(f.col);
            grp.append($('<label class="small text-muted mb-1 font-weight-bold"></label>').text(f.label));

            if (f.key === 'document_type') {
                const sel = $('<select class="form-control form-control-sm ai-ext-scalar" data-field="document_type"></select>');
                const docTypes = [
                    { val: 'purchase_bill', label: 'Purchase Bill (Supplier Invoice)' },
                    { val: 'purchase_order', label: 'Purchase Order' },
                    { val: 'sales_invoice', label: 'Sales Invoice (Customer Bill)' },
                    { val: 'sales_order', label: 'Sales Order' },
                    { val: 'quotation', label: 'Quotation / Estimate' },
                    { val: 'unknown', label: 'Unknown / Other' },
                ];
                docTypes.forEach(function (dt) {
                    const opt = $('<option></option>').attr('value', dt.val).text(dt.label);
                    if (data[f.key] === dt.val) {
                        opt.prop('selected', true);
                    }
                    sel.append(opt);
                });
                grp.append(sel);
            } else {
                const input = $('<input type="text" class="form-control form-control-sm ai-ext-scalar">')
                    .attr('data-field', f.key)
                    .val(data[f.key] == null ? '' : String(data[f.key]));
                grp.append(input);
            }
            grid.append(grp);
        });
        editPane.append(grid);

        const editTableWrap = $('<div class="table-responsive mt-2"></div>');
        const editTable = $('<table class="table table-sm table-bordered bg-white mb-0 ai-fact-table"></table>');
        editTable.append('<thead class="thead-light"><tr><th style="min-width: 180px;">Description</th><th style="width:80px;">Qty</th><th style="width:75px;">UOM</th><th style="width:105px;">Unit Price</th><th style="width:110px;">Line Total</th></tr></thead>');
        const editTbody = $('<tbody></tbody>');

        lines.forEach(function (line, idx) {
            const lineId = line.client_line_id || ('line_' + (idx + 1));
            const tr = $('<tr class="ai-ext-line-row"></tr>').attr('data-line-id', lineId);
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-line-desc">').val(line.description || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-line-qty">').val(line.quantity == null ? '' : String(line.quantity))));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-line-uom">').val(line.uom || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-line-price">').val(line.unit_price == null ? '' : String(line.unit_price))));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-line-total">').val(line.line_total == null ? '' : String(line.line_total))));
            editTbody.append(tr);
        });

        if (lines.length === 0) {
            editTbody.append('<tr><td colspan="5" class="text-center text-muted small py-2">No line items detected.</td></tr>');
        }

        editTable.append(editTbody);
        editTableWrap.append(editTable);
        editPane.append(editTableWrap);
        formCol.append(editPane);
    }

    function renderAudioEditor(formCol, data) {
        formCol.empty();

        const viewPane = $('<div class="ai-extraction-view-pane"></div>');
        if (data.transcript_text) {
            viewPane.append($('<div class="p-2 mb-2 rounded bg-light border text-dark font-italic small"></div>')
                .append('<i class="dripicons-quote mr-1 text-muted"></i>')
                .append(document.createTextNode(data.transcript_text)));
        }

        const items = Array.isArray(data.items) ? data.items : [];
        const viewTableWrap = $('<div class="ai-extraction-table-wrapper"></div>');
        const viewTable = $('<table class="table table-borderless table-sm ai-extraction-table mb-0"></table>');
        viewTable.append('<thead><tr><th style="min-width: 180px;">Product</th><th class="text-right" style="width: 80px;">Qty</th><th class="text-right" style="width: 110px;">Rate</th></tr></thead>');
        const viewTbody = $('<tbody></tbody>');

        if (items.length > 0) {
            items.forEach(function (item) {
                const tr = $('<tr></tr>');
                tr.append($('<td class="font-weight-medium"></td>').text(item.product_name || 'Unnamed item'));
                tr.append($('<td class="text-right"></td>').text(item.quantity == null ? '—' : String(item.quantity)));
                tr.append($('<td class="text-right"></td>').text(item.unit_price == null ? '—' : formatCurrencyVal(item.unit_price)));
                viewTbody.append(tr);
            });
        } else {
            viewTbody.append('<tr><td colspan="3" class="text-center text-muted small py-2">No product items detected in audio.</td></tr>');
        }
        viewTable.append(viewTbody);
        viewTableWrap.append(viewTable);
        viewPane.append(viewTableWrap);

        const editLinkWrap = $('<div class="text-right mb-2"></div>');
        editLinkWrap.append('<a href="javascript:void(0);" class="small text-muted ai-btn-inline-toggle-edit"><i class="dripicons-pencil mr-1"></i> Edit transcript &amp; items</a>');
        viewPane.append(editLinkWrap);
        formCol.append(viewPane);

        // Edit pane for audio
        const editPane = $('<div class="ai-extraction-edit-pane border rounded p-3 bg-light mb-3 d-none"></div>');
        editPane.append('<h6 class="font-weight-bold text-dark small mb-2"><i class="dripicons-pencil mr-1 text-primary"></i> Edit Voice Transcript &amp; Items</h6>');

        const txtGrp = $('<div class="form-group mb-2"></div>');
        txtGrp.append('<label class="small text-muted mb-1 font-weight-bold">Transcript Text (edit if misheard)</label>');
        txtGrp.append($('<textarea rows="3" class="form-control form-control-sm ai-ext-scalar" data-field="transcript_text"></textarea>').val(data.transcript_text || ''));
        editPane.append(txtGrp);

        const grid = $('<div class="form-row"></div>');
        [
            { key: 'intent_guess', label: 'Workflow Intent', col: 'col-sm-4' },
            { key: 'party_name', label: 'Supplier / Customer', col: 'col-sm-4' },
            { key: 'warehouse_name', label: 'Warehouse', col: 'col-sm-4' },
        ].forEach(function (f) {
            const grp = $('<div class="form-group mb-2"></div>').addClass(f.col);
            grp.append($('<label class="small text-muted mb-1 font-weight-bold"></label>').text(f.label));
            grp.append($('<input type="text" class="form-control form-control-sm ai-ext-scalar">').attr('data-field', f.key).val(data[f.key] == null ? '' : String(data[f.key])));
            grid.append(grp);
        });
        editPane.append(grid);

        const editTableWrap = $('<div class="table-responsive mt-2"></div>');
        const editTable = $('<table class="table table-sm table-bordered bg-white mb-0 ai-fact-table"></table>');
        editTable.append('<thead class="thead-light"><tr><th style="min-width: 180px;">Product</th><th style="width:85px;">Qty</th><th style="width:75px;">UOM</th><th style="width:105px;">Rate</th></tr></thead>');
        const editTbody = $('<tbody></tbody>');
        items.forEach(function (item, idx) {
            const lineId = item.client_line_id || ('line_' + (idx + 1));
            const tr = $('<tr class="ai-ext-item-row"></tr>').attr('data-line-id', lineId);
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-item-name">').val(item.product_name || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-item-qty">').val(item.quantity == null ? '' : String(item.quantity))));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-item-uom">').val(item.uom || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-item-price">').val(item.unit_price == null ? '' : String(item.unit_price))));
            editTbody.append(tr);
        });
        if (items.length === 0) {
            editTbody.append('<tr><td colspan="4" class="text-center text-muted small py-2">No items detected.</td></tr>');
        }
        editTable.append(editTbody);
        editTableWrap.append(editTable);
        editPane.append(editTableWrap);
        formCol.append(editPane);
    }

    function collectEditorPatch(formCol, isAudio) {
        const patch = {};
        formCol.find('.ai-ext-scalar').each(function () {
            const field = $(this).attr('data-field');
            if (field) {
                patch[field] = $(this).val().trim() || null;
            }
        });

        if (isAudio) {
            const itemsPatch = [];
            formCol.find('.ai-ext-item-row').each(function () {
                itemsPatch.push({
                    client_line_id: $(this).attr('data-line-id'),
                    product_name: $(this).find('.ai-item-name').val().trim() || null,
                    quantity: $(this).find('.ai-item-qty').val().trim() || null,
                    uom: $(this).find('.ai-item-uom').val().trim() || null,
                    unit_price: $(this).find('.ai-item-price').val().trim() || null,
                });
            });
            if (itemsPatch.length > 0) {
                patch.items_patch = itemsPatch;
            }
        } else {
            const linesPatch = [];
            formCol.find('.ai-ext-line-row').each(function () {
                linesPatch.push({
                    client_line_id: $(this).attr('data-line-id'),
                    description: $(this).find('.ai-line-desc').val().trim() || null,
                    quantity: $(this).find('.ai-line-qty').val().trim() || null,
                    uom: $(this).find('.ai-line-uom').val().trim() || null,
                    unit_price: $(this).find('.ai-line-price').val().trim() || null,
                    line_total: $(this).find('.ai-line-total').val().trim() || null,
                });
            });
            if (linesPatch.length > 0) {
                patch.lines_patch = linesPatch;
            }
        }

        return patch;
    }

    // Bind File Picker (stages file instead of immediate extraction)
    $('#btn-ai-attach-media').on('click', function () {
        $('#ai-media-file-input').trigger('click');
    });

    $('#ai-media-file-input').on('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        this.value = '';
        stageFile(file, file.name);
    });

    // Drag & drop support onto composer footer (stages file)
    $('#chat-form').on('dragover', function (e) {
        e.preventDefault();
        $(this).addClass('ai-drop-active');
    }).on('dragleave drop', function (e) {
        e.preventDefault();
        $(this).removeClass('ai-drop-active');
        if (e.type === 'drop' && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files.length > 0) {
            const f = e.originalEvent.dataTransfer.files[0];
            stageFile(f, f.name);
        }
    });

    // Voice Recording Lifecycle (explicit consent, stages audio on stop/attach)
    $('#btn-ai-record-audio').on('click', async function () {
        const tray = $('#ai-voice-recorder-tray');
        tray.removeClass('d-none');

        const hasAudioApi = (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) &&
            (typeof MediaRecorder !== 'undefined' || !!(window.AudioContext || window.webkitAudioContext));

        if (!hasAudioApi) {
            showRecorderStatus('Browser voice recording is unavailable here. Use the paperclip button to upload a WAV/WebM audio file or type your prompt below.', true);
            return;
        }

        try {
            resetRecorderState(false);
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });

            let sampleRate = 16000;
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                try {
                    audioContext = new AudioContextClass();
                    sampleRate = audioContext.sampleRate || 16000;
                    audioSource = audioContext.createMediaStreamSource(mediaStream);
                    audioProcessor = audioContext.createScriptProcessor(4096, 1, 1);
                    audioPcmChunks = [];
                    isPcmRecording = true;
                    audioProcessor.onaudioprocess = function (e) {
                        if (!isPcmRecording) return;
                        const channelData = e.inputBuffer.getChannelData(0);
                        audioPcmChunks.push(new Float32Array(channelData));
                    };
                    audioSource.connect(audioProcessor);
                    audioProcessor.connect(audioContext.destination);
                } catch (audioCtxErr) {
                    console.warn('AudioContext PCM capture unavailable, falling back to MediaRecorder:', audioCtxErr);
                    stopAudioContext();
                }
            }

            // Also keep MediaRecorder as fallback or companion
            let preferredMime = '';
            if (typeof MediaRecorder !== 'undefined') {
                preferredMime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                    ? 'audio/webm;codecs=opus'
                    : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');
                try {
                    mediaRecorder = preferredMime ? new MediaRecorder(mediaStream, { mimeType: preferredMime }) : new MediaRecorder(mediaStream);
                    recordedMime = preferredMime ? preferredMime.split(';')[0] : 'audio/webm';
                    recordedChunks = [];
                    mediaRecorder.ondataavailable = function (ev) {
                        if (ev.data && ev.data.size > 0) {
                            recordedChunks.push(ev.data);
                        }
                    };
                    mediaRecorder.start(250);
                } catch (recErr) {
                    console.warn('MediaRecorder start failed:', recErr);
                }
            }

            let isStopHandled = false;
            function handleRecordingStop() {
                if (isStopHandled) return;
                isStopHandled = true;

                if (recordTimerInterval) {
                    clearInterval(recordTimerInterval);
                    recordTimerInterval = null;
                }

                stopAudioContext();
                stopStreamTracks();
                $('#btn-ai-voice-stop').addClass('d-none');
                $('#btn-ai-record-audio').prop('disabled', false).removeClass('active');

                // Prefer pure 16kHz PCM WAV encoding from captured audio chunks
                if (audioPcmChunks && audioPcmChunks.length > 0) {
                    try {
                        const wavBlob = encodePcm16Wav(audioPcmChunks, sampleRate);
                        if (wavBlob && wavBlob.size > 44) {
                            recordedBlob = wavBlob;
                            recordedMime = 'audio/wav';
                        }
                    } catch (wavErr) {
                        console.warn('PCM WAV encoding failed, falling back to MediaRecorder blob:', wavErr);
                    }
                }

                // Fall back to MediaRecorder chunk blob if WAV was not produced
                if (!recordedBlob && recordedChunks.length > 0) {
                    recordedBlob = new Blob(recordedChunks, { type: recordedMime });
                }

                if (recordedBlob && recordedBlob.size > 44) {
                    recordedUrl = URL.createObjectURL(recordedBlob);
                    $('#ai-voice-replay').attr('src', recordedUrl).removeClass('d-none');
                    $('#btn-ai-voice-upload').removeClass('d-none');
                    showRecorderStatus('Recording stopped. Listen back or click "Attach Voice Note" to stage with your instruction.', false);
                } else {
                    showRecorderStatus('No audio captured. Please try again.', true);
                }
            }

            if (mediaRecorder) {
                mediaRecorder.onstop = handleRecordingStop;
            }

            $('#btn-ai-record-audio').prop('disabled', true).addClass('active');
            $('#btn-ai-voice-stop').removeClass('d-none');
            showRecorderStatus('Recording active. Audio stays on your device until you attach and send it.', false);

            const maxSec = Number(cfg.maxAudioSeconds || 120);
            recordSeconds = 0;
            $('#ai-voice-timer').text('00:00');
            recordTimerInterval = setInterval(function () {
                recordSeconds++;
                $('#ai-voice-timer').text(formatTimer(recordSeconds));
                if (recordSeconds >= maxSec) {
                    if (mediaRecorder && mediaRecorder.state === 'recording') {
                        mediaRecorder.stop();
                    }
                    handleRecordingStop();
                }
            }, 1000);
        } catch (_err) {
            resetRecorderState(false);
            showRecorderStatus('Microphone permission was denied or no microphone was found. You can attach an audio file or type your message.', true);
        }
    });

    $('#btn-ai-voice-stop').on('click', function () {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        } else if (isPcmRecording) {
            stopAudioContext();
            resetRecorderState(false);
        }
    });

    $('#btn-ai-voice-discard').on('click', function () {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            recordedChunks = [];
            mediaRecorder.stop();
        }
        audioPcmChunks = [];
        stopAudioContext();
        resetRecorderState(true);
    });

    // Stage the recorded voice note with user instruction
    $('#btn-ai-voice-upload').on('click', function () {
        if (!recordedBlob) return;
        const blob = recordedBlob;
        const ext = recordedMime.includes('wav') ? 'wav' : (recordedMime.includes('ogg') ? 'ogg' : 'webm');
        const name = 'voice_note_' + Date.now() + '.' + ext;
        const previewUrl = recordedUrl;
        recordedUrl = null; // Transferred to stagedAttachment
        resetRecorderState(true);
        clearStagedAttachment();
        stagedAttachment = {
            file: blob,
            fileName: name,
            fileSize: blob.size,
            mimeType: recordedMime,
            isAudio: true,
            previewUrl: previewUrl,
        };
        renderAttachedChips();
        $('#chat-input').focus();
    });

    window.zoloAiMedia = {
        getAttachedMediaIds: function () {
            return Array.from(attachedMedia.keys());
        },
        clearAttachedMedia: function () {
            attachedMedia.clear();
            renderAttachedChips();
        },
        hasStagedAttachment: function () {
            return stagedAttachment !== null;
        },
        hasStagedVoiceAttachment: function () {
            return stagedAttachment !== null && stagedAttachment.isAudio === true;
        },
        clearStagedAttachment: clearStagedAttachment,
        stageFile: stageFile,
        submitStagedAttachment: submitStagedAttachment,
        renderExtractionCard: renderExtractionCard,
        renderUnifiedWorkflowCard: renderUnifiedWorkflowCard,
    };
})(jQuery);
