<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesCompanyQueries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpticalRxJob extends Model
{
    use ScopesCompanyQueries;

    protected $table = 'optical_rx_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'optical_spec_json' => 'array',
        'qty_requested' => 'float',
        'qty_accepted' => 'float',
        'qty_failed' => 'float',
        'qty_completed' => 'float',
        'qty_packed' => 'float',
        'qty_dispatched' => 'float',
        'qty_invoiced' => 'float',
        'promised_date' => 'date:Y-m-d',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
