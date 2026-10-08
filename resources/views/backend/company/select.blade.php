@php
    $glyphs = [
        'fmcg' => '<path d="M4 8l8-4 8 4v8l-8 4-8-4z"/><path d="M4 8l8 4 8-4M12 12v8"/>',
        'textile' => '<path d="M7 4h10M7 20h10M8 4c0 4 8 4 8 8s-8 4-8 8"/><path d="M16 4c0 4-8 4-8 8s8 4 8 8"/>',
        'timber' => '<path d="M12 3l6 8h-3l4 6H5l4-6H6z"/><path d="M12 17v4"/>',
        'solar' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/>',
        'general_trading' => '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9h16v11H4zM9 20v-6h6v6"/>',
    ];
    $initials = fn ($name) => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $hasCompanies = $directory['count'] > 0;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose company · zoloERP</title>
    <link rel="stylesheet" href="{{ asset('css/zolo-erp-neo.css') }}">
    <link rel="stylesheet" href="{{ asset('css/company-picker.css') }}">
</head>
<body class="cp-body {{ $theme === 'dark' ? 'dark-mode' : '' }}">
<header class="cp-top">
    <span class="cp-brand"><span class="cp-brand-mark">z</span>zoloERP</span>
    <div class="cp-user">
        <span class="cp-avatar" aria-hidden="true">{{ $initials($user->name) }}</span>
        <span class="cp-user-name">{{ $user->name }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="cp-link" type="submit">Sign out</button></form>
    </div>
</header>

<main class="cp-main">
    <div class="cp-head">
        <div>
            <h1>Choose a company</h1>
            <p>Each company keeps its own books, stock and financial year. You can switch any time from the top bar.</p>
        </div>
        <div class="cp-actions">
            @if($directory['count'] > 3)
                <label class="cp-search"><span class="cp-sr">Search companies</span>
                    <input id="cp-search" type="search" placeholder="Search companies" autocomplete="off"><kbd>/</kbd></label>
            @endif
            @if($isAdmin)
                <button class="cp-btn" type="button" data-open="dlg-group">New group</button>
                <button class="cp-btn cp-btn-primary" type="button" data-open="dlg-company">New company</button>
            @endif
        </div>
    </div>

    @if(session('status'))<p class="cp-flash" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())
        <div class="cp-flash cp-flash-error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    @php
        $sections = collect($directory['groups'])->map(fn ($g) => $g + ['independent' => false]);
        if (count($directory['ungrouped'])) {
            $sections->push(['id' => null, 'code' => '', 'name' => 'Independent companies', 'manageable' => $isAdmin,
                'companies' => $directory['ungrouped'], 'independent' => true]);
        }
    @endphp

    @forelse($sections as $section)
        <section class="cp-group {{ $section['independent'] ? 'is-independent' : '' }}" aria-labelledby="grp-{{ $section['id'] ?? 'none' }}">
            <div class="cp-group-head">
                @unless($section['independent'])<span class="cp-group-badge" aria-hidden="true">{{ $initials($section['name']) }}</span>@endunless
                <div class="cp-group-title">
                    <h2 id="grp-{{ $section['id'] ?? 'none' }}">{{ $section['name'] }}</h2>
                    <span>{{ count($section['companies']) }} {{ \Illuminate\Support\Str::plural('company', count($section['companies'])) }}@if($section['code']) · <code>{{ $section['code'] }}</code>@endif</span>
                </div>
                @if($section['manageable'])
                    <button class="cp-link" type="button" data-open="dlg-company" data-group="{{ $section['id'] }}">+ Add company</button>
                @endif
            </div>
            <div class="cp-rail">
                @forelse($section['companies'] as $company)
                    <form method="post" action="{{ route('company.choose') }}" class="cp-card ind-{{ $company['industry'] }}"
                          data-search="{{ strtolower($company['name'].' '.$company['legal_name'].' '.$company['code'].' '.$section['name'].' '.($industries[$company['industry']] ?? '')) }}">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $company['id'] }}">
                        <div class="cp-card-top">
                            <span class="cp-glyph" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $glyphs[$company['industry']] ?? $glyphs['general_trading'] !!}</svg></span>
                            @if($company['id'] === $currentCompanyId)<span class="cp-current">Current</span>@endif
                        </div>
                        <h3>{{ $company['name'] }}</h3>
                        @if($company['legal_name'] !== $company['name'])<p class="cp-legal">{{ $company['legal_name'] }}</p>@endif
                        <p class="cp-meta">
                            <span class="cp-chip">{{ $industries[$company['industry']] ?? 'General Trading' }}@if($company['subtype']) · {{ ucwords(str_replace('_', ' ', $company['subtype'])) }}@endif</span>
                            <code>{{ $company['code'] }}</code>
                        </p>
                        <div class="cp-fields">
                            @if(count($company['branches']) > 1)
                                <label>Branch<select name="branch_id">@foreach($company['branches'] as $branch)<option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>@endforeach</select></label>
                            @elseif(count($company['branches']) === 1)
                                <input type="hidden" name="branch_id" value="{{ $company['branches'][0]['id'] }}">
                            @endif
                            @if(count($company['years']) > 1)
                                <label>Year<select name="financial_year_id">@foreach($company['years'] as $year)<option value="{{ $year['id'] }}" @selected($year['id'] === $company['current_year_id'])>{{ $year['name'] }}{{ $year['status'] !== 'open' ? ' · '.$year['status'] : '' }}</option>@endforeach</select></label>
                            @elseif(count($company['years']) === 1)
                                <span class="cp-fy">{{ $company['years'][0]['name'] }}</span>
                            @else
                                <span class="cp-fy cp-fy-missing">No financial year yet</span>
                            @endif
                        </div>
                        @if(count($company['branches']) === 0)
                            <p class="cp-note">You have no branch in this company. Ask its administrator for access.</p>
                        @else
                            <button class="cp-open" type="submit">Open {{ $company['name'] }} <span aria-hidden="true">→</span></button>
                        @endif
                    </form>
                @empty
                    <div class="cp-empty-card">
                        <p>No companies in this group yet.</p>
                        @if($section['manageable'])<button class="cp-btn cp-btn-primary" type="button" data-open="dlg-company" data-group="{{ $section['id'] }}">Add the first company</button>@endif
                    </div>
                @endforelse
            </div>
        </section>
    @empty
        <div class="cp-empty">
            <h2>You don't have access to any company yet</h2>
            @if($isAdmin)
                <p>Start with a group such as "MJ Group", then add each business inside it.</p>
                <button class="cp-btn cp-btn-primary" type="button" data-open="dlg-group">Create a group</button>
            @else
                <p>Ask your administrator to add you to a company, then sign in again.</p>
            @endif
        </div>
    @endforelse
    <p class="cp-nomatch" hidden>No company matches that search.</p>
