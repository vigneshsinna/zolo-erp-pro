<!DOCTYPE html>
<html>
@php
    $show = json_decode($invoice_settings->show_column);
@endphp

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" type="image/png" href="{{ url('logo', $general_setting->site_logo) }}" />
    <title>{{ $general_setting->site_title }}</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="all,follow">

    <style type="text/css">
        * {
            font-size: 10px;
            line-height: 20px;
            font-family: 'Ubuntu', sans-serif;
            text-transform: capitalize;
        }

        .btn {
            padding: 7px 10px;
            text-decoration: none;
            border: none;
            display: block;
            text-align: center;
            margin: 7px;
            cursor: pointer;
        }

        .btn-info {
            background-color: #999;
            color: #FFF;
        }

        .btn-primary {
            background-color: #6449e7;
            color: #FFF;
            width: 100%;
        }

        td,
        th,
        tr,
        table {
            border-collapse: collapse;
        }

        tr {
            border-bottom: 1px dotted #ddd;
        }

        td,
        th {
            padding: 7px 0;
            width: 50%;
        }

        table {
            width: 100%;
        }

        tfoot tr th:first-child {
            text-align: left;
        }

        .centered {
            text-align: center;
            align-content: center;
        }

        small {
            font-size: 10px;
        }

        @media print {
            * {
                font-size: 10px !important;
                line-height: 20px;
            }

            table {
                width: 100%;
                margin: 0 0;
            }

            td,
            th {
                padding: 5px 0;
            }

            .hidden-print {
                display: none !important;
            }

            /*tbody::after {
                content: ''; display: block;
                page-break-after: always;
                page-break-inside: avoid;
                page-break-before: avoid;
            }*/
        }
    </style>
</head>

