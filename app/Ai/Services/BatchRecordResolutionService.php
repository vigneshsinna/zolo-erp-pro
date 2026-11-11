<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\BatchResolutionResult;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Collection;

final class BatchRecordResolutionService
{
    public function __construct(
        private readonly RecordResolutionService $resolver,
    ) {
    }

    /**
     * Resolves all independently resolvable entities (supplier, warehouse, products across all lines, UOMs)
     * in a single pass without stopping on the first failure.
     *
     * @param array<string, mixed> $slots
     */
    public function resolveAll(WorkflowTaskContext $taskContext, array $slots): BatchResolutionResult
    {
        $result = new BatchResolutionResult();

        $context = $taskContext->context;
        if (!$context) {
            return $result;
        }

        // 1. Resolve Supplier (purchasing flows)
        if (isset($slots['supplier_id']) || isset($slots['supplier_query'])) {
            $this->resolveSupplierField($context, $slots, $result);
        }

        // 2. Resolve Customer (sales flows)
        if (isset($slots['customer_id']) || isset($slots['customer_query'])) {
            $this->resolveCustomerField($context, $slots, $result);
        }

        // 3. Resolve Warehouse
        $this->resolveWarehouseField($context, $slots, $result);

        // 4. Resolve Line Items (Products & Units) across every single line
        $this->resolveLineItems($context, $slots, $result);

        return $result;
    }