</main>

@if($isAdmin)
<dialog id="dlg-group" class="cp-dialog" aria-labelledby="dlg-group-title">
    <form method="post" action="{{ route('company.groups.store') }}">
        @csrf
        <h2 id="dlg-group-title">New group</h2>
        <p class="cp-dialog-sub">A group holds related companies, for example MJ Group with its FMCG, timber and textile businesses. Each company keeps separate books.</p>
        <label>Group name<input type="text" name="name" required maxlength="255" placeholder="MJ Group" value="{{ old('name') }}"></label>
        <label><span>Short code <span class="cp-opt">optional</span></span><input type="text" name="code" maxlength="50" pattern="[A-Za-z0-9_-]+" placeholder="MJ"></label>
        @if($ownCompanies->isNotEmpty())
            <fieldset><legend>Move existing companies into this group</legend>
                @foreach($ownCompanies as $company)
                    <label class="cp-check"><input type="checkbox" name="company_ids[]" value="{{ $company->id }}"> {{ $company->legal_name }} <code>{{ $company->code }}</code></label>
                @endforeach
            </fieldset>
        @endif
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-primary">Create group</button></div>
    </form>
</dialog>

<dialog id="dlg-company" class="cp-dialog cp-dialog-wide" aria-labelledby="dlg-company-title">
    <form method="post" action="{{ route('company.companies.store') }}">
        @csrf
        <h2 id="dlg-company-title">New company</h2>
        <p class="cp-dialog-sub">Creates the company with a main branch, a main warehouse, a standard chart of accounts and its first financial year.</p>
        <div class="cp-grid">
            <label class="cp-span">Group<select name="company_group_id">
                @foreach($manageableGroups as $group)<option value="{{ $group['id'] }}">{{ $group['name'] }}</option>@endforeach
                <option value="">No group (independent)</option>
            </select></label>
            <label>Display name<input type="text" name="trade_name" maxlength="255" placeholder="Motumo FMCG" value="{{ old('trade_name') }}"></label>
            <label>Legal name<input type="text" name="legal_name" required maxlength="255" placeholder="Motumo Foods Private Limited" value="{{ old('legal_name') }}"></label>
            <label>Industry<select name="industry" id="cp-industry" required>
                @foreach($industries as $key => $label)<option value="{{ $key }}" @selected(old('industry') === $key)>{{ $label }}</option>@endforeach
            </select></label>
            <label>Business type<select name="subtype" id="cp-subtype"></select></label>
            <label><span>Short code <span class="cp-opt">optional</span></span><input type="text" name="code" maxlength="50" pattern="[A-Za-z0-9_-]+" placeholder="MOTUMO"></label>
            <label><span>State code <span class="cp-opt">GST</span></span><input type="text" name="state_code" maxlength="20" placeholder="33" value="{{ old('state_code') }}"></label>
            <label>Currency<select name="base_currency_id">
                @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected($currency->id == $defaultCurrencyId)>{{ $currency->code }} · {{ $currency->name }}</option>@endforeach
            </select></label>
            <label>Time zone<input type="text" name="timezone" required value="{{ old('timezone', $defaults['timezone']) }}"></label>
            <label>Financial year starts<input type="date" name="fy_start" required value="{{ old('fy_start', $defaults['fy_start']) }}"></label>
            <label>Financial year ends<input type="date" name="fy_end" required value="{{ old('fy_end', $defaults['fy_end']) }}"></label>
        </div>
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-primary">Create company</button></div>
    </form>
