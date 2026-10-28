@php
    $Doc = \App\Services\Documents\DocumentRenderingService::class;
    $states = \App\Services\Tax\Gstin::$states;
    $d = $document; $p = $d['print'] ?? []; $kind = $d['kind'];
    $sale = str_starts_with($kind, 'sale'); $note = str_ends_with($kind, '_note');
    $money = fn ($value) => $Doc::inr((float) $value);
    $scale = $d['quantity_scale'] ?? 4;
    $qty = function ($value) use ($scale) { $text = number_format((float) $value, $scale, '.', ','); return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text; };
    $state = fn ($code) => $code ? ($states[$code] ?? $code).' ('.$code.')' : '';
    $components = ['cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'cess' => 'Cess'];
    $totals = ['taxable' => 0] + array_fill_keys(array_keys($components), 0);
    $summary = []; $quantities = [];
    foreach ($d['lines'] as $line) {
        $key = ($line['hsn_sac'] ?? '').'|'.(float) $line['tax_rate'];
        $summary[$key] ??= ['hsn' => $line['hsn_sac'] ?? '', 'rate' => (float) $line['tax_rate'], 'taxable' => 0] + array_fill_keys(array_keys($components), 0);
        $summary[$key]['taxable'] += $line['taxable_value']; $totals['taxable'] += $line['taxable_value'];
        foreach ($components as $component => $label) { $summary[$key][$component] += $line[$component]; $totals[$component] += $line[$component]; }
        $unit = $line['unit'] ?? ''; $quantities[$unit] = ($quantities[$unit] ?? 0) + $line['qty'];
    }
    $componentTax = array_sum(array_intersect_key($totals, $components));
    $shown = array_filter($components, fn ($label, $component) => round($totals[$component], 2) != 0, ARRAY_FILTER_USE_BOTH);
    $gst = ($d['place_of_supply'] ?? '') !== '';
    if (!$shown && $gst) $shown = $d['place_of_supply'] !== ($d['company_state'] ?? '') ? ['igst' => 'IGST'] : ['cgst' => 'CGST', 'sgst' => 'SGST'];
    $rates = array_unique(array_filter(array_column($summary, 'rate')));
    $otherTax = round($d['total_tax'] - $componentTax, 4);
    $discounted = collect($d['lines'])->contains(fn ($line) => ($line['discount'] ?? 0) > 0);
    $roundOff = (float) ($d['round_off'] ?? 0);
    $paid = $d['paid_amount'] ?? null; $balance = $paid === null ? null : round($d['grand_total'] - $paid, 2);
    $tradeName = $d['company_trade_name'] ?? null; $companyName = $tradeName ?: $d['company_name'];
    $logo = $Doc::logo($p['logo'] ?? null);
    $partyLabel = $sale ? 'Buyer (Bill to)' : 'Supplier';
    $contact = $d['party_contact'] ?? [];
    $meta = array_filter([
        ($note ? 'Note No.' : (!empty($d['order']) ? ($sale ? 'SO No.' : 'PO No.') : ($kind === 'purchase' ? 'Bill No.' : 'Invoice No.'))) => $d['supplier_bill_no'] ?? $d['number'],
        'Bill Date' => !empty($d['supplier_bill_date']) ? \Carbon\Carbon::parse($d['supplier_bill_date'])->format('d-m-Y') : null,
        'Our Ref.' => !empty($d['supplier_bill_no']) ? $d['number'] : null,
        (!empty($d['supplier_bill_no']) ? 'Entry Date' : 'Date') => \Carbon\Carbon::parse($d['date'])->format('d-m-Y'),
        'Delivery Date' => !empty($d['delivery_date']) ? \Carbon\Carbon::parse($d['delivery_date'])->format('d-m-Y') : null,
        'Against' => $d['source_number'] ?? null,
        'Source DC / GRN' => implode(', ', array_column($d['material_documents'] ?? [], 'reference_no')),
        'Due Date' => !empty($d['due_date']) ? \Carbon\Carbon::parse($d['due_date'])->format('d-m-Y') : null,
        'Order No.' => $d['references']['order_no'] ?? null,
        'Transport' => $d['transport']['transport_name'] ?? null,
        'Vehicle No.' => $d['transport']['vehicle_number'] ?? null,
        'LR No.' => ($d['transport']['lr_number'] ?? null) ?: ($d['references']['lr_no'] ?? null),
        'LR Date' => $d['transport']['lr_date'] ?? null,
        'Destination' => $d['references']['station_to'] ?? null,
        // The Transport & Remarks drawer saves bale_no / no_of_bales; API callers may send bale_count.
        'Bale No.' => $d['transport']['bale_no'] ?? null,
        'Bales' => ($d['transport']['bale_count'] ?? null) ?: ($d['transport']['no_of_bales'] ?? null),
        'Bundles' => $d['transport']['bundle_count'] ?? null,
        'Dispatched from' => $d['transport']['dispatched_from'] ?? null,
        'Supply' => match ($d['transport']['supply_type'] ?? null) { 'export_lut' => 'Export under LUT, without IGST', 'export_igst' => 'Export with IGST paid',
            'sez_lut' => 'SEZ supply under LUT, without IGST', 'sez_igst' => 'SEZ supply with IGST paid', default => null },
        'LUT / Bond No.' => $d['transport']['lut_number'] ?? null,
    ], fn ($value) => $value !== null && $value !== '');
    $columns = 8 + ($discounted ? 1 : 0);
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $d['title'] }} {{ $d['number'] }}</title>
<style>
@page { size: A4; margin: 8mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #fff; color: #000; font: 10px/1.35 "DejaVu Sans", Arial, Helvetica, sans-serif; }
.sheet { width: 194mm; margin: 12px auto; position: relative; }
.copy { page-break-after: always; } .copy:last-child { page-break-after: auto; }
table { width: 100%; border-collapse: collapse; }
td, th { padding: 2px 4px; vertical-align: top; }
.box { border: 1px solid #000; }
.box > tbody > tr > td, .box > thead > tr > th, .box > tbody > tr > th { border: 1px solid #000; }
.top td { border: 0; padding: 1px 0 3px; font-size: 10px; }
.title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; text-decoration: underline; }
.company { text-align: center; padding: 5px 6px; }
.company h1 { margin: 0 0 2px; font-size: 18px; text-transform: uppercase; }
.company p { margin: 0; }
.letterhead td { border: 0; vertical-align: middle; }
.letterhead td.logo { width: 30mm; padding: 4px 6px; }
.letterhead td.logo img { max-width: 28mm; max-height: 18mm; }
.label { font-weight: bold; text-decoration: underline; }
.party-name { font-size: 12px; font-weight: bold; text-transform: uppercase; }
.meta td { border: 0; padding: 1px 3px; }
.meta td.k { width: 34%; white-space: nowrap; }
.meta td.v { font-weight: bold; }
.meta td.sep { width: 8px; padding: 1px 0; }
.items th { font-size: 9.5px; background: #f2f2f2; text-align: center; }
.items td { border-top: 0 !important; border-bottom: 0 !important; }
.items tfoot td { border-top: 1px solid #000 !important; font-weight: bold; }
.items thead { display: table-header-group; }
.items tr { page-break-inside: avoid; }
.num { text-align: right; white-space: nowrap; }
.c { text-align: center; }
.desc { font-weight: bold; text-transform: uppercase; }
.small { font-size: 8.5px; }
.summary th, .summary td { border: 1px solid #000; font-size: 9px; }
.summary th { background: #f2f2f2; }
.totals td { border: 0; padding: 2px 4px; }
.totals .grand td { border-top: 1px solid #000; font-size: 13px; font-weight: bold; }
.words { font-weight: bold; }
.foot td { font-size: 9px; }
.sign { height: 52px; text-align: right; vertical-align: bottom; font-weight: bold; }
.reversed { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 46px; color: rgba(200, 0, 0, .18); transform: rotate(-24deg); font-weight: bold; }
.print-action { display: block; margin: 12px auto; padding: 8px 20px; }
@media print { .print-action { display: none; } .sheet { margin: 0 auto; } }
@media (max-width: 760px) { .sheet { width: auto; margin: 8px 16px; } }
</style></head><body>
{{-- Added by script so PDF and e-mail copies never show it. --}}
<script>document.write('<button type="button" class="print-action" onclick="window.print()">Print</button>');</script>
@foreach($profile['copies'] as $copy)
<div class="sheet copy">
    @if($d['reversed_at'] ?? null)<div class="reversed">REVERSED {{ $d['reversed_at'] }}</div>@endif
    <table class="top"><tr>
        <td style="width:33%"><strong>GSTIN: {{ $d['company_gstin'] ?: 'Unregistered' }}</strong></td>
        <td style="width:34%" class="title">{{ $d['title'] }}</td>
        <td style="width:33%;text-align:right"><em>{{ $copy === 'Original' && $kind === 'sale' ? 'Original for Recipient' : $copy }}</em></td>
    </tr></table>

    <table class="box">
        <tr><td colspan="2" style="padding:0">
            <table class="letterhead"><tr>
            <td class="logo">@if($logo)<img src="{{ $logo }}" alt="">@endif</td>
            <td class="company">
            <h1>{{ $companyName }}</h1>
            @if($tradeName && strcasecmp($tradeName, $d['company_name']) !== 0)<p>{{ $d['company_name'] }}</p>@endif
            @if($d['company_address'])<p>{{ $d['company_address'] }}</p>@endif
            @if($p['licences'] ?? null)<p>{{ $p['licences'] }}</p>@endif
            @if($p['dispatch_from'] ?? null)<p>Dispatch from: {{ $p['dispatch_from'] }}</p>@endif
            @php $reach = array_filter([($p['phone'] ?? null) ? 'Ph: '.$p['phone'] : null, $p['email'] ?? null, $p['website'] ?? null, ($p['pan'] ?? null) ? 'PAN: '.$p['pan'] : null]); @endphp
            @if($reach)<p>{{ implode(' · ', $reach) }}</p>@endif
            </td>
            <td class="logo"></td>
            </tr></table>
        </td></tr>
        <tr>
            <td style="width:55%">
                <div class="label">{{ $partyLabel }}</div>
                <div class="party-name">{{ $d['party_name'] }}</div>
                @if(($contact['company'] ?? null) && $contact['company'] !== $d['party_name'])<div>{{ $contact['company'] }}</div>@endif
                @if($d['party_address'])<div>{{ $d['party_address'] }}</div>@endif
                @php $place = trim(implode(' - ', array_filter([$contact['city'] ?? null, $contact['postal_code'] ?? null]))); @endphp
                @if($place)<div>{{ $place }}</div>@endif
                @if($d['party_state'] ?? null)<div>State: {{ $state($d['party_state']) }}</div>@endif
                @if($contact['phone'] ?? null)<div>Ph: {{ $contact['phone'] }}</div>@endif
                <div><strong>GSTIN: {{ $d['party_gstin'] ?: 'Unregistered' }}</strong></div>
                @if($d['ship_to'] ?? null)
                    <div class="label" style="margin-top:4px">Shipped to</div>
                    <div class="party-name">{{ $d['ship_to']['name'] }}</div>
                    <div>{{ $d['ship_to']['address'] }}</div>
                    @if($d['ship_to']['phone'])<div>Ph: {{ $d['ship_to']['phone'] }}</div>@endif
                @endif
                <div style="margin-top:4px"><strong>Place of supply:</strong> {{ $state($d['place_of_supply']) ?: '—' }}
                    @if($d['reverse_charge']) · <strong>Reverse charge: Yes</strong>@endif</div>
            </td>
            <td style="width:45%">
                <table class="meta">
                    @foreach($meta as $label => $value)<tr><td class="k">{{ $label }}</td><td class="sep">:</td><td class="v">{{ $value }}</td></tr>@endforeach
                    @if($paid !== null)
                        <tr><td class="k">Payment</td><td class="sep">:</td><td class="v">{{ $balance <= 0 ? 'Paid' : ($paid > 0 ? 'Part paid' : 'Credit') }}</td></tr>
                        @if($balance > 0)<tr><td class="k">Balance due</td><td class="sep">:</td><td class="v">{{ $money($balance) }}</td></tr>@endif
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="box items" style="border-top:0">
        <thead><tr>
            <th style="width:4%">S.No</th><th>Description of Goods / Services</th><th style="width:9%">HSN/SAC</th><th style="width:7%">GST %</th>
            <th style="width:10%">Quantity</th><th style="width:9%">Rate</th><th style="width:9%">Rate incl. GST</th>
            @if($discounted)<th style="width:8%">Discount</th>@endif
            <th style="width:12%">Taxable Value</th>
        </tr></thead>
        <tbody>
        @foreach($d['lines'] as $i => $line)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td><span class="desc">{{ $line['name'] }}</span>
                    @if($dimension = $line['dimensions'] ?? null)<div class="small">Piece {{ $dimension['identity_no'] ?? '' }} · {{ $dimension['length'] }} × {{ $dimension['width'] }} × {{ $dimension['thickness'] }} {{ $dimension['dimension_uom'] }} · CFT {{ $dimension['line_cft'] ?? $dimension['computed_cft'] }} · CBM {{ $dimension['line_cbm'] ?? $dimension['computed_cbm'] }} · {{ $dimension['formula_version'] }}</div>@endif</td>
                <td class="c">{{ $line['hsn_sac'] ?: '—' }}</td>
                <td class="c">{{ $qty($line['tax_rate']) }}%</td>
                <td class="num">{{ $qty($line['qty']) }} {{ $line['unit'] ?? '' }}</td>
                <td class="num">{{ $money($line['price']) }}</td>
                <td class="num">{{ $money($line['price'] * (1 + ($line['tax_rate'] + ($line['cess_rate'] ?? 0)) / 100)) }}</td>
                @if($discounted)<td class="num">{{ ($line['discount'] ?? 0) > 0 ? $money($line['discount']) : '' }}</td>@endif
                <td class="num">{{ $money($line['taxable_value']) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr>
            <td colspan="4" class="num">Total</td>
            <td class="num">@foreach($quantities as $unit => $sum){{ $qty($sum) }} {{ $unit }}@if(!$loop->last)<br>@endif @endforeach</td>
            <td colspan="{{ $columns - 6 }}"></td>
            <td class="num">{{ $money($totals['taxable']) }}</td>
        </tr></tfoot>
    </table>

    <table class="box" style="border-top:0">
        <tr>
            <td style="width:62%">
                <table class="summary">
                    <tr><th>HSN/SAC</th><th>GST %</th><th>Taxable Value</th>@foreach($shown as $label)<th>{{ $label }}</th>@endforeach<th>Total Tax</th></tr>
                    @foreach($summary as $row)
                        <tr><td class="c">{{ $row['hsn'] ?: '—' }}</td><td class="c">{{ $qty($row['rate']) }}%</td><td class="num">{{ $money($row['taxable']) }}</td>
                            @foreach($shown as $component => $label)<td class="num">{{ $money($row[$component]) }}</td>@endforeach
                            <td class="num">{{ $money(array_sum(array_intersect_key($row, $components))) }}</td></tr>
                    @endforeach
                    <tr><th class="num" colspan="2">Total</th><th class="num">{{ $money($totals['taxable']) }}</th>
                        @foreach($shown as $component => $label)<th class="num">{{ $money($totals[$component]) }}</th>@endforeach
                        <th class="num">{{ $money($componentTax) }}</th></tr>
                </table>
                <p style="margin:6px 0 0">Amount chargeable (in words)</p>
                <p class="words" style="margin:0">{{ $d['currency'] === 'INR' ? $Doc::rupeesInWords($d['grand_total']) : $d['currency'].' '.$money($d['grand_total']) }}</p>
                @if($d['note'] ?? null)<p style="margin:6px 0 0"><strong>Remarks:</strong> {{ $d['note'] }}</p>@endif
            </td>
            <td style="width:38%">
                <table class="totals">
                    <tr><td>Taxable Amount</td><td class="num">{{ $money($totals['taxable']) }}</td></tr>
                    @foreach($shown as $component => $label)
                        <tr><td>{{ $label }}{{ in_array($component, ['cgst', 'sgst'], true) && count($rates) === 1 ? ' @ '.$qty(reset($rates) / 2).'%' : '' }}</td><td class="num">{{ $money($totals[$component]) }}</td></tr>
                    @endforeach
                    @if($otherTax != 0)<tr><td>Tax</td><td class="num">{{ $money($otherTax) }}</td></tr>@endif
                    @if($d['shipping'] > 0)<tr><td>Freight</td><td class="num">{{ $money($d['shipping']) }}</td></tr>@endif
                    @if(!$gst && $d['discount'] > 0)<tr><td>Discount</td><td class="num">-{{ $money($d['discount']) }}</td></tr>@endif
                    @if($roundOff != 0)<tr><td>Round Off</td><td class="num">{{ $roundOff > 0 ? '+' : '' }}{{ $money($roundOff) }}</td></tr>@endif
                    <tr class="grand"><td>Grand Total</td><td class="num">{{ $d['currency'] === 'INR' ? '' : $d['currency'].' ' }}{{ $money($d['grand_total']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @php $bank = array_filter(['Bank' => $p['bank_name'] ?? null, 'A/c Name' => $p['bank_account_name'] ?? null, 'A/c No.' => $p['bank_account_no'] ?? null,
        'IFSC' => $p['bank_ifsc'] ?? null, 'Branch' => $p['bank_branch'] ?? null, 'UPI' => $p['upi_id'] ?? null]);
        $declaration = $p['declaration'] ?? ($kind === 'sale' ? 'We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.' : null); @endphp
    <table class="box foot" style="border-top:0">
        <tr>
            <td style="width:55%">
                @if($bank && $sale)
                    <div class="label">Bank Details</div>
                    <table class="meta">@foreach($bank as $label => $value)<tr><td class="k" style="width:22%">{{ $label }}</td><td class="sep">:</td><td>{{ $value }}</td></tr>@endforeach</table>
                @endif
                @if(($p['terms'] ?? null) && $sale)
                    <div class="label" style="margin-top:4px">Terms &amp; Conditions</div>
                    <div class="small" style="white-space:pre-line">{{ $p['terms'] }}</div>
                @endif
            </td>
            <td style="width:45%">
                @if($declaration)<div class="label">Declaration</div><div class="small">{{ $declaration }}</div>@endif
                <div class="sign">For {{ $companyName }}<br><br><br>{{ $p['signatory'] ?? 'Authorised Signatory' }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2"><table><tr>
                <td style="width:33%;border:0"><strong>{{ $sale ? 'Buyer' : 'Receiver' }} Seal &amp; Signature</strong></td>
                <td style="width:34%;border:0" class="c small">This is a computer generated {{ strtolower($d['title']) }}.</td>
                <td style="width:33%;border:0;text-align:right"><strong>{{ $p['signatory'] ?? 'Authorised Signatory' }}</strong></td>
            </tr></table></td>
        </tr>
    </table>
</div>
@endforeach
</body></html>
