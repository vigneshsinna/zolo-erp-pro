<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionPreparationService;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging dual-warehouse stock transfer requests to the Action Proposal pipeline.
 * Dual-warehouse movements only occur after explicit human review and approval.
 */
final class PrepareStockTransfer implements Tool
{
    public function __construct(
        private readonly AiPrincipal $principal,
        private readonly ?ActionPreparationService $preparationService = null,
    ) {
    }

    public function name(): string
    {
        return 'prepare_stock_transfer';
    }

    public function description(): string
    {
        return 'Prepares a draft Dual-Warehouse Stock Transfer proposal from parameters (source warehouse, destination warehouse, product items, quantities, business_date, reason). Validates stock availability and branch permissions with zero physical stock decrements until explicit human approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'warehouse_id' => $schema->integer()
                ->description('Source / From Warehouse ID where stock is currently located'),
            'from_warehouse_query' => $schema->string()
                ->description('Source / From Warehouse name or code'),
            'to_warehouse_id' => $schema->integer()
                ->description('Destination / To Warehouse ID where stock will be transferred'),
            'to_warehouse_query' => $schema->string()
                ->description('Destination / To Warehouse name or code'),
            'business_date' => $schema->string()
                ->description('Transfer date in YYYY-MM-DD format. Defaults to today.'),
            'reason' => $schema->string()
                ->description('Reason for stock transfer (e.g. "Replenishment for retail showroom", "Inter-branch balance")'),
            'lines' => $schema->array()
                ->items($schema->object([
                    'product_id' => $schema->integer()
                        ->description('Product ID to transfer'),
                    'product_query' => $schema->string()
                        ->description('Product name or SKU to resolve if ID is unknown'),
                    'qty' => $schema->number()
                        ->description('Quantity to transfer (must be positive)'),
                    'uom_id' => $schema->integer()
                        ->description('Optional Unit of Measure ID. Defaults to product base unit.'),
                    'unit_cost' => $schema->number()
                        ->description('Optional valuation unit cost override'),
                ]))
                ->description('List of products and quantities to transfer'),
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

        // Normalize source warehouse
        if (empty($args['warehouse_id']) && !empty($args['from_warehouse_id'])) {
            $args['warehouse_id'] = (int) $args['from_warehouse_id'];
        }
        if (empty($args['warehouse_id']) && !empty($args['from_warehouse_query'])) {
            $wh = Warehouse::where('company_id', $this->principal->companyId)
                ->where('name', 'like', '%' . trim((string) $args['from_warehouse_query']) . '%')
                ->first();
            if ($wh) {
                $args['warehouse_id'] = $wh->id;
            }
        }

        // Normalize destination warehouse
        if (empty($args['to_warehouse_id']) && !empty($args['to_warehouse_query'])) {
            $wh = Warehouse::where('company_id', $this->principal->companyId)
                ->where('name', 'like', '%' . trim((string) $args['to_warehouse_query']) . '%')
                ->first();
            if ($wh) {
                $args['to_warehouse_id'] = $wh->id;
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

        $inputs = [
            'business_date' => !empty($args['business_date']) ? (string) $args['business_date'] : now()->toDateString(),
            'warehouse_id' => !empty($args['warehouse_id']) ? (int) $args['warehouse_id'] : 0,
            'to_warehouse_id' => !empty($args['to_warehouse_id']) ? (int) $args['to_warehouse_id'] : 0,
            'reason' => !empty($args['reason']) ? (string) $args['reason'] : 'Dual-warehouse transfer requested via AI Copilot',
            'lines' => $normalizedLines,
        ];

        try {
            $service = $this->preparationService ?? app(ActionPreparationService::class);
            $result = $service->prepare(
                $this->principal,
                'stock_transfer.create.v1',
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
                'error_code' => 'AI_STOCK_TRANSFER_PREPARATION_FAILED',
                'message' => 'Could not generate stock transfer proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

