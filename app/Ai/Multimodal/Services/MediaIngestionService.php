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
                'derivatives_json' => $storedDerivatives,
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

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'application/pdf' => 'pdf',
            'audio/wav' => 'wav',
            default => 'bin',
        };
    }
}

