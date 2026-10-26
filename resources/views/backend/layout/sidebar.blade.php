@php
    // Layout: documents/ZOLOERP_SIDEBAR_INFORMATION_ARCHITECTURE_PLAN.md — business domains, capability/permission gated.
    $nav = new \App\Services\Platform\NavigationAccess($role_has_permissions_list ?? null);
    $role = $role ?? (\Spatie\Permission\Models\Role::find(Auth::user()->role_id ?? 1));
    $navigationContext = $nav->context;
    $isAdmin = $nav->isAdmin;
    $canNavigate = fn ($permission) => $nav->can($permission);
    $documentShortcuts = app(\App\Services\Platform\DocumentShortcutRegistry::class)->forUser($canNavigate, $isAdmin);
    $complianceOn = config('commercial.enabled') && config('compliance.enabled');
    $canMasters = $nav->allows('sidebar_product');
    $canSelling = $nav->allows('sidebar_sale', 'packing_slip_challan', 'delivery', 'delivery-challans-index', 'quotes-index');
    $canJobWork = config('operations.enabled') && $nav->has('operations.job_work');
    $canDistribution = (config('commercial.enabled') && $nav->has('sales.route_distribution') && $nav->allows('sales-index')) || $nav->has('operations.water_logistics');
    $canHrm = $nav->has('hrm.enabled') && $nav->allows('sidebar_hrm');
    $hasOperations = $canJobWork || $canDistribution || $nav->has('operations.scheduling') || $nav->has('manufacturing.production')
        || $nav->has('operations.projects') || $nav->has('service.repair') || $nav->has('operations.cafe_bakery')
        || ($nav->allows('addons') && \Auth::user()->role_id != 5 && ($nav->has('sales.ecommerce') || $nav->has('operations.restaurant')));
    $settingsSections = \App\Services\Platform\SettingsCatalog::sections($nav);
@endphp

<div class="side-search d-print-none" role="search">
    <input type="search" id="side-menu-search" placeholder="Search menu…" aria-label="Search menu" autocomplete="off">
</div>
<ul id="side-search-results" class="side-menu list-unstyled d-print-none" aria-label="Menu search results" hidden></ul>

