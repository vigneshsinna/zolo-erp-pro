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
            (string) $row['id'],
            (string) $row['kind'],
            (int) $row['version'],
            (string) $row['status'],
            (string) $row['owner'],
            (string) $row['summary'],
            (array) $row['source_refs'],
            $row,
        );
    }

    public function isReviewed(): bool
    {
        return $this->status === 'reviewed'
            && !$this->isWithdrawnOrDeprecated()
            && !empty($this->data['reviewed_at'])
            && !empty($this->data['reviewed_by'])
            && !empty($this->data['signoff_evidence'])
            && !empty($this->data['domain_policy_ref']);
    }

    public function isWithdrawnOrDeprecated(): bool
    {
        return in_array($this->status, ['retired', 'deprecated', 'withdrawn'], true);
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

    /** @return list<string> */
    public function profiles(): array
    {
        return array_values(array_map('strval', (array) ($this->data['profiles'] ?? [])));
    }

    /** @return list<string> */
    public function subtypes(): array
    {
        return array_values(array_map('strval', (array) ($this->data['subtypes'] ?? [])));
    }

    /** @return list<string> */
    public function requiresCapabilities(): array
    {
        return array_values(array_map('strval', (array) ($this->data['requires_capabilities'] ?? [])));
    }

    public function effectiveFrom(): ?string
    {
        return isset($this->data['effective_from']) ? (string) $this->data['effective_from'] : null;
    }

    public function effectiveTo(): ?string
    {
        return isset($this->data['effective_to']) ? (string) $this->data['effective_to'] : null;
    }

    public function appliesToProfile(string $profile, ?string $subtype = null): bool
    {
        $profiles = $this->profiles();
        if ($profiles !== [] && !in_array($profile, $profiles, true)) {
            return false;
        }
        $subtypes = $this->subtypes();
        if ($subtypes !== [] && ($subtype === null || !in_array($subtype, $subtypes, true))) {
            return false;
        }

        return true;
    }

    public function isEffectiveOn(?string $date = null): bool
    {
        $target = $date ?? now('UTC')->toDateString();
        $from = $this->effectiveFrom();
        if ($from !== null && $target < $from) {
            return false;
        }
        $to = $this->effectiveTo();
        if ($to !== null && $target > $to) {
            return false;
        }

        return true;
    }
}
