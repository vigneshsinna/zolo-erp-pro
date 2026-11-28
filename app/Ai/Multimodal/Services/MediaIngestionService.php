<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Services\AiFeatureGate;
use App\Models\AiMediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

/**
 * Stores validated, preprocessed company-scoped AI media onto private disk storage
 * with strict idempotency, retention expiry, and daily upload quotas.
 */
final class MediaIngestionService
{
    public function __construct(
        protected AiFeatureGate $featureGate,
        protected ApprovedModelGateway $gateway,
        protected MediaValidationService $validator,
        protected MediaPreprocessingService $preprocessor,
    ) {
    }

    public function ingest(
        AiPrincipal $principal,
        UploadedFile|SplFileInfo $file,
        ?string $declaredMime = null,
        ?string $originalName = null,
        ?string $idempotencyKey = null,
        ?string $threadId = null,
        ?string $workflowTaskId = null,
    ): AiMediaAsset {
        if (!$principal->isActive) {
            throw MultimodalException::permissionDenied();
        }

        if (!$this->featureGate->isMultimodalEnabledForCompany($principal->companyId)) {
            throw MultimodalException::featureDisabled();
        }

        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 120)
            : null;

        $sourcePath = $file instanceof UploadedFile ? $file->getRealPath() : (string) $file;
        $fileSha256 = is_string($sourcePath) && file_exists($sourcePath) ? hash_file('sha256', $sourcePath) : false;

        if ($cleanKey !== null) {
            $existing = AiMediaAsset::query()
                ->where('company_id', $principal->companyId)
                ->where('actor_id', $principal->actorId)
                ->where('idempotency_key', $cleanKey)
                ->first();

            if ($existing !== null && !$existing->isPurged() && !$existing->isExpired()) {
                if ($fileSha256 !== false && $existing->sha256 !== $fileSha256) {
                    throw MultimodalException::idempotencyConflict();
                }

                return $existing;
            }
        }

        $reservation = $this->gateway->reserveMediaUpload($principal, now('UTC')->toDateString());

