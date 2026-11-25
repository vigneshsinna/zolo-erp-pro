<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Services\Optical\OpticalPowerMatrixService;
use App\Services\Platform\CompanyContext;
use Throwable;

/** Read-only authorized optical power matrix & replenishment suggestion handler. */
final class OpticalPowerMatrixHandler implements ErpToolHandlerInterface
{
    public function __construct(
        private readonly OpticalPowerMatrixService $matrixService,
    ) {}

    public function key(): string
    {
        return 'optical.power_matrix.v1';
    }

    public function description(): string
    {
        return 'Read power-wise optical lens stock matrix across authorized branch warehouses and deterministic reorder suggestions.';
    }

    public function risk(): string
    {
        return 'read_only';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lens_family' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Filter by lens family name.'],
                'product_query' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Filter by product code or name.'],
                'sph' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Exact sphere power filter.'],
                'cyl' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Exact cylinder power filter.'],
                'coating' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Coating filter.'],
                'refractive_index' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Refractive index filter.'],
                'dia' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Diameter filter.'],
                'warehouse_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Scope to a specific authorized warehouse.'],
                'include_authorized_stores' => ['type' => 'boolean', 'default' => false, 'description' => 'Include other branches authorized for this user.'],
                'include_reorder_suggestions' => ['type' => 'boolean', 'default' => false, 'description' => 'Include deterministic reorder/transfer suggestions.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50, 'description' => 'Max lens identities returned.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function requiredPermissions(): array
    {
        return ['ai.use'];
    }

    public function authorize(AiPrincipal $principal, array $input): bool
    {
        return $principal->isActive
            && $principal->hasTool($this->key())
            && (bool) config('ai_business_knowledge.opticals_enabled', false);
    }

    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult
    {
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        try {
            $data = !empty($validatedInput['include_reorder_suggestions'])
                ? $this->matrixService->suggestReplenishment($context, $principal->actorId, $validatedInput)
                : $this->matrixService->powerMatrix($context, $principal->actorId, $validatedInput);
        } catch (Throwable $e) {
            return ErpToolResult::denied($this->key(), $e->getMessage());
        }

        $rowCount = count($data['cells'] ?? $data['suggestions'] ?? []);

        return ErpToolResult::ok(
            toolKey: $this->key(),
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: $data,
            rowCount: $rowCount,
        );
    }
}
