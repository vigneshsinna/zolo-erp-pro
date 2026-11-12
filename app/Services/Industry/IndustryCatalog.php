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
        'textile' => ['quantity_scale' => 3, 'units' => ['MTR', 'KGS', 'DOZ', 'PCS'], 'uqc' => 'MTR',
            'labels' => ['warehouse' => 'Godown'],
            'document_fields' => ['transporter', 'lr_no', 'lr_date', 'bale_count', 'bundle_count'],
            'print' => ['lines' => 68, 'columns' => 80], 'entry_aids' => ['previous_rates' => true, 'outstanding' => true, 'clone_invoice' => true, 'inline_party' => true, 'inline_item' => true, 'tracking' => true],
            'reports' => ['stock_lot', 'previous_rates']],
        'timber' => ['quantity_scale' => 4, 'units' => ['PCS', 'CFT', 'CBM'], 'dimension_unit' => 'ft',
            'formula_version' => 'rectangular-v1', 'labels' => [], 'reports' => ['piece_volume', 'conversion_yield']],
        'solar' => ['quantity_scale' => 4, 'units' => ['PCS', 'KW'], 'labels' => ['project' => 'Site / Project'],
            'document_fields' => ['project_id', 'site_address'], 'reports' => ['project_margin', 'installed_serials', 'warranty_due']],
        'opticals' => ['quantity_scale' => 2, 'units' => ['PRS', 'PCS', 'BOX'], 'uqc' => 'PRS',
            'labels' => ['warehouse' => 'Lab / Branch', 'dispatch' => 'Lab Dispatch', 'receipt' => 'Lab Receipt'],
            'document_fields' => ['doctor_name', 'patient_name', 'fitting_charges', 'tinting_charges', 'coating_charges', 'transporter', 'lr_no'],
            'entry_aids' => ['previous_rates' => true, 'outstanding' => true, 'clone_invoice' => true, 'inline_party' => true, 'inline_item' => true, 'tracking' => true],
            'reports' => ['prescription_sales', 'lab_pending_jobs', 'warranty_tracker']],
    ];

    /** Preset unit code => [name, other spellings that already count as this unit]. */
    public const UNITS = [
        'PCS' => ['Pieces', ['pc', 'piece', 'nos', 'no', 'unit']], 'BOX' => ['Box', ['bx']], 'CASE' => ['Case', ['cs']],
        'PRS' => ['Pairs', ['pair', 'prs', 'pr']],
        'KGS' => ['Kilogram', ['kg', 'kilograms', 'kgs']], 'DOZ' => ['Dozen', ['dz', 'dozens']],
        'MTR' => ['Meter', ['m', 'mt', 'metre', 'meters', 'metres']], 'CFT' => ['Cubic feet', ['cu ft']],
        'CBM' => ['Cubic meter', ['m3', 'cubic metre']], 'KW' => ['Kilowatt', ['kilowatts']],
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
        'opticals' => [
            'lens_material' => ['Lens material', 'text'],
            'lens_type' => ['Lens type', 'text'],
            'coating' => ['Coating / Treatment', 'text'],
            'refractive_index' => ['Refractive index', 'text'],
            'dia' => ['Diameter (mm)', 'number'],
            'base_curve' => ['Base curve', 'number'],
        ],
    ];

    // Process thresholds are explicit presets. Users configure their actual process before dispatch.
    public const PROCESSES = [
        // Grey → dyed fabric is a conversion (a new item comes back); the others return the same material.
        'textile' => [['BLEACHING', 'Bleaching', 0, false], ['GREY-DYE', 'Dyeing - grey to dyed fabric', 5, true], ['DYEING', 'Dyeing', 4, false], ['PRINTING', 'Printing', 4, false], ['FINISHING', 'Finishing', 4, false]],
        'timber' => [['SAWING', 'External sawing', 30, true]],
        'fmcg' => [['REPACKING', 'Outsourced repacking', 5, true]],
        'opticals' => [
            ['SURFACING', 'Lens Surfacing / Power Grinding', 2, false],
            ['COATING', 'Hard & Anti-Glare Coating', 1, false],
            ['TINTING', 'Lens Tinting & Coloring', 1, false],
            ['FITTING', 'Edging & Frame Fitting', 2, false],
        ],
    ];

    /**
     * Capabilities a business type adds on top of its profile preset. Applying a profile resets to the preset first,
     * so switching type also hides what the previous type added. The first type is the default.
     */
    public const SUBTYPES = [
        'general_trading' => ['trading' => []],
        'fmcg' => ['distribution' => [], 'manufacturer' => ['manufacturing.bom', 'manufacturing.production'], 'route_distribution' => ['sales.route_distribution']],
        // Traders buy finished cloth and sell it on; only manufacturing sends material out for processing or makes goods.
        'textile' => ['wholesale_distributor' => ['sales.route_distribution'], 'wholesale' => [], 'distributor' => ['sales.route_distribution'],
            'manufacturing' => ['operations.job_work', 'manufacturing.bom', 'manufacturing.production']],
        'timber' => ['trading' => [], 'processor' => ['manufacturing.bom', 'manufacturing.production', 'operations.job_work']],
        'solar' => ['epc' => []],
        'opticals' => [
            'lens_manufacturing' => ['manufacturing.bom', 'manufacturing.production', 'operations.job_work', 'service.warranty_amc'],
            'optical_retail' => ['sales.pos', 'service.warranty_amc'],
            'wholesale_distributor' => ['sales.wholesale'],
        ],
    ];

    public const SUBTYPE_LABELS = [
        'wholesale_distributor' => 'Wholesale & Distributor',
        'lens_manufacturing' => 'Lens Manufacturer / Rx Lab',
        'optical_retail' => 'Optical Retailer / Eyewear Store',
    ];

    /** Business type settings replace the profile's top-level setting of the same key. */
    public const SUBTYPE_SETTINGS = [
        'textile' => ['manufacturing' => [
            'labels' => ['dispatch' => 'Material DC', 'receipt' => 'Job Work GRN', 'warehouse' => 'Godown'],
            'reports' => ['job_work_pending', 'job_work_shrinkage', 'stock_lot', 'previous_rates'],
        ]],
        'opticals' => ['lens_manufacturing' => [
            'labels' => ['warehouse' => 'Lab / Factory', 'dispatch' => 'Lab Job Dispatch', 'receipt' => 'Lab Receipt'],
            'reports' => ['lab_jobs', 'prescription_sales', 'job_work_pending', 'warranty_tracker'],
        ]],
    ];

    /** Profile attributes a business type does not use; saved values are kept. Brand distributors do not deal in mill specs. */
    public const SUBTYPE_HIDDEN_ATTRIBUTES = [
        'textile' => ['distributor' => ['construction', 'gsm']],
    ];

    public static function subtypeLabel(?string $subtype): string
    {
        return self::SUBTYPE_LABELS[$subtype] ?? ucwords(str_replace('_', ' ', (string) $subtype));
    }

    public static function settings(string $profile, ?string $subtype): array
    {
        return array_replace(self::SETTINGS[$profile], self::SUBTYPE_SETTINGS[$profile][$subtype] ?? []);
    }
}
