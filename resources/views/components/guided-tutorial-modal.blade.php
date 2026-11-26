<div id="zolo-guided-tour-modal" tabindex="-1" role="dialog" aria-labelledby="guidedTourModalLabel" aria-hidden="true" class="modal fade text-left" data-backdrop="static">
    <div role="document" class="modal-dialog modal-lg zolo-tour-dialog">
        <div class="modal-content zolo-tour-card">
            <!-- Header -->
            <div class="zolo-tour-header">
                <div class="zolo-tour-header-info">
                    <div class="zolo-tour-logo-badge">
                        <i class="dripicons-lightbulb"></i>
                    </div>
                    <div>
                        <h5 class="zolo-tour-title" id="guidedTourModalLabel">Interactive Setup Guide & Tour</h5>
                        <p class="zolo-tour-subtitle mb-0">Master the 6 core pillars to launch and operate zoloERP Pro successfully</p>
                    </div>
                </div>
                <div class="zolo-tour-header-actions">
                    <span class="zolo-tour-step-counter" id="tour-step-counter">Step 1 of 6</span>
                    <button type="button" class="zolo-tour-close-btn" data-dismiss="modal" aria-label="Close">
                        <i class="dripicons-cross"></i>
                    </button>
                </div>
            </div>

            <!-- Progress Rail -->
            <div class="zolo-tour-rail">
                <div class="zolo-tour-progress-bar-wrap">
                    <div class="zolo-tour-progress-bar-fill" id="tour-progress-fill"></div>
                </div>
                <div class="zolo-tour-rail-steps">
                    <button type="button" class="tour-rail-step active" data-step="1" title="Company Profile">
                        <span class="step-num">1</span>
                        <span class="step-text">Company</span>
                    </button>
                    <button type="button" class="tour-rail-step" data-step="2" title="Warehouses">
                        <span class="step-num">2</span>
                        <span class="step-text">Godowns</span>
                    </button>
                    <button type="button" class="tour-rail-step" data-step="3" title="Products">
                        <span class="step-num">3</span>
                        <span class="step-text">Catalog</span>
                    </button>
                    <button type="button" class="tour-rail-step" data-step="4" title="Parties">
                        <span class="step-num">4</span>
                        <span class="step-text">Parties</span>
                    </button>
                    <button type="button" class="tour-rail-step" data-step="5" title="Invoicing">
                        <span class="step-num">5</span>
                        <span class="step-text">Invoicing</span>
                    </button>
                    <button type="button" class="tour-rail-step" data-step="6" title="Due Clearance">
                        <span class="step-num">6</span>
                        <span class="step-text">Dues</span>
                    </button>
                </div>
            </div>

            <!-- Body / Step Cards Container -->
            <div class="modal-body zolo-tour-body">
                <!-- STEP 1: Company Profile -->
                <div class="zolo-tour-slide active" data-slide="1">
                    <div class="tour-slide-hero theme-purple">
                        <div class="tour-hero-icon"><i class="fa fa-building-o"></i></div>
                        <div class="tour-hero-content">
                            <span class="tour-step-pill">Foundation Setup</span>
                            <h3 class="tour-hero-title">1. Company Profile, GST & Fiscal Rules</h3>
                            <p class="tour-hero-desc">Configure your legal business identity, GST registration classification, official trade names, fiscal calendar years, and billing document series.</p>
                        </div>
                    </div>
                    <div class="tour-slide-details">
                        <div class="row">
                            <div class="col-md-7">
                                <h6 class="font-weight-bold text-dark mb-2">Key Setup Milestones:</h6>
                                <ul class="tour-checklist">
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Legal & Trade Names:</strong> Match official registered records for GST invoices.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>GST Registration Type:</strong> Mandatory selection of Regular, Composition, or Unregistered.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Active Financial Year:</strong> Strict transaction boundaries prevent backdated posting errors.</li>
                                </ul>
                            </div>
                            <div class="col-md-5">
                                <div class="tour-action-card">
                                    <div class="tour-action-label">Quick Destination</div>
                                    <p class="small text-muted mb-2">Open general settings to verify company registration and tax details.</p>
                                    @can('general_setting')
                                    <a href="{{ route('setting.general') }}" class="btn btn-sm btn-block btn-primary">
                                        <i class="fa fa-cog mr-1"></i> Open Company Settings
                                    </a>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Warehouses & Godowns -->
                <div class="zolo-tour-slide" data-slide="2">
                    <div class="tour-slide-hero theme-sky">
                        <div class="tour-hero-icon"><i class="fa fa-archive"></i></div>
                        <div class="tour-hero-content">
                            <span class="tour-step-pill">Inventory Architecture</span>
                            <h3 class="tour-hero-title">2. Storage Godowns & Branch Warehouses</h3>
                            <p class="tour-hero-desc">Organize your physical inventory across central godowns, branch showrooms, and shop-floor storage locations with real-time stock isolation.</p>
                        </div>
                    </div>
                    <div class="tour-slide-details">
                        <div class="row">
                            <div class="col-md-7">
                                <h6 class="font-weight-bold text-dark mb-2">Key Setup Milestones:</h6>
                                <ul class="tour-checklist">
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Central Storage Hub:</strong> Establish your primary receiving warehouse.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Stock Transfers & DC:</strong> Move items between godowns using delivery challans.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Physical Audit:</strong> Reconcile actual shelf stock with system records effortlessly.</li>
                                </ul>
                            </div>
                            <div class="col-md-5">
                                <div class="tour-action-card">
                                    <div class="tour-action-label">Quick Destination</div>
                                    <p class="small text-muted mb-2">Create and configure your central warehouse and branch locations.</p>
                                    <a href="{{ route('warehouse.index') }}" class="btn btn-sm btn-block btn-info">
                                        <i class="fa fa-building mr-1"></i> Manage Warehouses
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Products & Catalog -->
                <div class="zolo-tour-slide" data-slide="3">
                    <div class="tour-slide-hero theme-emerald">
                        <div class="tour-hero-icon"><i class="fa fa-cubes"></i></div>
                        <div class="tour-hero-content">
                            <span class="tour-step-pill">Catalog & Pricing</span>
                            <h3 class="tour-hero-title">3. Master Products, Categories & GST Rates</h3>
                            <p class="tour-hero-desc">Build your product inventory with standard items, combo/recipe compositions, units of measurement, HSN codes, and automatic profit margin calculations.</p>
                        </div>
                    </div>
                    <div class="tour-slide-details">
                        <div class="row">
                            <div class="col-md-7">
                                <h6 class="font-weight-bold text-dark mb-2">Key Setup Milestones:</h6>
                                <ul class="tour-checklist">
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Quick-Add Attributes:</strong> Inline [+] creation for Brands, Categories, and Units.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Smart Margin Engine:</strong> Auto-computes sale price from cost + percentage margin.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Composition & Recipe:</strong> Track sub-assembly parts and production wastage.</li>
                                </ul>
                            </div>
                            <div class="col-md-5">
                                <div class="tour-action-card">
                                    <div class="tour-action-label">Quick Destination</div>
                                    <p class="small text-muted mb-2">Add your first product or bulk import your existing price list.</p>
                                    <a href="{{ route('products.index') }}" class="btn btn-sm btn-block btn-success">
                                        <i class="fa fa-tag mr-1"></i> Explore Product Catalog
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 4: Parties (Customers & Suppliers) -->
                <div class="zolo-tour-slide" data-slide="4">
                    <div class="tour-slide-hero theme-indigo">
                        <div class="tour-hero-icon"><i class="fa fa-users"></i></div>
                        <div class="tour-hero-content">
                            <span class="tour-step-pill">Stakeholder Directory</span>
                            <h3 class="tour-hero-title">4. Customers, Groups & Supplier Vendors</h3>
                            <p class="tour-hero-desc">Maintain comprehensive party master records with billing addresses, GSTIN numbers, customer group pricing tiers, and opening dues.</p>
                        </div>
                    </div>
                    <div class="tour-slide-details">
                        <div class="row">
                            <div class="col-md-7">
                                <h6 class="font-weight-bold text-dark mb-2">Key Setup Milestones:</h6>
                                <ul class="tour-checklist">
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Customer Groups:</strong> Categorize retail, wholesale, and distributor clients with dedicated discount policies.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Print Name / Display Name:</strong> Print specialized legal entity names on vouchers.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Due Ledger:</strong> 1-click access to party-wise receivables and payables history.</li>
                                </ul>
                            </div>
                            <div class="col-md-5">
                                <div class="tour-action-card">
                                    <div class="tour-action-label">Quick Destination</div>
                                    <p class="small text-muted mb-2">View customers, create customer groups, or add vendor suppliers.</p>
                                    <a href="{{ route('customer.index') }}" class="btn btn-sm btn-block btn-primary">
                                        <i class="fa fa-user-plus mr-1"></i> Manage Customers
                                    </a>
                                    <a href="{{ route('supplier.index') }}" class="btn btn-sm btn-block btn-outline-secondary mt-1">
                                        <i class="fa fa-truck mr-1"></i> Manage Suppliers
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 5: Invoicing & Purchases -->
                <div class="zolo-tour-slide" data-slide="5">
                    <div class="tour-slide-hero theme-amber">
                        <div class="tour-hero-icon"><i class="fa fa-file-text-o"></i></div>
                        <div class="tour-hero-content">
                            <span class="tour-step-pill">Operational Billing</span>
                            <h3 class="tour-hero-title">5. Sales Invoicing, Purchase Bills & GRNs</h3>
                            <p class="tour-hero-desc">Execute rapid point-of-sale or wholesale invoicing, issue Goods Receipt Notes (GRN), and preview bills with beautiful in-place modal cards.</p>
                        </div>
                    </div>
                    <div class="tour-slide-details">
                        <div class="row">
                            <div class="col-md-7">
                                <h6 class="font-weight-bold text-dark mb-2">Key Setup Milestones:</h6>
                                <ul class="tour-checklist">
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Real-time Stock Deductions:</strong> Invoices automatically adjust warehouse bins.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>In-Place Voucher View:</strong> Inspect line items, taxes, and payments with direct edit options.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Goods Receipt Notes (GRN):</strong> Accurately track received vs ordered purchase quantities.</li>
                                </ul>
                            </div>
                            <div class="col-md-5">
                                <div class="tour-action-card">
                                    <div class="tour-action-label">Quick Destination</div>
                                    <p class="small text-muted mb-2">Generate your first sales invoice or post an incoming purchase order.</p>
                                    <a href="{{ route('sales.index') }}" class="btn btn-sm btn-block btn-warning text-white">
                                        <i class="fa fa-shopping-cart mr-1"></i> Open Sales Desk
                                    </a>
                                    <a href="{{ route('purchases.index') }}" class="btn btn-sm btn-block btn-outline-secondary mt-1">
                                        <i class="fa fa-inbox mr-1"></i> Open Purchases Desk
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 6: Centralized Due Management -->
                <div class="zolo-tour-slide" data-slide="6">
                    <div class="tour-slide-hero theme-rose">
                        <div class="tour-hero-icon"><i class="fa fa-money"></i></div>
                        <div class="tour-hero-content">
                            <span class="tour-step-pill">Financial Command Center</span>
                            <h3 class="tour-hero-title">6. Centralized Due & Payment Command Center</h3>
                            <p class="tour-hero-desc">Monitor cash flows, collect customer receivables, disburse vendor payables, and clear debts with intelligent FIFO batch settlements.</p>
                        </div>
                    </div>
                    <div class="tour-slide-details">
                        <div class="row">
                            <div class="col-md-7">
                                <h6 class="font-weight-bold text-dark mb-2">Key Setup Milestones:</h6>
                                <ul class="tour-checklist">
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Unified Hub:</strong> Customer receivables, supplier payables, and expense dues in one place.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>FIFO Batch Settle:</strong> Clear oldest outstanding bills automatically when collecting lump sums.</li>
                                    <li><i class="fa fa-check-circle text-success mr-2"></i><strong>Instant Receipts:</strong> Auto-generate printable payment vouchers and bank ledger postings.</li>
                                </ul>
                            </div>
                            <div class="col-md-5">
                                <div class="tour-action-card">
                                    <div class="tour-action-label">Quick Destination</div>
                                    <p class="small text-muted mb-2">Inspect open dues across all customers and suppliers in real time.</p>
                                    <a href="{{ route('dues.index') }}" class="btn btn-sm btn-block btn-danger">
                                        <i class="fa fa-bolt mr-1"></i> Due Command Center
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Controls -->
            <div class="zolo-tour-footer">
                <div class="zolo-tour-footer-left">
                    <label class="tour-remember-checkbox mb-0">
                        <input type="checkbox" id="chk-dismiss-guided-tour">
                        <span>Don't show automatically on next login</span>
                    </label>
                </div>
                <div class="zolo-tour-footer-right">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-tour-prev" style="display: none;">
                        <i class="fa fa-angle-left mr-1"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="btn-tour-next">
                        Next Step <i class="fa fa-angle-right ml-1"></i>
                    </button>
                    <button type="button" class="btn btn-success btn-sm" id="btn-tour-finish" style="display: none;" data-dismiss="modal">
                        <i class="fa fa-check mr-1"></i> Complete Tour
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


