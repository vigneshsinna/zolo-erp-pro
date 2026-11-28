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
use App\Models\AiMediaProcessingTask;
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

    /**
     * Task-aware extraction with per-page durable checkpointing for async worker resilience.
     * Already-succeeded pages in $task->page_checkpoints_json are reused without re-calling the provider.
     *
     * @param callable(int, array<string, mixed>): void $onPageCheckpoint
     * @param callable(): void|null $beforePageDispatch
     * @param array<string, mixed> $providerOptions
     */
    public function extractForTask(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        AiMediaProcessingTask $task,
        callable $onPageCheckpoint,
        ?callable $beforePageDispatch = null,
        array $providerOptions = [],
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

        $companionText = $task->getCompanionText();
        if (! $task->force_reextract) {
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

        $idempotencyKey = 'task_' . $task->id . '_v' . $task->state_version;
        if ($asset->source_kind === 'audio') {
            $audioOptions = array_merge([
                'max_retries' => (int) config('ai_multimodal.async.provider_immediate_retries', 0),
                'media_task_id' => $task->id,
                'page_number' => 1,
                'attempt_number' => max(1, (int) $task->attempt_count + 1),
                'deadline_utc' => $task->deadline_at?->toIso8601String(),
            ], $providerOptions);

            return $this->extractAudio($principal, $asset, $idempotencyKey, $companionText, $audioOptions);
        }

        if ($companionText !== null && trim($companionText) !== '' && function_exists('app') && app()->bound(\App\Ai\Security\AiDataClassificationPolicy::class)) {
            $companionText = app(\App\Ai\Security\AiDataClassificationPolicy::class)->inspectUntrustedInput($companionText)['sanitized'];
        }

        $disk = Storage::disk($asset->private_disk);
        $derivatives = is_array($asset->derivatives_json) ? $asset->derivatives_json : [];
        if ($derivatives === []) {
            throw MultimodalException::corruptedMedia('No rendered image derivatives are available for extraction.');
        }

        $totalPages = count($derivatives);
        [$activeCompanyName, $activeCompanyGstin, $isOpticalProfile] = $this->resolveCompanyProfile($principal);

        $checkpoints = is_array($task->page_checkpoints_json) ? $task->page_checkpoints_json : [];
        $pageFailures = [];
        $succeededPages = 0;

        foreach ($derivatives as $index => $deriv) {
            $pageNum = (int) ($deriv['page'] ?? ($index + 1));
            $cpKey = (string) $pageNum;

            if (isset($checkpoints[$cpKey]['status']) && $checkpoints[$cpKey]['status'] === 'ok') {
                $succeededPages++;
                continue;
            }

            if ($beforePageDispatch !== null) {
                $beforePageDispatch();
            }

            $key = (string) ($deriv['key'] ?? '');
            $mime = (string) ($deriv['mime'] ?? 'image/jpeg');

            if ($key === '' || ! $disk->exists($key)) {
                $pageFailures[] = [
                    'page' => $pageNum,
                    'error_code' => 'MISSING_DERIVATIVE',
                    'error_category' => 'storage',
                    'retryable' => false,
                ];
                continue;
            }

            $binary = $disk->get($key);
            if (! is_string($binary) || $binary === '') {
                $pageFailures[] = [
                    'page' => $pageNum,
                    'error_code' => 'EMPTY_DERIVATIVE',
                    'error_category' => 'storage',
                    'retryable' => false,
                ];
                continue;
            }

            $pageOpts = array_merge([
                'max_retries' => (int) config('ai_multimodal.async.provider_immediate_retries', 0),
                'media_task_id' => $task->id,
                'page_number' => $pageNum,
                'attempt_number' => max(1, (int) $task->attempt_count + 1),
                'deadline_utc' => $task->deadline_at?->toIso8601String(),
            ], $providerOptions);

            try {
                $res = $this->perceptionClient->extractStructuredFromImages(
                    principal: $principal,
                    mediaId: $asset->id,
                    images: [['mime' => $mime, 'base64' => base64_encode($binary), 'page' => $pageNum]],
                    systemPrompt: InvoiceExtractionV1::systemPrompt($activeCompanyName, $activeCompanyGstin, $isOpticalProfile),
                    userPrompt: InvoiceExtractionV1::userPrompt($pageNum, $totalPages, $isOpticalProfile),
                    options: $pageOpts,
                );
                unset($binary);

                $cpData = [
                    'status' => 'ok',
                    'page' => $pageNum,
                    'mime' => $mime,
                    'provider' => $res->provider,
                    'model' => $res->model,
                    'parsed_json' => $res->parsedJson,
                    'input_tokens' => $res->inputTokens,
                    'output_tokens' => $res->outputTokens,
                    'completed_at_utc' => now('UTC')->toIso8601String(),
                ];
                $checkpoints[$cpKey] = $cpData;
                $succeededPages++;
                $onPageCheckpoint($pageNum, $cpData);
            } catch (Throwable $e) {
                unset($binary);
                $errorCode = $e instanceof MultimodalException ? $e->errorCode : 'EXTRACTION_ERROR';
                $nonRetryable = in_array($errorCode, [
                    'INVALID_PERCEPTION_RESPONSE',
                    'INVALID_PERCEPTION_JSON',
                    'EMPTY_PERCEPTION_RESPONSE',
                    'TRUNCATED_PERCEPTION_RESPONSE',
                    'MISSING_DERIVATIVE',
                    'EMPTY_DERIVATIVE',
                    'PROVIDER_HTTP_400',
                    'PROVIDER_AUTH_FAILED',
                    'PROVIDER_SSL_ERROR',
                    'MULTIMODAL_DISABLED',
                    'PERMISSION_DENIED',
                ], true);

                // Propagate single-page terminal exceptions directly, and transient/rate-limit failures immediately when task retry can finish missing pages
                if (($totalPages === 1 || ! $nonRetryable) && $e instanceof MultimodalException) {
                    $details = array_merge($e->details, [
                        'page' => $pageNum,
                        'succeeded_pages' => $succeededPages,
                        'total_pages' => $totalPages,
                        'media_id' => $asset->id,
                        'is_retryable' => ! $nonRetryable,
                    ]);
                    if ($nonRetryable) {
                        $asset->update(['state' => 'failed', 'last_error_code' => $errorCode]);
                    }
                    throw new MultimodalException($e->errorCode, $e->getMessage(), $e->httpStatus, $details);
                }

                $pageFailures[] = [
                    'page' => $pageNum,
                    'error_code' => $errorCode,
                    'error_category' => $e instanceof MultimodalException && $e->httpStatus >= 500 ? 'provider' : 'client',
                    'retryable' => ! $nonRetryable,
                ];
            }
        }

        if ($succeededPages === 0) {
            $distinctErrors = array_values(array_unique(array_column($pageFailures, 'error_code')));
            $primaryError = count($distinctErrors) === 1 ? reset($distinctErrors) : 'ALL_PAGES_FAILED';
            $asset->update(['state' => 'failed', 'last_error_code' => $primaryError]);
            $isRetryable = ! empty($pageFailures) && ! in_array(false, array_column($pageFailures, 'retryable'), true);

            throw MultimodalException::allPagesFailed(
                pageCount: $totalPages,
                failures: $pageFailures,
                isRetryable: $isRetryable,
                mediaId: $asset->id,
            );
        }

        ksort($checkpoints, SORT_NUMERIC);
        $mergedRaw = null;
        $mergedLines = [];
        $pageWarnings = [];
        $sourceEvidence = [];
        $modelProvider = (string) config('ai_multimodal.provider', 'nvidia');
        $modelKey = (string) config('ai_multimodal.model', '');

        foreach ($checkpoints as $pageNum => $cp) {
            if (($cp['status'] ?? '') !== 'ok' || ! is_array($cp['parsed_json'] ?? null)) {
                continue;
            }
            $parsed = $cp['parsed_json'];
            $modelProvider = (string) ($cp['provider'] ?? $modelProvider);
            $modelKey = (string) ($cp['model'] ?? $modelKey);

            $sourceEvidence[] = [
                'page' => (int) $pageNum,
                'mime' => (string) ($cp['mime'] ?? 'image/jpeg'),
                'status' => 'ok',
                'input_tokens' => (int) ($cp['input_tokens'] ?? 0),
                'output_tokens' => (int) ($cp['output_tokens'] ?? 0),
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
                    $line['page'] = $line['page'] ?? (int) $pageNum;
                    $mergedLines[] = $line;
                }
            }

            foreach ((array) ($parsed['warnings'] ?? []) as $pw) {
                if (is_string($pw) && $pw !== '') {
                    $pageWarnings[] = $pw;
                }
            }
        }

        foreach ($pageFailures as $pf) {
            $sourceEvidence[] = [
                'page' => $pf['page'],
                'status' => 'failed',
                'error' => $pf['error_code'],
            ];
            $pageWarnings[] = "Page {$pf['page']} extraction failed ({$pf['error_code']}). Review this page manually.";
        }

        if ($mergedRaw === null) {
            throw MultimodalException::extractionFailed('ALL_PAGES_FAILED', 'No valid pages could be merged.');
        }

        $mergedRaw['lines'] = $mergedLines;
        $mergedRaw['warnings'] = array_values(array_unique($pageWarnings));
        if ($succeededPages < $totalPages) {
            $mergedRaw['warnings'][] = "Partial extraction: {$succeededPages} of {$totalPages} pages extracted.";
        }

        try {
            $normalizedExtraction = $this->invoiceSchema->validateAndNormalize(
                companyId: $principal->companyId,
                raw: $mergedRaw,
                sourceEvidence: $sourceEvidence,
                isOpticalProfile: $isOpticalProfile,
                expectedPages: $totalPages,
            );
        } catch (Throwable $e) {
            $asset->update(['state' => 'failed', 'last_error_code' => 'EXTRACTION_SCHEMA_REJECTED']);
            throw MultimodalException::extractionFailed('EXTRACTION_SCHEMA_REJECTED', 'Schema validation rejected extraction output: ' . $e->getMessage());
        }

        return $this->persistExtraction(
            principal: $principal,
            asset: $asset,
            provider: $modelProvider,
            modelKey: $modelKey,
            promptVersion: $isOpticalProfile ? InvoiceExtractionV1::opticalPromptVersion() : InvoiceExtractionV1::promptVersion(),
            schemaVersion: $isOpticalProfile ? InvoiceExtractionV1::opticalVersion() : InvoiceExtractionV1::version(),
            extractedJson: $normalizedExtraction,
            sourceEvidence: $sourceEvidence,
            idempotencyKey: $idempotencyKey,
            companionText: $companionText,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function resolveCompanyProfile(AiPrincipal $principal): array
    {
        $activeCompanyName = null;
        $activeCompanyGstin = null;
        $isOpticalProfile = false;
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
            if (class_exists(\App\Ai\Knowledge\IndustryContextResolver::class) && function_exists('app')) {
                $companyCtx = new \App\Services\Platform\CompanyContext(
                    $principal->companyId,
                    (int) ($principal->branchId ?? 0),
                    (int) ($principal->financialYearId ?? 0),
                );
                $isOpticalProfile = app(\App\Ai\Knowledge\IndustryContextResolver::class)->isOptical($companyCtx);
            }
        } catch (Throwable) {
            // Unbound in isolated unit tests
        }

        return [$activeCompanyName, $activeCompanyGstin, $isOpticalProfile];
    }

    private function extractVisualDocument(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        ?string $idempotencyKey,
        ?string $companionText,
    ): AiMediaExtraction {
        if ($companionText !== null && trim($companionText) !== '' && function_exists('app') && app()->bound(\App\Ai\Security\AiDataClassificationPolicy::class)) {
            $companionText = app(\App\Ai\Security\AiDataClassificationPolicy::class)->inspectUntrustedInput($companionText)['sanitized'];
        }

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
        $isOpticalProfile = false;
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
            if (class_exists(\App\Ai\Knowledge\IndustryContextResolver::class) && function_exists('app')) {
                $companyCtx = new \App\Services\Platform\CompanyContext(
                    $principal->companyId,
                    (int) ($principal->branchId ?? 0),
                    (int) ($principal->financialYearId ?? 0),
                );
                $isOpticalProfile = app(\App\Ai\Knowledge\IndustryContextResolver::class)->isOptical($companyCtx);
            }
        } catch (\Throwable) {
            // Unbound in isolated unit tests
        }

        if (empty($derivatives)) {
            $asset->update(['state' => 'failed', 'last_error_code' => 'NO_DERIVATIVES_FOUND']);
            throw MultimodalException::extractionFailed('NO_DERIVATIVES_FOUND', 'No valid stored page derivatives found for document.');
        }

        $pageFailures = [];
        foreach ($derivatives as $index => $deriv) {
            $pageNum = (int) ($deriv['page'] ?? ($index + 1));
            $key = (string) ($deriv['key'] ?? '');
            $mime = (string) ($deriv['mime'] ?? 'image/jpeg');

            if ($key === '' || ! $disk->exists($key)) {
                $pageFailures[] = [
                    'page' => $pageNum,
                    'error_code' => 'MISSING_DERIVATIVE',
                    'error_category' => 'storage',
                    'retryable' => false,
                ];
                $pageWarnings[] = "Page {$pageNum} file could not be located on private storage.";
                continue;
            }

            $binary = $disk->get($key);
            if (! is_string($binary) || $binary === '') {
                $pageFailures[] = [
                    'page' => $pageNum,
                    'error_code' => 'EMPTY_DERIVATIVE',
                    'error_category' => 'storage',
                    'retryable' => false,
                ];
                $pageWarnings[] = "Page {$pageNum} was empty during extraction.";
                continue;
            }

            try {
                $res = $this->perceptionClient->extractStructuredFromImages(
                    principal: $principal,
                    mediaId: $asset->id,
                    images: [['mime' => $mime, 'base64' => base64_encode($binary), 'page' => $pageNum]],
                    systemPrompt: InvoiceExtractionV1::systemPrompt($activeCompanyName, $activeCompanyGstin, $isOpticalProfile),
                    userPrompt: InvoiceExtractionV1::userPrompt($pageNum, $totalPages, $isOpticalProfile),
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
                $errorCode = $e instanceof MultimodalException ? $e->errorCode : 'EXTRACTION_ERROR';
                $isRetryable = ! in_array($errorCode, [
                    'INVALID_PERCEPTION_RESPONSE',
                    'MISSING_DERIVATIVE',
                    'EMPTY_DERIVATIVE',
                    'PROVIDER_AUTH_FAILED',
                    'PROVIDER_SSL_ERROR',
                ], true);
                $pageFailures[] = [
                    'page' => $pageNum,
                    'error_code' => $errorCode,
                    'error_category' => $e instanceof MultimodalException ? ($e->httpStatus >= 500 ? 'provider' : 'client') : 'system',
                    'retryable' => $isRetryable,
                ];
                $sourceEvidence[] = [
                    'page' => $pageNum,
                    'mime' => $mime,
                    'status' => 'failed',
                    'error' => $errorCode,
                ];
                $pageWarnings[] = "Page {$pageNum} extraction failed ({$errorCode}). Review this page manually.";
            }
        }

        if ($succeededPages === 0 || $mergedRaw === null) {
            $distinctErrors = array_values(array_unique(array_column($pageFailures, 'error_code')));
            $primaryError = count($distinctErrors) === 1 ? reset($distinctErrors) : 'ALL_PAGES_FAILED';
            $asset->update(['state' => 'failed', 'last_error_code' => $primaryError]);

            $isRetryable = ! empty($pageFailures) && ! in_array(false, array_column($pageFailures, 'retryable'), true);

            throw MultimodalException::allPagesFailed(
                pageCount: $totalPages,
                failures: $pageFailures,
                isRetryable: $isRetryable,
                mediaId: $asset->id,
            );
        }

        $mergedRaw['lines'] = $mergedLines;
        $mergedRaw['warnings'] = array_values(array_unique($pageWarnings));
        if ($succeededPages < $totalPages) {
            $mergedRaw['warnings'][] = "Partial extraction: {$succeededPages} of {$totalPages} pages extracted.";
        }

        try {
            $normalizedExtraction = $this->invoiceSchema->validateAndNormalize(
                companyId: $principal->companyId,
                raw: $mergedRaw,
                sourceEvidence: $sourceEvidence,
                isOpticalProfile: $isOpticalProfile,
                expectedPages: $totalPages,
            );
        } catch (Throwable $e) {
            $asset->update(['state' => 'failed', 'last_error_code' => 'EXTRACTION_SCHEMA_REJECTED']);
            throw MultimodalException::extractionFailed('EXTRACTION_SCHEMA_REJECTED', 'Schema validation rejected extraction output: ' . $e->getMessage());
        }

        return $this->persistExtraction(
            principal: $principal,
            asset: $asset,
            provider: $modelProvider,
            modelKey: $modelKey,
            promptVersion: $isOpticalProfile ? InvoiceExtractionV1::opticalPromptVersion() : InvoiceExtractionV1::promptVersion(),
            schemaVersion: $isOpticalProfile ? InvoiceExtractionV1::opticalVersion() : InvoiceExtractionV1::version(),
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
        array $providerOptions = [],
    ): AiMediaExtraction {
        if ($companionText !== null && trim($companionText) !== '' && function_exists('app') && app()->bound(\App\Ai\Security\AiDataClassificationPolicy::class)) {
            $companionText = app(\App\Ai\Security\AiDataClassificationPolicy::class)->inspectUntrustedInput($companionText)['sanitized'];
        }

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
                options: $providerOptions,
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

