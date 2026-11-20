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
        return $this->status === 'reviewed';
    }
}