@push('scripts')
<script>
(function() {
    var totalSteps = 6;
    var currentStep = 1;

    function showStep(step) {
        if (step < 1) step = 1;
        if (step > totalSteps) step = totalSteps;
        currentStep = step;

        $('#tour-step-counter').text('Step ' + currentStep + ' of ' + totalSteps);
        var progressPct = (currentStep / totalSteps) * 100;
        $('#tour-progress-fill').css('width', progressPct + '%');

        $('.zolo-tour-slide').removeClass('active');
        $('.zolo-tour-slide[data-slide="' + currentStep + '"]').addClass('active');

        $('.tour-rail-step').each(function() {
            var s = parseInt($(this).attr('data-step'));
            $(this).removeClass('active completed');
            if (s === currentStep) {
                $(this).addClass('active');
            } else if (s < currentStep) {
                $(this).addClass('completed');
            }
        });

        if (currentStep === 1) {
            $('#btn-tour-prev').hide();
        } else {
            $('#btn-tour-prev').show();
        }

        if (currentStep === totalSteps) {
            $('#btn-tour-next').hide();
            $('#btn-tour-finish').show();
        } else {
            $('#btn-tour-next').show();
            $('#btn-tour-finish').hide();
        }
    }

    $(document).ready(function() {
        $('#btn-tour-next').on('click', function() {
            showStep(currentStep + 1);
        });

        $('#btn-tour-prev').on('click', function() {
            showStep(currentStep - 1);
        });

        $('.tour-rail-step').on('click', function() {
            var target = parseInt($(this).attr('data-step'));
            showStep(target);
        });

        $('#btn-tour-finish').on('click', function() {
            localStorage.setItem('zolo_guided_tour_dismissed', '1');
            $('#zolo-guided-tour-modal').modal('hide');
        });

        $('#chk-dismiss-guided-tour').on('change', function() {
            if ($(this).is(':checked')) {
                localStorage.setItem('zolo_guided_tour_dismissed', '1');
            } else {
                localStorage.removeItem('zolo_guided_tour_dismissed');
            }
        });

        $(document).on('click', '#btn-open-guided-tour', function(e) {
            e.preventDefault();
            showStep(1);
            $('#zolo-guided-tour-modal').modal('show');
        });

        // Auto-show on first visits (stops automatically after 2 times)
        var dismissed = localStorage.getItem('zolo_guided_tour_dismissed');
        var viewCount = parseInt(localStorage.getItem('zolo_guided_tour_view_count') || '0', 10);
        if (!dismissed && viewCount < 2) {
            setTimeout(function() {
                // Only show if user is on dashboard or workspace
                if (window.location.pathname.indexOf('/dashboard') !== -1 || window.location.pathname.indexOf('/workspace') !== -1 || window.location.pathname === '/') {
                    viewCount++;
                    localStorage.setItem('zolo_guided_tour_view_count', viewCount.toString());
                    if (viewCount >= 2) {
                        localStorage.setItem('zolo_guided_tour_dismissed', '1');
                    }
                    showStep(1);
                    $('#zolo-guided-tour-modal').modal('show');
                }
            }, 1200);
        }
    });
})();
</script>
@endpush
