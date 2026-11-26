@extends('backend.layout.main')
@section('title', 'Developer Portal · SaaS Licensing')
@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/developer.css') }}">
@endpush
@section('content')
<x-section-command-bar title="Developer Portal" icon="dripicons-code" badge="Multi-Tenant SaaS Licensing &amp; Module Control" :links="[]" />

@if(session('message'))<div class="alert alert-success dev-flash" role="status">{{ session('message') }}</div>@endif
@if($errors->any())<div class="alert alert-danger dev-flash" role="alert">{{ $errors->first() }}</div>@endif

@php
    $badgeClass = fn (string $status): string => match ($status) {
        'active', 'unlicensed_legacy' => 'dev-badge-active',
        'trial' => 'dev-badge-trial',
        'grace' => 'dev-badge-grace',
        'suspended', 'expired', 'cancelled' => 'dev-badge-blocked',
        default => 'dev-badge-neutral',
    };
@endphp

<section class="dev-shell">
    {{-- Platform KPI Strip --}}
    <div class="dev-kpi-strip">
        <div class="dev-kpi">
            <span class="dev-kpi-label">Tenants (Companies)</span>
            <span class="dev-kpi-val">{{ $stats['tenants_total'] }}</span>
            <span class="dev-kpi-sub">{{ $stats['tenants_active'] }} active · {{ $stats['tenants_trial_grace'] }} trial/grace · {{ $stats['tenants_blocked'] }} paused</span>
        </div>
        <div class="dev-kpi">
            <span class="dev-kpi-label">SaaS Plans</span>
            <span class="dev-kpi-val">{{ $stats['plans_active'] }}</span>
            <span class="dev-kpi-sub">{{ $plans->count() }} total templates</span>
        </div>
        <div class="dev-kpi">
            <span class="dev-kpi-label">ERP + AI Module Catalog</span>
            <span class="dev-kpi-val">{{ $stats['modules_total'] }}</span>
            @php $aiModCount = collect($moduleCatalog)->where('group_key', 'ai')->count(); @endphp
            <span class="dev-kpi-sub">{{ $stats['modules_total'] - $aiModCount }} ERP · {{ $aiModCount }} AI modules</span>
        </div>
        <div class="dev-kpi">
            <span class="dev-kpi-label">Tenant Seats Used</span>
            <span class="dev-kpi-val">{{ $stats['total_users'] }}</span>
            <span class="dev-kpi-sub">Active non-developer users</span>
        </div>
        <div class="dev-kpi">
            <span class="dev-kpi-label">Monthly Invoices</span>
            <span class="dev-kpi-val">{{ $stats['total_monthly_invoices'] }}</span>
            <span class="dev-kpi-sub">Across all active tenants</span>
        </div>
    </div>

    {{-- Top Navigation Tabs --}}
    <nav class="dev-tabs" aria-label="Developer Portal Sections">
        <a href="{{ route('developer.index', ['tab' => 'tenants', 'company' => $selectedTenant['id'] ?? null]) }}" class="dev-tab {{ $tab === 'tenants' ? 'is-active' : '' }}">
            <i class="dripicons-store" aria-hidden="true"></i> Tenants &amp; Licensing <span class="dev-tab-count">{{ $allTenants->count() }}</span>
        </a>
        <a href="{{ route('developer.index', ['tab' => 'plans', 'company' => $selectedTenant['id'] ?? null]) }}" class="dev-tab {{ $tab === 'plans' ? 'is-active' : '' }}">
            <i class="dripicons-tags" aria-hidden="true"></i> SaaS Plans <span class="dev-tab-count">{{ $plans->count() }}</span>
        </a>
        <a href="{{ route('developer.index', ['tab' => 'admins', 'company' => $selectedTenant['id'] ?? null]) }}" class="dev-tab {{ $tab === 'admins' ? 'is-active' : '' }}">
            <i class="dripicons-user-group" aria-hidden="true"></i> Admin Logins <span class="dev-tab-count">{{ $admins->count() }}</span>
        </a>
        <a href="{{ route('developer.index', ['tab' => 'modules', 'company' => $selectedTenant['id'] ?? null]) }}" class="dev-tab {{ $tab === 'modules' ? 'is-active' : '' }}">
            <i class="dripicons-view-thumb" aria-hidden="true"></i> Module Catalog <span class="dev-tab-count">{{ $stats['modules_total'] }}</span>
        </a>
        <a href="{{ route('developer.index', ['tab' => 'audits', 'company' => $selectedTenant['id'] ?? null]) }}" class="dev-tab {{ $tab === 'audits' ? 'is-active' : '' }}">
            <i class="dripicons-document" aria-hidden="true"></i> License Audit Log <span class="dev-tab-count">{{ $audits->count() }}</span>
        </a>
    </nav>

    {{-- TAB 1: TENANTS & MODULE LICENSING --}}
    @if($tab === 'tenants')
        <details class="dev-collapsible">
            <summary>+ Provision New Tenant Company (or Company Group)</summary>
            <div class="mt-2">
                <form method="POST" action="{{ route('developer.tenants.store') }}" class="dev-card mb-2">
                    @csrf
                    <h2><i class="dripicons-plus" aria-hidden="true"></i> Onboard Tenant Company</h2>
                    <div class="dev-form-grid">
                        <div class="dev-field">
                            <label>Legal Name *</label>
                            <input name="legal_name" class="form-control" required maxlength="255" placeholder="Acme Opticals Pvt Ltd">
                        </div>
                        <div class="dev-field">
                            <label>Trade / Display Name</label>
                            <input name="trade_name" class="form-control" maxlength="255" placeholder="Acme Opticals">
                        </div>
                        <div class="dev-field">
                            <label>Short Code</label>
                            <input name="code" class="form-control" maxlength="50" placeholder="Auto if blank">
                        </div>
                        <div class="dev-field">
                            <label>Company Group</label>
                            <select name="company_group_id" class="form-control">
                                <option value="">Independent Tenant</option>
                                @foreach($groups as $g)
                                    <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->code }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dev-field">
                            <label>Industry Vertical *</label>
                            <select name="industry" id="dev-new-industry" class="form-control" required>
                                @foreach($industries as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dev-field">
                            <label>Business Type</label>
                            <select name="subtype" id="dev-new-subtype" class="form-control"></select>
                        </div>
                        <div class="dev-field">
                            <label>SaaS Plan *</label>
                            <select name="saas_plan" class="form-control" required>
                                @foreach($plans->where('is_active', true) as $plan)
                                    <option value="{{ $plan->code }}" @selected($plan->code === 'starter')>{{ $plan->name }} ({{ $plan->currency_code }} {{ number_format((float) $plan->price, 0) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dev-field">
                            <label>Billing Cycle</label>
                            <select name="billing_cycle" class="form-control">
                                <option value="">From Plan</option>
                                @foreach(\App\Services\Platform\LicenseService::CYCLES as $cycle)
                                    <option value="{{ $cycle }}">{{ ucfirst($cycle) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dev-field">
                            <label>Expires On</label>
                            <input type="date" name="expires_at" class="form-control">
                        </div>
                        <div class="dev-field">
                            <label>Timezone *</label>
                            <input name="timezone" class="form-control" required value="{{ $defaults['timezone'] }}">
                        </div>
                        <div class="dev-field">
                            <label>FY Start *</label>
                            <input type="date" name="fy_start" class="form-control" required value="{{ $defaults['fy_start'] }}">
                        </div>
                        <div class="dev-field">
                            <label>FY End *</label>
                            <input type="date" name="fy_end" class="form-control" required value="{{ $defaults['fy_end'] }}">
                        </div>
                        <div class="dev-field">
                            <label>Initial Admin Name</label>
                            <input name="admin_name" class="form-control" placeholder="Optional admin user">
                        </div>
                        <div class="dev-field">
                            <label>Initial Admin Email</label>
                            <input type="email" name="admin_email" class="form-control" placeholder="admin@tenant.com">
                        </div>
                        <div class="dev-field">
                            <label>Initial Admin Password (8+)</label>
                            <input type="password" name="admin_password" class="form-control" minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="dripicons-checkmark"></i> Provision Tenant &amp; Issue License</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('developer.groups.store') }}" class="dev-card">
                    @csrf
                    <div class="dev-inline">
                        <strong>Create Parent Company Group:</strong>
                        <input name="name" class="form-control form-control-sm" placeholder="Group Name" required>
                        <input name="code" class="form-control form-control-sm" placeholder="Code (optional)">
                        <button type="submit" class="btn btn-secondary btn-sm">Add Group</button>
                    </div>
                </form>
            </div>
        </details>

        <div class="dev-split">
            {{-- Left Tenant Directory Rail --}}
            <aside class="dev-card">
                <div class="dev-card-head">
                    <h2><i class="dripicons-list" aria-hidden="true"></i> Tenants</h2>
                    <span class="dev-badge dev-badge-neutral">{{ $tenants->count() }}</span>
                </div>
                <form method="GET" action="{{ route('developer.index') }}" class="dev-filter-form">
                    <input type="hidden" name="tab" value="tenants">
                    <div class="dev-filter-row">
                        <input name="q" class="form-control" value="{{ $search }}" placeholder="Search name, code, key...">
                        <select name="status" class="form-control">
                            <option value="all" @selected($statusFilter === 'all')>All status</option>
                            @foreach(['active', 'trial', 'grace', 'suspended', 'expired', 'unlicensed_legacy'] as $st)
                                <option value="{{ $st }}" @selected($statusFilter === $st)>{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
                    </div>
                </form>
                <div class="dev-tenant-list">
                    @forelse($tenants as $t)
                        @php $isSel = $selectedTenant && (int) $selectedTenant['id'] === (int) $t['id']; @endphp
                        <a href="{{ route('developer.index', ['tab' => 'tenants', 'company' => $t['id'], 'q' => $search, 'status' => $statusFilter]) }}"
                           class="dev-tenant-item {{ $isSel ? 'is-selected' : '' }}">
                            <div class="dev-tenant-top">
                                <span class="dev-tenant-name">{{ $t['label'] }}</span>
                                <span class="dev-badge {{ $badgeClass($t['license']['status']) }}">{{ str_replace('_', ' ', $t['license']['status']) }}</span>
                            </div>
                            <div class="dev-tenant-meta">
                                <span>{{ $t['license']['plan']['name'] ?? 'Legacy/Custom' }}</span>
                                <span>·</span>
                                <span>{{ $t['licensed_count'] }}/{{ $t['total_modules'] }} modules</span>
                                <span>·</span>
                                <span>{{ $t['usage']['users'] }} users</span>
                            </div>
                            <div class="dev-tenant-meta">
                                <code>{{ $t['code'] }}</code>
                                @if($t['group_name'])<span>· {{ $t['group_name'] }}</span>@endif
                                @if($t['license']['expires_at'])<span>· Exp {{ $t['license']['expires_at'] }}</span>@endif
                            </div>
                        </a>
                    @empty
                        <p class="dev-muted">No matching tenant companies found.</p>
                    @endforelse
                </div>
            </aside>

            {{-- Right Selected Tenant Control Plane --}}
            @if($selectedTenant)
                @php
                    $selId = $selectedTenant['id'];
                    $lic = $selectedTenant['license'];
                    $usage = $selectedTenant['usage'];
                    $limits = $lic['limits'];
                @endphp
                <div class="dev-detail-stack">
                    {{-- Tenant Hero Header --}}
                    <div class="dev-hero">
                        <div>
                            <h2>{{ $selectedTenant['label'] }} <code>{{ $selectedTenant['code'] }}</code></h2>
                            <div class="dev-hero-sub">
                                {{ $selectedTenant['legal_name'] }} · {{ $selectedTenant['industry_label'] }}
                                @if($selectedTenant['group_name']) · Group: {{ $selectedTenant['group_name'] }}@endif
                                · Plan: <strong>{{ $lic['plan']['name'] ?? ($lic['has_license'] ? 'Custom' : 'Unconstrained Legacy') }}</strong>
                            </div>
                        </div>
                        <div class="dev-hero-actions">
                            <span class="dev-badge {{ $badgeClass($lic['status']) }}">{{ strtoupper(str_replace('_', ' ', $lic['status'])) }}</span>
                            <form method="POST" action="{{ route('developer.tenants.lifecycle', $selId) }}" class="dev-inline">
                                @csrf
                                <input type="hidden" name="action" value="renew">
                                <input type="hidden" name="months" value="1">
                                <button type="submit" class="btn btn-primary btn-sm">+1 Month</button>
                            </form>
                            <form method="POST" action="{{ route('developer.tenants.lifecycle', $selId) }}" class="dev-inline">
                                @csrf
                                <input type="hidden" name="action" value="renew">
                                <input type="hidden" name="months" value="12">
                                <button type="submit" class="btn btn-primary btn-sm">+1 Year</button>
                            </form>
                            @if($lic['is_blocked'])
                                <form method="POST" action="{{ route('developer.tenants.lifecycle', $selId) }}" class="dev-inline">
                                    @csrf
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="btn btn-success btn-sm">Activate</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('developer.tenants.lifecycle', $selId) }}" class="dev-inline">
                                    @csrf
                                    <input type="hidden" name="action" value="suspend">
                                    <button type="submit" class="btn btn-danger btn-sm">Suspend</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if($lic['banner_warning'])
                        <div class="alert alert-warning mb-0 py-2">{{ $lic['banner_warning'] }}</div>
                    @endif
                    @if($lic['is_blocked'] && $lic['block_reason'])
                        <div class="alert alert-danger mb-0 py-2">Blocked for non-developers: {{ $lic['block_reason'] }}</div>
                    @endif

                    {{-- License Contract & Plan Assignment --}}
                    <div class="dev-card">
                        <div class="dev-card-head">
                            <h2><i class="dripicons-lock" aria-hidden="true"></i> License Contract &amp; Plan Assignment</h2>
                            @if($lic['license_key'])
                                <div class="dev-inline">
                                    <span class="dev-key-box" id="dev-license-key">{{ $lic['license_key'] }}</span>
                                    <button type="button" class="btn btn-secondary btn-sm" id="dev-copy-key">Copy</button>
                                    <form method="POST" action="{{ route('developer.tenants.lifecycle', $selId) }}" class="dev-inline">
                                        @csrf
                                        <input type="hidden" name="action" value="regenerate_key">
                                        <button type="submit" class="btn btn-secondary btn-sm">Rotate Key</button>
                                    </form>
                                </div>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('developer.tenants.plan', $selId) }}">
                            @csrf
                            <div class="dev-form-grid">
                                <div class="dev-field">
                                    <label>Subscription Plan</label>
                                    <select name="saas_plan" class="form-control">
                                        <option value="custom" @selected(!$lic['plan'])>Custom (Manual Modules)</option>
                                        @foreach($plans as $plan)
                                            <option value="{{ $plan->code }}" @selected(($lic['plan']['id'] ?? null) === $plan->id)>{{ $plan->name }} ({{ $plan->currency_code }} {{ number_format((float) $plan->price, 0) }}/{{ $plan->billing_cycle }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="dev-field">
                                    <label>Status</label>
                                    <select name="status" class="form-control">
                                        @foreach(\App\Services\Platform\LicenseService::STATUSES as $st)
                                            <option value="{{ $st }}" @selected(($lic['raw_status'] ?? 'active') === $st)>{{ ucfirst($st) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="dev-field">
                                    <label>Billing Cycle</label>
                                    <select name="billing_cycle" class="form-control">
                                        @foreach(\App\Services\Platform\LicenseService::CYCLES as $cycle)
                                            <option value="{{ $cycle }}" @selected(($lic['billing_cycle'] ?? 'monthly') === $cycle)>{{ ucfirst($cycle) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="dev-field">
                                    <label>Starts At</label>
                                    <input type="date" name="starts_at" class="form-control" value="{{ $lic['starts_at'] ?? now()->toDateString() }}">
                                </div>
                                <div class="dev-field">
                                    <label>Expires At (blank = lifetime)</label>
                                    <input type="date" name="expires_at" class="form-control" value="{{ $lic['expires_at'] }}">
                                </div>
                                <div class="dev-field">
                                    <label>Grace Ends At</label>
                                    <input type="date" name="grace_ends_at" class="form-control" value="{{ $lic['grace_ends_at'] }}">
                                </div>
                            </div>

                            <div class="dev-quota-grid mt-2">
                                @foreach(['users' => 'Max Users', 'branches' => 'Max Branches', 'warehouses' => 'Max Warehouses', 'monthly_invoices' => 'Max Monthly Invoices'] as $qKey => $qLabel)
                                    @php $used = $usage[$qKey]; $max = $limits[$qKey]; $customMax = $lic['custom_limits'][$qKey] ?? null; @endphp
                                    <div class="dev-quota-box">
                                        <div class="dev-quota-top">
                                            <span>{{ $qLabel }}</span>
                                            <span>{{ $used }} / {{ $max ?? 'Unlimited' }}</span>
                                        </div>
                                        @if($max)
                                            <progress class="dev-meter" value="{{ min($used, $max) }}" max="{{ $max }}"></progress>
                                        @endif
                                        <input type="number" min="0" name="max_{{ $qKey }}" class="form-control form-control-sm" value="{{ $customMax }}" placeholder="Override (effective: {{ $max ?? 'Unlimited' }})">
                                    </div>
                                @endforeach
                            </div>

                            @php
                                $aiLimits = $lic['ai_limits'] ?? [];
                                $customAi = $lic['custom_ai_limits'] ?? [];
                                $aiQuotas = [
                                    ['field' => 'ai_monthly_credits', 'key' => 'monthly_credits', 'usage_key' => 'ai_credits_used', 'label' => 'AI Monthly Credits'],
                                    ['field' => 'ai_monthly_media_pages', 'key' => 'monthly_media_pages', 'usage_key' => 'ai_media_pages_used', 'label' => 'AI Media Pages / Mo'],
                                    ['field' => 'ai_max_concurrent_runs', 'key' => 'max_concurrent_runs', 'usage_key' => 'ai_concurrent_runs', 'label' => 'AI Concurrent Runs'],
                                    ['field' => 'ai_daily_token_limit', 'key' => 'daily_token_limit', 'usage_key' => 'ai_daily_tokens_used', 'label' => 'AI Daily Token Limit'],
                                ];
                            @endphp
                            <div class="dev-quota-grid mt-2">
                                @foreach($aiQuotas as $aq)
                                    @php
                                        $aiUsed = (int) ($usage[$aq['usage_key']] ?? 0);
                                        $aiEff = $aiLimits[$aq['key']] ?? null;
                                        $aiCust = $customAi[$aq['key']] ?? null;
                                    @endphp
                                    <div class="dev-quota-box">
                                        <div class="dev-quota-top">
                                            <span>{{ $aq['label'] }}</span>
                                            <span>{{ number_format($aiUsed) }} / {{ $aiEff === null ? 'Unlimited' : number_format((int) $aiEff) }}</span>
                                        </div>
                                        @if($aiEff !== null && $aiEff > 0)
                                            <progress class="dev-meter" value="{{ min($aiUsed, $aiEff) }}" max="{{ $aiEff }}"></progress>
                                        @endif
                                        <input type="number" min="0" name="{{ $aq['field'] }}" class="form-control form-control-sm" value="{{ $aiCust }}" placeholder="Blank = inherit ({{ $aiEff === null ? 'Unlimited' : $aiEff }})">
                                    </div>
                                @endforeach
                                <div class="dev-quota-box">
                                    <div class="dev-quota-top">
                                        <span>AI Execution Mode</span>
                                        <span>{{ ($aiLimits['read_only'] ?? true) ? 'Read-Only' : 'Read + Write' }}</span>
                                    </div>
                                    <select name="ai_read_only" class="form-control form-control-sm">
                                        @php $custRo = $customAi['read_only'] ?? null; @endphp
                                        <option value="" @selected($custRo === null)>Inherit from plan</option>
                                        <option value="1" @selected($custRo === true)>Force Read-Only AI</option>
                                        <option value="0" @selected($custRo === false)>Allow AI Write Actions</option>
                                    </select>
                                </div>
                            </div>

                            <div class="dev-inline mt-2">
                                <input name="notes" class="form-control form-control-sm" value="{{ $lic['notes'] }}" placeholder="Contract / renewal notes">
                                <label class="mb-0 dev-muted"><input type="checkbox" name="keep_overrides" value="1"> Keep custom module overrides</label>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="dripicons-checkmark"></i> Save Plan &amp; Quotas</button>
                            </div>
                        </form>
                    </div>

                    {{-- Module Licensing Matrix --}}
                    <form method="POST" action="{{ route('developer.tenants.modules', $selId) }}" class="dev-card" id="dev-modules-form">
                        @csrf
                        <div class="dev-card-head">
                            <div>
                                <h2><i class="dripicons-toggles" aria-hidden="true"></i> Module Licensing ({{ $selectedTenant['licensed_count'] }}/{{ $selectedTenant['total_modules'] }} licensed)</h2>
                                <p class="dev-muted">Controls both sidebar visibility and server-side capability/API access. Non-developer admins cannot activate unlicensed modules.</p>
                            </div>
                            <div class="dev-inline">
                                <button type="button" class="btn btn-secondary btn-sm" id="dev-modules-all">Select All {{ $stats['modules_total'] }}</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="dev-modules-core">Core Only</button>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="dripicons-checkmark"></i> Save Module Licenses</button>
                            </div>
                        </div>

                        <div class="dev-module-groups">
                            @foreach($moduleGroups as $groupKey => $items)
                                <div class="dev-module-group">
                                    <div class="dev-module-group-title">{{ $moduleGroupLabels[$groupKey] ?? ucfirst($groupKey) }} ({{ $items->count() }})</div>
                                    @foreach($items as $mod)
                                        @php
                                            $mState = $lic['modules'][$mod['key']] ?? ['licensed' => false, 'source' => 'unlicensed', 'expires_at' => null];
                                            $isCore = $mod['is_core'];
                                            $checked = !empty($mState['licensed']);
                                            $src = $mState['source'];
                                            $srcBadge = match ($src) {
                                                'core' => ['Core', 'dev-badge-neutral'],
                                                'plan' => ['Plan', 'dev-badge-primary'],
                                                'addon' => ['Add-on', 'dev-badge-active'],
                                                'revoked' => ['Revoked', 'dev-badge-blocked'],
                                                'addon_expired' => ['Expired', 'dev-badge-blocked'],
                                                'legacy' => ['Legacy', 'dev-badge-neutral'],
                                                default => ['Locked', 'dev-badge-neutral'],
                                            };
                                        @endphp
                                        <div class="dev-module-row">
                                            <label class="dev-module-check">
                                                <input type="checkbox"
                                                    name="modules[]"
                                                    value="{{ $mod['key'] }}"
                                                    class="dev-mod-cb"
                                                    data-key="{{ $mod['key'] }}"
                                                    data-deps="{{ implode(',', $mod['dependencies']) }}"
                                                    @checked($checked)
                                                    @disabled($isCore)>
                                                <span>
                                                    {{ $mod['name'] }}
                                                    <span class="dev-module-sub">
                                                        <code>{{ $mod['key'] }}</code>
                                                        @if($mod['dependencies']) · Req: {{ implode(', ', $mod['dependencies']) }}@endif
                                                    </span>
                                                </span>
                                            </label>
                                            <div class="dev-module-side">
                                                <span class="dev-badge {{ $srcBadge[1] }}">{{ $srcBadge[0] }}</span>
                                                @unless($isCore)
                                                    <input type="date"
                                                        name="expiries[{{ $mod['key'] }}]"
                                                        class="form-control dev-module-expiry"
                                                        value="{{ $mState['expires_at'] }}"
                                                        title="Optional add-on expiry date">
                                                @endunless
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </form>

                    {{-- Screen / Sidebar Menu Switches --}}
                    <form method="POST" action="{{ route('developer.tenants.switches', $selId) }}" class="dev-card">
                        @csrf
                        <div class="dev-card-head">
                            <div>
                                <h2><i class="dripicons-menu" aria-hidden="true"></i> Screen &amp; Menu Switches for {{ $selectedTenant['label'] }}</h2>
                                <p class="dev-muted">Fine-grained UI route blocker on top of licensed modules. Unticked screens hide from sidebar and return HTTP 403 for non-developers.</p>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="dripicons-checkmark"></i> Save Screen Switches</button>
                        </div>
                        <div class="dev-section-grid">
                            @foreach($catalog as $section)
                                @php $sectionOn = !in_array($section['key'], $off, true); @endphp
                                <fieldset class="dev-section" data-section>
                                    <legend><label><input type="checkbox" name="on[]" value="{{ $section['key'] }}" data-section-toggle @checked($sectionOn)> {{ $section['label'] }}</label></legend>
                                    @foreach($section['items'] as $item)
                                        <label class="dev-item"><input type="checkbox" name="on[]" value="{{ $item['key'] }}" @checked(!in_array($item['key'], $off, true)) @disabled(!$sectionOn)> {{ $item['label'] }}</label>
                                    @endforeach
                                </fieldset>
                            @endforeach
                        </div>
                    </form>
                </div>
            @endif
        </div>
    @endif

    {{-- TAB 2: SAAS PLANS --}}
    @if($tab === 'plans')
        <details class="dev-collapsible">
            <summary>+ Create Custom SaaS License Plan</summary>
            <form method="POST" action="{{ route('developer.plans.store') }}" class="dev-card mt-2">
                @csrf
                <div class="dev-form-grid">
                    <div class="dev-field"><label>Plan Name *</label><input name="name" class="form-control" required placeholder="Opticals Lab Pro"></div>
                    <div class="dev-field"><label>Plan Code</label><input name="code" class="form-control" placeholder="opticals_lab_pro"></div>
                    <div class="dev-field">
                        <label>Billing Cycle</label>
                        <select name="billing_cycle" class="form-control">
                            @foreach(\App\Services\Platform\LicenseService::CYCLES as $cycle)
                                <option value="{{ $cycle }}">{{ ucfirst($cycle) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="dev-field"><label>Price</label><input type="number" step="0.01" min="0" name="price" class="form-control" value="4999"></div>
                    <div class="dev-field"><label>Currency</label><input name="currency_code" class="form-control" value="INR" maxlength="10"></div>
                    <div class="dev-field"><label>Trial Days</label><input type="number" min="0" name="trial_days" class="form-control" value="14"></div>
                    <div class="dev-field"><label>Grace Days</label><input type="number" min="0" name="grace_days" class="form-control" value="7"></div>
                    <div class="dev-field"><label>Max Users (blank/0 = ∞)</label><input type="number" min="0" name="max_users" class="form-control"></div>
                    <div class="dev-field"><label>Max Branches</label><input type="number" min="0" name="max_branches" class="form-control"></div>
                    <div class="dev-field"><label>Max Warehouses</label><input type="number" min="0" name="max_warehouses" class="form-control"></div>
                    <div class="dev-field"><label>Max Monthly Invoices</label><input type="number" min="0" name="max_monthly_invoices" class="form-control"></div>
                    <div class="dev-field"><label>AI Credits/Mo (blank = ∞)</label><input type="number" min="0" name="ai_monthly_credits" class="form-control" value="5000"></div>
                    <div class="dev-field"><label>AI Media Pages/Mo</label><input type="number" min="0" name="ai_monthly_media_pages" class="form-control" value="25"></div>
                    <div class="dev-field"><label>AI Concurrent Runs</label><input type="number" min="0" name="ai_max_concurrent_runs" class="form-control" value="2"></div>
                    <div class="dev-field"><label>AI Daily Tokens (blank = ∞)</label><input type="number" min="0" name="ai_daily_token_limit" class="form-control" value="100000"></div>
                    <div class="dev-field">
                        <label>AI Write Mode</label>
                        <select name="ai_read_only" class="form-control">
                            <option value="1" selected>Read-Only AI (Default)</option>
                            <option value="0">Allow AI Write Actions</option>
                        </select>
                    </div>
                    <div class="dev-field"><label>Description</label><input name="description" class="form-control"></div>
                </div>
                <div class="dev-plan-checkgrid">
                    @foreach($moduleCatalog as $mod)
                        <label>
                            <input type="checkbox" name="modules[]" value="{{ $mod['key'] }}" @checked($mod['is_core']) @disabled($mod['is_core'])>
                            {{ $mod['name'] }}
                        </label>
                    @endforeach
                </div>
                <div><button type="submit" class="btn btn-primary btn-sm">Create SaaS Plan</button></div>
            </form>
        </details>

        <div class="dev-plans-grid">
            @foreach($plans as $plan)
                @php $subs = (int) ($planSubscriberCounts[$plan->id] ?? 0); @endphp
                <form method="POST" action="{{ route('developer.plans.update', $plan->id) }}" class="dev-card">
                    @csrf
                    <div class="dev-card-head">
                        <div>
                            <h2>{{ $plan->name }} <code>{{ $plan->code }}</code></h2>
                            <p class="dev-muted">{{ $subs }} active tenant(s) · {{ count($plan->modules_json ?? []) }}/{{ $stats['modules_total'] }} modules</p>
                        </div>
                        <span class="dev-badge {{ $plan->is_active ? 'dev-badge-active' : 'dev-badge-neutral' }}">{{ $plan->is_active ? 'Active' : 'Archived' }}</span>
                    </div>
                    <div class="dev-form-grid">
                        <div class="dev-field"><label>Name</label><input name="name" class="form-control" value="{{ $plan->name }}" required></div>
                        <div class="dev-field">
                            <label>Cycle</label>
                            <select name="billing_cycle" class="form-control">
                                @foreach(\App\Services\Platform\LicenseService::CYCLES as $cycle)
                                    <option value="{{ $cycle }}" @selected($plan->billing_cycle === $cycle)>{{ ucfirst($cycle) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dev-field"><label>Price ({{ $plan->currency_code }})</label><input type="number" step="0.01" min="0" name="price" class="form-control" value="{{ $plan->price }}"></div>
                        <div class="dev-field"><label>Grace Days</label><input type="number" min="0" name="grace_days" class="form-control" value="{{ $plan->grace_days }}"></div>
                        <div class="dev-field"><label>Max Users</label><input type="number" min="0" name="max_users" class="form-control" value="{{ $plan->max_users }}" placeholder="∞"></div>
                        <div class="dev-field"><label>Max Branches</label><input type="number" min="0" name="max_branches" class="form-control" value="{{ $plan->max_branches }}" placeholder="∞"></div>
                        <div class="dev-field"><label>Max Warehouses</label><input type="number" min="0" name="max_warehouses" class="form-control" value="{{ $plan->max_warehouses }}" placeholder="∞"></div>
                        <div class="dev-field"><label>Max Invoices/Mo</label><input type="number" min="0" name="max_monthly_invoices" class="form-control" value="{{ $plan->max_monthly_invoices }}" placeholder="∞"></div>
                        <div class="dev-field"><label>AI Credits/Mo</label><input type="number" min="0" name="ai_monthly_credits" class="form-control" value="{{ $plan->ai_monthly_credits }}" placeholder="∞"></div>
                        <div class="dev-field"><label>AI Media Pages/Mo</label><input type="number" min="0" name="ai_monthly_media_pages" class="form-control" value="{{ $plan->ai_monthly_media_pages }}" placeholder="∞"></div>
                        <div class="dev-field"><label>AI Concurrent Runs</label><input type="number" min="0" name="ai_max_concurrent_runs" class="form-control" value="{{ $plan->ai_max_concurrent_runs }}" placeholder="∞"></div>
                        <div class="dev-field"><label>AI Daily Tokens</label><input type="number" min="0" name="ai_daily_token_limit" class="form-control" value="{{ $plan->ai_daily_token_limit }}" placeholder="∞"></div>
                        <div class="dev-field">
                            <label>AI Write Mode</label>
                            <select name="ai_read_only" class="form-control">
                                <option value="1" @selected((bool) $plan->ai_read_only)>Read-Only AI</option>
                                <option value="0" @selected(!(bool) $plan->ai_read_only)>Read + Write</option>
                            </select>
                        </div>
                    </div>
                    <div class="dev-plan-checkgrid">
                        @foreach($moduleCatalog as $mod)
                            <label>
                                <input type="checkbox" name="modules[]" value="{{ $mod['key'] }}" @checked($mod['is_core'] || in_array($mod['key'], $plan->modules_json ?? [], true)) @disabled($mod['is_core'])>
                                {{ $mod['name'] }}
                            </label>
                        @endforeach
                    </div>
                    <div class="dev-inline">
                        <button type="submit" class="btn btn-primary btn-sm">Save Plan</button>
                    </div>
                </form>
            @endforeach
        </div>
    @endif

    {{-- TAB 3: ADMIN LOGINS --}}
    @if($tab === 'admins')
        <div class="dev-card">
            <h2><i class="dripicons-user-group" aria-hidden="true"></i> Tenant Admin Logins</h2>
            <form method="POST" action="{{ route('developer.admins.store') }}" class="dev-add-admin">
                @csrf
                <div class="dev-field"><label>Name *</label><input name="name" class="form-control" value="{{ old('name') }}" required maxlength="255"></div>
                <div class="dev-field"><label>Email (login) *</label><input name="email" type="email" class="form-control" value="{{ old('email') }}" required maxlength="255"></div>
                <div class="dev-field"><label>Password (8+) *</label><input name="password" type="password" class="form-control" required minlength="8" maxlength="100" autocomplete="new-password"></div>
                <div class="dev-company-ticks" role="group" aria-label="Companies">
                    @foreach($allTenants as $t)
                        <label><input type="checkbox" name="company_ids[]" value="{{ $t['id'] }}" @checked(in_array($t['id'], old('company_ids', [$selectedTenant['id'] ?? 0])))> {{ $t['label'] }}</label>
                    @endforeach
                </div>
                <button class="btn btn-primary btn-sm"><i class="dripicons-plus"></i> Add Admin</button>
            </form>
            <div class="dev-table-wrap">
                <table class="table dev-table">
                    <thead><tr><th>Admin</th><th>Assigned Tenants</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($admins as $admin)
                        @php $mine = $memberships[$admin->id] ?? []; @endphp
                        <tr>
                            <td><strong>{{ $admin->name }}</strong><br><span class="dev-muted">{{ $admin->email }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('developer.admins.companies', $admin->id) }}" class="dev-inline">
                                    @csrf
                                    <div class="dev-company-ticks">
                                        @foreach($allTenants as $t)
                                            <label><input type="checkbox" name="company_ids[]" value="{{ $t['id'] }}" @checked(in_array((int) $t['id'], $mine, true))> {{ $t['label'] }}</label>
                                        @endforeach
                                    </div>
                                    <button class="btn btn-secondary btn-sm">Save</button>
                                </form>
                            </td>
                            <td><span class="dev-badge {{ $admin->is_active ? 'dev-badge-active' : 'dev-badge-blocked' }}">{{ $admin->is_active ? 'Active' : 'Blocked' }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('developer.admins.password', $admin->id) }}" class="dev-inline mb-1">
                                    @csrf
                                    <input name="password" type="password" class="form-control form-control-sm" placeholder="New password" minlength="8" maxlength="100" required autocomplete="new-password">
                                    <button class="btn btn-secondary btn-sm">Set</button>
                                </form>
                                <form method="POST" action="{{ route('developer.admins.status', $admin->id) }}" class="dev-inline">
                                    @csrf
                                    <button class="btn btn-sm {{ $admin->is_active ? 'btn-danger' : 'btn-primary' }}">{{ $admin->is_active ? 'Block' : 'Activate' }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="dev-muted">No admin logins yet. Add one above.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- TAB 4: MODULE CATALOG REFERENCE --}}
    @if($tab === 'modules')
        <div class="dev-card">
            <div class="dev-card-head">
                <h2><i class="dripicons-view-thumb" aria-hidden="true"></i> Complete ERP Module Catalog ({{ count($moduleCatalog) }} Capabilities)</h2>
                <span class="dev-muted">Single-DB Multi-Company SaaS (`company_id` scoped) · Profiles are capability presets</span>
            </div>
            <div class="dev-table-wrap">
                <table class="table dev-table">
                    <thead><tr><th>Module Key</th><th>Name</th><th>Domain</th><th>Tier</th><th>Dependencies</th><th>Industry Presets</th></tr></thead>
                    <tbody>
                    @foreach($moduleCatalog as $mod)
                        <tr>
                            <td><code>{{ $mod['key'] }}</code></td>
                            <td><strong>{{ $mod['name'] }}</strong></td>
                            <td>{{ $mod['group_label'] }}</td>
                            <td>
                                @if($mod['is_core'])
                                    <span class="dev-badge dev-badge-active">Core (Included)</span>
                                @elseif($mod['is_switchable'])
                                    <span class="dev-badge dev-badge-trial">Licensable · Switchable</span>
                                @else
                                    <span class="dev-badge dev-badge-primary">Licensable Add-on</span>
                                @endif
                            </td>
                            <td>{{ $mod['dependencies'] ? implode(', ', $mod['dependencies']) : '—' }}</td>
                            <td>{{ $mod['profiles'] ? implode(', ', $mod['profiles']) : 'Manual / Plan' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- TAB 5: LICENSE AUDIT LOG --}}
    @if($tab === 'audits')
        <div class="dev-card">
            <h2><i class="dripicons-document" aria-hidden="true"></i> Licensing &amp; Tenant Lifecycle Audit Log</h2>
            <div class="dev-table-wrap">
                <table class="table dev-table">
                    <thead><tr><th>Timestamp</th><th>Tenant / Scope</th><th>Action</th><th>Summary</th><th>Actor</th></tr></thead>
                    <tbody>
                    @forelse($audits as $audit)
                        <tr>
                            <td>{{ $audit->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $audit->company_id ? ($companyNames[$audit->company_id] ?? '#'.$audit->company_id) : 'Platform Plan' }}</td>
                            <td><span class="dev-badge dev-badge-neutral">{{ $audit->action }}</span></td>
                            <td>{{ $audit->summary }}</td>
                            <td>{{ $audit->actor_id ? ($actorNames[$audit->actor_id] ?? '#'.$audit->actor_id) : 'System' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="dev-muted">No licensing audit events recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-section-toggle]').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            toggle.closest('[data-section]').querySelectorAll('.dev-item input').forEach(function (box) { box.disabled = !toggle.checked; });
        });
    });

    var copyBtn = document.getElementById('dev-copy-key');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var keyEl = document.getElementById('dev-license-key');
            if (keyEl && navigator.clipboard) {
                navigator.clipboard.writeText(keyEl.textContent.trim());
                copyBtn.textContent = 'Copied';
                setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
            }
        });
    }

    var modForm = document.getElementById('dev-modules-form');
    if (modForm) {
        var boxes = Array.prototype.slice.call(modForm.querySelectorAll('.dev-mod-cb'));
        var byKey = {};
        boxes.forEach(function (b) { byKey[b.dataset.key] = b; });

        function checkDeps(box) {
            if (!box.checked || !box.dataset.deps) return;
            box.dataset.deps.split(',').forEach(function (dep) {
                if (byKey[dep] && !byKey[dep].checked) {
                    byKey[dep].checked = true;
                    checkDeps(byKey[dep]);
                }
            });
        }
        boxes.forEach(function (box) {
            box.addEventListener('change', function () { checkDeps(box); });
        });

        var allBtn = document.getElementById('dev-modules-all');
        if (allBtn) allBtn.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = true; }); });
        var coreBtn = document.getElementById('dev-modules-core');
        if (coreBtn) coreBtn.addEventListener('click', function () { boxes.forEach(function (b) { if (!b.disabled) b.checked = false; }); });
    }

    var indSel = document.getElementById('dev-new-industry');
    var subSel = document.getElementById('dev-new-subtype');
    if (indSel && subSel) {
        var subtypes = @json($subtypes);
        var subtypeLabels = @json(\App\Services\Industry\IndustryCatalog::SUBTYPE_LABELS);
        function fillSubtypes() {
            subSel.innerHTML = '';
            Object.keys(subtypes[indSel.value] || {}).forEach(function (k) {
                var label = subtypeLabels[k] || k.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                subSel.appendChild(new Option(label, k));
            });
        }
        indSel.addEventListener('change', fillSubtypes);
        fillSubtypes();
    }
})();
</script>
@endpush
