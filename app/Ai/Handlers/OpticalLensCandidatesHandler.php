<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Knowledge\IndustryContextResolver;
use App\Services\Optical\OpticalCatalogService;
use App\Services\Platform\CompanyContext;
use Throwable;

/** Read-only scoped optical lens specification & candidate search handler. */
final class OpticalLensCandidatesHandler implements ErpToolHandlerInterface
{
    public function __construct(
        private readonly OpticalCatalogService $catalog,
        private readonly IndustryContextResolver $industry,
    ) {}

    public function key(): string
    {
        return 'optical.lens_candidates.v1';
    }

    public function description(): string
    {
        return 'Search exact optical lens SKUs and candidate specifications (SPH, CYL, AXIS, ADD, DIA, L/R, coating, refractive index) within the authorized company.';
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
                'product_query' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Lens family, SKU code, or supplier alias.'],
                'sph' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Sphere power with explicit sign (e.g. -2.00, +1.50).'],
                'cyl' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Cylinder power (e.g. -0.50).'],
                'axis' => ['type' => 'string', 'maxLength' => 8, 'description' => 'Axis integer 0-180.'],
                'addition' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Addition power (e.g. +2.00).'],
                'dia' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Lens diameter in mm (e.g. 70).'],
                'lr' => ['type' => 'string', 'enum' => ['R', 'L', 'Pair'], 'description' => 'Eye designation.'],
                'coating' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Coating / treatment filter.'],
                'refractive_index' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Refractive index filter (e.g. 1.56, 1.67).'],
                'warehouse_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional authorized warehouse ID for stock display.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10, 'description' => 'Maximum candidate rows.'],
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
        if (!config('ai_business_knowledge.opticals_enabled', false) || !$this->industry->isOptical($context)) {
            return ErpToolResult::denied($this->key(), 'Optical lens search is only available for Opticals companies.');
        }

        try {
            $res = $this->catalog->resolveExactOrCandidates(
                $context,
                (string) ($validatedInput['product_query'] ?? ''),
                array_intersect_key($validatedInput, array_flip(['sph', 'cyl', 'axis', 'addition', 'dia', 'lr', 'coating', 'refractive_index'])),
                isset($validatedInput['warehouse_id']) ? (int) $validatedInput['warehouse_id'] : null,
            );
        } catch (Throwable $e) {
            return ErpToolResult::error($this->key(), 'OPTICAL_SPEC_INVALID', $e->getMessage(), [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
            ]);
        }

        $limit = max(1, min(20, (int) ($validatedInput['limit'] ?? 10)));
        $candidates = array_slice($res['candidates'], 0, $limit);

        return ErpToolResult::ok(
            toolKey: $this->key(),
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'resolution_status' => $res['status'],
                'candidates' => $candidates,
                'message' => $res['message'] ?? null,
            ],
            rowCount: count($candidates),
        );
    }
}
