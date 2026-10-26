@extends('backend.layout.main')
@section('title', 'Company profile')

@section('content')
<x-settings-command-bar active="company">
    <button type="submit" form="company-profile-form" class="btn btn-primary"><i class="dripicons-checkmark"></i> Save profile</button>
</x-settings-command-bar>

<section class="settings-workspace-view">
    <div class="cpf-wrap u-p-4px-6px u-maxw-100pct u-w-100pct u-flex-1-1-0 u-ovy-auto">
        <div class="cpf-head u-mb-6px">
            <p class="u-m-0-0-6px u-fs-12px u-c-text-muted">These details print on this company's invoices, bills and notes. Bank details and terms are under <a href="{{ route('settings.gst') }}#registration">GST &amp; Tax › Registration</a>.</p>
        </div>
        @if(session('message'))<div class="alert alert-success py-2">{{ session('message') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger py-2"><ul class="mb-0 pl-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <form method="POST" action="{{ route('settings.company.update') }}" enctype="multipart/form-data" id="company-profile-form">
        @csrf
        <div class="cpf-card">
            <h2>Business</h2>
            <div class="cpf-grid">
                <div><label for="cpf-trade">Display name (printed large)</label><input id="cpf-trade" name="trade_name" maxlength="191" value="{{ old('trade_name', $company->trade_name) }}"></div>
                <div><label for="cpf-legal">Legal name *</label><input id="cpf-legal" name="legal_name" maxlength="191" required value="{{ old('legal_name', $company->legal_name) }}"></div>
                <div class="cpf-span cpf-logo">
                    <div class="cpf-logo-box">@if($company->logo && is_file(public_path($company->logo)))<img src="{{ asset($company->logo) }}" alt="Current logo">@else No logo @endif</div>
                    <div><label for="cpf-logo">Logo (PNG or JPG, up to 1 MB)</label><input id="cpf-logo" type="file" name="logo" accept="image/png,image/jpeg">
                        <p class="cpf-muted">Printed at the top left of the A4 invoice. Bills already posted keep the logo they were posted with.</p></div>
                </div>
            </div>
        </div>

        <div class="cpf-card">
            <h2>Address &amp; contact</h2>
            <div class="cpf-grid">
                <div class="cpf-span"><label for="cpf-address">Address</label><textarea id="cpf-address" name="address" maxlength="1000" placeholder="Door no., street, area">{{ old('address', $company->address) }}</textarea></div>
                <div><label for="cpf-city">City</label><input id="cpf-city" name="city" maxlength="100" value="{{ old('city', $company->city) }}"></div>
                <div><label for="cpf-state">State</label><select id="cpf-state" name="state_code">
                    <option value="">— Select —</option>
                    @foreach($states as $code => $name)<option value="{{ $code }}" @selected((string) old('state_code', $company->state_code) === (string) $code)>{{ $name }} ({{ $code }})</option>@endforeach
                </select></div>
                <div><label for="cpf-pin">PIN code</label><input id="cpf-pin" name="postal_code" maxlength="6" inputmode="numeric" value="{{ old('postal_code', $company->postal_code) }}"></div>
                <div><label for="cpf-phone">Phone</label><input id="cpf-phone" name="phone" maxlength="100" value="{{ old('phone', $company->phone) }}"></div>
                <div><label for="cpf-email">Email</label><input id="cpf-email" type="email" name="email" maxlength="191" value="{{ old('email', $company->email) }}"></div>
                <div><label for="cpf-website">Website</label><input id="cpf-website" name="website" maxlength="191" value="{{ old('website', $company->website) }}"></div>
            </div>
        </div>

        <div class="cpf-card">
            <h2>Tax</h2>
            <div class="cpf-grid">
                <div><label for="cpf-gstin">GSTIN</label><input id="cpf-gstin" name="gstin" maxlength="15" value="{{ old('gstin', $registration->gstin ?? '') }}" class="u-tt-uppercase"></div>
                <div><label for="cpf-pan">PAN</label><input id="cpf-pan" name="pan" maxlength="10" value="{{ old('pan', $company->pan) }}" class="u-tt-uppercase"></div>
                <div><label for="cpf-regtype">GST type</label><select id="cpf-regtype" name="registration_type">
                    @foreach(['regular' => 'Regular', 'composition' => 'Composition', 'unregistered' => 'Unregistered (no GSTIN)'] as $value => $label)<option value="{{ $value }}" @selected(old('registration_type', $registration->registration_type ?? 'regular') === $value)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="cpf-gstfrom">Effective from</label><input id="cpf-gstfrom" type="date" name="gst_effective_from" value="{{ old('gst_effective_from') }}">
                    <p class="cpf-muted">Only for a new GSTIN or a type change; blank means today (first Unregistered save: start of the year).</p></div>
            </div>
            <p class="cpf-muted">With a GSTIN, the state comes from it, PAN defaults to characters 3–12, and the GST registration takes this legal name, display name and address, so bills always carry the company's name. Unregistered: leave GSTIN empty and choose the State; bills carry no GST.</p>
        </div>

        <div class="cpf-card">
            <h2>Currency</h2>
            <div class="cpf-grid">
                <div><label for="cpf-currency">Base currency</label><select id="cpf-currency" name="base_currency_id">
                    @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((int) old('base_currency_id', $company->base_currency_id) === (int) $currency->id)>{{ $currency->code }} · {{ $currency->name }}</option>@endforeach
                </select></div>
                <div class="u-self-end"><label class="cpf-check"><input type="checkbox" name="app_currency" value="1" @checked($appCurrencyId === (int) $company->base_currency_id)> Also the app's default currency (all screens and new companies)</label></div>
            </div>
            <p class="cpf-muted">Invoices print the amount in words (Rupees … Only) when the currency is INR. Add other currencies under Settings › Currency.</p>
        </div>

        @if($industry)
        <div class="cpf-card">
            <h2>Industry</h2>
            <div class="cpf-grid">
                <div><label for="cpf-industry">Industry</label><select id="cpf-industry" name="industry">
                    @foreach($industries as $key => $label)<option value="{{ $key }}" @selected($industry['profile'] === $key)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="cpf-subtype">Business type</label><select id="cpf-subtype" name="subtype" data-current="{{ $industry['subtype'] }}"></select></div>
            </div>
            <p class="cpf-muted">Changing the industry applies its preset (units, product attributes, entry aids). Existing products, stock and bills are not changed.</p>
        </div>
        @endif

        <div class="cpf-card">
            <h2>Features</h2>
            <input type="hidden" name="features_submitted" value="1">
            <div class="cpf-grid cpf-features">
                @foreach($features as $key => $feature)
                    <label class="cpf-check"><input type="checkbox" name="features[]" value="{{ $key }}" @checked($feature['enabled'])> {{ $feature['label'] }}</label>
                @endforeach
            </div>
            <p class="cpf-muted">Switched-off features leave the menu and their pages refuse access. Records already saved are kept. Changing the industry resets these to its preset.</p>
        </div>

        <div class="cpf-actions"><button type="submit" class="btn btn-primary">Save company profile</button></div>
    </form>
</div>
</section>
<script>
(function () {
    var subtypes = @json($subtypes), subtypeLabels = @json(\App\Services\Industry\IndustryCatalog::SUBTYPE_LABELS);
    var industry = document.getElementById('cpf-industry'), subtype = document.getElementById('cpf-subtype');
    if (!industry) return;
    function fill() {
        var current = subtype.dataset.current; subtype.innerHTML = '';
        Object.keys(subtypes[industry.value] || {}).forEach(function (key) {
            var option = new Option(subtypeLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }), key);
            option.selected = key === current; subtype.appendChild(option);
        });
    }
    industry.addEventListener('change', function () { subtype.dataset.current = ''; fill(); });
    fill();
})();
// Unregistered has no GSTIN.
(function () {
    var type = document.getElementById('cpf-regtype'), gstin = document.getElementById('cpf-gstin');
    function sync() { gstin.disabled = type.value === 'unregistered'; if (gstin.disabled) gstin.value = ''; }
    type.addEventListener('change', sync);
    sync();
})();
</script>
@endsection

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/company-profile.css') }}">
@endpush
