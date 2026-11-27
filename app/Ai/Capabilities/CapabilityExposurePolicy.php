<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Infrastructure\McpClientGrant;
use App\Ai\Services\ActionSecurity;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiToolManifest;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Live per-request capability discovery & invocation policy engine.
 * Never caches filtered per-user catalogs globally.
 */
final class CapabilityExposurePolicy
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
        private readonly AiPrincipalAuthorizerInterface $authorizer,
        private readonly AiFeatureGate $featureGate,
        private readonly AiToolManifest $manifest,
        private readonly ActionSecurity $actionSecurity,
    ) {}

    /**
     * Discover bounded, policy-filtered capabilities for the given principal and channel.
     *
     * @param ?list<string> $modules
     * @return list<CapabilityDescriptor>
     */
    public function discover(
        AiPrincipal $principal,
        string $channel,
        ?McpClientGrant $grant = null,
        ?array $modules = null,
        ?int $limit = null,
        ?string $operation = null,
    ): array {
        $freshPrincipal = $this->refreshForChannel($principal, $channel);
        if (!$freshPrincipal->isActive || !$this->featureGate->isEnabledForCompany($freshPrincipal->companyId)) {
            return [];
        }

        $maxLimit = $limit ?? match ($channel) {
            'sdk' => max(1, (int) config('ai_capabilities.max_sdk_tools', 25)),
            'rest' => max(1, (int) config('ai_capabilities.max_rest_page_size', 50)),
            default => 50,
        };

        $eligible = [];
        foreach ($this->catalog->all() as $descriptor) {
            if ($operation !== null && $descriptor->operation !== $operation) {
                continue;
            }
            if ($modules !== null && $modules !== [] && !in_array($descriptor->module, $modules, true)) {
                continue;
            }
            if ($this->authorize($freshPrincipal, $channel, $descriptor, $grant, skipRefresh: true)) {
                $eligible[] = $descriptor;
                if (count($eligible) >= $maxLimit) {
                    break;
                }
            }
        }

        return $eligible;
    }

    /**
     * Independently authorize a single capability invocation at call time.
     */
    public function authorize(
        AiPrincipal $principal,
        string $channel,
        CapabilityDescriptor $descriptor,
        ?McpClientGrant $grant = null,
        bool $skipRefresh = false,
    ): bool {
        if ($descriptor->lifecycle !== 'active' || !$descriptor->supportsChannel($channel)) {
            return false;
        }

        $freshPrincipal = $skipRefresh ? $principal : $this->refreshForChannel($principal, $channel);
        if (!$freshPrincipal->isActive || !$this->featureGate->isEnabledForCompany($freshPrincipal->companyId)) {
            return false;
        }

        foreach ($descriptor->featureDependencies as $flagKey) {
            if (!config($flagKey, false)) {
                return false;
            }
        }

        if ($channel === 'automation' && !config('ai_infrastructure.scheduling.enabled', false)) {
            return false;
        }

        if ($channel === 'mcp') {
            if (!config('ai_infrastructure.mcp.enabled', false) || $grant === null) {
                return false;
            }
            if (str_starts_with($descriptor->capabilityKey, 'optical.')
                && (!config('ai_business_knowledge.opticals_mcp_enabled', false)
                    || !in_array($descriptor->capabilityKey, (array) config('ai_infrastructure.mcp.tools', []), true))) {
                return false;
            }
            if ($descriptor->operation === 'prepare'
                && in_array($descriptor->capabilityKey, ['purchase_bill.create.v1', 'sales_order.create.v1', 'sales_invoice.create.v1'], true)
                && (!config('ai_infrastructure.mcp.commercial_prepare_enabled', false)
                    || !in_array($descriptor->capabilityKey, (array) config('ai_infrastructure.mcp.prepare_tools', []), true))) {
                return false;
            }
            if (in_array($descriptor->operation, ['prepare', 'status'], true)
                && !config('ai_infrastructure.mcp.governed_proposals_enabled', false)) {
                return false;
            }
            if (!$grant->allowsCapability($descriptor->capabilityKey, $descriptor->operation)) {
                return false;
            }
        }

        if ($descriptor->operation === 'read') {
            if (!$freshPrincipal->hasTool($descriptor->capabilityKey) || !$this->manifest->has($descriptor->capabilityKey)) {
                return false;
            }
            if (str_starts_with($descriptor->capabilityKey, 'optical.')) {
                $ctx = new \App\Services\Platform\CompanyContext($freshPrincipal->companyId, $freshPrincipal->branchId, $freshPrincipal->financialYearId);
                if (!app(\App\Ai\Knowledge\IndustryContextResolver::class)->isOptical($ctx)) {
                    return false;
                }
            }

            return $this->manifest->get($descriptor->capabilityKey)->risk() === 'read_only';
        }

        if ($descriptor->operation === 'prepare') {
            if (!$freshPrincipal->hasTool($descriptor->capabilityKey)) {
                return false;
            }

            try {
                $this->actionSecurity->definition($descriptor->capabilityKey, $freshPrincipal);

                return true;
            } catch (AuthorizationException|InvalidArgumentException) {
                return false;
            }
        }

        if ($descriptor->operation === 'status') {
            try {
                $this->actionSecurity->assertEnabled($freshPrincipal);
                $context = $this->actionSecurity->context($freshPrincipal);
                app(\App\Services\Commercial\CommercialPermission::class)->assert('ai.use', $context, $freshPrincipal->actorId);

                return true;
            } catch (AuthorizationException|InvalidArgumentException) {
                return false;
            }
        }

        return false;
    }

    private function refreshForChannel(AiPrincipal $principal, string $channel): AiPrincipal
    {
        if ($channel === 'mcp') {
            // MCP principals already carry tightened intersection of binding + token abilities;
            // revalidate live user/membership via authorizer while preserving tightened tool allowlist.
            $refreshed = $this->authorizer->refresh($principal);

            return new AiPrincipal(
                actorId: $refreshed->actorId,
                companyId: $refreshed->companyId,
                branchId: $refreshed->branchId,
                financialYearId: $refreshed->financialYearId,
                permittedToolNames: array_values(array_intersect($refreshed->permittedToolNames, $principal->permittedToolNames)),
                requestId: $principal->requestId !== '' ? $principal->requestId : $refreshed->requestId,
                effectiveRole: $refreshed->effectiveRole,
                tenantBoundaryId: $refreshed->tenantBoundaryId,
                isActive: $refreshed->isActive,
                threadId: $principal->threadId,
            );
        }

        return $this->authorizer->refresh($principal);
    }
}
