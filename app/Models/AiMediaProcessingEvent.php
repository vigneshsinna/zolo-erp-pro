<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit event for async media task stage transitions.
 */
class AiMediaProcessingEvent extends Model
{
    use HasUuids;

    protected $table = 'ai_media_processing_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'task_id',
        'company_id',
        'state_version',
        'stage',
        'event_type',
        'from_state',
        'to_state',
        'error_code',
        'meta_json',
        'occurred_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'state_version' => 'integer',
            'meta_json' => 'array',
            'occurred_at_utc' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AiMediaProcessingTask::class, 'task_id', 'id');
    }
}
