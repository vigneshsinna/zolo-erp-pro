<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\EntityDirectorySearchService;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Task T03: Authorized ERP Entity Directory API Controller.
 * Provides authenticated, tenant-isolated, read-only entity searches
 * for warehouses, suppliers, products, customers, and units.
 */
final class AiEntityLookupController extends Controller
{
    public function __construct(
        private readonly EntityDirectorySearchService $searchService,
        private readonly CompanyContextResolver $contextResolver,
        private readonly CommercialPermission $permissions,
        private readonly AiFeatureGate $featureGate,
    ) {
    }

    /**
     * GET /api/v1/ai/entity-lookup
     * GET /ai/entity-options
     *
     * Scoped read-only directory query for selection pickers and clarification flows.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertFoundationEnabled();

        $validated = $request->validate([
            'type' => ['required_without:entity_type', 'nullable', 'string', 'in:warehouse,supplier,product,customer,unit'],
            'entity_type' => ['nullable', 'string', 'in:warehouse,supplier,product,customer,unit'],
            'q' => ['nullable', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:100'],
            'task_id' => ['nullable', 'uuid'],
            'field' => ['nullable', 'string', 'max:100'],
            'field_path' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $actorId = (int) $request->user()->id;
        $context = $this->contextResolver->forActor(userId: $actorId);

        $this->permissions->assert('ai.use', $context, $actorId);

        $entityType = (string) ($validated['type'] ?? $validated['entity_type'] ?? 'warehouse');
        $searchQuery = (string) ($validated['q'] ?? $validated['query'] ?? '');
        $taskId = isset($validated['task_id']) ? (string) $validated['task_id'] : null;
        $fieldPath = (string) ($validated['field'] ?? $validated['field_path'] ?? '');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 20);

        $result = $this->searchService->search(
            context: $context,
            actorId: $actorId,
            entityType: $entityType,
            query: $searchQuery !== '' ? $searchQuery : null,
            page: $page,
            perPage: $perPage,
            taskId: $taskId,
            fieldPath: $fieldPath !== '' ? $fieldPath : null,
        );

        return response()->json($result);
    }

    private function assertFoundationEnabled(): void
    {
        if (!config('ai_actions.foundation_enabled', false)) {
            abort(403, 'AI action foundation is disabled.');
        }
    }
}
