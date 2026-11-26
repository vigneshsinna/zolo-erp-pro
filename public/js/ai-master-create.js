/**
 * zoloERP Pro — AI Master Create Panel (Task T05)
 * Governed missing-master UI and resumption engine for natural-language workflows.
 * Reuses DependentMasterOrchestrator spawn/preview/approve-and-execute APIs to safely
 * create missing warehouses, suppliers, or products and automatically resume the parent bill.
 */
(function ($) {
    'use strict';

    if (window.AiMasterCreatePanel) {
        return;
    }

    const escapeHtml = function (str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const AiMasterCreatePanel = {
        /**
         * Render the Governed Master Creation Panel into a container.
         *
         * @param {jQuery|string} container
         * @param {Object} options
         *   - taskId: string UUID of parent workflow task
         *   - field: string (e.g. 'warehouse_id', 'supplier_id')
         *   - entityType: 'warehouse' | 'supplier' | 'product' | 'customer' | 'unit'
         *   - initialName: string
         *   - aiBaseUrl: string (default '/ai')
         *   - baseHeaders: function(isJson)
         *   - onCompleted: function(result)
         *   - onCancel: function()
         *   - onUseExisting: function(existingEntity)
         */
        render: function (container, options) {
            const $wrap = $(container);
            $wrap.empty();

            const taskId = options.taskId;
            const field = options.field || 'warehouse_id';
            const entityType = options.entityType || (field.includes('warehouse') ? 'warehouse' : 'supplier');
            const baseUrl = options.aiBaseUrl || (window.zoloAiMultimodalConfig && window.zoloAiMultimodalConfig.mediaBaseUrl) || '/ai';
            const headers = (typeof options.baseHeaders === 'function') ? options.baseHeaders(true) : {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };

            const labels = {
                warehouse: 'Warehouse',
                supplier: 'Supplier',
                product: 'Product',
                customer: 'Customer',
                unit: 'Unit of Measure'
            };
            const labelText = labels[entityType] || 'Record';

            const $panel = $('<div class="ai-master-create-panel border rounded p-3 bg-white shadow-sm"></div>');
            $wrap.append($panel);

            // Step 1: Loading state while spawning requirements
            $panel.html('<div class="text-center text-muted py-4 small"><span class="spinner-border spinner-border-sm mr-2 text-primary"></span>Preparing ' + escapeHtml(labelText) + ' setup requirements...</div>');

            $.ajax({
                url: baseUrl + '/workflow-tasks/' + taskId + '/dependent-masters/spawn',
                method: 'POST',
                headers: headers,
                data: JSON.stringify({ field: field }),
                dataType: 'json'
            }).done(function (spawnData) {
                renderFormStep(spawnData);
            }).fail(function (xhr) {
                const err = (xhr.responseJSON && xhr.responseJSON.message) || 'Permission denied or failed to load setup requirements.';
                $panel.html(
                    '<div class="alert alert-danger py-2 px-3 small mb-2"><i class="dripicons-warning mr-1"></i>' + escapeHtml(err) + '</div>' +
                    '<div class="text-right"><button type="button" class="btn btn-sm btn-light ai-btn-cancel-spawn border">Cancel</button></div>'
                );
                $panel.find('.ai-btn-cancel-spawn').on('click', function () {
                    if (typeof options.onCancel === 'function') options.onCancel();
                });
            });

            // Step 2: Form Step
            function renderFormStep(spawnData) {
                $panel.empty();

                const prefilled = spawnData.prefilled || {};
                const initialName = options.initialName || prefilled.name || '';

                const $header = $('<div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2"></div>');
                $header.append('<h6 class="font-weight-bold mb-0 text-dark"><i class="dripicons-plus mr-1 text-primary"></i>Create New ' + escapeHtml(labelText) + '</h6>');
                const $closeBtn = $('<button type="button" class="btn btn-sm btn-link text-muted p-0" aria-label="Cancel">&times;</button>');
                $closeBtn.on('click', function () {
                    if (typeof options.onCancel === 'function') options.onCancel();
                });
                $header.append($closeBtn);
                $panel.append($header);

                // Duplicate Check Alert Box
                const $dupAlert = $('<div class="ai-duplicate-alert mb-3" style="display: none;"></div>');
                $panel.append($dupAlert);

                const $form = $('<form class="ai-master-form"></form>');
                const $grid = $('<div class="form-row"></div>');

                if (entityType === 'warehouse') {
                    $grid.append(
                        '<div class="form-group col-sm-12 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Warehouse Name *</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-name" required value="' + escapeHtml(initialName) + '" placeholder="e.g. Central Spinning Godown">' +
                        '</div>'
                    );
                    $grid.append(
                        '<div class="form-group col-sm-12 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Address / Location</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-address" value="' + escapeHtml(prefilled.address || 'Industrial Estate, Sector 2') + '">' +
                        '</div>'
                    );
                    $grid.append(
                        '<div class="form-group col-sm-6 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Phone</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-phone" value="' + escapeHtml(prefilled.phone || '') + '">' +
                        '</div>'
                    );
                    $grid.append(
                        '<div class="form-group col-sm-6 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Email</label>' +
                        '<input type="email" class="form-control form-control-sm ai-master-email" value="' + escapeHtml(prefilled.email || '') + '">' +
                        '</div>'
                    );
                } else if (entityType === 'supplier') {
                    $grid.append(
                        '<div class="form-group col-sm-6 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Supplier Name *</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-name" required value="' + escapeHtml(initialName) + '">' +
                        '</div>'
                    );
                    $grid.append(
                        '<div class="form-group col-sm-6 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Company Trade Name</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-company-name" value="' + escapeHtml(prefilled.company_name || initialName) + '">' +
                        '</div>'
                    );
                    $grid.append(
                        '<div class="form-group col-sm-6 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">GSTIN / VAT Number</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-vat" value="' + escapeHtml(prefilled.vat_number || '') + '">' +
                        '</div>'
                    );
                    $grid.append(
                        '<div class="form-group col-sm-6 mb-2">' +
                        '<label class="small font-weight-bold text-dark mb-1">Phone Number</label>' +
                        '<input type="text" class="form-control form-control-sm ai-master-phone" value="' + escapeHtml(prefilled.phone_number || '') + '">' +
                        '</div>'
                    );
                }
                $form.append($grid);

                const $feedback = $('<div class="small mb-2 ai-master-feedback"></div>');
                $form.append($feedback);

                const $footer = $('<div class="pt-2 border-top d-flex justify-content-between align-items-center"></div>');
                const $btnCancel = $('<button type="button" class="btn btn-sm btn-light border">Cancel</button>');
                const $btnPreview = $('<button type="button" class="btn btn-sm btn-primary px-3 font-weight-bold ai-btn-preview-master"><i class="dripicons-preview mr-1"></i>Preview Master Proposal</button>');

                $footer.append($btnCancel).append($btnPreview);
                $form.append($footer);
                $panel.append($form);

                // Duplicate Check on Name Input
                let dupTimer = null;
                $form.find('.ai-master-name').on('input', function () {
                    const typed = $(this).val().trim();
                    clearTimeout(dupTimer);
                    if (typed.length < 3) {
                        $dupAlert.slideUp('fast').empty();
                        return;
                    }
                    dupTimer = setTimeout(function () {
                        $.ajax({
                            url: baseUrl + '/entity-options',
                            method: 'GET',
                            data: { type: entityType, q: typed, per_page: 5 },
                            dataType: 'json'
                        }).done(function (res) {
                            if (res && Array.isArray(res.items) && res.items.length > 0) {
                                const match = res.items[0];
                                $dupAlert.html(
                                    '<div class="alert alert-info py-2 px-3 small d-flex justify-content-between align-items-center">' +
                                    '<div><i class="dripicons-information mr-1"></i>Similar record found: <strong>' + escapeHtml(match.name || match.display) + '</strong></div>' +
                                    '<button type="button" class="btn btn-xs btn-outline-primary ml-2 ai-btn-use-match">Use Existing</button>' +
                                    '</div>'
                                ).slideDown('fast');

                                $dupAlert.find('.ai-btn-use-match').on('click', function () {
                                    if (typeof options.onUseExisting === 'function') {
                                        options.onUseExisting(match);
                                    } else if (typeof options.onCompleted === 'function') {
                                        options.onCompleted({ status: 'used_existing', newly_created: { resource_id: match.id, resource_type: entityType, name: match.name } });
                                    }
                                });
                            } else {
                                $dupAlert.slideUp('fast').empty();
                            }
                        });
                    }, 400);
                });

                $btnCancel.on('click', function () {
                    if (typeof options.onCancel === 'function') options.onCancel();
                });

                $btnPreview.on('click', function () {
                    const nameVal = $form.find('.ai-master-name').val().trim();
                    if (!nameVal) {
                        $feedback.html('<span class="text-danger">Please enter a valid ' + escapeHtml(labelText.toLowerCase()) + ' name.</span>');
                        return;
                    }

                    $btnPreview.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Generating Preview...');
                    $feedback.empty();

                    const masterData = {
                        name: nameVal
                    };

                    if (entityType === 'warehouse') {
                        masterData.address = $form.find('.ai-master-address').val().trim() || 'Industrial Estate, Sector 2';
                        masterData.phone = $form.find('.ai-master-phone').val().trim();
                        masterData.email = $form.find('.ai-master-email').val().trim();
                    } else if (entityType === 'supplier') {
                        masterData.company_name = $form.find('.ai-master-company-name').val().trim() || nameVal;
                        masterData.vat_number = $form.find('.ai-master-vat').val().trim();
                        masterData.phone_number = $form.find('.ai-master-phone').val().trim();
                    }

                    $.ajax({
                        url: baseUrl + '/workflow-tasks/' + taskId + '/dependent-masters/preview',
                        method: 'POST',
                        headers: headers,
                        data: JSON.stringify({
                            field: field,
                            data: masterData
                        }),
                        dataType: 'json'
                    }).done(function (previewRes) {
                        renderProposalStep(previewRes, masterData);
                    }).fail(function (xhr) {
                        $btnPreview.prop('disabled', false).html('<i class="dripicons-preview mr-1"></i>Preview Master Proposal');
                        const err = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to generate proposal preview.';
                        $feedback.html('<span class="text-danger">' + escapeHtml(err) + '</span>');
                    });
                });
            }

            // Step 3: Proposal Review & Approval Step
            function renderProposalStep(previewRes, masterData) {
                $panel.empty();

                const proposal = previewRes.proposal || {};
                const proposalId = proposal.id;
                const proposalVersion = proposal.version || 1;

                const $header = $('<div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2"></div>');
                $header.append('<h6 class="font-weight-bold mb-0 text-dark"><i class="dripicons-document-edit mr-1 text-success"></i>Review Governed ' + escapeHtml(labelText) + ' Proposal</h6>');
                $panel.append($header);

                const $summaryBox = $('<div class="p-3 border rounded bg-light mb-3"></div>');
                $summaryBox.append('<div class="small text-muted mb-1">PROPOSED MASTER RECORD</div>');
                $summaryBox.append('<h5 class="font-weight-bold text-dark mb-2">' + escapeHtml(masterData.name) + '</h5>');

                if (masterData.address) {
                    $summaryBox.append('<div class="small text-muted"><strong>Address:</strong> ' + escapeHtml(masterData.address) + '</div>');
                }
                if (masterData.vat_number) {
                    $summaryBox.append('<div class="small text-muted"><strong>GSTIN / Tax No:</strong> ' + escapeHtml(masterData.vat_number) + '</div>');
                }
                $summaryBox.append('<div class="small text-success mt-2"><i class="dripicons-shield mr-1"></i>Signed proposal ready for human authorization.</div>');
                $panel.append($summaryBox);

                const $feedback = $('<div class="small mb-2 ai-approve-feedback"></div>');
                $panel.append($feedback);

                const $footer = $('<div class="pt-2 border-top d-flex justify-content-between align-items-center"></div>');
                const $btnBack = $('<button type="button" class="btn btn-sm btn-light border">Edit Details</button>');
                const $btnApprove = $('<button type="button" class="btn btn-sm btn-success px-3 font-weight-bold ai-btn-approve-master"><i class="dripicons-checkmark mr-1"></i>Approve & Create ' + escapeHtml(labelText) + '</button>');

                $footer.append($btnBack).append($btnApprove);
                $panel.append($footer);

                $btnBack.on('click', function () {
                    renderFormStep({ prefilled: masterData });
                });

                // Approve & Execute with double-click guard
                $btnApprove.on('click', function () {
                    $btnApprove.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Creating ' + escapeHtml(labelText) + ' in ERP...');
                    $btnBack.prop('disabled', true);
                    $feedback.empty();

                    $.ajax({
                        url: baseUrl + '/workflow-tasks/' + taskId + '/dependent-masters/approve-and-execute',
                        method: 'POST',
                        headers: headers,
                        data: JSON.stringify({
                            field: field,
                            master_proposal_id: proposalId,
                            expected_version: proposalVersion
                        }),
                        dataType: 'json'
                    }).done(function (execRes) {
                        $panel.html(
                            '<div class="alert alert-success py-3 px-3 text-center">' +
                            '<h6 class="font-weight-bold mb-1"><i class="dripicons-checkmark mr-1"></i>' + escapeHtml(labelText) + ' Created Successfully!</h6>' +
                            '<p class="small mb-0">Record ID #' + escapeHtml((execRes.newly_created && execRes.newly_created.resource_id) || '') + ' bound to parent workflow task. Resuming bill verification...</p>' +
                            '</div>'
                        );

                        // If task is ready to prepare purchase bill proposal, trigger it automatically!
                        if (execRes.can_prepare_proposal && taskId) {
                            $.ajax({
                                url: baseUrl + '/workflow-tasks/' + taskId + '/prepare-proposal',
                                method: 'POST',
                                headers: headers,
                                dataType: 'json'
                            }).done(function (propRes) {
                                if (typeof options.onCompleted === 'function') {
                                    options.onCompleted(propRes);
                                }
                            }).fail(function () {
                                if (typeof options.onCompleted === 'function') {
                                    options.onCompleted(execRes);
                                }
                            });
                        } else if (typeof options.onCompleted === 'function') {
                            options.onCompleted(execRes);
                        }
                    }).fail(function (xhr) {
                        $btnApprove.prop('disabled', false).html('<i class="dripicons-checkmark mr-1"></i>Approve & Create ' + escapeHtml(labelText));
                        $btnBack.prop('disabled', false);
                        const err = (xhr.responseJSON && xhr.responseJSON.message) || 'Approval and execution failed.';
                        $feedback.html('<span class="text-danger">' + escapeHtml(err) + '</span>');
                    });
                });
            }
        }
    };

    window.AiMasterCreatePanel = AiMasterCreatePanel;
})(jQuery);
