<?php

namespace App\Services\Platform;

/** Shared capability definitions and profile presets; profiles never select a code fork. */
final class CapabilityCatalog
{
    // Remove this gate only after the complete company-boundary acceptance is reviewed.
    public const OPTIONAL_ACTIVATION_READY = false;

    public const DEFINITIONS = [
        'core.sales' => ['Sales', [], true],
        'core.purchases' => ['Purchases', [], true],
        'core.inventory' => ['Inventory', [], true],
        'core.accounting' => ['Accounting', [], true],
        'core.gst' => ['GST', ['core.accounting'], true],
        'inventory.multi_uom' => ['Multiple units', ['core.inventory']],
        'inventory.batch_expiry' => ['Batch and expiry', ['core.inventory']],
        'inventory.serial_tracking' => ['Serial tracking', ['core.inventory']],
        'inventory.dimension_tracking' => ['Dimension tracking', ['core.inventory']],
        'inventory.lot_tracking' => ['Lot tracking', ['core.inventory']],
        'sales.wholesale' => ['Wholesale sales', ['core.sales']],
        'sales.route_distribution' => ['Route distribution', ['core.sales', 'core.inventory']],
        'sales.installment_plans' => ['Installment plans', ['core.sales', 'core.accounting']],
        'sales.exchange' => ['Exchange', ['core.sales', 'core.inventory']],
        'sales.ecommerce' => ['Ecommerce', ['core.sales']],
        'sales.woocommerce' => ['WooCommerce', ['sales.ecommerce']],
        'sales.catalogue_qr' => ['QR catalogue', ['core.sales']],
        'inventory.damage_stock' => ['Damage stock', ['core.inventory']],
        'manufacturing.bom' => ['BOM and system kits', ['core.inventory']],
        'manufacturing.production' => ['Production', ['manufacturing.bom', 'core.accounting']],
        'operations.job_work' => ['Job work', ['core.inventory', 'core.purchases']],
        'operations.projects' => ['Projects', ['core.sales']],
        'operations.installation' => ['Installation', ['operations.projects', 'inventory.serial_tracking']],
        'operations.water_logistics' => ['Water logistics', ['sales.route_distribution']],
        'operations.cafe_bakery' => ['Cafe and bakery', ['core.sales', 'core.inventory']],
        'operations.restaurant' => ['Restaurant', ['core.sales']],
        'service.repair' => ['Repair', ['inventory.serial_tracking']],
        'service.warranty_amc' => ['Warranty and AMC', ['inventory.serial_tracking']],
        'printing.dot_matrix' => ['Dot matrix printing', ['core.sales']],
        'communications.whatsapp' => ['WhatsApp', ['core.sales']],
        'integrations.api' => ['API integration', []],
        'hrm.enabled' => ['HRM', []],
        'sales.pos' => ['POS terminal', ['core.sales']],
        'sales.gift_cards' => ['Gift cards', ['core.sales']],
        'sales.coupons' => ['Coupons', ['core.sales']],
        'operations.scheduling' => ['Scheduling', []],
        'inventory.lot_report' => ['Stock by lot report', ['core.inventory']],
    ];

    /**
     * Features every company already had, made switchable. With no explicit company choice they stay on, and the
     * optional-activation gate does not apply to them (it guards new modules, not turning old ones back on).
     */
    public const SWITCHABLE = ['hrm.enabled', 'sales.pos', 'sales.gift_cards', 'sales.coupons', 'operations.scheduling', 'inventory.lot_report'];

    public const PROFILES = [
        'general_trading' => ['General Trading', []],
        'fmcg' => ['FMCG', ['inventory.multi_uom', 'inventory.batch_expiry', 'inventory.damage_stock', 'sales.wholesale', 'communications.whatsapp', 'sales.pos', 'inventory.lot_report']],
        'textile' => ['Textile', ['inventory.multi_uom', 'sales.wholesale', 'printing.dot_matrix', 'communications.whatsapp', 'inventory.lot_report']],
        'timber' => ['Timber', ['inventory.multi_uom', 'inventory.dimension_tracking', 'inventory.lot_tracking', 'sales.wholesale', 'inventory.lot_report']],
        'solar' => ['Solar', ['operations.projects', 'inventory.serial_tracking', 'operations.installation', 'manufacturing.bom', 'service.warranty_amc', 'communications.whatsapp']],
    ];

    public const CONFIGURATION = [
        'inventory.batch_expiry' => ['warning_days' => 'integer|min:0|max:3650'],
        'inventory.dimension_tracking' => ['unit' => 'string|in:mm,cm,m,in,ft'],
        'printing.dot_matrix' => ['lines' => 'integer|min:1|max:200', 'columns' => 'integer|min:1|max:200'],
    ];
}
