<?php

namespace App\Models\Platform;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyLicense extends Model
{
    protected $table = 'company_licenses';

    protected $fillable = [
        'company_id',
        'saas_plan_id',
        'license_key',
        'status',
        'billing_cycle',
        'starts_at',
        'expires_at',
        'grace_ends_at',
        'suspended_at',
        'suspension_reason',
        'max_users',
        'max_branches',
        'max_warehouses',
        'max_monthly_invoices',
        'ai_monthly_credits',
        'ai_monthly_media_pages',
        'ai_max_concurrent_runs',
        'ai_daily_token_limit',
        'ai_read_only',
        'notes',
        'managed_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'saas_plan_id' => 'integer',
        'starts_at' => 'date:Y-m-d',
        'expires_at' => 'date:Y-m-d',
        'grace_ends_at' => 'date:Y-m-d',
        'suspended_at' => 'datetime',
        'max_users' => 'integer',
        'max_branches' => 'integer',
        'max_warehouses' => 'integer',
        'max_monthly_invoices' => 'integer',
        'ai_monthly_credits' => 'integer',
        'ai_monthly_media_pages' => 'integer',
        'ai_max_concurrent_runs' => 'integer',
        'ai_daily_token_limit' => 'integer',
        'ai_read_only' => 'boolean',
        'managed_by' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function moduleOverrides(): HasMany
    {
        return $this->hasMany(CompanyLicenseModule::class, 'company_id', 'company_id');
    }
}
