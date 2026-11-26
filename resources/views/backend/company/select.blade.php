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
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-utilities.css') }}">
    <link rel="stylesheet" href="{{ asset('css/zolo-erp-neo.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/company-picker.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/field-help.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-components.css') }}">
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
                <label class="cp-search" data-no-help><span class="cp-sr">Search companies</span>
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
                    @unless($section['independent'])
                        <button class="cp-link" type="button" data-edit-group="{{ json_encode(['id' => $section['id'], 'name' => $section['name'], 'code' => $section['code']]) }}">Edit group</button>
                        <button class="cp-link cp-link-danger" type="button" data-delete-group="{{ json_encode(['id' => $section['id'], 'name' => $section['name'], 'count' => count($section['companies'])]) }}">Delete group</button>
                    @endunless
                @endif
            </div>
            <div class="cp-rail">
                @forelse($section['companies'] as $company)
                    <form method="post" action="{{ route('company.choose') }}" class="cp-card ind-{{ $company['industry'] }}"
                          data-search="{{ strtolower($company['name'].' '.$company['legal_name'].' '.$company['code'].' '.$section['name'].' '.($industries[$company['industry']] ?? '')) }}">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $company['id'] }}">
                        @if(request('return') === 'workspace')<input type="hidden" name="return" value="workspace">@endif
                        <div class="cp-card-top">
                            <span class="cp-glyph" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $glyphs[$company['industry']] ?? $glyphs['general_trading'] !!}</svg></span>
                            <span class="cp-card-tools">
                                @if($company['id'] === $currentCompanyId)<span class="cp-current">Current</span>@endif
                                @if($company['manageable'])
                                    <button class="cp-icon-btn" type="button" title="Edit {{ $company['name'] }}" aria-label="Edit {{ $company['name'] }}"
                                            data-edit-company="{{ json_encode(['id' => $company['id'], 'name' => $company['name'], 'group_name' => $section['independent'] ? null : $section['name']] + $company['details']) }}">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>
                                    </button>
                                    @if($isAdmin)
                                        <button class="cp-icon-btn cp-icon-danger" type="button" title="Delete {{ $company['name'] }}" aria-label="Delete {{ $company['name'] }}"
                                                data-delete-company="{{ json_encode(['id' => $company['id'], 'name' => $company['name'], 'code' => $company['code']]) }}">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                                        </button>
                                    @endif
                                @endif
                            </span>
                        </div>
                        <h3>{{ $company['name'] }}</h3>
                        @if($company['legal_name'] !== $company['name'])<p class="cp-legal">{{ $company['legal_name'] }}</p>@endif
                        <p class="cp-meta">
                            <span class="cp-chip">{{ $industries[$company['industry']] ?? 'General Trading' }}@if($company['subtype']) · {{ \App\Services\Industry\IndustryCatalog::subtypeLabel($company['subtype']) }}@endif</span>
                            <code>{{ $company['code'] }}</code>
                            @if(!empty($company['license']) && ($company['license']['status'] ?? 'unlicensed_legacy') !== 'unlicensed_legacy')
                                <span class="cp-chip {{ !empty($company['license']['is_blocked']) ? 'cp-chip-blocked' : '' }}">{{ $company['license']['plan_name'] ?? 'Licensed' }} · {{ ucfirst($company['license']['status']) }}</span>
                            @endif
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

    @if($deletedCompanies->isNotEmpty())
        <details class="cp-deleted">
            <summary>Deleted companies ({{ $deletedCompanies->count() }})</summary>
            <p>Their books, stock and documents are kept. Restore one to open it again.</p>
            <ul>
                @foreach($deletedCompanies as $company)
                    <li>
                        <span>{{ $company->trade_name ?: $company->legal_name }} <code>{{ $company->code }}</code></span>
                        <form method="post" action="{{ route('company.companies.restore', $company->id) }}">@csrf<button class="cp-btn" type="submit">Restore</button></form>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</main>

