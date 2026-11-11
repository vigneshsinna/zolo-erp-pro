<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Phase 2.2: Orchestrates intelligent dependent master data creation and workflow resumption.
 * Manages the Three-Way Disambiguation Triad, master setup sub-flows, separate approval gates,
 * and automatic resumption of parent workflow tasks upon master creation.
 */
class DependentMasterOrchestrator
{
    public function __construct(
        private readonly ActionCapabilityRegistry $capabilityRegistry,
        private readonly ActionRequirementsRegistry $requirementsRegistry,
        private readonly BatchRecordResolutionService $batchResolver,
        private readonly InputCompletenessEvaluator $evaluator,
        private readonly ActionProposalService $proposalService,
        private readonly ActionApprovalService $approvalService,
        private readonly ApprovedActionExecutor $actionExecutor,
        private readonly CommercialPermission $permissions,
        private readonly AiPrincipalFactory $principalFactory,
    ) {
    }

    /**
     * Resolves metadata for an unresolved slot field.
     *
     * @return array{entity_type: string, capability_key: string, permission: string, client_line_id: ?string}
     */
    public function resolveFieldEntity(string $field): array
    {
        $clientLineId = null;
        if (preg_match('/^items\[([a-zA-Z0-9\-_]+)\]\.product_id$/', $field, $matches)) {
            return [
                'entity_type' => 'product',
                'capability_key' => 'setup.product.create.v1',
                'permission' => 'products-add',
                'client_line_id' => $matches[1],
            ];
        }

        return match ($field) {
            'supplier_id' => [
                'entity_type' => 'supplier',
                'capability_key' => 'setup.supplier.create.v1',
                'permission' => 'suppliers-add',
                'client_line_id' => null,
            ],
            'warehouse_id' => [
                'entity_type' => 'warehouse',
                'capability_key' => 'setup.warehouse.create.v1',
                'permission' => 'warehouse',
                'client_line_id' => null,
            ],
            'customer_id' => [
                'entity_type' => 'customer',
                'capability_key' => 'setup.customer.create.v1',
                'permission' => 'customers-add',
                'client_line_id' => null,
            ],
            'unit_id' => [
                'entity_type' => 'unit',
                'capability_key' => 'setup.unit.create.v1',
                'permission' => 'unit',
                'client_line_id' => null,
            ],
            default => throw new InvalidArgumentException("Field '{$field}' is not a resolvable master entity."),
        };
    }

    /**
     * Spawns requirements schema and smart defaults for a dependent master creation sub-flow.
     *
     * @return array<string, mixed>
     */
    public function spawnMasterRequirements(WorkflowTaskContext $context, string $taskId, string $field): array
    {
        $task = $this->getAuthorizedTask($context, $taskId);
        $entityMeta = $this->resolveFieldEntity($field);
        $companyContext = new CompanyContext($context->companyId(), $context->branchId(), $context->financialYearId());

        // Assert actor has Spatie permission to create this master record
        $this->permissions->assert($entityMeta['permission'], $companyContext, $context->actorId());

        $slots = json_decode((string) $task->slots_json, true) ?? [];
        $prefilled = $this->extractPrefilledSlots($field, $entityMeta, $slots, $companyContext);
        $tenantDefaults = $this->loadTenantDefaults($entityMeta['entity_type'], $companyContext);

        return [
            'task_id' => $taskId,
            'field' => $field,
            'entity_type' => $entityMeta['entity_type'],
            'capability_key' => $entityMeta['capability_key'],
            'required_permission' => $entityMeta['permission'],
            'prefilled' => $prefilled,
            'tenant_defaults' => $tenantDefaults,
        ];
    }

