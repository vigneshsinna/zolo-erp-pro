<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ScopesCompanyQueries;
use App\Services\Platform\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
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
            ->where(fn ($q) => $q->whereNull('supplier_id')->orWhere('supplier_id', 0)
                ->orWhereHas('supplier', fn ($q) => $q->forCompany($context)));
    }

    protected $fillable =[
        "reference_no", "supplier_invoice_no", "lot_number", "supplier_invoice_date", "user_id", "warehouse_id", "supplier_id", "currency_id", "exchange_rate", "item", "total_qty", "total_discount", "total_tax", "total_cost", "order_tax_rate", "order_tax", "order_discount", "shipping_cost", "grand_total","paid_amount", "status", "payment_status", "document", "note", "purchase_type", "purchase_type_id", "agent_id", "update_item_cost", "update_item_hsn", "is_reverse_charge", "created_at", "deleted_by",
    ];

    public function purchaseType()
    {
        return $this->belongsTo(PurchaseType::class, 'purchase_type_id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** Legacy list controls use 1 = due and 2 = paid; shared posting uses different status codes. */
    public function scopeWithLegacyPaymentStatus(Builder $query, int $status): Builder
    {
        if (!in_array($status, [1, 2], true)) {
            return $query->where('purchases.payment_status', $status);
        }

        return $query->whereColumn('purchases.paid_amount', $status === 1 ? '<' : '>=', 'purchases.grand_total');
    }
    
    public function user()
    {
    	return $this->belongsTo('App\Models\User');
    }

    public function supplier()
    {
    	return $this->belongsTo('App\Models\Supplier');
    }

    public function warehouse()
    {
    	return $this->belongsTo('App\Models\Warehouse');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function returns()
    {
        return $this->hasMany(ReturnPurchase::class,'purchase_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class,'product_purchases')->withPivot('qty','tax','tax_rate','discount','total');
    }

    public function productPurchases(): HasMany
    {
        return $this->hasMany(ProductPurchase::class, 'purchase_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'purchase_id');
    }

    public function getCreatedAtFormattedAttribute()
    {
        $dateFormat = GeneralSetting::first()->date_format;
        return Carbon::parse($this->attributes['created_at'])->format($dateFormat);
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
