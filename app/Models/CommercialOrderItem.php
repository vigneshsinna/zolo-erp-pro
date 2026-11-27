<?php

namespace App\Models;

use App\Models\Concerns\ScopesCompanyQueries;
use Illuminate\Database\Eloquent\Model;

class CommercialOrderItem extends Model
{
    use ScopesCompanyQueries;
    protected $guarded = ['id'];
    protected $casts = ['qty' => 'float', 'qty_base' => 'float', 'cancelled_qty_base' => 'float', 'unit_rate' => 'float', 'optical_spec_json' => 'array'];
    public function product() { return $this->belongsTo(Product::class); }
    public function unit() { return $this->belongsTo(Unit::class, 'uom_id'); }
}
