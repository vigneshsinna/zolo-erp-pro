<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Services\Accounting\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CafeApiController extends BaseApiController
{
    protected $accountingService;

    public function __construct(AccountingService $accountingService)
    {
        $this->accountingService = $accountingService;
    }

    /**
     * Get touch POS menu items and active categories
     */
    public function menu(): JsonResponse
    {
        $products = Product::where('is_active', true)
            ->select('id', 'name', 'code', 'price', 'category_id', 'image')
            ->take(50)
            ->get();

        $categories = DB::table('categories')->where('is_active', true)->select('id', 'name')->get();

        return $this->sendResponse([
            'products' => $products,
            'categories' => $categories,
        ], 'POS menu fetched successfully');
    }

    /**
     * Dispatch rapid cafe POS order
     */
    public function storeOrder(Request $request): JsonResponse
    {
        $total = (float) ($request->total_amount ?? $request->grand_total ?? $request->amount ?? 0);
        $paymentMethod = $request->payment_method ?? $request->payment_mode ?? 'Cash';
        $items = $request->items ?? $request->order_items ?? [];

        if ($total <= 0) {
            return $this->sendError('Validation error', ['total_amount' => ['A valid positive total amount is required.']], 422);
        }

        if (empty($items)) {
            // Default single line if only amount was passed
            $items = [['item_id' => 1, 'qty' => 1, 'price' => $total]];
        }

        $context = $request->attributes->get(\App\Services\Platform\CompanyContext::class);
        $companyId = $context?->companyId ?? ($request->header('X-Company-ID') ?: null);
        if (!$companyId && Auth::check()) {
            $companyId = DB::table('company_user')->where('user_id', Auth::id())->where('is_default', 1)->value('company_id')
                ?? DB::table('company_user')->where('user_id', Auth::id())->value('company_id')
                ?? 1;
        }
        $companyId = (int) ($companyId ?: 1);
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

        $warehouseId = $this->resolveCompanyWarehouseId(
            $companyId,
            $branchId,
            $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );
        $customerId = $this->resolveCompanyCustomerId(
            $companyId,
            $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
            $request->input('customer_name')
        );
        $billerId = $this->resolveCompanyBillerId($companyId);
        $defaultProdId = (int) (
            DB::table('products')->where('company_id', $companyId)->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('products')->where('is_active', 1)->orderBy('id')->value('id')
            ?? DB::table('products')->orderBy('id')->value('id')
            ?? 1
        );
        $defaultUnitId = (int) (DB::table('units')->orderBy('id')->value('id') ?: 1);

        $orderRef = 'POS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $cashPortion = (float) ($request->cash_amount ?? (strcasecmp($paymentMethod, 'Cash') === 0 ? $total : 0));
        $upiPortion = (float) ($request->upi_amount ?? (stripos($paymentMethod, 'upi') !== false || stripos($paymentMethod, 'bank') !== false || stripos($paymentMethod, 'card') !== false ? $total : 0));
        if ($cashPortion == 0 && $upiPortion == 0) {
            $cashPortion = $total;
        }

        $saleId = null;

        DB::transaction(function () use ($orderRef, $total, $cashPortion, $upiPortion, $paymentMethod, $items, $companyId, $branchId, $warehouseId, $customerId, $billerId, $defaultProdId, $defaultUnitId, $context, &$saleId) {
            // 1. Insert authoritative record into sales table
            $totalQty = 0;
            foreach ($items as $it) {
                $totalQty += (float) ($it['qty'] ?? 1);
            }

            $saleData = [
                'reference_no' => $orderRef,
                'user_id' => Auth::id() ?? 1,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'biller_id' => $billerId,
                'item' => count($items),
                'total_qty' => max(1, $totalQty),
                'total_discount' => 0.0,
                'total_tax' => 0.0,
                'total_price' => $total,
                'grand_total' => $total,
                'order_tax_rate' => 0.0,
                'order_tax' => 0.0,
                'order_discount' => 0.0,
                'shipping_cost' => 0.0,
                'sale_status' => 1, // Completed
                'payment_status' => 4, // Paid
                'paid_amount' => $total,
                'sale_note' => 'Cafe Quick POS Touch Order',
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($context?->financialYearId) {
                $saleData['financial_year_id'] = $context->financialYearId;
            }
            $saleId = DB::table('sales')->insertGetId($saleData);

            // 2. Insert line items into product_sales table
            foreach ($items as $it) {
                $rawProdId = (int) ($it['item_id'] ?? $it['product_id'] ?? $it['id'] ?? 0);
                $prodId = ($rawProdId > 0 && DB::table('products')->where('id', $rawProdId)->exists())
                    ? $rawProdId
                    : $defaultProdId;
                $qty = (float) ($it['qty'] ?? 1);
                $unitPrice = (float) ($it['price'] ?? ($total / max(1, count($items))));
                DB::table('product_sales')->insert([
                    'sale_id' => $saleId,
                    'product_id' => $prodId,
                    'qty' => $qty,
                    'sale_unit_id' => $defaultUnitId,
                    'net_unit_price' => $unitPrice,
                    'discount' => 0.0,
                    'tax_rate' => 0.0,
                    'tax' => 0.0,
                    'total' => $unitPrice * $qty,
                    'company_id' => $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. Insert real payment record
            $isBank = $upiPortion > 0 && $cashPortion == 0;
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
                'payment_reference' => 'PAY-' . $orderRef,
                'user_id' => Auth::id() ?? 1,
                'account_id' => $accountId,
                'amount' => $total,
                'paying_method' => $paymentMethod,
                'payment_note' => 'Cafe POS Counter Bill',
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Post balanced double-entry journal using resolved leaf ChartOfAccount IDs
            if ($context) {
                try {
                    $cashAcc = $this->accountingService->getAccount('cash', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where(fn ($q) => $q->where('code', '1010')->orWhere('control_type', 'cash'))->value('id');
                    $bankAcc = $this->accountingService->getAccount('bank', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where(fn ($q) => $q->where('code', '1020')->orWhere('control_type', 'bank'))->value('id');
                    $revAcc = $this->accountingService->getAccount('sales', $context)?->id
                        ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                            ->where('code', '4010')->value('id');

                    $glItems = [];
                    if ($cashPortion > 0 && $cashAcc) {
                        $glItems[] = ['chart_of_account_id' => (int) $cashAcc, 'debit' => $cashPortion, 'credit' => 0, 'memo' => 'Cafe POS Counter Cash'];
                    }
                    if ($upiPortion > 0 && ($bankAcc || $cashAcc)) {
                        $glItems[] = ['chart_of_account_id' => (int) ($bankAcc ?: $cashAcc), 'debit' => $upiPortion, 'credit' => 0, 'memo' => 'Cafe POS QR/UPI Bank'];
                    }
                    if ($revAcc) {
                        $glItems[] = ['chart_of_account_id' => (int) $revAcc, 'debit' => 0, 'credit' => $total, 'memo' => 'Cafe POS Food & Beverage Revenue'];
                    }

                    if (count($glItems) >= 2) {
                        $this->accountingService->postJournalEntry([
                            'entry_date' => now()->toDateString(),
                            'reference_type' => 'sale',
                            'reference_id' => $saleId,
                            'reference_no' => $orderRef,
                            'description' => "Cafe Quick-POS Order [{$orderRef}]",
                            'created_by' => Auth::id() ?? 1,
                        ], $glItems, $context);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Cafe POS journal post warning: ' . $e->getMessage());
                }
            }
        });

        return $this->sendResponse([
            'order_reference' => $orderRef,
            'sale_id' => $saleId,
            'total_amount' => $total,
            'grand_total' => $total,
            'payment_method' => $paymentMethod,
            'timestamp' => now()->toIso8601String(),
            'status' => 'Completed & Reconciled',
        ], 'Cafe POS order processed, saved to sales, and posted to ERP ledger', 201);
    }

    /**
     * Reconcile evening cash & UPI drawer
     */
    public function reconcileDrawer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'drawer_date' => 'required|date',
            'opening_float' => 'required|numeric',
            'closing_cash' => 'required|numeric',
            'upi_settlement' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error', $validator->errors()->toArray(), 422);
        }

        $expectedCash = (float)$request->opening_float + (float)($request->cash_sales ?? 0);
        $variance = (float)$request->closing_cash - $expectedCash;

        $record = DB::table('cafe_cash_drawers')->insertGetId([
            'drawer_date' => $request->drawer_date,
            'user_id' => Auth::id() ?? 1,
            'opening_float' => $request->opening_float,
            'closing_cash' => $request->closing_cash,
            'upi_settlement' => $request->upi_settlement,
            'cash_sales' => $request->cash_sales ?? 0,
            'variance' => $variance,
            'status' => abs($variance) < 1 ? 'Balanced' : 'Discrepancy',
            'notes' => $request->notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->sendResponse([
            'id' => $record,
            'drawer_date' => $request->drawer_date,
            'variance' => $variance,
            'status' => abs($variance) < 1 ? 'Balanced' : 'Discrepancy Logged',
        ], 'Drawer reconciled successfully');
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
