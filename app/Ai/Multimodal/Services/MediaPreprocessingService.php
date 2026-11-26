<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Multimodal\Contracts\AudioTranscoderInterface;
use App\Ai\Multimodal\Contracts\PdfRasterizerInterface;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Sanitizes, normalizes, strips EXIF/GPS metadata, rasterizes bounded PDFs,
 * and transcodes voice uploads in a temporary private scratch staging folder.
 */
final class MediaPreprocessingService
{
    public function __construct(
        protected PdfRasterizerInterface $pdfRasterizer,
        protected AudioTranscoderInterface $audioTranscoder,
    ) {}

    /**
     * Preprocess a validated media file into stripped canonical binary files.
     *
     * @param array{source_kind: string, original_mime: string, sanitized_name: string, size_bytes: int, sha256: string, width: ?int, height: ?int} $validated
     * @return array{
     *   canonical_mime: string,
     *   canonical_path: string,
     *   derivatives: array<int, array{page?: int, local_path: string, mime: string, width?: int, height?: int, size_bytes: int}>,
     *   page_count: ?int,
     *   duration_seconds: ?float,
     *   staging_dir: string
     * }
     */
    public function preprocess(string $sourcePath, array $validated): array
    {
        $stagingDir = storage_path('app/private/ai_staging/' . Str::uuid());
        if (! is_dir($stagingDir) && ! @mkdir($stagingDir, 0750, true) && ! is_dir($stagingDir)) {
            throw MultimodalException::dependencyUnavailable('STAGING_DIR_UNAVAILABLE', 'Unable to create private preprocessing staging directory.');
        }

        try {
            return match ($validated['source_kind']) {
                'image' => $this->preprocessImage($sourcePath, $validated['original_mime'], $stagingDir),
                'pdf' => $this->preprocessPdf($sourcePath, $stagingDir),
                'audio' => $this->preprocessAudio($sourcePath, $validated['original_mime'], $stagingDir),
                default => throw MultimodalException::unsupportedMime($validated['original_mime']),
            };
        } catch (Throwable $e) {
            $this->cleanupStagingDir($stagingDir);
            throw $e;
        }
    }

    public function cleanupStagingDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->cleanupStagingDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    /**
     * Re-encode images cleanly via Intervention Image v3 so EXIF, GPS, comments,
     * and any trailing polyglot payloads are physically removed.
     *
     * @return array{canonical_mime: string, canonical_path: string, derivatives: array<int, array<string, mixed>>, page_count: int, duration_seconds: null, staging_dir: string}
     */
    private function preprocessImage(string $sourcePath, string $originalMime, string $stagingDir): array
    {
        $maxDim = max(512, (int) config('ai_multimodal.max_image_dimension', 2048));
        $targetMime = $originalMime === 'image/png' ? 'image/png' : 'image/jpeg';
        $ext = $targetMime === 'image/png' ? 'png' : 'jpg';
        $outputPath = $stagingDir . DIRECTORY_SEPARATOR . 'canonical.' . $ext;

        try {
            $manager = $this->createImageManager();
            $image = $manager->read($sourcePath)->orient()->scaleDown($maxDim, $maxDim);
            $encoded = $targetMime === 'image/png'
                ? $image->toPng()
                : $image->toJpeg(88);

            $encoded->save($outputPath);
            $width = $image->width();
            $height = $image->height();
        } catch (Throwable) {
            throw MultimodalException::corruptedMedia('Image decoding failed during sanitization.');
        }

        return [
            'canonical_mime' => $targetMime,
            'canonical_path' => $outputPath,
            'derivatives' => [[
                'page' => 1,
                'local_path' => $outputPath,
                'mime' => $targetMime,
                'width' => $width,
                'height' => $height,
                'size_bytes' => (int) filesize($outputPath),
            ]],
            'page_count' => 1,
            'duration_seconds' => null,
            'staging_dir' => $stagingDir,
        ];
    }

    /**
     * @return array{canonical_mime: string, canonical_path: string, derivatives: array<int, array<string, mixed>>, page_count: int, duration_seconds: null, staging_dir: string}
     */
    private function preprocessPdf(string $sourcePath, string $stagingDir): array
    {
        $maxPages = max(1, (int) config('ai_multimodal.max_pdf_pages', 5));
        $dpi = max(100, min(300, (int) config('ai_multimodal.pdf_rasterizer.dpi', 175)));
        $maxDim = max(512, (int) config('ai_multimodal.max_image_dimension', 2048));

        $rasterDir = $stagingDir . DIRECTORY_SEPARATOR . 'pages';
        $rasterized = $this->pdfRasterizer->rasterize($sourcePath, $rasterDir, $maxPages, $dpi);

        $manager = $this->createImageManager();
        $derivatives = [];
        foreach ($rasterized['page_paths'] as $index => $pagePath) {
            $pageNum = $index + 1;
            $normalizedPath = $stagingDir . DIRECTORY_SEPARATOR . "page_{$pageNum}.jpg";
            $img = $manager->read($pagePath)->scaleDown($maxDim, $maxDim);
            $img->toJpeg(88)->save($normalizedPath);

            $derivatives[] = [
                'page' => $pageNum,
                'local_path' => $normalizedPath,
                'mime' => 'image/jpeg',
                'width' => $img->width(),
                'height' => $img->height(),
                'size_bytes' => (int) filesize($normalizedPath),
            ];
        }

        return [
            'canonical_mime' => 'application/pdf',
            'canonical_path' => $sourcePath,
            'derivatives' => $derivatives,
            'page_count' => (int) $rasterized['page_count'],
            'duration_seconds' => null,
            'staging_dir' => $stagingDir,
        ];
    }

    /**
     * @return array{canonical_mime: string, canonical_path: string, derivatives: array<int, array<string, mixed>>, page_count: null, duration_seconds: float, staging_dir: string}
     */
    private function preprocessAudio(string $sourcePath, string $originalMime, string $stagingDir): array
    {
        $maxDuration = max(5, (int) config('ai_multimodal.max_audio_seconds', 120));
        $wavPath = $stagingDir . DIRECTORY_SEPARATOR . 'canonical.wav';

        $transcoded = $this->audioTranscoder->transcodeToWav16kMono(
            sourcePath: $sourcePath,
            sourceMime: $originalMime,
            outputWavPath: $wavPath,
            maxDurationSeconds: $maxDuration,
        );

        return [
            'canonical_mime' => 'audio/wav',
            'canonical_path' => $transcoded['wav_path'],
            'derivatives' => [[
                'page' => 1,
                'local_path' => $transcoded['wav_path'],
                'mime' => 'audio/wav',
                'size_bytes' => (int) filesize($transcoded['wav_path']),
            ]],
            'page_count' => null,
            'duration_seconds' => (float) $transcoded['duration_seconds'],
            'staging_dir' => $stagingDir,
        ];
    }

    private function createImageManager(): ImageManager
    {
        if (extension_loaded('gd')) {
            return ImageManager::gd();
        }
        if (extension_loaded('imagick')) {
            return ImageManager::imagick();
        }

        throw MultimodalException::dependencyUnavailable('IMAGE_DRIVER_UNAVAILABLE', 'Neither GD nor Imagick extension is available for safe image normalization.');
    }
}

