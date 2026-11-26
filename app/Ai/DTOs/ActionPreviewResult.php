<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/** A deterministic, domain-calculated preview, not an LLM financial estimate. */
final readonly class ActionPreviewResult
{
    public function __construct(
        public array $payload,
        public array $preview,
        public string $sourceStateHash,
        public array $warnings = [],
    ) {}
}
