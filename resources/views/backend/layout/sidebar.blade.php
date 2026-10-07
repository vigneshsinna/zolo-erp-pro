@php
    $enabledCapabilities = app(\App\Services\Platform\CapabilityService::class)->forNavigation();
    $role = $role ?? (\Spatie\Permission\Models\Role::find(Auth::user()->role_id ?? 1));
    $navigationContext = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
    $effectiveRoleId = $navigationContext
        ? (\Illuminate\Support\Facades\DB::table('company_user')->where('company_id', $navigationContext->companyId)->where('user_id', Auth::id())->value('role_id_override') ?? Auth::user()->role_id)
        : Auth::user()->role_id;
    $isAdmin = Auth::check() && in_array((int) $effectiveRoleId, [1, 2], true);
    $canNavigate = function ($permission) use ($navigationContext, $effectiveRoleId) {
        if (!$navigationContext) {
            return Auth::user()->can($permission);
        }
        return \Illuminate\Support\Facades\DB::table('permissions')
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $effectiveRoleId)->where('permissions.name', $permission)->exists();
    };
    $shortcutRegistry = app(\App\Services\Platform\DocumentShortcutRegistry::class);
    $documentShortcuts = $shortcutRegistry->forUser($canNavigate, $isAdmin);
    $shortcutFor = fn (string $id) => $shortcutRegistry->find($documentShortcuts, $id);
@endphp

