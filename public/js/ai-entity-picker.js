/**
 * zoloERP Pro — AI Entity Picker Component (Task T04)
 * Authoritative, scoped ERP record search & selection for natural-language workflows.
 * Supports warehouses, suppliers, products, customers, and units with live debounce,
 * branch eligibility checking, recommendation badges, and accessible keyboard navigation.
 */
(function ($) {
    'use strict';

    if (window.AiEntityPicker) {
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

    const AiEntityPicker = {
        /**
         * Render an interactive Entity Picker into a target container.
         *
         * @param {jQuery|string} container
         * @param {Object} config
         *   - entityType: 'warehouse' | 'supplier' | 'product' | 'customer' | 'unit'
         *   - taskId: string UUID
         *   - taskRevision: number
         *   - fieldPath: string (e.g. 'warehouse_id', 'items[line-0].product_id')
         *   - selectedId: number|null
         *   - initialQuery: string
         *   - aiBaseUrl: string (default '/ai')
         *   - onSelect: function(entity, source)
         *   - onCreateRequested: function()
         *   - onCancel: function()
         */
        renderPicker: function (container, config) {
            const $wrap = $(container);
            $wrap.empty();

            const entityType = config.entityType || 'warehouse';
            const taskId = config.taskId || '';
            const fieldPath = config.fieldPath || 'warehouse_id';
            const baseUrl = config.aiBaseUrl || (window.zoloAiMultimodalConfig && window.zoloAiMultimodalConfig.mediaBaseUrl) || '/ai';
            let activeXhr = null;
            let debounceTimer = null;
            let selectedItem = null;
            let currentItems = [];
            let highlightedIndex = -1;

            const entityLabels = {
                warehouse: 'Warehouse',
                supplier: 'Supplier',
                product: 'Product',
                customer: 'Customer',
                unit: 'Unit of Measure'
            };
            const labelText = entityLabels[entityType] || 'Record';

            const $picker = $('<div class="ai-entity-picker border rounded p-3 bg-white shadow-sm" role="region" aria-label="Search ' + escapeHtml(labelText) + '"></div>');

            // Header & search input
            const $header = $('<div class="ai-picker-header mb-2"></div>');
            const $titleRow = $('<div class="d-flex justify-content-between align-items-center mb-2"></div>');
            $titleRow.append('<strong class="small text-dark font-weight-bold"><i class="dripicons-search mr-1 text-primary"></i>Choose ' + escapeHtml(labelText) + '</strong>');

            if (typeof config.onCancel === 'function') {
                const $closeBtn = $('<button type="button" class="btn btn-sm btn-link text-muted p-0" aria-label="Close picker">&times;</button>');
                $closeBtn.on('click', function () {
                    config.onCancel();
                });
                $titleRow.append($closeBtn);
            }
            $header.append($titleRow);

            const $searchGroup = $('<div class="input-group input-group-sm mb-2"></div>');
            const $searchInput = $('<input type="search" class="form-control ai-picker-search-input" autocomplete="off" placeholder="Search existing ' + escapeHtml(labelText.toLowerCase()) + 's..." aria-label="Search ' + escapeHtml(labelText) + '">');
            if (config.initialQuery) {
                $searchInput.val(config.initialQuery);
            }
            $searchGroup.append($searchInput);
            $header.append($searchGroup);
            $picker.append($header);

            // Options List
            const $list = $('<div class="ai-picker-list mb-3" role="listbox" aria-label="' + escapeHtml(labelText) + ' options" style="max-height: 240px; overflow-y: auto;"></div>');
            $picker.append($list);

            // Footer with Use Selected, Create New & Cancel
            const $footer = $('<div class="ai-picker-footer pt-2 border-top d-flex justify-content-between align-items-center flex-wrap"></div>');
            const $leftActions = $('<div></div>');
            const $rightActions = $('<div class="d-flex gap-2"></div>');

            const $btnUse = $('<button type="button" class="btn btn-sm btn-primary px-3 ai-picker-btn-use" disabled><i class="dripicons-checkmark mr-1"></i>Use Selected</button>');
            const $btnCancel = $('<button type="button" class="btn btn-sm btn-light border px-2 mr-2">Cancel</button>');
            const $btnCreate = $('<button type="button" class="btn btn-sm btn-outline-primary ai-picker-btn-create" style="display: none;"><i class="dripicons-plus mr-1"></i>Create New</button>');

            $leftActions.append($btnCreate);
            $rightActions.append($btnCancel).append($btnUse);
            $footer.append($leftActions).append($rightActions);
            $picker.append($footer);

            $wrap.append($picker);

            // Search Query Function
            const performSearch = function (query) {
                if (activeXhr) {
                    activeXhr.abort();
                }

                $list.html('<div class="text-center text-muted py-3 small"><span class="spinner-border spinner-border-sm mr-1"></span>Loading ' + escapeHtml(labelText.toLowerCase()) + 's...</div>');
                $btnUse.prop('disabled', true);
                selectedItem = null;
                highlightedIndex = -1;

                const params = {
                    type: entityType,
                    q: query || '',
                    task_id: taskId || '',
                    field: fieldPath || '',
                    page: 1,
                    per_page: 20
                };

                activeXhr = $.ajax({
                    url: baseUrl + '/entity-options',
                    method: 'GET',
                    data: params,
                    dataType: 'json'
                }).done(function (res) {
                    renderResults(res);
                }).fail(function (xhr, status) {
                    if (status === 'abort') return;
                    // Fallback to /entity-lookup if /entity-options differs
                    $.ajax({
                        url: baseUrl + '/entity-lookup',
                        method: 'GET',
                        data: params,
                        dataType: 'json'
                    }).done(function (res) {
                        renderResults(res);
                    }).fail(function () {
                        $list.html('<div class="text-center text-danger py-3 small"><i class="dripicons-warning mr-1"></i>Could not load records. Please try again.</div>');
                    });
                });
            };

            const renderResults = function (res) {
                $list.empty();
                currentItems = res.items || [];

                if (res.can_create) {
                    $btnCreate.show().text('+ Create New ' + labelText);
                    $btnCreate.off('click').on('click', function () {
                        if (typeof config.onCreateRequested === 'function') {
                            config.onCreateRequested();
                        }
                    });
                } else {
                    $btnCreate.hide();
                }

                if (currentItems.length === 0) {
                    $list.html('<div class="text-center text-muted py-3 small">No matching ' + escapeHtml(labelText.toLowerCase()) + ' records found.</div>');
                    return;
                }

                currentItems.forEach(function (item, idx) {
                    const isSelected = (config.selectedId && Number(config.selectedId) === Number(item.id)) || (res.recommended_id && Number(res.recommended_id) === Number(item.id));
                    const isEligible = item.eligible !== false;

                    const $row = $('<div class="ai-picker-option p-2 rounded mb-1 border d-flex justify-content-between align-items-center" role="option"></div>')
                        .attr('data-id', item.id)
                        .attr('data-index', idx)
                        .attr('aria-selected', isSelected ? 'true' : 'false')
                        .attr('aria-disabled', isEligible ? 'false' : 'true');

                    if (!isEligible) {
                        $row.addClass('bg-light text-muted opacity-75');
                        $row.css('cursor', 'not-allowed');
                    } else {
                        $row.css('cursor', 'pointer');
                        if (isSelected) {
                            $row.addClass('border-primary bg-light');
                            selectedItem = item;
                            $btnUse.prop('disabled', false);
                        }
                    }

                    const $left = $('<div class="d-flex align-items-center"></div>');
                    const $radio = $('<input type="radio" name="ai-picker-radio" class="mr-2">')
                        .val(item.id)
                        .prop('checked', isSelected)
                        .prop('disabled', !isEligible);
                    $left.append($radio);

                    const $info = $('<div></div>');
                    const $nameLine = $('<div class="font-weight-bold small text-dark"></div>').text(item.name || item.display);
                    $info.append($nameLine);

                    const metaParts = [];
                    if (item.branch_label || item.branch_name) {
                        metaParts.push(item.branch_label || item.branch_name);
                    }
                    if (item.location) {
                        metaParts.push(item.location);
                    }
                    if (item.code) {
                        metaParts.push('Code: ' + item.code);
                    }
                    const spec = item.optical_spec || (item.metadata && item.metadata.optical_spec);
                    if (spec && typeof spec === 'object') {
                        const specParts = [];
                        if (spec.lr) specParts.push(spec.lr);
                        if (spec.sph != null) specParts.push('SPH ' + spec.sph);
                        if (spec.cyl != null) specParts.push('CYL ' + spec.cyl);
                        if (spec.axis != null) specParts.push('AXIS ' + spec.axis);
                        if (spec.addition != null) specParts.push('ADD ' + spec.addition);
                        if (spec.dia != null) specParts.push('DIA ' + spec.dia);
                        if (spec.coating) specParts.push(spec.coating);
                        if (specParts.length > 0) metaParts.push(specParts.join(' · '));
                    }
                    if (metaParts.length > 0) {
                        $info.append($('<small class="text-muted d-block"></small>').text(metaParts.join(' · ')));
                    }
                    $left.append($info);
                    $row.append($left);

                    const $badges = $('<div class="text-right"></div>');
                    if (res.recommended_id && Number(res.recommended_id) === Number(item.id)) {
                        $badges.append('<span class="badge badge-success px-2 py-1 small font-weight-normal"><i class="dripicons-star mr-1"></i>Recommended</span>');
                    }
                    if (item.available_qty != null) {
                        const qtyLabel = 'Stock: ' + item.available_qty + (item.stock_as_of ? ' @ ' + String(item.stock_as_of).slice(11, 16) + 'Z' : '');
                        $badges.append($('<span class="badge badge-info px-2 py-1 small font-weight-normal ml-1"></span>').text(qtyLabel));
                    }
                    if (Array.isArray(item.mismatch_badges) && item.mismatch_badges.length > 0) {
                        item.mismatch_badges.forEach(function (mb) {
                            $badges.append($('<span class="badge badge-warning px-2 py-1 small font-weight-normal ml-1"></span>').text(mb));
                        });
                    }
                    if (!isEligible && item.disabled_reason) {
                        $badges.append('<span class="badge badge-secondary px-2 py-1 small font-weight-normal ml-1">' + escapeHtml(item.disabled_reason) + '</span>');
                    }
                    $row.append($badges);

                    if (isEligible) {
                        $row.on('click', function () {
                            $list.find('.ai-picker-option').removeClass('border-primary bg-light').attr('aria-selected', 'false');
                            $list.find('input[type="radio"]').prop('checked', false);
                            $row.addClass('border-primary bg-light').attr('aria-selected', 'true');
                            $radio.prop('checked', true);
                            selectedItem = item;
                            $btnUse.prop('disabled', false);
                        });
                    }

                    $list.append($row);
                });
            };

            // Keyboard navigation
            $searchInput.on('input', function () {
                const val = $(this).val();
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    performSearch(val);
                }, 300);
            });

            $searchInput.on('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (currentItems.length > 0) {
                        highlightedIndex = Math.min(highlightedIndex + 1, currentItems.length - 1);
                        updateHighlight();
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (currentItems.length > 0) {
                        highlightedIndex = Math.max(highlightedIndex - 1, 0);
                        updateHighlight();
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIndex >= 0 && currentItems[highlightedIndex] && currentItems[highlightedIndex].eligible !== false) {
                        selectedItem = currentItems[highlightedIndex];
                        confirmSelection();
                    } else if (selectedItem) {
                        confirmSelection();
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    if (typeof config.onCancel === 'function') {
                        config.onCancel();
                    }
                }
            });

            const updateHighlight = function () {
                $list.find('.ai-picker-option').removeClass('bg-secondary text-white');
                if (highlightedIndex >= 0) {
                    const $target = $list.find('.ai-picker-option[data-index="' + highlightedIndex + '"]');
                    if ($target.length > 0) {
                        $target[0].scrollIntoView({ block: 'nearest' });
                        if (!$target.hasClass('border-primary')) {
                            $target.addClass('bg-light');
                        }
                    }
                }
            };

            const confirmSelection = function () {
                if (!selectedItem || selectedItem.eligible === false) return;
                if (typeof config.onSelect === 'function') {
                    config.onSelect(selectedItem, 'user_selected');
                }
            };

            $btnUse.on('click', confirmSelection);
            $btnCancel.on('click', function () {
                if (typeof config.onCancel === 'function') {
                    config.onCancel();
                }
            });

            // Initial fetch
            performSearch(config.initialQuery || '');
        },

        /**
         * Render a selection summary card when a record is resolved.
         *
         * @param {jQuery|string} container
         * @param {Object} options
         *   - label: string (e.g. 'Destination Warehouse')
         *   - entityName: string
         *   - metadata: string (e.g. 'Branch 61 · Coimbatore Main Mill')
         *   - reason: string
         *   - onChangeRequested: function()
         */
        renderSelectionSummary: function (container, options) {
            const $wrap = $(container);
            $wrap.empty();

            const $card = $('<div class="ai-selection-summary p-2 border rounded bg-light d-flex justify-content-between align-items-center"></div>');
            const $left = $('<div></div>');
            $left.append('<small class="text-muted d-block font-weight-bold">' + escapeHtml(options.label || 'Selected Record') + '</small>');
            $left.append('<strong class="text-dark small">' + escapeHtml(options.entityName || '—') + '</strong>');

            if (options.metadata || options.reason) {
                const metaText = [options.metadata, options.reason].filter(Boolean).join(' · ');
                $left.append('<small class="text-muted d-block">' + escapeHtml(metaText) + '</small>');
            }
            $card.append($left);

            if (typeof options.onChangeRequested === 'function') {
                const $btnChange = $('<button type="button" class="btn btn-sm btn-link text-primary font-weight-bold p-0 ml-3">Change</button>');
                $btnChange.on('click', function () {
                    options.onChangeRequested();
                });
                $card.append($btnChange);
            }

            $wrap.append($card);
        }
    };

    window.AiEntityPicker = AiEntityPicker;
    window.AiSelectionSummary = {
        render: AiEntityPicker.renderSelectionSummary
    };
})(jQuery);