<dialog id="dlg-company-edit" class="cp-dialog cp-dialog-wide" aria-labelledby="dlg-company-edit-title">
    <form method="post" action="" enctype="multipart/form-data">
        @csrf
        <div class="cp-dialog-header">
            <div class="cp-dialog-header-text">
                <h2 id="dlg-company-edit-title">Edit company</h2>
                <p class="cp-dialog-sub">Names, address, GSTIN and logo print on this company's bills. Industry and modules are changed inside the company under Business profile.</p>
            </div>
            <button type="button" class="cp-dialog-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="cp-dialog-columns">
            <div class="cp-col cp-col-identity">
                <span class="cp-col-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                    Business Identity
                </span>
                <label>Group<select name="company_group_id">
                    @foreach($manageableGroups as $group)<option value="{{ $group['id'] }}">{{ $group['name'] }}</option>@endforeach
                    <option value="">No group (independent)</option>
                </select></label>
                <label>Display name<input type="text" name="trade_name" maxlength="191"></label>
                <label>Legal name<input type="text" name="legal_name" required maxlength="191"></label>
                <div class="cp-row-2">
                    <label>Short code<input type="text" name="code" required maxlength="50" pattern="[A-Za-z0-9_-]+"></label>
                    <label><span>New logo <span class="cp-opt">PNG/JPG</span></span><input type="file" name="logo" accept="image/png,image/jpeg"></label>
                </div>
            </div>

            <div class="cp-col cp-col-address">
                <span class="cp-col-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    Address &amp; Contact
                </span>
                <label>Address<textarea name="address" maxlength="1000" placeholder="Door no., street, area"></textarea></label>
                <div class="cp-row-2">
                    <label>City<input type="text" name="city" maxlength="100"></label>
                    <label>State<select name="state_code">
                        <option value="">— Select —</option>
                        @foreach($states as $code => $name)<option value="{{ $code }}">{{ $name }} ({{ $code }})</option>@endforeach
                    </select></label>
                </div>
                <div class="cp-row-2">
                    <label>PIN code<input type="text" name="postal_code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"></label>
                    <label>Phone<input type="text" name="phone" maxlength="100"></label>
                </div>
                <label>Email<input type="email" name="email" maxlength="191"></label>
                <label>Website<input type="text" name="website" maxlength="191"></label>
            </div>

            <div class="cp-col cp-col-compliance">
                <span class="cp-col-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Tax &amp; Currency
                </span>
                <div class="cp-row-2">
                    <label><span>GSTIN <span class="cp-opt">turns on GST</span></span><input type="text" name="gstin" maxlength="15" class="u-tt-uppercase"></label>
                    <label><span>PAN <span class="cp-opt">from GSTIN</span></span><input type="text" name="pan" maxlength="10" class="u-tt-uppercase"></label>
                </div>
                <label><span>GST type <span class="text-danger font-weight-bold">*</span></span><select name="registration_type" required><option value="regular">Regular</option><option value="composition">Composition</option><option value="unregistered">Unregistered (no GSTIN)</option></select></label>
                <label>Currency<select name="base_currency_id">
                    @foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} · {{ $currency->name }}</option>@endforeach
                </select></label>
            </div>
        </div>
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-primary">Save changes</button></div>
    </form>
</dialog>

<dialog id="dlg-company-delete" class="cp-dialog" aria-labelledby="dlg-company-delete-title">
    <form method="post" action="">
        @csrf
        <div class="cp-dialog-header">
            <div class="cp-dialog-header-text">
                <h2 id="dlg-company-delete-title">Delete <span data-fill="name"></span>?</h2>
                <p class="cp-dialog-sub">The company disappears from the company list for everyone and can't be opened. Its books, stock and documents are <strong>kept</strong>, and an administrator can restore it from <em>Deleted companies</em>.</p>
            </div>
            <button type="button" class="cp-dialog-close" data-close aria-label="Close">&times;</button>
        </div>
        <label>Type the short code <code data-fill="code"></code> to confirm<input type="text" name="confirm_code" required autocomplete="off" class="u-tt-uppercase"></label>
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-danger">Delete company</button></div>
    </form>
</dialog>

<dialog id="dlg-group-edit" class="cp-dialog" aria-labelledby="dlg-group-edit-title">
    <form method="post" action="">
        @csrf
        <div class="cp-dialog-header">
            <div class="cp-dialog-header-text">
                <h2 id="dlg-group-edit-title">Edit group</h2>
            </div>
            <button type="button" class="cp-dialog-close" data-close aria-label="Close">&times;</button>
        </div>
        <label>Group name<input type="text" name="name" required maxlength="255"></label>
        <label>Short code<input type="text" name="code" required maxlength="50" pattern="[A-Za-z0-9_-]+"></label>
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-primary">Save changes</button></div>
    </form>
</dialog>

