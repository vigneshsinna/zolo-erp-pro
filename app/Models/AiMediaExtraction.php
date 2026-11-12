<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable/versioned structured perception extraction derived from an AiMediaAsset.
 */
class AiMediaExtraction extends Model
{
    use HasUuids;

    protected $table = 'ai_media_extractions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'media_id',
        'revision',
        'model_provider',
        'model_key',
        'prompt_version',
        'extraction_schema_version',
        'extracted_json',
        'source_evidence_json',
        'normalized_input_json',
        'confirmation_status',
        'confirmed_by',
        'confirmed_at',
        'idempotency_key',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'revision' => 'integer',
            'extracted_json' => 'array',
            'source_evidence_json' => 'array',
            'normalized_input_json' => 'array',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'datetime',
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

    public function isConfirmed(): bool
    {
        return $this->confirmation_status === 'confirmed';
    }
}

