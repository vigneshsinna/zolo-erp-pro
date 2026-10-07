<?php

return [
    // Ctrl+F12 opens a new Purchase Order (status 4). Enable only after the order lifecycle checks pass.
    'purchase_order_shortcut' => (bool) env('DOCUMENTS_PURCHASE_ORDER_SHORTCUT', false),
];
