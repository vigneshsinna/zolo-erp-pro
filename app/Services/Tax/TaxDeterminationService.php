<?php

namespace App\Services\Tax;

use App\Models\Company;
use App\Services\Commercial\CommercialPricing;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Determines ordinary domestic GST once; unsupported special supplies cannot silently use domestic rules. */
class TaxDeterminationService
{
    public function prepare(array $data, string $kind, array $products, object $party, CompanyContext $context, string $date): array
    {
        unset($data['_tax_snapshot'], $data['tax_snapshot_json']);
        foreach ($data['items'] as &$line) {
            unset($line['_tax_snapshot'], $line['tax_snapshot_json'], $line['recoverable_tax']);
        }
        unset($line);
        if (!config('compliance.enabled') && empty($data['gst']) && !collect($products)->contains(fn ($p) => $p->tax_category_id)) return $data;
        $company = Company::findOrFail($context->companyId);
        $registrations = DB::table('tax_registrations')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->where('status', 'active')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get();
        if ($registrations->isEmpty() && empty($data['gst']) && !collect($products)->contains(fn ($p) => $p->tax_category_id)) {
            return $data;
        }
        if (!config('compliance.enabled') || !config('commercial.enabled')) {
            $this->invalid('gst', 'GST posting remains disabled pending compliance acceptance.');
        }
        if ($company->country_code !== 'IN' || $registrations->count() !== 1) {
            $this->invalid('registration', 'Configure exactly one active Indian GST registration for this branch and date.');
        }
        $registration = $registrations->sole();
        $companyState = Gstin::state($registration->state_code);
        if ($registration->gstin && substr(Gstin::normalize($registration->gstin), 0, 2) !== $companyState) {
            $this->invalid('registration', 'GSTIN state differs from registration state.');
        }
        $gst = $data['gst'] ?? [];
        $gst += array_filter(['supply_type' => $data['supply_type'] ?? null]);
        validator(['gst' => $gst], ['gst' => 'array', 'gst.place_of_supply' => 'nullable|string|size:2',
            'gst.reverse_charge' => 'sometimes|boolean', 'gst.special_supply' => 'nullable|in:domestic',
            'gst.supply_type' => 'nullable|in:domestic,export_lut,export_igst,sez_lut,sez_igst'])->validate();
        // Exports and SEZ supplies are zero-rated (Sec 16 IGST Act): inter-state, without IGST under a valid LUT or with IGST paid.
        $supply = $kind === 'sale' ? ($gst['supply_type'] ?? 'domestic') : 'domestic';
        $export = str_starts_with($supply, 'export'); $zeroRated = $supply !== 'domestic'; $underLut = str_ends_with($supply, '_lut');
        $lut = null;
        if ($underLut) {
            $trade = GstSetupService::tradeSettings($context->companyId);
            if (!$trade['lut_number'] || ($trade['lut_valid_from'] && $date < $trade['lut_valid_from']) || ($trade['lut_valid_to'] && $date > $trade['lut_valid_to'])) {
                $this->invalid('supply_type', 'Supply under LUT needs a Letter of Undertaking valid on '.$date.' (Settings › GST › Exports and TDS).');
            }
            $lut = $trade['lut_number'];
        }
        $profile = DB::table('party_tax_profiles')->where('company_id', $context->companyId)
            ->where('party_type', $kind === 'sale' ? 'customer' : 'supplier')->where('party_id', $party->id)->first();
        // A party without a saved profile gets one from its master GSTIN/state, as the party form would.
        $profile ??= $export ? (object) ['gstin' => null, 'state_code' => '96', 'registration_type' => 'overseas']
            : app(GstSetupService::class)->syncParty($kind === 'sale' ? 'customer' : 'supplier', $party);
        if (!$profile) $this->invalid('party', 'Enter the GSTIN or state of '.$party->name.' before GST billing.');
        // An overseas buyer has no Indian state (code 96, Other Country) and no GSTIN.
        $partyState = $export ? '96' : Gstin::state($profile->state_code);
        if (!$export && $profile->gstin && substr(Gstin::normalize($profile->gstin), 0, 2) !== $partyState) {
            $this->invalid('party', 'Party GSTIN state differs from its address state.');
        }
        if (!$export && ($profile->registration_type !== 'unregistered') !== (bool) $profile->gstin) {
            $this->invalid('party', 'Party registration type and GSTIN disagree.');
        }
        if ($supply !== 'domestic' && !$export && !$profile->gstin) $this->invalid('party', 'An SEZ unit or developer must have a GSTIN.');
        $sellerState = $kind === 'sale' ? $companyState : $partyState;
        $place = $export ? '96' : Gstin::state($gst['place_of_supply'] ?? ($kind === 'sale' ? $partyState : $companyState));
        $intra = !$zeroRated && $sellerState === $place;
        if ($intra && in_array($sellerState, ['04', '26', '31', '35', '38'], true)) {
            $this->invalid('place_of_supply', 'Union-territory intra-state supply requires a reviewed UTGST posting adapter.');
        }
        $reverse = (bool) ($gst['reverse_charge'] ?? false);
        $pricing = app(CommercialPricing::class);
        $basis = [];
        $priceField = $kind === 'sale' ? 'net_unit_price' : 'net_unit_cost';
        foreach ($data['items'] as $i => $line) {
            $basis[$i] = $pricing->units($pricing->number($line['qty'] ?? null, 'qty', 6) * $pricing->number($line[$priceField] ?? null, $priceField));
        }
        $discount = $pricing->units($pricing->number($data['order_discount'] ?? 0, 'order_discount'));
        if ($discount > array_sum($basis)) $this->invalid('order_discount', 'GST discount exceeds taxable line value.');
        $discounts = self::spread($discount, $basis);
        if (!empty($data['shipping_cost'])) {
            $this->invalid('shipping_cost', 'For GST, enter freight as a classified service line with its own tax category; tick "Add freight to item cost" to include it in stock value.');
        }
        $sellerRegistered = $kind === 'sale' ? $registration->registration_type === 'regular' : $profile->registration_type === 'regular';
        $header = ['version' => 'gst-domestic-v1', 'registration_gstin' => $registration->gstin,
            'seller_gstin' => $kind === 'sale' ? $registration->gstin : $profile->gstin,
            'buyer_gstin' => $kind === 'sale' ? $profile->gstin : $registration->gstin,
            'seller_name' => $kind === 'sale' ? $registration->legal_name : $party->name,
            'buyer_name' => $kind === 'sale' ? $party->name : $registration->legal_name,
            'company_address' => $registration->address ?? '', 'party_address' => $party->address ?? '', 'seller_state' => $sellerState, 'place_of_supply' => $place,
            'reverse_charge' => $reverse, 'registration_type' => $registration->registration_type]
            + ($zeroRated ? ['supply_type' => $supply, 'lut_number' => $lut] : []);
        foreach ($data['items'] as $i => &$line) {
            $product = $products[$line['product_id']];
            $category = DB::table('tax_categories')->where('company_id', $context->companyId)->where('id', $product->tax_category_id)->first();
            if (!$category) $this->invalid('tax_category_id', 'Set a GST rate for '.$product->name.' (Product or Settings › GST).');
            if (!in_array($category->supply_type, ['taxable', 'exempt', 'nil_rated', 'non_gst'], true)) $this->invalid('tax_category_id', 'Invalid supply classification.');
            $code = (string) ($product->hsn_code ?? '');
            $service = in_array($product->type, ['service', 'digital'], true);
            if (!preg_match($service ? '/^99[0-9]{4}$/D' : '/^[0-9]{4}(?:[0-9]{2})?(?:[0-9]{2})?$/D', $code)
                || !DB::table('hsn_sac_codes')->where('company_id', $context->companyId)->where('code', $code)
                    ->where('kind', $service ? 'sac' : 'hsn')->exists()) {
                $this->invalid('hsn_sac', 'Set a valid '.($service ? 'SAC' : 'HSN').' code for '.$product->name.' (Product or Settings › GST).');
            }
            $rates = DB::table('tax_rates')->where('company_id', $context->companyId)->where('tax_category_id', $category->id)
                ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get();
            $taxable = $basis[$i] - $discounts[$i];
            // Price-banded rates (garments: up to ₹2,500 a piece 5%, above 18%) pick the band by per-unit taxable value.
            $unitValue = (float) $line['qty'] > 0 ? $taxable / 10000 / (float) $line['qty'] : 0.0;
            $rates = $rates->filter(fn ($rate) => (!isset($rate->min_unit_value) || $rate->min_unit_value === null || $unitValue >= (float) $rate->min_unit_value - 0.00005)
                && (!isset($rate->max_unit_value) || $rate->max_unit_value === null || $unitValue <= (float) $rate->max_unit_value + 0.00005));
            if ($rates->count() !== 1) $this->invalid('tax_rate', $category->name.' has no single GST rate effective on '.$date.' for a unit value of ₹'.number_format($unitValue, 2).'.');
            $rate = $rates->sole();
            $chargeable = $category->supply_type === 'taxable' && ($sellerRegistered || $reverse) && !$underLut;
            $gstRate = $chargeable ? $pricing->number($rate->rate, 'rate') : 0;
            $cessRate = $chargeable ? $pricing->number($rate->cess_rate, 'cess_rate') : 0;
            // The user may change a line's GST rate; it must be one of the company's own GST rates, and the snapshot keeps the product's rate.
            $productRate = $gstRate;
            if (isset($line['tax_rate_override']) && $line['tax_rate_override'] !== '') {
                $override = $pricing->number($line['tax_rate_override'], 'tax_rate_override');
                if (!$chargeable && $override > 0) $this->invalid('tax_rate', $product->name.' carries no GST on this bill ('.str_replace('_', ' ', $category->supply_type).' or unregistered), so its rate cannot be changed.');
                $slabs = DB::table('tax_rates')->where('company_id', $context->companyId)->distinct()->pluck('rate')->map(fn ($slab) => (float) $slab)->push(0.0)->all();
                if (!in_array($override, $slabs, true)) $this->invalid('tax_rate', 'GST '.$override.'% is not one of your GST rates (Settings › GST).');
                $gstRate = $override;
            }
            if ($gstRate + $cessRate > 100) $this->invalid('rate', 'Total tax rate exceeds supported range.');
            $tax = (int) round($taxable * $gstRate / 100);
            $cess = (int) round($taxable * $cessRate / 100);
            $cgst = $intra ? (int) round($tax / 2) : 0;
            $snapshot = ['taxable_value' => $taxable / 10000, 'discount' => $discounts[$i] / 10000,
                'rate' => $gstRate, 'product_rate' => $productRate, 'cess_rate' => $cessRate, 'cgst' => $cgst / 10000,
                'sgst' => $intra ? ($tax - $cgst) / 10000 : 0, 'igst' => $intra ? 0 : $tax / 10000,
                'cess' => $cess / 10000, 'hsn_sac' => $code, 'place_of_supply' => $place, 'reverse_charge' => $reverse,
                'supply_type' => $zeroRated && $category->supply_type === 'taxable' ? 'zero_rated' : $category->supply_type, 'input_credit_allowed' => (bool) $category->input_credit_allowed && $registration->registration_type === 'regular',
                'product_name' => $product->name, 'product_code' => $product->code, 'qty' => (float) $line['qty']];
            $line['_tax_snapshot'] = $snapshot;
            $line['tax_rate'] = $gstRate;
        }
        unset($line);
        $data['_tax_snapshot'] = $header;
        if ($zeroRated) { $data['supply_type'] = $supply; $data['lut_number'] = $lut; }
        $data['order_tax_rate'] = 0;
        return $data;
    }

    /** Integer allocation with a final residual; line sums equal the header exactly. */
    public static function spread(int $amount, array $weights): array
    {
        $result = array_fill(0, count($weights), 0); $sum = array_sum($weights);
        if (!$amount || !$sum) return $result;
        $eligible = array_keys(array_filter($weights, fn ($w) => $w > 0)); $remaining = $amount;
        foreach ($eligible as $i) { $result[$i] = (int) floor($amount * ($weights[$i] / $sum)); $remaining -= $result[$i]; }
        // Distribute subunit residuals without putting more discount than value on a tiny final line.
        for ($i = 0; $remaining > 0; $i++, $remaining--) $result[$eligible[$i % count($eligible)]]++;
        return $result;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
