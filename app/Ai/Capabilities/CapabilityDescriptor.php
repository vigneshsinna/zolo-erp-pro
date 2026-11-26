<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

/**
 * Immutable derived descriptor representing one ERP capability + operation.
 * Never exposes internal PHP class names or authorization bypass flags.
 */
final readonly class CapabilityDescriptor
{
    /**
     * @param array<string, string> $providerAliases Channel-keyed tool aliases (sdk, mcp, rest, automation)
     * @param list<string> $tags
     * @param array<string, mixed> $draftInputSchema
     * @param array<string, mixed> $resultSchema
     * @param list<string> $permissions
     * @param list<string> $featureDependencies
     * @param array<string, bool> $channelModes Channel availability flags (sdk, mcp, rest, automation)
     * @param ?array<string, mixed> $knowledgeReference
     */
    public function __construct(
        public string $capabilityKey,
        public string $contractVersion,
        public string $operation,
        public array $providerAliases,
        public string $title,
        public string $description,
        public string $module,
        public array $tags,
        public array $draftInputSchema,
        public string $commandContractHash,
        public array $resultSchema,
        public string $risk,
        public bool $requiresHumanApproval,
        public array $permissions,
        public array $featureDependencies,
        public array $channelModes,
        public ?array $knowledgeReference,
        public string $lifecycle,
        public string $owner,
        public ?string $reviewReference,
        public ?string $compatibilityLegacyTool = null,
    ) {}

    public function aliasFor(string $channel): ?string
    {
        return $this->providerAliases[$channel] ?? null;
    }

    public function supportsChannel(string $channel): bool
    {
        return (bool) ($this->channelModes[$channel] ?? false);
    }

    /**
     * Return channel-appropriate JSON Schema (MCP prepare wraps business fields
     * inside `inputs` alongside `client_request_id`).
     *
     * @return array<string, mixed>
     */
    public function inputSchemaFor(string $channel): array
    {
        if ($channel === 'mcp' && $this->operation === 'prepare') {
            return [
                'type' => 'object',
                'properties' => [
                    'client_request_id' => [
                        'type' => 'string',
                        'minLength' => 8,
                        'maxLength' => 128,
                        'description' => 'Stable client-generated idempotency key for this preparation call.',
                    ],
                    'task_id' => [
                        'type' => 'string',
                        'format' => 'uuid',
                        'maxLength' => 36,
                        'description' => 'Optional owned workflow task ID when amending an existing draft.',
                    ],
                    'expected_revision' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Expected workflow task revision when continuing a draft.',
                    ],
                    'inputs' => $this->draftInputSchema,
                ],
                'required' => ['client_request_id', 'inputs'],
                'additionalProperties' => false,
            ];
        }

        return $this->draftInputSchema;
    }

    /**
     * Safe public metadata projection for discovery APIs (REST, Automation, MCP).
     * Never returns internal PHP class names or unpublished authorization secrets.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(string $channel): array
    {
        return [
            'capability_key' => $this->capabilityKey,
            'contract_version' => $this->contractVersion,
            'operation' => $this->operation,
            'alias' => $this->aliasFor($channel) ?? $this->capabilityKey,
            'title' => $this->title,
            'description' => $this->description,
            'module' => $this->module,
            'tags' => $this->tags,
            'risk' => $this->risk,
            'requires_human_approval' => $this->requiresHumanApproval,
            'lifecycle' => $this->lifecycle,
            'input_schema' => $this->inputSchemaFor($channel),
            'result_schema' => $this->resultSchema,
            'knowledge_reference' => $this->knowledgeReference,
        ];
    }
}