<ul id="side-main-menu" class="side-menu list-unstyled d-print-none">
    @if($navigationContext)<li><a href="/workspace"><i class="dripicons-home"></i><span>Workspace</span></a></li>@endif
    <li id="dashboard-menu">
        <a href="{{url('/dashboard')}}">
            <i class="dripicons-meter"></i>
            <span>{{__('db.dashboard')}}</span>
        </a>
    </li>

    <!-- MASTER DATA -->
    @if($canMasters || $nav->allows('customers-index', 'suppliers-index'))
        <li class="sidebar-heading"><span>Master Data</span></li>
    @endif
    @if($canMasters)
        <li>
            <a href="#product" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-list"></i>
                <span>Products</span>
            </a>
            <ul id="product" class="collapse list-unstyled">
                @if($nav->allows('products-index'))
                    <li id="product-list-menu"><a href="{{route('products.index')}}">Product List</a></li>
                @endif
                @if($nav->allows('categories-index'))
                    <li id="category-menu"><a href="{{route('category.index')}}">Categories</a></li>
                @endif
                @if($nav->allows('brand'))
                    <li id="brand-menu"><a href="{{route('brand.index')}}">Brands</a></li>
                @endif
                @if($nav->allows('unit'))
                    <li id="unit-menu"><a href="{{route('unit.index')}}" data-keywords="uom">Units</a></li>
                @endif
                @if($nav->allows('print_barcode'))
                    <li id="printBarcode-menu"><a href="{{route('product.printBarcode')}}">Print Barcode</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($canMasters || $nav->allows('customers-index', 'suppliers-index'))
        <li>
            <a href="#parties" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-user"></i>
                <span>Parties</span>
            </a>
            <ul id="parties" class="collapse list-unstyled">
                @if($nav->allows('customers-index'))
                    <li id="customer-list-menu"><a href="{{route('customer.index')}}">Customers</a></li>
                @endif
                @if($nav->allows('suppliers-index'))
                    <li id="supplier-list-menu"><a href="{{route('supplier.index')}}">Suppliers</a></li>
                @endif
                @if($canMasters)
                    <li><a href="{{route('agent.index')}}">Agents / Brokers</a></li>
                    <li><a href="{{route('area.index')}}">Areas / Routes</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($canMasters)
        <li>
            <a href="#optech_masters" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-view-thumb"></i>
                <span>Commercial Masters</span>
            </a>
            <ul id="optech_masters" class="collapse list-unstyled">
                <li><a href="{{route('sale-type.index')}}">Sale Types</a></li>
                <li><a href="{{route('purchase-type.index')}}">Purchase Types</a></li>
                <li><a href="{{route('bill-sundry.index')}}">Bill Sundry</a></li>
                <li><a href="{{route('standard-remark.index')}}">Standard Remarks</a></li>
                <li><a href="{{route('document-series.index')}}" data-keywords="document series numbering">Voucher Series</a></li>
            </ul>
        </li>
    @endif

    <!-- INVENTORY -->
    @if($canMasters || $nav->allows('sidebar_transfer'))
        <li class="sidebar-heading"><span>Inventory</span></li>
        <li>
            <a href="#inventory" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-box"></i>
                <span>Inventory</span>
            </a>
            <ul id="inventory" class="collapse list-unstyled">
                @if(config('operations.enabled') && $nav->has('core.inventory') && $nav->allows('products-index'))
                    <li id="stock-menu"><a href="{{ url('/operations/stock') }}">Stock</a></li>
                @endif
                @if($nav->has('inventory.lot_report') && $nav->allows('products-index'))
                    <li id="stock-by-lot-menu"><a href="{{route('inventory-reports.stock-by-lot')}}" data-keywords="batch">Stock By Lot</a></li>
                @endif
                @if($canMasters && $nav->allows('stock_count'))
                    <li id="stock-count-menu"><a href="{{route('stock-count.index')}}" data-keywords="physical">Stock Count</a></li>
                @endif
                @if($canMasters && $nav->allows('adjustment'))
                    <li id="adjustment-list-menu"><a href="{{route('qty_adjustment.index')}}" data-keywords="damage shrinkage write-off">Stock Adjustments</a></li>
                @endif
                @if($canMasters && $nav->has('inventory.damage_stock'))
                    <li id="damage-stock-menu"><a href="{{route('damage-stock.index')}}">Damage Stock Audit</a></li>
                @endif
                @if($nav->allows('transfers-index') && $nav->allows('sidebar_transfer'))
                    <li id="transfer-list-menu"><a href="{{route('transfers.index')}}" data-keywords="godown warehouse">Transfers</a></li>
                @endif
                @if($nav->allows('transfers-import') && $nav->allows('sidebar_transfer'))
                    <li id="transfer-import-menu"><a href="{{url('transfers/transfer_by_csv')}}" data-keywords="csv">Import Transfers</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- TRANSACTIONS -->
    @if($nav->allows('sidebar_purchase') || $canSelling)
        <li class="sidebar-heading"><span>Transactions</span></li>
    @endif
    @if($nav->allows('sidebar_purchase'))
        <li>
            <a href="#purchase" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-card"></i>
                <span>Buying</span>
            </a>
            <ul id="purchase" class="collapse list-unstyled">
                @if($nav->allows('purchases-index'))
                    <li id="purchase-list-menu"><a href="{{route('purchases.index')}}" data-keywords="grn purchase">Purchase Bills</a></li>
                    <li><a href="{{ config('commercial.enabled') ? route('purchases.index', ['orders' => 1]) : route('purchases.index', ['view' => 'orders', 'purchase_status' => 4]) }}" data-keywords="po">Purchase Orders</a></li>
                @endif
                @if($nav->allows('purchase-return-index'))
                    <li id="purchase-return-menu"><a href="{{ $complianceOn ? route('purchases.index', ['returns' => 1]) : route('return-purchase.index') }}">Purchase Returns &amp; Debit Notes</a></li>
                @endif
                @if($nav->allows('purchases-import'))
                    <li id="purchase-import-menu"><a href="{{url('purchases/purchase_by_csv')}}" data-keywords="csv">Import Purchases</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($canSelling)
        <li>
            <a href="#sale" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-cart"></i>
                <span>Selling</span>
            </a>
            <ul id="sale" class="collapse list-unstyled">
                @if($nav->allows('sales-index'))
                    <li id="sale-list-menu"><a href="{{route('sales.index')}}" data-keywords="invoice sale">Sales Bills</a></li>
                    @if(config('commercial.enabled'))<li><a href="{{ route('sales.index', ['orders' => 1]) }}" data-keywords="so">Sales Orders</a></li>@endif
                @endif
                @if($nav->allows('returns-index'))
                    <li id="sale-return-menu"><a href="{{ $complianceOn ? route('sales.index', ['returns' => 1]) : route('return-sale.index') }}">Sales Returns &amp; Credit Notes</a></li>
                @endif
                @if($nav->allows('quotes-index'))
                    <li id="quotation-list-menu"><a href="{{route('quotations.index')}}" data-keywords="estimate">Quotations</a></li>
                @endif
                @if($nav->allows('packing_slip_challan', 'delivery', 'delivery-challans-index'))
                    <li id="dispatch-menu"><a href="{{route('dispatch-register.index')}}" data-keywords="challan dc delivery">Dispatch Register</a></li>
                @endif
                @if($nav->has('sales.pos') && $nav->allows('sales-add'))
                    <li><a href="{{route('sale.pos')}}">POS Terminal</a></li>
                @endif
                @if($nav->allows('sales-import'))
                    <li id="sale-import-menu"><a href="{{url('sales/sale_by_csv')}}" data-keywords="csv">Import Sales</a></li>
                @endif
                @if($nav->has('sales.exchange'))
                    <li id="sale-exchange-menu"><a href="{{route('exchange.index')}}">Product Exchange</a></li>
                @endif
                @if($nav->has('sales.installment_plans'))
                    <li id="installment-menu"><a href="{{route('installmentplan.index')}}">Installment Plans</a></li>
                @endif
                <li class="sidebar-sublabel">Sales Tools</li>
                @if($nav->has('sales.gift_cards') && $nav->allows('gift_card'))
                    <li id="gift-card-menu"><a href="{{route('gift_cards.index')}}">Gift Cards</a></li>
                @endif
                @if($nav->has('sales.coupons') && $nav->allows('coupon'))
                    <li id="coupon-menu"><a href="{{route('coupons.index')}}">Coupons</a></li>
                @endif
                <li id="courier-menu"><a href="{{route('couriers.index')}}" data-keywords="transport">Courier / Logistics</a></li>
                @if($nav->has('sales.catalogue_qr'))
                    <li><a href="{{route('qr.index')}}">Catalogue QR</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- OPERATIONS (each module only when its capability is on) -->
    @if($hasOperations)
        <li class="sidebar-heading"><span>Operations</span></li>
    @endif
    @if($canJobWork)
        {{-- Job work DC/GRN stay apart from commercial GRN/DC: the material remains ours. ?tab= opens a register. --}}
        <li>
            <a href="#job_work" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-swap"></i>
                <span>Job Work</span>
            </a>
            <ul id="job_work" class="collapse list-unstyled">
                <li id="job-work-menu"><a href="{{ url('/operations/job-work') }}" data-keywords="subcontracting">Job Work Orders</a></li>
                <li><a href="{{ url('/operations/job-work?tab=dc') }}">Material DC</a></li>
                <li><a href="{{ url('/operations/job-work?tab=grn') }}" data-keywords="receipt rework return service bill">Job Work GRN</a></li>
                <li><a href="{{ url('/operations/job-work?tab=outside') }}" data-keywords="material outside">Pending With Job Workers</a></li>
            </ul>
        </li>
    @endif
    @if($nav->has('manufacturing.production'))
        {{-- BOMs, production orders and completion are tabs of one screen. --}}
        <li id="manufacturing-menu"><a href="{{url('/operations/manufacturing')}}" data-keywords="bom recipe production"><i class="fa fa-industry"></i><span>Manufacturing</span></a></li>
    @endif
    @if($nav->has('operations.projects'))
        <li>
            <a href="#project-mgmt-menu" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-briefcase"></i>
                <span>Projects / Sites</span>
            </a>
            <ul id="project-mgmt-menu" class="collapse list-unstyled">
                <li><a href="{{url('/operations/projects')}}" data-keywords="site installation commissioning">Projects</a></li>
                <li><a href="{{route('project-management.tasks')}}">Task Board</a></li>
                <li><a href="{{route('project-management.categories')}}">Project Categories</a></li>
            </ul>
        </li>
    @endif
    @if($canDistribution)
        <li>
            <a href="#distribution" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-map"></i>
                <span>Distribution</span>
            </a>
            <ul id="distribution" class="collapse list-unstyled">
                @if(config('commercial.enabled') && $nav->has('sales.route_distribution') && $nav->allows('sales-index'))
                    <li id="route-sheet-menu"><a href="{{ url('/trade/routes') }}" data-keywords="route sheet van">Routes</a></li>
                @endif
                @if($nav->has('operations.water_logistics'))
                    <li><a href="{{route('water-logistics.index')}}">Fleet Trips &amp; Can Routes</a></li>
                    <li><a href="{{route('water-logistics.credit-aging')}}">Corporate Credit Aging</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($nav->has('service.repair'))
        <li>
            <a href="#repair-menu" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-wrench"></i>
                <span>Repair Center</span>
            </a>
            <ul id="repair-menu" class="collapse list-unstyled">
                <li><a href="{{route('repair.dashboard')}}">Repair Dashboard</a></li>
                <li><a href="{{route('repair.services')}}">Service Job Sheets</a></li>
                <li><a href="{{route('repair.device-types')}}">Device Categories</a></li>
            </ul>
        </li>
    @endif
    @if($nav->has('operations.cafe_bakery'))
        <li>
            <a href="#cafe-menu" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-store"></i>
                <span>Bakery &amp; Cafe POS</span>
            </a>
            <ul id="cafe-menu" class="collapse list-unstyled">
                <li><a href="{{route('cafe.index')}}">Operations Dashboard</a></li>
                <li><a href="{{route('cafe.pos')}}">Touch POS Terminal</a></li>
            </ul>
        </li>
    @endif
    @if($nav->allows('addons') && \Auth::user()->role_id != 5)
        @if($nav->has('sales.ecommerce') && View::exists('ecommerce::backend.layout.sidebar-menu'))
            <li>
                <a href="#ecommerce" aria-expanded="false" data-toggle="collapse"><i class="dripicons-shopping-bag"></i><span>eCommerce</span></a>
                <ul id="ecommerce" class="collapse list-unstyled">
                    @include('ecommerce::backend.layout.sidebar-menu')
                </ul>
            </li>
        @endif
        @if($nav->has('operations.projects') && View::exists('project::backend.layout.sidebar-menu'))
            @include('project::backend.layout.sidebar-menu')
        @endif
        @if($nav->has('operations.restaurant') && View::exists('restaurant::backend.layout.sidebar-menu'))
            @include('restaurant::backend.layout.sidebar-menu')
        @endif
    @endif
    @if($nav->has('operations.scheduling'))
        <li>
            <a href="{{route('bookings.calendar')}}" data-keywords="scheduling appointment reservation">
                <i class="dripicons-calendar"></i>
                <span>Booking Calendar</span>
            </a>
        </li>
    @endif

    <!-- FINANCE -->
    @if($nav->allows('sidebar_accounting', 'sidebar_expense', 'sidebar_income'))
        <li class="sidebar-heading"><span>Finance</span></li>
    @endif
    @if($nav->allows('sidebar_accounting'))
        <li>
            <a href="#account" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-briefcase"></i>
                <span>{{__('db.Accounting')}}</span>
            </a>
            <ul id="account" class="collapse list-unstyled">
                <li id="voucher-entry-menu"><a href="{{route('accounting.voucher.entry')}}" class="font-weight-bold text-primary"><i class="dripicons-pencil mr-1"></i> Voucher Entry · F9</a></li>
                <li id="coa-menu"><a href="{{route('accounting.coa')}}">Chart of Accounts</a></li>
                <li id="journal-menu"><a href="{{route('accounting.journal-entries')}}">Journal Entries</a></li>
                <li id="general-ledger-menu"><a href="{{route('accounting.general-ledger')}}">General Ledger</a></li>
                <li id="trial-balance-menu"><a href="{{route('accounting.trial-balance')}}">Trial Balance</a></li>
                <li id="profit-loss-menu"><a href="{{route('accounting.profit-loss')}}">Profit &amp; Loss</a></li>
                <li id="balance-sheet-menu"><a href="{{route('accounting.balance-sheet')}}">Balance Sheet</a></li>
                <li id="cash-flow-menu"><a href="{{route('accounting.cash-flow')}}">Cash Flow Statement</a></li>
                <li id="inv-close-menu"><a href="{{route('accounting.inventory-close')}}">Periodic Inventory Close</a></li>
                <li id="semantic-map-menu"><a href="{{route('accounting.semantic-mappings')}}">Semantic Account Mapping</a></li>
                @if($nav->allows('account-index'))
                    <li id="account-list-menu"><a href="{{route('accounts.index')}}">{{__('db.Account List')}} (Cash)</a></li>
                @endif
                @if($nav->allows('money-transfer'))
                    <li id="money-transfer-menu"><a href="{{route('money-transfers.index')}}">{{__('db.Money Transfer')}}</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($nav->allows('sidebar_expense'))
        <li>
            <a href="#expense" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-wallet"></i>
                <span>Expenses</span>
            </a>
            <ul id="expense" class="collapse list-unstyled">
                <li id="exp-cat-menu"><a href="{{route('expense_categories.index')}}">{{__('db.Expense Category')}}</a></li>
                @if($nav->allows('expenses-index'))
                    <li id="exp-list-menu"><a href="{{route('expenses.index')}}">{{__('db.Expense List')}}</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($nav->allows('sidebar_income'))
        <li>
            <a href="#income" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-rocket"></i>
                <span>{{__('db.Income')}}</span>
            </a>
            <ul id="income" class="collapse list-unstyled">
                <li id="income-cat-menu"><a href="{{route('income_categories.index')}}">{{__('db.Income Category')}}</a></li>
                @if($nav->allows('incomes-index'))
                    <li id="income-list-menu"><a href="{{route('incomes.index')}}">{{__('db.Income List')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- COMPLIANCE -->
    @if($complianceOn && $nav->allows('gst-index'))
        <li class="sidebar-heading"><span>Compliance</span></li>
        <li>
            <a href="#gst_compliance" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-document-edit"></i>
                <span>GST &amp; Compliance</span>
            </a>
            <ul id="gst_compliance" class="collapse list-unstyled">
                <li><a href="{{ url('/compliance/gst/report') }}" data-keywords="gst tax report">GST Review</a></li>
                <li class="sidebar-sublabel">GST Returns</li>
                <li><a href="{{ url('/compliance/gst/returns#gstr3b') }}" data-keywords="gst returns">GSTR-3B</a></li>
                <li><a href="{{ url('/compliance/gst/returns#itc04') }}" data-keywords="gst returns job work">ITC-04</a></li>
                <li><a href="{{ url('/compliance/gst/returns#tds') }}" data-keywords="gst returns 194q">TDS / TCS where applicable</a></li>
            </ul>
        </li>
    @endif

    <!-- REPORTING (one central entry; stock reports included) -->
    @if($nav->allows('sidebar_reports'))
        <li class="sidebar-heading"><span>Reporting</span></li>
        <li>
            <a href="#report" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-document-remove"></i>
                <span>{{__('db.Reports')}}</span>
            </a>
            <ul id="report" class="collapse list-unstyled">
                <li class="sidebar-sublabel">Sales</li>
                @if($nav->allows('sale-report'))
                    <li id="sale-report-menu"><a href="{{route('report.sale')}}">{{__('db.Sale Report')}}</a></li>
                @endif
                @if($nav->allows('daily-sale'))
                    <li id="daily-sale-report-menu"><a href="{{url('report/daily_sale/'.date('Y').'/'.date('m'))}}">{{__('db.Daily Sale')}}</a></li>
                @endif
                @if($nav->allows('monthly-sale'))
                    <li id="monthly-sale-report-menu"><a href="{{url('report/monthly_sale/'.date('Y'))}}">{{__('db.Monthly Sale')}}</a></li>
                @endif
                @if($nav->allows('sale-report-chart'))
                    <li id="sale-report-chart-menu"><a href="{{route('report.saleChart')}}">{{__('db.Sale Report Chart')}}</a></li>
                @endif
                @if($nav->allows('best-seller'))
                    <li id="best-seller-report-menu"><a href="{{url('report/best_seller')}}">{{__('db.Best Seller')}}</a></li>
                @endif
                @if($nav->allows('dso-report'))
                    <li id="daily-sale-objective-menu"><a href="{{route('report.dailySaleObjective')}}">{{__('db.Daily Sale Objective Report')}}</a></li>
                @endif
                <li id="challan-report-menu"><a href="{{route('report.challan')}}">{{__('db.Challan Report')}}</a></li>
                @if(config('commercial.enabled') && $nav->allows('sales-index'))
                    <li id="brokerage-menu"><a href="{{ url('/trade/brokerage') }}" data-keywords="agent commission">Brokerage</a></li>
                @endif
                @if($nav->allows('payment-report'))
                    <li id="payment-report-menu"><a href="{{route('report.paymentByDate')}}">{{__('db.Payment Report')}}</a></li>
                @endif
                <li class="sidebar-sublabel">Purchases</li>
                @if($nav->allows('purchase-report'))
                    <li id="purchase-report-menu"><a href="{{route('report.purchase')}}">{{__('db.Purchase Report')}}</a></li>
                @endif
                @if($nav->allows('daily-purchase'))
                    <li id="daily-purchase-report-menu"><a href="{{url('report/daily_purchase/'.date('Y').'/'.date('m'))}}">{{__('db.Daily Purchase')}}</a></li>
                @endif
                @if($nav->allows('monthly-purchase'))
                    <li id="monthly-purchase-report-menu"><a href="{{url('report/monthly_purchase/'.date('Y'))}}">{{__('db.Monthly Purchase')}}</a></li>
                @endif
                <li class="sidebar-sublabel">Inventory</li>
                @if($nav->allows('product-report'))
                    <li id="product-report-menu"><a href="{{route('report.product')}}">{{__('db.Product Report')}}</a></li>
                @endif
                @if($nav->allows('warehouse-report'))
                    <li id="warehouse-report-menu"><a href="{{route('report.warehouse')}}">{{__('db.Warehouse Report')}}</a></li>
                @endif
                @if($nav->allows('warehouse-stock-report'))
                    <li id="warehouse-stock-report-menu"><a href="{{route('report.warehouseStock')}}">{{__('db.Warehouse Stock Chart')}}</a></li>
                @endif
                @if($nav->allows('product-expiry-report'))
                    <li id="productExpiry-report-menu"><a href="{{route('report.productExpiry')}}" data-keywords="batch near expiry">{{__('db.Product Expiry Report')}}</a></li>
                @endif
                @if($nav->allows('product-qty-alert'))
                    <li id="qtyAlert-report-menu"><a href="{{route('report.qtyAlert')}}" data-keywords="reorder low stock">{{__('db.Product Quantity Alert')}}</a></li>
                @endif
                <li class="sidebar-sublabel">Users &amp; Audit</li>
                @if($nav->allows('user-report'))
                    <li id="user-report-menu"><a href="{{route('report.user')}}">{{__('db.User Report')}}</a></li>
                @endif
                @if($nav->allows('biller-report'))
                    <li id="biller-report-menu"><a href="{{route('report.biller')}}">{{__('db.Biller Report')}}</a></li>
                @endif
                <li id="cash-register-report-menu"><a href="{{route('cashRegister.index')}}">{{__('db.Cash Register')}}</a></li>
                @if($isAdmin || $role->id <= 2)
                    <li id="activity-log-menu"><a href="{{route('setting.activityLog')}}" data-keywords="audit history">{{__('db.Activity Log')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- ORGANIZATION -->
    @if($nav->allows('sidebar_people') || $canHrm)
        <li class="sidebar-heading"><span>Organization</span></li>
    @endif
    @if($nav->allows('sidebar_people'))
        <li>
            <a href="#people" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-user-group"></i>
                <span>{{__('db.People')}}</span>
            </a>
            <ul id="people" class="collapse list-unstyled">
                @if($nav->allows('users-index'))
                    <li id="user-list-menu"><a href="{{route('user.index')}}">{{__('db.User List')}}</a></li>
                @endif
                @if($nav->allows('sale-agents'))
                    <li id="sale-agent-menu"><a href="{{route('sale-agents.index')}}">{{__('db.Sale Agents')}}</a></li>
                @endif
                @if($nav->allows('billers-index'))
                    <li id="biller-list-menu"><a href="{{route('biller.index')}}">{{__('db.Biller List')}}</a></li>
                @endif
            </ul>
        </li>
    @endif
    @if($canHrm)
        <li>
            <a href="#hrm" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-user-id"></i>
                <span>{{__('db.HRM')}}</span>
            </a>
            <ul id="hrm" class="collapse list-unstyled">
                @if($nav->allows('department'))
                    <li id="dept-menu"><a href="{{route('departments.index')}}">{{__('db.Department')}}</a></li>
                @endif
                @if($nav->allows('designations'))
                    <li id="designations-menu"><a href="{{route('designations.index')}}">{{__('db.Designation')}}</a></li>
                @endif
                @if($nav->allows('shift'))
                    <li id="shift-menu"><a href="{{route('shift.index')}}">{{__('db.Shift')}}</a></li>
                @endif
                @if($nav->allows('employees-index'))
                    <li id="employee-menu"><a href="{{route('employees.index')}}">{{__('db.Employee')}}</a></li>
                @endif
                @if($nav->allows('attendance'))
                    <li id="attendance-menu"><a href="{{route('attendance.index')}}">{{__('db.Attendance')}}</a></li>
                @endif
                @if($nav->allows('holiday'))
                    <li id="holiday-menu"><a href="{{route('holidays.index')}}">{{__('db.Holiday')}}</a></li>
                @endif
                @if($nav->allows('overtime'))
                    <li id="overtime-menu"><a href="{{route('overtime.index')}}">{{__('db.Overtime')}}</a></li>
                @endif
                @if($nav->allows('leave-type'))
                    <li id="leave-type-menu"><a href="{{route('leave-type.index')}}">{{__('db.Leave Type')}}</a></li>
                @endif
                @if($nav->allows('leave'))
                    <li id="leave-menu"><a href="{{route('leave.index')}}">{{__('db.Leaves')}}</a></li>
                @endif
                @if($nav->allows('payroll'))
                    <li id="payroll-menu"><a href="{{route('payroll.index')}}">{{__('db.Payroll')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- ADMINISTRATION: one entry; the Settings workspace lists every settings page. -->
    @if($settingsSections)
        <li class="sidebar-heading"><span>Administration</span></li>
        <li id="settings-menu"><a href="{{ route('settings.workspace') }}"><i class="dripicons-gear"></i><span>Settings</span></a></li>
    @endif
</ul>

{{-- Search-only index: settings pages are found by the menu search and mark Settings as current. --}}
<ul id="side-search-index" hidden>
    @foreach($settingsSections as $section)
        @foreach($section['items'] as $item)
            <li><a href="{{ $item['url'] }}" data-nav-owner="settings-menu" data-group="Settings · {{ $section['title'] }}" data-keywords="{{ $item['keywords'] }}">{{ $item['label'] }}</a></li>
        @endforeach
    @endforeach
</ul>
@include('backend.partials.document-shortcuts', ['documentShortcuts' => $documentShortcuts])
