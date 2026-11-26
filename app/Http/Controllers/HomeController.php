<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\Returns;
use App\Models\ReturnPurchase;
use App\Models\ProductPurchase;
use App\Models\Purchase;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payroll;
use App\Models\Quotation;
use App\Models\Payment;
use App\Models\Account;
use App\Models\Accounting\AccountOpenItem;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Warehouse;
use App\Services\Accounting\FinancialReportService;
use App\Services\Platform\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RewardPointSetting;
use App\Models\Unit;
use DB;
use Auth;
use Printing;
use Rawilk\Printing\Contracts\Printer;
use Spatie\Permission\Models\Role;
use App\Traits\AutoUpdateTrait;

class HomeController extends Controller
{
    use AutoUpdateTrait;

    private $versionUpgradeInfo = [];

	public function __construct()
    {
        if(!config('database.connections.zoloerp_landlord')) {
            $this->versionUpgradeInfo = $this->isUpdateAvailable();
        }
	}

    public function home()
    {
        return view('backend.home');
    }

    public function index()
    {
        return redirect('dashboard');
    }

    public function addonList()
    {
        if(!config('database.connections.zoloerp_landlord')) {
            $role = Role::find(Auth::user()->role_id);
            if(!$role->hasPermissionTo('addons')) {
                return redirect('dashboard')->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
            }
        }

        $general_setting = DB::table('general_settings')->first();
        $activeModules = array_filter(explode(',', $general_setting->modules ?? ''));
        $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
        if ($context instanceof \App\Services\Platform\CompanyContext) {
            $capabilities = app(\App\Services\Platform\CapabilityService::class);
            $activeModules = array_keys(array_filter(
                \App\Services\Platform\LegacyModuleAdapter::MODULES,
                fn ($capKey) => $capabilities->enabled($capKey, $context)
            ));
        }

        $addons = [
            [
                'key' => 'water_logistics',
                'name' => 'Water Logistics & Tanker Fleet',
                'category' => 'Industry Solution',
                'description' => 'Bulk water tanker fleet trip sheets (9KL, 16KL, 24KL), diesel & toll expense vouchers, site receiver signatures, and Tata Ace 20L Can distribution with live inventory balancing and corporate 15/30-day B2B credit aging.',
                'icon' => 'dripicons-drop',
                'tone' => 'info',
                'route' => 'water-logistics.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'cafe_bakery',
                'name' => 'Bakery & Cafe POS Operations',
                'category' => 'Hospitality & Retail',
                'description' => 'Fast touch POS counter terminal, kitchen raw materials consumption tracking (~10 ingredients), morning float and evening cash & UPI drawer reconciliation directly linked to ERP general ledger.',
                'icon' => 'dripicons-store',
                'tone' => 'warning',
                'route' => 'cafe.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'repair',
                'name' => 'Repair & RMA Service Center',
                'category' => 'Services & Hardware',
                'description' => 'Complete job sheet workflow for smartphones, laptops, electronics and water dispensers. Defect diagnosis, spare parts inventory deduction, technician notes, and revenue billing.',
                'icon' => 'dripicons-wrench',
                'tone' => 'primary',
                'route' => 'repair.dashboard',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'project_management',
                'name' => 'Project & Task Management',
                'category' => 'Productivity & Delivery',
                'description' => 'Project milestones, task delegation, team assignments, budget tracking, and progress metrics connected with customer accounts.',
                'icon' => 'dripicons-briefcase',
                'tone' => 'success',
                'route' => 'project-management.projects',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'manufacturing',
                'name' => 'Manufacturing & Production BOM',
                'category' => 'Industrial & Assembly',
                'description' => 'Bill of Materials (BOM recipes), batch production runs, automated raw material stock deduction and finished goods creation with overhead costing.',
                'icon' => 'dripicons-industrial',
                'tone' => 'primary',
                'route' => 'productions.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'installment_plans',
                'name' => 'Consumer Financing & EMI Plans',
                'category' => 'Financial Services',
                'description' => 'Customer installment plans, down payments, EMI schedule generator, auto-interest calculations, late payment penalty accruals, and ledger integration.',
                'icon' => 'dripicons-calendar',
                'tone' => 'warning',
                'route' => 'installmentplan.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'catalogue_qr',
                'name' => 'Dynamic QR Digital Catalogue',
                'category' => 'Digital Commerce',
                'description' => 'Instant contactless digital menus and interactive product catalogues accessible via table/counter QR codes, with real-time price and stock sync from ERP.',
                'icon' => 'dripicons-view-thumb',
                'tone' => 'info',
                'route' => 'qr.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'damage_stock',
                'name' => 'Damage Stock & Shrinkage Audit',
                'category' => 'Warehouse & Inventory',
                'description' => 'Log damaged, expired, or spoiled merchandise with reason codes and automated journal entries posting from Inventory Asset to Damage Loss (5010).',
                'icon' => 'dripicons-trash',
                'tone' => 'danger',
                'route' => 'damage-stock.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'exchange',
                'name' => 'Product Return & Exchange Counter',
                'category' => 'Retail POS',
                'description' => 'Counter product returns and exchanges, calculate value differentials, generate return credit notes or collect balance payments directly linked to ERP sales ledger.',
                'icon' => 'dripicons-retweet',
                'tone' => 'success',
                'route' => 'exchange.index',
                'installed' => true,
                'is_core_connected' => true,
            ],
            [
                'key' => 'api',
                'name' => 'RESTful API Engine (v1)',
                'category' => 'Developer & Mobile',
                'description' => 'High-performance Sanctum authenticated RESTful API layer for mobile driver apps, external POS hardware, automated webhooks, and third-party integrations.',
                'icon' => 'dripicons-code',
                'tone' => 'info',
                'route' => 'api.v1.auth.me',
                'installed' => true,
                'is_core_connected' => true,
            ],
        ];

        return view('backend.addonlist', compact('addons', 'activeModules', 'general_setting'));
    }