    /**
     * Previews a governed master setup proposal linked to the parent workflow task.
     *
     * @param array<string, mixed> $masterData
     * @return array<string, mixed>
     */
    public function previewMasterProposal(
        WorkflowTaskContext $context,
        string $taskId,
        string $field,
        array $masterData
    ): array {
        $task = $this->getAuthorizedTask($context, $taskId);
        $entityMeta = $this->resolveFieldEntity($field);
        $companyContext = new CompanyContext($context->companyId(), $context->branchId(), $context->financialYearId());

        // Assert actor has permission
        $this->permissions->assert($entityMeta['permission'], $companyContext, $context->actorId());

        $principal = $this->resolvePrincipal($context);

        // Create the governed Action Proposal using existing setup capability
        $proposal = $this->proposalService->create(
            principal: $principal,
            key: $entityMeta['capability_key'],
            slots: $masterData,
            threadId: $task->thread_id
        );

        // Record the dependent master proposal on the parent task
        $dependentMasters = json_decode((string) ($task->dependent_masters_json ?? '{}'), true) ?? [];
        $dependentMasters[$field] = [
            'status' => 'proposal_prepared',
            'proposal_id' => $proposal['id'],
            'action_key' => $entityMeta['capability_key'],
            'entity_type' => $entityMeta['entity_type'],
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('ai_workflow_tasks')->where('id', $taskId)->update([
            'dependent_masters_json' => json_encode($dependentMasters),
            'updated_at' => now(),
        ]);

        return [
            'parent_task_id' => $taskId,
            'field' => $field,
            'proposal' => $proposal,
        ];
    }

    /**
     * Approves and executes the master proposal, then automatically resumes the parent workflow task
     * by injecting the newly created ERP resource ID into the parent slot bindings.
     *
     * @return array<string, mixed>
     */
    public function approveAndExecuteMaster(
        WorkflowTaskContext $context,
        string $taskId,
        string $field,
        string $masterProposalId,
        int $expectedVersion = 1
    ): array {
        $entityMeta = $this->resolveFieldEntity($field);
        $companyContext = new CompanyContext($context->companyId(), $context->branchId(), $context->financialYearId());

        // Assert permissions: user must have ai.action.approve and the master creation permission
        $this->permissions->assert('ai.action.approve', $companyContext, $context->actorId());
        $this->permissions->assert($entityMeta['permission'], $companyContext, $context->actorId());

        $principal = $this->resolvePrincipal($context);

        // 1. Approve the master proposal (HMAC verified, version locked)
        $this->approvalService->approve($principal, $masterProposalId, $expectedVersion);

        // 2. Execute the master proposal through authoritative ERP domain command adapter
        $executionResult = $this->actionExecutor->execute($principal, $masterProposalId, $expectedVersion);

        $receipt = $executionResult['receipt'] ?? [];
        $createdResourceId = (int) ($receipt['resource_id'] ?? $receipt['details']['company_id'] ?? 0);
        $createdResourceType = (string) ($receipt['resource_type'] ?? $entityMeta['entity_type']);

        if ($createdResourceId <= 0) {
            throw new \RuntimeException('Master execution completed without returning a valid ERP resource ID.');
        }

        // 3. Resumption: Atomically update parent workflow task with newly created ERP resource ID
        return DB::transaction(function () use (
            $context,
            $taskId,
            $field,
            $entityMeta,
            $masterProposalId,
            $createdResourceId,
            $createdResourceType,
            $receipt
        ): array {
            $task = DB::table('ai_workflow_tasks')
                ->where('id', $taskId)
                ->where('company_id', $context->companyId())
                ->where('actor_id', $context->actorId())
                ->lockForUpdate()
                ->first();

            if (!$task) {
                throw new AuthorizationException('Workflow task not accessible for resumption.');
            }

            $slots = json_decode((string) $task->slots_json, true) ?? [];
            $dependentMasters = json_decode((string) ($task->dependent_masters_json ?? '{}'), true) ?? [];

            // Inject the new ID into the slots
            $this->injectSlotValue($slots, $field, $entityMeta, $createdResourceId);

            // Record completed master in dependent_masters_json
            $dependentMasters[$field] = [
                'status' => 'completed',
                'proposal_id' => $masterProposalId,
                'created_resource_id' => $createdResourceId,
                'created_resource_type' => $createdResourceType,
                'completed_at' => now()->toIso8601String(),
            ];

            // Re-resolve and re-evaluate completeness with the new slot bindings
            $provider = $this->requirementsRegistry->get($task->action_key);
            $batchResult = $this->batchResolver->resolveAll($context, $slots);
            $evaluation = $this->evaluator->evaluate($provider, $context, $slots, $batchResult);

            $newRevision = (int) $task->revision + 1;
            $newStatus = $evaluation->isReady() ? 'ready' : 'collecting';

            DB::table('ai_workflow_tasks')->where('id', $taskId)->update([
                'slots_json' => json_encode($slots),
                'resolution_json' => json_encode($batchResult->toArray()),
                'issues_json' => json_encode($evaluation->issuesArray()),
                'dependent_masters_json' => json_encode($dependentMasters),
                'status' => $newStatus,
                'revision' => $newRevision,
                'updated_at' => now(),
            ]);

            return [
                'status' => 'resumed',
                'parent_task_id' => $taskId,
                'revision' => $newRevision,
                'newly_created' => [
                    'field' => $field,
                    'resource_type' => $createdResourceType,
                    'resource_id' => $createdResourceId,
                    'name' => $receipt['details']['name'] ?? $receipt['details']['code'] ?? null,
                ],
                'evaluation' => $evaluation->toArray(),
                'can_prepare_proposal' => $evaluation->isReady(),
            ];
        });
    }

    /**
     * Injects a resolved resource ID into the slot structure.
     */
    private function injectSlotValue(array &$slots, string $field, array $entityMeta, int $resourceId): void
    {
        if ($entityMeta['client_line_id'] !== null) {
            $clientLineId = $entityMeta['client_line_id'];
            $items = (array) ($slots['items'] ?? []);

            // Locate matching item line by clientLineId or index
            $matched = false;
            foreach ($items as $idx => $line) {
                if (($line['client_line_id'] ?? null) === $clientLineId) {
                    $items[$idx]['product_id'] = $resourceId;
                    $matched = true;
                    break;
                }
            }

            // Fallback: if clientLineId is line-0, check index 0
            if (!$matched && preg_match('/^line-(\d+)$/', $clientLineId, $m)) {
                $idx = (int) $m[1];
                if (isset($items[$idx])) {
                    $items[$idx]['product_id'] = $resourceId;
                    $matched = true;
                }
            }

            $slots['items'] = $items;
            return;
        }

        $slots[$field] = $resourceId;
    }

    /**
     * Extracts prefilled slot values from parent task context.
     *
     * @return array<string, mixed>
     */
    private function extractPrefilledSlots(
        string $field,
        array $entityMeta,
        array $slots,
        CompanyContext $context
    ): array {
        $prefilled = [];

        if ($entityMeta['entity_type'] === 'supplier') {
            $query = trim((string) ($slots['supplier_query'] ?? ''));
            $prefilled['name'] = $query !== '' ? $query : null;
            $company = DB::table('companies')->where('id', $context->companyId)->first();
            $prefilled['state'] = $company?->state_code;
        } elseif ($entityMeta['entity_type'] === 'warehouse') {
            $query = trim((string) ($slots['warehouse_query'] ?? ''));
            $prefilled['name'] = $query !== '' ? $query : null;
            $prefilled['branch_id'] = $context->branchId;
        } elseif ($entityMeta['entity_type'] === 'product') {
            $query = '';
            $unitCost = null;

            if ($entityMeta['client_line_id'] !== null) {
                $clientLineId = $entityMeta['client_line_id'];
                foreach ((array) ($slots['items'] ?? []) as $idx => $line) {
                    if (($line['client_line_id'] ?? "line-{$idx}") === $clientLineId) {
                        $query = trim((string) ($line['product_query'] ?? ''));
                        $unitCost = isset($line['net_unit_cost']) ? (float) $line['net_unit_cost'] : null;
                        break;
                    }
                }
            }

            $prefilled['name'] = $query !== '' ? $query : null;
            $cleanCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $query), 0, 10));
            $prefilled['code'] = $cleanCode !== '' ? "SKU-{$cleanCode}" : 'SKU-' . strtoupper(Str::random(6));
            if ($unitCost !== null && $unitCost > 0) {
                $prefilled['cost'] = $unitCost;
                $prefilled['price'] = $unitCost;
            }

