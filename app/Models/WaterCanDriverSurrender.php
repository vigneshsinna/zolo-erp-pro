<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaterCanDriverSurrender extends Model
{
    protected $table = 'water_can_driver_surrenders';

    protected $fillable = [
        'surrender_receipt',
        'company_id',
        'branch_id',
        'route_id',
        'driver_id',
        'surrender_date',
        'cash_surrendered',
        'empty_cans_returned',
        'filled_cans_returned',
        'damaged_cans_reported',
        'status',
        'journal_entry_id',
        'notes',
    ];

    protected $casts = [
        'surrender_date' => 'date',
        'cash_surrendered' => 'decimal:2',
        'empty_cans_returned' => 'integer',
        'filled_cans_returned' => 'integer',
        'damaged_cans_reported' => 'integer',
    ];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function route(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WaterCanRoute::class, 'route_id');
    }

    public function driver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}

