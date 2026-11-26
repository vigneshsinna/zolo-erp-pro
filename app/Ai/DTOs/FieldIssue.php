<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

final readonly class FieldIssue
{
    /**
     * @param list<array<string, mixed>> $candidates
     * @param list<ResolutionOption|array<string, mixed>> $resolutionOptions
     */
    public function __construct(
        public string $field,
        public string $label,
        public string $status, // missing, invalid, ambiguous, blocked_by_dependency, unverified
        public string $reason,
        public string $control = 'text',
        public array $candidates = [],
        public ?string $clientLineId = null,
        public array $resolutionOptions = [],
        public ?string $entityType = null,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'field' => $this->field,
            'label' => $this->label,
            'status' => $this->status,
            'reason' => $this->reason,
            'control' => $this->control,
            'candidates' => $this->candidates,
        ];

        if ($this->clientLineId !== null) {
            $data['client_line_id'] = $this->clientLineId;
        }

        if (!empty($this->resolutionOptions)) {
            $data['resolution_options'] = array_map(
                static fn ($opt): array => $opt instanceof ResolutionOption ? $opt->toArray() : (array) $opt,
                $this->resolutionOptions
            );
        }

        if ($this->entityType !== null) {
            $data['entity_type'] = $this->entityType;
        }

        return $data;
    }
}