<dialog id="dlg-group-delete" class="cp-dialog" aria-labelledby="dlg-group-delete-title">
    <form method="post" action="">
        @csrf
        <div class="cp-dialog-header">
            <div class="cp-dialog-header-text">
                <h2 id="dlg-group-delete-title">Delete group <span data-fill="name"></span>?</h2>
                <p class="cp-dialog-sub">Only the group is removed. Its <span data-fill="count"></span> companies stay as they are and move to <em>Independent companies</em>; their books are not touched.</p>
            </div>
            <button type="button" class="cp-dialog-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-danger">Delete group</button></div>
    </form>
</dialog>

@if($isAdmin)
<dialog id="dlg-group" class="cp-dialog" aria-labelledby="dlg-group-title">
    <form method="post" action="{{ route('company.groups.store') }}">
        @csrf
        <div class="cp-dialog-header">
            <div class="cp-dialog-header-text">
                <h2 id="dlg-group-title">New group</h2>
                <p class="cp-dialog-sub">A group holds related companies, for example MJ Group with its FMCG, timber and textile businesses. Each company keeps separate books.</p>
            </div>
            <button type="button" class="cp-dialog-close" data-close aria-label="Close">&times;</button>
        </div>
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
    <form method="post" action="{{ route('company.companies.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="cp-dialog-header">
            <div class="cp-dialog-header-text">
                <h2 id="dlg-company-title">New company</h2>
                <p class="cp-dialog-sub">Creates the company with a main branch, a main warehouse, a standard chart of accounts and its first financial year. Its name, address, GSTIN and logo print on its bills; change them later in Settings › Company profile.</p>
            </div>
            <button type="button" class="cp-dialog-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="cp-dialog-columns">
            <div class="cp-col cp-col-identity">
                <span class="cp-col-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                    Business Identity
                </span>
                <label>Group<select name="company_group_id">
                    @foreach($manageableGroups as $group)<option value="{{ $group['id'] }}">{{ $group['name'] }}</option>@endforeach
                    <option value="">No group (independent)</option>
                </select></label>
                <label>Display name<input type="text" name="trade_name" maxlength="191" placeholder="Motumo FMCG" value="{{ old('trade_name') }}"></label>
                <label>Legal name<input type="text" name="legal_name" required maxlength="191" placeholder="Motumo Foods Private Limited" value="{{ old('legal_name') }}"></label>
                <div class="cp-row-2">
                    <label>Industry<select name="industry" id="cp-industry" required>
                        @foreach($industries as $key => $label)<option value="{{ $key }}" @selected(old('industry') === $key)>{{ $label }}</option>@endforeach
                    </select></label>
                    <label>Business type<select name="subtype" id="cp-subtype"></select></label>
                </div>
                <div class="cp-row-2">
                    <label><span>Short code <span class="cp-opt">optional</span></span><input type="text" name="code" maxlength="50" pattern="[A-Za-z0-9_-]+" placeholder="MOTUMO" value="{{ old('code') }}"></label>
                    <label><span>Logo <span class="cp-opt">PNG/JPG</span></span><input type="file" name="logo" accept="image/png,image/jpeg"></label>
                </div>
            </div>

            <div class="cp-col cp-col-address">
                <span class="cp-col-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    Address &amp; Contact
                </span>
                <label>Address<textarea name="address" maxlength="1000" placeholder="Door no., street, area">{{ old('address') }}</textarea></label>
                <div class="cp-row-2">
                    <label>City<input type="text" name="city" maxlength="100" value="{{ old('city') }}"></label>
                    <label>State<select name="state_code">
                        <option value="">— Select —</option>
                        @foreach($states as $code => $name)<option value="{{ $code }}" @selected((string) old('state_code') === (string) $code)>{{ $name }} ({{ $code }})</option>@endforeach
                    </select></label>
                </div>
                <div class="cp-row-2">
                    <label>PIN code<input type="text" name="postal_code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" value="{{ old('postal_code') }}"></label>
                    <label>Phone<input type="text" name="phone" maxlength="100" value="{{ old('phone') }}"></label>
                </div>
                <label>Email<input type="email" name="email" maxlength="191" value="{{ old('email') }}"></label>
                <label>Website<input type="text" name="website" maxlength="191" value="{{ old('website') }}"></label>
            </div>

            <div class="cp-col cp-col-compliance">
                <span class="cp-col-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Tax &amp; Compliance
                </span>
                <div class="cp-row-2">
                    <label><span>GSTIN <span class="cp-opt">turns on GST</span></span><input type="text" name="gstin" maxlength="15" value="{{ old('gstin') }}" class="u-tt-uppercase"></label>
                    <label><span>PAN <span class="cp-opt">from GSTIN</span></span><input type="text" name="pan" maxlength="10" value="{{ old('pan') }}" class="u-tt-uppercase"></label>
                </div>
                <div class="cp-row-2">
                    <label><span>GST type <span class="text-danger font-weight-bold">*</span></span><select name="registration_type" required><option value="" disabled @selected(!old('registration_type'))>— Select GST type —</option><option value="regular" @selected(old('registration_type') === 'regular')>Regular</option><option value="composition" @selected(old('registration_type') === 'composition')>Composition</option><option value="unregistered" @selected(old('registration_type') === 'unregistered')>Unregistered</option></select></label>
                    <label>Currency<select name="base_currency_id">
                        @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected($currency->id == old('base_currency_id', $defaultCurrencyId))>{{ $currency->code }} · {{ $currency->name }}</option>@endforeach
                    </select></label>
                </div>
                <span class="cp-col-title u-mt-4px">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    Books &amp; Financial Year
                </span>
                <label>Time zone<input type="text" name="timezone" required value="{{ old('timezone', $defaults['timezone']) }}"></label>
                <div class="cp-row-2">
                    <label>FY starts<input type="date" name="fy_start" required value="{{ old('fy_start', $defaults['fy_start']) }}"></label>
                    <label>FY ends<input type="date" name="fy_end" required value="{{ old('fy_end', $defaults['fy_end']) }}"></label>
                </div>
            </div>
        </div>
        <div class="cp-dialog-actions"><button type="button" class="cp-btn" data-close>Cancel</button><button class="cp-btn cp-btn-primary">Create company</button></div>
    </form>
