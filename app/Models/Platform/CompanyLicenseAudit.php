<?php

namespace App\Models\Platform;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLicenseAudit extends Model
{
    protected $table = 'company_license_audits';

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'saas_plan_id',
        'actor_id',
        'action',
        'summary',
        'before_json',
        'after_json',
        'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'saas_plan_id' => 'integer',
        'actor_id' => 'integer',
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
