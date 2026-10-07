<?php

namespace App\Services\Industry;

/** Presets configure shared workflows; no industry-specific transaction master exists. */
final class IndustryCatalog
{
    public const SETTINGS = [
        'general_trading' => ['quantity_scale' => 4, 'units' => ['PCS'], 'labels' => [], 'reports' => []],
        'fmcg' => ['quantity_scale' => 4, 'units' => ['PCS', 'BOX', 'CASE'], 'picking' => 'fefo', 'expired_batch' => 'block',
            'master_defaults' => ['barcode', 'brand', 'hsn_code', 'gst', 'mrp', 'shelf_life_days', 'reorder_level'],
            'labels' => [], 'reports' => ['batch_expiry', 'reorder', 'route_distribution']],
        'textile' => ['quantity_scale' => 3, 'units' => ['MTR'], 'uqc' => 'MTR',
            'labels' => ['dispatch' => 'Material DC', 'receipt' => 'Job Work GRN', 'warehouse' => 'Godown'],
            'document_fields' => ['transporter', 'lr_no', 'lr_date', 'bale_count', 'bundle_count'],
            'print' => ['lines' => 68, 'columns' => 80], 'entry_aids' => ['previous_rates' => true, 'outstanding' => true, 'clone_invoice' => true, 'inline_party' => true, 'inline_item' => true, 'tracking' => true],
            'reports' => ['job_work_pending', 'job_work_shrinkage', 'stock_lot', 'previous_rates']],
        'timber' => ['quantity_scale' => 4, 'units' => ['PCS', 'CFT', 'CBM'], 'dimension_unit' => 'ft',
            'formula_version' => 'rectangular-v1', 'labels' => [], 'reports' => ['piece_volume', 'conversion_yield']],
        'solar' => ['quantity_scale' => 4, 'units' => ['PCS', 'KW'], 'labels' => ['project' => 'Site / Project'],
            'document_fields' => ['project_id', 'site_address'], 'reports' => ['project_margin', 'installed_serials', 'warranty_due']],
    ];

    public const ATTRIBUTES = [
        'general_trading' => [],
        'fmcg' => ['mrp' => ['MRP', 'number'], 'shelf_life_days' => ['Shelf life (days)', 'integer'], 'reorder_level' => ['Reorder level', 'number']],
        'textile' => ['fabric_type' => ['Fabric type', 'text'], 'construction' => ['Construction', 'text'], 'width' => ['Width', 'number'],
            'gsm' => ['GSM', 'number'], 'design' => ['Design', 'text'], 'color' => ['Color', 'text'], 'brand' => ['Brand', 'text'],
            'roll_lot' => ['Roll / lot', 'text'], 'rack_godown' => ['Rack / godown', 'text']],
        'timber' => ['species' => ['Species', 'text'], 'grade' => ['Grade', 'text'], 'moisture_class' => ['Moisture class', 'text'],
            'standard_dimensions' => ['Standard dimensions', 'text'], 'source' => ['Source', 'text']],
        'solar' => ['wattage' => ['Wattage', 'number'], 'voltage' => ['Voltage', 'number'], 'phase' => ['Phase', 'text'],
            'panel_type' => ['Panel type', 'text'], 'inverter_type' => ['Inverter type', 'text'], 'warranty_months' => ['Warranty (months)', 'integer']],
    ];

    // Process thresholds are explicit presets. Users configure their actual process before dispatch.
    public const PROCESSES = [
        'textile' => [['DYEING', 'Dyeing', 4, false], ['PRINTING', 'Printing', 4, false], ['FINISHING', 'Finishing', 4, false]],
        'timber' => [['SAWING', 'External sawing', 30, true]],
        'fmcg' => [['REPACKING', 'Outsourced repacking', 5, true]],
    ];

    public const SUBTYPES = [
        'general_trading' => ['trading' => []],
        'fmcg' => ['distribution' => [], 'manufacturer' => ['manufacturing.bom', 'manufacturing.production'], 'route_distribution' => ['sales.route_distribution']],
        'textile' => ['wholesale' => [], 'in_house_processing' => ['manufacturing.bom', 'manufacturing.production']],
        'timber' => ['trading' => [], 'processor' => ['manufacturing.bom', 'manufacturing.production', 'operations.job_work']],
        'solar' => ['epc' => []],
    ];
}
