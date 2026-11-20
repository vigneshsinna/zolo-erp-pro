<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use InvalidArgumentException;

/** Deployment/read-time validation; deliberately contains no execution hooks. */
final class KnowledgeDefinitionValidator
{
    private const KINDS = ['entity', 'relationship', 'policy', 'action', 'process'];
    private const STATUSES = ['draft', 'reviewed', 'retired'];
    private const STEP_KINDS = ['read_or_clarify', 'reviewed_action', 'external_event', 'read_only_validation', 'unsupported_action'];

    public function validate(array $document, array $manifest, string $repositoryRoot): void
    {
        $this->assert(($document['schema_version'] ?? null) === 1, 'Knowledge schema version must be 1.');
        $this->assert(($manifest['schema_version'] ?? null) === 1, 'Manifest schema version must be 1.');
        $rows = $document['definitions'] ?? null;
        $this->assert(is_array($rows) && array_is_list($rows) && $rows !== [], 'Definitions must be a non-empty list.');
        $known = [];
        $actions = [];
        foreach ($rows as $row) {
            $this->assert(is_array($row), 'Definition must be an object.');
            foreach (['id', 'kind', 'version', 'status', 'owner', 'summary', 'source_refs', 'effective_from'] as $field) {
                $this->assert(array_key_exists($field, $row), "Definition missing {$field}.");
            }
            $id = (string) $row['id'];
            $kind = (string) $row['kind'];
            $this->assert((bool) preg_match('/^(entity|relationship|policy|action|process)\.[a-z0-9_.]+\.v[1-9][0-9]*$/D', $id), "Invalid semantic ID {$id}.");
            $this->assert(str_starts_with($id, $kind.'.'), "Kind mismatch for {$id}.");
            $this->assert(!isset($known[$id]), "Duplicate semantic ID {$id}.");
            $this->assert(in_array($kind, self::KINDS, true), "Unknown kind {$kind}.");
            $this->assert(in_array($row['status'], self::STATUSES, true), "Unknown review status for {$id}.");
            $this->assert(is_int($row['version']) && $row['version'] > 0, "Invalid version for {$id}.");
            $this->assert(is_string($row['owner']) && trim($row['owner']) !== '', "Missing owner for {$id}.");
            $this->assert(is_string($row['summary']) && trim($row['summary']) !== '', "Missing summary for {$id}.");
            $this->assert(is_string($row['effective_from']) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['effective_from']), "Invalid effective date for {$id}.");
            if ($row['status'] === 'reviewed') {
                $this->assert(!empty($row['reviewed_at']) && !empty($row['reviewed_by']), "Reviewed definition {$id} needs signoff.");
            }
            $sources = $row['source_refs'];
            $this->assert(is_array($sources) && array_is_list($sources) && $sources !== [], "Missing source refs for {$id}.");
            foreach ($sources as $source) {
                $this->assert(is_string($source) && (bool) preg_match('/^app\/[A-Za-z0-9_\/.-]+\.php$/D', $source) && !str_contains($source, '..'), "Unsafe source path for {$id}.");
                $this->assert(in_array($source, $manifest['sources'] ?? [], true), "Unmanifested source {$source}.");
                $this->assert(is_file(rtrim($repositoryRoot, '/').'/'.$source), "Missing source {$source}.");
            }
            if ($kind === 'action') {
                $this->assert(is_string($row['action_key'] ?? null) && (bool) preg_match('/^[a-z0-9_.]+\.v[1-9][0-9]*$/D', $row['action_key']), "Invalid action key for {$id}.");
                $this->assert(!isset($actions[$row['action_key']]), "Duplicate action key {$row['action_key']}.");
                $actions[$row['action_key']] = $id;
            }
            $known[$id] = $row;
        }
        foreach ($known as $id => $row) {
            if ($row['kind'] === 'relationship') {
                foreach (['from', 'to'] as $edge) {
                    $target = $row[$edge] ?? null;
                    $this->assert(is_string($target) && isset($known[$target]) && $known[$target]['kind'] === 'entity', "Broken relationship {$id}: {$edge}.");
                }
            }
            if ($row['kind'] === 'process') $this->validateSteps($id, $row['steps'] ?? null, $actions);
            foreach ($row['policy_refs'] ?? [] as $ref) {
                $this->assert(isset($known[$ref]) && $known[$ref]['kind'] === 'policy', "Unknown policy reference {$ref}.");
            }
        }
    }

    private function validateSteps(string $id, mixed $steps, array $actions): void
    {
        $this->assert(is_array($steps) && array_is_list($steps) && $steps !== [], "Process {$id} has no steps.");
        $byId = [];
        foreach ($steps as $step) {
            $key = $step['id'] ?? null;
            $this->assert(is_string($key) && (bool) preg_match('/^[a-z][a-z0-9_]*$/D', $key) && !isset($byId[$key]), "Invalid/duplicate step in {$id}.");
            $this->assert(in_array($step['kind'] ?? null, self::STEP_KINDS, true), "Unknown step type in {$id}.");
            $this->assert(is_array($step['depends_on'] ?? null), "Missing dependencies in {$id}.");
            if ($step['kind'] === 'reviewed_action') {
                $this->assert(isset($actions[$step['action_key'] ?? '']), "Unknown action descriptor in {$id}.");
            } else {
                $this->assert(!isset($step['action_key']), "Non-action step has action key in {$id}.");
            }
            $byId[$key] = $step;
        }
        $visited = [];
        $walk = function (string $stepId) use (&$walk, &$visited, $byId, $id): void {
            if (($visited[$stepId] ?? 0) === 1) throw new InvalidArgumentException("Cycle detected in {$id}.");
            if (($visited[$stepId] ?? 0) === 2) return;
            $visited[$stepId] = 1;
            foreach ($byId[$stepId]['depends_on'] as $dep) {
                $this->assert(is_string($dep) && isset($byId[$dep]), "Unknown step dependency in {$id}.");
                $walk($dep);
            }
            $visited[$stepId] = 2;
        };
        foreach (array_keys($byId) as $key) $walk($key);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) throw new InvalidArgumentException($message);
    }
}
