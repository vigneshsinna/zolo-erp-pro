<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\RecordResolutionService;
use App\Services\Platform\CompanyContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging natural language purchase order instructions to the Action Proposal pipeline.
 * Disambiguates supplier, products, warehouse, and quantities, validates business rules,
 * and seals a versioned draft proposal with deterministic preview for human sign-off.
 * Strictly READ-ONLY with ZERO ledger or stock writes.
 */
final readonly class PreparePurchaseOrder implements Tool
{
    public function __construct(
        private AiPrincipal $principal,
        private RecordResolutionService $resolver,
    ) {}

    public function name(): string
    {
        return 'prepare_purchase_order';
    }

    public function description(): string
    {
        return 'Prepares a draft Purchase Order proposal from natural language parameters (supplier, products, quantities, costs, warehouse). Validates supplier and product entities, checks branch warehouse, calculates taxes and totals, and creates an editable preview for human sign-off with zero financial writes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_query' => $schema->string()
                ->description('Supplier name, company name, VAT/GSTIN, code, or ID')
                ->required(),
            'items' => $schema->array()
                ->items($schema->object([
                    'product_query' => $schema->string()
                        ->description('Product name, code, SKU, or alias')
                        ->required(),
                    'qty' => $schema->number()
                        ->description('Quantity to order (e.g. 100)')
                        ->required(),
                    'net_unit_cost' => $schema->number()
                        ->description('Optional agreed unit price/cost. If omitted, uses standard product cost.'),
                    'discount' => $schema->number()
                        ->description('Optional line discount amount'),
                    'tax_rate' => $schema->number()
                        ->description('Optional tax rate percentage override'),
                    'uom' => $schema->string()
                        ->description('Optional unit of measure (e.g. KG, PCS, MTR)'),
                    'note' => $schema->string()
                        ->description('Optional line item note'),
                ]))
                ->description('List of line items to include in the purchase order')
                ->required(),
            'warehouse_query' => $schema->string()
                ->description('Optional destination warehouse name or ID. Defaults to the active branch warehouse.'),
            'date' => $schema->string()
                ->description('Optional order date in YYYY-MM-DD format. Defaults to today.'),
            'note' => $schema->string()
                ->description('Optional overall order note or reference'),
            'order_discount' => $schema->number()
                ->description('Optional overall order discount amount'),
            'shipping_cost' => $schema->number()
                ->description('Optional shipping or freight cost amount'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $context = new CompanyContext(
            $this->principal->companyId,
            $this->principal->branchId,
            $this->principal->financialYearId
        );

        // 1. Resolve Supplier
        $supplierQuery = (string) ($args['supplier_query'] ?? '');
        $supplierRes = $this->resolver->resolveSupplier($context, $supplierQuery);

        if ($supplierRes['status'] === 'none') {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'supplier',
                'message' => "Could not find any active supplier matching '{$supplierQuery}'. Please check the supplier name or create the supplier first.",
            ], JSON_THROW_ON_ERROR);
        }

        if ($supplierRes['status'] === 'candidates') {
            $names = $supplierRes['candidates']->map(function ($s) {
                $tax = !empty($s->vat_number) ? " (GSTIN: {$s->vat_number})" : '';
                return "{$s->name}{$tax}";
            })->all();

            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'supplier',
                'message' => "Multiple suppliers matched '{$supplierQuery}': " . implode(', ', $names) . ". Please specify which supplier you meant.",
                'candidates' => $supplierRes['candidates']->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            ], JSON_THROW_ON_ERROR);
        }

        $supplier = $supplierRes['supplier'];

        // 2. Resolve Warehouse
        $warehouseQuery = !empty($args['warehouse_query']) ? (string) $args['warehouse_query'] : null;
        $warehouseRes = $this->resolver->resolveWarehouse($context, $warehouseQuery);

        if ($warehouseRes['status'] === 'none' || !$warehouseRes['warehouse']) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'warehouse',
                'message' => 'No active warehouse found for the current branch. Please configure a warehouse before placing orders.',
            ], JSON_THROW_ON_ERROR);
        }

        $warehouse = $warehouseRes['warehouse'];

        // 3. Resolve Line Items
        $rawItems = (array) ($args['items'] ?? []);
        if (empty($rawItems)) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'items',
                'message' => 'At least one product item is required to create a purchase order.',
            ], JSON_THROW_ON_ERROR);
        }

        $resolvedItems = [];
        foreach ($rawItems as $index => $item) {
            $productQuery = (string) ($item['product_query'] ?? '');
            $prodRes = $this->resolver->resolveProduct($context, $productQuery);

            if ($prodRes['status'] === 'none') {
                return json_encode([
                    'status' => 'needs_input',
                    'missing_field' => "items.{$index}.product",
                    'message' => "Could not find any product matching '{$productQuery}'. Please check the product name or code.",
                ], JSON_THROW_ON_ERROR);
            }

            if ($prodRes['status'] === 'candidates') {
                $names = $prodRes['candidates']->map(fn ($p) => "{$p->name} ({$p->code})")->all();
                return json_encode([
                    'status' => 'needs_input',
                    'missing_field' => "items.{$index}.product",
                    'message' => "Multiple products matched '{$productQuery}': " . implode(', ', $names) . ". Please specify the exact product.",
                    'candidates' => $prodRes['candidates']->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->all(),
                ], JSON_THROW_ON_ERROR);
            }

            $product = $prodRes['product'];
            $qty = (float) ($item['qty'] ?? 1);
            $cost = isset($item['net_unit_cost']) && (float) $item['net_unit_cost'] > 0
                ? (float) $item['net_unit_cost']
                : (float) ($product->cost ?? 0);

            $purchaseUnitId = $this->resolver->resolveUnit($product, $item['uom'] ?? null);

            $resolvedLine = [
                'product_id' => $product->id,
                'qty' => sprintf('%.2f', $qty),
                'net_unit_cost' => sprintf('%.2f', $cost),
                'purchase_unit_id' => $purchaseUnitId,
                'discount' => sprintf('%.2f', (float) ($item['discount'] ?? 0)),
                'note' => isset($item['note']) ? (string) $item['note'] : null,
            ];

            if (isset($item['tax_rate']) && $item['tax_rate'] !== null && $item['tax_rate'] !== '') {
                $resolvedLine['tax_rate'] = sprintf('%.2f', (float) $item['tax_rate']);
            }

            $resolvedItems[] = $resolvedLine;
        }

        // 4. Construct validated slots
        $orderDate = !empty($args['date']) ? (string) $args['date'] : now()->toDateString();
        $slots = [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'date' => $orderDate,
            'items' => $resolvedItems,
            'note' => !empty($args['note']) ? (string) $args['note'] : null,
        ];

        if (isset($args['order_discount']) && (float) $args['order_discount'] > 0) {
            $slots['order_discount'] = (float) $args['order_discount'];
        }
        if (isset($args['shipping_cost']) && (float) $args['shipping_cost'] > 0) {
            $slots['shipping_cost'] = (float) $args['shipping_cost'];
        }

        // 5. Invoke ActionProposalService to create versioned proposal
        try {
            $proposalService = app(ActionProposalService::class);
            $proposal = $proposalService->create(
                $this->principal,
                'purchase_order.create.v1',
                $slots,
                $this->principal->requestId
            );

            $preview = json_decode((string) ($proposal['preview_json'] ?? '{}'), true) ?? [];

            $factData = array_merge($preview, [
                'proposal_id' => $proposal['id'],
                'version' => (int) $proposal['version'],
                'action_key' => 'purchase_order.create.v1',
            ]);

            return json_encode([
                'status' => 'ok',
                'tool' => 'purchase_order.create.v1',
                'as_of' => $orderDate,
                'data' => $factData,
                'source_refs' => ['/purchases'],
                'reconciled' => true,
                'row_count' => count($resolvedItems),
            ], JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'validation',
                'message' => implode(' ', $e->validator->errors()->all()),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_DOMAIN_EXECUTION_FAILURE',
                'message' => 'Could not generate purchase order proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}
