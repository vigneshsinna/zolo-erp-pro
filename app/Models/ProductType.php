<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductType extends Model
{
    protected $table = 'product_types';

    protected $fillable = [
        'company_id',
        'name',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope for active product types.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to current tenant company context (or system defaults where company_id is null).
     */
    public function scopeForCompany($query, ?int $companyId = null)
    {
        if ($companyId === null) {
            return $query;
        }

        return $query->where(function ($q) use ($companyId) {
            $q->where('company_id', $companyId)
              ->orWhereNull('company_id');
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProductType::class, 'parent_id');
    }
}

