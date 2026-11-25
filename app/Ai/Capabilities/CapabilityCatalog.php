<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

use App\Ai\DTOs\ActionDefinition;
use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\AiToolManifest;
use Illuminate\Support\Str;

/**
 * Derived, code-governed capability catalog unifying AiToolManifest,
 * ActionCapabilityRegistry, ActionRequirementsRegistry, and reviewed exposure policy.
 * Never acts as a second command registry or permission store.
 */
final class CapabilityCatalog
{
    /** @var array<string, CapabilityDescriptor>|null */
    private ?array $descriptors = null;

    /**
     * @param ?list<CapabilityDescriptor> $overrideDescriptors Used in isolated unit integrity tests
     */
    public function __construct(
        private readonly AiToolManifest $manifest,
        private readonly ActionCapabilityRegistry $actions,
        private readonly ActionRequirementsRegistry $requirements,
        private readonly CapabilitySchemaCompiler $compiler,
        private readonly CapabilityIntegrityValidator $validator,
        private readonly ?BusinessKnowledgeRegistry $knowledge = null,
        private readonly ?array $overrideDescriptors = null,
    ) {}

    /** @return array<string, CapabilityDescriptor> */
    public function all(): array
    {
        if ($this->descriptors !== null) {
            return $this->descriptors;
        }

        $list = $this->overrideDescriptors ?? $this->buildDerivedDescriptors();

        usort($list, static function (CapabilityDescriptor $a, CapabilityDescriptor $b): int {
            return [$a->capabilityKey, $a->operation] <=> [$b->capabilityKey, $b->operation];
        });

        $this->validator->validate($list, $this->manifest, $this->actions, $this->requirements, $this->knowledge);

        $indexed = [];
        foreach ($list as $descriptor) {
            $indexed[$descriptor->capabilityKey . ':' . $descriptor->operation] = $descriptor;
        }

        return $this->descriptors = $indexed;
    }

    public function find(string $capabilityKey, string $operation): ?CapabilityDescriptor
    {
        return $this->all()[$capabilityKey . ':' . $operation] ?? null;
    }

    public function findByAlias(string $channel, string $alias): ?CapabilityDescriptor
    {
        $needle = strtolower($alias);
        foreach ($this->all() as $descriptor) {
            $channelAlias = $descriptor->aliasFor($channel);
            if ($channelAlias !== null && strtolower($channelAlias) === $needle) {
                return $descriptor;
            }
        }

        return null;
    }

