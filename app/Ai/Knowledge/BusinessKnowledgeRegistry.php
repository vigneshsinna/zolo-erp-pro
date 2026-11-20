<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use InvalidArgumentException;

/** Static, versioned, descriptive catalog. Draft data never grants action access. */
final class BusinessKnowledgeRegistry
{
    private ?array $definitions = null;

    public function __construct(
        private readonly ?string $root = null,
        private readonly ?KnowledgeDefinitionValidator $validator = null,
    ) {}

    /** @return array<string, KnowledgeDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) return $this->definitions;
        $root = $this->root ?? base_path();
        $dir = $root.'/resources/ai/knowledge/';
        $document = $this->readJson($dir.'definitions.json');
        $manifest = $this->readJson($dir.'source_manifest.json');
        ($this->validator ?? new KnowledgeDefinitionValidator)->validate($document, $manifest, $root);
        $indexed = [];
        foreach ($document['definitions'] as $row) {
            $value = KnowledgeDefinition::fromArray($row);
            $indexed[$value->id] = $value;
        }
        return $this->definitions = $indexed;
    }

    public function find(string $id, bool $reviewedOnly = false): ?KnowledgeDefinition
    {
        $value = $this->all()[$id] ?? null;
        return $value && (!$reviewedOnly || $value->isReviewed()) ? $value : null;
    }

    /** @return list<KnowledgeDefinition> */
    public function forAction(string $key, bool $reviewedOnly = true): array
    {
        return array_values(array_filter($this->all(),
            fn (KnowledgeDefinition $d): bool => $d->kind === 'action'
                && ($d->data['action_key'] ?? null) === $key
                && (!$reviewedOnly || $d->isReviewed())));
    }

    public function versionHash(): string
    {
        $root = $this->root ?? base_path();
        $this->all();
        return hash('sha256', (string) file_get_contents($root.'/resources/ai/knowledge/definitions.json'));
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) throw new InvalidArgumentException("Missing knowledge catalog {$path}.");
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new InvalidArgumentException('Knowledge catalog must be an object.');
        return $decoded;
    }
}
