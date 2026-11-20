<!DOCTYPE html>
<html>
@php
    $show = json_decode($invoice_settings->show_column);
@endphp

<head>
    <link rel="icon" type="image/png" href="{{ url('logo', $general_setting->site_logo) }}" />
    <title>{{ $lims_sale_data->customer->name . '_Sale_' . $lims_sale_data->reference_no }}</title>
    @php
        $primary_color =
            isset($show->active_primary_color) &&
            $show->active_primary_color == 1 &&
            !empty($invoice_settings->primary_color)
                ? $invoice_settings->primary_color
                : '#014b94';
    @endphp
    <style type="text/css">
        span,
        td {
            font-size: 13px;
            line-height: 1.4;
        }

        @media print {
            .hidden-print {
                display: none !important;
            }

            tr.table-header {
                background-color: {{ $primary_color }} !important;
                -webkit-print-color-adjust: exact;
            }

            td.td-text {
                background-color: rgb(205, 218, 235) !important;
                -webkit-print-color-adjust: exact;
            }
        }

        table,
        tr,
        td {
            font-family: sans-serif;
            border-collapse: collapse;
        }
    </style>
</head>

<body>

    @if (preg_match('~[0-9]~', url()->previous()))
        @php $url = '../../pos'; @endphp
    @else
        @php $url = url()->previous(); @endphp
    @endif
    <div class="hidden-print">
        <table>
            <tr>
                <td><a href="{{ $url }}" class="btn btn-info"><i class="fa fa-arrow-left"></i>
                        {{ __('db.Back') }}</a> </td>
                <td><button onclick="window.print();" class="btn btn-primary"><i class="dripicons-print"></i>
                        {{ __('db.Print') }}</button></td>
            </tr>
        </table>
        <br>
    </div>
    <table style="width: 100%;border-collapse: collapse;">
        <tr>
            <td colspan="2" style="padding:9px 0;width:40%">
                @if (isset($show->show_warehouse_info) && $show->show_warehouse_info == 1)
                    <h1 style="margin:0">{{ $general_setting->company_name ?? $lims_biller_data->company_name }}</h1>
                    <div>
                        <span>{{ __('db.Address') }}:</span>&nbsp;&nbsp;<span>{{ $lims_warehouse_data->address }}</span>
                    </div>
                    <div>
                        <span>{{ __('db.Phone') }}:</span>&nbsp;&nbsp;<span>{{ $lims_warehouse_data->phone }}</span>
                    </div>
                    @if ($general_setting->vat_registration_number && isset($show->show_vat_registration_number) && $show->show_vat_registration_number == 1)
                        <div>
                            <span>{{ __('db.VAT Number') }}:</span>&nbsp;&nbsp;<span>{{ $general_setting->vat_registration_number }}</span>
                        </div>
                    @endif

                @endif
            </td>
            <td style="width:30%; text-align: middle; vertical-align: top;">
                @if ($general_setting->site_logo || $invoice_settings->company_logo)
                    <img src="{{ $invoice_settings->company_logo ? url('invoices', $invoice_settings->company_logo) : url('logo', $general_setting->site_logo) }}"
                        height="{{ $invoice_settings->logo_height ?? auto }}"
                        width="{{ $invoice_settings->logo_width ?? auto }}" style="margin:5px 0;">
                @endif
            </td>
            <td style="padding:5px -19px;width:30%;text-align:right;">
                <div style="display: flex;justify-content: space-between;border-bottom:1px solid #aaa">
                    <span>{{ __('db.reference') }}:</span> <span>{{ $lims_sale_data->reference_no }}</span>
                </div>
                <div style="display: flex;justify-content: space-between;border-bottom:1px solid #aaa">
                    <span>{{ __('db.date') }}:</span>
                    @if (isset($show->active_date_format) && $show->active_date_format == 1)
                        {{ Carbon\Carbon::parse($lims_sale_data->created_at)->format($invoice_settings->invoice_date_format) }}
                    @else
                        {{ $lims_sale_data->created_at }}
                    @endif
                </div>
                @if ($paid_by_info)
                    <div style="display: flex;justify-content: space-between;border-bottom:1px solid #aaa">
                        <span>{{ __('db.Paid By') }}:</span> <span>{{ $paid_by_info }}</span>
                    </div>
                @endif

                @if (isset($show->show_biller_info) && $show->show_biller_info == 1)
                    <div style="display: flex;justify-content: space-between;border-bottom:1px solid #aaa">
                        <span>{{ __('db.Served By') }}:</span> <span>{{ $lims_bill_by['name'] }} -
                            ({{ $lims_bill_by['user_name'] }})</span>
                    </div>
                @endif
                <?php
                foreach ($sale_custom_fields as $key => $fieldName) {
                    $field_name = str_replace(' ', '_', strtolower($fieldName));
                    echo '<div style="display: flex;justify-content: space-between;border-bottom:1px solid #aaa"><span>' . $fieldName . ':</span> <span> ' . $lims_sale_data->$field_name . '</span></div>';
                }
                foreach ($customer_custom_fields as $key => $fieldName) {
                    $field_name = str_replace(' ', '_', strtolower($fieldName));
                    echo '<div style="display: flex;justify-content: space-between;border-bottom:1px solid #aaa"><span>' . $fieldName . ':</span> <span>' . $lims_customer_data->$field_name . '</span></div>';
                }
                ?>
            </td>
        </tr>
    </table>
    <table style="width: 100%;border-collapse: collapse; margin-top: 4px;">
        <tr>
            @if (isset($show->show_bill_to_info) && $show->show_bill_to_info == 1)
                <td colspan="3" style="padding:4px 0;width:30%;vertical-align:top">
                    <h2
                        style="background-color: {{ isset($show->active_primary_color) &&
                        $show->active_primary_color == 1 &&
                        !empty($invoice_settings->primary_color)
                            ? $invoice_settings->primary_color
                            : '#014b94' }}; color: white; padding: 3px 10px; margin-bottom: 0;">
                        Bill To
                    </h2>
                    <div style="margin-top: 10px;margin-left: 10px">
                        <span>{{ __('db.customer') }}: {{ $lims_customer_data->name }}</span>
                    </div>
                    {{-- <div style="margin-left: 10px">
                        <span>VAT Number:</span>&nbsp;&nbsp;<span>{{$lims_customer_data->tax_no}}</span>
                    </div> --}}
                    <div style="margin-left: 10px">

                        <span>{{ __('db.Address') }}:</span>&nbsp;&nbsp;
                        @if ($lims_sale_data->sale_type == 'online')
                            <span>{{ $lims_sale_data->shipping_name }}, {{ $lims_sale_data->shipping_address }},
                                {{ $lims_sale_data->shipping_city }}, {{ $lims_sale_data->shipping_country }},
                                {{ $lims_sale_data->shipping_zip }}</span>
                        @else
                            <span>{{ $lims_customer_data->address }}</span>
                        @endif
                    </div>
                    @if (isset($lims_customer_data->phone_number) || isset($lims_sale_data->shipping_phone))
                        <div style="margin-bottom: 10px;margin-left: 10px">
                            <span>Phone:</span>&nbsp;&nbsp;
                            @if ($lims_sale_data->sale_type == 'online')
                                <span>{{ $lims_sale_data->shipping_phone }}
                                @else
                                    <span>{{ $lims_customer_data->phone_number }}</span>
                            @endif
                        </div>
                    @endif
                    @if (!empty($lims_sale_data->end_user_name))
                        <div style="margin-bottom: 4px;margin-left: 10px">
                            <span style="font-weight: bold;">Patient / End User:</span>&nbsp;&nbsp;<span>{{ $lims_sale_data->end_user_name }}</span>
                        </div>
                    @endif
                    @if (!empty($lims_sale_data->end_user_phone))
                        <div style="margin-bottom: 4px;margin-left: 10px">
                            <span style="font-weight: bold;">Patient Phone:</span>&nbsp;&nbsp;<span>{{ $lims_sale_data->end_user_phone }}</span>
                        </div>
                    @endif
                    @if (!empty($lims_sale_data->customer_order_no))
                        <div style="margin-bottom: 4px;margin-left: 10px">
                            <span style="font-weight: bold;">Order No:</span>&nbsp;&nbsp;<span>{{ $lims_sale_data->customer_order_no }}</span>
                        </div>
                    @endif
                </td>
            @endif

        </tr>
    </table>
    <table dir="@if (Config::get('app.locale') == 'ar' || $general_setting->is_rtl) {{ 'rtl' }} @endif"
        style="width: 100%;border-collapse: collapse;">
        <tr class="table-header"
            style="background-color: {{ isset($show->active_primary_color) &&
            $show->active_primary_color == 1 &&
            !empty($invoice_settings->primary_color)
                ? $invoice_settings->primary_color
                : '#014b94' }}; color: white;">
            <td style="border:1px solid #222;padding:1px 3px;width:4%;text-align:center">#</td>

            <td style="border:1px solid #222;padding:1px 3px;width:49%;text-align:center">{{ __('db.Description') }}
            </td>
            <td style="border:1px solid #222;padding:1px 3px;width:6%;text-align:center">{{ __('db.qty') }}</td>
            <td style="border:1px solid #222;padding:1px 3px;width:9%;text-align:center">{{ __('db.Unit Price') }}</td>
            <td style="border:1px solid #222;padding:1px 3px;width:7%;text-align:center">{{ __('db.Total') }}</td>
            <td style="border:1px solid #222;padding:1px 3px;width:7%;text-align:center">{{ __('db.Tax') }}</td>
            <td style="border:1px solid #222;padding:1px 2px;width:13%;text-align:center;">{{ __('db.Subtotal') }}</td>
        </tr>
        <?php
        $total_product_tax = 0;
        $totalPrice = 0;
        ?>

        @foreach ($lims_product_sale_data as $key => $product_sale_data)
            <?php
            $lims_product_data = \App\Models\Product::find($product_sale_data->product_id);
            if ($product_sale_data->sale_unit_id) {
                $unit = \App\Models\Unit::select('unit_code')->find($product_sale_data->sale_unit_id);
                $unit_code = $unit->unit_code;
            } else {
                $unit_code = '';
            }

            if ($product_sale_data->variant_id) {
                $variant = \App\Models\Variant::select('name')->find($product_sale_data->variant_id);
                $variant_name = $variant->name;
            } else {
                $variant_name = '';
            }
            $totalPrice += $product_sale_data->net_unit_price * $product_sale_data->qty;

            $topping_names = [];
            $topping_prices = [];
            $topping_price_sum = 0;

            if ($product_sale_data->topping_id) {
                $decoded_topping_id = is_string($product_sale_data->topping_id) ? json_decode($product_sale_data->topping_id, true) : $product_sale_data->topping_id;
                //dd(json_decode($product_sale_data->topping_id));
                if (is_array($decoded_topping_id)) {
                    foreach ($decoded_topping_id as $topping) {
                        $topping_names[] = $topping['name']; // Extract name
                        $topping_prices[] = $topping['price']; // Extract price
                        $topping_price_sum += $topping['price']; // Sum up prices
                    }
                }
            }

            $net_price_with_toppings = $product_sale_data->net_unit_price + $topping_price_sum;
            $total = ($product_sale_data->net_unit_price + $topping_price_sum) * $product_sale_data->qty;

            $subtotal = $product_sale_data->total + $topping_price_sum;
            ?>
            <tr>
                <td
                    style="@if (Config::get('app.locale') == 'ar' || $general_setting->is_rtl) {{ 'border-right:1px solid #222;' }} @endif border:1px solid #222;padding:1px 3px;text-align: center;">
                    {{ $key + 1 }}</td>
                <td style="border:1px solid #222;padding:1px 3px;font-size: 15px;line-height: 1.2;">

                    {!! $lims_product_data->name !!}

                    @if (!empty($topping_names))
                        <br><small>({{ implode(', ', $topping_names) }})</small>
                    @endif

                    @foreach ($product_custom_fields as $index => $fieldName)
                        <?php $field_name = str_replace(' ', '_', strtolower($fieldName)); ?>
                        @if ($lims_product_data->$field_name)
                            @if (!$index)
                                <br>
                                <span style="font-weight: bold;">{{ $fieldName }}</span>
                                {{ ': ' . $lims_product_data->$field_name }}
                            @else
                                <br>
                                <span style="font-weight: bold;">{{ $fieldName }}</span>
                                {{ ': ' . $lims_product_data->$field_name }}
                            @endif
                        @endif
                    @endforeach
                    @if ($product_sale_data->imei_number && !str_contains($product_sale_data->imei_number, 'null'))
                        <br><small>IMEI or Serial: {{ $product_sale_data->imei_number }}</small>
                    @endif
                    <!-- warranty -->
                    @if (isset($product_sale_data->warranty_duration))
                        <br>
                        <span
                            style="font-weight: bold;">Warranty</span>{{ ': ' . $product_sale_data->warranty_duration }}
                        <br>
                        <span style="font-weight: bold;">Will
                            Expire</span>{{ ': ' . $product_sale_data->warranty_end }}
                    @endif
                    <!-- guarantee -->
                    @if (isset($product_sale_data->guarantee_duration))
                        <br>
                        <span
                            style="font-weight: bold;">Guarantee</span>{{ ': ' . $product_sale_data->guarantee_duration }}
                        <br>
                        <span style="font-weight: bold;">Will
                            Expire</span>{{ ': ' . $product_sale_data->guarantee_end }}
                    @endif

                    @php
                        $hasRx = (!empty($product_sale_data->sph) || !empty($product_sale_data->cyl) || !empty($product_sale_data->axis) || !empty($product_sale_data->addition) || !empty($product_sale_data->dia) || !empty($product_sale_data->lr));
                    @endphp
                    @if($hasRx)
                        <div style="margin-top: 4px; padding: 2px 6px; background-color: #f8fafc; border-radius: 3px; font-size: 11px; display: inline-block; border: 1px solid #cbd5e1; line-height: 1.4;">
                            @if(!empty($product_sale_data->lr))<span style="font-weight: 700; color: #0284c7; margin-right: 6px;">Eye: {{ $product_sale_data->lr }}</span>@endif
                            @if($product_sale_data->sph !== null && $product_sale_data->sph !== '')<span style="margin-right: 6px;"><strong>SPH:</strong> {{ $product_sale_data->sph }}</span>@endif
                            @if($product_sale_data->cyl !== null && $product_sale_data->cyl !== '')<span style="margin-right: 6px;"><strong>CYL:</strong> {{ $product_sale_data->cyl }}</span>@endif
                            @if($product_sale_data->axis !== null && $product_sale_data->axis !== '')<span style="margin-right: 6px;"><strong>AXIS:</strong> {{ $product_sale_data->axis }}&deg;</span>@endif
                            @if($product_sale_data->addition !== null && $product_sale_data->addition !== '')<span style="margin-right: 6px;"><strong>ADD:</strong> {{ $product_sale_data->addition }}</span>@endif
                            @if(!empty($product_sale_data->dia))<span style="margin-right: 6px;"><strong>DIA:</strong> {{ $product_sale_data->dia }}mm</span>@endif
                        </div>
                    @endif
                </td>
                <td style="border:1px solid #222;padding:1px 3px;text-align:center">
                    {{ $product_sale_data->qty . ' ' . $unit_code . ' ' . $variant_name }}</td>
                <td style="border:1px solid #222;padding:1px 3px;text-align:center">
                    <x-amount-currency-symbol :amount="$product_sale_data->net_unit_price" :currency_symbol="$lims_sale_data->currency->symbol" />
                    @if (!empty($topping_prices))
                        <br><small>+
                            {{ implode(' + ', array_map(fn($price) => number_format($price, $general_setting->decimal, '.', ','), $topping_prices)) }}</small>
                    @endif
                </td>
                <td style="border:1px solid #222;padding:1px 3px;text-align:center">
                    <x-amount-currency-symbol :amount="$total" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
                <td style="border:1px solid #222;padding:1px 3px;text-align:center">
                    <x-amount-currency-symbol :amount="$product_sale_data->tax" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
                <td
                    style="border:1px solid #222;border-right:1px solid #222;padding:1px 3px;text-align:center;font-size: 15px;">
                    <x-amount-currency-symbol :amount="$subtotal" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            </tr>
        @endforeach
        <tr>
            <td colspan="3" rowspan="@if ($general_setting->invoice_format == 'gst' && $general_setting->state == 2) 6 @else 5 @endif"
                style="border:1px solid #222;padding:1px 3px;text-align: center; vertical-align: top;">
                @if (isset($show->show_payment_note) && $show->show_payment_note == 1 && $lims_sale_data->payment_note)
                    <p class="">
                        <strong>{{ __('db.Payment Note') }}:</strong>{{ $lims_sale_data->payment_note }}</p>
                @endif
                @if (isset($show->show_sale_note) && isset($lims_sale_data->sale_note) && $show->show_sale_note)
                    <p class=""> <strong>{{ __('db.Sale Note') }}:</strong>{{ $lims_sale_data->sale_note }}</p>
                @endif
            </td>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                {{ __('db.Total Before Tax') }}
            </td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->total_price - ($lims_sale_data->total_tax + $lims_sale_data->order_tax)" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>
        </tr>
        @if ($general_setting->invoice_format == 'gst' && $general_setting->state == 1)
            <tr>
                <td class="td-text" colspan="3"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                    IGST
                </td>
                <td class="td-text"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                    <x-amount-currency-symbol :amount="$lims_sale_data->total_tax + $lims_sale_data->order_tax" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            </tr>
        @elseif($general_setting->invoice_format == 'gst' && $general_setting->state == 2)
            <tr>
                <td class="td-text" colspan="3"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                    SGST
                </td>
                <td class="td-text"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                    @php $total_tax_amount = ($lims_sale_data->total_tax + $lims_sale_data->order_tax) / 2; @endphp
                    <x-amount-currency-symbol :amount="$total_tax_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            </tr>
            <tr>
                <td class="td-text" colspan="3"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                    CGST
                </td>
                <td class="td-text"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                    @php $total_tax_amount = ($lims_sale_data->total_tax + $lims_sale_data->order_tax) / 2; @endphp
                    <x-amount-currency-symbol :amount="$total_tax_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            </tr>
        @else
            <tr>
                <td class="td-text" colspan="3"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                    {{ __('db.Tax') }}
                </td>
                <td class="td-text"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                    <x-amount-currency-symbol :amount="$lims_sale_data->total_tax + $lims_sale_data->order_tax" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            </tr>
        @endif
        <tr>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                {{ __('db.Discount') }}
            </td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->total_discount + $lims_sale_data->order_discount" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>
        </tr>
        <tr>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                {{ __('db.Shipping Cost') }}
            </td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->shipping_cost ?? 0" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>

        </tr>
        @if(!empty($lims_sale_data->fitting_charge) && $lims_sale_data->fitting_charge > 0)
        <tr>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                Fitting Charge
            </td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->fitting_charge" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>
        </tr>
        @endif
        @if(!empty($lims_sale_data->tinting_cost) && $lims_sale_data->tinting_cost > 0)
        <tr>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                Tinting Cost
            </td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->tinting_cost" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>
        </tr>
        @endif
        <tr>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                {{ __('db.grand total') }}</td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->grand_total" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>
        </tr>
        <tr>
            @if ($general_setting->currency_position == 'prefix')
                <td class="td-text" colspan="3" rowspan="4"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;vertical-align: bottom;font-size: 15px; vertical-align: top;">
                    @if (isset($show->show_in_words) && $show->show_in_words == 1)
                        {{ __('db.In Words') }}<br>{{ $currency_code }} <span
                            style="text-transform:capitalize;font-size: 15px;">{{ str_replace('-', ' ', $numberInWords) }}</span>
                        only
                    @endif
                </td>
            @else
                <td class="td-text" colspan="3" rowspan="4"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;vertical-align: bottom;font-size: 15px; vertical-align: top;">
                    {{ __('db.In Words') }}:<br><span
                        style="text-transform:capitalize;font-size: 15px;">{{ str_replace('-', ' ', $numberInWords) }}</span>
                    {{ $currency_code }} only
                </td>
            @endif
        </tr>

        <tr>
            <td class="td-text" colspan="3"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                {{ __('db.Paid') }}
            </td>
            <td class="td-text"
                style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                <x-amount-currency-symbol :amount="$lims_sale_data->paid_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
            </td>
        </tr>
        <tr>
            @if ($change_amount > 0)
                <td class="td-text" colspan="3"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                    {{ __('db.Change') }}
                </td>
                <td class="td-text"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                    <x-amount-currency-symbol :amount="$change_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            @else
                <td class="td-text" colspan="3"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                    {{ __('db.Due') }}
                </td>
                <td class="td-text"
                    style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                    <x-amount-currency-symbol :amount="$lims_sale_data->grand_total - $lims_sale_data->paid_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                </td>
            @endif
        </tr>

        @if ($totalDue && isset($show->hide_total_due))
            <tr>
                @if (!$show->hide_total_due)
                    <td class="td-text" colspan="3"
                        style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);">
                        {{ __('db.Total Due') }}
                    </td>
                    <td class="td-text" colspan="4"
                        style="border:1px solid #222;padding:1px 3px;background-color:rgb(205, 218, 235);text-align: center;font-size: 15px;">
                        <x-amount-currency-symbol :amount="$totalDue" :currency_symbol="$lims_sale_data->currency->symbol" />
                    </td>
                @endif
            </tr>
        @endif
    </table>
    <table style="width: 100%; border-collapse: collapse;margin-top:-9px;">

        <tr>
            <td style="width: 100%; text-align: center">
                <br>
                <br>
                @if (isset($show->show_barcode) && $show->show_barcode == 1)
                    <?php echo '<img style="max-width:100%" src="data:image/png;base64,' . DNS1D::getBarcodePNG($lims_sale_data->reference_no, 'C128') . '" alt="barcode"   />'; ?>
                @endif
                <br><br>
                @if (isset($show->show_qr_code) && $show->show_qr_code == 1)
                    <?php echo '<img style="width:5%" src="data:image/png;base64,' . DNS2D::getBarcodePNG($qrText, 'QRCODE') . '" alt="barcode"   />'; ?>
                @endif
                <br>
            </td>
        </tr>
        <tr>
            <td>
                @if (isset($show->show_footer_text) && $show->show_footer_text == 1)
                    {!! $invoice_settings->footer_text ?? __('db.Thank you for shopping with us Please come again') !!}
                @endif
            </td>
        </tr>
    </table>
    <script type="text/javascript">
        localStorage.clear();

        function auto_print() {
            window.print();

        }
        //setTimeout(auto_print, 1000);
    </script>
</body>

</html>