            // Default primary unit
            $defaultUnit = DB::table('units')->where('company_id', $context->companyId)->where('is_active', true)->first();
            if ($defaultUnit) {
                $prefilled['unit_id'] = (int) $defaultUnit->id;
            }
        } elseif ($entityMeta['entity_type'] === 'customer') {
            $query = trim((string) ($slots['customer_query'] ?? ''));
            $prefilled['name'] = $query !== '' ? $query : null;
        }

        return array_filter($prefilled, static fn ($v): bool => $v !== null && $v !== '');
    }

    /**
     * Loads active tenant defaults for reference when creating masters.
     *
     * @return array<string, mixed>
     */
    private function loadTenantDefaults(string $entityType, CompanyContext $context): array
    {
        $defaults = [];

        if ($entityType === 'product') {
            $defaults['units'] = DB::table('units')
                ->where('company_id', $context->companyId)
                ->where('is_active', true)
                ->get(['id', 'unit_name', 'unit_code'])
                ->toArray();

            if (Schema::hasTable('categories')) {
                $defaults['categories'] = DB::table('categories')
                    ->where('company_id', $context->companyId)
                    ->get(['id', 'name'])
                    ->toArray();
            }

            if (Schema::hasTable('tax_categories')) {
                $defaults['tax_categories'] = DB::table('tax_categories')
                    ->where('company_id', $context->companyId)
                    ->get(['id', 'name'])
                    ->toArray();
            }
        }

        return $defaults;
    }

    private function getAuthorizedTask(WorkflowTaskContext $context, string $taskId): object
    {
        $task = DB::table('ai_workflow_tasks')
            ->where('id', $taskId)
            ->where('company_id', $context->companyId())
            ->where('actor_id', $context->actorId())
            ->first();

        if (!$task) {
            throw new AuthorizationException('Workflow task not accessible or does not exist.');
        }

        if (now()->greaterThanOrEqualTo($task->expires_at)) {
            throw ValidationException::withMessages(['task' => 'Workflow task has expired.']);
        }

        return $task;
    }

    private function resolvePrincipal(WorkflowTaskContext $context): AiPrincipal
    {
        if ($context->principal instanceof AiPrincipal) {
            return $context->principal;
        }

        return $this->principalFactory->createForUser(
            $context->actorId(),
            $context->companyId(),
            $context->branchId(),
            $context->financialYearId()
        );
    }
}
