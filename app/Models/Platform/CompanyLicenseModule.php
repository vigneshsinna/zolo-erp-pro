<?php

namespace App\Models\Platform;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLicenseModule extends Model
{
    protected $table = 'company_license_modules';

    protected $fillable = [
        'company_id',
        'capability_key',
        'state',
        'expires_at',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'expires_at' => 'date:Y-m-d',
        'updated_by' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
