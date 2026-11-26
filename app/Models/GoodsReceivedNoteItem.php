<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceivedNoteItem extends Model
{
    protected $table = 'goods_received_note_items';

    protected $guarded = ['id'];
    protected $fillable = ['company_id', 'goods_received_note_id', 'product_id', 'unit_id', 'variant_id',
        'qty', 'cost', 'amount', 'tax_rate', 'tax_amount', 'total', 'remarks', 'batch_no', 'qty_base',
        'value', 'billed_qty_base', 'returned_qty_base', 'optical_spec_json'];

    protected $casts = [
        'variant_id' => 'integer',
        'qty' => 'float',
        'cost' => 'float',
        'amount' => 'float',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'total' => 'float',
        'qty_base' => 'float',
        'value' => 'float',
        'billed_qty_base' => 'float',
        'returned_qty_base' => 'float',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
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
