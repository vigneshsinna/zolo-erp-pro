@php
    $glyphs = [
        'fmcg' => '<path d="M4 8l8-4 8 4v8l-8 4-8-4z"/><path d="M4 8l8 4 8-4M12 12v8"/>',
        'textile' => '<path d="M7 4h10M7 20h10M8 4c0 4 8 4 8 8s-8 4-8 8"/><path d="M16 4c0 4-8 4-8 8s8 4 8 8"/>',
        'timber' => '<path d="M12 3l6 8h-3l4 6H5l4-6H6z"/><path d="M12 17v4"/>',
        'solar' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/>',
        'general_trading' => '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9h16v11H4zM9 20v-6h6v6"/>',
    ];
    $initials = fn ($name) => collect(preg_split('/\s+/', trim($name ?: 'New Company')))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Company Onboarding Portal · zoloERP</title>
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-utilities.css') }}">
    <link rel="stylesheet" href="{{ asset('css/zolo-erp-neo.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/company-portal.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('vendor/font-awesome/css/font-awesome.min.css') }}">
</head>
<body class="cp-portal-body {{ ($theme ?? 'light') === 'dark' ? 'dark-mode' : '' }}">

{{-- Topbar Navigation --}}
<header class="cp-portal-top">
    <div class="cp-portal-brand-block">
        <a href="{{ route('workspace') }}" class="cp-portal-brand">
            <span class="cp-portal-brand-mark">z</span>
            <span>zoloERP</span>
        </a>
        <span class="cp-portal-tag">ENTERPRISE PORTAL</span>
        <div class="cp-portal-crumb">
            <span>Workspace</span>
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span>Company Directory</span>
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="text-primary font-weight-bold">New Company Onboarding</span>
        </div>
    </div>
    <div class="cp-portal-top-actions">
        <a href="{{ $return === 'workspace' ? route('workspace') : route('company.select', ['manage' => 1]) }}" class="cp-portal-nav-btn">
            <i class="fa fa-arrow-left" aria-hidden="true"></i>
            <span>{{ $return === 'workspace' ? 'Back to Workspace' : 'Back to Directory' }}</span>
        </a>
    </div>
</header>

