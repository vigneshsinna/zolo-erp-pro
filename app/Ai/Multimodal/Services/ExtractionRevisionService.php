<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\Contracts\MultilingualSpeechProviderInterface;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Multimodal\Schemas\InvoiceExtractionV1;
use App\Ai\Multimodal\Schemas\TranscriptExtractionV1;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Policies\AiMediaAssetPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies optimistic-locked partial user edits and confirmation receipts onto
 * multimodal extractions without mutating historical revisions or ERP ledgers.
 */
final class ExtractionRevisionService
{
    public function __construct(
        protected AiMediaAssetPolicy $policy,
        protected InvoiceExtractionV1 $invoiceSchema,
        protected TranscriptExtractionV1 $transcriptSchema,
        protected MultilingualSpeechProviderInterface $multilingualSpeech,
        protected NormalizedInputBuilder $normalizedBuilder,
    ) {}

    /**
     * @param array<string, mixed> $patch
     */
    public function patchExtraction(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        int $expectedRevision,
        array $patch,
        bool $markConfirmed = false,
    ): AiMediaExtraction {
        if (! $this->policy->canAccessPrincipal($principal, $asset)) {
            throw MultimodalException::crossTenantForbidden();
        }
        if ($asset->isPurged()) {
            throw MultimodalException::mediaNotFound();
        }
        if ($asset->isExpired()) {
            throw MultimodalException::mediaExpired();
        }

        return DB::transaction(function () use ($principal, $asset, $expectedRevision, $patch, $markConfirmed): AiMediaExtraction {
            /** @var AiMediaExtraction|null $latest */
            $latest = AiMediaExtraction::query()
                ->where('company_id', $principal->companyId)
                ->where('media_id', $asset->id)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();

            if ($latest === null) {
                throw MultimodalException::mediaNotFound('No extraction exists yet for this media asset.');
            }

            if ((int) $latest->revision !== $expectedRevision) {
                throw MultimodalException::revisionConflict($expectedRevision, (int) $latest->revision);
            }

            $base = is_array($latest->extracted_json) ? $latest->extracted_json : [];
            $merged = $asset->source_kind === 'audio'
                ? $this->applyTranscriptPatch($base, $patch, $markConfirmed)
                : $this->applyInvoicePatch($base, $patch, $markConfirmed);

            $normalizedExtraction = $asset->source_kind === 'audio'
                ? $this->transcriptSchema->validateAndNormalize($merged, $asset->duration_seconds, $this->multilingualSpeech)
                : $this->invoiceSchema->validateAndNormalize($principal->companyId, $merged, (array) $latest->source_evidence_json);

            // Preserve user_confirmed flags through schema normalization
            if ($markConfirmed) {
                $lineKey = $asset->source_kind === 'audio' ? 'items' : 'lines';
                foreach ($normalizedExtraction[$lineKey] ?? [] as $i => $row) {
                    $normalizedExtraction[$lineKey][$i]['user_confirmed'] = true;
                }
            }

            $nextRevision = (int) $latest->revision + 1;
            $newId = (string) Str::uuid();

            $normalizedInput = $this->normalizedBuilder->build(
                principal: $principal,
                asset: $asset,
                extracted: $normalizedExtraction,
                extractionId: $newId,
                extractionRevision: $nextRevision,
                modelKey: (string) $latest->model_key,
                companionText: isset($patch['companion_text']) && is_string($patch['companion_text']) ? $patch['companion_text'] : null,
            );

            $newRecord = AiMediaExtraction::query()->create([
                'id' => $newId,
                'company_id' => $principal->companyId,
                'media_id' => $asset->id,
                'revision' => $nextRevision,
                'model_provider' => $latest->model_provider,
                'model_key' => $latest->model_key,
                'prompt_version' => $latest->prompt_version,
                'extraction_schema_version' => $latest->extraction_schema_version,
                'extracted_json' => $normalizedExtraction,
                'source_evidence_json' => $latest->source_evidence_json,
                'normalized_input_json' => $normalizedInput->toArray(),
                'confirmation_status' => $markConfirmed ? 'confirmed' : 'needs_review',
                'confirmed_by' => $markConfirmed ? $principal->actorId : null,
                'confirmed_at' => $markConfirmed ? now('UTC') : null,
            ]);

            if ($markConfirmed) {
                $asset->update(['state' => 'confirmed']);
            }

            return $newRecord;
        }, 3);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function applyInvoicePatch(array $base, array $patch, bool $markConfirmed): array
    {
        $allowedScalars = [
            'document_type',
            'supplier_name',
            'supplier_gstin',
            'supplier_phone',
            'buyer_name',
            'buyer_gstin',
            'invoice_number',
            'invoice_date',
            'currency',
            'place_of_supply',
            'subtotal',
            'tax_amount',
            'discount_amount',
            'total_amount',
        ];

        foreach ($allowedScalars as $field) {
            if (array_key_exists($field, $patch)) {
                $base[$field] = $patch[$field];
            }
        }

        $lines = is_array($base['lines'] ?? null) ? $base['lines'] : [];

        if (! empty($patch['remove_client_line_ids']) && is_array($patch['remove_client_line_ids'])) {
            $removeIds = array_map('strval', $patch['remove_client_line_ids']);
            $lines = array_values(array_filter(
                $lines,
                static fn ($line) => ! in_array((string) ($line['client_line_id'] ?? ''), $removeIds, true)
            ));
        }

        if (! empty($patch['lines_patch']) && is_array($patch['lines_patch'])) {
            $byId = [];
            foreach ($patch['lines_patch'] as $rowPatch) {
                if (is_array($rowPatch) && ! empty($rowPatch['client_line_id'])) {
                    $byId[(string) $rowPatch['client_line_id']] = $rowPatch;
                }
            }

            $allowedLineKeys = ['description', 'sku_or_hsn', 'quantity', 'uom', 'unit_price', 'discount_amount', 'tax_rate', 'tax_amount', 'line_total'];
            foreach ($lines as $i => $line) {
                $id = (string) ($line['client_line_id'] ?? '');
                if (isset($byId[$id])) {
                    foreach ($allowedLineKeys as $k) {
                        if (array_key_exists($k, $byId[$id])) {
                            $lines[$i][$k] = $byId[$id][$k];
                        }
                    }
                    $lines[$i]['user_confirmed'] = true;
                } elseif ($markConfirmed) {
                    $lines[$i]['user_confirmed'] = true;
                }
            }
        }

        if (! empty($patch['add_lines']) && is_array($patch['add_lines'])) {
            foreach ($patch['add_lines'] as $added) {
                if (is_array($added)) {
                    $added['client_line_id'] = $added['client_line_id'] ?? ('line_' . (count($lines) + 1));
                    $added['user_confirmed'] = true;
                    $lines[] = $added;
                }
            }
        }

        $base['lines'] = $lines;

        return $base;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function applyTranscriptPatch(array $base, array $patch, bool $markConfirmed): array
    {
        foreach (['transcript_text', 'intent_guess', 'party_name', 'warehouse_name', 'expected_date', 'notes'] as $field) {
            if (array_key_exists($field, $patch)) {
                $base[$field] = $patch[$field];
            }
        }

        $items = is_array($base['items'] ?? null) ? $base['items'] : [];
        if (! empty($patch['items_patch']) && is_array($patch['items_patch'])) {
            $byId = [];
            foreach ($patch['items_patch'] as $rowPatch) {
                if (is_array($rowPatch) && ! empty($rowPatch['client_line_id'])) {
                    $byId[(string) $rowPatch['client_line_id']] = $rowPatch;
                }
            }

            foreach ($items as $i => $item) {
                $id = (string) ($item['client_line_id'] ?? '');
                if (isset($byId[$id])) {
                    foreach (['product_name', 'quantity', 'uom', 'unit_price'] as $k) {
                        if (array_key_exists($k, $byId[$id])) {
                            $items[$i][$k] = $byId[$id][$k];
                        }
                    }
                    $items[$i]['user_confirmed'] = true;
                } elseif ($markConfirmed) {
                    $items[$i]['user_confirmed'] = true;
                }
            }
        }

        $base['items'] = $items;

        return $base;
    }
}