<ul id="side-main-menu" class="side-menu list-unstyled d-print-none">
    @if($navigationContext)<li><a href="/workspace"><i class="dripicons-home"></i><span>Workspaces</span></a></li>@endif
    <!-- SECTION: CORE -->
    <li class="sidebar-heading"><span>Core</span></li>
    <li id="dashboard-menu">
        <a href="{{url('/dashboard')}}">
            <i class="dripicons-meter"></i>
            <span>{{__('db.dashboard')}}</span>
        </a>
    </li>

    <!-- SECTION: SALES, PURCHASES & INVENTORY -->
    <li class="sidebar-heading"><span>Sales, Purchases &amp; Inventory</span></li>

    {{-- Product Menu --}}
    @if($isAdmin || $canNavigate('sidebar_product'))
        <li>
            <a href="#product" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-list"></i>
                <span>{{__('db.product')}}</span>
            </a>
            <ul id="product" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('categories-index'))
                    <li id="category-menu"><a href="{{route('category.index')}}">{{__('db.category')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('brand'))
                    <li id="brand-menu"><a href="{{route('brand.index')}}">{{__('db.Brand')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('unit'))
                    <li id="unit-menu"><a href="{{route('unit.index')}}">{{__('db.Unit')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('products-index'))
                    <li id="product-list-menu"><a href="{{route('products.index')}}">{{__('db.product_list')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('products-add'))
                    <li id="product-create-menu"><a href="{{route('products.create')}}">{{__('db.add_product')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('print_barcode'))
                    <li id="printBarcode-menu"><a href="{{route('product.printBarcode')}}">{{__('db.print_barcode')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('adjustment'))
                    <li id="adjustment-list-menu"><a href="{{route('qty_adjustment.index')}}">{{__('db.Adjustment List')}}</a></li>
                    <li id="adjustment-create-menu"><a href="{{route('qty_adjustment.create')}}">{{__('db.Add Adjustment')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('stock_count'))
                    <li id="stock-count-menu"><a href="{{route('stock-count.index')}}">{{__('db.Stock Count')}}</a></li>
                @endif
                @if(in_array('inventory.damage_stock', $enabledCapabilities, true))
                    <li id="damage-stock-menu"><a href="{{route('damage-stock.index')}}">Damage Stock Audit</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Optech Master Registers Menu --}}
    @if($isAdmin || $canNavigate('sidebar_product'))
        <li>
            <a href="#optech_masters" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-view-thumb"></i>
                <span>Master Registers</span>
            </a>
            <ul id="optech_masters" class="collapse list-unstyled">
                <li><a href="{{route('bill-sundry.index')}}">Bill Sundry</a></li>
                <li><a href="{{route('sale-type.index')}}">Sale Types</a></li>
                <li><a href="{{route('purchase-type.index')}}">Purchase Types</a></li>
                <li><a href="{{route('agent.index')}}">Agents &amp; Brokers</a></li>
                <li><a href="{{route('area.index')}}">Areas</a></li>
                <li><a href="{{route('standard-remark.index')}}">Standard Remarks</a></li>
                <li><a href="{{route('document-series.index')}}">Voucher Series (Ctl+F9)</a></li>
            </ul>
        </li>
    @endif

    {{-- Purchase Menu --}}
    @if($isAdmin || $canNavigate('sidebar_purchase'))
        <li>
            <a href="#purchase" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-card"></i>
                <span>Purchase Command Center</span>
            </a>
            <ul id="purchase" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('purchases-index'))
                    <li id="purchase-list-menu"><a href="{{route('purchases.index')}}">Bills</a></li>
                    <li><a href="{{route('purchases.index', ['view' => 'orders', 'purchase_status' => 4])}}">Orders</a></li>
                    <li id="grn-list-menu"><a href="{{route('goods-received-notes.index')}}">Goods Received Note (GRN)</a></li>
                @endif
                @if($shortcutFor('purchase'))
                    <li id="purchase-create-menu" class="sidebar-action"><a data-document-shortcut="purchase" href="{{ $shortcutFor('purchase')['url'] }}">+ New Purchase Bill <kbd>F12</kbd></a></li>
                @endif
                @if($isAdmin || $canNavigate('purchases-import'))
                    <li id="purchase-import-menu"><a href="{{url('purchases/purchase_by_csv')}}">{{__('db.Import Purchase By CSV')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('purchase-return-index'))
                    <li id="purchase-return-menu"><a href="{{route('return-purchase.index')}}">Returns</a></li>
                @endif
                @if($isAdmin || $canNavigate('suppliers-index'))
                    <li><a href="{{route('supplier.index')}}">Suppliers</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Sale Menu --}}
    @if($isAdmin || $canNavigate('sidebar_sale'))
        <li>
            <a href="#sale" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-cart"></i>
                <span>Sales Command Center</span>
            </a>
            <ul id="sale" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('sales-index'))
                    @if(config('commercial.enabled') && config('compliance.enabled'))
                        @if($isAdmin || $canNavigate('returns-index'))<li><a href="{{ url('/compliance/returns') }}">Returns & notes</a></li>@endif
                        @if($isAdmin || $canNavigate('gst-index'))<li><a href="{{ url('/compliance/gst/report') }}">GST review</a></li>@endif
                        @if($isAdmin)<li><a href="{{ url('/compliance/setup') }}">Tax & document settings</a></li>@endif
                    @endif
                    <li id="sale-list-menu"><a href="{{route('sales.index')}}">Bills</a></li>
                    <li><a href="{{route('sales.index', ['view' => 'orders', 'sale_status' => 2])}}">Orders</a></li>
                @endif
                @if($isAdmin || $canNavigate('sales-add'))
                    <li><a href="{{route('sale.pos')}}">POS Terminal</a></li>
                    @if($shortcutFor('sale'))
                        <li id="sale-create-menu" class="sidebar-action"><a data-document-shortcut="sale" href="{{ $shortcutFor('sale')['url'] }}">+ New Sales Bill <kbd>F2</kbd></a></li>
                    @endif
                @endif
                @if($isAdmin || $canNavigate('sales-import'))
                    <li id="sale-import-menu"><a href="{{url('sales/sale_by_csv')}}">{{__('db.Import Sale By CSV')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('packing_slip_challan'))
                    <li id="packing-list-menu"><a href="{{route('packingSlip.index')}}">{{__('db.Packing Slip List')}}</a></li>
                    <li id="challan-list-menu"><a href="{{route('challan.index')}}">{{__('db.Challan List')}}</a></li>
                    <li id="delivery-challan-menu"><a href="{{route('delivery-challans.index')}}">Delivery Challan (DC)</a></li>
                @endif
                @if($isAdmin || $canNavigate('delivery'))
                    <li id="delivery-menu"><a href="{{route('delivery.index')}}">{{__('db.Delivery List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('gift_card'))
                    <li id="gift-card-menu"><a href="{{route('gift_cards.index')}}">{{__('db.Gift Card List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('coupon'))
                    <li id="coupon-menu"><a href="{{route('coupons.index')}}">{{__('db.Coupon List')}}</a></li>
                @endif
                <li id="courier-menu"><a href="{{route('couriers.index')}}">{{__('db.Courier List')}}</a></li>
                @if($isAdmin || $canNavigate('returns-index'))
                    <li id="sale-return-menu"><a href="{{route('return-sale.index')}}">Returns</a></li>
                @endif
                @if($isAdmin || $canNavigate('quotes-index'))
                    <li><a href="{{route('quotations.index')}}">Quotations</a></li>
                @endif
                @if(in_array('sales.exchange', $enabledCapabilities, true))
                    <li id="sale-exchange-menu"><a href="{{route('exchange.index')}}">Product Exchange</a></li>
                @endif
                @if(in_array('sales.installment_plans', $enabledCapabilities, true))
                    <li id="installment-menu"><a href="{{route('installmentplan.index')}}">Installment Plans</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Quotation Menu --}}
    @if($isAdmin || $canNavigate('sidebar_quotation'))
        <li>
            <a href="#quotation" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-document"></i>
                <span>{{__('db.Quotation')}}</span>
            </a>
            <ul id="quotation" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('quotes-index'))
                    <li id="quotation-list-menu"><a href="{{route('quotations.index')}}">{{__('db.Quotation List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('quotes-add'))
                    <li id="quotation-create-menu" class="sidebar-action"><a data-document-shortcut="quotation" href="{{route('quotations.create')}}">+ {{__('db.Add Quotation')}} <kbd>Alt+F10</kbd></a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Transfer Menu --}}
    @if($isAdmin || $canNavigate('sidebar_transfer'))
        <li>
            <a href="#transfer" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-export"></i>
                <span>{{__('db.Transfer')}}</span>
            </a>
            <ul id="transfer" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('transfers-index'))
                    <li id="transfer-list-menu"><a href="{{route('transfers.index')}}">{{__('db.Transfer List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('transfers-add'))
                    <li id="transfer-create-menu"><a href="{{route('transfers.create')}}">{{__('db.Add Transfer')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('transfers-import'))
                    <li id="transfer-import-menu"><a href="{{url('transfers/transfer_by_csv')}}">{{__('db.Import Transfer By CSV')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Catalogue QR & Bookings --}}
    @if(in_array('sales.catalogue_qr', $enabledCapabilities, true))
    <li>
        <a href="{{route('qr.index')}}">
            <i class="dripicons-view-thumb"></i>
            <span>Catalogue QR</span>
        </a>
    </li>
    @endif
    <li>
        <a href="{{route('bookings.calendar')}}">
            <i class="dripicons-calendar"></i>
            <span>Bookings Calendar</span>
        </a>
    </li>

    <!-- SECTION: FINANCE & DOUBLE-ENTRY LEDGER -->
    <li class="sidebar-heading"><span>Finance &amp; Ledger</span></li>

    {{-- Accounting (Double Entry) Menu --}}
    @if($isAdmin || $canNavigate('sidebar_accounting'))
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
                <li id="semantic-map-menu"><a href="{{route('accounting.semantic-mappings')}}">Semantic Account Mappings</a></li>
                @if($isAdmin || $canNavigate('account-index'))
                    <li id="account-list-menu"><a href="{{route('accounts.index')}}">{{__('db.Account List')}} (Cash)</a></li>
                @endif
                @if($isAdmin || $canNavigate('money-transfer'))
                    <li id="money-transfer-menu"><a href="{{route('money-transfers.index')}}">{{__('db.Money Transfer')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Expense Menu --}}
    @if($isAdmin || $canNavigate('sidebar_expense'))
        <li>
            <a href="#expense" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-wallet"></i>
                <span>{{__('db.Expense')}}</span>
            </a>
            <ul id="expense" class="collapse list-unstyled">
                <li id="exp-cat-menu"><a href="{{route('expense_categories.index')}}">{{__('db.Expense Category')}}</a></li>
                @if($isAdmin || $canNavigate('expenses-index'))
                    <li id="exp-list-menu"><a href="{{route('expenses.index')}}">{{__('db.Expense List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('expenses-add'))
                    <li><a id="add-expense" href="">{{__('db.Add Expense')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Income Menu --}}
    @if($isAdmin || $canNavigate('sidebar_income'))
        <li>
            <a href="#income" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-rocket"></i>
                <span>{{__('db.Income')}}</span>
            </a>
            <ul id="income" class="collapse list-unstyled">
                <li id="income-cat-menu"><a href="{{route('income_categories.index')}}">{{__('db.Income Category')}}</a></li>
                @if($isAdmin || $canNavigate('incomes-index'))
                    <li id="income-list-menu"><a href="{{route('incomes.index')}}">{{__('db.Income List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('incomes-add'))
                    <li><a id="add-income" href="">{{__('db.Add Income')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- SECTION: ORGANIZATION & OPERATIONS -->
    <li class="sidebar-heading"><span>Organization &amp; Operations</span></li>

    {{-- People Menu --}}
    @if($isAdmin || $canNavigate('sidebar_people'))
        <li>
            <a href="#people" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-user"></i>
                <span>{{__('db.People')}}</span>
            </a>
            <ul id="people" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('customers-index'))
                    <li id="customer-list-menu"><a href="{{route('customer.index')}}">{{__('db.Customer List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('suppliers-index'))
                    <li id="supplier-list-menu"><a href="{{route('supplier.index')}}">{{__('db.Supplier List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('users-index'))
                    <li id="user-list-menu"><a href="{{route('user.index')}}">{{__('db.User List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('sale-agents'))
                    <li id="sale-agent-menu"><a href="{{route('sale-agents.index')}}">{{__('db.Sale Agents')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('billers-index'))
                    <li id="biller-list-menu"><a href="{{route('biller.index')}}">{{__('db.Biller List')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- HRM Menu --}}
    @if($isAdmin || $canNavigate('sidebar_hrm'))
        <li>
            <a href="#hrm" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-user-group"></i>
                <span>{{__('db.HRM')}}</span>
            </a>
            <ul id="hrm" class="collapse list-unstyled">
                @if($isAdmin || $canNavigate('department'))
                    <li id="dept-menu"><a href="{{route('departments.index')}}">{{__('db.Department')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('designations'))
                    <li id="designations-menu"><a href="{{route('designations.index')}}">{{__('db.Designation')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('shift'))
                    <li id="shift-menu"><a href="{{route('shift.index')}}">{{__('db.Shift')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('employees-index'))
                    <li id="employee-menu"><a href="{{route('employees.index')}}">{{__('db.Employee')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('attendance'))
                    <li id="attendance-menu"><a href="{{route('attendance.index')}}">{{__('db.Attendance')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('holiday'))
                    <li id="holiday-menu"><a href="{{route('holidays.index')}}">{{__('db.Holiday')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('overtime'))
                    <li id="overtime-menu"><a href="{{route('overtime.index')}}">{{__('db.Overtime')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('leave-type'))
                    <li id="leave-type-menu"><a href="{{route('leave-type.index')}}">{{__('db.Leave Type')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('leave'))
                    <li id="leave-menu"><a href="{{route('leave.index')}}">{{__('db.Leaves')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('payroll'))
                    <li id="payroll-menu"><a href="{{route('payroll.index')}}">{{__('db.Payroll')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    <!-- SECTION: INDUSTRY ADDONS & MODULAR SOLUTIONS -->
    <li class="sidebar-heading"><span>Industry Addons</span></li>

    {{-- Water Logistics --}}
    @if(in_array('operations.water_logistics', $enabledCapabilities, true))
        <li>
            <a href="#water-logistics-menu" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-drop"></i>
                <span>Water Logistics</span>
            </a>
            <ul id="water-logistics-menu" class="collapse list-unstyled">
                <li><a href="{{route('water-logistics.index')}}">Fleet Trips &amp; Can Routes</a></li>
                <li><a href="{{route('water-logistics.credit-aging')}}">Corporate Credit Aging</a></li>
            </ul>
        </li>
    @endif

    {{-- Bakery & Cafe POS --}}
    @if(in_array('operations.cafe_bakery', $enabledCapabilities, true))
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

    {{-- Repair & Service Center --}}
    @if(in_array('service.repair', $enabledCapabilities, true))
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

    {{-- Project Management --}}
    @if(in_array('operations.projects', $enabledCapabilities, true))
        <li>
            <a href="#project-mgmt-menu" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-briefcase"></i>
                <span>Project Management</span>
            </a>
            <ul id="project-mgmt-menu" class="collapse list-unstyled">
                <li><a href="{{url('/operations/projects')}}">Projects List</a></li>
                <li><a href="{{route('project-management.tasks')}}">Task Board</a></li>
                <li><a href="{{route('project-management.categories')}}">Project Categories</a></li>
            </ul>
        </li>
    @endif

    {{-- Manufacturing --}}
    @if(config('operations.enabled') && in_array('operations.job_work', $enabledCapabilities, true))
        <li><a href="{{ url('/operations/job-work') }}"><i class="dripicons-network-3"></i><span>Subcontracting / Job work</span></a></li>
    @endif
    @if(config('operations.enabled') && auth()->user()->role_id <= 2)
        <li><a href="{{ url('/operations/profiles') }}"><i class="dripicons-gear"></i><span>Business profile</span></a></li>
    @endif
    @if(in_array('manufacturing.production', $enabledCapabilities, true))
        <li>
            <a href="#manufacturing" aria-expanded="false" data-toggle="collapse">
                <i class="fa fa-industry"></i>
                <span>{{__('db.Manufacturing')}}</span>
            </a>
            <ul id="manufacturing" class="collapse list-unstyled">
                <li id="production-list-menu"><a href="{{url('/operations/manufacturing')}}">{{__('db.Production List')}}</a></li>
                <li id="production-create-menu"><a href="{{url('/operations/manufacturing')}}">{{__('db.Add Production')}}</a></li>
                <li id="recipe-menu"><a href="{{url('/operations/manufacturing')}}">{{__('db.Recipe')}}</a></li>
            </ul>
        </li>
    @endif

    <!-- SECTION: ANALYTICS & INTELLIGENCE -->
    <li class="sidebar-heading"><span>Analytics &amp; Intelligence</span></li>

    {{-- Reports Menu --}}
    @if($isAdmin || $canNavigate('sidebar_reports'))
        <li>
            <a href="#report" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-document-remove"></i>
                <span>{{__('db.Reports')}}</span>
            </a>
            <ul id="report" class="collapse list-unstyled">
                @if($isAdmin || $role->id <= 2)
                    <li id="activity-log-menu"><a href="{{route('setting.activityLog')}}">{{__('db.Activity Log')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('profit-loss'))
                    <li id="profit-loss-report-menu">
                        {!! Form::open(['route' => 'report.profitLoss', 'method' => 'post', 'id' => 'profitLoss-report-form']) !!}
                            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
                            <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                            <a id="profitLoss-link" href="">{{__('db.Summary Report')}}</a>
                        {!! Form::close() !!}
                    </li>
                @endif
                @if($isAdmin || $canNavigate('best-seller'))
                    <li id="best-seller-report-menu"><a href="{{url('report/best_seller')}}">{{__('db.Best Seller')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('product-report'))
                    <li id="product-report-menu">
                        {!! Form::open(['route' => 'report.product', 'method' => 'get', 'id' => 'product-report-form']) !!}
                            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
                            <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                            <input type="hidden" name="warehouse_id" value="0" />
                            <a id="report-link" href="">{{__('db.Product Report')}}</a>
                        {!! Form::close() !!}
                    </li>
                @endif
                @if($isAdmin || $canNavigate('daily-sale'))
                    <li id="daily-sale-report-menu"><a href="{{url('report/daily_sale/'.date('Y').'/'.date('m'))}}">{{__('db.Daily Sale')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('monthly-sale'))
                    <li id="monthly-sale-report-menu"><a href="{{url('report/monthly_sale/'.date('Y'))}}">{{__('db.Monthly Sale')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('daily-purchase'))
                    <li id="daily-purchase-report-menu"><a href="{{url('report/daily_purchase/'.date('Y').'/'.date('m'))}}">{{__('db.Daily Purchase')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('monthly-purchase'))
                    <li id="monthly-purchase-report-menu"><a href="{{url('report/monthly_purchase/'.date('Y'))}}">{{__('db.Monthly Purchase')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('sale-report'))
                    <li id="sale-report-menu">
                        {!! Form::open(['route' => 'report.sale', 'method' => 'post', 'id' => 'sale-report-form']) !!}
                            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
                            <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                            <input type="hidden" name="warehouse_id" value="0" />
                            <a id="sale-report-link" href="">{{__('db.Sale Report')}}</a>
                        {!! Form::close() !!}
                    </li>
                @endif
                <li id="challan-report-menu"><a href="{{route('report.challan')}}">{{__('db.Challan Report')}}</a></li>
                @if($isAdmin || $canNavigate('sale-report-chart'))
                    <li id="sale-report-chart-menu">
                        {!! Form::open(['route' => 'report.saleChart', 'method' => 'post', 'id' => 'sale-report-chart-form']) !!}
                            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
                            <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                            <input type="hidden" name="warehouse_id" value="0" />
                            <input type="hidden" name="time_period" value="weekly" />
                            <a id="sale-report-chart-link" href="">{{__('db.Sale Report Chart')}}</a>
                        {!! Form::close() !!}
                    </li>
                @endif
                @if($isAdmin || $canNavigate('payment-report'))
                    <li id="payment-report-menu">
                        {!! Form::open(['route' => 'report.paymentByDate', 'method' => 'post', 'id' => 'payment-report-form']) !!}
                            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
                            <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                            <a id="payment-report-link" href="">{{__('db.Payment Report')}}</a>
                        {!! Form::close() !!}
                    </li>
                @endif
                @if($isAdmin || $canNavigate('purchase-report'))
                    <li id="purchase-report-menu">
                        {!! Form::open(['route' => 'report.purchase', 'method' => 'post', 'id' => 'purchase-report-form']) !!}
                            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
                            <input type="hidden" name="end_date" value="{{date('Y-m-d')}}" />
                            <input type="hidden" name="warehouse_id" value="0" />
                            <a id="purchase-report-link" href="">{{__('db.Purchase Report')}}</a>
                        {!! Form::close() !!}
                    </li>
                @endif
                @if($isAdmin || $canNavigate('warehouse-report'))
                    <li id="warehouse-report-menu"><a id="warehouse-report-link" href="">{{__('db.Warehouse Report')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('warehouse-stock-report'))
                    <li id="warehouse-stock-report-menu"><a href="{{route('report.warehouseStock')}}">{{__('db.Warehouse Stock Chart')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('product-expiry-report'))
                    <li id="productExpiry-report-menu"><a href="{{route('report.productExpiry')}}">{{__('db.Product Expiry Report')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('product-qty-alert'))
                    <li id="qtyAlert-report-menu"><a href="{{route('report.qtyAlert')}}">{{__('db.Product Quantity Alert')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('dso-report'))
                    <li id="daily-sale-objective-menu"><a href="{{route('report.dailySaleObjective')}}">{{__('db.Daily Sale Objective Report')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('user-report'))
                    <li id="user-report-menu"><a id="user-report-link" href="">{{__('db.User Report')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('biller-report'))
                    <li id="biller-report-menu"><a id="biller-report-link" href="">{{__('db.Biller Report')}}</a></li>
                @endif
                <li id="cash-register-report-menu"><a href="{{route('cashRegister.index')}}">{{__('db.Cash Register')}}</a></li>
            </ul>
        </li>
    @endif

    <!-- SECTION: SYSTEM & SETTINGS -->
    <li class="sidebar-heading"><span>System &amp; Settings</span></li>

    {{-- WhatsApp Menu --}}
    @if(in_array('communications.whatsapp', $enabledCapabilities, true))
    <li>
        <a href="#whatsapp" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-message"></i>
            <span>{{ __('db.whatsapp') }}</span>
        </a>
        <ul id="whatsapp" class="collapse list-unstyled">
            <li id="whatsapp-settings-menu"><a href="{{ route('whatsapp.settings') }}">{{ __('db.whatsapp_settings') }}</a></li>
            <li id="whatsapp-templates-menu"><a href="{{ route('whatsapp.templates') }}">{{ __('db.message_templates') }}</a></li>
            <li id="whatsapp-send-menu"><a href="{{ route('whatsapp.send.page') }}">{{ __('db.send_message') }}</a></li>
        </ul>
    </li>
    @endif

    {{-- Settings Menu --}}
    @if($isAdmin || $canNavigate('sidebar_settings'))
        <li>
            <a href="#setting" aria-expanded="false" data-toggle="collapse">
                <i class="dripicons-gear"></i>
                <span>{{__('db.settings')}}</span>
            </a>
            <ul id="setting" class="collapse list-unstyled">
                @if($isAdmin || \Auth::user()->role_id <= 2)
                    <li id="printer-menu"><a href="{{route('printers.index')}}">{{__('db.Receipt Printers')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('invoice_setting'))
                    <li id="invoice-menu"><a href="{{route('settings.invoice.index')}}">{{__('db.Invoice Settings')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('role_permission'))
                    <li id="role-menu"><a href="{{route('role.index')}}">{{__('db.Role Permission')}}</a></li>
                    <li><a href="{{route('smstemplates.index')}}">{{__('db.SMS Template')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('custom_field'))
                    <li id="custom-field-list-menu"><a href="{{route('custom-fields.index')}}">{{__('db.Custom Field List')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('discount_plan'))
                    <li id="discount-plan-list-menu"><a href="{{route('discount-plans.index')}}">{{__('db.Discount Plan')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('discount'))
                    <li id="discount-list-menu"><a href="{{route('discounts.index')}}">{{__('db.Discount')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('all_notification'))
                    <li id="notification-list-menu"><a href="{{route('notifications.index')}}">{{__('db.All Notification')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('send_notification'))
                    <li id="notification-menu"><a href="" id="send-notification">{{__('db.Send Notification')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('warehouse'))
                    <li id="warehouse-menu"><a href="{{route('warehouse.index')}}">{{__('db.Warehouse')}}</a></li>
                @endif
                @if($isAdmin || \Auth::user()->role_id <= 2)
                    <li id="table-menu"><a href="{{route('tables.index')}}">{{__('db.Tables')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('customer_group'))
                    <li id="customer-group-menu"><a href="{{route('customer_group.index')}}">{{__('db.Customer Group')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('currency'))
                    <li id="currency-menu"><a href="{{route('currency.index')}}">{{__('db.Currency')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('tax'))
                    <li id="tax-menu"><a href="{{route('tax.index')}}">{{__('db.Tax')}}</a></li>
                @endif
                <li id="user-menu"><a href="{{route('user.profile', ['id' => Auth::id()])}}">{{__('db.User Profile')}}</a></li>
                @if($isAdmin || $canNavigate('create_sms'))
                    <li id="create-sms-menu"><a href="{{route('setting.createSms')}}">{{__('db.Create SMS')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('backup_database'))
                    <li><a href="{{route('setting.backup')}}">{{__('db.Backup Database')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('general_setting'))
                    <li id="general-setting-menu"><a href="{{route('setting.general')}}">{{__('db.General Setting')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('mail_setting'))
                    <li id="mail-setting-menu"><a href="{{route('setting.mail')}}">{{__('db.Mail Setting')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('reward_point_setting'))
                    <li id="reward-point-setting-menu"><a href="{{route('setting.rewardPoint')}}">{{__('db.Reward Point Setting')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('sms_setting'))
                    <li id="sms-setting-menu"><a href="{{route('setting.sms')}}">{{__('db.SMS Setting')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('payment_gateway_setting'))
                    <li id="payment-gateway-setting-menu"><a href="{{route('setting.gateway')}}">{{__('db.Payment Gateways')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('pos_setting'))
                    <li id="pos-setting-menu"><a href="{{route('setting.pos')}}">POS {{__('db.settings')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('hrm_setting'))
                    <li id="hrm-setting-menu"><a href="{{route('setting.hrm')}}">{{__('db.HRM Setting')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('barcode_setting'))
                    <li id="barcode-setting-menu"><a href="{{route('barcodes.index')}}">{{__('db.Barcode Settings')}}</a></li>
                @endif
                @if($isAdmin || $canNavigate('language_setting'))
                    <li id="languages"><a href="{{route('languages')}}">{{__('db.Languages')}}</a></li>
                @endif
            </ul>
        </li>
    @endif

    {{-- Addons menu --}}
    @if($isAdmin || $canNavigate('addons'))
        @if(\Auth::user()->role_id != 5)
            @if(!config('database.connections.zoloerp_landlord'))
                <li><a href="{{url('addon-list')}}" id="addon-list"><i class="dripicons-flag"></i><span>{{__('db.Addons')}}</span></a></li>
            @endif
            @if(in_array('sales.woocommerce', $enabledCapabilities, true) && Route::has('woocommerce.index'))
                <li><a href="{{route('woocommerce.index')}}"><i class="fa fa-wordpress"></i><span>WooCommerce</span></a></li>
            @endif
            @if(in_array('sales.ecommerce', $enabledCapabilities, true) && View::exists('ecommerce::backend.layout.sidebar-menu'))
                <li>
                    <a href="#ecommerce" aria-expanded="false" data-toggle="collapse"><i class="dripicons-shopping-bag"></i><span>eCommerce</span></a>
                    <ul id="ecommerce" class="collapse list-unstyled">
                        @include('ecommerce::backend.layout.sidebar-menu')
                    </ul>
                </li>
            @endif
            @if(in_array('operations.projects', $enabledCapabilities, true) && View::exists('project::backend.layout.sidebar-menu'))
                @include('project::backend.layout.sidebar-menu')
            @endif
            @if(in_array('operations.restaurant', $enabledCapabilities, true) && View::exists('restaurant::backend.layout.sidebar-menu'))
                @include('restaurant::backend.layout.sidebar-menu')
            @endif
        @endif
    @endif
</ul>
@include('backend.partials.document-shortcuts', ['documentShortcuts' => $documentShortcuts])
