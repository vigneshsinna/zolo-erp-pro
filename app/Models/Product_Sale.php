<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;

class Product_Sale extends Model
{
    protected $casts = ['tax_snapshot_json' => 'array', 'stock_details_json' => 'array', 'prescription_json' => 'array'];
    use ScopesCompanyQueries;
    use \App\Models\Concerns\ProtectsCommercialLineHistory;

	protected $table = 'product_sales';
    protected $fillable =[
        "sale_id", "product_id", "product_batch_id", "variant_id", 'imei_number', "qty", "return_qty", "sale_unit_id", "net_unit_price", "discount", "tax_rate", "tax", "total", "is_packing", "is_delivered","topping_id",
        "sph", "cyl", "axis", "base", "addition", "lr", "dia", "prescription_json",
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
