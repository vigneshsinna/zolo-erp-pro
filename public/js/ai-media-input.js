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
            .append('<div class="chat-bubble chat-assistant shadow-sm small"><span class="spinner-border spinner-border-sm mr-2" role="status"></span>Validating document, resolving records, and automatically preparing ERP proposal with ' + escapeText(perceptionName) + '...</div>');
        $('.ai-welcome-box').remove();
        $('#chat-messages').append(loadingCard);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);

        const fd = new FormData();
        fd.append('file', targetStaged.file, targetStaged.fileName);
        fd.append('prepare_now', '1');
        if (companionText) {
            fd.append('companion_text', companionText);
        }
        const effectiveThreadId = threadId || (ws && ws.getCurrentThreadId && ws.getCurrentThreadId());
        if (effectiveThreadId) {
            fd.append('thread_id', effectiveThreadId);
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
                    renderUnifiedWorkflowCard(res, companionText, targetStaged);
                    if (ws && ws.loadTelemetry) {
                        ws.loadTelemetry();
                    }
                } catch (cardErr) {
                    console.error('Error rendering unified workflow card:', cardErr);
                    if (ws && ws.appendMessage) {
                        ws.appendMessage('assistant', 'An unexpected error occurred while displaying the bill review: ' + (cardErr.message || 'Unknown error'));
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

    function renderUnifiedWorkflowCard(res, companionText, stagedRef) {
        const ws = getWs();
        const aiBaseUrl = resolveAiBaseUrl();
        const media = res.media || {};
        const extraction = res.extraction || {};
        const taskId = res.task_id || (res.task && res.task.id) || media.workflow_task_id;

        const container = $('<div class="d-flex mb-3 justify-content-start ai-unified-workflow-wrap"></div>');
        const bubble = $('<div class="chat-bubble chat-assistant shadow-sm w-100 ai-media-review-bubble"></div>');
        const card = $('<div class="card border shadow-none ai-unified-card" data-media-id="' + escapeText(media.id || '') + '" data-task-id="' + escapeText(taskId || '') + '"></div>');

        bubble.append(card);
        container.append(bubble);
        $('.ai-welcome-box').remove();
        $('#chat-messages').append(container);
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
            $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
        }

        function renderProposalView(dataPayload) {
            currentRes = dataPayload;
            const proposal = currentRes.proposal || {};
            const preview = proposal.preview || {};
            const ext = (currentRes.extraction && currentRes.extraction.extracted) || {};
            const actionKey = currentRes.action_key || proposal.action_key || 'purchase_bill.create.v1';
            const isPb = (actionKey === 'purchase_bill.create.v1');
            const docTitle = isPb ? 'Purchase Bill Review' : ((preview.document_type || 'Commercial Action') + ' Review');
            const proposalId = proposal.proposal_id || proposal.id;
            const version = proposal.version || 1;

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            const titleWrap = $('<div></div>');
            titleWrap.append($('<h5 class="font-weight-bold mb-1 text-dark"></h5>').html('<i class="dripicons-document mr-2 text-primary"></i>' + escapeText(docTitle)));
            const perceptionName = formatModelDisplayName((currentRes.extraction && currentRes.extraction.model_key) || media.model_key || 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', 'nvidia');
            titleWrap.append($('<div class="small text-muted"></div>').text('Extracted by ' + perceptionName + ' · Verified by ERP Validation'));
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
            const supplierName = (preview.supplier && preview.supplier.name) || ext.supplier_name || '—';
            const invoiceNo = preview.supplier_invoice_no || ext.invoice_number || '—';
            const invoiceDate = preview.date || ext.invoice_date || '—';
            const warehouseName = (preview.warehouse && preview.warehouse.name) || 'Main Warehouse';

            metaBox.append('<div class="col-6 mb-2"><small class="text-muted d-block">' + (isPb ? 'Supplier' : 'Customer') + '</small><strong class="text-dark">' + escapeText(supplierName) + '</strong></div>');
            metaBox.append('<div class="col-6 mb-2 text-right"><small class="text-muted d-block">Invoice number</small><strong class="text-dark">' + escapeText(invoiceNo) + '</strong></div>');
            metaBox.append('<div class="col-6"><small class="text-muted d-block">Invoice date</small><strong class="text-dark">' + escapeText(invoiceDate) + '</strong></div>');
            metaBox.append('<div class="col-6 text-right"><small class="text-muted d-block">Warehouse</small><strong class="text-dark">' + escapeText(warehouseName) + '</strong></div>');
            body.append(metaBox);

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
            body.append('<div class="small text-muted border-top pt-2 mt-2 font-italic"><i class="dripicons-shield mr-1 text-success"></i>Original invoice number and document type verified by ERP validation. Real financial writes require human authorization.</div>');

            // Action Feedback
            const feedbackBox = $('<div class="small mt-2 mb-1 ai-action-feedback"></div>');
            body.append(feedbackBox);

            // Action Buttons (Image 3)
            const footer = $('<div class="d-flex justify-content-between align-items-center pt-3 border-top mt-2 flex-wrap"></div>');
            const btnEdit = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 py-1 px-3 ai-btn-edit-bill"><i class="dripicons-pencil mr-1"></i>Edit Bill</button>');
            const rightWrap = $('<div class="d-flex align-items-center flex-wrap"></div>');
            const pdfUrl = aiBaseUrl + '/proposals/' + proposalId + '/pdf';
            const btnDraft = $('<button type="button" class="btn btn-sm btn-outline-primary mr-2 py-1 px-2 btn-preview-pdf" data-pdf-url="' + pdfUrl + '"><i class="dripicons-document mr-1"></i>Draft PDF</button>');
            const btnApprove = $('<button type="button" class="btn btn-sm btn-success px-3 py-1 font-weight-bold ai-btn-approve-bill"><i class="dripicons-checkmark mr-1"></i>Approve & Create</button>');

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
                        btnApprove.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Approve & Create');
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
            const resId = (receipt && receipt.resource_id) || (receipt && receipt.details && receipt.details.purchase_id);
            const pdfUrl = resId ? (aiBaseUrl + '/documents/purchase/' + resId + '/pdf') : null;

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            topRow.append($('<h5 class="font-weight-bold mb-0 text-success"></h5>').html('<i class="dripicons-checkmark mr-2"></i>ERP Purchase Bill Created'));
            topRow.append($('<span class="badge badge-success px-2 py-1 font-weight-bold"><i class="dripicons-checkmark mr-1"></i>Booked</span>'));
            header.append(topRow);
            card.append(header);

            const body = $('<div class="card-body p-3 bg-white"></div>');
            const successBox = $('<div class="alert alert-success p-3 border-success mb-2"></div>');
            successBox.append('<h6 class="font-weight-bold text-success mb-1">Voucher Booked: ' + escapeText(refNo) + '</h6>');
            successBox.append('<div class="small text-muted mb-3">Status: Successfully posted to Accounts Payable, inventory updated in Main Warehouse.</div>');

            const btnRow = $('<div class="d-flex align-items-center flex-wrap"></div>');
            if (pdfUrl) {
                btnRow.append($('<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 mr-2 mb-1 btn-preview-pdf"><i class="dripicons-preview mr-1"></i>View PDF in Chat</button>').attr('data-pdf-url', pdfUrl).attr('data-title', refNo));
                btnRow.append($('<a class="btn btn-sm btn-primary py-1 px-2 mb-1" target="_blank" download><i class="dripicons-download mr-1"></i>Download PDF Receipt</a>').attr('href', pdfUrl));
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

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            topRow.append($('<h5 class="font-weight-bold mb-0 text-dark"></h5>').html('<i class="dripicons-warning mr-2 text-warning"></i>Purchase Bill Review'));
            topRow.append($('<span class="badge badge-warning px-2 py-1 font-weight-bold"><i class="dripicons-hourglass mr-1"></i>Details Required</span>'));
            header.append(topRow);
            card.append(header);

            const body = $('<div class="card-body p-3 bg-white"></div>');
            body.append('<div class="alert alert-warning py-2 px-3 small border-warning mb-3">Please resolve the missing details below to generate the purchase bill proposal.</div>');

            const inputsForm = $('<div class="ai-clarification-form mb-3"></div>');
            const reqInputs = clarifyObj.required_inputs || [];
            if (reqInputs.length > 0) {
                reqInputs.forEach(function (inp) {
                    const grp = $('<div class="form-group mb-3 border p-2 rounded bg-light"></div>');
                    grp.append($('<label class="font-weight-bold small mb-1 text-dark"></label>').text(inp.label || inp.field));
                    if (inp.help_text) {
                        grp.append($('<small class="text-muted d-block mb-2"></small>').text(inp.help_text));
                    }
                    if (Array.isArray(inp.candidates) && inp.candidates.length > 0) {
                        const sel = $('<select class="form-control form-control-sm ai-clarify-select"></select>').attr('data-field', inp.field);
                        sel.append('<option value="">-- Choose ' + escapeText(inp.label || 'Candidate') + ' --</option>');
                        inp.candidates.forEach(function (c) {
                            sel.append($('<option></option>').attr('value', c.id).text(c.display || c.name || c.code));
                        });
                        grp.append(sel);
                    } else {
                        const txt = $('<input type="text" class="form-control form-control-sm ai-clarify-input">')
                            .attr('data-field', inp.field)
                            .val(inp.current_value || '');
                        grp.append(txt);
                    }
                    inputsForm.append(grp);
                });
            } else {
                inputsForm.append('<div class="small text-muted mb-2">' + escapeText(clarifyObj.message || 'Please check and complete the missing records.') + '</div>');
            }
            body.append(inputsForm);

            const feedback = $('<div class="small mb-2 ai-clarify-feedback"></div>');
            body.append(feedback);

            const footer = $('<div class="pt-2 border-top d-flex justify-content-end"></div>');
            const btnSubmit = $('<button type="button" class="btn btn-sm btn-primary px-3 ai-btn-submit-clarify"><i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview</button>');

            btnSubmit.on('click', function () {
                btnSubmit.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Submitting...');
                feedback.empty();

                const slotPatch = {};
                inputsForm.find('.ai-clarify-select, .ai-clarify-input').each(function () {
                    const f = $(this).attr('data-field');
                    const v = $(this).val();
                    if (f && v !== '') {
                        slotPatch[f] = v;
                    }
                });

                $.ajax({
                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/inputs',
                    method: 'PATCH',
                    headers: baseHeaders(true),
                    data: JSON.stringify({
                        expected_revision: (taskObj && taskObj.revision) || 1,
                        slots_patch: slotPatch
                    }),
                    success: function (resPatch) {
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
                                        media: media,
                                        extraction: extraction
                                    });
                                } else if (resPrep && resPrep.status === 'needs_input') {
                                    refreshView('clarification', resPrep);
                                }
                            },
                            error: function (xhrPrep) {
                                btnSubmit.prop('disabled', false).html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                                const err = xhrPrep.responseJSON || {};
                                feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Failed to prepare proposal.') + '</div>');
                            }
                        });
                    },
                    error: function (xhrPatch) {
                        btnSubmit.prop('disabled', false).html('<i class="dripicons-direction mr-1"></i>Submit Details & Generate Preview');
                        const err = xhrPatch.responseJSON || {};
                        feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Failed to update inputs.') + '</div>');
                    }
                });
            });

            footer.append(btnSubmit);
            body.append(footer);
            card.append(body);
        }

        function renderEditView() {
            const proposal = currentRes.proposal || {};
            const preview = proposal.preview || {};
            const ext = (currentRes.extraction && currentRes.extraction.extracted) || {};
            const currentTaskId = currentRes.task_id || (currentRes.task && currentRes.task.id) || taskId;

            const header = $('<div class="card-header bg-white py-3 px-3 border-bottom"></div>');
            const topRow = $('<div class="d-flex justify-content-between align-items-center flex-wrap"></div>');
            topRow.append($('<h5 class="font-weight-bold mb-0 text-dark"></h5>').html('<i class="dripicons-pencil mr-2 text-primary"></i>Edit Bill Details'));
            topRow.append($('<span class="badge badge-secondary px-2 py-1">Draft Revision</span>'));
            header.append(topRow);
            card.append(header);

            const body = $('<div class="card-body p-3 bg-white"></div>');
            const formGrid = $('<div class="form-row mb-3"></div>');

            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">Supplier</label><input type="text" class="form-control form-control-sm ai-edit-supplier" value="' + escapeText((preview.supplier && preview.supplier.name) || ext.supplier_name || '') + '"></div>');
            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">Invoice Number</label><input type="text" class="form-control form-control-sm ai-edit-invoice-no" value="' + escapeText(preview.supplier_invoice_no || ext.invoice_number || '') + '"></div>');
            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">Invoice Date</label><input type="date" class="form-control form-control-sm ai-edit-date" value="' + escapeText(preview.date || ext.invoice_date || '') + '"></div>');
            formGrid.append('<div class="form-group col-sm-6 mb-2"><label class="small text-muted mb-1 font-weight-bold">Warehouse</label><input type="text" class="form-control form-control-sm ai-edit-warehouse" value="' + escapeText((preview.warehouse && preview.warehouse.name) || 'Main Warehouse') + '"></div>');
            body.append(formGrid);

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

                const patchData = {
                    supplier_invoice_no: body.find('.ai-edit-invoice-no').val().trim(),
                    date: body.find('.ai-edit-date').val(),
                    items: updatedItems
                };

                $.ajax({
                    url: aiBaseUrl + '/workflow-tasks/' + currentTaskId + '/inputs',
                    method: 'PATCH',
                    headers: baseHeaders(true),
                    data: JSON.stringify({
                        expected_revision: (currentRes.task && currentRes.task.revision) || 1,
                        slots_patch: patchData
                    }),
                    success: function (resPatch) {
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
                                    media: media,
                                    extraction: extraction
                                });
                            },
                            error: function (xhrPrep) {
                                btnSave.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Update & Revalidate');
                                const err = xhrPrep.responseJSON || {};
                                feedback.html('<div class="alert alert-danger py-1 px-2 mb-0">' + escapeText(err.message || 'Validation failed.') + '</div>');
                            }
                        });
                    },
                    error: function (xhrPatch) {
                        btnSave.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Update & Revalidate');
                        const err = xhrPatch.responseJSON || {};
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
        renderUnifiedWorkflowCard: renderUnifiedWorkflowCard,
    };
})(jQuery);
