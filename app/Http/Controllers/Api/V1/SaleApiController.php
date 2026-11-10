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

        $grandTotal = (float) ($request->input('grand_total') ?? $request->input('total_amount') ?? 0);
        $paidAmount = (float) ($request->input('paid_amount') ?? $grandTotal);
        $qty = max(1, (float) ($request->input('qty') ?? 1));
        $unitPrice = (float) ($request->input('unit_price') ?? ($grandTotal / $qty));
        $paymentMode = $request->input('payment_mode') ?? 'Cash';

        if ($grandTotal <= 0) {
            return $this->sendError('Validation Error', ['grand_total' => ['Total amount must be positive.']], 422);
        }

        $customerId = $this->resolveCompanyCustomerId(
            $companyId,
            $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
            $request->input('customer_name')
        );
        $billerId = $this->resolveCompanyBillerId($companyId);
        $warehouseId = $this->resolveCompanyWarehouseId(
            $companyId,
            $branchId,
            $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );
        $rawProdId = (int) ($request->input('product_id') ?: 0);
        $productId = (int) (
            ($rawProdId > 0 && DB::table('products')->where('id', $rawProdId)->exists() ? $rawProdId : null)
            ?? DB::table('products')->where('company_id', $companyId)->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('products')->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('products')->orderBy('id')->value('id')
            ?? 1
        );
        $defaultUnitId = (int) (DB::table('units')->orderBy('id')->value('id') ?: 1);
        $refNo = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $saleId = null;
        DB::transaction(function () use (
            $companyId, $branchId, $customerId, $billerId, $warehouseId, $productId, $defaultUnitId,
            $refNo, $qty, $unitPrice, $grandTotal, $paidAmount, $paymentMode, $context, &$saleId
        ) {
            $saleData = [
                'reference_no' => $refNo,
                'user_id' => Auth::id() ?? 1,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'biller_id' => $billerId,
                'item' => 1,
                'total_qty' => $qty,
                'total_discount' => 0.0,
                'total_tax' => 0.0,
                'total_price' => $grandTotal,
                'grand_total' => $grandTotal,
                'order_tax_rate' => 0.0,
                'order_tax' => 0.0,
                'order_discount' => 0.0,
                'shipping_cost' => 0.0,
                'sale_status' => 1,
                'payment_status' => $paidAmount >= $grandTotal ? 4 : ($paidAmount > 0 ? 3 : 2),
                'paid_amount' => $paidAmount,
                'sale_note' => 'Mobile App Sale Bill',
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($context?->financialYearId) {
                $saleData['financial_year_id'] = $context->financialYearId;
            }
            $saleId = DB::table('sales')->insertGetId($saleData);

            DB::table('product_sales')->insert([
                'sale_id' => $saleId,
                'product_id' => $productId,
                'qty' => $qty,
                'sale_unit_id' => $defaultUnitId,
                'net_unit_price' => $unitPrice,
                'discount' => 0.0,
                'tax_rate' => 0.0,
                'tax' => 0.0,
                'total' => $grandTotal,
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($paidAmount > 0) {
                $isBank = stripos($paymentMode, 'upi') !== false || stripos($paymentMode, 'bank') !== false || stripos($paymentMode, 'card') !== false;
                $accountId = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('is_active', 1)
                    ->where(fn ($q) => $isBank
                        ? $q->where('name', 'like', '%bank%')->orWhere('account_no', 'like', '%bank%')
                        : $q->where('name', 'like', '%cash%')->orWhere('account_no', 'like', '%cash%'))
                    ->value('id')
                    ?? DB::table('accounts')->where('company_id', $companyId)->where('is_active', 1)->value('id')
                    ?? 1;

                DB::table('payments')->insert([
                    'sale_id' => $saleId,
                    'payment_reference' => 'PAY-' . $refNo,
                    'user_id' => Auth::id() ?? 1,
                    'account_id' => $accountId,
                    'amount' => $paidAmount,
                    'paying_method' => $paymentMode,
                    'payment_note' => 'Mobile Sale Receipt',
                    'company_id' => $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($context) {
                try {
                    $accService = app(AccountingService::class);
                    $cashAcc = $accService->getAccount('cash', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where(fn ($q) => $q->where('code', '1010')->orWhere('control_type', 'cash'))->value('id');
                    $bankAcc = $accService->getAccount('bank', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where(fn ($q) => $q->where('code', '1020')->orWhere('control_type', 'bank'))->value('id');
                    $arAcc = $accService->getAccount('ar', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where('control_type', 'ar')->value('id');
                    $revAcc = $accService->getAccount('sales', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where('code', '4010')->value('id');

                    $glItems = [];
                    if ($paidAmount > 0) {
                        $isBank = stripos($paymentMode, 'upi') !== false || stripos($paymentMode, 'bank') !== false || stripos($paymentMode, 'card') !== false;
                        $depAcc = $isBank ? ($bankAcc ?: $cashAcc) : $cashAcc;
                        if ($depAcc) {
                            $glItems[] = ['chart_of_account_id' => (int) $depAcc, 'debit' => $paidAmount, 'credit' => 0, 'memo' => 'Mobile Sale Receipt'];
                        }
                    }
                    $due = max(0.0, $grandTotal - $paidAmount);
                    if ($due > 0 && $arAcc) {
                        $glItems[] = [
                            'chart_of_account_id' => (int) $arAcc,
                            'debit' => $due,
                            'credit' => 0,
                            'memo' => 'Customer Credit Due',
                            'partner_type' => 'customer',
                            'partner_id' => $customerId,
                        ];
                    }
                    if ($revAcc) {
                        $glItems[] = ['chart_of_account_id' => (int) $revAcc, 'debit' => 0, 'credit' => $grandTotal, 'memo' => 'Sales Revenue'];
                    }

                    if (count($glItems) >= 2) {
                        $accService->postJournalEntry([
                            'entry_date' => now()->toDateString(),
                            'reference_type' => 'sale',
                            'reference_id' => $saleId,
                            'reference_no' => $refNo,
                            'description' => "Mobile Quick Sale [{$refNo}]",
                            'created_by' => Auth::id() ?? 1,
                        ], $glItems, $context);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Mobile quick sale journal warning: ' . $e->getMessage());
                }
            }
        });

        return $this->sendResponse([
            'id' => $saleId,
            'reference_no' => $refNo,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
        ], 'Mobile sale recorded and posted to general ledger', 201);
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
        $existing = DB::table('customers')->where('company_id', $companyId)->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('customers')->where('company_id', $companyId)->orderBy('id')->value('id');
        if ($existing) {
            return (int) $existing;
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
            'city' => 'Erode',
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
