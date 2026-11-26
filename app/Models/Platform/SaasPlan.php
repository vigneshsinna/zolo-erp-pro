<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    protected $table = 'saas_plans';

    protected $fillable = [
        'code',
        'name',
        'description',
        'billing_cycle',
        'price',
        'currency_code',
        'trial_days',
        'grace_days',
        'max_users',
        'max_branches',
        'max_warehouses',
        'max_monthly_invoices',
        'ai_monthly_credits',
        'ai_monthly_media_pages',
        'ai_max_concurrent_runs',
        'ai_daily_token_limit',
        'ai_read_only',
        'modules_json',
        'is_active',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'trial_days' => 'integer',
        'grace_days' => 'integer',
        'max_users' => 'integer',
        'max_branches' => 'integer',
        'max_warehouses' => 'integer',
        'max_monthly_invoices' => 'integer',
        'ai_monthly_credits' => 'integer',
        'ai_monthly_media_pages' => 'integer',
        'ai_max_concurrent_runs' => 'integer',
        'ai_daily_token_limit' => 'integer',
        'ai_read_only' => 'boolean',
        'modules_json' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function licenses(): HasMany
    {
        return $this->hasMany(CompanyLicense::class, 'saas_plan_id');
    }
}
