<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lab Receipt - {{ $lims_sale_data->reference_no }}</title>
    @if(isset($general_setting->site_logo) && $general_setting->site_logo)
        <link rel="icon" type="image/png" href="{{ url('logo', $general_setting->site_logo) }}" />
    @endif

    <style type="text/css">
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            width: 340px;
            margin: 0 auto;
            background: #f8fafc;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 13px;
            line-height: 1.4;
            padding: 10px 8px;
        }

        .hidden-print {
            margin-bottom: 12px;
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            flex: 1;
            transition: opacity 0.15s ease;
        }

        .btn-back {
            background-color: #475569;
            color: #ffffff;
        }

        .btn-print {
            background-color: #0284c7;
            color: #ffffff;
        }

        .btn:hover {
            opacity: 0.9;
        }

        #receipt-data {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .lab-header {
            text-align: center;
            border-bottom: 2px dashed #94a3b8;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .lab-badge {
            display: inline-block;
            background: #0f172a;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1px;
            padding: 2px 8px;
            border-radius: 3px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .company-name {
            font-size: 15px;
            font-weight: 800;
            text-transform: uppercase;
            color: #0f172a;
            line-height: 1.2;
        }

        .company-sub {
            font-size: 11px;
            color: #475569;
        }

        .order-meta-box {
            background: #f1f5f9;
            border-radius: 4px;
            padding: 6px 8px;
            margin-bottom: 10px;
            font-size: 12px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        .meta-row:last-child {
            margin-bottom: 0;
        }

        .meta-label {
            font-weight: 600;
            color: #475569;
        }

        .meta-value {
            font-weight: 700;
            color: #0f172a;
        }

        .lens-card {
            border: 1px solid #0f172a;
            border-radius: 4px;
            padding: 8px 8px 6px;
            margin-bottom: 10px;
            position: relative;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .lens-card:last-child {
            margin-bottom: 0;
        }

        .lens-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        .lens-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            max-width: 220px;
            line-height: 1.2;
        }

        .lens-category {
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }

        .qr-wrap {
            text-align: right;
        }

        .qr-wrap img {
            width: 54px;
            height: 54px;
            image-rendering: pixelated;
        }

        /* Power Grid (SPH / CYL / AXIS / ADD) */
        .power-grid {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
            text-align: center;
        }

        .power-grid th,
        .power-grid td {
            border: 1px solid #334155;
            padding: 3px 2px;
        }

        .power-grid th {
            background: #f1f5f9;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #334155;
        }

        .power-grid td {
            font-size: 12px;
            font-weight: 800;
            color: #000000;
            font-family: 'Courier New', Courier, monospace;
        }

        .power-grid .eye-col {
            background: #0f172a;
            color: #ffffff;
            font-weight: 900;
            font-size: 11px;
            width: 32px;
        }

        .lens-extra-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            font-size: 11px;
            padding-top: 4px;
            border-top: 1px dashed #cbd5e1;
            color: #334155;
        }

        .lens-extra-item {
            font-weight: 600;
        }

        .lens-extra-item span {
            font-weight: 800;
            color: #0f172a;
        }

        .lab-footer {
            margin-top: 10px;
            text-align: center;
            border-top: 1px dashed #94a3b8;
            padding-top: 6px;
            font-size: 10px;
            color: #64748b;
        }

        @media print {
            body {
                width: 100%;
                background: #ffffff;
                padding: 0;
                margin: 0;
            }

            .hidden-print {
                display: none !important;
            }

            #receipt-data {
                border: none;
                box-shadow: none;
                padding: 0;
                width: 100%;
            }

            @page {
                size: 100mm auto;
                margin: 2mm 3mm;
            }
        }
    </style>
</head>
<body>

<div class="hidden-print">
    <a href="{{ route('sales.index') }}" class="btn btn-back">&larr; Back to Sales</a>
    <button onclick="window.print();" class="btn btn-print">Print Lab Slip</button>
</div>

