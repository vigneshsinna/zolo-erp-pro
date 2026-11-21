<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

/** Immutable descriptive metadata; this is never an authorization token. */
final readonly class KnowledgeDefinition
{
    public function __construct(
        public string $id,
        public string $kind,
        public int $version,
        public string $status,
        public string $owner,
        public string $summary,
        public array $sourceRefs,
        public array $data,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['id'], (string) $row['kind'], (int) $row['version'],
            (string) $row['status'], (string) $row['owner'], (string) $row['summary'],
            (array) $row['source_refs'], $row,
        );
    }

    public function isReviewed(): bool
    {
        return $this->status === 'reviewed'
            && !empty($this->data['reviewed_at'])
            && !empty($this->data['reviewed_by'])
            && !empty($this->data['signoff_evidence'])
            && !empty($this->data['domain_policy_ref']);
    }

    public function domainPolicyRef(): ?string
    {
        return $this->data['domain_policy_ref'] ?? null;
    }

    public function signoffEvidence(): mixed
    {
        return $this->data['signoff_evidence'] ?? null;
    }

    public function rollbackPolicy(): ?string
    {
        return $this->data['rollback_policy'] ?? null;
    }

    public function impactedWorkflows(): array
    {
        return (array) ($this->data['impacted_workflows'] ?? []);
    }
}