<main class="cp-portal-container">
    {{-- Executive Theme-Colored Hero Banner --}}
    <div class="cp-portal-hero">
        <div class="cp-portal-hero-vector" aria-hidden="true">
            <svg class="w-100 h-100" viewBox="0 0 500 130" fill="none" preserveAspectRatio="xMidYMid slice">
                <defs>
                    <linearGradient id="portalHeroGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#ffffff" stop-opacity="0.22" />
                        <stop offset="100%" stop-color="#ffffff" stop-opacity="0.02" />
                    </linearGradient>
                </defs>
                <g opacity="0.65" transform="translate(140, -15)">
                    <polygon points="200,25 245,45 245,95 200,75" fill="url(#portalHeroGrad)" stroke="rgba(255,255,255,0.3)" stroke-width="1.2" />
                    <polygon points="200,25 245,45 200,65 155,45" fill="rgba(255,255,255,0.14)" stroke="rgba(255,255,255,0.35)" stroke-width="1.2" />
                    <polygon points="155,45 200,65 200,115 155,95" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.22)" stroke-width="1.2" />
                    <polygon points="280,50 320,68 320,115 280,95" fill="url(#portalHeroGrad)" stroke="rgba(255,255,255,0.25)" stroke-width="1" />
                    <polygon points="280,50 320,68 280,86 240,68" fill="rgba(255,255,255,0.16)" stroke="rgba(255,255,255,0.3)" stroke-width="1" />
                    <path d="M 200,25 L 280,50 M 245,95 L 280,95 M 155,95 L 80,65" stroke="rgba(255,255,255,0.3)" stroke-width="1.5" stroke-dasharray="4,4" />
                    <circle cx="200" cy="25" r="3.5" fill="#ffffff" />
                    <circle cx="280" cy="50" r="3" fill="#a7f3d0" />
                    <circle cx="245" cy="95" r="3" fill="#ffffff" />
                </g>
                <path d="M0,85 C140,120 280,30 420,75 C500,100 560,60 600,70 L600,130 L0,130 Z" fill="rgba(255,255,255,0.05)" />
            </svg>
        </div>
        <div class="cp-portal-hero-left">
            <div class="cp-portal-hero-emblem" id="heroEmblem">
                <span id="heroEmblemText">NC</span>
                <span class="star">★</span>
            </div>
            <div class="cp-portal-hero-text">
                <h1>Enterprise Company Onboarding Portal</h1>
                <p>Provision an autonomous corporate operating entity with dedicated double-entry general ledgers, multi-branch warehouses, GST tax compliance, and industry profiles.</p>
                <div class="cp-portal-hero-pills">
                    <span class="cp-portal-hero-pill"><i class="fa fa-shield" aria-hidden="true"></i> Isolated Multi-Tenant Boundary</span>
                    <span class="cp-portal-hero-pill"><i class="fa fa-sitemap" aria-hidden="true"></i> Automated Facility &amp; Warehouse Setup</span>
                    <span class="cp-portal-hero-pill"><i class="fa fa-book" aria-hidden="true"></i> Standard Indian CoA Template</span>
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="alert alert-success d-flex align-items-center mb-4" role="status">
            <i class="fa fa-check-circle mr-2" aria-hidden="true"></i> {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger mb-4" role="alert">
            <ul class="mb-0 pl-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('company.companies.store') }}" enctype="multipart/form-data" id="companyOnboardingForm">
        @csrf
        <input type="hidden" name="return" value="{{ $return ?? 'workspace' }}">

        <div class="cp-portal-grid">
            {{-- Left Column: Form Suite --}}
            <div class="cp-portal-cards">
                {{-- 1. Business Identity & Corporate Hierarchy --}}
                <div class="cp-portal-card">
                    <div class="cp-portal-card-head">
                        <div class="cp-portal-card-icon"><i class="fa fa-building" aria-hidden="true"></i></div>
                        <div>
                            <h2 class="cp-portal-card-title">1. Business Identity &amp; Hierarchy</h2>
                            <p class="cp-portal-card-desc">Define corporate governance, holding groups, legal trade names, and industry vertical profiles.</p>
                        </div>
                    </div>

                    <div class="cp-field-group">
                        <label class="cp-field-label">
                            <span>Holding Group</span>
                            <span class="cp-field-hint">Optional corporate parent</span>
                        </label>
                        <select name="company_group_id" class="cp-portal-select" id="inputGroup">
                            <option value="">No parent group (Independent / Standalone entity)</option>
                            @foreach($manageableGroups as $group)
                                <option value="{{ $group['id'] }}" @selected(old('company_group_id', request('group_id')) == $group['id'])>{{ $group['name'] }} @if($group['code'])({{ $group['code'] }})@endif</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="cp-field-row-2">
                        <div class="cp-field-group">
                            <label class="cp-field-label">
                                <span>Legal Name <span class="cp-field-req">*</span></span>
                                <span class="cp-field-hint">Official registered name</span>
                            </label>
                            <input type="text" name="legal_name" id="inputLegalName" required maxlength="191" class="cp-portal-input" placeholder="e.g. Motumo Foods Private Limited" value="{{ old('legal_name') }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label">
                                <span>Display / Trade Name</span>
                                <span class="cp-field-hint">Used on desk &amp; POS</span>
                            </label>
                            <input type="text" name="trade_name" id="inputTradeName" maxlength="191" class="cp-portal-input" placeholder="e.g. Motumo FMCG" value="{{ old('trade_name') }}">
                        </div>
                    </div>

                    <div class="cp-field-row-2">
                        <div class="cp-field-group">
                            <label class="cp-field-label">
                                <span>Company Short Code <span class="cp-field-req">*</span></span>
                                <span class="cp-field-hint">2-8 characters for vouchers</span>
                            </label>
                            <input type="text" name="code" id="inputCode" required maxlength="50" pattern="[A-Za-z0-9_-]+" class="cp-portal-input u-uppercase" placeholder="e.g. MOTUMO" value="{{ old('code') }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label">
                                <span>Company Logo</span>
                                <span class="cp-field-hint">PNG or JPG (printed on bills)</span>
                            </label>
                            <input type="file" name="logo" id="inputLogo" accept="image/png,image/jpeg" class="cp-portal-input">
                        </div>
                    </div>

                    {{-- Industry Vertical Selector Grid --}}
                    <div class="cp-field-group">
                        <label class="cp-field-label">
                            <span>Industry Vertical Profile <span class="cp-field-req">*</span></span>
                            <span class="cp-field-hint">Applies custom industry masters and tax defaults</span>
                        </label>
                        <div class="cp-industry-grid">
                            @foreach($industries as $key => $label)
                                <label class="cp-industry-option">
                                    <input type="radio" name="industry" value="{{ $key }}" class="input-industry-radio" @checked(old('industry', 'fmcg') === $key)>
                                    <div class="cp-industry-box">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $glyphs[$key] ?? $glyphs['general_trading'] !!}</svg>
                                        <span class="cp-industry-name">{{ $label }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="cp-field-group">
                        <label class="cp-field-label">
                            <span>Business Subtype</span>
                            <span class="cp-field-hint">Specialized workflow specialization</span>
                        </label>
                        <select name="subtype" id="inputSubtype" class="cp-portal-select">
                            {{-- Dynamically populated via JS --}}
                        </select>
                    </div>
                </div>

                {{-- 2. Registered Headquarters & Contact --}}
                <div class="cp-portal-card">
                    <div class="cp-portal-card-head">
                        <div class="cp-portal-card-icon"><i class="fa fa-map-marker" aria-hidden="true"></i></div>
                        <div>
                            <h2 class="cp-portal-card-title">2. Registered Address &amp; Contact</h2>
                            <p class="cp-portal-card-desc">Official headquarters address printed on tax invoices, delivery challans and e-way bills.</p>
                        </div>
                    </div>

                    <div class="cp-field-group">
                        <label class="cp-field-label"><span>Street Address</span></label>
                        <textarea name="address" id="inputAddress" maxlength="1000" class="cp-portal-textarea" placeholder="Door no., building, street, commercial estate">{{ old('address') }}</textarea>
                    </div>

                    <div class="cp-field-row-3">
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>City</span></label>
                            <input type="text" name="city" id="inputCity" maxlength="100" class="cp-portal-input" placeholder="e.g. Chennai" value="{{ old('city') }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>State</span></label>
                            <select name="state_code" id="inputState" class="cp-portal-select">
                                <option value="">— Select Operating State —</option>
                                @foreach($states as $code => $name)
                                    <option value="{{ $code }}" @selected((string) old('state_code', '33') === (string) $code)>{{ $name }} ({{ $code }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>PIN Code</span></label>
                            <input type="text" name="postal_code" id="inputPostalCode" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" class="cp-portal-input" placeholder="600001" value="{{ old('postal_code') }}">
                        </div>
                    </div>

                    <div class="cp-field-row-3">
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>Official Phone</span></label>
                            <input type="text" name="phone" id="inputPhone" maxlength="100" class="cp-portal-input" placeholder="+91 98765 43210" value="{{ old('phone') }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>Official Email</span></label>
                            <input type="email" name="email" id="inputEmail" maxlength="191" class="cp-portal-input" placeholder="accounts@company.com" value="{{ old('email') }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>Website</span></label>
                            <input type="text" name="website" id="inputWebsite" maxlength="191" class="cp-portal-input" placeholder="https://www.company.com" value="{{ old('website') }}">
                        </div>
                    </div>
                </div>

                {{-- 3. Statutory Tax & Compliance (GSTIN & PAN) --}}
                <div class="cp-portal-card">
                    <div class="cp-portal-card-head">
                        <div class="cp-portal-card-icon"><i class="fa fa-file-text-o" aria-hidden="true"></i></div>
                        <div>
                            <h2 class="cp-portal-card-title">3. Statutory Tax &amp; GST Compliance</h2>
                            <p class="cp-portal-card-desc">Configure GSTIN, Permanent Account Number (PAN), and base accounting ledger currency.</p>
                        </div>
                    </div>

                    <div class="cp-field-row-2">
                        <div class="cp-field-group">
                            <label class="cp-field-label">
                                <span>GSTIN</span>
                                <span class="cp-field-hint">Auto-extracts PAN digits 3-12</span>
                            </label>
                            <input type="text" name="gstin" id="inputGstin" maxlength="15" class="cp-portal-input u-uppercase" placeholder="33AAAAA0000A1Z5" value="{{ old('gstin') }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label">
                                <span>PAN (Permanent Account Number)</span>
                                <span class="cp-field-hint">Income tax identifier</span>
                            </label>
                            <input type="text" name="pan" id="inputPan" maxlength="10" class="cp-portal-input u-uppercase" placeholder="AAAAA0000A" value="{{ old('pan') }}">
                        </div>
                    </div>

                    <div class="cp-field-group">
                        <label class="cp-field-label">
                            <span>GST Registration Type <span class="cp-field-req">*</span></span>
                            <span class="cp-field-hint">Determines invoicing &amp; tax calculation behavior</span>
                        </label>
                        <div class="cp-gst-types">
                            <label class="cp-gst-type-chip">
                                <input type="radio" name="registration_type" value="regular" @checked(old('registration_type', 'regular') === 'regular')>
                                <span class="cp-gst-chip-inner"><i class="fa fa-check" aria-hidden="true"></i> Regular (Tax Invoices with CGST/SGST/IGST)</span>
                            </label>
                            <label class="cp-gst-type-chip">
                                <input type="radio" name="registration_type" value="composition" @checked(old('registration_type') === 'composition')>
                                <span class="cp-gst-chip-inner"><i class="fa fa-percent" aria-hidden="true"></i> Composition Scheme (Bill of Supply)</span>
                            </label>
                            <label class="cp-gst-type-chip">
                                <input type="radio" name="registration_type" value="unregistered" @checked(old('registration_type') === 'unregistered')>
                                <span class="cp-gst-chip-inner"><i class="fa fa-ban" aria-hidden="true"></i> Unregistered / Non-GST Entity</span>
                            </label>
                        </div>
                    </div>

                    <div class="cp-field-group">
                        <label class="cp-field-label">
                            <span>Base Operating Currency</span>
                            <span class="cp-field-hint">Single source of truth for financial ledgers</span>
                        </label>
                        <select name="base_currency_id" class="cp-portal-select">
                            @foreach($currencies as $currency)
                                <option value="{{ $currency->id }}" @selected($currency->id == old('base_currency_id', $defaultCurrencyId))>{{ $currency->code }} · {{ $currency->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- 4. Operating Calendar, Timezone & Auto-Provisioning --}}
                <div class="cp-portal-card">
                    <div class="cp-portal-card-head">
                        <div class="cp-portal-card-icon"><i class="fa fa-calendar" aria-hidden="true"></i></div>
                        <div>
                            <h2 class="cp-portal-card-title">4. Financial Calendar &amp; Auto-Provisioning</h2>
                            <p class="cp-portal-card-desc">Set operating fiscal windows and review automated facility provisioning manifest.</p>
                        </div>
                    </div>

                    <div class="cp-field-row-3">
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>Timezone</span></label>
                            <input type="text" name="timezone" class="cp-portal-input" required value="{{ old('timezone', $defaults['timezone']) }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>First FY Start</span></label>
                            <input type="date" name="fy_start" id="inputFyStart" class="cp-portal-input" required value="{{ old('fy_start', $defaults['fy_start']) }}">
                        </div>
                        <div class="cp-field-group">
                            <label class="cp-field-label"><span>First FY End</span></label>
                            <input type="date" name="fy_end" id="inputFyEnd" class="cp-portal-input" required value="{{ old('fy_end', $defaults['fy_end']) }}">
                        </div>
                    </div>

                    <div class="cp-field-group mt-3">
                        <label class="cp-field-label"><span>Autonomous Infrastructure Provisioning Manifest:</span></label>
                        <div class="cp-manifest-grid">
                            <div class="cp-manifest-item"><i class="fa fa-check-circle cp-manifest-check" aria-hidden="true"></i> Primary Facility (Main Branch)</div>
                            <div class="cp-manifest-item"><i class="fa fa-check-circle cp-manifest-check" aria-hidden="true"></i> Central Fulfillment Warehouse (WH-1)</div>
                            <div class="cp-manifest-item"><i class="fa fa-check-circle cp-manifest-check" aria-hidden="true"></i> Standard Double-Entry CoA</div>
                            <div class="cp-manifest-item"><i class="fa fa-check-circle cp-manifest-check" aria-hidden="true"></i> Default Tax Ledgers (CGST/SGST/IGST)</div>
                        </div>
                    </div>
                </div>

                {{-- Action Bar --}}
                <div class="cp-portal-actions-bar">
                    <a href="{{ $return === 'workspace' ? route('workspace') : route('company.select', ['manage' => 1]) }}" class="cp-portal-nav-btn">Cancel &amp; Return</a>
                    <button type="submit" class="cp-portal-submit-btn" id="btnSubmitCompany">
                        <i class="fa fa-rocket" aria-hidden="true"></i>
                        <span>Provision &amp; Launch Enterprise Company</span>
                    </button>
                </div>
            </div>

            {{-- Right Column: Live Interactive Entity Passport Preview --}}
            <aside class="cp-passport-sticky">
                <div class="cp-passport-card">
                    <div class="cp-passport-header">
                        <div class="cp-passport-header-bg">
                            <svg width="120" height="120" viewBox="0 0 24 24" fill="currentColor"><path d="M4 8l8-4 8 4v8l-8 4-8-4z"/></svg>
                        </div>
                        <div class="cp-passport-emblem-row">
                            <div class="cp-passport-emblem" id="passportEmblem">
                                <span id="passportEmblemText">NC</span>
                                <span class="star">★</span>
                            </div>
                            <span class="cp-passport-badge-new">New Entity</span>
                        </div>
                        <h3 class="cp-passport-title" id="passportTitle">New Company</h3>
                        <p class="cp-passport-subtitle" id="passportSubtitle">Standalone Enterprise</p>
                    </div>

                    <div class="cp-passport-body">
                        <div class="cp-passport-meta-row">
                            <span class="cp-passport-meta-label">Short Code:</span>
                            <span class="cp-passport-meta-val"><code id="passportCode">NEWCO</code></span>
                        </div>
                        <div class="cp-passport-meta-row">
                            <span class="cp-passport-meta-label">Industry:</span>
                            <span class="cp-passport-meta-val" id="passportIndustry">FMCG &amp; Distribution</span>
                        </div>
                        <div class="cp-passport-meta-row">
                            <span class="cp-passport-meta-label">Headquarters:</span>
                            <span class="cp-passport-meta-val" id="passportLocation">Tamil Nadu, India</span>
                        </div>
                        <div class="cp-passport-meta-row">
                            <span class="cp-passport-meta-label">GSTIN:</span>
                            <span class="cp-passport-meta-val"><code id="passportGstin">Pending Setup</code></span>
                        </div>
                        <div class="cp-passport-meta-row">
                            <span class="cp-passport-meta-label">PAN:</span>
                            <span class="cp-passport-meta-val"><code id="passportPan">—</code></span>
                        </div>
                        <div class="cp-passport-meta-row">
                            <span class="cp-passport-meta-label">Initial FY:</span>
                            <span class="cp-passport-meta-val" id="passportFy">FY 2026-27</span>
                        </div>

                        {{-- Readiness Checklist --}}
                        <div class="cp-passport-checklist">
                            <div class="cp-check-step" id="checkIdentity">
                                <i class="fa fa-circle-o" aria-hidden="true"></i>
                                <span>Corporate Legal Identity</span>
                            </div>
                            <div class="cp-check-step" id="checkCode">
                                <i class="fa fa-circle-o" aria-hidden="true"></i>
                                <span>Unique Company Code</span>
                            </div>
                            <div class="cp-check-step" id="checkLocation">
                                <i class="fa fa-circle-o" aria-hidden="true"></i>
                                <span>Operating State &amp; Address</span>
                            </div>
                            <div class="cp-check-step" id="checkTax">
                                <i class="fa fa-circle-o" aria-hidden="true"></i>
                                <span>GST &amp; Statutory Configuration</span>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</main>

<script>
(function () {
    var subtypes = @json($subtypes);
    var subtypeLabels = @json(\App\Services\Industry\IndustryCatalog::SUBTYPE_LABELS);
    var stateNames = @json($states);

    var inputLegalName = document.getElementById('inputLegalName');
    var inputTradeName = document.getElementById('inputTradeName');
    var inputCode = document.getElementById('inputCode');
    var inputGroup = document.getElementById('inputGroup');
    var inputSubtype = document.getElementById('inputSubtype');
    var inputCity = document.getElementById('inputCity');
    var inputState = document.getElementById('inputState');
    var inputGstin = document.getElementById('inputGstin');
    var inputPan = document.getElementById('inputPan');
    var inputFyStart = document.getElementById('inputFyStart');

    // Passport preview targets
    var passportTitle = document.getElementById('passportTitle');
    var passportSubtitle = document.getElementById('passportSubtitle');
    var passportCode = document.getElementById('passportCode');
    var passportIndustry = document.getElementById('passportIndustry');
    var passportLocation = document.getElementById('passportLocation');
    var passportGstin = document.getElementById('passportGstin');
    var passportPan = document.getElementById('passportPan');
    var passportFy = document.getElementById('passportFy');
    var passportEmblemText = document.getElementById('passportEmblemText');
    var heroEmblemText = document.getElementById('heroEmblemText');

    // Check steps
    var checkIdentity = document.getElementById('checkIdentity');
    var checkCode = document.getElementById('checkCode');
    var checkLocation = document.getElementById('checkLocation');
    var checkTax = document.getElementById('checkTax');

    function getInitials(name) {
        if (!name || !name.trim()) return 'NC';
        var words = name.trim().split(/\s+/);
        if (words.length === 1) return words[0].substring(0, 2).toUpperCase();
        return (words[0].substring(0, 1) + words[1].substring(0, 1)).toUpperCase();
    }

    function updateLivePreview() {
        var name = (inputTradeName.value.trim() || inputLegalName.value.trim() || 'New Company');
        passportTitle.textContent = name;

        var inits = getInitials(name);
        passportEmblemText.textContent = inits;
        if (heroEmblemText) heroEmblemText.textContent = inits;

        // Group / Subtitle
        var groupOpt = inputGroup.options[inputGroup.selectedIndex];
        passportSubtitle.textContent = inputGroup.value ? groupOpt.text : 'Standalone Enterprise';

        // Code
        var code = inputCode.value.trim().toUpperCase() || 'NEWCO';
        passportCode.textContent = code;

        // Industry
        var checkedInd = document.querySelector('.input-industry-radio:checked');
        if (checkedInd) {
            var indLabel = checkedInd.closest('.cp-industry-option').querySelector('.cp-industry-name').textContent;
            passportIndustry.textContent = indLabel;
        }

        // Location
        var city = inputCity.value.trim();
        var stateCode = inputState.value;
        var stateName = stateNames[stateCode] || '';
        if (city && stateName) {
            passportLocation.textContent = city + ', ' + stateName;
        } else if (stateName) {
            passportLocation.textContent = stateName;
        } else if (city) {
            passportLocation.textContent = city;
        } else {
            passportLocation.textContent = 'India';
        }

        // Tax
        var gstin = inputGstin.value.trim().toUpperCase();
        passportGstin.textContent = gstin || 'Pending Setup';
        passportPan.textContent = inputPan.value.trim().toUpperCase() || '—';

        // FY
        if (inputFyStart && inputFyStart.value) {
            var y = new Date(inputFyStart.value).getFullYear();
            passportFy.textContent = 'FY ' + y + '-' + String(y + 1).slice(-2);
        }

        // Update Checklist state
        updateChecklist();
    }

    function updateChecklist() {
        var hasLegalName = inputLegalName.value.trim().length > 2;
        setCheck(checkIdentity, hasLegalName);

        var hasCode = inputCode.value.trim().length >= 2;
        setCheck(checkCode, hasCode);

        var hasLocation = inputState.value !== '';
        setCheck(checkLocation, hasLocation);

        var hasTax = inputGstin.value.trim().length === 15 || document.querySelector('input[name="registration_type"]:checked');
        setCheck(checkTax, hasTax);
    }

    function setCheck(el, done) {
        if (!el) return;
        if (done) {
            el.classList.add('is-done');
            el.querySelector('i').className = 'fa fa-check-circle text-success';
        } else {
            el.classList.remove('is-done');
            el.querySelector('i').className = 'fa fa-circle-o';
        }
    }

    function repopulateSubtypes() {
        var checkedInd = document.querySelector('.input-industry-radio:checked');
        if (!checkedInd || !inputSubtype) return;
        var indKey = checkedInd.value;
        inputSubtype.innerHTML = '<option value="">Standard ' + checkedInd.closest('.cp-industry-option').querySelector('.cp-industry-name').textContent + ' Workflow</option>';
        var list = subtypes[indKey] || {};
        Object.keys(list).forEach(function (k) {
            var opt = document.createElement('option');
            opt.value = k;
            opt.textContent = subtypeLabels[k] || k.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            inputSubtype.appendChild(opt);
        });
    }

    // Auto-fill trade name and code from legal name if empty
    inputLegalName.addEventListener('input', function () {
        if (!inputTradeName.value.trim()) {
            inputTradeName.placeholder = inputLegalName.value.replace(/private limited|pvt ltd|ltd|limited/gi, '').trim();
        }
        if (!inputCode.value.trim()) {
            var words = inputLegalName.value.trim().split(/\s+/);
            var autoCode = words.map(function(w) { return w[0]; }).join('').toUpperCase();
            if (autoCode.length >= 2) inputCode.value = autoCode.slice(0, 8);
        }
        updateLivePreview();
    });

    inputTradeName.addEventListener('input', updateLivePreview);
    inputCode.addEventListener('input', updateLivePreview);
    inputGroup.addEventListener('change', updateLivePreview);
    inputCity.addEventListener('input', updateLivePreview);
    inputState.addEventListener('change', updateLivePreview);

    // GSTIN smart parsing: extract state code and PAN
    inputGstin.addEventListener('input', function () {
        var g = inputGstin.value.trim().toUpperCase();
        inputGstin.value = g;
        if (g.length >= 2) {
            var st = g.substring(0, 2);
            if (stateNames[st] && inputState.value !== st) {
                inputState.value = st;
            }
        }
        if (g.length >= 12) {
            var pan = g.substring(2, 12);
            if (/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(pan) || !inputPan.value.trim()) {
                inputPan.value = pan;
            }
        }
        updateLivePreview();
    });

    inputPan.addEventListener('input', function () {
        inputPan.value = inputPan.value.trim().toUpperCase();
        updateLivePreview();
    });

    document.querySelectorAll('.input-industry-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            repopulateSubtypes();
            updateLivePreview();
        });
    });

    // Initialize
    repopulateSubtypes();
    updateLivePreview();
})();
</script>
</body>
</html>