</dialog>
@endif

<script>
(function () {
    var subtypes = @json($subtypes), subtypeLabels = @json(\App\Services\Industry\IndustryCatalog::SUBTYPE_LABELS);
    var industry = document.getElementById('cp-industry'), subtype = document.getElementById('cp-subtype');
    function fillSubtypes() {
        if (!industry) return;
        subtype.innerHTML = '';
        Object.keys(subtypes[industry.value] || {}).forEach(function (key) {
            var o = document.createElement('option');
            o.value = key; o.textContent = subtypeLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
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

    // Edit / delete dialogs: one dialog each, filled from the clicked card or group.
    var base = @json(url('company-picker'));
    function openWith(dialogId, action, data) {
        var dlg = document.getElementById(dialogId), form = dlg.querySelector('form');
        form.reset();
        form.action = action;
        Object.keys(data).forEach(function (key) {
            var field = form.elements[key];
            if (field && field.type !== 'file') field.value = data[key] === null ? '' : data[key];
        });
        dlg.querySelectorAll('[data-fill]').forEach(function (el) { el.textContent = data[el.dataset.fill]; });
        dlg.showModal();
        var first = form.querySelector('input:not([type=hidden]):not([type=file]),select'); if (first) first.focus();
        return form;
    }
    document.querySelectorAll('[data-edit-company]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var data = JSON.parse(btn.dataset.editCompany), groups = document.querySelector('#dlg-company-edit [name=company_group_id]');
            // A group the user doesn't administer can't be chosen, but it stays selected as the current one.
            groups.querySelectorAll('option[data-current]').forEach(function (o) { o.remove(); });
            if (data.company_group_id && !groups.querySelector('option[value="' + data.company_group_id + '"]')) {
                var o = new Option(data.group_name || 'Current group', data.company_group_id); o.dataset.current = '1'; groups.prepend(o);
            }
            openWith('dlg-company-edit', base + '/companies/' + data.id, data);
        });
    });
    document.querySelectorAll('[data-delete-company]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var data = JSON.parse(btn.dataset.deleteCompany);
            openWith('dlg-company-delete', base + '/companies/' + data.id + '/delete', data);
        });
    });
    document.querySelectorAll('[data-edit-group]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var data = JSON.parse(btn.dataset.editGroup);
            openWith('dlg-group-edit', base + '/groups/' + data.id, data);
        });
    });
    document.querySelectorAll('[data-delete-group]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var data = JSON.parse(btn.dataset.deleteGroup);
            openWith('dlg-group-delete', base + '/groups/' + data.id + '/delete', data);
        });
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
<script src="{{ versioned_asset('js/field-help.js') }}"></script>
</body>
</html>
