<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\DTOs;

/**
 * Canonical `normalized.input.v1` intermediate representation bridging
 * untrusted perception extractions with deterministic ERP workflow validation.
 */
final readonly class NormalizedInputV1
{
    /**
     * @param array<string, mixed> $slots
     * @param array<string, mixed> $sourceProvenance
     * @param array<int, array<string, mixed>> $conflicts
     * @param array<int, string> $warnings
     */
    public function __construct(
        public string $schemaVersion,
        public ?string $intentKey,
        public bool $isActionable,
        public bool $isVerifiedByErp,
        public array $slots,
        public array $sourceProvenance,
        public array $conflicts = [],
        public array $warnings = [],
        public ?string $capabilityUnavailableReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'intent_key' => $this->intentKey,
            'is_actionable' => $this->isActionable,
            'is_verified_by_erp' => $this->isVerifiedByErp,
            'slots' => $this->slots,
            'source_provenance' => $this->sourceProvenance,
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
            'capability_unavailable_reason' => $this->capabilityUnavailableReason,
        ];
    }
}

