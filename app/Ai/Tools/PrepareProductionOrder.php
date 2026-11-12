<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionPreparationService;
use App\Models\Warehouse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging manufacturing work orders to the Action Proposal pipeline.
 * Production orders reserve components and plan manufacturing runs without premature stock consumption.
 */
final class PrepareProductionOrder implements Tool
{
    public function __construct(
        private readonly AiPrincipal $principal,
        private readonly ?ActionPreparationService $preparationService = null,
    ) {
    }

    public function name(): string
    {
        return 'prepare_production_order';
    }

    public function description(): string
    {
        return 'Prepares a draft Manufacturing Production Order proposal. Validates Bill of Materials (BOM), planned output quantity, component availability, and production warehouse with zero stock consumption until explicit human work order execution.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'bom_id' => $schema->integer()
                ->description('Bill of Materials (BOM) ID to produce'),
            'bom_query' => $schema->string()
                ->description('BOM code or finished goods product name if ID is unknown'),
            'warehouse_id' => $schema->integer()
                ->description('Production warehouse ID where finished output will be deposited'),
            'warehouse_query' => $schema->string()
                ->description('Production warehouse name or code'),
            'planned_qty' => $schema->number()
                ->description('Target quantity of finished goods to produce (e.g. 100)'),
            'business_date' => $schema->string()
                ->description('Production planning date in YYYY-MM-DD format. Defaults to today.'),
            'task_id' => $schema->string()
                ->description('Optional workflow task ID when resuming an existing clarification session'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        // Authority protection: Never trust caller-supplied authority overrides
        foreach (['company_id', 'branch_id', 'financial_year_id', 'actor_id', 'user_id'] as $field) {
            unset($args[$field]);
        }

        // Normalize BOM ID
        $bomId = $args['bom_id'] ?? null;
        if (empty($bomId) && !empty($args['bom_query'])) {
            $bom = DB::table('boms')
                ->where('company_id', $this->principal->companyId)
                ->where(function ($q) use ($args) {
                    $term = trim((string) $args['bom_query']);
                    $q->where('code', 'like', "%{$term}%")
                      ->orWhere('name', 'like', "%{$term}%");
                })
                ->first();
            if ($bom) {
                $bomId = $bom->id;
            }
        }

        // Normalize Warehouse ID
        $warehouseId = $args['warehouse_id'] ?? null;
        if (empty($warehouseId) && !empty($args['warehouse_query'])) {
            $wh = Warehouse::where('company_id', $this->principal->companyId)
                ->where('name', 'like', '%' . trim((string) $args['warehouse_query']) . '%')
                ->first();
            if ($wh) {
                $warehouseId = $wh->id;
            }
        }
        if (empty($warehouseId)) {
            $defaultWh = Warehouse::where('company_id', $this->principal->companyId)
                ->where('branch_id', $this->principal->branchId)
                ->first();
            if ($defaultWh) {
                $warehouseId = $defaultWh->id;
            }
        }

        $inputs = [
            'bom_id' => $bomId !== null ? (int) $bomId : 0,
            'warehouse_id' => $warehouseId !== null ? (int) $warehouseId : 0,
            'planned_qty' => isset($args['planned_qty']) ? (float) $args['planned_qty'] : 0.0,
            'business_date' => !empty($args['business_date']) ? (string) $args['business_date'] : now()->toDateString(),
        ];

        try {
            $service = $this->preparationService ?? app(ActionPreparationService::class);
            $result = $service->prepare(
                $this->principal,
                'production_order.create.v1',
                $inputs,
                !empty($args['task_id']) ? (string) $args['task_id'] : null
            );

            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'validation',
                'errors' => $e->errors(),
                'message' => implode(' ', $e->validator->errors()->all()),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_PRODUCTION_ORDER_PREPARATION_FAILED',
                'message' => 'Could not generate production order proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

