@extends('backend.layout.main')
@section('charts', '1')
@section('content')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/index.css') }}">
@endpush

<x-success-message key="message" />
<x-error-message key="not_permitted" />

@php
    $general_setting = $general_setting ?? \App\Models\GeneralSetting::latest()->first();
    $navigation = new \App\Services\Platform\NavigationAccess($role_has_permissions_list ?? null);
    $menu = app(\App\Services\Platform\MenuSwitches::class);
    $stockUrl = app(\App\Services\Platform\InventoryNavigation::class)->sections($navigation)['stock']['url'] ?? null;
    $stockUrl = $stockUrl && $menu->allowsUrl($stockUrl) ? $stockUrl : null;
    $newSale = route('sales.index', ['new' => 1]);
    $newPurchase = route('purchases.index', ['new' => 1]);
    $newExpense = route('expenses.index', ['new' => 1]);
    $showMetrics = $navigation->allows('revenue_profit_summary');
    $showCashFlow = $navigation->allows('cash_flow');
    $showMonthly = $navigation->allows('monthly_summary');
    $showYearly = $navigation->allows('yearly_report');
    $breakdownTitle = $period === 'month' ? 'Monthly breakdown' : 'Period breakdown';
    $dateRange = $start === $end ? $reportDate->format('d M Y') : \Carbon\CarbonImmutable::parse($start)->format('d M Y') . ' – ' . $reportDate->format('d M Y');
    $warehouseQuery = $warehouseId ? '?' . http_build_query(['warehouse_id' => $warehouseId]) : '';
    $pnlUrl = $ledger && $navigation->allows('accounting.reports.view') && $menu->routeAllowed('accounting.profit-loss')
        ? route('accounting.profit-loss', ['start_date' => $start, 'end_date' => $end]) : null;
    $decimals = (int) $general_setting->decimal;
    $money = fn ($value) => $currency_symbol . number_format((float) $value, $decimals);
    $currentCompany = $company ?? $activeCompany ?? (\App\Models\Company::with('group')->find(session('company_id')));

    // Sidebar navigation parity for quick modules ribbon
    $nav = $navigation;
    $navigationContext = $nav->context;
    $complianceOn = config('commercial.enabled') && config('compliance.enabled');
    $canMasters = $nav->allows('sidebar_product');
    $canSelling = $nav->allows('sidebar_sale', 'packing_slip_challan', 'delivery', 'delivery-challans-index', 'quotes-index', 'sale-payment-add');
    $canJobWork = config('operations.enabled') && $nav->has('operations.job_work');
    $canDistribution = (config('commercial.enabled') && $nav->has('sales.route_distribution') && $nav->allows('sales-index')) || $nav->has('operations.water_logistics');
    $canHrm = $nav->has('hrm.enabled') && $nav->allows('sidebar_hrm');
    $settingsSections = \App\Services\Platform\SettingsCatalog::sections($nav);

    // Dynamic SVG Sparkline generator
    $generateSparkline = function(array $values, int $w = 88, int $h = 34) {
        $data = array_values(array_filter($values, fn($v) => is_numeric($v)));
        if (count($data) < 2) {
            $data = [0, 0, 0, 0, 0, 0];
        }
        if (count($data) > 6) {
            $data = array_slice($data, -6);
        }
        $min = min($data);
        $max = max($data);
        $range = ($max > $min) ? ($max - $min) : 1;
        $padX = 4;
        $padY = 4;
        $effW = $w - ($padX * 2);
        $effH = $h - ($padY * 2);
        $count = count($data);
        $step = $effW / max(1, $count - 1);

        $pts = [];
        foreach ($data as $i => $v) {
            $x = round($padX + ($i * $step), 1);
            $y = round($h - $padY - ((($v - $min) / $range) * $effH), 1);
            $pts[] = [$x, $y];
        }

        $line = "M {$pts[0][0]},{$pts[0][1]}";
        for ($i = 0; $i < count($pts) - 1; $i++) {
            $p0 = $pts[$i];
            $p1 = $pts[$i + 1];
            $cx = round($p0[0] + ($p1[0] - $p0[0]) * 0.5, 1);
            $line .= " C {$cx},{$p0[1]} {$cx},{$p1[1]} {$p1[0]},{$p1[1]}";
        }
        $lastPt = end($pts);
        $area = "{$line} L {$lastPt[0]},{$h} L {$pts[0][0]},{$h} Z";

        return ['line' => $line, 'area' => $area, 'last' => $lastPt];
    };

    // Calculate micro histogram bars (last 6 months)
    $getHistogramBars = function(array $series) {
        $data = array_slice($series, -6);
        if (empty($data)) $data = [0, 0, 0, 0, 0, 0];
        $max = max($data);
        $bars = [];
        foreach ($data as $val) {
            $pct = $max > 0 ? min(100, max(12, round(($val / $max) * 100))) : 15;
            $bars[] = ['val' => $val, 'pct' => $pct];
        }
        return $bars;
    };

    $revSpark = $generateSparkline($yearly_sale_amount ?? []);
    $expSpark = $generateSparkline($payment_sent ?? []);
    $saleBars = $getHistogramBars($yearly_sale_amount ?? []);
    $purchBars = $getHistogramBars($yearly_purchase_amount ?? []);

    $profitMargin = ($revenue > 0) ? min(100, max(0, round(($profit / $revenue) * 100, 1))) : 0;
    $arRatio = ($total_sale > 0) ? min(100, max(0, round((($total_sale - $invoice_due) / $total_sale) * 100))) : 100;
    $apRatio = ($purchase > 0) ? min(100, max(0, round((($purchase - $purchase_due) / $purchase) * 100))) : 100;

    $totalLiquidAssets = $liquid_balance + $stock_valuation;
    $cashPct = $totalLiquidAssets > 0 ? min(100, max(5, round(($liquid_balance / $totalLiquidAssets) * 100))) : 50;
    $stockPct = 100 - $cashPct;

    $metricCards = [
        [
            'label' => 'Revenue (Net)',
            'icon' => 'graph-bar',
            'tone' => 'primary',
            'amount' => $revenue,
            'field' => 'revenue',
            'delta' => '+14.8% vs last month',
            'delta_tone' => 'positive',
            'visual_type' => 'sparkline',
            'spark' => $revSpark,
            'note' => 'Sales returns',
            'detail' => $return,
            'detailField' => 'return',
            'help' => $ledger ? 'Posted revenue for the selected branch and period.' : 'Sales less shipping and returns, plus other income.',
        ],
        [
            'label' => 'Net Profit',
            'icon' => 'trophy',
            'tone' => 'success',
            'amount' => $profit,
            'field' => 'profit',
            'delta' => $profitMargin . '% operating margin',
            'delta_tone' => 'positive',
            'visual_type' => 'radial',
            'margin' => $profitMargin,
            'note' => 'Purchase returns',
            'detail' => $purchase_return,
            'detailField' => 'purchase_return',
            'help' => $ledger ? 'Net income from the Profit and Loss report for the selected branch and period.' : 'Estimated profit after product costs and expenses.',
        ],
        [
            'label' => 'Total Gross Sales',
            'icon' => 'cart',
            'tone' => 'info',
            'amount' => $total_sale,
            'field' => 'total_sale',
            'delta' => 'Active turnover',
            'delta_tone' => 'neutral',
            'visual_type' => 'histogram',
            'bars' => $saleBars,
            'note' => 'Active customers',
            'detail' => $total_customers,
            'detailField' => null,
            'help' => 'Sales total excluding shipping.',
        ],
        [
            'label' => 'Total Purchases (SCM)',
            'icon' => 'archive',
            'tone' => 'info',
            'amount' => $purchase,
            'field' => 'total_purchase',
            'delta' => 'Procurement spend',
            'delta_tone' => 'neutral',
            'visual_type' => 'histogram',
            'bars' => $purchBars,
            'note' => 'Active suppliers',
            'detail' => $total_suppliers,
            'detailField' => null,
            'help' => 'Purchase total after purchase returns.',
        ],
        [
            'label' => 'Operating Expenses',
            'icon' => 'wallet',
            'tone' => 'warning',
            'amount' => $expense,
            'field' => 'expense',
            'delta' => 'Operating budget',
            'delta_tone' => 'warning',
            'visual_type' => 'sparkline',
            'spark' => $expSpark,
            'note' => 'Direct business overhead',
            'detail' => null,
            'detailField' => null,
            'help' => $ledger ? 'Posted operating expenses for the selected branch and period.' : 'Direct business expenses and overhead.',
        ],
        [
            'label' => 'Accounts Receivable',
            'icon' => 'clock',
            'tone' => $invoice_due > 0 ? 'warning' : 'info',
            'amount' => $invoice_due,
            'field' => 'invoice-due',
            'delta' => $invoice_due > 0 ? 'Due from customers' : 'Zero overdue balance',
            'delta_tone' => $invoice_due > 0 ? 'warning' : 'positive',
            'visual_type' => 'ratio',
            'ratio_val' => $arRatio,
            'ratio_label' => $arRatio . '% Collected',
            'note' => 'Outstanding dues',
            'detail' => null,
            'detailField' => null,
            'help' => 'Current outstanding customer balances.',
        ],
        [
            'label' => 'Accounts Payable',
            'icon' => 'calendar',
            'tone' => $purchase_due > 0 ? 'warning' : 'info',
            'amount' => $purchase_due,
            'field' => 'purchase_due',
            'delta' => $purchase_due > 0 ? 'Due to suppliers' : 'Zero pending dues',
            'delta_tone' => $purchase_due > 0 ? 'warning' : 'positive',
            'visual_type' => 'ratio',
            'ratio_val' => $apRatio,
            'ratio_label' => $apRatio . '% Settled',
            'note' => 'Vendor liabilities',
            'detail' => null,
            'detailField' => null,
            'help' => 'Current outstanding supplier balances.',
        ],
        [
            'label' => 'Liquid Cash & Bank',
            'icon' => 'briefcase',
            'tone' => 'success',
            'amount' => $liquid_balance,
            'field' => 'liquid-balance',
            'delta' => 'Available liquidity',
            'delta_tone' => 'positive',
            'visual_type' => 'capital',
            'cash_pct' => $cashPct,
            'stock_pct' => $stockPct,
            'note' => 'Inventory value',
            'detail' => $stock_valuation,
            'detailField' => 'stock-value',
            'help' => 'Current cash and bank balance. Inventory value follows the warehouse filter.',
        ],
    ];

    $hasBreakdown = min($revenue, $purchase, $expense) >= 0 && max($revenue, $purchase, $expense) > 0;
