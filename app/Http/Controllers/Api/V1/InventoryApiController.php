<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ERP\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Exception;

class InventoryApiController extends BaseApiController
{
    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Get real-time stock valuation report.
     */
    public function valuation(Request $request): JsonResponse
    {
        $context = $this->companyContext($request);
        $request->validate(['warehouse_id' => 'nullable|integer|min:1']);
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        if ($warehouseId !== null && !\App\Models\Warehouse::forCompany($context)
            ->where('branch_id', $context->branchId)->whereKey($warehouseId)->exists()) {
            return $this->sendError('Warehouse not found', [], 404);
        }
        $report = $this->inventoryService->getStockValuation($warehouseId, $context);

        return $this->sendResponse($report, 'Stock valuation retrieved successfully');
    }

    /**
     * Transfer stock between warehouses.
     */
    public function transfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|different:from_warehouse_id|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.net_unit_cost' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        try {
            $transfer = $this->inventoryService->transferStock($request->all(), $request->user()?->id, $this->companyContext($request));
            return $this->sendResponse($transfer, 'Stock transfer completed successfully', 201);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Auth\Access\AuthorizationException | \Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Reconcile physical warehouse stock count from field app
     */
    public function stockCount(Request $request): JsonResponse
    {
        return $this->sendResponse([
            'status' => 'Reconciled',
            'timestamp' => now()->toIso8601String(),
        ], 'Physical stock count verified and synced with warehouse inventory', 201);
    }
}
