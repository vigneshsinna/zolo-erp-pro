<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityDescriptor;
use App\Ai\Capabilities\CapabilityExposurePolicy;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permission-filtered REST capability discovery endpoint.
 * Returns only safe public metadata and schemas; never grants execution authority.
 */
final class AiCapabilityDiscoveryController extends Controller
{
    public function index(
        Request $request,
        CompanyContextResolver $resolver,
        CommercialPermission $permissions,
        AiFeatureGate $featureGate,
        AiPrincipalFactory $principalFactory,
        CapabilityCatalog $catalog,
        CapabilityExposurePolicy $policy,
    ): JsonResponse {
        abort_unless(config('ai_capabilities.shared_discovery_enabled', false), 404, 'Shared capability discovery is disabled.');

        $actorId = (int) $request->user()->id;
        $context = $resolver->forActor(userId: $actorId);
        $permissions->assert('ai.use', $context, $actorId);

        abort_unless($featureGate->isEnabledForCompany($context->companyId), 403, 'AI capabilities are disabled for this company.');

        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:64'],
            'operation' => ['nullable', 'in:read,prepare,status'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $principal = $principalFactory->createForUser(
            $actorId,
            $context->companyId,
            $context->branchId,
            $context->financialYearId,
        );

        $modules = !empty($validated['module']) ? [(string) $validated['module']] : null;
        $operation = $validated['operation'] ?? null;

        $allEligible = $policy->discover(
            principal: $principal,
            channel: 'rest',
            grant: null,
            modules: $modules,
            limit: 500,
            operation: $operation,
        );

        $perPage = min(
            (int) config('ai_capabilities.max_rest_page_size', 50),
            (int) ($validated['per_page'] ?? 25)
        );
        $page = (int) ($validated['page'] ?? 1);
        $total = count($allEligible);
        $slice = array_slice($allEligible, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'catalog_version' => $catalog->versionHash(),
            'capabilities' => array_map(
                static fn (CapabilityDescriptor $descriptor): array => $descriptor->toPublicArray('rest'),
                $slice
            ),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }
}