<body>
    <div style="width:100%;max-width:180px;margin:0 0">
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



        <div id="receipt-data" style="margin-right:15%">
            <div class="centered">
                @if ($general_setting->site_logo || $invoice_settings->company_logo)
                    <img src="{{ $invoice_settings->company_logo ? url('invoices', $invoice_settings->company_logo) : url('logo', $general_setting->site_logo) }}"
                        height="{{ $invoice_settings->logo_height ?? auto }}"
                        width="{{ $invoice_settings->logo_width ?? auto }}" style="margin:5px 0;">
                @endif
                @if (isset($show->show_warehouse_info) && $show->show_warehouse_info == 1)
                    <!--<h2>{{ $lims_biller_data->company_name }}</h2>-->

                    <p>{{ __('db.Address') }}: {{ $lims_warehouse_data->address }}
                        <br>{{ __('db.Phone Number') }}: {{ $lims_warehouse_data->phone }}
                        @if ($general_setting->vat_registration_number && isset($show->show_vat_registration_number) && $show->show_vat_registration_number == 1)
                            <br>{{ __('db.VAT Number') }}: {{ $general_setting->vat_registration_number }}
                        @endif
                    </p>
                @endif
            </div>
            <p>{{ __('db.date') }}:
                @if (isset($show->active_date_format) && $show->active_date_format == 1)
                    {{ Carbon\Carbon::parse($lims_sale_data->created_at)->format($invoice_settings->invoice_date_format) }}
                @else
                    {{ $lims_sale_data->created_at }}
                @endif
                <br>
                @if (isset($show->show_ref_number) && $show->show_ref_number == 1)
                    {{ __('db.reference') }}: {{ $lims_sale_data->reference_no }}<br>
                @endif
                @if (isset($show->show_customer_name) && $show->show_customer_name == 1)
                    {{ __('db.customer') }}: {{ $lims_customer_data->name }}
                @endif
                @if (!empty($lims_sale_data->end_user_name))
                    <br><strong>Patient:</strong> {{ $lims_sale_data->end_user_name }}
                @endif
                @if (!empty($lims_sale_data->customer_order_no))
                    <br><strong>Order:</strong> {{ $lims_sale_data->customer_order_no }}
                @endif

                {{-- biller info --}}
                @if (isset($show->show_biller_info) && $show->show_biller_info == 1)
                    {{ __('db.Biller') }}: {{ $lims_bill_by['name'] }} - ({{ $lims_bill_by['user_name'] }})
                @endif
                {{-- end biller info --}}
                @if ($lims_sale_data->table_id)
                    <br>{{ __('db.Table') }}: {{ $lims_sale_data->table->name }}
                    <br>{{ __('db.Queue') }}: {{ $lims_sale_data->queue }}
                @endif
                <?php
                foreach ($sale_custom_fields as $key => $fieldName) {
                    $field_name = str_replace(' ', '_', strtolower($fieldName));
                    echo '<br>' . $fieldName . ': ' . $lims_sale_data->$field_name;
                }
                foreach ($customer_custom_fields as $key => $fieldName) {
                    $field_name = str_replace(' ', '_', strtolower($fieldName));
                    echo '<br>' . $fieldName . ': ' . $lims_customer_data->$field_name;
                }
                ?>

            </p>
            <table class="table-data" style="width:100%;max-width:180px;margin: 0 0">
                <tbody>

                    <?php $total_product_tax = 0; ?>
                    @if (isset($show->show_description) && $show->show_description == 1)
                        @foreach ($lims_product_sale_data as $key => $product_sale_data)
                            <?php
                            $lims_product_data = \App\Models\Product::find($product_sale_data->product_id);
                            if ($product_sale_data->variant_id) {
                                $variant_data = \App\Models\Variant::find($product_sale_data->variant_id);
                                $product_name = $lims_product_data->name . ' [' . $variant_data->name . ']';
                            } elseif ($product_sale_data->product_batch_id) {
                                $product_batch_data = \App\Models\ProductBatch::select('batch_no')->find($product_sale_data->product_batch_id);
                                $product_name = $lims_product_data->name . ' [' . __('db.Batch No') . ':' . $product_batch_data->batch_no . ']';
                            } else {
                                $product_name = $lims_product_data->name;
                            }

                            if ($product_sale_data->imei_number && !str_contains($product_sale_data->imei_number, 'null')) {
                                $product_name .= '<br>' . trans('IMEI or Serial Numbers') . ': <span style="text-transform: none;">' . $product_sale_data->imei_number . '</span>';
                            }
                            // Warranty
                            if (isset($product_sale_data->warranty_duration)) {
                                $product_name .= '<br>' . "<span style='font-weight: bold;'>Warranty</span>: " . $product_sale_data->warranty_duration;
                                $product_name .= '<br>' . "<span style='font-weight: bold;'>Will Expire</span>: " . $product_sale_data->warranty_end;
                            }
                            // Guarantee
                            if (isset($product_sale_data->guarantee_duration)) {
                                $product_name .= '<br>' . "<span style='font-weight: bold;'>Guarantee</span>: " . $product_sale_data->guarantee_duration;
                                $product_name .= '<br>' . "<span style='font-weight: bold;'>Will Expire</span>: " . $product_sale_data->guarantee_end;
                            }

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
                            $subtotal = $product_sale_data->total + $topping_price_sum;
                            ?>
                            <tr>
                                <td colspan="2" style="width:60%">
                                    {!! $product_name !!}

                                    @if (!empty($topping_names))
                                        <br><small>({{ implode(', ', $topping_names) }})</small>
                                    @endif

                                    @foreach ($product_custom_fields as $index => $fieldName)
                                        <?php $field_name = str_replace(' ', '_', strtolower($fieldName)); ?>
                                        @if ($lims_product_data->$field_name)
                                            @if (!$index)
                                                <br>{{ $fieldName . ': ' . $lims_product_data->$field_name }}
                                            @else
                                                {{ '/' . $fieldName . ': ' . $lims_product_data->$field_name }}
                                            @endif
                                        @endif
                                    @endforeach
                                    <br>{{ $product_sale_data->qty }} x
                                    {{ number_format((float) ($product_sale_data->total / $product_sale_data->qty), $general_setting->decimal, '.', ',') }}

                                    @if (!empty($topping_prices))
                                        <small>+
                                            {{ implode(' + ', array_map(fn($price) => number_format($price, $general_setting->decimal, '.', ','), $topping_prices)) }}</small>
                                    @endif

                                    @if ($product_sale_data->tax_rate)
                                        <?php $total_product_tax += $product_sale_data->tax; ?>
                                        [{{ __('db.Tax') }} ({{ $product_sale_data->tax_rate }}%):
                                        {{ $product_sale_data->tax }}]
                                    @endif
                                    @php
                                        $hasRx = (!empty($product_sale_data->sph) || !empty($product_sale_data->cyl) || !empty($product_sale_data->axis) || !empty($product_sale_data->addition) || !empty($product_sale_data->dia) || !empty($product_sale_data->lr));
                                    @endphp
                                    @if($hasRx)
                                        <br><small style="color: #0284c7; font-weight: bold;">
                                            @if(!empty($product_sale_data->lr))Eye: {{ $product_sale_data->lr }} @endif
                                            @if($product_sale_data->sph !== null && $product_sale_data->sph !== '')SPH: {{ $product_sale_data->sph }} @endif
                                            @if($product_sale_data->cyl !== null && $product_sale_data->cyl !== '')CYL: {{ $product_sale_data->cyl }} @endif
                                            @if($product_sale_data->axis !== null && $product_sale_data->axis !== '')AXIS: {{ $product_sale_data->axis }}&deg; @endif
                                            @if($product_sale_data->addition !== null && $product_sale_data->addition !== '')ADD: {{ $product_sale_data->addition }} @endif
                                            @if(!empty($product_sale_data->dia))DIA: {{ $product_sale_data->dia }}mm @endif
                                        </small>
                                    @endif
                                </td>
                                <td style="vertical-align:bottom;width:40%">
                                    <x-amount-currency-symbol :amount="$subtotal" :currency_symbol="$lims_sale_data->currency->symbol" />
                                </td>
                            </tr>
                        @endforeach
                    @endif
                    <!-- <tfoot> -->
                    <tr>
                        <th colspan="2" style="text-align:left;width:60%">{{ __('db.Total') }}</th>
                        <th style="width:40%">
                            <x-amount-currency-symbol :amount="$lims_sale_data->total_price" :currency_symbol="$lims_sale_data->currency->symbol" />
                        </th>
                    </tr>
                    @if ($general_setting->invoice_format == 'gst' && $general_setting->state == 1)
                        <tr>
                            <td colspan="2" style="width:60%">IGST</td>
                            <td style="width:40%">
                                <x-amount-currency-symbol :amount="$total_product_tax" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </td>
                        </tr>
                    @elseif($general_setting->invoice_format == 'gst' && $general_setting->state == 2)
                        <tr>
                            <td colspan="2" style="width:60%">>SGST</td>
                            <td style="width:40%">
                                @php $total_product_tax_amount = ((float) ($total_product_tax / 2)); @endphp
                                <x-amount-currency-symbol :amount="$total_product_tax_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="width:60%">>CGST</td>
                            <td style="width:40%">
                                @php $total_product_tax_amount = ((float) ($total_product_tax / 2)); @endphp
                                <x-amount-currency-symbol :amount="$total_product_tax_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </td>
                        </tr>
                    @endif
                    @if ($lims_sale_data->order_tax)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">{{ __('db.Order Tax') }}</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->order_tax" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    @if ($lims_sale_data->order_discount)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">{{ __('db.Order Discount') }}</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->order_discount" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    @if ($lims_sale_data->coupon_discount)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">{{ __('db.Coupon Discount') }}</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->coupon_discount" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    @if ($lims_sale_data->shipping_cost)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">{{ __('db.Shipping Cost') }}</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->shipping_cost" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    @if (!empty($lims_sale_data->fitting_charge) && $lims_sale_data->fitting_charge > 0)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">Fitting Charge</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->fitting_charge" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    @if (!empty($lims_sale_data->tinting_cost) && $lims_sale_data->tinting_cost > 0)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">Tinting Cost</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->tinting_cost" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    <tr>
                        <th colspan="2" style="text-align:left;width:60%">{{ __('db.grand total') }}</th>
                        <th style="width:40%">
                            <x-amount-currency-symbol :amount="$lims_sale_data->grand_total" :currency_symbol="$lims_sale_data->currency->symbol" />
                        </th>
                    </tr>
                    @if ($lims_sale_data->grand_total - $lims_sale_data->paid_amount > 0)
                        <tr>
                            <th colspan="2" style="text-align:left;width:60%">{{ __('db.Due') }}</th>
                            <th style="width:40%">
                                <x-amount-currency-symbol :amount="$lims_sale_data->grand_total - $lims_sale_data->paid_amount" :currency_symbol="$lims_sale_data->currency->symbol" />
                            </th>
                        </tr>
                    @endif
                    @if ($totalDue && isset($show->hide_total_due))
                        <tr>
                            @if (!$show->hide_total_due)
                                <th colspan="2" style="text-align:left;width:60%">{{ __('db.Total Due') }}</th>
                                <th style="width:40%">
                                    <x-amount-currency-symbol :amount="$totalDue" :currency_symbol="$lims_sale_data->currency->symbol" />
                                </th>
                            @endif
                        </tr>
                    @endif
                    <tr>
                        @if (isset($show->show_in_word) && $show->show_in_word == 1)
                            @if ($general_setting->currency_position == 'prefix')
                                <th class="centered" colspan="3">{{ __('db.In Words') }}:
                                    <span>{{ $currency_code }}</span>
                                    <span>{{ str_replace('-', ' ', $numberInWords) }}</span>
                                </th>
                            @else
                                <th class="centered" colspan="3">{{ __('db.In Words') }}:
                                    <span>{{ str_replace('-', ' ', $numberInWords) }}</span>
                                    <span>{{ $currency_code }}</span>
                                </th>
                            @endif
                        @endif
                    </tr>

                    <tr>
                        @if (isset($show->show_sale_note) && isset($lims_sale_data->sale_note) && $show->show_sale_note)
                            <td colspan="3">
                               <p class=""> <strong>{{ __('db.Sale Note') }}:</strong>{{ $lims_sale_data->sale_note }}</p>
                            </td>
                        @endif
                    </tr>


                    @foreach ($lims_payment_data as $payment_data)
                        @if (isset($show->show_paid_info) && $show->show_paid_info == 1)
                            <tr style="background-color:#ddd;">
                                <td style="text-align:center;padding: 5px;width:30">{{ __('db.Paid By') }}:<br>
                                    {{ $payment_data->paying_method }}</td>
                                <td style="text-align:center;padding: 5px;width:30%">{{ __('db.Amount') }}:<br>
                                    <x-amount-currency-symbol :amount="$payment_data->amount + $payment_data->change" :currency_symbol="$lims_sale_data->currency->symbol" />
                                </td>
                                <td style="text-align:center;padding: 5px;width:30%">{{ __('db.Change') }}:<br>
                                    <x-amount-currency-symbol :amount="$payment_data->change" :currency_symbol="$lims_sale_data->currency->symbol" />
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    <tr>
                        @if (isset($show->show_footer_text) && $show->show_footer_text == 1)
                            <td class="centered" colspan="3" style="width:100%;">
                                {{ $invoice_settings->footer_text }}
                            </td>
                        @else
                            <td class="centered" colspan="3" style="width:100%;">Thank you for shopping with us
                                <br>Please come again
                            </td>
                        @endif
                    </tr>

                    <tr>
                        <td class="centered" colspan="3">
                            @if (isset($show->show_barcode) && $show->show_barcode == 1)
                                <?php echo '<img style="margin:10px auto;" src="data:image/png;base64,' . DNS1D::getBarcodePNG($lims_sale_data->reference_no, 'C128') . '" width="90%" alt="barcode"   />'; ?>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td class="centered" colspan="3">
                            @if (isset($show->show_qr_code) && $show->show_qr_code == 1)
                                <?php echo '<img style="margin:10px auto;width:30px" src="data:image/png;base64,' . DNS2D::getBarcodePNG($qrText, 'QRCODE') . '" alt="QRcode"   />'; ?>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script type="text/javascript">
        localStorage.clear();

        function auto_print() {
            window.print();
        }
        //setTimeout(auto_print, 1000);
    </script>

</body>

</html>
