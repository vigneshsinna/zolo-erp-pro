<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable per-task, per-page, per-attempt provider dispatch record for idempotency & billing reconciliation.
 */
class AiMediaProviderAttempt extends Model
{
    use HasUuids;

    protected $table = 'ai_media_provider_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'task_id',
        'company_id',
        'media_id',
        'page_number',
        'attempt_number',
        'provider',
        'model_key',
        'provider_attempt_id',
        'reservation_id',
        'provider_request_id',
        'status',
        'error_code',
        'http_status',
        'input_tokens',
        'output_tokens',
        'duration_ms',
        'sent_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'page_number' => 'integer',
            'attempt_number' => 'integer',
            'http_status' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AiMediaProcessingTask::class, 'task_id', 'id');
    }
}
