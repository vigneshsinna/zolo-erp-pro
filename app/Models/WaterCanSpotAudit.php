<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaterCanSpotAudit extends Model
{
    protected $table = 'water_can_spot_audits';

    protected $fillable = [
        'audit_id',
        'company_id',
        'branch_id',
        'auditor_id',
        'vehicle_number',
        'driver_name',
        'audit_date',
        'expected_units',
        'physical_units',
        'unit_difference',
        'expected_cash',
        'physical_cash',
        'cash_difference',
        'is_fraud_suspected',
        'status',
        'notes',
    ];

    protected $casts = [
        'expected_units' => 'integer',
        'physical_units' => 'integer',
        'unit_difference' => 'integer',
        'expected_cash' => 'decimal:2',
        'physical_cash' => 'decimal:2',
        'cash_difference' => 'decimal:2',
        'is_fraud_suspected' => 'boolean',
        'audit_date' => 'date',
    ];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}

