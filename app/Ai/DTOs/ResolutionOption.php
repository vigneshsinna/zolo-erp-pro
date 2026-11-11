<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Represents an actionable resolution option for an unresolved master slot in Phase 2.2.
 */
final readonly class ResolutionOption
{
    /**
     * @param array<string, mixed> $prefilledSlots
     */
    public function __construct(
        public string $key, // select_existing, create_new, correct_name
        public string $label,
        public ?string $description = null,
        public ?string $capabilityKey = null,
        public bool $canCreate = true,
        public ?string $disabledReason = null,
        public ?string $searchEndpoint = null,
        public array $prefilledSlots = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'capability_key' => $this->capabilityKey,
            'can_create' => $this->canCreate,
            'disabled_reason' => $this->disabledReason,
            'search_endpoint' => $this->searchEndpoint,
            'prefilled_slots' => !empty($this->prefilledSlots) ? $this->prefilledSlots : null,
        ], static fn ($val): bool => $val !== null);
    }
}
