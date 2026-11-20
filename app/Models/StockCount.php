<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    use \App\Models\Concerns\ScopesCompanyQueries;

    protected $table = 'stock_counts';
    protected $fillable =[
        "reference_no", "warehouse_id", "brand_id", "category_id", "user_id", "type", "initial_file", "final_file", "note", "is_adjusted", "company_id"
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
