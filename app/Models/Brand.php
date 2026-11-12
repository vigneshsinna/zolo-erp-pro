<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;

class Brand extends Model
{
    use ScopesCompanyQueries;

    protected $fillable =[
        "company_id", "title", "image", "page_title", "short_description", "slug", "is_active"
    ];

    public function product()
    {
    	return $this->hasMany('App\Models\Product');
    }
}