    public function versionHash(): string
    {
        $summary = [];
        foreach ($this->all() as $key => $descriptor) {
            $summary[$key] = [
                'version' => $descriptor->contractVersion,
                'command_hash' => $descriptor->commandContractHash,
                'channels' => $descriptor->channelModes,
                'lifecycle' => $descriptor->lifecycle,
                'review' => $descriptor->reviewReference,
            ];
        }

        return hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    /**
     * Machine-readable coverage report for audits and runbooks.
     *
     * @return list<array<string, mixed>>
     */
    public function coverageReport(): array
    {
        $report = [];
        foreach ($this->all() as $descriptor) {
            $report[] = [
                'capability_key' => $descriptor->capabilityKey,
                'operation' => $descriptor->operation,
                'aliases' => $descriptor->providerAliases,
                'module' => $descriptor->module,
                'risk' => $descriptor->risk,
                'requires_human_approval' => $descriptor->requiresHumanApproval,
                'channels' => $descriptor->channelModes,
                'permissions' => $descriptor->permissions,
                'feature_dependencies' => $descriptor->featureDependencies,
                'lifecycle' => $descriptor->lifecycle,
                'owner' => $descriptor->owner,
                'review_reference' => $descriptor->reviewReference,
                'has_requirements_provider' => $descriptor->operation !== 'prepare' || $this->requirements->has($descriptor->capabilityKey),
            ];
        }

        return $report;
    }

    /**
     * @return list<CapabilityDescriptor>
     */
    private function buildDerivedDescriptors(): array
    {
        $exposureConfig = (array) config('ai_capabilities.exposure', []);
        $readDefaults = (array) ($exposureConfig['read_defaults'] ?? []);
        $prepareDefaults = (array) ($exposureConfig['prepare_defaults'] ?? []);
        $perCapability = (array) ($exposureConfig['capabilities'] ?? []);

        $list = [];

        // 1. Project read capabilities from AiToolManifest
        foreach ($this->manifest->all() as $key => $registration) {
            $handler = $registration->handler;
            $policy = (array) ($perCapability[$key]['read'] ?? []);

            $channels = [
                'sdk' => (bool) ($policy['channels']['sdk'] ?? $readDefaults['sdk'] ?? true),
                'rest' => (bool) ($policy['channels']['rest'] ?? $readDefaults['rest'] ?? true),
                'mcp' => (bool) ($policy['channels']['mcp'] ?? $readDefaults['mcp'] ?? false),
                'automation' => (bool) ($policy['channels']['automation'] ?? $readDefaults['automation'] ?? false),
            ];

            $aliases = array_filter([
                'sdk' => (string) ($policy['aliases']['sdk'] ?? $registration->providerName),
                'rest' => (string) ($policy['aliases']['rest'] ?? $key),
                'mcp' => $channels['mcp'] ? (string) ($policy['aliases']['mcp'] ?? $registration->providerName) : null,
                'automation' => $channels['automation'] ? (string) ($policy['aliases']['automation'] ?? $key) : null,
            ], static fn (?string $v): bool => $v !== null && $v !== '');

            $featureDeps = ['erp_ai.enabled'];
            if ($registration->operationsCapability !== null) {
                $featureDeps[] = 'operations.enabled';
            }
            if (str_starts_with($key, 'optical.')) {
                $featureDeps[] = 'ai_business_knowledge.opticals_enabled';
            }

            $list[] = new CapabilityDescriptor(
                capabilityKey: $key,
                contractVersion: $this->extractVersion($key),
                operation: 'read',
                providerAliases: $aliases,
                title: $this->humanizeTitle($key, $registration->providerName),
                description: $handler->description(),
                module: $this->inferReadModule($key),
                tags: ['read_only', $this->inferReadModule($key)],
                draftInputSchema: $this->compiler->compileReadSchema($handler->schema()),
                commandContractHash: hash('sha256', json_encode($handler->schema(), JSON_THROW_ON_ERROR)),
                resultSchema: $this->compiler->resultSchemaFor('read'),
                risk: 'read_only',
                requiresHumanApproval: false,
                permissions: array_values(array_unique($handler->requiredPermissions())),
                featureDependencies: $featureDeps,
                channelModes: $channels,
                knowledgeReference: null,
                lifecycle: (string) ($policy['lifecycle'] ?? 'active'),
                owner: (string) ($policy['owner'] ?? $readDefaults['owner'] ?? 'platform-ai'),
                reviewReference: (string) ($policy['review_reference'] ?? $readDefaults['review_reference'] ?? 'REV-U1-READ-V1'),
                compatibilityLegacyTool: $registration->providerName,
            );
        }

        // 2. Project prepare capabilities from ActionCapabilityRegistry + ActionRequirementsRegistry
        foreach ($this->actions->all() as $key => $definition) {
            $provider = $this->requirements->has($key) ? $this->requirements->get($key) : null;
            $policy = (array) ($perCapability[$key]['prepare'] ?? []);

            $hasProvider = $provider !== null;
            $channels = [
                'sdk' => $hasProvider && (bool) ($policy['channels']['sdk'] ?? $prepareDefaults['sdk'] ?? true),
                'rest' => $hasProvider && (bool) ($policy['channels']['rest'] ?? $prepareDefaults['rest'] ?? true),
                'mcp' => $hasProvider && (bool) ($policy['channels']['mcp'] ?? false),
                'automation' => $hasProvider && (bool) ($policy['channels']['automation'] ?? false),
            ];

            $aliases = array_filter([
                'sdk' => $channels['sdk'] ? (string) ($policy['aliases']['sdk'] ?? $this->defaultSdkAlias($key)) : null,
                'rest' => $channels['rest'] ? (string) ($policy['aliases']['rest'] ?? $key) : null,
                'mcp' => $channels['mcp'] ? (string) ($policy['aliases']['mcp'] ?? $this->defaultMcpAlias($key)) : null,
                'automation' => $channels['automation'] ? (string) ($policy['aliases']['automation'] ?? $key) : null,
            ], static fn (?string $v): bool => $v !== null && $v !== '');

            $knowledgeRef = $this->resolveKnowledgeReference($policy['knowledge_id'] ?? null);
            $featureDeps = ['erp_ai.enabled', 'ai_actions.foundation_enabled'];
            if ($key === 'purchase_bill.service.create.v1') {
                $featureDeps[] = 'ai_business_knowledge.service_bill_enabled';
            }
            if (str_starts_with($key, 'job_work.') || str_starts_with($key, 'principal_job.')) {
                $featureDeps[] = 'ai_business_knowledge.job_work_enabled';
            }

            $list[] = new CapabilityDescriptor(
                capabilityKey: $key,
                contractVersion: $this->extractVersion($key),
                operation: 'prepare',
                providerAliases: $aliases,
                title: $this->humanizeTitle($key),
                description: $this->prepareDescription($key, $definition),
                module: $definition->businessModule,
                tags: ['prepare_only', 'requires_approval', $definition->businessModule],
                draftInputSchema: $this->compiler->compilePrepareDraftSchema($key, $provider, 'sdk'),
                commandContractHash: $this->compiler->commandContractHash($key, $definition),
                resultSchema: $this->compiler->resultSchemaFor('prepare'),
                risk: $definition->riskTier,
                requiresHumanApproval: true,
                permissions: array_values(array_unique($definition->requiredPermissions)),
                featureDependencies: $featureDeps,
                channelModes: $channels,
                knowledgeReference: $knowledgeRef,
                lifecycle: (string) ($policy['lifecycle'] ?? 'active'),
                owner: (string) ($policy['owner'] ?? $prepareDefaults['owner'] ?? 'erp-commercial'),
                reviewReference: $policy['review_reference'] ?? ($prepareDefaults['review_reference'] ?? null),
                compatibilityLegacyTool: $policy['compatibility_legacy_tool'] ?? null,
            );
        }

        // 3. Project governed status capability
        $statusPolicy = (array) ($perCapability['proposal.status.v1']['status'] ?? []);
        $list[] = new CapabilityDescriptor(
            capabilityKey: 'proposal.status.v1',
            contractVersion: 'v1',
            operation: 'status',
            providerAliases: array_filter([
                'rest' => (string) ($statusPolicy['aliases']['rest'] ?? 'proposal.status.v1'),
                'mcp' => (string) ($statusPolicy['aliases']['mcp'] ?? 'erp_proposal_status'),
            ]),
            title: (string) ($statusPolicy['title'] ?? 'Proposal & Task Status'),
            description: (string) ($statusPolicy['description'] ?? 'Read scoped status, review URL, and verified execution receipt for an owned proposal or workflow task.'),
            module: (string) ($statusPolicy['module'] ?? 'governance'),
            tags: (array) ($statusPolicy['tags'] ?? ['status', 'proposal']),
            draftInputSchema: $this->compiler->compileStatusSchema(),
            commandContractHash: hash('sha256', 'proposal.status.v1'),
            resultSchema: $this->compiler->resultSchemaFor('status'),
            risk: 'read_only',
            requiresHumanApproval: false,
            permissions: ['ai.use'],
            featureDependencies: ['erp_ai.enabled', 'ai_actions.foundation_enabled'],
            channelModes: [
                'sdk' => (bool) ($statusPolicy['channels']['sdk'] ?? false),
                'rest' => (bool) ($statusPolicy['channels']['rest'] ?? true),
                'mcp' => (bool) ($statusPolicy['channels']['mcp'] ?? true),
                'automation' => (bool) ($statusPolicy['channels']['automation'] ?? false),
            ],
            knowledgeReference: null,
            lifecycle: (string) ($statusPolicy['lifecycle'] ?? 'active'),
            owner: (string) ($statusPolicy['owner'] ?? 'platform-security'),
            reviewReference: (string) ($statusPolicy['review_reference'] ?? 'REV-U2-STATUS-V1'),
        );

        return $list;
    }

    private function resolveKnowledgeReference(?string $knowledgeId): ?array
    {
        if (!$knowledgeId || !$this->knowledge) {
            return null;
        }
        $def = $this->knowledge->find($knowledgeId, reviewedOnly: false);
        if (!$def) {
            return null;
        }

        return [
            'id' => $def->id,
            'version' => $def->version,
            'status' => $def->status,
        ];
    }

    private function extractVersion(string $key): string
    {
        if (preg_match('/\.(v\d+)$/', $key, $m)) {
            return $m[1];
        }

        return 'v1';
    }

    private function humanizeTitle(string $key, ?string $fallback = null): string
    {
        $base = preg_replace('/\.v\d+$/', '', $key) ?? $key;
        $base = str_replace(['.', '_'], ' ', $base);

        return $fallback && !str_contains($key, '.') ? $fallback : Str::title($base);
    }

    private function inferReadModule(string $key): string
    {
        return explode('.', $key)[0] ?: 'erp';
    }

    private function defaultSdkAlias(string $key): string
    {
        $base = preg_replace('/\.v\d+$/', '', $key) ?? $key;

        return 'prepare_' . str_replace('.', '_', $base);
    }

    private function defaultMcpAlias(string $key): string
    {
        $base = preg_replace('/\.v\d+$/', '', $key) ?? $key;

        return 'erp_' . str_replace('.', '_', $base) . '_prepare';
    }

    private function prepareDescription(string $key, ActionDefinition $definition): string
    {
        return "Prepares a draft {$this->humanizeTitle($key)} proposal in module [{$definition->businessModule}] for human review and approval. Never approves or posts ERP records.";
    }
}