</dialog>
@endif

<script>
(function () {
    var subtypes = @json($subtypes);
    var industry = document.getElementById('cp-industry'), subtype = document.getElementById('cp-subtype');
    function fillSubtypes() {
        if (!industry) return;
        subtype.innerHTML = '';
        Object.keys(subtypes[industry.value] || {}).forEach(function (key) {
            var o = document.createElement('option');
            o.value = key; o.textContent = key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            subtype.appendChild(o);
        });
    }
    if (industry) { industry.addEventListener('change', fillSubtypes); fillSubtypes(); }

    document.querySelectorAll('[data-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dlg = document.getElementById(btn.dataset.open);
            if (btn.dataset.group) { var g = dlg.querySelector('[name=company_group_id]'); if (g) g.value = btn.dataset.group; }
            dlg.showModal();
            var first = dlg.querySelector('input:not([type=hidden]),select'); if (first) first.focus();
        });
    });
    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { btn.closest('dialog').close(); });
    });

    var search = document.getElementById('cp-search');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase(), any = false;
            document.querySelectorAll('.cp-group').forEach(function (group) {
                var visible = 0;
                group.querySelectorAll('.cp-card').forEach(function (card) {
                    var hit = !q || card.dataset.search.indexOf(q) !== -1;
                    card.hidden = !hit; if (hit) visible++;
                });
                group.hidden = q && !visible; if (visible) any = true;
            });
            document.querySelector('.cp-nomatch').hidden = !q || any;
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && !/INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName) && !document.querySelector('dialog[open]')) {
                e.preventDefault(); search.focus();
            }
        });
    }
    var first = document.querySelector('.cp-card .cp-open');
    if (first && !search) first.focus();
})();
</script>
</body>
</html>