    public function addonToggle(Request $request)
    {
        if (app(\App\Services\Platform\LicenseService::class)->tablesReady()
            && !\App\Services\Platform\MenuSwitches::isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Module licensing is controlled from the Developer Portal (/developer).',
            ], 403);
        }
        $key = $request->key;
        $general_setting = DB::table('general_settings')->first();
        $current = array_filter(explode(',', $general_setting->modules ?? ''));

        if (in_array($key, $current)) {
            $updated = array_values(array_diff($current, [$key]));
            $status = false;
            $msg = "Addon deactivated.";
        } else {
            $current[] = $key;
            $updated = array_values(array_unique($current));
            $status = true;
            $msg = "Addon activated successfully.";
        }

        DB::table('general_settings')->where('id', $general_setting->id)->update([
            'modules' => implode(',', $updated),
        ]);

        return response()->json(['success' => true, 'status' => $status, 'message' => $msg]);
    }

    public function dashboard(Request $request)
    {

        if(in_array('restaurant',explode(',',cache()->get('general_setting')->modules))){
            if(Auth::user()->role_id > 2 && isset(Auth::user()->kitchen_id)){

                $result = (new \Modules\Restaurant\Http\Controllers\KitchenController)->dashboard();

                return $result;
            }
        }

        if(Auth::user()->role_id == 5) {
            $customer = Customer::select('id', 'points')->where('user_id', Auth::id())->first();
            $lims_sale_data = Sale::with('warehouse')
                                ->whereNull('deleted_at')
                                ->where('customer_id', $customer->id)
                                ->where(function($q) {
                                    $q->where('sale_type', '!=', 'opening balance')
                                    ->orWhereNull('sale_type');
                                })
                                ->orderBy('created_at', 'desc')
                                ->get();
            $lims_payment_data = DB::table('payments')
                           ->join('sales', 'payments.sale_id', '=', 'sales.id')
                           ->whereNull('sales.deleted_at')
                           ->where('customer_id', $customer->id)
                           ->select('payments.*', 'sales.reference_no as sale_reference')
                           ->orderBy('payments.created_at', 'desc')
                           ->get();
            $lims_quotation_data = Quotation::with('biller', 'customer', 'supplier', 'user')->orderBy('id', 'desc')->where('customer_id', $customer->id)->orderBy('created_at', 'desc')->get();

            $lims_return_data = Returns::with('warehouse', 'customer', 'biller')->where('customer_id', $customer->id)->orderBy('created_at', 'desc')->get();
            $lims_reward_point_setting_data = RewardPointSetting::select('per_point_amount')->latest()->first();
            return view('backend.customer_index', compact('customer', 'lims_sale_data', 'lims_payment_data', 'lims_quotation_data', 'lims_return_data', 'lims_reward_point_setting_data'));
        }

        $user = Auth::user();
        if ($user && ($user->role_id ?? 0) >= 4) {
            $userName = strtolower(trim($user->name ?? ''));
            $userEmail = strtolower(trim($user->email ?? ''));

            $isTankerDriver = str_contains($userName, 'tanker') || str_contains($userEmail, 'tanker');
            $isDeliveryDriver = (str_contains($userName, 'driver') || str_contains($userEmail, 'driver')) && !$isTankerDriver;
            $isCashier = str_contains($userName, 'cashier') || str_contains($userEmail, 'cashier');
            $isFieldAgent = str_contains($userName, 'agent') || str_contains($userEmail, 'agent') || str_contains($userName, 'field');

            if ($isTankerDriver) {
                $response = app(\App\Http\Controllers\Api\V1\WaterLogisticsApiController::class)->driverManifest($request);
                $manifest = $response->getData(true)['data'] ?? [];
                return view('backend.role_dashboards.tanker_driver', compact('manifest'));
            }

            if ($isDeliveryDriver) {
                $response = app(\App\Http\Controllers\Api\V1\WaterLogisticsApiController::class)->driverManifest($request);
                $manifest = $response->getData(true)['data'] ?? [];
                return view('backend.role_dashboards.can_driver', compact('manifest'));
            }

            if ($isCashier) {
                $response = app(\App\Http\Controllers\Api\V1\WaterLogisticsApiController::class)->roleDashboard($request, 'cashier');
                $metrics = $response->getData(true)['data'] ?? [];
                return view('backend.role_dashboards.cashier', compact('metrics'));
            }

            if ($isFieldAgent) {
                $response = app(\App\Http\Controllers\Api\V1\WaterLogisticsApiController::class)->roleDashboard($request, 'fieldAgent');
                $metrics = $response->getData(true)['data'] ?? [];
                return view('backend.role_dashboards.field_agent', compact('metrics'));
            }
        }

        $context = $request->attributes->get(CompanyContext::class);
        $company = Company::with('group')->findOrFail($context->companyId);
        $year = FiscalYear::where('company_id', $context->companyId)->findOrFail($context->financialYearId);
        $yearStart = CarbonImmutable::parse($year->start_date);
        $yearEnd = CarbonImmutable::parse($year->end_date);
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $reportDate = $today->max($yearStart)->min($yearEnd->endOfDay());
        $request->query->remove('branch_scope');
        $period = $request->validate(['period' => 'sometimes|in:today,week,month,year'])['period'] ?? 'month';
        $warehouseId = $this->dashboardWarehouseId($request->query('warehouse_id', 0));
        $warehouses = Warehouse::where('branch_id', $context->branchId)->where('is_active', true)->orderBy('name')->get();

        $start = match ($period) {
            'today' => $reportDate,
            'week' => $reportDate->subDays(6),
            'month' => $reportDate->startOfMonth(),
            'year' => $yearStart,
        };
        $start = max($start->toDateString(), $yearStart->toDateString());
        $end = $reportDate->toDateString();

        $reports = app(FinancialReportService::class);
        $entries = $this->dashboardLedger($context, $reports);
        $ledger = $entries !== null;
        $metrics = $this->dashboardMetrics($start, $end, $warehouseId, $context, $reports, $ledger);

        $months = [];
        for ($month = $yearStart->startOfMonth(); $month->lte($reportDate); $month = $month->addMonth()) {
            $months[] = $month;
        }

        $monthlyTotals = function (Builder $query, string $amount) use ($yearStart, $end, $months) {
            $totals = $query->whereDate($query->qualifyColumn('created_at'), '>=', $yearStart->toDateString())
                ->whereDate($query->qualifyColumn('created_at'), '<=', $end)
                ->selectRaw("SUBSTR(".$query->qualifyColumn('created_at').", 1, 7) as month_key, SUM($amount) as total")
                ->groupBy('month_key')->pluck('total', 'month_key');
            return array_map(fn ($month) => (float) ($totals[$month->format('Y-m')] ?? 0), $months);
        };

        $yearly_sale_amount = $monthlyTotals($this->dashboardSales($warehouseId), '(grand_total - COALESCE(shipping_cost, 0)) / COALESCE(NULLIF(exchange_rate, 0), 1)');
        $yearly_purchase_amount = $monthlyTotals($this->dashboardPurchases($warehouseId), 'grand_total / COALESCE(NULLIF(exchange_rate, 0), 1)');
        $monthlyPurchaseReturns = $monthlyTotals($this->dashboardReturns(ReturnPurchase::class, $warehouseId), 'grand_total / COALESCE(NULLIF(exchange_rate, 0), 1)');
        foreach ($yearly_purchase_amount as $index => &$amount) {
            $amount -= $monthlyPurchaseReturns[$index];
        }
        unset($amount);

        $cashAccounts = ChartOfAccount::forCompany($context)->whereIn('control_type', ['cash', 'bank']);
        $liquid_balance = $ledger
            ? $cashAccounts->get()->sum(fn ($account) => $reports->openingBalance($account, $context, $today->addDay()->toDateString()))
            : Account::where('is_active', true)->sum('total_balance');

        if ($ledger) {
            // Net each entry across cash accounts so transfers between them do not count as cash flow.
            $cashPerEntry = DB::table('journal_items')
                ->joinSub($entries->whereBetween('entry_date', [$yearStart->toDateString(), $end])
                    ->select('id', 'entry_date'), 'posted_entries', 'posted_entries.id', '=', 'journal_items.journal_entry_id')
                ->where('journal_items.company_id', $context->companyId)
                ->whereIn('chart_of_account_id', $cashAccounts->select('id'))
                ->selectRaw('posted_entries.id, posted_entries.entry_date, SUM(debit - credit) as movement')
                ->groupBy('posted_entries.id', 'posted_entries.entry_date');
            $cash = DB::query()->fromSub($cashPerEntry, 'cash_entries')
                ->selectRaw("SUBSTR(entry_date, 1, 7) as month_key,
                    SUM(CASE WHEN movement > 0 THEN movement ELSE 0 END) as received,
                    SUM(CASE WHEN movement < 0 THEN -movement ELSE 0 END) as sent")
                ->groupBy('month_key')->get()->keyBy('month_key');
            $payment_recieved = array_map(fn ($month) => (float) ($cash[$month->format('Y-m')]->received ?? 0), $months);
            $payment_sent = array_map(fn ($month) => (float) ($cash[$month->format('Y-m')]->sent ?? 0), $months);
        } else {
            $payment_recieved = $monthlyTotals($this->dashboardPayments($warehouseId)->where('sale_id', '>', 0), 'amount / COALESCE(NULLIF(exchange_rate, 0), 1)');
            $payment_sent = $monthlyTotals($this->dashboardPayments($warehouseId)->where('purchase_id', '>', 0), 'amount / COALESCE(NULLIF(exchange_rate, 0), 1)');
            $expenses = $monthlyTotals($this->dashboardDocuments(Expense::class, $warehouseId), 'amount');
            $payroll = $monthlyTotals(Payroll::when($this->dashboardOwnRecords(), fn ($query) => $query->where('user_id', Auth::id())), 'amount');
            foreach ($payment_sent as $index => &$amount) {
                $amount += $expenses[$index] + $payroll[$index];
            }
            unset($amount);
        }

        $month = array_map(fn ($month) => $month->format('M Y'), $months);
        $total_customers = Customer::where('is_active', true)->count();
        $total_suppliers = \App\Models\Supplier::where('is_active', true)->count();

        $stock = DB::table('product_warehouse')
            ->join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->join('warehouses', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
            ->where('warehouses.branch_id', $context->branchId)
            ->when($warehouseId, fn ($query) => $query->where('warehouses.id', $warehouseId));
        $legacyStock = clone $stock;
        $stock_valuation = 0;
        if (Schema::hasTable('stock_movement_lines')) {
            $stockLedger = DB::table('stock_movement_lines')
                ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')
                ->join('warehouses', 'warehouses.id', '=', 'stock_movement_lines.warehouse_id')
                ->where('stock_movement_lines.company_id', $context->companyId)
                ->where('stock_movements.company_id', $context->companyId)
                ->where('stock_movements.status', 'posted')
                ->where('warehouses.branch_id', $context->branchId)
                ->when($warehouseId, fn ($query) => $query->where('warehouses.id', $warehouseId));
            $stock_valuation = $stockLedger->sum('stock_movement_lines.value');
            // Existing stock without movement history still uses its recorded unit cost.
            $legacyStock->whereNotExists($stockLedger->selectRaw('1')
                ->whereColumn('stock_movement_lines.product_id', 'products.id')
                ->whereColumn('stock_movement_lines.warehouse_id', 'product_warehouse.warehouse_id'));
        }
        $stock_valuation += $legacyStock->sum(DB::raw('product_warehouse.qty * products.cost'));

        $lowStock = $stock->where('products.is_active', true)->where('products.alert_quantity', '>', 0)
            ->groupBy('products.id', 'products.name', 'products.code', 'products.alert_quantity', 'warehouses.id', 'warehouses.name')
            ->select('products.name', 'products.code', 'products.alert_quantity', 'warehouses.name as warehouse_name')
            ->selectRaw('SUM(product_warehouse.qty) as qty')
            ->havingRaw('SUM(product_warehouse.qty) <= products.alert_quantity');
        $low_stock_count = DB::query()->fromSub(clone $lowStock, 'low_stock')->count();
        $low_stock_products = $lowStock->orderBy('qty')->orderBy('products.id')->limit(5)->get();

        $currency_model = \App\Models\Currency::find($company->base_currency_id ?: (cache()->get('general_setting')->currency ?? 1));
        $currency_symbol = $currency_model ? ($currency_model->symbol ?: ($currency_model->code == 'INR' ? '₹' : ($currency_model->code ?: '$'))) : '$';
        $versionUpgradeData = (!config('database.connections.zoloerp_landlord') && Auth::user()->role_id <= 2) ? ($this->versionUpgradeInfo ?? []) : [];

        return view('backend.index', $metrics + compact(
            'company', 'period', 'warehouseId', 'warehouses', 'start', 'end', 'year', 'reportDate', 'ledger',
            'payment_recieved', 'payment_sent', 'month', 'yearly_sale_amount', 'yearly_purchase_amount',
            'versionUpgradeData', 'liquid_balance', 'stock_valuation', 'total_customers',
            'total_suppliers', 'low_stock_count', 'low_stock_products', 'currency_symbol'
        ));
    }

    public function newVersionReleasePage()
    {
        abort(410, 'Browser upgrades are retired. Deploy a reviewed release through the operator runbook.');
    }

    public function versionUpgrade(Request $request)
    {
        abort(410, 'Browser upgrades are retired. Deploy a reviewed release through the operator runbook.');
    }

    public function fileTransferProcess($version_upgrade_file_url)
    {
        abort(410, 'Remote code extraction is retired.');
    }

    public function yearlyBestSellingPrice(Request $request)
    {
        return $this->dashboardBestSellers($request, byValue: true);
    }

    public function yearlyBestSellingQty(Request $request)
    {
        return $this->dashboardBestSellers($request);
    }

    public function monthlyBestSellingQty(Request $request)
    {
        return $this->dashboardBestSellers($request, monthly: true);
    }

    private function dashboardBestSellers(Request $request, bool $byValue = false, bool $monthly = false)
    {
        $warehouseId = $this->dashboardWarehouseId($request->query('warehouse_id', 0));
        $context = $request->attributes->get(CompanyContext::class);
        $year = FiscalYear::where('company_id', $context->companyId)->findOrFail($context->financialYearId);
        $timezone = Company::whereKey($context->companyId)->value('timezone') ?: config('app.timezone');
        $end = max($year->start_date->toDateString(), min(now($timezone)->toDateString(), $year->end_date->toDateString()));
        $start = $year->start_date->toDateString();
        if ($monthly) {
            $start = max($start, CarbonImmutable::parse($end)->startOfMonth()->toDateString());
        }
        $sales = $this->dashboardSales($warehouseId)->whereDate('sales.created_at', '>=', $start)->whereDate('sales.created_at', '<=', $end);

        return response()->json($sales->join('product_sales', 'product_sales.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'product_sales.product_id')
            ->where('products.company_id', $context->companyId)
            ->select('products.name as product_name', 'products.code as product_code', 'products.image as product_images')
            ->selectRaw($byValue
                ? 'SUM(product_sales.total / COALESCE(NULLIF(sales.exchange_rate, 0), 1)) as total_price'
                : 'SUM(product_sales.qty - COALESCE(product_sales.return_qty, 0)) as sold_qty')
            ->groupBy('products.id', 'products.name', 'products.code', 'products.image')
            ->orderByDesc($byValue ? 'total_price' : 'sold_qty')->orderBy('products.id')->take(5)->get());
    }

    public function recentSale(Request $request)
    {
        return response()->json($this->dashboardSales($this->dashboardWarehouseId($request->query('warehouse_id', 0)))
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->select('sales.id', 'sales.reference_no', 'sales.sale_status', 'sales.created_at', 'sales.grand_total', 'sales.exchange_rate', 'customers.name')
            ->selectRaw('DATE(sales.created_at) as business_date')
            ->latest('sales.created_at')->orderByDesc('sales.id')->take(5)->get());
    }

    public function recentPurchase(Request $request)
    {
        return response()->json($this->dashboardPurchases($this->dashboardWarehouseId($request->query('warehouse_id', 0)))
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->select('purchases.id', 'purchases.reference_no', 'purchases.status', 'purchases.created_at', 'purchases.grand_total', 'purchases.exchange_rate', 'suppliers.name')
            ->selectRaw('DATE(purchases.created_at) as business_date')
            ->latest('purchases.created_at')->orderByDesc('purchases.id')->take(5)->get());
    }

    public function recentQuotation(Request $request)
    {
        return response()->json($this->dashboardDocuments(Quotation::class, $this->dashboardWarehouseId($request->query('warehouse_id', 0)))
            ->join('customers', 'customers.id', '=', 'quotations.customer_id')
            ->select('quotations.id', 'quotations.reference_no', 'quotations.quotation_status', 'quotations.created_at', 'quotations.grand_total', 'customers.name')
            ->selectRaw('DATE(quotations.created_at) as business_date')
            ->latest('quotations.created_at')->orderByDesc('quotations.id')->take(5)->get());
    }

    public function recentPayment(Request $request)
    {
        return response()->json($this->dashboardPayments($this->dashboardWarehouseId($request->query('warehouse_id', 0)))
            ->select('id', 'payment_reference', 'amount', 'exchange_rate', 'paying_method', 'created_at', 'payment_at')
            ->selectRaw('DATE(COALESCE(payment_at, created_at)) as business_date')
            ->orderByRaw('COALESCE(payment_at, created_at) DESC')->orderByDesc('id')->take(5)->get());
    }

    public function dashboardFilter(Request $request, $start_date, $end_date, $warehouse_id)
    {
        Validator::validate(['start' => $start_date, 'end' => $end_date], [
            'start' => 'required|date_format:Y-m-d',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start',
        ]);
        $warehouseId = $this->dashboardWarehouseId($warehouse_id);
        $request->query->remove('branch_scope');
        $context = $request->attributes->get(CompanyContext::class);
        $reports = app(FinancialReportService::class);
        $year = FiscalYear::where('company_id', $context->companyId)->findOrFail($context->financialYearId);
        $start = max($start_date, $year->start_date->toDateString());
        $end = min($end_date, $year->end_date->toDateString());
        $metrics = $this->dashboardMetrics($start, $end, $warehouseId, $context, $reports, $this->dashboardLedger($context, $reports) !== null);

        return [$metrics['revenue'], $metrics['return'], $metrics['profit'], $metrics['purchase_return'],
            $metrics['total_sale'], $metrics['invoice_due'], $metrics['purchase'], $metrics['purchase_due'], $metrics['expense']];
    }

    private function dashboardMetrics(string $start, string $end, int $warehouseId, CompanyContext $context, FinancialReportService $reports, bool $ledger): array
    {
        $inPeriod = fn (Builder $query) => $query->whereDate($query->qualifyColumn('created_at'), '>=', $start)
            ->whereDate($query->qualifyColumn('created_at'), '<=', $end);
        $baseAmount = 'grand_total / COALESCE(NULLIF(exchange_rate, 0), 1)';
        $sales = $this->dashboardSales($warehouseId);
        $purchases = $this->dashboardPurchases($warehouseId);
        $total_sale = $inPeriod(clone $sales)->sum(DB::raw('(grand_total - COALESCE(shipping_cost, 0)) / COALESCE(NULLIF(exchange_rate, 0), 1)'));
        $return = $inPeriod($this->dashboardReturns(Returns::class, $warehouseId))->sum(DB::raw($baseAmount));
        $purchase_return = $inPeriod($this->dashboardReturns(ReturnPurchase::class, $warehouseId))->sum(DB::raw($baseAmount));
        $purchase = $inPeriod(clone $purchases)->sum(DB::raw($baseAmount)) - $purchase_return;

        if ($ledger && $start <= $end) {
            $pnl = $reports->getProfitAndLoss($start, $end, $context);
            $revenue = $pnl['total_revenue'];
            $expense = $pnl['total_operating_expenses'];
            $profit = $pnl['net_income'];
        } else {
            $expense = $inPeriod($this->dashboardDocuments(Expense::class, $warehouseId))->sum('amount');
            $income = $inPeriod($this->dashboardDocuments(Income::class, $warehouseId))->sum('amount');
            $revenue = $total_sale - $return + $income;
            $lines = $inPeriod(clone $sales)->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                ->select('product_sales.product_id', 'product_sales.product_batch_id', 'product_sales.variant_id', 'product_sales.sale_unit_id')
                ->selectRaw('SUM(product_sales.qty) as sold_qty, SUM(COALESCE(product_sales.return_qty, 0)) as return_qty')
                ->groupBy('product_sales.product_id', 'product_sales.product_batch_id', 'product_sales.variant_id', 'product_sales.sale_unit_id')
                ->get();
            $profit = $revenue + $purchase_return - $this->calculateAverageCOGS($lines) - $expense;
        }

        $invoice_due = $this->dashboardDue($sales, $context, 'customer', 'sale', $warehouseId);
        $purchase_due = $this->dashboardDue($purchases, $context, 'supplier', 'purchase', $warehouseId);
        return compact('revenue', 'expense', 'profit', 'total_sale', 'return', 'purchase_return', 'purchase', 'invoice_due', 'purchase_due');
    }

    private function dashboardDue(Builder $documents, CompanyContext $context, string $partyType, string $sourceType, int $warehouseId): float
    {
        $open = 0;
        if (Schema::hasTable('account_open_items')) {
            $items = AccountOpenItem::forCompany($context)->where('branch_id', $context->branchId)->where('party_type', $partyType);
            $tracked = (clone $items)->where('source_type', $sourceType);
            $open = $warehouseId || $this->dashboardOwnRecords()
                ? (clone $tracked)->whereIn('source_id', (clone $documents)->select($documents->qualifyColumn('id')))->sum('open_amount')
                : $items->sum('open_amount');
            $documents->whereNotIn($documents->qualifyColumn('id'), $tracked->whereNotNull('source_id')->select('source_id'));
        }

        return $open + $documents->whereColumn('paid_amount', '<', 'grand_total')
            ->sum(DB::raw('(grand_total - paid_amount) / COALESCE(NULLIF(exchange_rate, 0), 1)'));
    }

    private function dashboardLedger(CompanyContext $context, FinancialReportService $reports): ?Builder
    {
        if ($this->dashboardOwnRecords() || !Schema::hasTable('journal_entries')) {
            return null;
        }
        if (!$reports->scopeEntries(JournalEntry::query(), $context, currentYearOnly: false)->exists()) {
            return null;
        }
        return $reports->scopeEntries(JournalEntry::query(), $context);
    }

    private function dashboardWarehouseId(mixed $value): int
    {
        Validator::validate(['warehouse_id' => $value], ['warehouse_id' => 'required|integer|min:0']);
        $id = (int) $value;
        if ($id) {
            abort_unless(Auth::user()->role_id <= 2, 403);
            $context = request()->attributes->get(CompanyContext::class);
            Warehouse::where('branch_id', $context->branchId)->where('is_active', true)->findOrFail($id);
        }
        return $id;
    }

    private function dashboardOwnRecords(): bool
    {
        return Auth::user()->role_id > 2 && cache()->get('general_setting')->staff_access === 'own';
    }

    private function dashboardDocuments(string $model, int $warehouseId): Builder
    {
        $context = request()->attributes->get(CompanyContext::class);
        $query = $model::query();
        $warehouses = Warehouse::where('branch_id', $context->branchId)
            ->when($warehouseId, fn ($warehouses) => $warehouses->whereKey($warehouseId))->select('id');
        $column = $query->qualifyColumn('warehouse_id');
        return $query->where(function ($query) use ($column, $warehouses, $model, $warehouseId) {
            $query->whereIn($column, $warehouses);
            if (!$warehouseId && in_array($model, [Expense::class, Income::class], true)) {
                $query->orWhereNull($column);
            }
        })->when($this->dashboardOwnRecords(), fn ($query) => $query->where($query->qualifyColumn('user_id'), Auth::id()));
    }

    private function dashboardSales(int $warehouseId): Builder
    {
        return $this->dashboardDocuments(Sale::class, $warehouseId)->where('sales.sale_status', 1)
            ->whereNull('sales.reversed_at')
            ->where(fn ($query) => $query->whereNull('sales.sale_type')->orWhere('sales.sale_type', '!=', 'opening balance'));
    }

    private function dashboardPurchases(int $warehouseId): Builder
    {
        return $this->dashboardDocuments(Purchase::class, $warehouseId)->whereIn('purchases.status', [1, 2, 3])
            ->whereNull('purchases.reversed_at')
            ->where(fn ($query) => $query->whereNull('purchases.purchase_type')->orWhere('purchases.purchase_type', '!=', 'opening balance'));
    }

    private function dashboardReturns(string $model, int $warehouseId): Builder
    {
        $query = $this->dashboardDocuments($model, $warehouseId);
        if (Schema::hasColumn($query->getModel()->getTable(), 'status')) {
            $query->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'posted'));
        }
        return $query;
    }

    private function dashboardPayments(int $warehouseId): Builder
    {
        $sales = $this->dashboardSales($warehouseId)->select('sales.id');
        $purchases = $this->dashboardPurchases($warehouseId)->select('purchases.id');
        return Payment::where(fn ($query) => $query->whereIn('sale_id', $sales)->orWhereIn('purchase_id', $purchases))
            ->when($this->dashboardOwnRecords(), fn ($query) => $query->where('user_id', Auth::id()));
    }

    public function calculateAverageCOGS($product_sale_data)
    {
        // Initialize total product cost
        $product_cost = 0;

        // Loop through each sold product entry
        foreach ($product_sale_data as $key => $product_sale) {

            // Fetch product details for the sold product
            $product_data = Product::select('type', 'product_list', 'variant_list', 'qty_list')
                ->find($product_sale->product_id);

            // If product is a combo (bundle of multiple products)
            if($product_data && $product_data->type == 'combo') {
                $product_list = explode(",", $product_data->product_list);

                // Handle variants if present
                if($product_data->variant_list)
                    $variant_list = explode(",", $product_data->variant_list);
                else
                    $variant_list = [];

                // Quantities of each product in the combo
                $qty_list = explode(",", $product_data->qty_list);

                // Loop through each product inside the combo
                foreach ($product_list as $index => $product_id) {

                    // If product has variants, fetch purchase data accordingly
                    if(count($variant_list) && $variant_list[$index]) {
                        $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where([
                            ['product_purchases.product_id', $product_id],
                            ['product_purchases.variant_id', $variant_list[$index] ]
                        ])
                        ->whereNull('purchases.deleted_at')
                        ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.total')
                        ->get();
                    }
                    else {
                        // Fetch all purchases for this product
                        $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where('product_purchases.product_id', $product_id)
                        ->whereNull('purchases.deleted_at')
                        ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.total')
                        ->get();
                    }

                    $total_received_qty = 0;
                    $total_purchased_amount = 0;

                    // Calculate sold quantity of this sub-product in the combo
                    $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty) * $qty_list[$index];

                    // Fetch all unit conversion data
                    $units = Unit::select('id', 'operator', 'operation_value')->get();

                    // Loop through all purchases for this product
                    foreach ($product_purchase_data as $key => $product_purchase) {
                        $purchase_unit_data = $units->where('id',$product_purchase->purchase_unit_id)->first();

                        // Convert received quantity into base unit
                        if(!$purchase_unit_data)
                            continue;
                        if($purchase_unit_data->operator == '*')
                            $total_received_qty += $product_purchase->recieved * $purchase_unit_data->operation_value;
                        else
                            $total_received_qty += $product_purchase->recieved / $purchase_unit_data->operation_value;

                        // Accumulate purchase cost
                        if($product_purchase->exchange_rate)
                            $total_purchased_amount += $product_purchase->total/$product_purchase->exchange_rate;
                        else
                            $total_purchased_amount += $product_purchase->total;
                    }

                    // Compute average cost (purchase amount / total received qty)
                    if($total_received_qty)
                        $averageCost = $total_purchased_amount / $total_received_qty;
                    else
                        $averageCost = 0;

                    // Add to total product cost
                    $product_cost += $sold_qty * $averageCost;
                }
            }
            else {
                // For normal products (not combo)

                // Fetch purchase data depending on batch or variant
                if($product_sale->product_batch_id) {
                    $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where([
                        ['product_purchases.product_id', $product_sale->product_id],
                        ['product_purchases.product_batch_id', $product_sale->product_batch_id]
                    ])
                    ->whereNull('purchases.deleted_at')
                    ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                    ->get();
                }
                elseif($product_sale->variant_id) {
                    $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where([
                        ['product_purchases.product_id', $product_sale->product_id],
                        ['product_purchases.variant_id', $product_sale->variant_id]
                    ])
                    ->whereNull('purchases.deleted_at')
                    ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                    ->get();
                }
                else {
                    $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where('product_id', $product_sale->product_id)
                        ->whereNull('purchases.deleted_at')
                    ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                    ->get();
                }

                $total_received_qty = 0;
                $total_purchased_amount = 0;

                // Fetch all unit conversion data
                $units = Unit::select('id', 'operator', 'operation_value')->get();

                // Convert sold quantity into base unit if sale unit is defined
                if($product_sale->sale_unit_id) {
                    $sale_unit_data = $units->where('id', $product_sale->sale_unit_id)->first();
                    if(!$sale_unit_data)
                        $sold_qty = $product_sale->sold_qty - $product_sale->return_qty;
                    elseif($sale_unit_data->operator == '*')
                        $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty) * $sale_unit_data->operation_value;
                    else
                        $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty) / $sale_unit_data->operation_value;
                }
                else {
                    // If no unit conversion, just take raw sold qty
                    $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty);
                }

                // Loop through purchases to accumulate received qty and purchase amount
                foreach ($product_purchase_data as $key => $product_purchase) {
                    $purchase_unit_data = $units->where('id', $product_purchase->purchase_unit_id)->first();
                    if($purchase_unit_data) {
                        if($purchase_unit_data->operator == '*')
                            $total_received_qty += $product_purchase->recieved * $purchase_unit_data->operation_value;
                        else
                            $total_received_qty += $product_purchase->recieved / $purchase_unit_data->operation_value;

                        if($product_purchase->exchange_rate)
                            $total_purchased_amount += $product_purchase->total/$product_purchase->exchange_rate;
                        else
                            $total_purchased_amount += $product_purchase->total;
                    }
                }

                // Calculate average cost for the product
                if($total_received_qty)
                    $averageCost = $total_purchased_amount / $total_received_qty;
                else
                    $averageCost = 0;

                // Add to total product cost
                $product_cost += $sold_qty * $averageCost;
            }
        }

        // Return the total calculated product cost (COGS)
        return $product_cost;
    }


    public function myTransaction($year, $month)
    {
        $start = 1;
        $number_of_day = date('t', mktime(0, 0, 0, $month, 1, $year));
        while($start <= $number_of_day)
        {
            if($start < 10)
                $date = $year.'-'.$month.'-0'.$start;
            else
                $date = $year.'-'.$month.'-'.$start;
            $sale_generated[$start] = Sale::whereDate('created_at', $date)->where('user_id', Auth::id())->whereNull('deleted_at')->count();
            $sale_grand_total[$start] = Sale::whereDate('created_at', $date)->where('user_id', Auth::id())->whereNull('deleted_at')->sum(DB::raw('grand_total / exchange_rate'));
            $purchase_generated[$start] = Purchase::whereDate('created_at', $date)->where('user_id', Auth::id())->whereNull('deleted_at')->count();
            $purchase_grand_total[$start] = Purchase::whereDate('created_at', $date)->where('user_id', Auth::id())->whereNull('deleted_at')->sum(DB::raw('grand_total / exchange_rate'));
            $quotation_generated[$start] = Quotation::whereDate('created_at', $date)->where('user_id', Auth::id())->count();
            $quotation_grand_total[$start] = Quotation::whereDate('created_at', $date)->where('user_id', Auth::id())->sum('grand_total');
            $start++;
        }
        $start_day = date('w', strtotime($year.'-'.$month.'-01')) + 1;
        $prev_year = date('Y', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $prev_month = date('m', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $next_year = date('Y', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        $next_month = date('m', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        return view('backend.user.my_transaction', compact('start_day', 'year', 'month', 'number_of_day', 'prev_year', 'prev_month', 'next_year', 'next_month', 'sale_generated', 'sale_grand_total','purchase_generated', 'purchase_grand_total','quotation_generated', 'quotation_grand_total'));
    }

    public function switchTheme($theme)
    {
        setcookie('theme', $theme, time() + (86400 * 365), "/");
    }

    public function sessionRenew(Request $request)
    {
        return response()->json('success');
    }
}
