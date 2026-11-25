<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\AiToolManifest;
use InvalidArgumentException;

/**
 * Startup/CI integrity validator for derived capability descriptors.
 * Fails fast on duplicate aliases, permission widening, open schemas,
 * missing underlying registrations, or unreviewed external exposures.
 */
final class CapabilityIntegrityValidator
{
    public const VALID_OPERATIONS = ['read', 'prepare', 'status'];
    public const VALID_CHANNELS = ['sdk', 'mcp', 'rest', 'automation'];

    /**
     * @param list<CapabilityDescriptor> $descriptors
     */
    public function validate(
        array $descriptors,
        AiToolManifest $manifest,
        ActionCapabilityRegistry $actions,
        ActionRequirementsRegistry $requirements,
        ?BusinessKnowledgeRegistry $knowledge = null,
    ): void {
        $seenCapabilityOperations = [];
        $seenChannelAliases = [];

        foreach ($descriptors as $descriptor) {
            if (!$descriptor instanceof CapabilityDescriptor) {
                throw new InvalidArgumentException('Invalid capability descriptor entry.');
            }

            if (!in_array($descriptor->operation, self::VALID_OPERATIONS, true)) {
                throw new InvalidArgumentException("Unsupported capability operation [{$descriptor->operation}] on [{$descriptor->capabilityKey}].");
            }

            $opKey = $descriptor->capabilityKey . ':' . $descriptor->operation;
            if (isset($seenCapabilityOperations[$opKey])) {
                throw new InvalidArgumentException("Duplicate capability/operation [{$opKey}].");
            }
            $seenCapabilityOperations[$opKey] = true;

            foreach ($descriptor->providerAliases as $channel => $alias) {
                if (!in_array($channel, self::VALID_CHANNELS, true)) {
                    throw new InvalidArgumentException("Unknown capability channel [{$channel}] on [{$opKey}].");
                }
                if (trim($alias) === '') {
                    throw new InvalidArgumentException("Empty alias for channel [{$channel}] on [{$opKey}].");
                }
                $normalizedAlias = strtolower($alias);
                if (isset($seenChannelAliases[$channel][$normalizedAlias])) {
                    throw new InvalidArgumentException("Duplicate alias [{$alias}] on channel [{$channel}].");
                }
                $seenChannelAliases[$channel][$normalizedAlias] = $opKey;
            }

            $this->assertClosedSchema($descriptor->draftInputSchema, $opKey);

            if ($descriptor->operation === 'read') {
                if (!$manifest->has($descriptor->capabilityKey)) {
                    throw new InvalidArgumentException("Read capability [{$descriptor->capabilityKey}] is not registered in AiToolManifest.");
                }
                $handler = $manifest->get($descriptor->capabilityKey);
                if ($handler->risk() !== 'read_only' || $descriptor->risk !== 'read_only' || $descriptor->requiresHumanApproval) {
                    throw new InvalidArgumentException("Read capability [{$descriptor->capabilityKey}] must enforce read_only risk and no approval flag.");
                }
                $missingPerms = array_diff($handler->requiredPermissions(), $descriptor->permissions);
                if ($missingPerms !== []) {
                    throw new InvalidArgumentException("Permission widening detected on [{$opKey}].");
                }
            }

            if ($descriptor->operation === 'prepare') {
                if (!$actions->has($descriptor->capabilityKey)) {
                    throw new InvalidArgumentException("Prepare capability [{$descriptor->capabilityKey}] is missing from ActionCapabilityRegistry.");
                }
                $definition = $actions->get($descriptor->capabilityKey);
                if (!$descriptor->requiresHumanApproval || !$definition->requiresApproval) {
                    throw new InvalidArgumentException("Prepare capability [{$descriptor->capabilityKey}] must require human approval.");
                }
                $missingPerms = array_diff($definition->requiredPermissions, $descriptor->permissions);
                if ($missingPerms !== []) {
                    throw new InvalidArgumentException("Permission widening detected on [{$opKey}].");
                }
                if (($descriptor->supportsChannel('mcp') || $descriptor->supportsChannel('automation')) && !$requirements->has($descriptor->capabilityKey)) {
                    throw new InvalidArgumentException("Prepare capability [{$descriptor->capabilityKey}] requires ActionRequirementsProvider for channel exposure.");
                }
            }

            if ($descriptor->supportsChannel('mcp') && in_array($descriptor->operation, ['prepare', 'status'], true)) {
                if ($descriptor->lifecycle !== 'active' || trim((string) $descriptor->reviewReference) === '' || trim($descriptor->owner) === '') {
                    throw new InvalidArgumentException("External MCP exposure for [{$opKey}] requires active lifecycle, owner, and review reference.");
                }
            }

            if ($descriptor->knowledgeReference !== null && $knowledge !== null) {
                $knowledgeId = (string) ($descriptor->knowledgeReference['id'] ?? '');
                $def = $knowledgeId !== '' ? $knowledge->find($knowledgeId, reviewedOnly: false) : null;
                if (!$def || $def->status === 'retired') {
                    throw new InvalidArgumentException("Capability [{$opKey}] references missing or retired knowledge [{$knowledgeId}].");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function assertClosedSchema(array $schema, string $opKey): void
    {
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? null) !== false) {
            throw new InvalidArgumentException("Capability [{$opKey}] requires a closed object schema.");
        }

        $properties = (array) ($schema['properties'] ?? []);
        foreach ($properties as $name => $field) {
            if (in_array(strtolower((string) $name), CapabilitySchemaCompiler::AUTHORITY_FIELDS, true)) {
                throw new InvalidArgumentException("Authority field [{$name}] is prohibited in schema [{$opKey}].");
            }
            if (is_array($field) && ($field['type'] ?? null) === 'object') {
                $this->assertClosedSchema($field, "{$opKey}.{$name}");
            }
            if (is_array($field) && ($field['type'] ?? null) === 'array' && is_array($field['items'] ?? null) && ($field['items']['type'] ?? null) === 'object') {
                $this->assertClosedSchema($field['items'], "{$opKey}.{$name}[]");
            }
        }
    }
}
