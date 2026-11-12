<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\ActionPreparationService;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging inventory adjustment requests to the Action Proposal pipeline.
 * Privileged inventory shrinkage, damage write-offs, or physical variances.
 */
final class PrepareStockAdjustment implements Tool
{
    public function __construct(
        private readonly AiPrincipal $principal,
        private readonly ?ActionPreparationService $preparationService = null,
    ) {
    }

    public function name(): string
    {
        return 'prepare_stock_adjustment';
    }

    public function description(): string
    {
        return 'Prepares a draft Stock Adjustment proposal for inventory shrinkage, damage write-offs, or counted physical variances. Requires privileged role approval before creating physical stock movement and balanced variance journal entries.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'warehouse_id' => $schema->integer()
                ->description('Warehouse ID where stock adjustment is taking place'),
            'warehouse_query' => $schema->string()
                ->description('Warehouse name or code'),
            'business_date' => $schema->string()
                ->description('Adjustment date in YYYY-MM-DD format. Defaults to today.'),
            'reason' => $schema->string()
                ->description('Mandatory justification for adjustment (e.g. "Shrinkage write-off", "Physical count variance", "Water damage")'),
            'lines' => $schema->array()
                ->items($schema->object([
                    'product_id' => $schema->integer()
                        ->description('Product ID to adjust'),
                    'product_query' => $schema->string()
                        ->description('Product name or SKU to resolve if ID is unknown'),
                    'qty' => $schema->number()
                        ->description('Quantity variance: negative number (e.g. -5) for write-offs/loss, positive number (e.g. 3) for surplus count'),
                    'uom_id' => $schema->integer()
                        ->description('Optional Unit of Measure ID. Defaults to product base unit.'),
                    'unit_cost' => $schema->number()
                        ->description('Optional unit valuation cost override'),
                ]))
                ->description('List of products and quantities to adjust'),
            'items' => $schema->array()
                ->description('Alias for lines'),
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

        // Normalize warehouse
        if (empty($args['warehouse_id']) && !empty($args['warehouse_query'])) {
            $wh = Warehouse::where('company_id', $this->principal->companyId)
                ->where('name', 'like', '%' . trim((string) $args['warehouse_query']) . '%')
                ->first();
            if ($wh) {
                $args['warehouse_id'] = $wh->id;
            }
        }
        if (empty($args['warehouse_id'])) {
            $defaultWh = Warehouse::where('company_id', $this->principal->companyId)
                ->where('branch_id', $this->principal->branchId)
                ->first();
            if ($defaultWh) {
                $args['warehouse_id'] = $defaultWh->id;
            }
        }

        // Normalize lines / items
        $rawLines = $args['lines'] ?? $args['items'] ?? [];
        if (!is_array($rawLines)) {
            $rawLines = [];
        }

        $normalizedLines = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $productId = $line['product_id'] ?? null;
            if (empty($productId) && !empty($line['product_query'])) {
                $prod = Product::where('company_id', $this->principal->companyId)
                    ->where(function ($q) use ($line) {
                        $term = trim((string) $line['product_query']);
                        $q->where('name', 'like', "%{$term}%")
                          ->orWhere('code', 'like', "%{$term}%");
                    })
                    ->first();
                if ($prod) {
                    $productId = $prod->id;
                }
            }

            $qty = isset($line['qty']) ? (float) $line['qty'] : 0.0;
            $uomId = $line['uom_id'] ?? null;
            if (empty($uomId) && !empty($productId)) {
                $prod = Product::where('company_id', $this->principal->companyId)->find($productId);
                if ($prod) {
                    $uomId = $prod->unit_id;
                }
            }

            $normalizedLines[] = [
                'product_id' => $productId !== null ? (int) $productId : 0,
                'qty' => $qty,
                'uom_id' => $uomId !== null ? (int) $uomId : 0,
                'unit_cost' => isset($line['unit_cost']) ? (float) $line['unit_cost'] : null,
            ];
        }

        $actionKey = app(ActionRequirementsRegistry::class)->has('stock_adjustment.create.v1')
            ? 'stock_adjustment.create.v1'
            : 'stock_adjust.create.v1';

        $inputs = [
            'business_date' => !empty($args['business_date']) ? (string) $args['business_date'] : now()->toDateString(),
            'warehouse_id' => !empty($args['warehouse_id']) ? (int) $args['warehouse_id'] : 0,
            'reason' => !empty($args['reason']) ? (string) $args['reason'] : 'Counted physical stock adjustment via AI Copilot',
            'lines' => $normalizedLines,
        ];

        try {
            $service = $this->preparationService ?? app(ActionPreparationService::class);
            $result = $service->prepare(
                $this->principal,
                $actionKey,
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
                'error_code' => 'AI_STOCK_ADJUSTMENT_PREPARATION_FAILED',
                'message' => 'Could not generate stock adjustment proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

