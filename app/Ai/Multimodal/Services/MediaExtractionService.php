<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\Contracts\MultilingualSpeechProviderInterface;
use App\Ai\Multimodal\Contracts\PerceptionClientInterface;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Multimodal\Schemas\InvoiceExtractionV1;
use App\Ai\Multimodal\Schemas\TranscriptExtractionV1;
use App\Ai\Services\AiFeatureGate;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Policies\AiMediaAssetPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates idempotent multimodal perception extraction via NVIDIA Nemotron Omni,
 * normalizes outputs against strict schemas, and persists versioned extraction records.
 */
final class MediaExtractionService
{
    public function __construct(
        protected AiFeatureGate $featureGate,
        protected AiMediaAssetPolicy $policy,
        protected PerceptionClientInterface $perceptionClient,
        protected MultilingualSpeechProviderInterface $multilingualSpeech,
        protected InvoiceExtractionV1 $invoiceSchema,
        protected TranscriptExtractionV1 $transcriptSchema,
        protected NormalizedInputBuilder $normalizedBuilder,
    ) {}

    public function extract(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        ?string $idempotencyKey = null,
        bool $forceReextract = false,
        ?string $companionText = null,
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
        if (! $this->featureGate->isMultimodalEnabledForCompany($principal->companyId)) {
            throw MultimodalException::featureDisabled();
        }

        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 120)
            : null;

        if ($cleanKey !== null) {
            $idempotent = AiMediaExtraction::query()
                ->where('company_id', $principal->companyId)
                ->where('media_id', $asset->id)
                ->where('idempotency_key', $cleanKey)
                ->first();

            if ($idempotent !== null) {
                return $idempotent;
            }
        }

        if (! $forceReextract) {
            $existing = AiMediaExtraction::query()
                ->where('company_id', $principal->companyId)
                ->where('media_id', $asset->id)
                ->whereNull('error_code')
                ->orderByDesc('revision')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return $asset->source_kind === 'audio'
            ? $this->extractAudio($principal, $asset, $cleanKey, $companionText)
            : $this->extractVisualDocument($principal, $asset, $cleanKey, $companionText);
    }

