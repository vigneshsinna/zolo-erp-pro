@php
    $currentTab = request()->query('tab', 'dashboard');
    $devTotalSubscribers = $stats['tenants_total'] ?? 0;
    $devActivePlans = $stats['plans_active'] ?? 0;
    $devTotalModules = $stats['modules_total'] ?? 45;
    $devAuditCount = $stats['audits_count'] ?? 0;
    $devPendingUsers = $stats['pending_activations_count'] ?? 0;
@endphp

<div class="dev-side-search d-print-none" role="search">
    <input type="search" id="dev-menu-search" class="form-control form-control-sm" placeholder="Search menu…" aria-label="Search developer navigation" autocomplete="off">
</div>

<ul id="dev-side-menu" class="side-menu dev-side-menu list-unstyled d-print-none">
    <li class="sidebar-heading"><span>Command Center</span></li>
    <li class="{{ $currentTab === 'dashboard' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'dashboard']) }}" data-keywords="dashboard overview kpis mrr health">
            <i class="dripicons-meter" aria-hidden="true"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <li class="sidebar-heading"><span>Subscribers</span></li>
    <li class="{{ $currentTab === 'tenants' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'tenants']) }}" data-keywords="subscribers tenants companies groups independent licenses">
            <i class="dripicons-store" aria-hidden="true"></i>
            <span>Subscribers</span>
            <span class="badge badge-primary float-right">{{ $devTotalSubscribers }}</span>
        </a>
    </li>
    <li class="{{ $currentTab === 'admins' && request()->query('filter') === 'pending' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'admins', 'filter' => 'pending']) }}" data-keywords="pending activations registrations users approvals">
            <i class="dripicons-user-id" aria-hidden="true"></i>
            <span>Pending Activations</span>
            @if($devPendingUsers > 0)
                <span class="badge badge-warning float-right">{{ $devPendingUsers }}</span>
            @endif
        </a>
    </li>

    <li class="sidebar-heading"><span>Access &amp; Gating</span></li>
    <li class="{{ $currentTab === 'tenants' && request()->filled('company') ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'tenants']) }}" data-keywords="companies verticals industry presets gstin">
            <i class="dripicons-briefcase" aria-hidden="true"></i>
            <span>Companies &amp; Verticals</span>
        </a>
    </li>
    <li>
        <a href="{{ route('developer.index', ['tab' => 'tenants']) }}#branch-gates" data-keywords="branches location gates isolation modes">
            <i class="dripicons-location" aria-hidden="true"></i>
            <span>Branches &amp; Gates</span>
        </a>
    </li>
    <li class="{{ $currentTab === 'admins' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'admins']) }}" data-keywords="users seats roles matrix permissions simulator">
            <i class="dripicons-user-group" aria-hidden="true"></i>
            <span>Users, Seats &amp; Matrix</span>
        </a>
    </li>

    <li class="sidebar-heading"><span>Plans &amp; Entitlements</span></li>
    <li class="{{ $currentTab === 'plans' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'plans']) }}" data-keywords="plans saas pricing tiers quotas packages">
            <i class="dripicons-tags" aria-hidden="true"></i>
            <span>SaaS Plans</span>
            <span class="badge badge-secondary float-right">{{ $devActivePlans }}</span>
        </a>
    </li>
    <li class="{{ $currentTab === 'modules' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'modules']) }}" data-keywords="modules catalog capabilities ai erp features">
            <i class="dripicons-view-thumb" aria-hidden="true"></i>
            <span>Module &amp; AI Catalog</span>
            <span class="badge badge-info float-right">{{ $devTotalModules }}</span>
        </a>
    </li>
    <li>
        <a href="{{ route('developer.index', ['tab' => 'tenants']) }}#screen-switches" data-keywords="switches screens routes toggles blocking">
            <i class="dripicons-toggles" aria-hidden="true"></i>
            <span>Screen Switches</span>
        </a>
    </li>

    <li class="sidebar-heading"><span>Metering &amp; Governance</span></li>
    <li>
        <a href="{{ route('developer.index', ['tab' => 'tenants']) }}#quotas" data-keywords="quotas usage telemetry ai credits storage">
            <i class="dripicons-graph-bar" aria-hidden="true"></i>
            <span>Quotas &amp; AI Telemetry</span>
        </a>
    </li>
    <li class="{{ $currentTab === 'audits' ? 'active' : '' }}">
        <a href="{{ route('developer.index', ['tab' => 'audits']) }}" data-keywords="audits logs security timeline history compliance">
            <i class="dripicons-document" aria-hidden="true"></i>
            <span>Immutable Audit Log</span>
            @if($devAuditCount > 0)
                <span class="badge badge-secondary float-right">{{ $devAuditCount }}</span>
            @endif
        </a>
    </li>
</ul>
