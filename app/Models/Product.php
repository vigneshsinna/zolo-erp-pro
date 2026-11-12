<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;
use App\Services\Platform\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Database\SchemaCache;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use ScopesCompanyQueries;

    public const USAGE_RULE = 'sometimes|required|in:purchase,sale,composite';

    public function scopeForBill(Builder $query, string $kind): Builder
    {
        if (app(SchemaCache::class)->hasColumn($query->getConnection(), 'products', 'product_usage')) {
            $query->whereIn('products.product_usage', [$kind, 'composite']);
        }

        return $query;
    }

    public function assertAllowedOnBill(string $kind, string $field = 'items'): void
    {
        if (!in_array($this->product_usage ?? 'composite', [$kind, 'composite'], true)) {
            $bill = $kind === 'sale' ? 'sales' : 'purchase';
            throw ValidationException::withMessages([$field => "Product {$this->code} cannot be used on a {$bill} bill."]);
        }
    }

    protected $fillable =[
        'product_usage',
        "name", "print_name", "code", "type", "slug", "barcode_symbology", "brand_id", "category_id", "unit_id", "purchase_unit_id", "sale_unit_id", "cost", "profit_margin", "profit_margin_type", "price", "wholesale_price", "qty", "alert_quantity", "neg_stock_allowed", "negative_stock_policy", "sales_account_id", "purchase_account_id", "daily_sale_objective", "promotion", "promotion_price", "starting_date", "last_date", "tax_id", "tax_method", "hsn_code", "image", "file", "is_embeded", "is_batch", "is_variant", "is_diffPrice", "is_imei", "featured", "product_list", "variant_list", "qty_list", "price_list", "product_details", "short_description", "specification", "related_products", "is_addon", "extras", "menu_type", "variant_option", "variant_value", "is_active", "is_online", "kitchen_id", "in_stock", "track_inventory", "is_sync_disable", "woocommerce_product_id","woocommerce_media_id","tags","meta_title","meta_description", "warranty", "guarantee", "warranty_type", "guarantee_type","wastage_percent","combo_unit_id","production_cost","is_recipe",
        "base", "addition", "lr", "dia", "product_type_id"
    ];

    public function salesAccount()
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    public function purchaseAccount()
    {
        return $this->belongsTo(Account::class, 'purchase_account_id');
    }

    public function productWarehouse()
    {
        return $this->hasMany(Product_Warehouse::class, 'product_id');
    }

    /** Catalog quantity is the selected branch's visible stock, preserving the legacy stored aggregate. */
    public function scopeCatalogFor(
        Builder $query,
        CompanyContext $context,
        array $columns = ['products.*'],
    ): Builder {
        return $query->forCompany($context)->select($columns)->selectSub(
            Product_Warehouse::visibleIn($context)->selectRaw('COALESCE(SUM(product_warehouse.qty), 0)')
                ->whereColumn('product_warehouse.product_id', 'products.id'),
            'qty',
        )->with([
            'category' => fn ($q) => $q->forCompany($context),
            'brand' => fn ($q) => $q->forCompany($context),
            'unit' => fn ($q) => $q->forCompany($context),
        ]);
    }

    public function category()
    {
    	return $this->belongsTo('App\Models\Category');
    }

    public function productType()
    {
        return $this->belongsTo('App\Models\ProductType', 'product_type_id');
    }

    public function brand()
    {
    	return $this->belongsTo('App\Models\Brand');
    }

    public function tax()
    {
        return $this->belongsTo('App\Models\Tax');
    }

    public function unit()
    {
        return $this->belongsTo('App\Models\Unit');
    }

    public function variant()
    {
        return $this->belongsToMany('App\Models\Variant', 'product_variants')->withPivot('id', 'item_code', 'additional_cost', 'additional_price', 'qty');
    }

    public function scopeActiveStandard($query)
    {
        return $query->where(function($q) {
            $q->where('products.is_active', true)->orWhere('products.is_active', 1)->orWhereNull('products.is_active');
        })->where(function($q) {
            $q->where('products.type', 'standard')->orWhereNull('products.type');
        });
    }

    public function scopeActiveFeatured($query)
    {
        return $query->where([
            ['is_active', true],
            ['featured', 1]
        ]);
    }

    public function products()
    {
        return $this->belongsToMany(Warehouse::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class)->withPivot('qty');
    }

    public function purchases()
    {
        return $this->belongsToMany(Purchase::class,'product_purchases')->withPivot('qty','tax','tax_rate','discount','total');
    }

    public function sales()
    {
        return $this->belongsToMany('App\Models\Sale', 'product_sales')->withPivot('qty','product_batch_id', 'return_qty','net_unit_price','tax','discount','tax_rate','total','is_delivered');
    }

    public function returns()
    {
        return $this->belongsToMany('App\Models\ProductReturn', 'product_returns');
    }

    // product review relation
    public function reviews()
    {
        return $this->hasMany(\Modules\Ecommerce\Entities\ProductReview::class,'product_id');
    }

    public function approvedReviews()
    {
        return $this->hasMany(\Modules\Ecommerce\Entities\ProductReview::class)->where('approved', true);
    }

    protected $casts = [
        'profit_margin_type' => 'string',
    ];

}