    private function extractVisualDocument(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        ?string $idempotencyKey,
        ?string $companionText,
    ): AiMediaExtraction {
        $disk = Storage::disk($asset->private_disk);
        $derivatives = is_array($asset->derivatives_json) ? $asset->derivatives_json : [];
        if ($derivatives === []) {
            throw MultimodalException::corruptedMedia('No rendered image derivatives are available for extraction.');
        }

        $totalPages = count($derivatives);
        $mergedRaw = null;
        $mergedLines = [];
        $pageWarnings = [];
        $sourceEvidence = [];
        $succeededPages = 0;
        $modelProvider = (string) config('ai_multimodal.provider', 'nvidia');
        $modelKey = (string) config('ai_multimodal.model', '');

        $activeCompanyName = null;
        $activeCompanyGstin = null;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('companies')) {
                $comp = \Illuminate\Support\Facades\DB::table('companies')->where('id', $principal->companyId)->first(['legal_name', 'trade_name']);
                $activeCompanyName = $comp?->trade_name ?: $comp?->legal_name;
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('tax_registrations')) {
                $activeCompanyGstin = \Illuminate\Support\Facades\DB::table('tax_registrations')
                    ->where('company_id', $principal->companyId)
                    ->whereNotNull('gstin')
                    ->value('gstin');
            }
        } catch (\Throwable) {
            // Unbound in isolated unit tests
        }

        foreach ($derivatives as $index => $deriv) {
            $pageNum = (int) ($deriv['page'] ?? ($index + 1));
            $key = (string) ($deriv['key'] ?? '');
            $mime = (string) ($deriv['mime'] ?? 'image/jpeg');

            if ($key === '' || ! $disk->exists($key)) {
                $pageWarnings[] = "Page {$pageNum} file could not be located on private storage.";
                continue;
            }

            $binary = $disk->get($key);
            if (! is_string($binary) || $binary === '') {
                $pageWarnings[] = "Page {$pageNum} was empty during extraction.";
                continue;
            }

            try {
                $res = $this->perceptionClient->extractStructuredFromImages(
                    principal: $principal,
                    mediaId: $asset->id,
                    images: [['mime' => $mime, 'base64' => base64_encode($binary), 'page' => $pageNum]],
                    systemPrompt: InvoiceExtractionV1::systemPrompt($activeCompanyName, $activeCompanyGstin),
                    userPrompt: InvoiceExtractionV1::userPrompt($pageNum, $totalPages),
                );

                $modelProvider = $res->provider;
                $modelKey = $res->model;
                $parsed = $res->parsedJson;
                $succeededPages++;

                $sourceEvidence[] = [
                    'page' => $pageNum,
                    'mime' => $mime,
                    'status' => 'ok',
                    'input_tokens' => $res->inputTokens,
                    'output_tokens' => $res->outputTokens,
                ];

                if ($mergedRaw === null) {
                    $mergedRaw = $parsed;
                } else {
                    foreach (['supplier_name', 'supplier_gstin', 'invoice_number', 'invoice_date', 'subtotal', 'tax_amount', 'total_amount'] as $headerField) {
                        if (empty($mergedRaw[$headerField]) && ! empty($parsed[$headerField])) {
                            $mergedRaw[$headerField] = $parsed[$headerField];
                        }
                    }
                }

                foreach ((array) ($parsed['lines'] ?? []) as $line) {
                    if (is_array($line)) {
                        $line['page'] = $line['page'] ?? $pageNum;
                        $mergedLines[] = $line;
                    }
                }

                foreach ((array) ($parsed['warnings'] ?? []) as $pw) {
                    if (is_string($pw) && $pw !== '') {
                        $pageWarnings[] = $pw;
                    }
                }
            } catch (Throwable $e) {
                $sourceEvidence[] = [
                    'page' => $pageNum,
                    'mime' => $mime,
                    'status' => 'failed',
                    'error' => $e instanceof MultimodalException ? $e->errorCode : class_basename($e),
                ];
                $pageWarnings[] = "Page {$pageNum} extraction failed (" . ($e instanceof MultimodalException ? $e->errorCode : 'EXTRACTION_ERROR') . '). Review this page manually.';
            }
        }

        if ($succeededPages === 0 || $mergedRaw === null) {
            $asset->update(['state' => 'failed', 'last_error_code' => 'ALL_PAGES_FAILED']);
            throw MultimodalException::extractionFailed('ALL_PAGES_FAILED', 'Document extraction failed across all pages.');
        }

        $mergedRaw['lines'] = $mergedLines;
        $mergedRaw['warnings'] = array_values(array_unique($pageWarnings));
        if ($succeededPages < $totalPages) {
            $mergedRaw['warnings'][] = "Partial extraction: {$succeededPages} of {$totalPages} pages extracted.";
        }

        $normalizedExtraction = $this->invoiceSchema->validateAndNormalize($principal->companyId, $mergedRaw, $sourceEvidence);

        return $this->persistExtraction(
            principal: $principal,
            asset: $asset,
            provider: $modelProvider,
            modelKey: $modelKey,
            promptVersion: InvoiceExtractionV1::promptVersion(),
            schemaVersion: InvoiceExtractionV1::version(),
            extractedJson: $normalizedExtraction,
            sourceEvidence: $sourceEvidence,
            idempotencyKey: $idempotencyKey,
            companionText: $companionText,
        );
    }

    private function extractAudio(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        ?string $idempotencyKey,
        ?string $companionText,
    ): AiMediaExtraction {
        $disk = Storage::disk($asset->private_disk);
        if (! $disk->exists($asset->private_key)) {
            throw MultimodalException::mediaNotFound('Canonical audio file is missing.');
        }

        $wavBinary = $disk->get($asset->private_key);
        if (! is_string($wavBinary) || $wavBinary === '') {
            throw MultimodalException::silentAudio();
        }

        try {
            $res = $this->perceptionClient->transcribeWavAudio(
                principal: $principal,
                mediaId: $asset->id,
                wavBinary: $wavBinary,
                systemPrompt: TranscriptExtractionV1::systemPrompt(),
                userPrompt: TranscriptExtractionV1::userPrompt(),
            );
        } catch (Throwable $e) {
            $asset->update([
                'state' => 'failed',
                'last_error_code' => $e instanceof MultimodalException ? $e->errorCode : 'AUDIO_TRANSCRIPTION_FAILED',
            ]);
            throw $e;
        }

        $normalizedTranscript = $this->transcriptSchema->validateAndNormalize(
            $res->parsedJson,
            $asset->duration_seconds,
            $this->multilingualSpeech,
        );

        $evidence = [[
            'audio_duration_seconds' => $asset->duration_seconds,
            'mime' => $asset->canonical_mime,
            'input_tokens' => $res->inputTokens,
            'output_tokens' => $res->outputTokens,
        ]];

        return $this->persistExtraction(
            principal: $principal,
            asset: $asset,
            provider: $res->provider,
            modelKey: $res->model,
            promptVersion: TranscriptExtractionV1::promptVersion(),
            schemaVersion: TranscriptExtractionV1::version(),
            extractedJson: $normalizedTranscript,
            sourceEvidence: $evidence,
            idempotencyKey: $idempotencyKey,
            companionText: $companionText,
        );
    }

    /**
     * @param array<string, mixed> $extractedJson
     * @param array<int, array<string, mixed>> $sourceEvidence
     */
    private function persistExtraction(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        string $provider,
        string $modelKey,
        string $promptVersion,
        string $schemaVersion,
        array $extractedJson,
        array $sourceEvidence,
        ?string $idempotencyKey,
        ?string $companionText,
    ): AiMediaExtraction {
        return DB::transaction(function () use (
            $principal,
            $asset,
            $provider,
            $modelKey,
            $promptVersion,
            $schemaVersion,
            $extractedJson,
            $sourceEvidence,
            $idempotencyKey,
            $companionText,
        ): AiMediaExtraction {
            $maxRev = (int) AiMediaExtraction::query()
                ->where('media_id', $asset->id)
                ->lockForUpdate()
                ->max('revision');

            $nextRevision = $maxRev + 1;
            $extractionId = (string) Str::uuid();

            $normalized = $this->normalizedBuilder->build(
                principal: $principal,
                asset: $asset,
                extracted: $extractedJson,
                extractionId: $extractionId,
                extractionRevision: $nextRevision,
                modelKey: $modelKey,
                companionText: $companionText,
            );

            $extraction = AiMediaExtraction::query()->create([
                'id' => $extractionId,
                'company_id' => $principal->companyId,
                'media_id' => $asset->id,
                'revision' => $nextRevision,
                'model_provider' => $provider,
                'model_key' => $modelKey,
                'prompt_version' => $promptVersion,
                'extraction_schema_version' => $schemaVersion,
                'extracted_json' => $extractedJson,
                'source_evidence_json' => $sourceEvidence,
                'normalized_input_json' => $normalized->toArray(),
                'confirmation_status' => 'needs_review',
                'idempotency_key' => $idempotencyKey,
            ]);

            $asset->update([
                'state' => 'extracted',
                'last_error_code' => null,
            ]);

            Log::info('AI multimodal extraction recorded', [
                'company_id' => $principal->companyId,
                'media_id' => $asset->id,
                'extraction_id' => $extraction->id,
                'revision' => $nextRevision,
                'model_key' => $modelKey,
                'source_kind' => $asset->source_kind,
            ]);

            return $extraction;
        }, 3);
    }
}

