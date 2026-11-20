<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Services\Platform\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use App\Services\ERP\SaleService;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class SaleApiController extends BaseApiController
{
    protected SaleService $saleService;

    public function __construct(SaleService $saleService)
    {
        $this->saleService = $saleService;
    }

    private function queryFor(CompanyContext $context): Builder
    {
        return Sale::visibleIn($context)->with([
            'customer' => fn ($q) => $q->forCompany($context),
            'warehouse' => fn ($q) => $q->forCompany($context),
            'biller' => fn ($q) => $q->forCompany($context),
            'payments' => fn ($q) => $q->visibleIn($context),
        ]);
    }

    /** Get paginated sales with optional filters within the authorized context. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $query = $this->queryFor($this->companyContext($request));

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        $perPage = (int) $request->input('per_page', 15);
        $sales = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->sendResponse(\App\Http\Resources\CommercialDocumentResource::collection($sales->getCollection())->resolve($request), 'Sales retrieved successfully', 200, [
            'pagination' => [
                'total' => $sales->total(),
                'per_page' => $sales->perPage(),
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
            ]
        ]);
    }

    /**
     * Get detailed sale with line items, payments, and double-entry journal entry.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $context = $this->companyContext($request);
        $sale = $this->queryFor($context)->with([
            'productSales' => fn ($q) => $q->forCompany($context)
                ->whereHas('product', fn ($q) => $q->forCompany($context))
                ->with(['product' => fn ($q) => $q->catalogFor($context)]),
        ])->find($id);

        if (!$sale) {
            return $this->sendError('Sale not found', [], 404);
        }

        // Fetch associated double-entry journal entry
        $journalEntry = JournalEntry::withOwnedItems($context)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->first();

        $data = (new \App\Http\Resources\CommercialDocumentResource($sale))->resolve($request);
        $data['journal_entry'] = $journalEntry;

        return $this->sendResponse($data, 'Sale retrieved successfully');
    }

    /**
     * Create a new sale through the ERP engine.
     */
    public function store(Request $request): JsonResponse
    {
        // Support simplified mobile offline quick-sale bill submission
        if (!$request->has('items') && ($request->filled('client_timestamp') || $request->filled('item_name')) && $request->filled('grand_total')) {
            return $this->storeMobileQuickSale($request);
        }

        if (config('commercial.enabled')) {
            return app(\App\Http\Middleware\RequireSharedCommercial::class)->handle($request,
                fn ($request) => app(\App\Http\Controllers\CommercialController::class)->store($request, 'sale'));
        }
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.net_unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        try {
            $sale = $this->saleService->createSale($request->all(), $request->user()?->id, $this->companyContext($request));
            return $this->sendResponse($sale, 'Sale created and double-entry posted successfully', 201);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Auth\Access\AuthorizationException | \Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Add a payment against an existing sale.
     */
    public function addPayment(Request $request, int $id): JsonResponse
    {
        if (config('commercial.enabled')) {
            return app(\App\Http\Middleware\RequireSharedCommercial::class)->handle($request,
                fn ($request) => app(\App\Http\Controllers\CommercialController::class)->payment($request, 'sale', $id));
        }
        $sale = Sale::visibleIn($this->companyContext($request))->find($id);
        if (!$sale) {
            return $this->sendError('Sale not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'paying_method' => 'required|string',
            'account_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        try {
            $payment = $this->saleService->addPayment($sale, $request->all(), $request->user()?->id, $this->companyContext($request));
            return $this->sendResponse($payment, 'Payment recorded and posted successfully', 201);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Auth\Access\AuthorizationException | \Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * Quick mobile sale bill fallback for simplified single-item/offline bills.
     */
    protected function storeMobileQuickSale(Request $request): JsonResponse
    {
        $context = $request->attributes->get(\App\Services\Platform\CompanyContext::class);
        $companyId = (int) ($context?->companyId ?? $request->header('X-Company-ID') ?? $request->attributes->get('erp.company_id') ?? 1);
        $branchId = $context?->branchId ?? ($request->header('X-Branch-ID') ? (int) $request->header('X-Branch-ID') : null);
        if ($branchId === null && Auth::check()) {
            $branchId = DB::table('company_user_branches as cub')
                ->join('company_branches as cb', 'cb.id', '=', 'cub.branch_id')
                ->where('cub.company_id', $companyId)
                ->where('cub.user_id', Auth::id())
                ->where('cb.company_id', $companyId)
                ->where('cb.is_active', 1)
                ->orderByRaw("CASE WHEN cb.branch_type = 'main' THEN 0 ELSE 1 END")
                ->orderBy('cb.id')
                ->value('cb.id');
            $branchId = $branchId ? (int) $branchId : null;
        }
        $branchId = (int) ($branchId
            ?? DB::table('company_branches')->where('company_id', $companyId)->where('is_active', 1)->orderBy('id')->value('id')
            ?? 1);

        if (!$context && Auth::check()) {
            try {
                $context = app(\App\Services\Platform\CompanyContextResolver::class)->resolve(Auth::id(), $companyId, $branchId);
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $context = null;
            }
        }
        if ($context) {
            $companyId = $context->companyId;
            $branchId = $context->branchId;
        }

        // Enforce sales-add permission before proceeding
        if (Auth::check()) {
            $role = \Spatie\Permission\Models\Role::find(Auth::user()->role_id);
            if ($role && !$role->hasPermissionTo('sales-add')) {
                return $this->sendError(__('db.not_permitted'), [], 403);
            }
        }
        if ($context && Auth::check()) {
            app(\App\Services\Commercial\CommercialPermission::class)->assert('sales-add', $context, Auth::id());
        }

        $grandTotal = (float) ($request->input('grand_total') ?? $request->input('total_amount') ?? 0);
        $paidAmountInput = $request->input('paid_amount');
        $paidAmount = $paidAmountInput !== null ? (float) $paidAmountInput : $grandTotal;

        if ($grandTotal <= 0) {
            return $this->sendError('Validation Error', ['grand_total' => ['Total amount must be positive.']], 422);
        }
        if ($paidAmount < 0 || $paidAmount > $grandTotal) {
            return $this->sendError('Validation Error', ['paid_amount' => ['Paid amount must be between 0 and total amount.']], 422);
        }

        // Honor company-scoped Idempotency-Key if provided
        $idempotencyKey = $request->header('Idempotency-Key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->input('idempotency_key')
            ?? ($request->filled('client_timestamp') ? 'mobile:' . $companyId . ':' . Auth::id() . ':' . $request->input('client_timestamp') : null);

        if ($idempotencyKey) {
            $retry = DB::table('idempotency_keys')
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->first();
            if ($retry && $retry->response_type === 'sale') {
                $existingSale = DB::table('sales')->where('id', $retry->response_ref)->first();
                if ($existingSale) {
                    return $this->sendResponse([
                        'id' => $existingSale->id,
                        'reference_no' => $existingSale->reference_no,
                        'grand_total' => (float) $existingSale->grand_total,
                        'paid_amount' => (float) $existingSale->paid_amount,
                    ], 'Mobile sale retrieved (idempotent)', 200);
                }
            }
        }

        $qty = max(0.01, (float) ($request->input('qty') ?? 1));
        $unitPrice = (float) ($request->input('unit_price') ?? ($grandTotal / $qty));
        $rawPaymentMode = (string) ($request->input('payment_mode') ?? 'Cash');
        $payingMethod = match (strtoupper($rawPaymentMode)) {
            'UPI', 'QR', 'ONLINE', 'TRANSFER', 'BANK' => 'Bank',
            'CHEQUE', 'CHECK' => 'Cheque',
            'CARD', 'CREDIT CARD', 'DEBIT CARD' => 'Credit Card',
            default => 'Cash',
        };

        // Resolve customer without fallback to an arbitrary credit customer
        try {
            $customerId = $this->resolveCompanyCustomerId(
                $companyId,
                $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
                $request->input('customer_name')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation Error', $e->errors(), 422);
        }

        $billerId = $this->resolveCompanyBillerId($companyId);
        $warehouseId = $this->resolveCompanyWarehouseId(
            $companyId,
            $branchId,
            $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );

        // Resolve product strictly against the active company catalog
        $rawProdId = (int) ($request->input('product_id') ?: 0);
        $prod = null;
        if ($rawProdId > 0) {
            $prod = DB::table('products')->where('company_id', $companyId)->where('id', $rawProdId)->where('is_active', 1)->first();
            if (!$prod) {
                return $this->sendError('Validation Error', ['product_id' => ['Product not found or unauthorized for active company.']], 422);
            }
        } elseif ($request->filled('item_name')) {
            $itemName = trim($request->input('item_name'));
            $prod = DB::table('products')->where('company_id', $companyId)
                ->where(fn ($q) => $q->where('name', $itemName)->orWhere('code', $itemName))
                ->where('is_active', 1)
                ->first();
            if (!$prod) {
                $prod = DB::table('products')->where('company_id', $companyId)->where('name', 'like', $itemName)->where('is_active', 1)->first();
            }
            if (!$prod) {
                $unitId = (int) (DB::table('units')->where('company_id', $companyId)->orderBy('id')->value('id')
                    ?? DB::table('units')->orderBy('id')->value('id') ?? 1);
                $catId = (int) (DB::table('categories')->where('company_id', $companyId)->orderBy('id')->value('id')
                    ?? DB::table('categories')->orderBy('id')->value('id') ?? 1);
                $prodId = DB::table('products')->insertGetId([
                    'name' => $itemName,
                    'code' => 'ITM-' . strtoupper(bin2hex(random_bytes(3))),
                    'type' => 'standard',
                    'barcode_symbology' => 'C128',
                    'unit_id' => $unitId,
                    'purchase_unit_id' => $unitId,
                    'sale_unit_id' => $unitId,
                    'cost' => round($unitPrice * 0.7, 2),
                    'price' => $unitPrice,
                    'category_id' => $catId,
                    'is_active' => 1,
                    'company_id' => $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('product_warehouse')->insert([
                    'product_id' => $prodId,
                    'warehouse_id' => $warehouseId,
                    'qty' => $qty,
                    'price' => $unitPrice,
                    'company_id' => $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $prod = DB::table('products')->where('id', $prodId)->first();
            }
        } else {
            return $this->sendError('Validation Error', ['product_id' => ['Specify a valid product or item name.']], 422);
        }

        $saleUnitId = $prod->sale_unit_id ?? $prod->unit_id ?? (int) (DB::table('units')->where('company_id', $companyId)->value('id') ?: 1);

        $salePayload = [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'biller_id' => $billerId,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'sale_status' => 1,
            'paying_method' => $payingMethod,
            'sale_note' => 'Mobile App Sale Bill',
            'items' => [
                [
                    'product_id' => $prod->id,
                    'qty' => $qty,
                    'net_unit_price' => $unitPrice,
                    'sale_unit_id' => $saleUnitId,
                    'discount' => 0.0,
                    'tax_rate' => 0.0,
                    'tax' => 0.0,
                    'total' => $grandTotal,
                ]
            ],
        ];

        try {
            $sale = DB::transaction(function () use ($salePayload, $context, $idempotencyKey, $companyId) {
                $sale = $this->saleService->createSale($salePayload, Auth::id(), $context);
                if ($idempotencyKey) {
                    DB::table('idempotency_keys')->insert([
                        'company_id' => $companyId,
                        'key' => $idempotencyKey,
                        'request_hash' => hash('sha256', $idempotencyKey),
                        'response_type' => 'sale',
                        'response_ref' => $sale->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                return $sale;
            });

            return $this->sendResponse([
                'id' => $sale->id,
                'reference_no' => $sale->reference_no,
                'grand_total' => (float) $sale->grand_total,
                'paid_amount' => (float) $sale->paid_amount,
            ], 'Mobile sale recorded and posted to general ledger', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation Error', $e->errors(), 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    private function resolveCompanyCustomerId(int $companyId, ?int $requestedId = null, ?string $customerName = null): int
    {
        if ($requestedId) {
            $owned = DB::table('customers')->where('company_id', $companyId)->where('id', $requestedId)->value('id');
            if ($owned) {
                return (int) $owned;
            }
            $foreignQuery = DB::table('customers');
            if ($foreignQuery instanceof \App\Support\Database\CompanyScopedBuilder) {
                $foreignQuery->withoutCompanyScope();
            }
            $foreign = $foreignQuery->where('id', $requestedId)->whereNotNull('company_id')->where('company_id', '!=', $companyId)->exists();
            if ($foreign) {
                throw \Illuminate\Validation\ValidationException::withMessages(['customer_id' => 'Select a company-owned customer.']);
            }
        }
        if ($customerName) {
            $byName = DB::table('customers')->where('company_id', $companyId)->where('name', 'like', '%' . $customerName . '%')->orderBy('id')->value('id');
            if ($byName) {
                return (int) $byName;
            }
        }

        $walkin = DB::table('customers')->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('type', 'walkin')->orWhere('name', 'like', '%walk%')->orWhere('name', 'like', '%cash%'))
            ->orderBy('id')
            ->value('id');
        if ($walkin) {
            return (int) $walkin;
        }

        $groupId = (int) (DB::table('customer_groups')->orderBy('id')->value('id') ?: 1);
        return (int) DB::table('customers')->insertGetId([
            'customer_group_id' => $groupId,
            'name' => 'Walk-in Customer',
            'print_name' => 'Walk-in Customer',
            'company_name' => 'Walk-in Customer',
            'type' => 'walkin',
            'phone_number' => '0000000000',
            'address' => 'Counter Retail',
            'city' => 'Local',
            'opening_balance' => 0,
            'is_active' => 1,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveCompanyBillerId(int $companyId): int
    {
        $billerId = DB::table('billers')->where('company_id', $companyId)->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('billers')->where('company_id', $companyId)->orderBy('id')->value('id');
        if ($billerId) {
            return (int) $billerId;
        }

        $compName = DB::table('companies')->where('id', $companyId)->value('trade_name') ?? 'Counter Biller';
        return (int) DB::table('billers')->insertGetId([
            'name' => $compName,
            'company_name' => $compName,
            'email' => "billing-{$companyId}@erp.local",
            'phone_number' => '0000000000',
            'address' => 'Main Branch',
            'city' => 'Erode',
            'is_active' => 1,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveCompanyWarehouseId(int $companyId, int $branchId, ?int $requestedId = null): int
    {
        if ($requestedId) {
            $owned = DB::table('warehouses')->where('company_id', $companyId)->where('branch_id', $branchId)->where('id', $requestedId)->value('id')
                ?? DB::table('warehouses')->where('company_id', $companyId)->where('id', $requestedId)->value('id');
            if ($owned) {
                return (int) $owned;
            }
            $foreignQuery = DB::table('warehouses');
            if ($foreignQuery instanceof \App\Support\Database\CompanyScopedBuilder) {
                $foreignQuery->withoutCompanyScope();
            }
            if ($foreignQuery->where('id', $requestedId)->whereNotNull('company_id')->where('company_id', '!=', $companyId)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['warehouse_id' => 'Select a company-owned warehouse.']);
            }
        }
        $existing = DB::table('warehouses')->where('company_id', $companyId)->where('branch_id', $branchId)->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('warehouses')->where('company_id', $companyId)->where('branch_id', $branchId)->orderBy('id')->value('id')
            ?? DB::table('warehouses')->where('company_id', $companyId)->where('is_active', 1)->orderBy('id')->value('id');
        if ($existing) {
            return (int) $existing;
        }
        return (int) DB::table('warehouses')->insertGetId([
            'name' => "Branch {$branchId} Warehouse",
            'phone' => '0000000000',
            'address' => 'Main Branch',
            'is_active' => 1,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
