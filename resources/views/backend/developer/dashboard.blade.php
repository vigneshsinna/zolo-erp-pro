{{-- Executive Licensing Dashboard (docs/DEVELOPER_LICENSING_PORTAL_ARCHITECTURE.md Section 3) --}}
<div class="comm-command-bar d-print-none mb-3">
    <div class="comm-title-group">
        <h1 class="comm-title d-flex align-items-center gap-2">
            <i class="dripicons-meter text-primary" aria-hidden="true"></i>
            <span>Developer Licensing &amp; Control Plane</span>
        </h1>
        <span class="comm-title-badge">Multi-Tenant SaaS · Groups, Independent Companies, Licenses &amp; Modules</span>
    </div>
    <div class="comm-actions">
        <a href="{{ route('developer.index', ['tab' => 'tenants']) }}#new-tenant" class="btn btn-primary btn-sm">
            <i class="dripicons-plus"></i> New Subscriber
        </a>
        <a href="{{ route('developer.index', ['tab' => 'plans']) }}" class="btn btn-secondary btn-sm">
            <i class="dripicons-tags"></i> SaaS Plans
        </a>
        <a href="{{ route('developer.index', ['tab' => 'audits']) }}" class="btn btn-secondary btn-sm">
            <i class="dripicons-document"></i> Audit Log
        </a>
    </div>
</div>

{{-- 6-Card Canonical KPI Strip (AGENTS.md §4.4) --}}
<div class="kpi-summary-strip d-print-none mb-3">
    <div class="kpi-card">
        <div>
            <div class="kpi-label">TOTAL SUBSCRIBERS</div>
            <div class="kpi-number">{{ $stats['tenants_total'] }}</div>
            <div class="kpi-subtext">{{ $stats['groups_total'] }} Groups · {{ $stats['independent_total'] }} Independent Companies</div>
        </div>
        <div class="z-icon-tile z-tone-primary">
            <i class="dripicons-store u-fs-18px"></i>
        </div>
    </div>
    <div class="kpi-card">
        <div>
            <div class="kpi-label">ACTIVE LICENSES</div>
            <div class="kpi-number">{{ $stats['tenants_active'] }} / {{ $stats['tenants_total'] }}</div>
            <div class="kpi-subtext">{{ $stats['tenants_trial_grace'] }} Trial/Grace · {{ $stats['tenants_blocked'] }} Blocked</div>
        </div>
        <div class="z-icon-tile z-tone-success">
            <i class="dripicons-checkmark u-fs-18px"></i>
        </div>
    </div>
    <div class="kpi-card">
        <div>
            <div class="kpi-label">CONTRACT MRR / ARR</div>
            <div class="kpi-number">₹{{ number_format($stats['mrr'] ?? 0, 0) }}</div>
            <div class="kpi-subtext">ARR: ₹{{ number_format($stats['arr'] ?? 0, 0) }} (annualized)</div>
        </div>
        <div class="z-icon-tile z-tone-info">
            <i class="dripicons-wallet u-fs-18px"></i>
        </div>
    </div>
    <div class="kpi-card">
        <div>
            <div class="kpi-label">SEAT &amp; BRANCH LOAD</div>
            <div class="kpi-number">{{ $stats['total_users'] }} Seats</div>
            <div class="kpi-subtext">{{ $allTenants->sum('usage.branches') }} Active Locations / Branches</div>
        </div>
        <div class="z-icon-tile z-tone-primary">
            <i class="dripicons-user-group u-fs-18px"></i>
        </div>
    </div>
    <div class="kpi-card">
        <div>
            <div class="kpi-label">RENEWAL &amp; RISK ALERTS</div>
            <div class="kpi-number">{{ $stats['renewal_alerts_count'] }}</div>
            <div class="kpi-subtext">Expiring in 15d · In Grace / Suspended</div>
        </div>
        <div class="z-icon-tile z-tone-warning">
            <i class="dripicons-warning u-fs-18px"></i>
        </div>
    </div>
    <div class="kpi-card">
        <div>
            <div class="kpi-label">MONTHLY OPS &amp; CATALOG</div>
            <div class="kpi-number">{{ number_format($stats['total_monthly_invoices']) }}</div>
            <div class="kpi-subtext">{{ $stats['modules_total'] }} Modules (37 ERP + 8 AI)</div>
        </div>
        <div class="z-icon-tile z-tone-neutral">
            <i class="dripicons-graph-bar u-fs-18px"></i>
        </div>
    </div>
</div>

