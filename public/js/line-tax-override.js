/* Line GST/tax rate on the Sales and Purchase entry pages.
 * A line starts at its product's rate. The user may pick another rate; the row then carries data-tax-override, the save and
 * GST preview send it as tax_rate_override, and the row shows a warning while it differs from the product's rate. */
(function ($) {
    'use strict';
    var BODY = '#order-table-body';

    function productRate($row) {
        var value = $row.attr('data-product-tax-rate');
        return value === undefined || value === '' ? null : Number(value);
    }
    function override($row) {
        var value = $row.attr('data-tax-override');
        return value === undefined || value === '' ? undefined : Number(value);
    }
    function label(rate) { return Number(rate) + '%'; }

    // GST companies change a line only to one of their own slabs (the server refuses any other rate).
    function ensureOptions($select) {
        var slabs = (window.ZoloGstEntry && window.ZoloGstEntry.slabs) || [];
        slabs.forEach(function (slab) {
            if (!$select.find('option').filter(function () { return Number(this.value) === Number(slab); }).length) {
                $select.append(new Option(label(slab), slab));
            }
        });
    }

    function summary() {
        var count = $(BODY + ' .row-tax-warning').length;
        var $note = $('#tax-override-note');
        if (!$note.length) $note = $('<div id="tax-override-note" class="tax-override-note" role="status" hidden></div>').insertAfter('#display-tax-amount');
        $note.prop('hidden', !count).text(count ? count + (count === 1 ? ' line uses' : ' lines use') + ' a GST rate different from its product.' : '');
    }

    function mark($row) {
        var chosen = override($row), product = productRate($row);
        var $select = $row.find('.row-tax-rate');
        var differs = chosen !== undefined && product !== null && chosen !== product;
        $row.find('.row-tax-warning').remove();
        $select.toggleClass('is-tax-overridden', differs);
        if (differs) {
            $('<span class="row-tax-warning" role="note"></span>').text('Product ' + label(product))
                .attr('title', 'This product\'s GST is set to ' + label(product) + '. This line uses ' + label(chosen) + '. Edit the product to change it for every bill.')
                .insertAfter($select);
        }
        summary();
    }

    // Before the first manual change, remember the rate the product gave the row.
    $(document).on('focusin mousedown', BODY + ' .row-tax-rate', function () {
        var $row = $(this).closest('tr');
        if (productRate($row) === null) $row.attr('data-product-tax-rate', Number($(this).val()) || 0);
        ensureOptions($(this));
    });
    // Only a user's pick counts (programmatic .val()/.trigger('change') has no originalEvent).
    $(document).on('change', BODY + ' .row-tax-rate', function (event) {
        if (!event.originalEvent) return;
        var $row = $(this).closest('tr'), rate = Number($(this).val()) || 0;
        if (rate === productRate($row)) $row.removeAttr('data-tax-override');
        else $row.attr('data-tax-override', rate);
        mark($row);
    });
    // Typing another item name starts the line over from the new product's rate.
    $(document).on('input', BODY + ' .row-item-name', function () {
        var $row = $(this).closest('tr');
        $row.removeAttr('data-tax-override data-product-tax-rate');
        mark($row);
    });
    // A saved bill opened for editing keeps the rate each line was posted with; the next preview reports the product's rate.
    $(document).on('command-center-loaded', function () {
        setTimeout(function () {
            $(BODY + ' tr.order-item-row').each(function () {
                var $row = $(this);
                $row.removeAttr('data-product-tax-rate').attr('data-tax-override', Number($row.find('.row-tax-rate').val()) || 0);
            });
            if (window.ZoloGstEntry && window.ZoloGstEntry.refresh) window.ZoloGstEntry.refresh();
        });
    });
    if (window.MutationObserver && document.querySelector(BODY)) {
        new MutationObserver(summary).observe(document.querySelector(BODY), { childList: true });
    }

    window.zoloLineTax = {
        override: function (row) { return override($(row)); },
        // The GST preview reports the product's own rate for each line.
        productRate: function (row, rate) { var $row = $(row); $row.attr('data-product-tax-rate', Number(rate) || 0); mark($row); },
        // A line restored from a saved bill or draft keeps the rate it was saved with.
        restore: function (row, rate, product) {
            var $row = $(row);
            if (product !== undefined && product !== null) $row.attr('data-product-tax-rate', Number(product));
            if (rate !== undefined && rate !== null && Number(rate) !== productRate($row)) $row.attr('data-tax-override', Number(rate));
            mark($row);
        }
    };
})(jQuery);
