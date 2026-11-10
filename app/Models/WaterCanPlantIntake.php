<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaterCanPlantIntake extends Model
{
    use HasFactory;

    protected $table = 'water_can_plant_intakes';

    protected $fillable = [
        'route_id',
        'driver_id',
        'plant_name',
        'load_sequence',
        'cans_loaded',
        'purchase_rate_per_can',
        'slip_number',
        'slip_photo',
        'odometer_km',
        'latitude',
        'longitude',
        'intake_time',
        'notes',
    ];

    protected $casts = [
        'load_sequence' => 'integer',
        'cans_loaded' => 'integer',
        'purchase_rate_per_can' => 'decimal:2',
        'odometer_km' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'intake_time' => 'datetime',
    ];

    public function route()
    {
        return $this->belongsTo(WaterCanRoute::class, 'route_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
