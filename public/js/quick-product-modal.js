/**
 * Quick Product Modal Logic
 * Controls dynamic responsive grid spans, toggle buttons, and real-time margin/price calculation.
 */
(function ($) {
    'use strict';

    function applyQuickModalDynamicGridSpans() {
        var isCombo = $('#quick-item-type').val() === 'combo';
        var itemType = $('#quick-item-type').val();
        $('#quick-item-unit').prop('required', itemType === 'standard' || itemType === 'combo' || !itemType);

        var sections = [
            {
                id: 'quick-initial-stock-section',
                $el: $('#quick-initial-stock-section'),
                isWide: false,
                active: !isCombo && $('#quick_chk_initial_stock').is(':checked')
            },
            {
                id: 'quick-diffPrice-section',
                $el: $('#quick-diffPrice-section'),
                isWide: false,
                active: !isCombo && $('#quick_is_diffPrice').is(':checked')
            },
            {
                id: 'quick-imei-section',
                $el: $('#quick-imei-section'),
                isWide: false,
                active: !isCombo && $('#quick_is_imei').is(':checked')
            },
            {
                id: 'quick-promo-container',
                $el: $('#quick-promo-container'),
                isWide: false,
                active: $('#quick_promotion').is(':checked')
            },
            {
                id: 'quick-variant-section',
                $el: $('#quick-variant-section'),
                isWide: true,
                active: !isCombo && $('#quick_is_variant').is(':checked')
            },
            {
                id: 'quick-batch-section',
                $el: $('#quick-batch-section'),
                isWide: true,
                active: !isCombo && $('#quick_is_batch').is(':checked')
            }
        ];

        var spanClasses = 'zolo-grid-span-1 zolo-grid-span-2 zolo-grid-span-3 zolo-grid-span-4';
        sections.forEach(function (s) {
            if (s.$el.length) {
                s.$el.removeClass(spanClasses);
            }
        });

        var activeSections = sections.filter(function (s) {
            return s.active && s.$el.length;
        });

        var count = activeSections.length;
        var $container = $('#quick-create-item-modal .product-extended-details-container');
        if (count === 0) {
            $container.addClass('d-none u-d-none');
            return;
        }
        $container.removeClass('d-none u-d-none');

        if (count === 1) {
            activeSections[0].$el.addClass('zolo-grid-span-4');
        } else if (count === 2) {
            var wideList = activeSections.filter(function (s) { return s.isWide; });
            var compactList = activeSections.filter(function (s) { return !s.isWide; });

            if (wideList.length === 1 && compactList.length === 1) {
                compactList[0].$el.addClass('zolo-grid-span-1');
                wideList[0].$el.addClass('zolo-grid-span-3');
            } else {
                activeSections[0].$el.addClass('zolo-grid-span-2');
                activeSections[1].$el.addClass('zolo-grid-span-2');
            }
        } else if (count === 3) {
            var wideList = activeSections.filter(function (s) { return s.isWide; });
            var compactList = activeSections.filter(function (s) { return !s.isWide; });

            if (wideList.length === 1 && compactList.length === 2) {
                compactList[0].$el.addClass('zolo-grid-span-1');
                compactList[1].$el.addClass('zolo-grid-span-1');
                wideList[0].$el.addClass('zolo-grid-span-2');
            } else if (wideList.length === 0) {
                activeSections[0].$el.addClass('zolo-grid-span-2');
                activeSections[1].$el.addClass('zolo-grid-span-1');
                activeSections[2].$el.addClass('zolo-grid-span-1');
            } else if (wideList.length === 2 && compactList.length === 1) {
                wideList[0].$el.addClass('zolo-grid-span-2');
                wideList[1].$el.addClass('zolo-grid-span-2');
                compactList[0].$el.addClass('zolo-grid-span-4');
            }
        } else if (count >= 4) {
            activeSections.forEach(function (s) {
                s.$el.addClass(s.isWide ? 'zolo-grid-span-2' : 'zolo-grid-span-1');
            });
        }
    }

    function syncQuickSections() {
        var targetMap = {
            '#quick_chk_initial_stock': '#quick-initial-stock-section',
            '#quick_is_diffPrice': '#quick-diffPrice-section',
            '#quick_is_variant': '#quick-variant-section',
            '#quick_is_batch': '#quick-batch-section',
            '#quick_is_imei': '#quick-imei-section',
            '#quick_promotion': '#quick-promo-container'
        };

        var isCombo = $('#quick-item-type').val() === 'combo';
        var itemType = $('#quick-item-type').val();
        $('#quick-item-unit').prop('required', itemType === 'standard' || itemType === 'combo' || !itemType);

        $.each(targetMap, function (chkId, sectionId) {
            var isChecked = !isCombo && $(chkId).is(':checked');
            var $pill = $(chkId).closest('.zolo-toggle-pill');
            $pill.toggleClass('is-active', isChecked);
            if (isChecked) {
                $(sectionId).removeClass('d-none u-d-none').show();
            } else {
                $(sectionId).addClass('d-none u-d-none').hide();
            }
        });

        applyQuickModalDynamicGridSpans();
    }

    $(function () {
        syncQuickSections();
        $('#quick-item-type').on('change', syncQuickSections);

        // Switch toggles within quick modal
        $('#quick-create-item-modal .zolo-toggle-pill input[type="checkbox"]').on('change', function () {
            var $pill = $(this).closest('.zolo-toggle-pill');
            $pill.toggleClass('is-active', $(this).is(':checked'));

            var id = $(this).attr('id');
            var targetMap = {
                'quick_chk_initial_stock': '#quick-initial-stock-section',
                'quick_is_diffPrice': '#quick-diffPrice-section',
                'quick_is_variant': '#quick-variant-section',
                'quick_is_batch': '#quick-batch-section',
                'quick_is_imei': '#quick-imei-section',
                'quick_promotion': '#quick-promo-container'
            };

            var target = targetMap[id];
            if (target) {
                if ($(this).is(':checked')) {
                    $(target).removeClass('d-none u-d-none').hide().slideDown(200);
                } else {
                    $(target).slideUp(200, function () {
                        $(this).addClass('d-none u-d-none');
                    });
                }
            }

            applyQuickModalDynamicGridSpans();
        });

        // Price, Cost, and Profit Margin 2-way calculations
        function calculateQuickPrice() {
            var cost = parseFloat($('#quick-item-cost').val()) || 0;
            var margin = parseFloat($('#quick-item-margin').val()) || 0;
            var marginType = $('#quick-item-margin-type').val() || 'percentage';

            if (cost >= 0) {
                var price = 0;
                if (marginType === 'percentage') {
                    price = cost + (cost * margin / 100);
                } else {
                    price = cost + margin;
                }
                $('#quick-item-price').val(price.toFixed(2));
            }
        }

        function calculateQuickMargin() {
            var cost = parseFloat($('#quick-item-cost').val()) || 0;
            var price = parseFloat($('#quick-item-price').val()) || 0;
            var marginType = $('#quick-item-margin-type').val() || 'percentage';

            if (cost > 0 && price >= cost) {
                var margin = 0;
                if (marginType === 'percentage') {
                    margin = ((price - cost) / cost) * 100;
                } else {
                    margin = price - cost;
                }
                $('#quick-item-margin').val(margin.toFixed(2));
            }
        }

        $('#quick-item-cost, #quick-item-margin').on('input change', calculateQuickPrice);
        $('#quick-item-margin-type').on('change', calculateQuickPrice);
        $('#quick-item-price').on('input change', calculateQuickMargin);

        // Auto Generate Code button
        $('#btn-quick-gen-code').on('click', function (e) {
            e.preventDefault();
            var code = 'ITM-' + Math.floor(100000 + Math.random() * 900000);
            $('#quick-item-code').val(code);
        });

        // Refresh selectpickers and spans when modal opens
        var defaultTitleHtml = $('#quick-modal-title').html();
        var defaultSubtitleText = $('#quick-modal-subtitle').text();
        var defaultSubmitHtml = $('#btn-quick-create-submit').html();

        function resetQuickModalMode() {
            $('#quick-edit-product-id').val('');
            $('#quick-create-item-modal').removeData('edit-row').removeData('edit-prev-master');
            $('#quick-modal-error-banner').addClass('d-none').empty();
            if (defaultTitleHtml) $('#quick-modal-title').html(defaultTitleHtml);
            if (defaultSubtitleText) $('#quick-modal-subtitle').text(defaultSubtitleText);
            if (defaultSubmitHtml) $('#btn-quick-create-submit').prop('disabled', false).html(defaultSubmitHtml);
        }

        function setSelectVal(selector, val, fallbackLabel) {
            var $el = $(selector);
            if (!$el.length) return;
            var strVal = (val !== null && val !== undefined) ? String(val) : '';
            if (strVal !== '' && !$el.find('option').filter(function () { return String(this.value) === strVal; }).length && fallbackLabel) {
                $el.append($('<option>', { value: strVal, text: fallbackLabel }));
            }
            $el.val(strVal);
            if ($.fn.selectpicker && $el.hasClass('selectpicker')) {
                $el.selectpicker('refresh');
            }
        }

        function setCheckVal(selector, checked) {
            var $el = $(selector);
            if (!$el.length) return;
            $el.prop('checked', Boolean(checked));
            $el.closest('.zolo-toggle-pill').toggleClass('is-active', Boolean(checked));
        }

        function populateQuickEditForm(p) {
            setSelectVal('#quick-item-type', p.type || 'standard');
            setSelectVal('#quick-item-symbology', p.barcode_symbology || 'C128');
            $('#quick-item-name').val(p.name || '');
            $('#quick-item-code').val(p.code || '');
            setSelectVal('#quick-item-brand', p.brand_id || '');
            setSelectVal('#quick-item-category', p.category_id || '');
            setSelectVal('#quick-item-unit', p.unit_id || '', p.unit || 'Unit');
            setSelectVal('#quick-item-sale-unit', p.sale_unit_id || p.unit_id || '', p.unit || 'Unit');
            setSelectVal('#quick-item-purchase-unit', p.purchase_unit_id || p.unit_id || '', p.unit || 'Unit');

            var $usageRadios = $('#quick-create-item-form input[name="product_usage"]');
            if ($usageRadios.length) {
                var targetUsage = p.product_usage || 'composite';
                var $target = $usageRadios.filter('[value="' + targetUsage + '"]:not(:disabled)');
                if (!$target.length) {
                    $target = $usageRadios.filter('[value="composite"]:not(:disabled)');
                }
                if ($target.length) {
                    $target.prop('checked', true).trigger('change');
                }
            }

            $('#quick-item-cost').val(p.cost !== null && p.cost !== undefined ? p.cost : 0);
            setSelectVal('#quick-item-margin-type', p.profit_margin_type || 'percentage');
            $('#quick-item-margin').val(p.profit_margin !== null && p.profit_margin !== undefined ? p.profit_margin : 0);
            $('#quick-item-price').val(p.price !== null && p.price !== undefined ? p.price : 0);
            $('#quick-item-wholesale').val(p.wholesale_price !== null && p.wholesale_price !== undefined ? p.wholesale_price : '');
            $('#quick-item-daily-sale').val(p.daily_sale_objective !== null && p.daily_sale_objective !== undefined ? p.daily_sale_objective : '');

            if ($('#quick-item-tax').data('gst')) {
                setSelectVal('#quick-item-tax', p.tax_category_id || '');
            } else {
                setSelectVal('#quick-item-tax', p.tax_id || '');
            }
            if ($('#quick-item-hsn').length) {
                $('#quick-item-hsn').val(p.hsn_code || '');
            }
            setSelectVal('#quick-item-tax-method', p.tax_method || 1);
            setSelectVal('#negative-stock-policy', p.negative_stock_policy || '');

            $('#quick-item-warranty').val(p.warranty || '');
            setSelectVal('#quick-item-warranty-type', p.warranty_type || 'months');
            $('#quick-item-guarantee').val(p.guarantee || '');
            setSelectVal('#quick-item-guarantee-type', p.guarantee_type || 'months');

            $('#quick-item-details').val(p.product_details || '');
            $('#quick-item-alert-qty').val(p.alert_quantity !== null && p.alert_quantity !== undefined ? p.alert_quantity : '');

            setCheckVal('#quick_chk_initial_stock', false);
            setCheckVal('#quick_is_diffPrice', p.is_diffPrice);
            setCheckVal('#quick_is_variant', p.is_variant);
            setCheckVal('#quick_is_batch', p.is_batch);
            setCheckVal('#quick_is_imei', p.is_imei);
            setCheckVal('#quick_promotion', p.promotion);
            setCheckVal('#quick_chk_featured', p.featured);
            setCheckVal('#quick_chk_embeded', p.is_embeded);

            $('#quick-promo-container input[name="promotion_price"]').val(p.promotion_price !== null && p.promotion_price !== undefined ? p.promotion_price : '');
            $('#quick-promo-container input[name="starting_date"]').val(p.starting_date || '');
            $('#quick-promo-container input[name="last_date"]').val(p.last_date || '');

            $('#quick-diffPrice-section tbody tr').each(function () {
                var whId = $(this).find('input[name="warehouse_id[]"]').val();
                var val = (p.diff_prices && p.diff_prices[whId] !== undefined) ? p.diff_prices[whId] : '';
                $(this).find('input[name="diff_price[]"]').val(val);
            });

            $('#quick-imei-section tbody tr').each(function () {
                var whId = $(this).find('input[name="imei_warehouse_id[]"]').val();
                var val = (p.imeis && p.imeis[whId] !== undefined) ? p.imeis[whId] : '';
                $(this).find('input[name="imei_number[]"]').val(val);
            });

            var optVal = Array.isArray(p.variant_option) ? p.variant_option.join(', ') : '';
            var valStr = Array.isArray(p.variant_value) ? p.variant_value.join(', ') : '';
            $('#quick-variant-section input[name="variant_option[]"]').first().val(optVal);
            $('#quick-variant-section input[name="variant_value[]"]').first().val(valStr);

            syncQuickSections();
        }

        window.zoloOpenQuickEditProduct = function (productId, $sourceRow) {
            var $modal = $('#quick-create-item-modal');
            var $form = $('#quick-create-item-form');
            if (!$modal.length || !$form.length || !productId) return false;

            var base = $form.attr('data-quick-edit-base') || '/products';
            resetQuickModalMode();
            $form[0].reset();
            $('#quick-edit-product-id').val(String(productId));
            $modal.data('edit-row', $sourceRow && $sourceRow.length ? $sourceRow : null);
            $('#quick-modal-title').html('<i class="fa fa-edit u-c-primary u-fs-18px"></i> Edit Product (Catalog Master)');
            $('#quick-modal-subtitle').text('Update catalog master details. Changes apply cleanly and refresh this voucher without losing your draft.');
            $('#btn-quick-create-submit').html('<i class="dripicons-checkmark"></i> Save Changes & Update Voucher').prop('disabled', true);
            $modal.modal('show');

            $.ajax({
                type: 'GET',
                url: base + '/' + encodeURIComponent(productId) + '/quick-edit',
                dataType: 'json',
                success: function (res) {
                    if (!res || !res.product) {
                        $('#quick-modal-error-banner').text('Unable to load product details.').removeClass('d-none');
                        return;
                    }
                    $modal.data('edit-prev-master', {
                        cost: Number(res.product.cost || 0),
                        price: Number(res.product.price || 0),
                    });
                    populateQuickEditForm(res.product);
                    $('#btn-quick-create-submit').prop('disabled', false);
                },
                error: function (xhr) {
                    var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Unable to load product details.';
                    $('#quick-modal-error-banner').text(msg).removeClass('d-none');
                    $('#btn-quick-create-submit').prop('disabled', false);
                }
            });
            return true;
        };

        window.zoloSubmitQuickEditProduct = function (formEl) {
            var productId = $('#quick-edit-product-id').val();
            if (!productId) return false;

            var $form = $(formEl);
            var $modal = $('#quick-create-item-modal');
            var $btn = $('#btn-quick-create-submit');
            var base = $form.attr('data-quick-edit-base') || '/products';
            var token = $form.find('input[name="_token"]').val() || $('meta[name="csrf-token"]').attr('content') || '';

            $('#quick-modal-error-banner').addClass('d-none').empty();
            $btn.prop('disabled', true).text('Saving...');

            var formData = new FormData(formEl);
            formData.set('_token', token);
            formData.set('name', ($('#quick-item-name').val() || '').trim());
            formData.set('code', ($('#quick-item-code').val() || '').trim());
            formData.set('cost', parseFloat($('#quick-item-cost').val()) || 0);
            var priceVal = parseFloat($('#quick-item-price').val());
            formData.set('price', isNaN(priceVal) ? 0 : priceVal);
            var unitId = $('#quick-item-unit').val() || '';
            formData.set('unit_id', unitId);
            formData.set('sale_unit_id', $('#quick-item-sale-unit').val() || unitId);
            formData.set('purchase_unit_id', $('#quick-item-purchase-unit').val() || unitId);
            formData.set('tax_method', $('#quick-item-tax-method').val() || 1);
            if ($('#quick-item-tax').data('gst')) {
                formData.set('tax_category_id', $('#quick-item-tax').val() || '');
                formData.delete('tax_id');
            } else {
                formData.set('tax_id', $('#quick-item-tax').val() || '');
                formData.delete('tax_category_id');
            }
            ['is_batch', 'is_imei', 'featured', 'is_embeded', 'is_online', 'in_stock', 'is_initial_stock', 'is_diffPrice', 'is_variant', 'promotion'].forEach(function (k) {
                formData.set(k, $form.find('[name="' + k + '"]').is(':checked') ? 1 : 0);
            });

            var prevMaster = $modal.data('edit-prev-master') || {};
            var $editRow = $modal.data('edit-row') || null;

            $.ajax({
                type: 'POST',
                url: base + '/' + encodeURIComponent(productId) + '/quick-update',
                data: formData,
                contentType: false,
                processData: false,
                success: function (res) {
                    if (res && res.product) {
                        $(document).trigger('zolo:product-updated', [res.product, prevMaster, $editRow]);
                    }
                    $modal.modal('hide');
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).html('<i class="dripicons-checkmark"></i> Save Changes & Update Voucher');
                    var body = (xhr && xhr.responseJSON) ? xhr.responseJSON : {};
                    var msg = body.message || 'Failed to update product.';
                    if (body.errors) {
                        var firstErr = Object.values(body.errors).flat()[0];
                        if (firstErr) msg = firstErr;
                    }
                    $('#quick-modal-error-banner').text(msg).removeClass('d-none');
                }
            });
            return true;
        };

        // Shared hover-edit click handler for any item row on Purchases, Sales, GRN, or DC
        $(document).on('click', '.btn-quick-edit-product', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $tr = $(this).closest('tr');
            var productId = $tr.find('.row-product-id').val() || $tr.attr('data-product-id');
            if (productId && Number(productId) > 0 && window.zoloOpenQuickEditProduct) {
                window.zoloOpenQuickEditProduct(productId, $tr);
            }
        });

        $('#quick-create-item-modal').on('hidden.bs.modal', function () {
            resetQuickModalMode();
        });

        $('#quick-create-item-modal').on('show.bs.modal shown.bs.modal', function () {
            syncQuickSections();
            if ($.fn.selectpicker) {
                $(this).find('.selectpicker').selectpicker('render');
            }
            if (!$('#quick-edit-product-id').val() && !$('#quick-item-code').val()) {
                $('#quick-item-code').val('ITM-' + Math.floor(100000 + Math.random() * 900000));
            }
            setTimeout(function () {
                $('#quick-item-name').focus();
            }, 100);
        });

        window.zoloSyncUpdatedProductInGrid = function (p, prevMaster, opts) {
            if (!p || !p.id) return;
            opts = opts || {};
            var products = opts.products || [];
            var codes = opts.codes || [];
            var units = opts.units || [];
            var rateField = opts.rateField || 'cost';
            var escapeFn = opts.escapeHtml || function (s) { return $('<span>').text(s || '').html(); };

            var existingIdx = products.findIndex(function (item) { return String(item.id) === String(p.id); });
            var oldName = existingIdx !== -1 ? products[existingIdx].name : p.name;
            var oldCode = existingIdx !== -1 ? products[existingIdx].code : p.code;
            if (existingIdx !== -1) {
                products[existingIdx] = $.extend({}, products[existingIdx], p);
            } else {
                products.unshift(p);
            }

            if (codes.length && p.value) {
                var codeIdx = codes.findIndex(function (c) {
                    return String(c) === (oldCode + '|' + oldName) || String(c).indexOf((oldCode || '') + '|') === 0;
                });
                if (codeIdx !== -1) codes[codeIdx] = p.value;
                else codes.unshift(p.value);
            }

            var prevRate = prevMaster ? Number(prevMaster[rateField] || 0) : null;
            var nextRate = Number(p[rateField] !== undefined ? p[rateField] : (p.cost ?? p.price ?? 0));

            $('#order-table-body tr.order-item-row[data-product-id="' + p.id + '"]').each(function () {
                var $tr = $(this);
                $tr.find('.row-item-name').val(p.name).attr('title', p.code ? 'Code: ' + p.code : '');
                $tr.find('.row-product-code').val(p.code || '');
                $tr.find('.row-item-input-wrap').addClass('has-product');

                if (p.unit || p.unit_name) {
                    var targetUnit = p.unit_name || p.unit;
                    var matchedUnit = units.find(function (u) {
                        return (u.unit_name && u.unit_name.toLowerCase() === targetUnit.toLowerCase()) ||
                               (u.unit_code && p.unit && u.unit_code.toLowerCase() === p.unit.toLowerCase());
                    });
                    var unitLabel = matchedUnit ? matchedUnit.unit_name : targetUnit;
                    var $uSel = $tr.find('.row-unit-select');
                    var matchedOpt = false;
                    $uSel.find('option').each(function () {
                        if ($(this).val().toLowerCase() === unitLabel.toLowerCase() || $(this).text().toLowerCase() === unitLabel.toLowerCase()) {
                            $uSel.val($(this).val());
                            matchedOpt = true;
                            return false;
                        }
                    });
                    if (!matchedOpt && unitLabel) {
                        $uSel.append('<option value="' + escapeFn(unitLabel) + '" selected>' + escapeFn(unitLabel) + '</option>');
                    }
                }

                var $rateEl = $tr.find('.row-rate');
                var currentRowRate = parseFloat($rateEl.val());
                if (isNaN(currentRowRate) || currentRowRate === 0 || (prevRate !== null && Math.abs(currentRowRate - prevRate) < 0.0001)) {
                    $rateEl.val(nextRate);
                    $tr.find('.row-unit-cost-input, .row-net-unit-price').val(nextRate);
                }

                var $tSel = $tr.find('.row-tax-rate');
                if ($tSel.length && !$tSel.hasClass('is-locked') && p.tax_rate !== undefined && p.tax_rate !== null) {
                    var newTax = parseFloat(p.tax_rate) || 0;
                    var taxMatched = false;
                    $tSel.find('option').each(function () {
                        if (Math.abs(parseFloat($(this).val()) - newTax) < 0.0001) {
                            $tSel.val($(this).val());
                            taxMatched = true;
                            return false;
                        }
                    });
                    if (!taxMatched) {
                        $tSel.append('<option value="' + newTax + '" selected>' + newTax + '%</option>');
                    }
                }

                $tr.find('.row-rate').trigger('input');
            });

            if (typeof opts.recalc === 'function') opts.recalc();
            if (window.ZoloGstEntry && typeof window.ZoloGstEntry.refresh === 'function') {
                window.ZoloGstEntry.refresh();
            }
        };

        window.applyQuickModalDynamicGridSpans = applyQuickModalDynamicGridSpans;
        window.syncQuickSections = syncQuickSections;
        window.zoloResetQuickModalMode = resetQuickModalMode;
    });
})(window.jQuery);
