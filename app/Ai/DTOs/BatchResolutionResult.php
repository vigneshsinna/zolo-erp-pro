<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

final class BatchResolutionResult
{
    /**
     * @param array<string, int|string> $resolvedIds
     * @param array<string, object> $entities
     * @param array<string, array<string, mixed>> $fieldResolutions
     */
    public function __construct(
        public array $resolvedIds = [],
        public array $entities = [],
        public array $fieldResolutions = [],
    ) {
    }

    public function getResolvedId(string $field): mixed
    {
        return $this->resolvedIds[$field] ?? null;
    }

    public function getEntity(string $field): ?object
    {
        return $this->entities[$field] ?? null;
    }

    public function getResolution(string $field): ?array
    {
        return $this->fieldResolutions[$field] ?? null;
    }

    public function setResolved(string $field, int|string $id, ?object $entity = null): void
    {
        $this->resolvedIds[$field] = $id;
        if ($entity !== null) {
            $this->entities[$field] = $entity;
        }
        $this->fieldResolutions[$field] = [
            'status' => 'exact',
            'candidates' => [],
            'resolved_id' => $id,
        ];
    }

    public function setCandidates(string $field, array $candidates, ?string $query = null): void
    {
        $this->fieldResolutions[$field] = [
            'status' => 'candidates',
            'candidates' => $candidates,
            'query' => $query,
        ];
    }

    public function setNone(string $field, ?string $query = null, ?string $error = null): void
    {
        $this->fieldResolutions[$field] = [
            'status' => 'none',
            'candidates' => [],
            'query' => $query,
            'error' => $error,
        ];
    }
}