    private function resolveSupplierField(CompanyContext $context, array $slots, BatchResolutionResult $result): void
    {
        $supplierId = (int) ($slots['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $supplier = Supplier::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->find($supplierId);

            if ($supplier) {
                $result->setResolved('supplier_id', (int) $supplier->id, $supplier);
                return;
            }
        }

        $query = trim((string) ($slots['supplier_query'] ?? ''));
        if ($query !== '') {
            $res = $this->resolver->resolveSupplier($context, $query);

            if ($res['status'] === 'exact' && $res['supplier']) {
                $result->setResolved('supplier_id', (int) $res['supplier']->id, $res['supplier']);
            } elseif ($res['status'] === 'candidates') {
                $candidates = $res['candidates']->map(function (Supplier $s): array {
                    $vat = !empty($s->vat_number) ? " (GSTIN: {$s->vat_number})" : '';
                    return [
                        'id' => (int) $s->id,
                        'label' => "{$s->name}{$vat}",
                        'name' => $s->name,
                        'company_name' => $s->company_name,
                        'vat_number' => $s->vat_number,
                    ];
                })->all();
                $result->setCandidates('supplier_id', $candidates, $query);
            } else {
                $result->setNone('supplier_id', $query, "No active supplier found matching '{$query}'.");
            }
        }
    }

    private function resolveCustomerField(CompanyContext $context, array $slots, BatchResolutionResult $result): void
    {
        $customerId = (int) ($slots['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = Customer::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->find($customerId);

            if ($customer) {
                $result->setResolved('customer_id', (int) $customer->id, $customer);
                return;
            }
        }

        $query = trim((string) ($slots['customer_query'] ?? ''));
        if ($query !== '') {
            $res = $this->resolver->resolveCustomer($context, $query);

            if ($res['status'] === 'exact' && $res['customer']) {
                $result->setResolved('customer_id', (int) $res['customer']->id, $res['customer']);
            } elseif ($res['status'] === 'candidates') {
                $candidates = $res['candidates']->map(function (Customer $c): array {
                    $tax = !empty($c->tax_no) ? " (GSTIN: {$c->tax_no})" : '';
                    return [
                        'id' => (int) $c->id,
                        'label' => "{$c->name}{$tax}",
                        'name' => $c->name,
                        'company_name' => $c->company_name,
                        'tax_no' => $c->tax_no,
                    ];
                })->all();
                $result->setCandidates('customer_id', $candidates, $query);
            } else {
                $result->setNone('customer_id', $query, "No active customer found matching '{$query}'.");
            }
        }
    }

    private function resolveWarehouseField(CompanyContext $context, array $slots, BatchResolutionResult $result): void
    {
        $warehouseId = (int) ($slots['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $warehouse = Warehouse::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->find($warehouseId);

            if ($warehouse) {
                $result->setResolved('warehouse_id', (int) $warehouse->id, $warehouse);
                return;
            }
        }

        $query = isset($slots['warehouse_query']) ? trim((string) $slots['warehouse_query']) : null;
        if ($query !== null && $query !== '') {
            $res = $this->resolver->resolveWarehouse($context, $query);

            if ($res['status'] === 'exact' && $res['warehouse']) {
                $result->setResolved('warehouse_id', (int) $res['warehouse']->id, $res['warehouse']);
            } elseif ($res['status'] === 'candidates') {
                $candidates = $res['candidates']->map(fn (Warehouse $w): array => [
                    'id' => (int) $w->id,
                    'label' => $w->name,
                    'branch_id' => (int) $w->branch_id,
                ])->all();
                $result->setCandidates('warehouse_id', $candidates, $query);
            } else {
                $result->setNone('warehouse_id', $query, "No warehouse found matching '{$query}'.");
            }
            return;
        }

        // Automatic default resolution: primary warehouse of the active branch
        $defaultWh = Warehouse::where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('is_active', true)
            ->first();

        if ($defaultWh) {
            $result->setResolved('warehouse_id', (int) $defaultWh->id, $defaultWh);
        }
    }

    private function resolveLineItems(CompanyContext $context, array $slots, BatchResolutionResult $result): void
    {
        $rawItems = (array) ($slots['items'] ?? []);
        $isSale = isset($slots['customer_id']) || isset($slots['customer_query']);
        $mode = $isSale ? 'sale' : 'purchase';

        foreach ($rawItems as $index => $line) {
            $clientLineId = (string) ($line['client_line_id'] ?? "line-{$index}");
            $fieldKey = "items[{$clientLineId}].product_id";

            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId > 0) {
                $product = Product::where('company_id', $context->companyId)
                    ->where('is_active', true)
                    ->find($productId);

                if ($product) {
                    $result->setResolved($fieldKey, (int) $product->id, $product);
                    $this->resolveLineUnit($product, $line, $clientLineId, $result, $mode);
                    continue;
                }
            }

            $productQuery = trim((string) ($line['product_query'] ?? ''));
            if ($productQuery !== '') {
                $res = $this->resolver->resolveProduct($context, $productQuery);

                if ($res['status'] === 'exact' && $res['product']) {
                    $product = $res['product'];
                    $result->setResolved($fieldKey, (int) $product->id, $product);
                    $this->resolveLineUnit($product, $line, $clientLineId, $result, $mode);
                } elseif ($res['status'] === 'candidates') {
                    $candidates = $res['candidates']->map(fn (Product $p): array => [
                        'id' => (int) $p->id,
                        'label' => "{$p->name} (SKU: {$p->code})",
                        'name' => $p->name,
                        'code' => $p->code,
                        'cost' => (float) ($p->cost ?? 0),
                        'price' => (float) ($p->price ?? 0),
                    ])->all();
                    $result->setCandidates($fieldKey, $candidates, $productQuery);
                } else {
                    $result->setNone($fieldKey, $productQuery, "No product found matching '{$productQuery}'.");
                }
            }
        }
    }

    private function resolveLineUnit(Product $product, array $line, string $clientLineId, BatchResolutionResult $result, string $mode = 'purchase'): void
    {
        $uomQuery = !empty($line['uom']) ? (string) $line['uom'] : null;

        if ($mode === 'sale' || isset($line['sale_unit_id'])) {
            $uomKey = "items[{$clientLineId}].sale_unit_id";
            $unitId = $this->resolver->resolveUnit($product, $uomQuery, 'sale');
        } else {
            $uomKey = "items[{$clientLineId}].purchase_unit_id";
            $unitId = $this->resolver->resolveUnit($product, $uomQuery, 'purchase');
        }

        if ($unitId) {
            $unit = Unit::find($unitId);
            $result->setResolved($uomKey, (int) $unitId, $unit);
        }
    }
}
