<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Packing Slip - {{ $sale->reference_no ?? 'Slip' }}</title>
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 8pt;
            line-height: 1.25;
            color: #0f172a;
            background-color: #f1f5f9;
            -webkit-font-smoothing: antialiased;
            padding: 12px;
        }

        .screen-toolbar {
            max-width: 125mm;
            margin: 0 auto 12px;
            background: #0f172a;
            color: #f8fafc;
            border-radius: 8px;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .screen-toolbar .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 5px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-back { background: #475569; color: #f8fafc; }
        .btn-back:hover { background: #64748b; }
        .btn-print { background: #0284c7; color: #ffffff; }
        .btn-print:hover { background: #0369a1; }

        .a6-slip-wrapper {
            max-width: 125mm;
            margin: 0 auto;
            background: #ffffff;
            padding: 4mm 5mm;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        .slip-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 4px;
            margin-bottom: 4px;
        }

        .slip-brand {
            font-size: 13pt;
            font-weight: 900;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 0.5px;
            line-height: 1.1;
        }

        .slip-brand-sub {
            font-size: 6.5pt;
            color: #475569;
            font-weight: 600;
        }

        .slip-doc-badge {
            text-align: right;
        }

        .slip-doc-badge .main-title {
            font-size: 8pt;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #0f172a;
            color: #ffffff;
            padding: 2px 8px;
            display: inline-block;
            border-radius: 2px;
        }

        .slip-doc-badge .pkg-ref {
            font-size: 7.5pt;
            font-weight: 800;
            margin-top: 2px;
            color: #0f172a;
            font-family: 'Courier New', Courier, monospace;
        }

        .top-barcode-block {
            text-align: center;
            padding: 4px 0 2px;
            border-bottom: 1px dashed #cbd5e1;
            margin-bottom: 4px;
        }

        .top-barcode-block img {
            max-height: 32px;
            width: auto;
            max-width: 90mm;
            image-rendering: pixelated;
        }

        .barcode-caption {
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 1.5px;
            font-family: 'Courier New', Courier, monospace;
            margin-top: 1px;
        }

        .meta-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            font-size: 6.5pt;
        }

        .meta-grid td {
            width: 25%;
            border: 1px solid #cbd5e1;
            padding: 2px 4px;
            vertical-align: top;
            background: #f8fafc;
        }

        .meta-grid .meta-label {
            font-size: 5.5pt;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            display: block;
        }

        .meta-grid .meta-val {
            font-size: 7pt;
            font-weight: 800;
            color: #0f172a;
        }

        .address-row {
            display: flex;
            gap: 6px;
            margin-bottom: 6px;
        }

        .address-box {
            flex: 1;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 4px 6px;
            background: #ffffff;
            font-size: 6.8pt;
        }

        .address-title {
            font-size: 6pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 2px;
            margin-bottom: 2px;
        }

        .address-name {
            font-weight: 800;
            font-size: 7.5pt;
            color: #0f172a;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            font-size: 6.8pt;
        }

        .items-table th {
            background: #0f172a;
            color: #ffffff;
            font-size: 6pt;
            font-weight: 800;
            text-transform: uppercase;
            padding: 3px 4px;
            text-align: left;
            border: 1px solid #0f172a;
        }

        .items-table td {
            border: 1px solid #cbd5e1;
            padding: 3px 4px;
            vertical-align: top;
        }

        .items-table .text-center { text-align: center; }
        .items-table .text-right { text-align: right; }

        .item-main-name {
            font-weight: 800;
            color: #0f172a;
        }

        .rx-badge-strip {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 3px 6px;
            margin-top: 2px;
            background: #f1f5f9;
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 6.2pt;
            font-weight: 700;
            color: #1e293b;
        }

        .rx-badge-strip span {
            color: #0369a1;
            font-weight: 800;
        }

        .slip-footer {
            border-top: 1px dashed #94a3b8;
            padding-top: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 6pt;
            color: #64748b;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
                margin: 0;
            }

            .hidden-print {
                display: none !important;
            }

            .a6-slip-wrapper {
                border: none;
                box-shadow: none;
                padding: 0;
                width: 100%;
                max-width: 100%;
            }

            @page {
                size: 105mm 148mm;
                margin: 4mm 5mm;
            }
        }
    </style>
</head>
<body>

<div class="screen-toolbar hidden-print">
    <a href="{{ route('sales.index') }}" class="btn btn-back">&larr; Back to Sales</a>
    <span style="font-size: 11px; font-weight: 600;">Standard A6 Dispatch Packing Slip</span>
    <button onclick="window.print();" class="btn btn-print">Print Slip</button>
</div>

<div class="a6-slip-wrapper">
    <div class="slip-header">
        <div>
            <div class="slip-brand">{{ $sale->warehouse->name ?? ($general_setting->site_title ?? 'Opticals Lab') }}</div>
            <div class="slip-brand-sub">{{ $sale->warehouse->address ?? 'Central Laboratory & Fulfillment' }} &bull; Ph: {{ $sale->warehouse->phone ?? '-' }}</div>
        </div>
        <div class="slip-doc-badge">
            <span class="main-title">Packing Slip</span>
            <div class="pkg-ref">#{{ $sale->reference_no }}</div>
        </div>
    </div>

    <div class="top-barcode-block">
        <img src="data:image/png;base64,{{ \DNS1D::getBarcodePNG($sale->reference_no, 'C128') }}" alt="barcode" />
        <div class="barcode-caption">*{{ $sale->reference_no }}*</div>
    </div>

    <table class="meta-grid">
        <tr>
            <td>
                <span class="meta-label">Order Ref</span>
                <span class="meta-val">{{ $sale->reference_no }}</span>
            </td>
            <td>
                <span class="meta-label">Order Date</span>
                <span class="meta-val">{{ date('d-M-Y', strtotime($sale->created_at)) }}</span>
            </td>
            <td>
                <span class="meta-label">Payment Status</span>
                <span class="meta-val">
                    @if($sale->payment_status == 4) Paid @elseif($sale->payment_status == 2) Due @elseif($sale->payment_status == 3) Partial @else Pending @endif
                </span>
            </td>
            <td>
                <span class="meta-label">Ship Mode</span>
                <span class="meta-val">Standard Dispatch</span>
            </td>
        </tr>
    </table>

    <div class="address-row">
        <div class="address-box">
            <div class="address-title">Shipped From:</div>
            <div class="address-name">{{ $sale->warehouse->name ?? 'Main Lab' }}</div>
            <div>{{ $sale->warehouse->address ?? '-' }}</div>
            @if(isset($sale->warehouse->phone))<div>Phone: {{ $sale->warehouse->phone }}</div>@endif
        </div>
        <div class="address-box">
            <div class="address-title">Deliver To:</div>
            @if($sale->shipping_name)
                <div class="address-name">{{ $sale->shipping_name }}</div>
                <div>{{ $sale->shipping_address }}, {{ $sale->shipping_city }}</div>
                <div>Phone: {{ $sale->shipping_phone }}</div>
            @else
                <div class="address-name">{{ $sale->customer->name ?? 'Valued Customer' }}</div>
                <div>{{ $sale->customer->address ?? '' }}, {{ $sale->customer->city ?? '' }}</div>
                @if(isset($sale->customer->phone_number))<div>Phone: {{ $sale->customer->phone_number }}</div>@endif
            @endif
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 20px;" class="text-center">#</th>
                <th>Item &amp; Prescription Details</th>
                <th style="width: 50px;" class="text-center">Qty</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 1; @endphp
            @foreach($packing_slip_product_data as $item)
                @php
                    $prod = $item->product ?? \App\Models\Product::find($item->product_id);
                    $hasRx = !empty($item->sph) || !empty($item->cyl) || !empty($item->axis) || !empty($item->addition) || !empty($item->lr);
                @endphp
                <tr>
                    <td class="text-center">{{ $count++ }}</td>
                    <td>
                        <div class="item-main-name">{{ $prod->name ?? 'Lens Item' }} [{{ $prod->code ?? '-' }}]</div>
                        @if($hasRx)
                            <div class="rx-badge-strip">
                                @if($item->lr)<div>Eye: <span>{{ $item->lr }}</span></div>@endif
                                @if($item->sph !== null && $item->sph !== '')<div>SPH: <span>{{ $item->sph }}</span></div>@endif
                                @if($item->cyl !== null && $item->cyl !== '')<div>CYL: <span>{{ $item->cyl }}</span></div>@endif
                                @if($item->axis !== null && $item->axis !== '')<div>AXIS: <span>{{ $item->axis }}&deg;</span></div>@endif
                                @if($item->addition !== null && $item->addition !== '')<div>ADD: <span>{{ $item->addition }}</span></div>@endif
                                @if($item->dia)<div>DIA: <span>{{ $item->dia }}mm</span></div>@endif
                            </div>
                        @endif
                    </td>
                    <td class="text-center" style="font-weight: 800; font-size: 7.5pt;">{{ (float) $item->qty }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($sale->sale_note)
        <div style="font-size: 6.5pt; margin-bottom: 4px; padding: 2px 4px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 2px;">
            <strong>Instructions:</strong> {{ $sale->sale_note }}
        </div>
    @endif

    <div class="slip-footer">
        <div>{{ $sale->reference_no }} &bull; Packing &amp; Quality Checked</div>
        <div>Page 1 of 1</div>
    </div>
</div>

</body>
</html>
