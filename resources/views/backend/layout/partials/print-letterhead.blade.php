{{-- Letterhead for printed reports and lists. Hidden on screen; erp-print.js fills the title, filters and time
     and copies it onto every report it prints, and @media print shows it when a page prints itself. --}}
@php
    $printName = $general_setting->company_name ?? $general_setting->site_title ?? config('app.name');
    $printLegal = null; $printAddress = null; $printGstin = null; $printContact = []; $printLogo = null;
    if ($activeCompany) {
        $registration = \Illuminate\Support\Facades\DB::table('tax_registrations')->where('company_id', $activeCompany->id)
            ->where('status', 'active')->whereNotNull('gstin')->orderByDesc('effective_from')->first(['gstin', 'legal_name', 'trade_name', 'address']);
        $printLegal = $registration->legal_name ?? $activeCompany->legal_name;
        $printName = ($registration->trade_name ?? null) ?: ($activeCompany->trade_name ?: $printLegal);
        $printAddress = ($registration->address ?? null) ?: app(\App\Services\Platform\CompanyProfileService::class)->fullAddress($activeCompany);
        $printGstin = $registration->gstin ?? null;
        $printContact = array_filter([$activeCompany->phone ? 'Ph: '.$activeCompany->phone : null, $activeCompany->email, $activeCompany->website]);
        $printLogo = $activeCompany->logo && is_file(public_path($activeCompany->logo)) ? asset($activeCompany->logo) : null;
    } elseif (!empty($general_setting->site_logo)) {
        $printLogo = url('logo', $general_setting->site_logo);
    }
@endphp
<div class="erp-print-header" aria-hidden="true">
    <div class="erp-print-letterhead">
        @if($printLogo)
            <img class="erp-print-logo" src="{{ $printLogo }}" alt="">
        @endif
        <div class="erp-print-company">
            <div class="erp-print-company-name">{{ $printName }}</div>
            @if($printLegal && $printLegal !== $printName)
                <div class="erp-print-company-line">{{ $printLegal }}</div>
            @endif
            @if($printAddress)
                <div class="erp-print-company-line">{{ $printAddress }}</div>
            @endif
            @if($printGstin || $printContact)
                <div class="erp-print-company-line">{{ implode('  ·  ', array_filter(array_merge([$printGstin ? 'GSTIN: '.$printGstin : null], $printContact))) }}</div>
            @endif
        </div>
        <div class="erp-print-stamp">
            @if($activeYear)
                <div>{{ str_starts_with($activeYear, 'FY') ? $activeYear : 'FY '.$activeYear }}</div>
            @endif
            <div>Printed <span data-erp-print-time>{{ now()->format('d M Y, h:i A') }}</span></div>
            @auth
                <div>By {{ auth()->user()->name }}</div>
            @endauth
        </div>
    </div>
    <div class="erp-print-title" data-erp-print-title></div>
    <div class="erp-print-meta" data-erp-print-meta></div>
</div>
