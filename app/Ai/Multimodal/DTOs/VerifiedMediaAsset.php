<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\DTOs;

/**
 * Validated, sanitized, company-scoped media descriptor created after preprocessing.
 */
final readonly class VerifiedMediaAsset
{
    /**
     * @param array<int, array{page?: int, disk: string, key: string, mime: string, width?: int, height?: int, size_bytes?: int}> $derivatives
     */
    public function __construct(
        public string $sourceKind,
        public string $sanitizedFileName,
        public string $originalMime,
        public string $canonicalMime,
        public int $sizeBytes,
        public string $sha256,
        public string $privateDisk,
        public string $privateKey,
        public array $derivatives = [],
        public ?int $pageCount = null,
        public ?float $durationSeconds = null,
    ) {}
}