{{-- 4 Actionable Operational Panels in .desk-card-wrapper --}}
<div class="row">
    {{-- Panel 1: Renewal, Trial & Grace Watchlist --}}
    <div class="col-lg-6 mb-3">
        <div class="desk-card-wrapper h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="m-0 font-weight-bold d-flex align-items-center gap-2">
                    <i class="dripicons-warning text-warning" aria-hidden="true"></i>
                    <span>Renewal, Trial &amp; Grace Watchlist</span>
                </h5>
                <span class="badge badge-warning">{{ $renewalWatchlist->count() }} requiring action</span>
            </div>
            <p class="text-muted small mb-3">Subscribers expiring within 15 days, in grace period, or needing contract renewal.</p>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Subscriber / Company</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Validity / Window</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($renewalWatchlist->take(6) as $item)
                            <tr>
                                <td>
                                    <strong>{{ $item['label'] }}</strong>
                                    @if(!empty($item['code']))<span class="text-muted small">({{ $item['code'] }})</span>@endif
                                </td>
                                <td><span class="badge badge-light">{{ $item['plan_name'] }}</span></td>
                                <td>
                                    <span class="badge {{ $item['status'] === 'active' ? 'badge-success' : ($item['status'] === 'grace' ? 'badge-warning' : ($item['status'] === 'trial' ? 'badge-info' : 'badge-danger')) }}">
                                        {{ strtoupper($item['status']) }}
                                    </span>
                                </td>
                                <td class="small">
                                    @if($item['expires_at'])
                                        <div>Exp: {{ $item['expires_at'] }}</div>
                                    @endif
                                    @if($item['grace_ends_at'])
                                        <div class="text-warning">Grace: {{ $item['grace_ends_at'] }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('developer.index', ['tab' => 'tenants', 'company' => $item['id']]) }}" class="btn btn-sm btn-outline-primary py-0 px-2">Manage</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">All subscriber contracts are healthy. Zero licenses expiring in the next 15 days.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Panel 2: Quota Saturation Hotlist (>=80%) --}}
    <div class="col-lg-6 mb-3">
        <div class="desk-card-wrapper h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="m-0 font-weight-bold d-flex align-items-center gap-2">
                    <i class="dripicons-graph-bar text-primary" aria-hidden="true"></i>
                    <span>Quota Saturation Hotlist (&ge;80% Limit)</span>
                </h5>
                <span class="badge badge-primary">{{ $quotaHotlist->count() }} tenants hot</span>
            </div>
            <p class="text-muted small mb-3">Tenants nearing user seat limits, branch caps, warehouse caps, or invoice volume quotas.</p>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Subscriber</th>
                            <th>Resource</th>
                            <th>Usage / Limit</th>
                            <th>Utilization</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($quotaHotlist->take(6) as $item)
                            @foreach($item['metrics'] as $metric)
                                <tr>
                                    <td><strong>{{ $item['label'] }}</strong></td>
                                    <td>{{ $metric['name'] }}</td>
                                    <td>{{ $metric['used'] }} / {{ $metric['limit'] }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px; min-width: 60px;">
                                                <div class="progress-bar {{ $metric['pct'] >= 95 ? 'bg-danger' : 'bg-warning' }}" role="progressbar" style="width: {{ min(100, $metric['pct']) }}%" aria-valuenow="{{ $metric['pct'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <span class="small font-weight-bold">{{ $metric['pct'] }}%</span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('developer.index', ['tab' => 'tenants', 'company' => $item['id']]) }}#quotas" class="btn btn-sm btn-outline-secondary py-0 px-2">Adjust</a>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">All tenants operating within comfortable resource headroom. Zero quota pressure warnings.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    {{-- Panel 3: Vertical & Plan Distribution Summary --}}
    <div class="col-lg-6 mb-3">
        <div class="desk-card-wrapper h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="m-0 font-weight-bold d-flex align-items-center gap-2">
                    <i class="dripicons-view-thumb text-info" aria-hidden="true"></i>
                    <span>Vertical &amp; Plan Distribution Summary</span>
                </h5>
                <span class="badge badge-info">{{ $verticalDistribution->count() }} Verticals</span>
            </div>
            <p class="text-muted small mb-3">Enterprise Groups and Independent Companies categorized across Industry Profiles and SaaS Plans.</p>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Industry Vertical</th>
                            <th>Subscribers</th>
                            <th>Branches</th>
                            <th>User Seats</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($verticalDistribution as $v)
                            <tr>
                                <td>
                                    <strong>{{ ucwords(str_replace('_', ' ', $v['vertical'])) }}</strong>
                                </td>
                                <td><span class="badge badge-light">{{ $v['count'] }} companies</span></td>
                                <td>{{ $v['branches'] }} locations</td>
                                <td>{{ $v['users'] }} seats</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 pt-2 border-top">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="small text-muted font-weight-bold mr-1">Active Plans:</span>
                    @foreach($plans as $p)
                        <span class="badge badge-secondary py-1 px-2">
                            {{ $p->name }}: {{ $planSubscriberCounts->get($p->id, 0) }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Panel 4: Pending Activations & Latest Audit Events --}}
    <div class="col-lg-6 mb-3">
        <div class="desk-card-wrapper h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="m-0 font-weight-bold d-flex align-items-center gap-2">
                    <i class="dripicons-document text-primary" aria-hidden="true"></i>
                    <span>Pending Activations &amp; Audit Stream</span>
                </h5>
                <a href="{{ route('developer.index', ['tab' => 'audits']) }}" class="btn btn-sm btn-link p-0 text-decoration-none">View All Audits &rarr;</a>
            </div>
            <p class="text-muted small mb-3">Recent security events, license modifications, and self-registration activation queue.</p>

            @if($pendingUsers->isNotEmpty())
                <div class="alert alert-warning py-2 px-3 small mb-3 d-flex align-items-center justify-content-between">
                    <div>
                        <i class="dripicons-user-id mr-1"></i>
                        <strong>{{ $stats['pending_activations_count'] }} user(s)</strong> awaiting activation.
                    </div>
                    <a href="{{ route('developer.index', ['tab' => 'admins', 'filter' => 'pending']) }}" class="btn btn-sm btn-warning py-0 px-2 font-weight-bold">Review Queue</a>
                </div>
            @endif

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($audits->take(6) as $a)
                            <tr>
                                <td class="small text-muted" style="white-space: nowrap;">
                                    {{ $a->created_at ? $a->created_at->format('M d, H:i') : '—' }}
                                </td>
                                <td class="small">
                                    {{ $actorNames->get($a->actor_id, 'System') }}
                                </td>
                                <td>
                                    <span class="badge badge-light font-weight-bold">{{ $a->action }}</span>
                                </td>
                                <td class="small text-truncate" style="max-width: 200px;" title="{{ $a->summary }}">
                                    {{ $a->summary ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">Audit ledger is initialized and clean.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
