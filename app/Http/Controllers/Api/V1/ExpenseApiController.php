<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Account;
use App\Services\Accounting\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExpenseApiController extends BaseApiController
{
    protected AccountingService $accountingService;

    public function __construct(AccountingService $accountingService)
    {
        $this->accountingService = $accountingService;
    }

    /**
     * List expenses for active company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->header('X-Company-ID') ?? $request->attributes->get('erp.company_id');
        $limit = min((int) ($request->input('limit', 50)), 100);

        $query = Expense::with(['expenseCategory:id,name,code', 'warehouse:id,name'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->filled('starting_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->starting_date))
            ->when($request->filled('ending_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->ending_date))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', $request->expense_category_id))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->orderBy('id', 'desc');

        $expenses = $query->paginate($limit);

        return $this->sendResponse($expenses, 'Expenses retrieved successfully');
    }

    /**
     * Quick expense capture with optional GPS & bill photo.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expense_category_id' => 'nullable',
            'category' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'warehouse_id' => 'nullable',
            'account_id' => 'nullable',
            'note' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $expenseCategoryId = $request->input('expense_category_id');
        if (!$expenseCategoryId && $request->filled('category')) {
            $expenseCategoryId = DB::table('expense_categories')
                ->where('name', 'like', '%' . $request->input('category') . '%')
                ->value('id');
        }
        $expenseCategoryId = $expenseCategoryId ?? (DB::table('expense_categories')->where('is_active', true)->value('id') ?? 1);

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

        $refNo = 'EXP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $amount = (float) $request->amount;
        $documentName = null;

        if ($request->hasFile('document')) {
            $doc = $request->file('document');
            $dir = public_path('documents/expense');
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            $ext = $doc->getClientOriginalExtension();
            $documentName = 'exp_' . date('Ymd_His') . '_' . strtoupper(bin2hex(random_bytes(3))) . '.' . $ext;
            $doc->move($dir, $documentName);
        }

        $warehouseId = $this->resolveCompanyWarehouseId(
            $companyId,
            $branchId,
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null
        );
        $accountId = $request->account_id ?? (DB::table('accounts')->where('company_id', $companyId)->where('is_active', true)->orderBy('id')->value('id') ?? 1);

        $noteText = trim(($request->input('category') ? "[{$request->input('category')}] " : '') . ($request->note ?? 'Mobile Field Expense'));
        if ($request->latitude) {
            $noteText .= " [GPS: {$request->latitude}, {$request->longitude}]";
        }

        $expense = new Expense();
        $expense->forceFill([
            'reference_no' => $refNo,
            'expense_category_id' => $expenseCategoryId,
            'warehouse_id' => $warehouseId,
            'account_id' => $accountId,
            'amount' => $amount,
            'user_id' => Auth::id() ?? 1,
            'note' => $noteText,
            'document' => $documentName,
            'company_id' => $companyId,
        ]);
        $expense->save();

        // Post balanced double-entry journal entry using resolved leaf ChartOfAccount IDs
        if ($context) {
            try {
                $expAcc = $this->accountingService->getAccount('expense', $context)?->id
                    ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                        ->where('type', 'expense')->orderBy('code')->value('id');
                $cashAcc = $this->accountingService->getAccount('cash', $context)?->id
                    ?? \App\Models\Accounting\ChartOfAccount::forCompany($context)->where('is_active', true)->whereDoesntHave('children')
                        ->where(fn ($q) => $q->where('code', '1010')->orWhere('control_type', 'cash'))->value('id');

                if ($expAcc && $cashAcc) {
                    $this->accountingService->postJournalEntry([
                        'entry_date' => date('Y-m-d'),
                        'reference_type' => 'expense',
                        'reference_id' => $expense->id,
                        'reference_no' => $refNo,
                        'description' => "Operational Expense [{$refNo}] - {$noteText}",
                        'created_by' => Auth::id() ?? 1,
                    ], [
                        ['chart_of_account_id' => (int) $expAcc, 'debit' => $amount, 'credit' => 0, 'memo' => 'Operating Expense'],
                        ['chart_of_account_id' => (int) $cashAcc, 'debit' => 0, 'credit' => $amount, 'memo' => 'Disbursed from Cash'],
                    ], $context);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Mobile expense journal post warning: ' . $e->getMessage());
            }
        }

        return $this->sendResponse($expense, 'Expense recorded successfully', 201);
    }

    /**
     * List active expense categories.
     */
    public function categories(Request $request): JsonResponse
    {
        $categories = ExpenseCategory::where('is_active', true)
            ->select('id', 'code', 'name')
            ->orderBy('name')
            ->get();

        return $this->sendResponse($categories, 'Expense categories retrieved');
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

