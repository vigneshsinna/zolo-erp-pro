<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;

class Category extends Model
{
    use ScopesCompanyQueries;

    protected $fillable =[
        "company_id", "name", "default_hsn_code", 'image', "parent_id", "is_active", "is_sync_disable", "woocommerce_category_id","slug","featured","page_title","short_description"
    ];

    public function product()
    {
    	return $this->hasMany('App\Models\Product');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }
}
