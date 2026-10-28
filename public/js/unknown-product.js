/* Unknown item on the Sales and Purchase entry pages.
 * When a typed item name matches no product, ask whether to add it, then open this page's Create item form with that
 * name filled in. The bill stays open; the new product fills the row it was typed on (or a new row from the search bar). */
(function ($) {
    'use strict';

    // Returns true when the user chose to add the product.
    window.zoloUnknownProduct = function (name, $row) {
        name = String(name || '').trim();
        var $modal = $('#quick-create-item-modal');
        if (!name || !$modal.length) return false;
        if (!window.confirm('"' + name + '" is not in your products.\n\nAdd it as a new product now?')) return false;
        $('#btn-create-item-modal').trigger('click'); // resets the form, generates a code and opens it
        $modal.data('replace-row', $row && $row.length ? $row : null);
        $('#quick-item-name').val(name).trigger('input');
        $modal.one('shown.bs.modal', function () { $('#quick-item-name').trigger('focus'); });
        return true;
    };

    // The row a new product should fill, once; cleared when the form closes without saving.
    window.zoloUnknownProductRow = function () {
        var $modal = $('#quick-create-item-modal'), $row = $modal.data('replace-row');
        $modal.removeData('replace-row');
        return $row && $row.closest('body').length ? $row : null;
    };
    $(document).on('hidden.bs.modal', '#quick-create-item-modal', function () { $(this).removeData('replace-row'); });
})(jQuery);