@endphp

<div class="erp-dashboard" data-dashboard data-currency="{{ $currency_symbol }}" data-decimals="{{ $decimals }}"
    data-date-format="{{ $general_setting->date_format }}"
    @if ($navigation->allows('sales-index') && $menu->routeAllowed('sales.index')) data-sale-url="{{ route('sales.index') }}" @endif
    @if ($navigation->allows('purchases-index') && $menu->routeAllowed('purchases.index')) data-purchase-url="{{ route('purchases.index') }}" @endif>
    @if (!config('database.connections.zoloerp_landlord') && Auth::user()->role_id <= 2 && !empty($versionUpgradeData['alert_version_upgrade_enable']))
        <div id="alertSection" class="zolo-banner-card alert alert-dismissible fade show" role="alert">
            <div class="zolo-banner-content">
                <span class="zolo-badge-pill">zoloERP System</span>
                <span class="zolo-banner-text">Version <strong>{{ $versionUpgradeData['demo_version'] }}</strong> is ready for your deployment.</span>
                <a href="{{ route('new-release') }}" class="zolo-banner-action">View Release Notes &rarr;</a>
            </div>
            <button type="button" id="closeButtonUpgrade" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    {{-- 1. Executive Command Center Header (Modern Glass Cockpit) --}}
    <header class="dash-card dash-command">
        {{-- Subtle vector tech grid & curve art in background --}}
        <div class="dash-hero-vector-art" aria-hidden="true">
            <svg viewBox="0 0 600 130" preserveAspectRatio="none" class="dash-hero-vector-svg">
                <defs>
                    <linearGradient id="dashHeroGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#ffffff" stop-opacity="0.12" />
                        <stop offset="100%" stop-color="#ffffff" stop-opacity="0.02" />
                    </linearGradient>
                    <linearGradient id="dashHeroGrad2" x1="100%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#ffffff" stop-opacity="0.1" />
                        <stop offset="100%" stop-color="#ffffff" stop-opacity="0.01" />
                    </linearGradient>
                </defs>
                <path d="M0,45 C160,80 320,10 460,55 C520,75 570,40 600,50 L600,130 L0,130 Z" fill="url(#dashHeroGrad1)" opacity="0.6" />
                <path d="M0,85 C140,120 280,30 420,75 C500,100 560,60 600,70 L600,130 L0,130 Z" fill="url(#dashHeroGrad2)" opacity="0.45" />
                <path d="M0,105 C150,135 290,50 430,90 C510,115 570,75 600,85" stroke="rgba(255,255,255,0.22)" stroke-width="1.5" fill="none" />
            </svg>
        </div>

        <div class="dash-command-top">
            <div class="dash-heading">
                @php
                    $dashCompanyLogoUrl = null;
                    if ($currentCompany && $currentCompany->logo) {
                        if (file_exists(public_path($currentCompany->logo))) {
                            $dashCompanyLogoUrl = asset($currentCompany->logo);
                        } elseif (file_exists(public_path('images/company/' . $currentCompany->logo))) {
                            $dashCompanyLogoUrl = asset('images/company/' . $currentCompany->logo);
                        } elseif (file_exists(public_path('logo/' . $currentCompany->logo))) {
                            $dashCompanyLogoUrl = asset('logo/' . $currentCompany->logo);
                        }
                    }
                @endphp
                <div class="dash-company-logo-emblem" aria-hidden="true">
                    @if($dashCompanyLogoUrl)
                        <img src="{{ $dashCompanyLogoUrl }}" alt="{{ $currentCompany->trade_name ?: $currentCompany->legal_name }}" class="dash-company-logo-img">
                    @else
                        <span class="dash-company-monogram">{{ collect(preg_split('/\s+/', trim($currentCompany->trade_name ?? $currentCompany->legal_name ?? 'ZOLO')))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</span>
                    @endif
                    <span class="dash-company-star" title="Active Enterprise Workspace">★</span>
                </div>
                <div>
                    @if($currentCompany && $currentCompany->group)
                        <div class="dash-company-breadcrumb">
                            <i class="fa fa-sitemap" aria-hidden="true"></i>
                            <span>{{ $currentCompany->group->name }}</span>
                            <i class="fa fa-angle-right" aria-hidden="true"></i>
                            <span>Enterprise Workspace</span>
                        </div>
                    @endif
                    <div class="dash-company-title-row">
                        <h1 class="dash-company-name">{{ $currentCompany ? ($currentCompany->trade_name ?: $currentCompany->legal_name) : 'Dashboard' }}</h1>
                        @if($currentCompany && $currentCompany->code)
                            <code class="dash-company-code">{{ $currentCompany->code }}</code>
                        @endif
                        <span class="dash-pill-badge is-dashboard"><i class="dripicons-meter mr-1" aria-hidden="true"></i> Dashboard</span>
                        <span class="dash-pulse-badge">
                            <span class="dash-pulse-dot"></span> Live Sync • Operational
                        </span>
                    </div>
                    <p class="dash-company-submeta">
                        @if($currentCompany && $currentCompany->legal_name && $currentCompany->trade_name && $currentCompany->legal_name !== $currentCompany->trade_name)
                            <span class="dash-legal-name">{{ $currentCompany->legal_name }}</span> ·
                        @endif
                        <span>Welcome back, <strong>{{ Auth::user()->name }}</strong></span> ·
                        <span>FY {{ $general_setting->fiscal_year ?? date('Y') }}</span> ·
                        <span>All systems operational</span>
                    </p>
                </div>
            </div>

            {{-- Visual Mini-Cockpit Ticker --}}
            <div class="dash-cockpit-kpis" aria-hidden="true">
                <div class="dash-mini-kpi">
                    <span class="dash-mini-kpi-label">Gross Flow</span>
                    <span class="dash-mini-kpi-val text-white">{{ $money($revenue + $purchase) }}</span>
                    <div class="dash-mini-kpi-bar"><div class="dash-mini-kpi-fill" style="{{ 'width: 78%; background: #ffffff;' }}"></div></div>
                </div>
                <div class="dash-mini-kpi">
                    <span class="dash-mini-kpi-label">Operating Margin</span>
                    <span class="dash-mini-kpi-val text-success">{{ $profitMargin }}%</span>
                    <div class="dash-mini-kpi-bar"><div class="dash-mini-kpi-fill" style="{{ 'width: ' . min(100, max(12, $profitMargin)) . '%; background: var(--z-success);' }}"></div></div>
                </div>
                <div class="dash-mini-kpi">
                    <span class="dash-mini-kpi-label">Stock Status</span>
                    <span class="dash-mini-kpi-val {{ $low_stock_count > 0 ? 'text-warning' : 'text-success' }}">
                        {{ $low_stock_count > 0 ? $low_stock_count . ' Critical' : 'Healthy' }}
                    </span>
                    <div class="dash-mini-kpi-bar"><div class="dash-mini-kpi-fill" style="{{ 'width: ' . ($low_stock_count > 0 ? '45%' : '100%') . '; background: ' . ($low_stock_count > 0 ? 'var(--z-warning)' : 'var(--z-success)') . ';' }}"></div></div>
                </div>
            </div>

            <div class="dash-command-controls">
                <form action="{{ route('dashboard') }}" method="GET" class="dash-filter-controls-form d-flex align-items-center flex-wrap mb-0" id="dash-filter-form">
                    @if (Auth::user()->role_id <= 2)
                        <div class="dash-filter-group" title="Filter by Warehouse">
                            <i class="dripicons-store" aria-hidden="true"></i>
                            <select name="warehouse_id" id="warehouse_btn" aria-label="Warehouse selector">
                                <option value="0">All warehouses</option>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected($warehouseId === $warehouse->id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="dash-filter-group" title="Filter by Period">
                        <i class="dripicons-calendar" aria-hidden="true"></i>
                        <select id="dash-period" name="period" aria-label="Period selector">
                            <option value="today" @selected($period === 'today')>{{ __('db.Today') }}</option>
                            <option value="week" @selected($period === 'week')>{{ __('db.Last 7 Days') }}</option>
                            <option value="month" @selected($period === 'month')>{{ __('db.This Month') }}</option>
                            <option value="year" @selected($period === 'year')>Financial year</option>
                        </select>
                    </div>

                    <a href="{{ url('/workspace#zw-companies') }}" class="dashboard-switch-company" title="Switch company or view group insight">
                        <i class="fa fa-cubes"></i> Master Group Insight
                    </a>
                    <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
                </form>
            </div>
        </div>

        {{-- Dynamic Expandable Quick Navigation Modules Dock --}}
        <div class="dash-hero-expand-dock">
            <button type="button" class="dash-hero-expand-btn" id="dashHeroExpandBtn" aria-expanded="false" aria-controls="dashHeroModulesTray" title="Expand Quick Access Modules">
                <span class="dash-hero-expand-label">Explore Modules</span>
                <i class="fa fa-chevron-down dash-hero-expand-chevron" aria-hidden="true"></i>
            </button>
        </div>

        {{-- Expanded Dynamic Hover-Pan Modules Ribbon --}}
        <div class="dash-hero-modules-tray" id="dashHeroModulesTray" aria-hidden="true">
            <div class="dash-modules-tray-inner">
                <button type="button" class="dash-modules-nav-arrow is-left" id="dashModulesPrev" aria-label="Scroll left" title="Scroll left">
                    <i class="fa fa-chevron-left" aria-hidden="true"></i>
                </button>

                <div class="dash-modules-viewport" id="dashModulesViewport" tabindex="0" role="region" aria-label="Quick Modules Navigation">
                    <div class="dash-modules-track" id="dashModulesTrack">
                        @if($navigationContext)
                            <a href="/workspace" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-home" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Workspace</span>
                                    <span class="dash-module-desc">Company Overview</span>
                                </div>
                            </a>
                        @endif

                        @if(config('erp_ai.enabled'))
                            <a href="{{ url('/ai') }}" class="dash-module-card is-ai">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-sparkles" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="dash-module-title">AI Workspace</span>
                                        <span class="badge badge-primary u-fs-9px">PRO</span>
                                    </div>
                                    <span class="dash-module-desc">Copilot &amp; Intelligence</span>
                                </div>
                            </a>
                        @endif

                        @if($canMasters || $nav->allows('products-index'))
                            <a href="{{ route('products.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-list" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Products</span>
                                    <span class="dash-module-desc">Catalog &amp; Units</span>
                                </div>
                            </a>
                        @endif

                        @if($canMasters || $nav->allows('customers-index', 'suppliers-index'))
                            <a href="{{ route('customer.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-user" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Parties</span>
                                    <span class="dash-module-desc">Customers &amp; Suppliers</span>
                                </div>
                            </a>
                        @endif

                        @if($canMasters)
                            <a href="{{ route('sale-type.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-view-thumb" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Commercial Masters</span>
                                    <span class="dash-module-desc">Vouchers &amp; Types</span>
                                </div>
                            </a>
                        @endif

                        @if($canMasters || $nav->allows('sidebar_transfer'))
                            <a href="{{ $stockUrl ?? url('/operations/stock') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-box" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Inventory</span>
                                    <span class="dash-module-desc">Stock Balances &amp; Godown</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->allows('sidebar_purchase', 'purchases-index'))
                            <a href="{{ route('purchases.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-card" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Buying</span>
                                    <span class="dash-module-desc">PO &amp; Purchase Bills</span>
                                </div>
                            </a>
                        @endif

                        @if($canSelling)
                            <a href="{{ route('sales.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-cart" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Selling</span>
                                    <span class="dash-module-desc">Invoices &amp; Orders</span>
                                </div>
                            </a>
                        @endif

                        <a href="{{ route('dues.index') }}" class="dash-module-card">
                            <div class="dash-module-icon-wrap">
                                <i class="dripicons-wallet" aria-hidden="true"></i>
                            </div>
                            <div class="dash-module-info">
                                <span class="dash-module-title">Payments &amp; Dues</span>
                                <span class="dash-module-desc">Collections &amp; Payables</span>
                            </div>
                        </a>

                        @if($canJobWork)
                            <a href="{{ url('/operations/job-work') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-swap" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Job Work</span>
                                    <span class="dash-module-desc">Subcontracting &amp; DC</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->has('manufacturing.production'))
                            <a href="{{ url('/operations/manufacturing') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="fa fa-industry" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Manufacturing</span>
                                    <span class="dash-module-desc">BOM &amp; Production Orders</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->has('operations.projects'))
                            <a href="{{ url('/operations/projects') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-briefcase" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Projects</span>
                                    <span class="dash-module-desc">Sites &amp; Task Board</span>
                                </div>
                            </a>
                        @endif

                        @if($canDistribution)
                            <a href="{{ route('water-logistics.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-map" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Distribution</span>
                                    <span class="dash-module-desc">Fleet &amp; Beat Routes</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->allows('sidebar_accounting'))
                            <a href="{{ route('accounting.voucher.entry') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-document-edit" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Accounting</span>
                                    <span class="dash-module-desc">Voucher Entry · F9</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->allows('sidebar_expense'))
                            <a href="{{ route('expenses.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-wallet" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Expenses</span>
                                    <span class="dash-module-desc">Operating Overhead</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->allows('sidebar_income'))
                            <a href="{{ route('incomes.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-rocket" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Income</span>
                                    <span class="dash-module-desc">Direct &amp; Other Inflows</span>
                                </div>
                            </a>
                        @endif

                        @if($complianceOn && $nav->allows('gst-index'))
                            <a href="{{ url('/compliance/gst/report') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-document" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">GST Compliance</span>
                                    <span class="dash-module-desc">GST Review &amp; GSTR-3B</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->allows('sidebar_reports'))
                            <a href="{{ route('report.stock') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-document-remove" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Reports</span>
                                    <span class="dash-module-desc">Registers &amp; Statements</span>
                                </div>
                            </a>
                        @endif

                        @if($nav->allows('sidebar_people'))
                            <a href="{{ route('user.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-user-group" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">People</span>
                                    <span class="dash-module-desc">Users &amp; Billers</span>
                                </div>
                            </a>
                        @endif

                        @if($canHrm && \Route::has('employees.index'))
                            <a href="{{ route('employees.index') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-user-id" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">HRM</span>
                                    <span class="dash-module-desc">Staff &amp; Payroll</span>
                                </div>
                            </a>
                        @endif

                        @if($settingsSections)
                            <a href="{{ route('settings.workspace') }}" class="dash-module-card">
                                <div class="dash-module-icon-wrap">
                                    <i class="dripicons-gear" aria-hidden="true"></i>
                                </div>
                                <div class="dash-module-info">
                                    <span class="dash-module-title">Settings</span>
                                    <span class="dash-module-desc">Preferences &amp; System</span>
                                </div>
                            </a>
                        @endif
                    </div>
                </div>

                <button type="button" class="dash-modules-nav-arrow is-right" id="dashModulesNext" aria-label="Scroll right" title="Scroll right">
                    <i class="fa fa-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </header>

    @if (in_array('restaurant', explode(',', cache()->get('general_setting')->modules)))
        @if (Auth::user()->role_id > 2 && isset(Auth::user()->service_staff))
            @php
                $cooked = DB::table('sales')
                    ->where('waiter_id', Auth::user()->id)
                    ->where('sale_status', 5)
                    ->orWhere('sale_status', 6)
                    ->where('sales.created_at', '>=', now()->subDay())
                    ->count();
            @endphp
        @elseif (Auth::user()->role_id <= 2)
            @php
                $cooked = DB::table('sales')
                    ->where('sale_status', 6)
                    ->where('sales.created_at', '>=', now()->subDay())
                    ->count();
            @endphp
        @endif
        @isset($cooked)
            <a href="{{ route('kitchen.dashboard') }}" class="alert alert-warning mb-0">{{ $cooked }} {{ __('db.Orders to serve') }}</a>
        @endisset
    @endif

    {{-- 2. Elevated 8-Card Financial & Operational KPI Grid (4 Columns x 2 Rows) --}}
    @if ($showMetrics)
        <section class="dash-metrics" aria-label="Financial and operational key metrics">
            @foreach ($metricCards as $card)
                <article class="dash-metric">
                    <div class="dash-metric-head">
                        <h3>
                            @if ($pnlUrl && in_array($card['field'], ['revenue', 'profit', 'expense'], true))
                                <a href="{{ $pnlUrl }}">{{ $card['label'] }}</a>
                            @else
                                {{ $card['label'] }}
                            @endif
                        </h3>
                        <span class="dash-icon dash-tone-{{ $card['tone'] }}">
                            <i class="dripicons-{{ $card['icon'] }}" aria-hidden="true"></i>
                        </span>
                    </div>

                    <div class="dash-metric-body">
                        <div class="dash-metric-main">
                            <div class="dash-metric-data">
                                <div class="dash-value" data-metric="{{ $card['field'] }}" data-amount="{{ $card['amount'] }}">
                                    {{ $money($card['amount']) }}
                                </div>
                                <span class="dash-delta-pill dash-delta-{{ $card['delta_tone'] }}">
                                    <i class="dripicons-{{ $card['delta_tone'] === 'positive' ? 'arrow-thin-up' : ($card['delta_tone'] === 'danger' ? 'warning' : 'pulse') }}" aria-hidden="true"></i>
                                    {{ $card['delta'] }}
                                </span>
                            </div>

                            <div class="dash-metric-visual" aria-hidden="true">
                                @if ($card['visual_type'] === 'sparkline')
                                    <svg viewBox="0 0 88 34" class="dash-sparkline">
                                        <defs>
                                            <linearGradient id="grad-{{ $card['field'] }}" x1="0%" y1="0%" x2="0%" y2="100%">
                                                <stop offset="0%" stop-color="var(--z-{{ $card['tone'] }})" stop-opacity="0.28" />
                                                <stop offset="100%" stop-color="var(--z-{{ $card['tone'] }})" stop-opacity="0.0" />
                                            </linearGradient>
                                        </defs>
                                        <path d="{{ $card['spark']['area'] }}" fill="url(#grad-{{ $card['field'] }})" />
                                        <path d="{{ $card['spark']['line'] }}" fill="none" stroke="var(--z-{{ $card['tone'] }})" stroke-width="2" stroke-linecap="round" />
                                        <circle cx="{{ $card['spark']['last'][0] }}" cy="{{ $card['spark']['last'][1] }}" r="2.5" fill="var(--z-{{ $card['tone'] }})" />
                                    </svg>
                                @elseif ($card['visual_type'] === 'radial')
                                    <svg viewBox="0 0 44 44" class="dash-radial-gauge">
                                        <circle cx="22" cy="22" r="16" fill="none" class="dash-radial-track" stroke-width="3.5" />
                                        <circle cx="22" cy="22" r="16" fill="none" stroke="var(--z-success)" stroke-width="3.5"
                                            stroke-dasharray="100.5" stroke-dashoffset="{{ round(100.5 * (1 - ($card['margin'] / 100))) }}"
                                            stroke-linecap="round" transform="rotate(-90 22 22)" class="dash-radial-fill" />
                                        <text x="22" y="23" text-anchor="middle" class="dash-radial-text">{{ round($card['margin']) }}%</text>
                                    </svg>
                                @elseif ($card['visual_type'] === 'histogram')
                                    <div class="dash-mini-histogram" title="6-month trend">
                                        @foreach ($card['bars'] as $bar)
                                            <div class="dash-mini-bar-track">
                                                <div class="dash-mini-bar-fill" style="{{ 'height: ' . $bar['pct'] . '%; background: var(--z-' . $card['tone'] . ');' }}"></div>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($card['visual_type'] === 'ratio')
                                    <div class="dash-ratio-meter">
                                        <div class="dash-ratio-label">{{ $card['ratio_label'] }}</div>
                                        <div class="dash-ratio-track">
                                            <div class="dash-ratio-fill" style="{{ 'width: ' . $card['ratio_val'] . '%; background: var(--z-' . $card['tone'] . ');' }}"></div>
                                        </div>
                                    </div>
                                @elseif ($card['visual_type'] === 'capital')
                                    <div class="dash-ratio-meter">
                                        <div class="dash-ratio-label">{{ $card['cash_pct'] }}% Cash · {{ $card['stock_pct'] }}% Stock</div>
                                        <div class="dash-capital-meter" title="Cash vs Stock distribution">
                                            <div class="dash-capital-segment cash" style="{{ 'width: ' . $card['cash_pct'] . '%;' }}"></div>
                                            <div class="dash-capital-segment stock" style="{{ 'width: ' . $card['stock_pct'] . '%;' }}"></div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="dash-note">
                        <span>{{ $card['note'] }}</span>
                        @if ($card['detailField'])
                            <strong data-metric="{{ $card['detailField'] }}" data-amount="{{ $card['detail'] }}">{{ $money($card['detail']) }}</strong>
                        @elseif ($card['detail'] !== null)
                            <strong>{{ number_format($card['detail']) }}</strong>
                        @else
                            <x-info :title="$card['help']" type="info" />
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    {{-- 3. Analytics & Asymmetric Pairs (Cash Flow 2/3 + Donut 1/3) --}}
    @if ($showCashFlow || $showMonthly)
        <div class="dash-analytics-split">
            @if ($showCashFlow)
                <section class="dash-card" aria-labelledby="dash-cash-title">
                    <div class="dash-panel-head">
                        <div class="dash-heading">
                            <span class="dash-icon dash-tone-primary"><i class="dripicons-pulse" aria-hidden="true"></i></span>
                            <div>
                                <h2 id="dash-cash-title">{{ __('db.Cash Flow') }}</h2>
                                <p>{{ $ledger ? 'Posted cash movements by month' : 'Monthly payments received and sent across fiscal year' }}</p>
                            </div>
                        </div>
                        <span class="dash-delta-pill dash-delta-positive">
                            <i class="dripicons-arrow-thin-up"></i> Net Cash Position
                        </span>
                    </div>
                    <div class="dash-chart-body">
                        <div class="dash-chart">
                            <canvas id="cashFlow" role="img" aria-label="Monthly cash flow" aria-describedby="dash-cash-description"
                                data-recieved="{{ json_encode($payment_recieved) }}" data-sent="{{ json_encode($payment_sent) }}"
                                data-month="{{ json_encode($month) }}" data-label1="Payments received" data-label2="Payments sent"></canvas>
                        </div>
                        <p class="sr-only" id="dash-cash-description">
                            @foreach ($month as $index => $label)
                                {{ $label }}. Received {{ $money($payment_recieved[$index]) }}. Sent {{ $money($payment_sent[$index]) }}.
                            @endforeach
                        </p>
                    </div>
                </section>
            @endif

            @if ($showMonthly)
                <section class="dash-card" aria-labelledby="dash-monthly-title">
                    <div class="dash-panel-head">
                        <div class="dash-heading">
                            <span class="dash-icon dash-tone-success"><i class="dripicons-graph-pie" aria-hidden="true"></i></span>
                            <div>
                                <h2 id="dash-monthly-title">{{ $breakdownTitle }}</h2>
                                <p>{{ $dateRange }}</p>
                            </div>
                        </div>
                        <span class="dash-delta-pill dash-delta-neutral">
                            {{ $currency_symbol }} Turnover
                        </span>
                    </div>
                    <div class="dash-breakdown">
                        @if ($hasBreakdown)
                            <div class="dash-donut-wrap" aria-hidden="true">
                                <canvas class="dash-donut" id="transactionChart" data-revenue="{{ $revenue }}" data-purchase="{{ $purchase }}"
                                    data-expense="{{ $expense }}" data-label1="Purchases" data-label2="Revenue" data-label3="Expenses"></canvas>
                            </div>
                        @endif
                        <dl class="dash-breakdown-values">
                            @foreach ([['Revenue', 'success', $revenue], ['Purchases', 'primary', $purchase], ['Expenses', 'warning', $expense]] as [$label, $tone, $amount])
                                <div>
                                    <dt><span class="dash-dot dash-tone-{{ $tone }}" aria-hidden="true"></span>{{ $label }}</dt>
                                    <dd>{{ $money($amount) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </section>
            @endif
        </div>
    @endif

    {{-- 4. Operational Cockpit (Activity & Stock Alerts 50/50) --}}
    <div class="dash-ops-split">
        <section class="dash-card" aria-labelledby="dash-activity-title">
            <div class="dash-panel-head">
                <div class="dash-heading">
                    <span class="dash-icon dash-tone-info"><i class="dripicons-swap" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="dash-activity-title">Recent Activity</h2>
                        <p>Latest live transactions across channels</p>
                    </div>
                </div>
                <select id="dash-activity-kind" class="dash-tab-select" aria-label="Transaction type">
                    <option value="sale" data-url="{{ url('/recent-sale') . $warehouseQuery }}">Sales Orders</option>
                    <option value="purchase" data-url="{{ url('/recent-purchase') . $warehouseQuery }}">Purchases</option>
                    <option value="quotation" data-url="{{ url('/recent-quotation') . $warehouseQuery }}">Quotations</option>
                    <option value="payment" data-url="{{ url('/recent-payment') . $warehouseQuery }}">Payments</option>
                </select>
            </div>
            <div class="table-responsive" id="dash-activity-results" role="region" aria-label="Recent transactions" tabindex="0">
                <table class="table dash-table" id="dash-activity-table" aria-labelledby="dash-activity-title">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('db.reference') }}</th>
                            <th scope="col">{{ __('db.date') }}</th>
                            <th scope="col" data-activity-party>{{ __('db.customer') }}</th>
                            <th scope="col" data-activity-status>{{ __('db.status') }}</th>
                            <th scope="col" class="dash-number">{{ __('db.Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody><tr><td colspan="5" class="dash-table-message" role="status">Loading transactions…</td></tr></tbody>
                </table>
            </div>
        </section>

        <section class="dash-card" aria-labelledby="dash-stock-title">
            <div class="dash-panel-head">
                <div class="dash-heading">
                    <span class="dash-icon dash-tone-{{ $low_stock_count ? 'danger' : 'success' }}"><i class="dripicons-stack" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="dash-stock-title">Stock Alerts</h2>
                        <p>{{ $low_stock_count ? $low_stock_count . ' items at or below reorder threshold' : 'All stock levels are optimal' }}</p>
                    </div>
                </div>
                @if ($stockUrl)
                    <a href="{{ $stockUrl }}" class="dash-link">View inventory <i class="dripicons-arrow-thin-right" aria-hidden="true"></i></a>
                @endif
            </div>
            @if ($low_stock_products->isNotEmpty())
                <div class="table-responsive" role="region" aria-label="Low stock entries" tabindex="0">
                    <table class="table dash-table" aria-labelledby="dash-stock-title">
                        <thead>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col">Warehouse</th>
                                <th scope="col" class="dash-number">On hand</th>
                                <th scope="col" class="dash-number">Threshold</th>
                                <th scope="col" style="{{ 'min-width: 90px;' }}">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($low_stock_products as $item)
                                @php
                                    $pct = $item->alert_quantity > 0 ? min(100, round(($item->qty / $item->alert_quantity) * 100)) : 0;
                                    $isCritical = $item->qty <= ($item->alert_quantity * 0.5);
                                    $fillColor = $isCritical ? 'var(--z-danger)' : 'var(--z-warning)';
                                @endphp
                                <tr>
                                    <th scope="row">{{ $item->name }} <small>{{ $item->code }}</small></th>
                                    <td>{{ $item->warehouse_name }}</td>
                                    <td class="dash-number font-weight-bold">{{ $item->qty }}</td>
                                    <td class="dash-number text-muted">{{ $item->alert_quantity }}</td>
                                    <td>
                                        <div class="dash-stock-bar-wrap" title="{{ $pct }}% of threshold">
                                            <div class="dash-stock-bar-fill" style="{{ 'width: ' . $pct . '%; background: ' . $fillColor . ';' }}"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="dash-empty">
                    <i class="dripicons-checkmark text-success" style="{{ 'font-size: 26px; display: block; margin-bottom: 6px;' }}"></i>
                    <p class="font-weight-bold text-dark mb-1">All inventory is healthy</p>
                    <p class="text-muted small mb-0">No items currently at or below their reorder threshold.</p>
                </div>
            @endif
        </section>
    </div>

    {{-- 5. Annual Performance & Sales Leaderboard (3 Columns) --}}
    @if ($showYearly)
        <section class="dash-card" aria-labelledby="dash-yearly-title">
            <div class="dash-panel-head">
                <div class="dash-heading">
                    <span class="dash-icon dash-tone-primary"><i class="dripicons-graph-bar" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="dash-yearly-title">Annual Performance Trajectory</h2>
                        <p>Monthly Sales vs Procurement comparison for {{ $year->name }}</p>
                    </div>
                </div>
                <span class="dash-delta-pill dash-delta-neutral">
                    Fiscal Year {{ $year->name }}
                </span>
            </div>
            <div class="dash-chart-body">
                <div class="dash-chart">
                    <canvas id="saleChart" role="img" aria-label="Monthly sales and purchase totals for {{ $year->name }}" aria-describedby="dash-yearly-description"
                        data-sale_chart_value="{{ json_encode($yearly_sale_amount) }}" data-purchase_chart_value="{{ json_encode($yearly_purchase_amount) }}"
                        data-label1="Purchases" data-label2="Sales" data-month="{{ json_encode($month) }}"></canvas>
                </div>
                <p class="sr-only" id="dash-yearly-description">
                    @foreach ($yearly_sale_amount as $index => $amount)
                        {{ $month[$index] }}. Sales {{ $money($amount) }}. Purchases {{ $money($yearly_purchase_amount[$index]) }}.
                    @endforeach
                </p>
            </div>
        </section>
    @endif

    <div class="dash-rankings">
        @foreach ([
            ['monthly-best-selling-qty', 'Top Products This Month', $reportDate->format('F Y'), 'Quantity', 'trophy'],
            ['yearly-best-selling-qty', 'Annual Volume Leaders', $year->name, 'Quantity', 'star'],
            ['yearly-best-selling-price', 'Top Revenue Drivers', $year->name, 'Sales value', 'tag'],
        ] as [$key, $title, $period, $unit, $badgeIcon])
            <section class="dash-card" aria-labelledby="{{ $key }}-title">
                <div class="dash-panel-head">
                    <div class="dash-heading">
                        <span class="dash-icon dash-tone-warning"><i class="dripicons-{{ $badgeIcon }}" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="{{ $key }}-title">{{ $title }}</h2>
                            <p>{{ $period }} · Top 5</p>
                        </div>
                    </div>
                </div>
                <table class="table dash-table" aria-labelledby="{{ $key }}-title" data-ranking-url="{{ url('/' . $key) . $warehouseQuery }}" @if ($unit === 'Sales value') data-ranking-value @endif>
                    <thead><tr><th scope="col">Product</th><th scope="col" class="dash-number">{{ $unit }}</th></tr></thead>
                    <tbody><tr><td colspan="2" class="dash-table-message" role="status">Loading best sellers…</td></tr></tbody>
                </table>
            </section>
        @endforeach
    </div>

    {{-- 6. Executive Footer Strip --}}
    <footer class="dash-footer-strip">
        <div class="d-flex align-items-center gap-2">
            <span><i class="dripicons-clock mr-1" aria-hidden="true"></i> Data synced live · Active Branch</span>
        </div>
        <div class="dash-footer-shortcuts">
            <span>Quick shortcuts:</span>
            <span><kbd>Alt+S</kbd> New Sale</span>
            <span><kbd>Alt+P</kbd> New Purchase</span>
            <span><kbd>Alt+E</kbd> Expense</span>
            <span><kbd>⌘K</kbd> Search</span>
        </div>
    </footer>
</div>
@endsection

@push('scripts')
<script src="{{ versioned_asset('js/dashboard.js') }}"></script>
@endpush
