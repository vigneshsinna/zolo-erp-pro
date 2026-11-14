/**
 * zoloERP Phase 2.3 — Multimodal AI Input, Voice Recorder & Extraction Review Card
 * Strictly uses session-authenticated private endpoints and never stores media in localStorage.
 * Supports staged attachments (separate attachment from extraction) and displays recorded perception model.
 */
(function ($) {
    'use strict';

    const cfg = window.zoloAiMultimodalConfig || {};
    if (!cfg.enabled) {
        return;
    }

    let mediaRecorder = null;
    let mediaStream = null;
    let recordedChunks = [];
    let recordedBlob = null;
    let recordedMime = 'audio/webm';
    let recordedUrl = null;
    let recordTimerInterval = null;
    let recordSeconds = 0;

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

    function mediaUrl(suffix) {
        const ws = getWs();
        const base = (ws && ws.aiBaseUrl) ? ws.aiBaseUrl : (cfg.mediaBaseUrl || '/ai');
        return base.replace(/\/$/, '') + '/media' + (suffix || '');
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
        stopStreamTracks();
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
            .append('<div class="chat-bubble chat-assistant shadow-sm small"><span class="spinner-border spinner-border-sm mr-2" role="status"></span>Validating, sanitizing, and extracting fields with ' + escapeText(perceptionName) + '...</div>');
        $('.ai-welcome-box').remove();
        $('#chat-messages').append(loadingCard);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        const fd = new FormData();
        fd.append('file', targetStaged.file, targetStaged.fileName);
        fd.append('extract_now', '1');
        if (companionText) {
            fd.append('companion_text', companionText);
        }
        const effectiveThreadId = threadId || (ws && ws.getCurrentThreadId && ws.getCurrentThreadId());
        if (effectiveThreadId) {
            fd.append('thread_id', effectiveThreadId);
        }

        $.ajax({
            url: mediaUrl('/'),
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
                if (res && res.media && res.extraction) {
                    renderExtractionCard(res.media, res.extraction, companionText);

                    // Contextual assistant message summarizing the perception extraction
                    const ext = res.extraction.extracted || {};
                    const partyName = ext.supplier_name || ext.buyer_name || 'party';
                    const totalAmt = ext.total_amount ? ('₹' + ext.total_amount) : '';
                    const docLabel = (ext.document_type || 'document').replace('_', ' ');
                    const modelBadge = formatModelDisplayName(res.extraction.model_key, res.extraction.model_provider);

                    let summaryMsg = 'I have extracted the ' + docLabel + ' from **' + escapeText(partyName) + '**';
                    if (totalAmt) {
                        summaryMsg += ' totaling **' + escapeText(totalAmt) + '**';
                    }
                    summaryMsg += ' using **' + escapeText(modelBadge) + '**.\n\n';
                    if (ext.document_type === 'purchase_bill') {
                        summaryMsg += '> **Notice:** Direct Purchase Bill posting from AI is restricted in zoloERP to safeguard accounting integrity. Please review the extracted bill above, then click **Prepare Purchase Order** to stage a draft Purchase Order (`purchase_order.create.v1`) for this vendor.';
                    } else {
                        summaryMsg += 'Please review the extracted fields and line items above. Click **Confirm Extraction** to verify the data, then proceed to **Stage ERP Proposal**.';
                    }

                    if (ws && ws.appendMessage) {
                        ws.appendMessage('assistant', summaryMsg);
                    }
                }
                if (ws && ws.loadTelemetry) {
                    ws.loadTelemetry();
                }
                if (callback) callback(null, res);
            },
            error: function (xhr) {
                $('#' + placeholderId).remove();
                if (targetStaged.previewUrl) {
                    try { URL.revokeObjectURL(targetStaged.previewUrl); } catch (_) {}
                }
                const err = xhr.responseJSON || {};
                const code = err.error_code || ('HTTP_' + xhr.status);
                const msg = err.error_message || err.message || 'Media upload or extraction failed.';
                if (ws && ws.appendMessage) {
                    ws.appendMessage('assistant', '[' + code + '] ' + msg);
                }
                if (callback) callback(err);
            }
        });
    }

    function formatCurrencyVal(val) {
        if (val == null || val === '') return '—';
        const num = Number(val);
        if (isNaN(num)) return String(val);
        return '₹' + num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
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

        // Direct Purchase Bill Posting Restriction Notice
        const restrictionAlert = $('<div class="alert alert-warning py-2 px-3 small border-warning mt-2 mb-2" style="background-color: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 6px;"></div>')
            .html('Direct Purchase Bill posting from AI is not enabled yet. Review the extracted bill above, or explicitly ask to draft a Purchase Order (<code>purchase_order.create.v1</code>) instead.');
        if (data.document_type === 'purchase_bill') {
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

        const isPurchaseBill = (data.document_type === 'purchase_bill');
        const btnPreparePo = $('<button type="button" class="btn btn-sm btn-primary mr-2 mb-1 ai-btn-media-prepare-po">Prepare Purchase Order</button>')
            .prop('disabled', confirmationStatus !== 'confirmed')
            .toggleClass('d-none', !isPurchaseBill);

        const btnHandoff = $('<button type="button" class="btn btn-sm btn-success mr-2 mb-1 ai-btn-media-handoff">Stage ERP Proposal</button>')
            .prop('disabled', confirmationStatus !== 'confirmed')
            .toggleClass('d-none', isPurchaseBill);

        const btnToggleEdit = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-1 ai-btn-media-toggle-edit">Edit Details</button>');
        const btnSave = $('<button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-1 ai-btn-media-save d-none">Save Edits</button>');
        const btnAttach = $('<button type="button" class="btn btn-sm btn-outline-info mr-2 mb-1 ai-btn-media-attach">Attach to Chat</button>');
        const btnDiscard = $('<button type="button" class="btn btn-sm btn-outline-danger mb-1 ai-btn-media-discard">Discard Media</button>');

        leftBtns.append(btnConfirm);
        if (isPurchaseBill) {
            leftBtns.append(btnPreparePo);
        } else {
            leftBtns.append(btnHandoff);
        }
        leftBtns.append(btnToggleEdit).append(btnSave);
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
            btnPreparePo.prop('disabled', confirmationStatus !== 'confirmed').toggleClass('d-none', !isPb);
            btnHandoff.prop('disabled', confirmationStatus !== 'confirmed').toggleClass('d-none', isPb);

            if (isPb) {
                restrictionAlert.removeClass('d-none');
            } else {
                restrictionAlert.addClass('d-none');
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
                    const nextAction = (data.document_type === 'purchase_bill') ? 'Prepare Purchase Order' : 'Stage ERP Proposal';
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
                    activeBtn.prop('disabled', confirmationStatus !== 'confirmed').text(isPO ? 'Prepare Purchase Order' : 'Stage ERP Proposal');
                }
            });
        }

        btnPreparePo.on('click', function () {
            executeHandoff('purchase_order.create.v1');
        });

        btnHandoff.on('click', function () {
            executeHandoff(null);
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

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
            showRecorderStatus('Browser voice recording is unavailable here. Use the paperclip button to upload a WAV/WebM audio file or type your prompt below.', true);
            return;
        }

        try {
            resetRecorderState(false);
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const preferredMime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus'
                : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');

            mediaRecorder = preferredMime ? new MediaRecorder(mediaStream, { mimeType: preferredMime }) : new MediaRecorder(mediaStream);
            recordedMime = preferredMime ? preferredMime.split(';')[0] : 'audio/webm';
            recordedChunks = [];

            mediaRecorder.ondataavailable = function (ev) {
                if (ev.data && ev.data.size > 0) {
                    recordedChunks.push(ev.data);
                }
            };

            mediaRecorder.onstop = function () {
                if (recordTimerInterval) {
                    clearInterval(recordTimerInterval);
                    recordTimerInterval = null;
                }
                stopStreamTracks();
                $('#btn-ai-voice-stop').addClass('d-none');
                $('#btn-ai-record-audio').prop('disabled', false).removeClass('active');

                if (recordedChunks.length > 0) {
                    recordedBlob = new Blob(recordedChunks, { type: recordedMime });
                    recordedUrl = URL.createObjectURL(recordedBlob);
                    $('#ai-voice-replay').attr('src', recordedUrl).removeClass('d-none');
                    $('#btn-ai-voice-upload').removeClass('d-none');
                    showRecorderStatus('Recording stopped. Listen back or click "Attach Voice Note" to stage with your instruction.', false);
                } else {
                    showRecorderStatus('No audio captured. Please try again.', true);
                }
            };

            mediaRecorder.start(250);
            $('#btn-ai-record-audio').prop('disabled', true).addClass('active');
            $('#btn-ai-voice-stop').removeClass('d-none');
            showRecorderStatus('Recording active. Audio stays on your device until you attach and send it.', false);

            const maxSec = Number(cfg.maxAudioSeconds || 120);
            recordSeconds = 0;
            $('#ai-voice-timer').text('00:00');
            recordTimerInterval = setInterval(function () {
                recordSeconds++;
                $('#ai-voice-timer').text(formatTimer(recordSeconds));
                if (recordSeconds >= maxSec && mediaRecorder && mediaRecorder.state === 'recording') {
                    mediaRecorder.stop();
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
        }
    });

    $('#btn-ai-voice-discard').on('click', function () {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            recordedChunks = [];
            mediaRecorder.stop();
        }
        resetRecorderState(true);
    });

    // Stage the recorded voice note with user instruction
    $('#btn-ai-voice-upload').on('click', function () {
        if (!recordedBlob) return;
        const blob = recordedBlob;
        const ext = recordedMime.includes('ogg') ? 'ogg' : 'webm';
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
        clearStagedAttachment: clearStagedAttachment,
        stageFile: stageFile,
        submitStagedAttachment: submitStagedAttachment,
        renderExtractionCard: renderExtractionCard,
    };
})(jQuery);
