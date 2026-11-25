<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\Registries\ActionCapabilityRegistry;
use InvalidArgumentException;

/** Deployment/read-time validation; deliberately contains no execution hooks. */
final class KnowledgeDefinitionValidator
{
    private const KINDS = ['entity', 'relationship', 'policy', 'action', 'process'];
    private const STATUSES = ['draft', 'reviewed', 'retired', 'deprecated', 'withdrawn'];
    private const STEP_KINDS = ['read_or_clarify', 'reviewed_action', 'external_event', 'read_only_validation', 'unsupported_action'];
    private const ALLOWED_OWNERS = [
        'purchasing', 'finance', 'finance-domain', 'inventory', 'manufacturing',
        'operations', 'security', 'product', 'sales', 'catalog', 'accounting', 'erp-platform',
        'optical-commercial', 'optical-lab', 'optical-inventory', 'commercial',
    ];
    private const UNSUPPORTED_PROCESSES = [
        'process.expense_payable.v1',
    ];

    public function __construct(
        private readonly ?ActionCapabilityRegistry $capabilities = null,
    ) {}

    public function validate(array $document, array $manifest, string $repositoryRoot): void
    {
        $schemaVersion = $document['schema_version'] ?? null;
        $this->assert(in_array($schemaVersion, [1, 2], true), 'Knowledge schema version must be 1.');
        $this->assert(in_array($manifest['schema_version'] ?? null, [1, 2], true), 'Manifest schema version must be 1.');
        $rows = $document['definitions'] ?? null;
        $this->assert(is_array($rows) && array_is_list($rows) && $rows !== [], 'Definitions must be a non-empty list.');
        $known = [];
        $actions = [];
        $aliasesByScope = [];

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
            $this->assert(is_string($row['owner']) && in_array(trim($row['owner']), self::ALLOWED_OWNERS, true), "Invalid or unassigned owner for {$id}.");
            $this->assert(is_string($row['summary']) && trim($row['summary']) !== '', "Missing summary for {$id}.");
            $this->assert(is_string($row['effective_from']) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['effective_from']), "Invalid effective date for {$id}.");

            if (array_key_exists('effective_to', $row) && $row['effective_to'] !== null) {
                $this->assert(
                    is_string($row['effective_to'])
                    && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['effective_to'])
                    && $row['effective_to'] >= $row['effective_from'],
                    "Invalid effective_to date for {$id}."
                );
            }

            if (array_key_exists('profiles', $row)) {
                $this->assert(is_array($row['profiles']) && array_is_list($row['profiles']), "Invalid profiles list for {$id}.");
            }
            if (array_key_exists('subtypes', $row)) {
                $this->assert(is_array($row['subtypes']) && array_is_list($row['subtypes']), "Invalid subtypes list for {$id}.");
            }
            if (array_key_exists('requires_capabilities', $row)) {
                $this->assert(is_array($row['requires_capabilities']) && array_is_list($row['requires_capabilities']), "Invalid requires_capabilities list for {$id}.");
            }

            if (!empty($row['aliases']) && is_array($row['aliases'])) {
                $profileScopes = !empty($row['profiles']) ? $row['profiles'] : ['*'];
                foreach ($profileScopes as $profileScope) {
                    foreach ($row['aliases'] as $alias) {
                        $normalized = mb_strtolower(trim((string) $alias));
                        if ($normalized === '') {
                            continue;
                        }
                        $scopeKey = $kind.':'.$profileScope.':'.$normalized;
                        $this->assert(!isset($aliasesByScope[$scopeKey]), "Duplicate alias '{$alias}' in scope {$kind}:{$profileScope} on {$id}.");
                        $aliasesByScope[$scopeKey] = $id;
                    }
                }
            }

            if ($row['status'] === 'reviewed') {
                $this->assert(!empty($row['reviewed_at']) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}/D', (string) $row['reviewed_at']), "Reviewed definition {$id} needs signoff.");
                $this->assert(!empty($row['reviewed_by']) && is_string($row['reviewed_by']), "Reviewed definition {$id} needs signoff.");
                $this->assert(!in_array($id, self::UNSUPPORTED_PROCESSES, true), "Unsupported process {$id} cannot be marked reviewed.");
                $this->assert(!empty($row['domain_policy_ref']) && is_string($row['domain_policy_ref']), "Reviewed definition {$id} requires domain_policy_ref.");
                $this->assert(!empty($row['signoff_evidence']), "Reviewed definition {$id} requires signoff_evidence.");
                $this->assert(array_key_exists('impacted_workflows', $row) && is_array($row['impacted_workflows']), "Reviewed definition {$id} requires impacted_workflows list.");
                $this->assert(!empty($row['rollback_policy']) && is_string($row['rollback_policy']), "Reviewed definition {$id} requires rollback_policy.");
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

                if ($row['status'] === 'reviewed' && $this->capabilities !== null) {
                    $this->assert($this->capabilities->has($row['action_key']), "Reviewed action {$id} specifies unregistered action capability {$row['action_key']}.");
                }
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
            if ($row['kind'] === 'process') {
                $this->validateSteps($id, $row['steps'] ?? null, $actions, $row, $known);
            }
            foreach ($row['policy_refs'] ?? [] as $ref) {
                $this->assert(isset($known[$ref]) && $known[$ref]['kind'] === 'policy', "Unknown policy reference {$ref}.");
            }
        }
    }

    private function validateSteps(string $id, mixed $steps, array $actions, array $processRow, array $known): void
    {
        $this->assert(is_array($steps) && array_is_list($steps) && $steps !== [], "Process {$id} has no steps.");
        $isReviewed = ($processRow['status'] ?? '') === 'reviewed';
        $byId = [];
        foreach ($steps as $step) {
            $key = $step['id'] ?? null;
            $this->assert(is_string($key) && (bool) preg_match('/^[a-z][a-z0-9_]*$/D', $key) && !isset($byId[$key]), "Invalid/duplicate step in {$id}.");
            $this->assert(in_array($step['kind'] ?? null, self::STEP_KINDS, true), "Unknown step type in {$id}.");
            $this->assert(is_array($step['depends_on'] ?? null), "Missing dependencies in {$id}.");
            if ($step['kind'] === 'reviewed_action') {
                $this->assert(isset($actions[$step['action_key'] ?? '']), "Unknown action descriptor in {$id}.");
                if ($isReviewed) {
                    $actionId = $actions[$step['action_key'] ?? ''];
                    $actionRow = $known[$actionId] ?? null;
                    $this->assert($actionRow !== null && ($actionRow['status'] ?? '') === 'reviewed', "Reviewed process {$id} step {$key} references unreviewed action {$step['action_key']}.");
                }
            } else {
                $this->assert(!isset($step['action_key']), "Non-action step has action key in {$id}.");
            }
            if ($isReviewed) {
                $this->assert($step['kind'] !== 'unsupported_action', "Reviewed process {$id} cannot contain unsupported steps ({$key}).");
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
