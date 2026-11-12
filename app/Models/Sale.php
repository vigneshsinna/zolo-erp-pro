<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;
use App\Services\Platform\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $casts = ['document_snapshot_json' => 'array', 'tax_snapshot_json' => 'array', 'attributes_json' => 'array', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    use ScopesCompanyQueries;
    use \App\Models\Concerns\ProtectsPostedCommercialHistory;

    use SoftDeletes;
    
    /** Only documents whose owning warehouse and parties belong to this context are readable. */
    public function scopeVisibleIn(Builder $query, CompanyContext $context): Builder
    {
        return $query->forCompany($context)
            ->whereHas('warehouse', fn ($q) => $q->forCompany($context)->where('branch_id', $context->branchId))
            ->whereHas('customer', fn ($q) => $q->forCompany($context))
            ->whereHas('biller', fn ($q) => $q->forCompany($context));
    }

    protected $fillable =[
        "reference_no", "lot_number", "user_id", "cash_register_id", "table_id", "queue", "customer_id", "warehouse_id", "biller_id", "item", "total_qty", "total_discount", "total_tax", "total_price", "order_tax_rate", "order_tax", "order_discount_type", "order_discount_value", "order_discount", "coupon_id", "coupon_discount", "shipping_cost", "grand_total", "currency_id", "exchange_rate", "sale_status", "payment_status", "billing_name", "billing_phone", "billing_email", "billing_address", "billing_city", "billing_state", "billing_country", "billing_zip", "shipping_name", "shipping_phone", "shipping_email", "shipping_address", "shipping_city", "shipping_state","shipping_country","shipping_zip", "sale_type", "sale_type_id", "agent_id", "service_id", "waiter_id", "paid_amount", "document", "sale_note", "staff_note", "created_at", "woocommerce_order_id", "deleted_by",
        "end_user_name", "end_user_phone", "end_user_email", "end_user_address", "fitting_charge", "tinting_cost", "customer_order_no", "order_no"
    ];

    public function saleType()
    {
        return $this->belongsTo(SaleType::class, 'sale_type_id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
    
    public function user()
    {
    	return $this->belongsTo('App\Models\User');
    }

    public function products()
    {
        return $this->belongsToMany('App\Models\Product', 'product_sales');
    }

    public function productSales(): HasMany
    {
        return $this->hasMany(Product_Sale::class, 'sale_id');
    }

    public function biller()
    {
        return $this->belongsTo('App\Models\Biller');
    }

    public function customer()
    {
        return $this->belongsTo('App\Models\Customer');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function table()
    {
        return $this->belongsTo('App\Models\Table');
    }

    public function currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

    public function saleWarrantyGuarantees(): HasMany
    {
        return $this->hasMany(SaleWarrantyGuarantee::class);
    }

    public function delivery()
    {
        return $this->hasOne(Delivery::class);
    }

    public function return()
    {
        return $this->hasOne(Returns::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
    
    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by')->withDefault([
            'name' => 'System/Unknown'
        ]);
    }

    public function installmentPlan()
    {
        return $this->morphOne(InstallmentPlan::class, 'reference');
    }
}
