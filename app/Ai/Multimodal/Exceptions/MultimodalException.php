<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Structured, fail-closed exception for Phase 2.3 multimodal perception workflows.
 * Never embeds API keys, file system paths, or raw media binaries in exception messages.
 */
class MultimodalException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error_code' => $this->errorCode,
            'error_message' => $this->getMessage(),
            'details' => $this->details,
        ], $this->httpStatus);
    }

    public static function featureDisabled(string $message = 'Multimodal perception is disabled for this company.'): self
    {
        return new self('MULTIMODAL_DISABLED', $message, 503);
    }

    public static function permissionDenied(string $message = 'You do not have permission to use AI multimodal tools.'): self
    {
        return new self('PERMISSION_DENIED', $message, 403);
    }

    public static function crossTenantForbidden(string $message = 'Media asset does not belong to the active company or actor.'): self
    {
        return new self('CROSS_TENANT_FORBIDDEN', $message, 403);
    }

    public static function mediaNotFound(string $message = 'Requested media asset was not found or has expired.'): self
    {
        return new self('MEDIA_NOT_FOUND', $message, 404);
    }

    public static function mediaExpired(string $message = 'Media asset has expired and can no longer be processed.'): self
    {
        return new self('MEDIA_EXPIRED', $message, 410);
    }

    public static function remoteUrlForbidden(): self
    {
        return new self('REMOTE_URL_FORBIDDEN', 'Remote URLs are not accepted. Upload media directly.', 422);
    }

    public static function unsupportedMime(string $mime): self
    {
        return new self('UNSUPPORTED_MEDIA_TYPE', "Unsupported media format ({$mime}). Upload PNG, JPEG, PDF, WAV, MP3, WebM, FLAC, or OGG.", 415);
    }

    public static function invalidMagicBytes(string $message = 'Uploaded file contents do not match an allowed media signature.'): self
    {
        return new self('INVALID_MAGIC_BYTES', $message, 422);
    }

    public static function oversizedPayload(string $message): self
    {
        return new self('PAYLOAD_TOO_LARGE', $message, 413);
    }

    public static function encryptedPdf(): self
    {
        return new self('ENCRYPTED_PDF_UNSUPPORTED', 'Password-protected or encrypted PDFs are not supported.', 422);
    }

    public static function tooManyPages(int $pages, int $max): self
    {
        return new self('PDF_PAGE_LIMIT_EXCEEDED', "PDF has {$pages} pages, exceeding the {$max}-page limit.", 422);
    }

    public static function excessivePixels(string $message = 'Image dimensions exceed the maximum allowed pixel budget.'): self
    {
        return new self('IMAGE_PIXELS_EXCEEDED', $message, 422);
    }

    public static function corruptedMedia(string $message = 'Uploaded media file appears corrupted or unreadable.'): self
    {
        return new self('CORRUPTED_MEDIA', $message, 422);
    }

    public static function audioTooLong(float $seconds, int $maxSeconds): self
    {
        return new self('AUDIO_DURATION_EXCEEDED', sprintf('Audio recording duration %.1fs exceeds the %ds limit.', $seconds, $maxSeconds), 422);
    }

    public static function silentAudio(): self
    {
        return new self('SILENT_OR_EMPTY_AUDIO', 'Audio contains no detectable speech or has near-zero energy.', 422);
    }

    public static function dailyLimitExceeded(int $limit): self
    {
        return new self('DAILY_MEDIA_LIMIT_EXCEEDED', "Daily company media upload limit ({$limit}) has been reached.", 429);
    }

    public static function rateLimited(int $retryAfter = 30, string $message = 'AI provider rate limit reached. Please retry shortly.'): self
    {
        return new self('RATE_LIMITED', $message, 429, ['retry_after' => $retryAfter]);
    }

    public static function providerUnavailable(string $message = 'Multimodal perception service is temporarily unavailable.'): self
    {
        return new self('PERCEPTION_PROVIDER_UNAVAILABLE', $message, 503);
    }

    public static function dependencyUnavailable(string $code, string $message): self
    {
        return new self($code, $message, 422);
    }

    public static function extractionFailed(string $code, string $message, int $httpStatus = 422, array $details = []): self
    {
        return new self($code, $message, $httpStatus, $details);
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     */
    public static function allPagesFailed(
        int $pageCount,
        array $failures,
        bool $isRetryable = true,
        ?string $mediaId = null,
        string $message = 'Document extraction failed across all pages.',
    ): self {
        return new self('ALL_PAGES_FAILED', $message, 422, [
            'total_pages' => $pageCount,
            'failures' => $failures,
            'is_retryable' => $isRetryable,
            'media_id' => $mediaId,
        ]);
    }

    public static function idempotencyConflict(string $message = 'Idempotency key was previously used with different file contents.'): self
    {
        return new self('IDEMPOTENCY_CONFLICT', $message, 409);
    }

    public static function revisionConflict(int $expected, int $actual): self
    {
        return new self('REVISION_CONFLICT', 'Extraction was updated by another request. Reload the latest revision.', 409, [
            'expected_revision' => $expected,
            'current_revision' => $actual,
        ]);
    }

    public static function capabilityUnavailable(string $intentKey, string $message): self
    {
        return new self('CAPABILITY_UNAVAILABLE', $message, 422, ['intent_key' => $intentKey]);
    }
}