<div id="receipt-data">
    <div class="lab-header">
        <span class="lab-badge">Optical Rx Lab Slip</span>
        <div class="company-name">{{ $lims_biller_data->name ?? ($lims_warehouse_data->name ?? 'Lens Lab') }}</div>
        <div class="company-sub">{{ $lims_warehouse_data->name ?? 'Surfacing & Fitting Division' }}</div>
    </div>

    @php
        $customerOrderNo = '';
        if (isset($lims_sale_data->sale_note) && preg_match('/Order No[.:]?\s*(\S+)/i', $lims_sale_data->sale_note, $matches)) {
            $customerOrderNo = $matches[1];
        }
    @endphp

    <div class="order-meta-box">
        <div class="meta-row">
            <span class="meta-label">Customer / Store:</span>
            <span class="meta-value">{{ $lims_customer_data->name ?? 'Counter Customer' }} ({{ $lims_customer_data->city ?? ($lims_customer_data->state ?? '') }})</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Job / Order No:</span>
            <span class="meta-value">{{ $lims_sale_data->reference_no }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Date &amp; Time:</span>
            <span class="meta-value">{{ date('d-M-Y H:i', strtotime($lims_sale_data->created_at)) }}</span>
        </div>
        @if($customerOrderNo)
        <div class="meta-row">
            <span class="meta-label">Cust Ref No:</span>
            <span class="meta-value">{{ $customerOrderNo }}</span>
        </div>
        @endif
    </div>

    @foreach($lims_product_sale_data as $key => $item)
        @php
            $product = $item->product;
            $qrText = $lims_sale_data->reference_no . '-' . $item->id;
            $eye = strtoupper(trim((string) ($item->lr ?? 'PAIR')));
            $categoryName = $product?->category?->name ?? 'Ophthalmic Lens';
            $lensType = $product?->productType?->name ?? ($product?->category?->name ?? 'Single Vision');
        @endphp

        <div class="lens-card">
            <div class="lens-card-header">
                <div>
                    <div class="lens-title">{{ $product->name ?? 'Prescription Lens' }}</div>
                    <div class="lens-category">{{ $lensType }} &bull; Qty: {{ (float) $item->qty }}</div>
                </div>
                <div class="qr-wrap">
                    <img src="data:image/png;base64,{{ \DNS2D::getBarcodePNG($qrText, 'QRCODE') }}" alt="QR" />
                </div>
            </div>

            <table class="power-grid">
                <thead>
                    <tr>
                        <th class="eye-col">Eye</th>
                        <th>SPH</th>
                        <th>CYL</th>
                        <th>AXIS</th>
                        <th>ADD</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="eye-col">{{ $eye ?: 'R/L' }}</td>
                        <td>{{ $item->sph !== null && $item->sph !== '' ? $item->sph : '0.00' }}</td>
                        <td>{{ $item->cyl !== null && $item->cyl !== '' ? $item->cyl : '0.00' }}</td>
                        <td>{{ $item->axis !== null && $item->axis !== '' ? $item->axis : '—' }}</td>
                        <td>{{ $item->addition !== null && $item->addition !== '' ? $item->addition : '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="lens-extra-row">
                @if($item->dia)
                    <div class="lens-extra-item">DIA: <span>{{ $item->dia }} mm</span></div>
                @endif
                @if($item->base)
                    <div class="lens-extra-item">BASE: <span>{{ $item->base }}</span></div>
                @endif
                <div class="lens-extra-item">Item Ref: <span>#{{ $item->id }}</span></div>
            </div>
        </div>
    @endforeach

    <div class="lab-footer">
        <div>{{ $lims_sale_data->reference_no }} &bull; Verified Optical Rx Laboratory Document</div>
    </div>
</div>

<script type="text/javascript">
    function autoPrint() {
        if (window.location.search.indexOf('autoprint=1') !== -1) {
            window.print();
        }
    }
    window.onload = autoPrint;
</script>

</body>
</html>

