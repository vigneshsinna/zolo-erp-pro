<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;

class Unit extends Model
{
    use ScopesCompanyQueries;

    protected $fillable = [
        "company_id", "unit_code", "unit_name", "base_unit", "operator", "operation_value", "is_active"
    ];

    public function product()
    {
    	return $this->hasMany('App\Models\Product');
    }

    public function baseUnit()
    {
        return $this->belongsTo(Unit::class, 'base_unit');
    }
}