        try {
            $validated = $this->validator->validateUploadedFile($file, $declaredMime, $originalName);
            $sourcePath = $file instanceof UploadedFile ? $file->getRealPath() : $file->getRealPath();
            $preprocessed = $this->preprocessor->preprocess((string) $sourcePath, $validated);

            $mediaId = (string) Str::uuid();
            $diskName = (string) config('ai_multimodal.storage_disk', 'ai_private');
            $disk = Storage::disk($diskName);
            $prefix = sprintf(
                'company_%d/%s/%s/%s',
                $principal->companyId,
                now('UTC')->format('Y'),
                now('UTC')->format('m'),
                $mediaId
            );

            $canonicalExt = $this->extensionForMime($preprocessed['canonical_mime']);
            $primaryKey = $prefix . '/canonical.' . $canonicalExt;
            $stream = fopen($preprocessed['canonical_path'], 'rb');
            if ($stream === false) {
                throw MultimodalException::corruptedMedia('Unable to store sanitized canonical media file.');
            }
            $disk->writeStream($primaryKey, $stream);
            fclose($stream);

            $storedDerivatives = [];
            foreach ($preprocessed['derivatives'] as $idx => $deriv) {
                $derivExt = $this->extensionForMime((string) $deriv['mime']);
                $derivKey = $prefix . '/deriv_' . ($deriv['page'] ?? ($idx + 1)) . '.' . $derivExt;

                if ($deriv['local_path'] === $preprocessed['canonical_path']) {
                    $derivKey = $primaryKey;
                } else {
                    $dStream = fopen($deriv['local_path'], 'rb');
                    if ($dStream === false) {
                        continue;
                    }
                    $disk->writeStream($derivKey, $dStream);
                    fclose($dStream);
                }

                $storedDerivatives[] = [
                    'page' => $deriv['page'] ?? ($idx + 1),
                    'disk' => $diskName,
                    'key' => $derivKey,
                    'mime' => $deriv['mime'],
                    'width' => $deriv['width'] ?? null,
                    'height' => $deriv['height'] ?? null,
                    'size_bytes' => $deriv['size_bytes'] ?? 0,
                ];
            }

            $retentionHours = max(1, (int) config('ai_multimodal.retention_hours', 24));

            $asset = AiMediaAsset::query()->create([
                'id' => $mediaId,
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'actor_id' => $principal->actorId,
                'thread_id' => $threadId,
                'workflow_task_id' => $workflowTaskId,
                'source_kind' => $validated['source_kind'],
                'original_file_name_sanitized' => $validated['sanitized_name'],
                'original_mime' => $validated['original_mime'],
                'canonical_mime' => $preprocessed['canonical_mime'],
                'size_bytes' => $validated['size_bytes'],
                'sha256' => $validated['sha256'],
                'private_disk' => $diskName,
                'private_key' => $primaryKey,
                'raw_private_key' => $primaryKey,
                'derivatives_json' => $storedDerivatives,
                'preprocessing_metrics_json' => $preprocessed['metrics'] ?? null,
                'page_count' => $preprocessed['page_count'],
                'duration_seconds' => $preprocessed['duration_seconds'],
                'idempotency_key' => $cleanKey,
                'state' => 'uploaded',
                'expires_at' => now('UTC')->addHours($retentionHours),
            ]);

            $reservation->commit();

            return $asset;
        } catch (Throwable $e) {
            $reservation->release();
            try {
                if (isset($disk, $prefix)) {
                    $disk->deleteDirectory($prefix);
                }
            } catch (Throwable) {
                // Best-effort cleanup
            }
            throw $e;
        } finally {
            if (isset($preprocessed['staging_dir'])) {
                $this->preprocessor->cleanupStagingDir($preprocessed['staging_dir']);
            }
        }
    }

    /**
     * Bounded async admission: validates magic bytes, size, header dimensions, and PDF security,
     * then stores the private original without running GD/Imagick decode, PDF rasterization, or FFmpeg.
     */
    public function admitRaw(
        AiPrincipal $principal,
        UploadedFile|SplFileInfo $file,
        ?string $declaredMime = null,
        ?string $originalName = null,
        ?string $idempotencyKey = null,
        ?string $threadId = null,
        ?string $workflowTaskId = null,
    ): AiMediaAsset {
        if (! $principal->isActive) {
            throw MultimodalException::permissionDenied();
        }

        if (! $this->featureGate->isMultimodalEnabledForCompany($principal->companyId)) {
            throw MultimodalException::featureDisabled();
        }

        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 120)
            : null;

        $sourcePath = $file instanceof UploadedFile ? $file->getRealPath() : $file->getRealPath();
        $fileSha256 = is_string($sourcePath) && file_exists($sourcePath) ? hash_file('sha256', $sourcePath) : false;

        if ($cleanKey !== null) {
            $existing = AiMediaAsset::query()
                ->where('company_id', $principal->companyId)
                ->where('actor_id', $principal->actorId)
                ->where('idempotency_key', $cleanKey)
                ->first();

            if ($existing !== null && ! $existing->isPurged() && ! $existing->isExpired()) {
                if ($fileSha256 !== false && $existing->sha256 !== $fileSha256) {
                    throw MultimodalException::idempotencyConflict();
                }

                return $existing;
            }
        }

        $reservation = $this->gateway->reserveMediaUpload($principal, now('UTC')->toDateString());

        try {
            $validated = $this->validator->validateUploadedFile($file, $declaredMime, $originalName);
            $resolvedPath = $file instanceof UploadedFile ? $file->getRealPath() : $file->getRealPath();
            if (! is_string($resolvedPath) || ! is_file($resolvedPath)) {
                throw MultimodalException::corruptedMedia('Uploaded media file could not be read from staging storage.');
            }

            $mediaId = (string) Str::uuid();
            $diskName = (string) config('ai_multimodal.storage_disk', 'ai_private');
            $disk = Storage::disk($diskName);
            $prefix = sprintf(
                'company_%d/%s/%s/%s',
                $principal->companyId,
                now('UTC')->format('Y'),
                now('UTC')->format('m'),
                $mediaId
            );

            $rawExt = $this->extensionForMime($validated['original_mime']);
            $rawKey = $prefix . '/original.' . $rawExt;
            $stream = fopen($resolvedPath, 'rb');
            if ($stream === false) {
                throw MultimodalException::corruptedMedia('Unable to store private media upload.');
            }
            $disk->writeStream($rawKey, $stream);
            fclose($stream);

            $retentionHours = max(1, (int) config('ai_multimodal.retention_hours', 24));

            $asset = AiMediaAsset::query()->create([
                'id' => $mediaId,
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'actor_id' => $principal->actorId,
                'thread_id' => $threadId,
                'workflow_task_id' => $workflowTaskId,
                'source_kind' => $validated['source_kind'],
                'original_file_name_sanitized' => $validated['sanitized_name'],
                'original_mime' => $validated['original_mime'],
                'canonical_mime' => $validated['original_mime'],
                'size_bytes' => $validated['size_bytes'],
                'sha256' => $validated['sha256'],
                'private_disk' => $diskName,
                'private_key' => $rawKey,
                'raw_private_key' => $rawKey,
                'derivatives_json' => [],
                'preprocessing_metrics_json' => [
                    'source_kind' => $validated['source_kind'],
                    'original_bytes' => $validated['size_bytes'],
                    'original_width' => $validated['width'],
                    'original_height' => $validated['height'],
                ],
                'page_count' => $validated['source_kind'] === 'image' ? 1 : null,
                'duration_seconds' => null,
                'idempotency_key' => $cleanKey,
                'state' => 'uploaded',
                'expires_at' => now('UTC')->addHours($retentionHours),
            ]);

            $reservation->commit();

            return $asset;
        } catch (Throwable $e) {
            $reservation->release();
            try {
                if (isset($disk, $prefix)) {
                    $disk->deleteDirectory($prefix);
                }
            } catch (Throwable) {
                // Best-effort cleanup
            }
            throw $e;
        }
    }

    /**
     * Worker-side preprocessing for an admitted raw media asset. Idempotent when derivatives exist.
     */
    public function preprocessAdmittedAsset(AiMediaAsset $asset, bool $isOpticalProfile = false): AiMediaAsset
    {
        if (! empty($asset->derivatives_json) && $asset->state !== 'uploaded') {
            return $asset;
        }

        // Legacy sync assets already populated derivatives_json during ingest()
        if (! empty($asset->derivatives_json) && is_array($asset->derivatives_json)) {
            $firstKey = (string) ($asset->derivatives_json[0]['key'] ?? '');
            if ($firstKey !== '' && Storage::disk($asset->private_disk)->exists($firstKey)) {
                if ($asset->state === 'uploaded') {
                    $asset->update(['state' => 'preprocessed']);
                }

                return $asset->fresh();
            }
        }

        $disk = Storage::disk($asset->private_disk);
        $sourceKey = $asset->raw_private_key ?: $asset->private_key;
        if ($sourceKey === '' || ! $disk->exists($sourceKey)) {
            throw MultimodalException::mediaNotFound('Stored raw media file is missing from private storage.');
        }

        $tempDir = storage_path('app/private/ai_staging/raw_' . Str::uuid());
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0750, true) && ! is_dir($tempDir)) {
            throw MultimodalException::dependencyUnavailable('STAGING_DIR_UNAVAILABLE', 'Unable to create temporary preprocessing directory.');
        }

        $rawLocalPath = $tempDir . DIRECTORY_SEPARATOR . 'source.' . $this->extensionForMime($asset->original_mime);
        $preprocessed = null;

        try {
            $readStream = $disk->readStream($sourceKey);
            if (! is_resource($readStream)) {
                throw MultimodalException::mediaNotFound('Unable to open stored private media stream.');
            }
            $writeHandle = fopen($rawLocalPath, 'wb');
            if ($writeHandle === false) {
                fclose($readStream);
                throw MultimodalException::corruptedMedia('Unable to materialize raw media file for preprocessing.');
            }
            stream_copy_to_stream($readStream, $writeHandle);
            fclose($readStream);
            fclose($writeHandle);

            $validated = [
                'source_kind' => $asset->source_kind,
                'original_mime' => $asset->original_mime,
                'sanitized_name' => $asset->original_file_name_sanitized ?: 'media_upload',
                'size_bytes' => (int) $asset->size_bytes,
                'sha256' => $asset->sha256,
                'width' => $asset->preprocessing_metrics_json['original_width'] ?? null,
                'height' => $asset->preprocessing_metrics_json['original_height'] ?? null,
            ];

            $preprocessed = $this->preprocessor->preprocess($rawLocalPath, $validated, $isOpticalProfile);
            $prefix = dirname($sourceKey);
            if ($prefix === '.' || $prefix === '') {
                $prefix = sprintf(
                    'company_%d/%s/%s/%s',
                    $asset->company_id,
                    now('UTC')->format('Y'),
                    now('UTC')->format('m'),
                    $asset->id
                );
            }

            $canonicalExt = $this->extensionForMime($preprocessed['canonical_mime']);
            $primaryKey = $prefix . '/canonical.' . $canonicalExt;

            if ($preprocessed['canonical_mime'] === 'application/pdf') {
                $primaryKey = $sourceKey;
            } else {
                $cStream = fopen($preprocessed['canonical_path'], 'rb');
                if ($cStream === false) {
                    throw MultimodalException::corruptedMedia('Unable to store sanitized canonical media file.');
                }
                $disk->writeStream($primaryKey, $cStream);
                fclose($cStream);
            }

            $storedDerivatives = [];
            foreach ($preprocessed['derivatives'] as $idx => $deriv) {
                $derivExt = $this->extensionForMime((string) $deriv['mime']);
                $derivKey = $prefix . '/deriv_' . ($deriv['page'] ?? ($idx + 1)) . '.' . $derivExt;

                if ($deriv['local_path'] === $preprocessed['canonical_path']) {
                    $derivKey = $primaryKey;
                } else {
                    $dStream = fopen($deriv['local_path'], 'rb');
                    if ($dStream === false) {
                        continue;
                    }
                    $disk->writeStream($derivKey, $dStream);
                    fclose($dStream);
                }

                $storedDerivatives[] = [
                    'page' => $deriv['page'] ?? ($idx + 1),
                    'disk' => $asset->private_disk,
                    'key' => $derivKey,
                    'mime' => $deriv['mime'],
                    'width' => $deriv['width'] ?? null,
                    'height' => $deriv['height'] ?? null,
                    'size_bytes' => $deriv['size_bytes'] ?? 0,
                ];
            }

            $asset->update([
                'canonical_mime' => $preprocessed['canonical_mime'],
                'private_key' => $primaryKey,
                'derivatives_json' => $storedDerivatives,
                'preprocessing_metrics_json' => $preprocessed['metrics'] ?? $asset->preprocessing_metrics_json,
                'page_count' => $preprocessed['page_count'],
                'duration_seconds' => $preprocessed['duration_seconds'],
                'state' => 'preprocessed',
                'last_error_code' => null,
            ]);

            return $asset->fresh();
        } finally {
            if (isset($preprocessed['staging_dir'])) {
                $this->preprocessor->cleanupStagingDir($preprocessed['staging_dir']);
            }
            $this->preprocessor->cleanupStagingDir($tempDir);
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'application/pdf' => 'pdf',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/flac' => 'flac',
            'audio/ogg' => 'ogg',
            default => 'bin',
        };
    }
}

