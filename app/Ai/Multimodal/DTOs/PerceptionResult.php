<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\DTOs;

use App\Ai\Multimodal\Enums\ModelPurpose;

/**
 * Structured result returned by a purpose-scoped perception model dispatch.
 */
final readonly class PerceptionResult
{
    /**
     * @param array<string, mixed> $parsedJson
     * @param array<int, string> $warnings
     */
    public function __construct(
        public string $rawText,
        public array $parsedJson,
        public string $provider,
        public string $model,
        public ModelPurpose $purpose,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $durationMs = 0,
        public ?string $finishReason = null,
        public array $warnings = [],
        public int $httpAttempts = 1,
    ) {}
}

