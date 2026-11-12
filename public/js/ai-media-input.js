/**
 * zoloERP Phase 2.3 — Multimodal AI Input, Voice Recorder & Extraction Review Card
 * Strictly uses session-authenticated private endpoints and never stores media in localStorage.
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

    // Media IDs attached to the next chat turn once confirmed
    const attachedMedia = new Map();

    function getWs() {
        return window.zoloAiWorkspace || null;
    }

    function escapeText(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
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
        if (attachedMedia.size === 0) {
            bar.addClass('d-none');
            return;
        }
        bar.removeClass('d-none');

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

    function uploadMediaFile(fileOrBlob, fileName, idempotencyKey) {
        const fd = new FormData();
        fd.append('file', fileOrBlob, fileName || fileOrBlob.name || 'upload.bin');
        fd.append('extract_now', '1');
        if (idempotencyKey) {
            fd.append('idempotency_key', idempotencyKey);
        }
        const ws = getWs();
        if (ws && ws.getCurrentThreadId && ws.getCurrentThreadId()) {
            fd.append('thread_id', ws.getCurrentThreadId());
        }

        const placeholderId = 'ai-media-loading-' + Date.now();
        const loadingCard = $('<div class="d-flex mb-3 justify-content-start" id="' + placeholderId + '"></div>')
            .append('<div class="chat-bubble chat-assistant shadow-sm small"><span class="spinner-border spinner-border-sm mr-2" role="status"></span>Validating, sanitizing, and extracting fields with NVIDIA Omni...</div>');
        $('.ai-welcome-box').remove();
        $('#chat-messages').append(loadingCard);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        $.ajax({
            url: mediaUrl('/'),
            method: 'POST',
            headers: baseHeaders(false),
            data: fd,
            processData: false,
            contentType: false,
            success: function (res) {
                $('#' + placeholderId).remove();
                if (res && res.media && res.extraction) {
                    renderExtractionCard(res.media, res.extraction);
                }
                if (ws && ws.loadTelemetry) {
                    ws.loadTelemetry();
                }
            },
            error: function (xhr) {
                $('#' + placeholderId).remove();
                const err = xhr.responseJSON || {};
                const code = err.error_code || ('HTTP_' + xhr.status);
                const msg = err.error_message || err.message || 'Media upload or extraction failed.';
                if (ws && ws.appendMessage) {
                    ws.appendMessage('assistant', '[' + code + '] ' + msg);
                }
            }
        });
    }

    function renderExtractionCard(media, extraction) {
        const ws = getWs();
        const container = $('<div class="d-flex mb-3 justify-content-start"></div>');
        const bubble = $('<div class="chat-bubble chat-assistant shadow-sm w-100 ai-media-review-bubble"></div>');
        const card = $('<div class="card border shadow-none ai-media-review-card" data-media-id="' + escapeText(media.id) + '"></div>');

        const isAudio = media.source_kind === 'audio';
        const data = extraction.extracted || {};
        let currentRevision = Number(extraction.revision || 1);
        let confirmationStatus = extraction.confirmation_status || 'needs_review';

        const header = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center flex-wrap"></div>');
        const titleSpan = $('<span class="font-weight-bold text-dark small"></span>')
            .append($('<i></i>').addClass(isAudio ? 'dripicons-microphone text-primary mr-1' : 'dripicons-document text-primary mr-1'))
            .append(document.createTextNode((isAudio ? 'Voice Transcript Review · ' : 'Document Extraction Review · ') + (media.file_name || 'Media')));
        header.append(titleSpan);

        const badgeWrap = $('<div class="d-flex align-items-center"></div>');
        const revBadge = $('<span class="badge badge-secondary mr-2 ai-rev-badge"></span>').text('Rev ' + currentRevision);
        const statusBadge = $('<span class="badge ai-confirm-badge"></span>')
            .addClass(confirmationStatus === 'confirmed' ? 'badge-success' : 'badge-warning')
            .text(confirmationStatus === 'confirmed' ? 'User Confirmed (Draft Data)' : 'Needs Review · Unverified AI Claim');
        badgeWrap.append(revBadge).append(statusBadge);
        header.append(badgeWrap);
        card.append(header);

        const body = $('<div class="card-body p-3 bg-white"></div>');
        body.append('<div class="alert alert-info py-1 px-2 mb-3 small"><strong>Trust Notice:</strong> Extracted fields come from perception OCR/STT (<code>is_verified_by_erp = false</code>). Verify against the preview before confirming or staging an ERP order.</div>');

        // Warnings section
        const warnContainer = $('<div class="ai-media-warnings mb-2"></div>');
        renderWarnings(warnContainer, data.warnings || []);
        body.append(warnContainer);

        const row = $('<div class="row"></div>');
        // Left: private authenticated preview (never public disk or localStorage)
        const previewCol = $('<div class="col-md-4 mb-3 mb-md-0"></div>');
        const previewBox = $('<div class="border rounded p-2 bg-light text-center ai-media-preview-pane"></div>');
        if (isAudio) {
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
        previewCol.append(previewBox);
        row.append(previewCol);

        // Right: editable extraction fields
        const formCol = $('<div class="col-md-8"></div>');
        if (isAudio) {
            renderAudioEditor(formCol, data);
        } else {
            renderInvoiceEditor(formCol, data);
        }
        row.append(formCol);
        body.append(row);

        // Companion instruction input (e.g., "create purchase order for Main Warehouse")
        const companionGroup = $('<div class="form-group mt-3 mb-2"></div>');
        companionGroup.append('<label class="small font-weight-bold text-muted mb-1">Optional Instruction / Warehouse Note (optional)</label>');
        const companionInput = $('<input type="text" class="form-control form-control-sm ai-media-companion-input" placeholder="e.g., Create purchase order for Main Warehouse">');
        companionGroup.append(companionInput);
        body.append(companionGroup);

        // Feedback area & action buttons
        const actionFeedback = $('<div class="ai-media-action-feedback small mb-2"></div>');
        body.append(actionFeedback);

        const footer = $('<div class="d-flex flex-wrap justify-content-between align-items-center pt-2 border-top"></div>');
        const leftBtns = $('<div class="d-flex flex-wrap"></div>');
        const rightBtns = $('<div class="d-flex flex-wrap"></div>');

        const btnSave = $('<button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-1 ai-btn-media-save">Save Edits</button>');
        const btnConfirm = $('<button type="button" class="btn btn-sm btn-primary mr-2 mb-1 ai-btn-media-confirm">Confirm Extraction</button>');
        const btnHandoff = $('<button type="button" class="btn btn-sm btn-success mr-2 mb-1 ai-btn-media-handoff">Stage ERP Proposal</button>')
            .prop('disabled', confirmationStatus !== 'confirmed');
        const btnAttach = $('<button type="button" class="btn btn-sm btn-outline-info mr-2 mb-1 ai-btn-media-attach">Attach to Chat</button>');
        const btnDiscard = $('<button type="button" class="btn btn-sm btn-outline-danger mb-1 ai-btn-media-discard">Discard Media</button>');

        leftBtns.append(btnSave).append(btnConfirm).append(btnHandoff);
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
            revBadge.text('Rev ' + currentRevision);
            statusBadge
                .removeClass('badge-warning badge-success')
                .addClass(confirmationStatus === 'confirmed' ? 'badge-success' : 'badge-warning')
                .text(confirmationStatus === 'confirmed' ? 'User Confirmed (Draft Data)' : 'Needs Review · Unverified AI Claim');
            btnHandoff.prop('disabled', confirmationStatus !== 'confirmed');
            renderWarnings(warnContainer, (updatedExtraction.extracted || {}).warnings || []);
        }

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
                    actionFeedback.html('<span class="text-success">Saved Revision ' + currentRevision + '.</span>');
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
                    actionFeedback.html('<span class="text-success font-weight-bold">Confirmed at Revision ' + currentRevision + '. You can now Stage ERP Proposal or ask Copilot below.</span>');
                },
                error: function (xhr) {
                    handleMediaActionError(xhr, actionFeedback, media.id, refreshCardState, formCol, isAudio);
                },
                complete: function () {
                    btnConfirm.prop('disabled', false).text('Confirm Extraction');
                }
            });
        });

        btnHandoff.on('click', function () {
            btnHandoff.prop('disabled', true).text('Staging...');
            actionFeedback.empty();

            const bodyPayload = {
                companion_text: companionInput.val().trim() || null,
                thread_id: (ws && ws.getCurrentThreadId && ws.getCurrentThreadId()) || null,
            };

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
                    btnHandoff.prop('disabled', confirmationStatus !== 'confirmed').text('Stage ERP Proposal');
                }
            });
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
            container.append($('<div class="alert alert-warning py-1 px-2 mb-1 small"></div>').text(w));
        });
    }

    function renderInvoiceEditor(formCol, data) {
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
            grp.append($('<label class="small text-muted mb-0"></label>').text(f.label));
            const input = $('<input type="text" class="form-control form-control-sm ai-ext-scalar">')
                .attr('data-field', f.key)
                .val(data[f.key] == null ? '' : String(data[f.key]));
            grp.append(input);
            grid.append(grp);
        });
        formCol.append(grid);

        const tableWrap = $('<div class="table-responsive mt-2"></div>');
        const table = $('<table class="table table-sm table-bordered mb-0 ai-fact-table"></table>');
        table.append('<thead class="thead-light"><tr><th>Description</th><th style="width:75px;">Qty</th><th style="width:70px;">UOM</th><th style="width:90px;">Unit Price</th><th style="width:95px;">Line Total</th></tr></thead>');
        const tbody = $('<tbody></tbody>');

        (data.lines || []).forEach(function (line, idx) {
            const lineId = line.client_line_id || ('line_' + (idx + 1));
            const tr = $('<tr class="ai-ext-line-row"></tr>').attr('data-line-id', lineId);
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-line-desc">').val(line.description || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-line-qty">').val(line.quantity == null ? '' : String(line.quantity))));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-line-uom">').val(line.uom || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-line-price">').val(line.unit_price == null ? '' : String(line.unit_price))));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-line-total">').val(line.line_total == null ? '' : String(line.line_total))));
            tbody.append(tr);
        });

        if ((data.lines || []).length === 0) {
            tbody.append('<tr><td colspan="5" class="text-center text-muted small">No line items detected.</td></tr>');
        }

        table.append(tbody);
        tableWrap.append(table);
        formCol.append(tableWrap);
    }

    function renderAudioEditor(formCol, data) {
        const txtGrp = $('<div class="form-group mb-2"></div>');
        txtGrp.append('<label class="small text-muted mb-0">Transcript Text (edit if any word or number is misheard)</label>');
        txtGrp.append($('<textarea rows="3" class="form-control form-control-sm ai-ext-scalar" data-field="transcript_text"></textarea>').val(data.transcript_text || ''));
        formCol.append(txtGrp);

        const grid = $('<div class="form-row"></div>');
        [
            { key: 'intent_guess', label: 'Workflow Intent', col: 'col-sm-4' },
            { key: 'party_name', label: 'Supplier / Customer', col: 'col-sm-4' },
            { key: 'warehouse_name', label: 'Warehouse', col: 'col-sm-4' },
        ].forEach(function (f) {
            const grp = $('<div class="form-group mb-2"></div>').addClass(f.col);
            grp.append($('<label class="small text-muted mb-0"></label>').text(f.label));
            grp.append($('<input type="text" class="form-control form-control-sm ai-ext-scalar">').attr('data-field', f.key).val(data[f.key] == null ? '' : String(data[f.key])));
            grid.append(grp);
        });
        formCol.append(grid);

        const tableWrap = $('<div class="table-responsive mt-2"></div>');
        const table = $('<table class="table table-sm table-bordered mb-0 ai-fact-table"></table>');
        table.append('<thead class="thead-light"><tr><th>Product</th><th style="width:85px;">Qty</th><th style="width:75px;">UOM</th><th style="width:95px;">Rate</th></tr></thead>');
        const tbody = $('<tbody></tbody>');
        (data.items || []).forEach(function (item, idx) {
            const lineId = item.client_line_id || ('line_' + (idx + 1));
            const tr = $('<tr class="ai-ext-item-row"></tr>').attr('data-line-id', lineId);
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-item-name">').val(item.product_name || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-item-qty">').val(item.quantity == null ? '' : String(item.quantity))));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm ai-item-uom">').val(item.uom || '')));
            tr.append($('<td></td>').append($('<input type="text" class="form-control form-control-sm text-right ai-item-price">').val(item.unit_price == null ? '' : String(item.unit_price))));
            tbody.append(tr);
        });
        table.append(tbody);
        tableWrap.append(table);
        formCol.append(tableWrap);
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

    // Bind File Picker
    $('#btn-ai-attach-media').on('click', function () {
        $('#ai-media-file-input').trigger('click');
    });

    $('#ai-media-file-input').on('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        this.value = '';
        uploadMediaFile(file, file.name);
    });

    // Drag & drop support onto composer footer
    $('#chat-form').on('dragover', function (e) {
        e.preventDefault();
        $(this).addClass('ai-drop-active');
    }).on('dragleave drop', function (e) {
        e.preventDefault();
        $(this).removeClass('ai-drop-active');
        if (e.type === 'drop' && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files.length > 0) {
            const f = e.originalEvent.dataTransfer.files[0];
            uploadMediaFile(f, f.name);
        }
    });

    // Voice Recording Lifecycle (explicit consent, no background/auto-upload)
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
                    showRecorderStatus('Recording stopped. Listen back or click "Transcribe & Review" when ready.', false);
                } else {
                    showRecorderStatus('No audio captured. Please try again.', true);
                }
            };

            mediaRecorder.start(250);
            $('#btn-ai-record-audio').prop('disabled', true).addClass('active');
            $('#btn-ai-voice-stop').removeClass('d-none');
            showRecorderStatus('Recording active. Audio stays on your device until you click "Transcribe & Review".', false);

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

    $('#btn-ai-voice-upload').on('click', function () {
        if (!recordedBlob) return;
        const blob = recordedBlob;
        const ext = recordedMime.includes('ogg') ? 'ogg' : 'webm';
        resetRecorderState(true);
        uploadMediaFile(blob, 'voice_note_' + Date.now() + '.' + ext);
    });

    window.zoloAiMedia = {
        getAttachedMediaIds: function () {
            return Array.from(attachedMedia.keys());
        },
        clearAttachedMedia: function () {
            attachedMedia.clear();
            renderAttachedChips();
        },
        renderExtractionCard: renderExtractionCard,
    };
})(jQuery);

