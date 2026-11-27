<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use InvalidArgumentException;

/**
 * Static, versioned, modular descriptive catalog.
 * Combines legacy definitions.json with core and industry packs deterministically.
 * Draft data never grants action access.
 */
final class BusinessKnowledgeRegistry
{
    private ?array $definitions = null;
    private ?string $compiledHash = null;

    public function __construct(
        private readonly ?string $root = null,
        private readonly ?KnowledgeDefinitionValidator $validator = null,
    ) {}

    /** @return array<string, KnowledgeDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $root = $this->root ?? base_path();
        $dir = rtrim($root, '/\\') . '/resources/ai/knowledge/';
        $document = $this->readJson($dir . 'definitions.json');
        $manifest = $this->readJson($dir . 'source_manifest.json');

        $rows = is_array($document['definitions'] ?? null) ? $document['definitions'] : [];
        $modularSchemaVersion = $document['schema_version'] ?? 1;

        foreach ($this->modularFiles($dir) as $modulePath) {
            $pack = $this->readJson($modulePath);
            if (($pack['schema_version'] ?? 1) === 2) {
                $modularSchemaVersion = 2;
            }
            $packRows = $pack['definitions'] ?? [];
            if (!is_array($packRows) || !array_is_list($packRows)) {
                throw new InvalidArgumentException("Knowledge module {$modulePath} definitions must be a list.");
            }
            foreach ($packRows as $row) {
                $rows[] = $row;
            }
        }

        $combinedDocument = [
            'schema_version' => $modularSchemaVersion,
            'definitions' => $rows,
        ];

        ($this->validator ?? new KnowledgeDefinitionValidator)->validate($combinedDocument, $manifest, $root);

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        $indexed = [];
        foreach ($rows as $row) {
            $value = KnowledgeDefinition::fromArray($row);
            $indexed[$value->id] = $value;
        }

        $this->compiledHash = hash('sha256', json_encode($this->canonicalSort([
            'schema_version' => $modularSchemaVersion,
            'definitions' => $rows,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->definitions = $indexed;
    }

    public function find(string $id, bool $reviewedOnly = false): ?KnowledgeDefinition
    {
        $value = $this->all()[$id] ?? null;
        return $value && (!$reviewedOnly || $value->isReviewed()) ? $value : null;
    }

    /** @return list<KnowledgeDefinition> */
    public function forAction(
        string $key,
        bool $reviewedOnly = true,
        ?string $profile = null,
        ?string $subtype = null,
        ?string $asOfDate = null,
    ): array {
        return array_values(array_filter(
            $this->all(),
            fn (KnowledgeDefinition $d): bool => $d->kind === 'action'
                && ($d->data['action_key'] ?? null) === $key
                && $d->isEffectiveOn($asOfDate)
                && !$d->isWithdrawnOrDeprecated()
                && ($profile === null || $d->appliesToProfile($profile, $subtype))
                && (!$reviewedOnly || $d->isReviewed())
        ));
    }

    /** @return list<KnowledgeDefinition> */
    public function forProfile(
        string $profile,
        ?string $subtype = null,
        bool $reviewedOnly = false,
        ?string $asOfDate = null,
    ): array {
        return array_values(array_filter(
            $this->all(),
            fn (KnowledgeDefinition $d): bool => $d->appliesToProfile($profile, $subtype)
                && $d->isEffectiveOn($asOfDate)
                && !$d->isWithdrawnOrDeprecated()
                && (!$reviewedOnly || $d->isReviewed())
        ));
    }

    /** @return array<string, array<string, mixed>> */
    public function profiles(): array
    {
        $root = $this->root ?? base_path();
        $dir = rtrim($root, '/\\') . '/resources/ai/knowledge/profiles/';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '*.json') ?: [];
        sort($files);
        $profiles = [];
        foreach ($files as $file) {
            $data = $this->readJson($file);
            $key = (string) ($data['profile_key'] ?? basename($file, '.json'));
            $profiles[$key] = $data;
        }

        return $profiles;
    }

    public function profileDefinition(string $key): ?array
    {
        return $this->profiles()[$key] ?? null;
    }

    public function versionHash(): string
    {
        $this->all();
        return (string) $this->compiledHash;
    }

    /** @return list<string> */
    private function modularFiles(string $knowledgeDir): array
    {
        $files = [];
        foreach (['core', 'industries'] as $group) {
            $base = $knowledgeDir . $group;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        sort($files);

        return $files;
    }

    private function canonicalSort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalSort($item), $value);
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Missing knowledge catalog {$path}.");
        }
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Knowledge catalog must be an object.');
        }

        return $decoded;
    }
}
