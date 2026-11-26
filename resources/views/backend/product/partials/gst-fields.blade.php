{{-- GST rate and HSN/SAC for companies billing under GST; replaces the legacy "Product Tax" list. --}}
@php
    $gstSetup = app(\App\Services\Tax\GstSetupService::class);
    $gstRates = $gstSetup->currentRates();
    $selectedRate = old('tax_category_id', $product->tax_category_id ?? '');
    $registration = null;
    try {
        $registration = $gstSetup->registration();
    } catch (\Throwable) {}
@endphp
<div class="col-12 mb-2">
    <div class="p-2 border rounded bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="small">
            <span class="font-weight-bold text-dark"><i class="dripicons-briefcase mr-1 text-primary"></i>Company GST Registration:</span>
            @if($registration && $registration->registration_type === 'regular' && $registration->gstin)
                <span class="badge badge-success px-2 py-1">Regular ({{ $registration->gstin }} - State {{ $registration->state_code }})</span>
            @elseif($registration && $registration->registration_type === 'composition')
                <span class="badge badge-warning px-2 py-1">Composition ({{ $registration->gstin }})</span>
            @elseif($registration && $registration->registration_type === 'unregistered')
                <span class="badge badge-secondary px-2 py-1">Unregistered (No GST collection/ITC)</span>
            @else
                <span class="badge badge-secondary px-2 py-1">Not Registered</span>
            @endif
        </div>
        <div class="text-muted small">
            <em>Company registration governs company tax treatment; product classification & HSN govern the tax rate on each supply.</em>
        </div>
    </div>
</div>
<div class="col-md-6 col-12">
    <div class="form-group">
        <label>GST Rate & Classification <a href="{{ route('settings.gst') }}" target="_blank" class="small ml-1">Manage</a></label>
        <select name="tax_category_id" id="tax_category_id" class="form-control selectpicker" data-live-search="true">
            <option value="">Not set</option>
            @foreach($gstRates as $id => $rate)
                <option value="{{ $id }}" @selected((string) $selectedRate === (string) $id) data-supply="{{ $rate['supply_type'] }}" data-bands="{{ !empty($rate['has_bands']) ? '1' : '0' }}">
                    {{ $rate['name'] }} ({{ ucfirst(str_replace('_', ' ', $rate['supply_type'])) }}{{ $rate['rate'] > 0 ? ' - ' . $rate['rate'] . '%' : '' }})
                </option>
            @endforeach
        </select>
        <span class="validation-msg text-danger small" id="tax_category_id-error"></span>
        @if(empty($gstRates))<small class="text-warning d-block">No GST rates yet. Open Settings › GST › Install standard GST setup.</small>@endif
        <small class="text-info d-block mt-1" id="gst-band-note" style="{{ (isset($gstRates[$selectedRate]) && !empty($gstRates[$selectedRate]['has_bands'])) ? '' : 'display:none;' }}">
            <i class="dripicons-info mr-1"></i>Rate changes dynamically by unit price band (e.g. up to ₹2,500 5%, above ₹2,500 18%).
        </small>
    </div>
</div>
<div class="col-md-6 col-12">
    <div class="form-group">
        <label>HSN / SAC Code</label>
        <input type="text" name="hsn_code" class="form-control" maxlength="8" inputmode="numeric"
               value="{{ old('hsn_code', $product->hsn_code ?? '') }}" placeholder="Goods: 4/6/8 digits · Services: 99xxxx">
        <span class="validation-msg text-danger small" id="hsn_code-error"></span>
        <small class="text-muted d-block mt-1">Apparel: Ch 61/62/63 · Perfumes/Cosmetics: Ch 33 · Services: SAC 99xxxx</small>
    </div>
</div>
<script>
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(function($) {
            $('#tax_category_id').on('change', function() {
                var $opt = $(this).find('option:selected');
                var hasBands = $opt.attr('data-bands') === '1' || ($opt.text().toLowerCase().indexOf('garment') !== -1);
                $('#gst-band-note').toggle(hasBands);
            });
        });
    }
</script>
