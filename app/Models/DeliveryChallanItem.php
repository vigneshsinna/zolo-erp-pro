<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryChallanItem extends Model
{
    protected $table = 'delivery_challan_items';

    protected $guarded = ['id'];
    protected $fillable = ['company_id', 'delivery_challan_id', 'product_id', 'unit_id', 'variant_id',
        'qty', 'rate', 'amount', 'tax_rate', 'tax_amount', 'total', 'remarks', 'batch_no', 'qty_base',
        'value', 'billed_qty_base', 'returned_qty_base', 'optical_spec_json'];

    protected $casts = [
        'variant_id' => 'integer',
        'qty' => 'float',
        'rate' => 'float',
        'amount' => 'float',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'total' => 'float',
        'qty_base' => 'float',
        'value' => 'float',
        'billed_qty_base' => 'float',
        'returned_qty_base' => 'float',
    ];

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
