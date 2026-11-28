<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Durable, company- and actor-scoped async media preparation task record.
 */
class AiMediaProcessingTask extends Model
{
    use HasUuids;

    protected $table = 'ai_media_processing_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'financial_year_id',
        'actor_id',
        'thread_id',
        'workflow_task_id',
        'expected_task_revision',
        'media_id',
        'extraction_id',
        'extraction_revision',
        'proposal_id',
        'proposal_version',
        'client_request_id',
        'request_hash',
        'requested_action_key',
        'resolved_action_key',
        'companion_text_encrypted',
        'resolution_mode',
        'force_reextract',
        'is_optical_profile',
        'source_kind',
        'state',
        'current_stage',
        'state_version',
        'stages_json',
        'page_checkpoints_json',
        'result_ref_json',
        'model_provider',
        'model_key',
        'lease_owner',
        'lease_token',
        'lease_expires_at',
        'heartbeat_at',
        'dispatched_at',
        'attempt_count',
        'rate_limit_deferrals',
        'next_retry_at',
        'deadline_at',
        'cancel_requested_at',
        'started_at',
        'completed_at',
        'error_code',
        'error_message',
        'failed_stage',
        'is_retryable',
        'correlation_id',
        'preparation_idempotency_key',
    ];

    protected $hidden = [
        'companion_text_encrypted',
        'lease_token',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'branch_id' => 'integer',
            'financial_year_id' => 'integer',
            'actor_id' => 'integer',
            'expected_task_revision' => 'integer',
            'extraction_revision' => 'integer',
            'proposal_version' => 'integer',
            'force_reextract' => 'boolean',
            'is_optical_profile' => 'boolean',
            'state_version' => 'integer',
            'stages_json' => 'array',
            'page_checkpoints_json' => 'array',
            'result_ref_json' => 'array',
            'attempt_count' => 'integer',
            'rate_limit_deferrals' => 'integer',
            'is_retryable' => 'boolean',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'deadline_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(AiMediaAsset::class, 'media_id', 'id');
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(AiMediaExtraction::class, 'extraction_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiMediaProcessingEvent::class, 'task_id', 'id')
            ->orderBy('state_version');
    }

    public function providerAttempts(): HasMany
    {
        return $this->hasMany(AiMediaProviderAttempt::class, 'task_id', 'id')
            ->orderBy('page_number')
            ->orderBy('attempt_number');
    }

    public static function encryptCompanionText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return Crypt::encryptString($text);
    }

    public function getCompanionText(): ?string
    {
        if (! is_string($this->companion_text_encrypted) || $this->companion_text_encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($this->companion_text_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [
            'review_ready',
            'needs_input',
            'needs_confirmation',
            'failed',
            'cancelled',
            'expired',
        ], true);
    }

    public function isAwaitingHuman(): bool
    {
        return in_array($this->state, ['review_ready', 'needs_input', 'needs_confirmation'], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this->state, ['queued', 'processing', 'retry_scheduled', 'cancel_requested'], true);
    }
}
