<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Private company-scoped uploaded media asset for AI perception.
 */
class AiMediaAsset extends Model
{
    use HasUuids;

    protected $table = 'ai_media_assets';

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
        'source_kind',
        'original_file_name_sanitized',
        'original_mime',
        'canonical_mime',
        'size_bytes',
        'sha256',
        'private_disk',
        'private_key',
        'derivatives_json',
        'page_count',
        'duration_seconds',
        'idempotency_key',
        'state',
        'last_error_code',
        'expires_at',
        'deleted_at_policy',
    ];

    protected $hidden = [
        'private_disk',
        'private_key',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'branch_id' => 'integer',
            'financial_year_id' => 'integer',
            'actor_id' => 'integer',
            'size_bytes' => 'integer',
            'derivatives_json' => 'array',
            'page_count' => 'integer',
            'duration_seconds' => 'float',
            'expires_at' => 'datetime',
            'deleted_at_policy' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function extractions(): HasMany
    {
        return $this->hasMany(AiMediaExtraction::class, 'media_id', 'id')
            ->orderByDesc('revision');
    }

    public function latestExtraction(): HasOne
    {
        return $this->hasOne(AiMediaExtraction::class, 'media_id', 'id')
            ->ofMany('revision', 'max');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPurged(): bool
    {
        return $this->deleted_at_policy !== null || $this->state === 'purged';
    }
}

