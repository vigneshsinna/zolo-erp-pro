<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

final readonly class EvaluationResult
{
    /**
     * @param list<FieldIssue> $issues
     * @param list<array<string, mixed>> $confirmedFields
     * @param list<string> $pendingChecks
     * @param array<string, mixed> $validatedSlots
     */
    public function __construct(
        public array $issues = [],
        public array $confirmedFields = [],
        public array $pendingChecks = [],
        public array $validatedSlots = [],
    ) {
    }

    public function hasBlockingIssues(): bool
    {
        return !empty($this->issues);
    }

    public function isReady(): bool
    {
        return empty($this->issues);
    }

    /**
     * @return list<FieldIssue>
     */
    public function blockingIssues(): array
    {
        return $this->issues;
    }

    public function firstBlockingIssue(): ?FieldIssue
    {
        return $this->issues[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issuesArray(): array
    {
        return array_map(fn (FieldIssue $i) => $i->toArray(), $this->issues);
    }

    public function toArray(): array
    {
        return [
            'is_ready' => $this->isReady(),
            'confirmed_fields' => $this->confirmedFields,
            'required_inputs' => $this->issuesArray(),
            'pending_checks' => $this->pendingChecks,
        ];
    }
}
