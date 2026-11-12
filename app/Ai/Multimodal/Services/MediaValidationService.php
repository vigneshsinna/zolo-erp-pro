<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Multimodal\Exceptions\MultimodalException;
use Illuminate\Http\UploadedFile;
use SplFileInfo;

/**
 * Validates uploaded media strictly by binary magic bytes, payload size caps,
 * pixel dimensions before decode, PDF encryption guards, and safe filenames.
 */
final class MediaValidationService
{
    /**
     * Validate an uploaded file and classify its true binary format.
     *
     * @return array{
     *   source_kind: string,
     *   original_mime: string,
     *   sanitized_name: string,
     *   size_bytes: int,
     *   sha256: string,
     *   width: ?int,
     *   height: ?int
     * }
     */
    public function validateUploadedFile(
        UploadedFile|SplFileInfo $file,
        ?string $declaredMime = null,
        ?string $originalName = null,
    ): array {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw MultimodalException::corruptedMedia('Uploaded media file could not be read from staging storage.');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw MultimodalException::corruptedMedia('Uploaded media file is empty.');
        }

        $rawName = $originalName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename());
        $sanitizedName = $this->sanitizeFileName($rawName);

        if ($declaredMime === null && $file instanceof UploadedFile) {
            $declaredMime = $file->getClientMimeType();
        }
        $normalizedDeclared = $this->normalizeMime($declaredMime);
        if ($normalizedDeclared !== null && $this->isExplicitlyBlockedMime($normalizedDeclared)) {
            throw MultimodalException::unsupportedMime($normalizedDeclared);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw MultimodalException::corruptedMedia('Unable to inspect uploaded media header.');
        }
        $head = fread($handle, 4096) ?: '';
        fclose($handle);

        $this->rejectDangerousSignatures($head);

        $detectedMime = $this->sniffMagicBytes($head, $path);
        if ($detectedMime === null) {
            $finfoMime = $this->sniffFinfo($path);
            if ($finfoMime !== null) {
                $detectedMime = $finfoMime;
            }
        }

        if ($detectedMime === null || ! $this->isAllowedCanonicalMime($detectedMime)) {
            throw MultimodalException::invalidMagicBytes('File signature is missing or not an allowed PNG, JPEG, PDF, WAV, WebM, MP3, FLAC, or OGG format.');
        }

        if ($normalizedDeclared !== null
            && $normalizedDeclared !== 'application/octet-stream'
            && ! $this->mimesAreCompatible($normalizedDeclared, $detectedMime)) {
            throw MultimodalException::invalidMagicBytes("Declared media type ({$normalizedDeclared}) does not match file signature ({$detectedMime}).");
        }

        $sourceKind = $this->classifySourceKind($detectedMime);
        $this->enforceByteLimit($sourceKind, $size);

        $width = null;
        $height = null;

        if ($sourceKind === 'image') {
            $info = @getimagesize($path);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                throw MultimodalException::corruptedMedia('Image header could not be decoded safely.');
            }
            $width = (int) $info[0];
            $height = (int) $info[1];
            $maxPixels = (int) config('ai_multimodal.max_image_pixels', 20_000_000);
            if (($width * $height) > $maxPixels || $width > 20000 || $height > 20000) {
                throw MultimodalException::excessivePixels("Image dimensions ({$width}x{$height}) exceed the maximum pixel limit.");
            }
        } elseif ($sourceKind === 'pdf') {
            $this->inspectPdfSecurity($path, $head);
        }

        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw MultimodalException::corruptedMedia('Unable to compute SHA-256 digest for uploaded file.');
        }

        return [
            'source_kind' => $sourceKind,
            'original_mime' => $detectedMime,
            'sanitized_name' => $sanitizedName,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'width' => $width,
            'height' => $height,
        ];
    }

    public function sanitizeFileName(string $name): string
    {
        $base = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name));
        $cleaned = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'media_upload';
        $cleaned = trim($cleaned, '._');
        if ($cleaned === '') {
            $cleaned = 'media_upload';
        }

        return substr($cleaned, 0, 180);
    }

    private function normalizeMime(?string $mime): ?string
    {
        if ($mime === null) {
            return null;
        }
        $base = strtolower(trim(explode(';', $mime)[0]));

        return $base === '' ? null : $base;
    }

    private function isExplicitlyBlockedMime(string $mime): bool
    {
        return in_array($mime, [
            'image/svg+xml',
            'text/html',
            'application/xhtml+xml',
            'application/javascript',
            'text/javascript',
            'application/x-msdownload',
            'application/x-executable',
            'application/x-php',
            'text/x-php',
        ], true);
    }

    private function rejectDangerousSignatures(string $head): void
    {
        $lower = strtolower(ltrim($head));
        foreach (['<svg', '<?php', '<!doctype html', '<html', '<script', '%!ps-'] as $prefix) {
            if (str_contains($lower, $prefix)) {
                throw MultimodalException::invalidMagicBytes('Active markup, vector SVG, or script content is forbidden in AI uploads.');
            }
        }

        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF") || str_starts_with($head, "PK\x03\x04")) {
            throw MultimodalException::invalidMagicBytes('Executable or archive binaries are forbidden.');
        }
    }

    private function sniffMagicBytes(string $head, string $path): ?string
    {
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        $pdfPos = strpos(substr($head, 0, 1024), '%PDF-');
        if ($pdfPos !== false) {
            return 'application/pdf';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }
        if (str_starts_with($head, "\x1a\x45\xdf\xa3")) {
            return 'audio/webm';
        }
        if (str_starts_with($head, 'OggS')) {
            return 'audio/ogg';
        }
        if (str_starts_with($head, 'fLaC')) {
            return 'audio/flac';
        }
        if (str_starts_with($head, 'ID3') || $this->isMp3FrameSync($head)) {
            return 'audio/mpeg';
        }

        // Fall back to finfo only for recognized non-executable media
        $finfo = $this->sniffFinfo($path);
        if ($finfo !== null && $this->isAllowedCanonicalMime($finfo)) {
            return $finfo;
        }

        return null;
    }

    private function isMp3FrameSync(string $head): bool
    {
        if (strlen($head) < 2) {
            return false;
        }
        $b0 = ord($head[0]);
        $b1 = ord($head[1]);

        return $b0 === 0xFF && ($b1 & 0xE0) === 0xE0;
    }

    private function sniffFinfo(string $path): ?string
    {
        if (! function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $this->normalizeMime($mime) : null;
    }

    private function isAllowedCanonicalMime(string $mime): bool
    {
        $allowed = array_merge(
            (array) config('ai_multimodal.allowed_image_mimes', ['image/jpeg', 'image/png']),
            (array) config('ai_multimodal.allowed_pdf_mimes', ['application/pdf']),
            (array) config('ai_multimodal.allowed_audio_mimes', ['audio/wav', 'audio/webm', 'audio/mpeg', 'audio/flac', 'audio/ogg']),
        );

        return in_array($mime, $allowed, true);
    }

    private function mimesAreCompatible(string $declared, string $detected): bool
    {
        if ($declared === $detected) {
            return true;
        }

        $aliases = [
            'image/jpg' => 'image/jpeg',
            'image/pjpeg' => 'image/jpeg',
            'video/webm' => 'audio/webm',
            'audio/x-wav' => 'audio/wav',
            'audio/wave' => 'audio/wav',
            'audio/vnd.wave' => 'audio/wav',
            'audio/mp3' => 'audio/mpeg',
            'audio/x-flac' => 'audio/flac',
            'application/ogg' => 'audio/ogg',
            'audio/webm;codecs=opus' => 'audio/webm',
        ];

        $canonicalDeclared = $aliases[$declared] ?? $declared;
        $canonicalDetected = $aliases[$detected] ?? $detected;

        return $canonicalDeclared === $canonicalDetected;
    }

    private function classifySourceKind(string $mime): string
    {
        if (in_array($mime, (array) config('ai_multimodal.allowed_image_mimes', []), true)) {
            return 'image';
        }
        if (in_array($mime, (array) config('ai_multimodal.allowed_pdf_mimes', []), true)) {
            return 'pdf';
        }

        return 'audio';
    }

    private function enforceByteLimit(string $sourceKind, int $size): void
    {
        $limit = match ($sourceKind) {
            'pdf' => (int) config('ai_multimodal.max_pdf_bytes', 20_971_520),
            'audio' => (int) config('ai_multimodal.max_audio_bytes', 20_971_520),
            default => (int) config('ai_multimodal.max_input_bytes', 10_485_760),
        };

        if ($size > $limit) {
            $mb = round($limit / 1048576, 1);
            throw MultimodalException::oversizedPayload("Uploaded {$sourceKind} exceeds maximum allowed size ({$mb} MB).");
        }
    }

    private function inspectPdfSecurity(string $path, string $head): void
    {
        $contents = @file_get_contents($path, false, null, 0, min(524288, (int) filesize($path)));
        if (! is_string($contents)) {
            throw MultimodalException::corruptedMedia('Uploaded PDF could not be read.');
        }

        if (preg_match('/\/Encrypt\b/', $contents)) {
            throw MultimodalException::encryptedPdf();
        }

        if (preg_match('/\/(?:JavaScript|JS|Launch|OpenAction|RichMedia)\b/', $head . $contents)) {
            throw MultimodalException::invalidMagicBytes('Active scripts, launch actions, or embedded JavaScript are forbidden in uploaded PDFs.');
        }
    }
}

