@php
    $lims_warehouse_list = $lims_warehouse_list ?? \App\Models\Warehouse::where('is_active', true)->get();
    $lims_brand_list = $lims_brand_list ?? \App\Models\Brand::where('is_active', true)->get();
    $lims_category_list = $lims_category_list ?? \App\Models\Category::where('is_active', true)->get();
    $lims_unit_list = $lims_unit_list ?? \App\Models\Unit::where('is_active', true)->get();
    $lims_tax_list = $lims_tax_list ?? \App\Models\Tax::where('is_active', true)->get();
    $general_setting = $general_setting ?? \App\Models\GeneralSetting::latest()->first();
    $custom_fields = $custom_fields ?? collect();
    $quickGstRates = \App\Services\Tax\GstSetupService::usesGst() ? app(\App\Services\Tax\GstSetupService::class)->currentRates() : null;
    try {
        $context = app(\App\Services\Platform\CompanyContextResolver::class)->forActor();
        $companyId = $context?->companyId;
    } catch (\Throwable) {
        $companyId = session('company_id');
    }
    $companyId = $companyId ?: session('company_id');
    $profileRow = $companyId ? \Illuminate\Support\Facades\DB::table('company_industry_settings')->where('company_id', $companyId)->first() : null;
    $isOpticalProfile = ($profileRow && $profileRow->profile_key === 'opticals');
    $quickProductTypes = $isOpticalProfile ? \App\Models\ProductType::forCompany($companyId)->where('is_active', true)->orderBy('name')->get() : collect();
@endphp

<div id="quick-create-item-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left compact-add-product-modal">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header d-flex align-items-center justify-content-between">
                <div>
                    <h5 id="quick-modal-title" class="modal-title font-weight-bold u-fs-15px u-c-text u-d-flex u-items-center u-gap-8px u-m-0">
                        <i class="fa fa-plus-circle u-c-primary u-fs-18px"></i> {{ __('db.add_product') }} (Catalog Master)
                    </h5>
                    <span id="quick-modal-subtitle" class="u-fs-11_5px u-c-text-muted">{{ __('db.The field labels marked with * are required input fields') }}. Once created, the item is immediately selected and added to the voucher.</span>
                </div>
                <button type="button" class="close u-fs-22px u-ol-none" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="quick-create-item-form" enctype="multipart/form-data" data-quick-edit-base="{{ url('/products') }}" @isset($billUsage) data-product-usage="{{ $billUsage }}" @endisset>
                @csrf
                <input type="hidden" id="quick-edit-product-id" name="edit_product_id" value="">
                <div class="modal-body p-2">
                    <div id="quick-modal-error-banner" class="alert alert-danger py-2 px-3 mb-2 d-none u-fs-12px" role="alert"></div>
                    <div class="zolo-product-grid">

                        <!-- BOX 1: Primary Identity & Categorization (Blue) -->
                        <x-ui.section-box variant="blue" title="{{__('db.Product Details')}}" icon="box">
                            <div class="row">
                                @if(isset($billUsage))
                                    @include('backend.product.partials.usage-options')
                                @endif
                                @if($isOpticalProfile)
                                    <input type="hidden" name="type" id="quick-item-type" value="standard">
                                    <div class="col-12">
                                        <div class="form-group">
                                            <label>{{__('db.Barcode Symbology')}} *</label>
                                            <select name="barcode_symbology" required class="form-control selectpicker" id="quick-item-symbology">
                                                <option value="C128" selected>Code 128</option>
                                                <option value="C39">Code 39</option>
                                                <option value="UPCA">UPC-A</option>
                                                <option value="UPCE">UPC-E</option>
                                                <option value="EAN8">EAN-8</option>
                                                <option value="EAN13">EAN-13</option>
                                            </select>
                                        </div>
                                    </div>
                                @else
                                    <div class="col-6">
                                        <div class="form-group">
                                            <label>{{__('db.Product Type')}} *</label>
                                            <select name="type" required class="form-control selectpicker" id="quick-item-type">
                                                <option value="standard" selected>Standard</option>
                                                <option value="combo">Combo</option>
                                                <option value="digital">Digital</option>
                                                <option value="service">Service</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-group">
                                            <label>{{__('db.Barcode Symbology')}} *</label>
                                            <select name="barcode_symbology" required class="form-control selectpicker" id="quick-item-symbology">
                                                <option value="C128" selected>Code 128</option>
                                                <option value="C39">Code 39</option>
                                                <option value="UPCA">UPC-A</option>
                                                <option value="UPCE">UPC-E</option>
                                                <option value="EAN8">EAN-8</option>
                                                <option value="EAN13">EAN-13</option>
                                            </select>
                                        </div>
                                    </div>
                                @endif
                                <div class="col-12">
                                    <div class="form-group">
                                        <label>{{__('db.Product Name')}} *</label>
                                        <input type="text" name="name" class="form-control" id="quick-item-name" required placeholder="{{ __('db.Type product name') }}">
                                        <span class="validation-msg text-danger small" id="quick-name-error"></span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group">
                                        <label>{{__('db.Product Code')}} *</label>
                                        <div class="input-group">
                                            <input type="text" name="code" class="form-control" id="quick-item-code" required placeholder="{{ __('db.Enter or generate code') }}">
                                            <div class="input-group-append">
                                                <button id="btn-quick-gen-code" type="button" class="btn btn-sm btn-default" title="{{__('db.Generate')}}"><i class="fa fa-refresh"></i></button>
                                            </div>
                                        </div>
                                        <span class="validation-msg text-danger small" id="quick-code-error"></span>
                                    </div>
                                </div>
                                <div class="{{ $isOpticalProfile ? 'col-md-4 col-12' : 'col-6' }}" id="quick-brand-col">
                                    <div class="form-group">
                                        <label>{{__('db.Brand')}}</label>
                                        <div class="input-group">
                                            <select name="brand_id" id="quick-item-brand" class="selectpicker form-control" data-live-search="true" data-live-search-style="begins" title="{{ __('db.Select Brand...') }}">
                                                @foreach($lims_brand_list as $brand)
                                                    <option value="{{$brand->id}}">{{$brand->title}}</option>
                                                @endforeach
                                            </select>
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#quick-create-brand-modal" title="{{ __('db.Add Brand') }}"><i class="dripicons-plus"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="{{ $isOpticalProfile ? 'col-md-4 col-12' : 'col-6' }}" id="quick-category-col">
                                    <div class="form-group">
                                        <label>{{__('db.category')}} *</label>
                                        <div class="input-group">
                                            <select name="category_id" id="quick-item-category" required class="selectpicker form-control" data-live-search="true" data-live-search-style="begins" title="{{ __('db.Select Category...') }}">
                                                @foreach($lims_category_list as $category)
                                                    <option value="{{$category->id}}">{{$category->name}}</option>
                                                @endforeach
                                            </select>
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#quick-create-category-modal" title="{{ __('db.Add Category') }}"><i class="dripicons-plus"></i></button>
                                            </div>
                                        </div>
                                        <span class="validation-msg text-danger small"></span>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12" id="quick-product-type-col" style="{{ $isOpticalProfile ? '' : 'display:none;' }}">
                                    <div class="form-group">
                                        <label>Type *</label>
                                        <div class="input-group">
                                            <select name="product_type_id" id="quick-item-product-type" class="selectpicker form-control" data-live-search="true" data-live-search-style="begins" title="Select Type...">
                                                @foreach($quickProductTypes as $pt)
                                                    <option value="{{ $pt->id }}">{{ $pt->name }}</option>
                                                @endforeach
                                            </select>
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-default btn-sm product-type-modal-btn" data-toggle="modal" data-target="#product-type-modal" title="Add Type"><i class="dripicons-plus"></i></button>
                                            </div>
                                        </div>
                                        <span class="validation-msg text-danger small"></span>
                                    </div>
                                </div>

                                <div id="quick-unit-container" class="col-12">
                                    <div class="row">
                                        <div class="col-12 form-group">
                                            <label>{{__('db.Product Unit')}} *</label>
                                            <div class="input-group">
                                                <select required class="form-control selectpicker" name="unit_id" id="quick-item-unit">
                                                    <option value="" disabled selected>{{ __('db.Select Product Unit...') }}</option>
                                                    @foreach($lims_unit_list as $unit)
                                                        @if($unit->base_unit==null)
                                                            <option value="{{$unit->id}}">{{$unit->unit_name}} ({{ $unit->unit_code ?? $unit->unit_name }})</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#createUnitModal" title="{{ __('db.Add Unit') }}"><i class="dripicons-plus"></i></button>
                                                </div>
                                            </div>
                                            <span class="validation-msg text-danger small"></span>
                                        </div>
                                        <div data-usage-side="sale" class="col-6 form-group">
                                            <label>{{__('db.Sale Unit')}}</label>
                                            <select class="form-control selectpicker" name="sale_unit_id" id="quick-item-sale-unit">
                                                @foreach($lims_unit_list as $u)
                                                    <option value="{{ $u->id }}">{{ $u->unit_name }} ({{ $u->unit_code ?? $u->unit_name }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div data-usage-side="purchase" class="col-6 form-group">
                                            <label>{{__('db.Purchase Unit')}}</label>
                                            <select class="form-control selectpicker" name="purchase_unit_id" id="quick-item-purchase-unit">
                                                @foreach($lims_unit_list as $u)
                                                    <option value="{{ $u->id }}">{{ $u->unit_name }} ({{ $u->unit_code ?? $u->unit_name }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </x-ui.section-box>

                        <!-- BOX 2: Pricing, Margins & Tax (Emerald) -->
                        <x-ui.section-box variant="emerald" title="{{__('db.Pricing & Tax')}}" icon="pricing">
                            <div class="row">
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Product Cost')}} <span data-usage-cost-required>*</span></label>
                                        <input type="number" name="cost" id="quick-item-cost" required class="form-control" step="any" placeholder="0.00" value="0.00">
                                    </div>
                                </div>
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{ __('db.Profit Margin Type') }}</label>
                                        <select name="profit_margin_type" id="quick-item-margin-type" class="form-control selectpicker" required>
                                            <option value="percentage" selected>Percentage (%)</option>
                                            <option value="flat">Flat (₹)</option>
                                        </select>
                                    </div>
                                </div>
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Profit Margin')}}</label>
                                        <input type="number" name="profit_margin" id="quick-item-margin" class="form-control" step="0.01" placeholder="25.00" value="25.00">
                                    </div>
                                </div>
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Product Price')}} *</label>
                                        <input type="number" name="price" id="quick-item-price" required class="form-control" step="any" placeholder="0.00" value="0.00">
                                    </div>
                                </div>
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Wholesale Price')}}</label>
                                        <input type="number" name="wholesale_price" id="quick-item-wholesale" class="form-control" step="any" placeholder="0.00">
                                    </div>
                                </div>
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Daily Sale Objective')}}</label>
                                        <input type="number" name="daily_sale_objective" id="quick-item-daily-sale" class="form-control" step="any" placeholder="0">
                                    </div>
                                </div>

                                @if($quickGstRates !== null)
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>GST Rate</label>
                                        <select id="quick-item-tax" name="tax_category_id" class="form-control selectpicker" data-gst="1">
                                            <option value="" data-rate="0">Not set</option>
                                            @foreach($quickGstRates as $rateId => $gstRate)
                                                <option value="{{ $rateId }}" data-rate="{{ $gstRate['rate'] }}" {{ $gstRate['name'] === 'GST 18%' ? 'selected' : '' }}>{{ $gstRate['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>HSN / SAC</label>
                                        <input type="text" id="quick-item-hsn" name="hsn_code" class="form-control" maxlength="8" inputmode="numeric" placeholder="e.g. 3004 or 998313">
                                    </div>
                                </div>
                                @else
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Product Tax')}}</label>
                                        <select id="quick-item-tax" name="tax_id" class="selectpicker form-control">
                                            <option value="" data-rate="0">No Tax</option>
                                            @foreach($lims_tax_list as $tax)
                                                <option value="{{$tax->id}}" data-rate="{{ $tax->rate }}">{{$tax->name}} ({{$tax->rate}}%)</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @endif

                                <div class="col-6">
                                    <div class="form-group">
                                        <label>{{__('db.Tax Method')}}</label>
                                        <select name="tax_method" id="quick-item-tax-method" class="form-control selectpicker">
                                            <option value="1" selected>{{__('db.Exclusive')}}</option>
                                            <option value="2">{{__('db.Inclusive')}}</option>
                                        </select>
                                    </div>
                                </div>

                                @include('backend.product.partials.stock-policy', ['product' => null, 'colClass' => 'col-6'])

                                <!-- Warranty & Guarantee -->
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{ __('db.Warranty') }}</label>
                                        <div class="input-group">
                                            <input type="number" name="warranty" id="quick-item-warranty" min="1" class="form-control" placeholder="1">
                                            <select name="warranty_type" id="quick-item-warranty-type" class="form-control selectpicker">
                                                <option value="days">{{ __('db.Days') }}</option>
                                                <option value="months" selected>{{ __('db.Months') }}</option>
                                                <option value="years">{{ __('db.Years') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div data-usage-side="sale" class="col-6">
                                    <div class="form-group">
                                        <label>{{ __('db.Guarantee') }}</label>
                                        <div class="input-group">
                                            <input type="number" name="guarantee" id="quick-item-guarantee" min="1" class="form-control" placeholder="1">
                                            <select name="guarantee_type" id="quick-item-guarantee-type" class="form-control selectpicker">
                                                <option value="days">{{ __('db.Days') }}</option>
                                                <option value="months" selected>{{ __('db.Months') }}</option>
                                                <option value="years">{{ __('db.Years') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </x-ui.section-box>

                        <!-- BOX 3: Media, Details & SEO (Purple) -->
                        <x-ui.section-box variant="purple" title="{{__('db.Media, Details & SEO')}}" icon="media">
                            <div class="form-group">
                                <label>{{__('db.Product Image')}}</label>
                                <input type="file" name="image" id="quick-item-image" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>{{__('db.Product Details')}}</label>
                                <textarea name="product_details" id="quick-item-details" class="form-control no-tiny" rows="3" placeholder="{{ __('db.Type product details') }}"></textarea>
                            </div>

                            <div class="form-group">
                                <label>{{__('db.Product Tags')}}</label>
                                <input type="text" name="tags" id="quick-item-tags" class="form-control" placeholder="Tag1, Tag2...">
                            </div>
                        </x-ui.section-box>
                    </div>

                    <!-- Full Content Width Stripe: Inventory & Stock Control Toggle Buttons -->
                    <div class="zolo-inventory-stripe">
                        <div class="zolo-inventory-stripe-header">
                            <span class="zolo-inventory-stripe-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                            </span>
                            <h6 class="zolo-inventory-stripe-title">{{ __('db.Inventory & Stock Control') }}</h6>
                        </div>

                        <div class="zolo-inventory-stripe-alert">
                            <label for="quick-item-alert-qty">{{ __('db.Alert Quantity') }}</label>
                            <input type="number" name="alert_quantity" id="quick-item-alert-qty" class="form-control form-control-sm" step="any" placeholder="{{ __('db.Minimum alert qty') }}">
                        </div>

                        <div class="zolo-inventory-stripe-divider"></div>

                        <div class="zolo-inventory-stripe-toggles">
                            <label class="zolo-toggle-pill" data-role="amber" for="quick_chk_initial_stock" title="{{ __('db.Initial Stock') }}">
                                <input type="checkbox" name="is_initial_stock" id="quick_chk_initial_stock" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Initial Stock')}}</span>
                            </label>

                            @if(!$isOpticalProfile)
                            <label class="zolo-toggle-pill" data-role="purple" for="quick_is_variant" title="{{ __('db.This product has variant') }}">
                                <input name="is_variant" type="checkbox" id="quick_is_variant" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.This product has variant')}}</span>
                            </label>
                            @endif

                            <label data-usage-side="sale" class="zolo-toggle-pill" data-role="emerald" for="quick_is_diffPrice" title="{{ __('db.Different price by warehouse') }}">
                                <input name="is_diffPrice" type="checkbox" id="quick_is_diffPrice" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Different price by warehouse')}}</span>
                            </label>

                            @if(!$isOpticalProfile)
                            <label class="zolo-toggle-pill" data-role="amber" for="quick_is_batch" title="{{ __('db.Batch & expiry date') }}">
                                <input name="is_batch" type="checkbox" id="quick_is_batch" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Batch & expiry date')}}</span>
                            </label>

                            <label class="zolo-toggle-pill" data-role="blue" for="quick_is_imei" title="{{ __('db.IMEI / Serial numbers') }}">
                                <input name="is_imei" type="checkbox" id="quick_is_imei" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.IMEI / Serial numbers')}}</span>
                            </label>

                            <label data-usage-side="sale" class="zolo-toggle-pill" data-role="blue" for="quick_promotion" title="{{ __('db.Promotional Price') }}">
                                <input name="promotion" type="checkbox" id="quick_promotion" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Promotional Price')}}</span>
                            </label>
                            @endif

                            <label data-usage-side="sale" class="zolo-toggle-pill" data-role="purple" for="quick_chk_featured" title="{{ __('db.Featured') }}">
                                <input type="checkbox" name="featured" id="quick_chk_featured" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Featured')}}</span>
                            </label>

                            @if(!$isOpticalProfile)
                            <label class="zolo-toggle-pill" data-role="emerald" for="quick_chk_embeded" title="{{ __('db.Embedded Barcode') }}">
                                <input type="checkbox" name="is_embeded" id="quick_chk_embeded" value="1">
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Embedded Barcode')}}</span>
                            </label>
                            @endif

                            <label id="quick-optical-option" class="zolo-toggle-pill {{ $isOpticalProfile ? 'is-active' : '' }}" data-role="purple" for="quick-is-optical" title="Optical Lens Specifications">
                                <input name="is_optical" type="checkbox" id="quick-is-optical" value="1" {{ $isOpticalProfile ? 'checked' : '' }}>
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>Optical / Lens Rx</span>
                            </label>

                            <label class="zolo-toggle-pill is-active" data-role="purple" for="quick_is_online" title="{{ __('db.Sell Online') }}">
                                <input name="is_online" type="checkbox" id="quick_is_online" value="1" checked>
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.Sell Online')}}</span>
                            </label>

                            <label class="zolo-toggle-pill is-active" data-role="emerald" for="quick_in_stock" title="{{ __('db.In Stock') }}">
                                <input name="in_stock" type="checkbox" id="quick_in_stock" value="1" checked>
                                <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                <span>{{__('db.In Stock')}}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Optical Lens Specifications Card (Controlled by Optical / Lens Rx toggle) -->
                    <div class="zolo-product-grid mt-2" id="quick-optical-container" style="{{ $isOpticalProfile ? '' : 'display:none;' }}">
                        <div id="quick-optical-section" class="w-100">
                            <x-ui.section-box variant="purple" title="Optical Lens Specifications" icon="view-thumb">
                                <div class="row">
                                    <div class="col-3">
                                        <div class="form-group mb-2">
                                            <label class="small mb-1 font-weight-bold">Base Curve</label>
                                            <input type="text" name="base" class="form-control form-control-sm" id="quick-item-base" placeholder="e.g. 2.00, 4.00">
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="form-group mb-2">
                                            <label class="small mb-1 font-weight-bold">Addition (ADD)</label>
                                            <input type="text" name="addition" class="form-control form-control-sm" id="quick-item-addition" placeholder="e.g. +0.75, +1.50">
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="form-group mb-2">
                                            <label class="small mb-1 font-weight-bold">Eye (L / R / Pair)</label>
                                            <select name="lr" class="form-control form-control-sm selectpicker" id="quick-item-lr" title="Select Eye...">
                                                <option value="L">Left (L)</option>
                                                <option value="R">Right (R)</option>
                                                <option value="Pair">Pair</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="form-group mb-2">
                                            <label class="small mb-1 font-weight-bold">Diameter (DIA mm)</label>
                                            <input type="text" name="dia" class="form-control form-control-sm" id="quick-item-dia" placeholder="e.g. 65, 70, 75">
                                        </div>
                                    </div>
                                    <div class="col-12 mt-2 pt-2 border-top d-flex align-items-center justify-content-between small text-muted">
                                        <span><i class="dripicons-view-thumb mr-1 text-primary"></i> Lens Type: <strong id="quick-optical-type-label" class="text-primary font-weight-bold">None selected</strong></span>
                                        <span class="text-secondary small">Optical Lens Design</span>
                                    </div>
                                </div>
                            </x-ui.section-box>
                        </div>
                    </div>

                    <!-- Extended Conditional Details Container (4-column grid mirroring dedicated page) -->
                    <div class="product-extended-details-container mt-2 d-none">
                        <!-- Warehouse Initial Stock Section -->
                        <div id="quick-initial-stock-section" class="d-none u-d-none">
                            <x-ui.section-box variant="amber" title="{{__('db.Warehouse Initial Stock')}}" icon="inventory">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>{{__('db.Warehouse')}}</th>
                                                <th>{{__('db.qty')}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($lims_warehouse_list as $warehouse)
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="stock_warehouse_id[]" value="{{$warehouse->id}}">
                                                    {{$warehouse->name}}
                                                </td>
                                                <td><input type="number" name="stock[]" min="0" class="form-control form-control-sm"></td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-ui.section-box>
                        </div>

                        <!-- Warehouse Differential Price Section -->
                        <div data-usage-side="sale" id="quick-diffPrice-section" class="d-none u-d-none">
                            <x-ui.section-box variant="emerald" title="{{__('db.Different Warehouse Price')}}" icon="pricing">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>{{__('db.Warehouse')}}</th>
                                                <th>{{__('db.Price')}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($lims_warehouse_list as $warehouse)
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="warehouse_id[]" value="{{$warehouse->id}}">
                                                    {{$warehouse->name}}
                                                </td>
                                                <td><input type="number" name="diff_price[]" class="form-control form-control-sm" placeholder="0.00"></td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-ui.section-box>
                        </div>

                        <!-- Product Variants Section -->
                        <div id="quick-variant-section" class="d-none u-d-none">
                            <x-ui.section-box variant="purple" title="{{__('db.Product Variants')}}" icon="tag">
                                <div class="row align-items-end mb-2">
                                    <div class="col-md-4 form-group mb-0">
                                        <label class="font-weight-bold">{{__('db.Option')}} *</label>
                                        <input type="text" name="variant_option[]" class="form-control form-control-sm" placeholder="e.g. Size, Color">
                                    </div>
                                    <div class="col-md-7 form-group mb-0">
                                        <label class="font-weight-bold">{{__('db.Value')}} *</label>
                                        <input type="text" name="variant_value[]" class="form-control form-control-sm" placeholder="e.g. S, M, L or Red, Blue">
                                    </div>
                                </div>
                            </x-ui.section-box>
                        </div>

                        <!-- Warehouse Batch & Expiry Date Section -->
                        <div id="quick-batch-section" class="d-none u-d-none">
                            <x-ui.section-box variant="amber" title="{{__('db.Batch & Expiry Date')}}" icon="inventory">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>{{__('db.Warehouse')}}</th>
                                                <th>{{__('db.Batch No')}}</th>
                                                <th>{{__('db.Expired Date')}}</th>
                                                <th>{{__('db.Initial Qty')}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($lims_warehouse_list as $warehouse)
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="batch_warehouse_id[]" value="{{$warehouse->id}}">
                                                    {{$warehouse->name}}
                                                </td>
                                                <td><input type="text" name="batch_no[]" class="form-control form-control-sm" placeholder="BATCH-001"></td>
                                                <td><input type="date" name="expired_date[]" class="form-control form-control-sm"></td>
                                                <td><input type="number" name="batch_qty[]" min="0" class="form-control form-control-sm" placeholder="0"></td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-ui.section-box>
                        </div>

                        <!-- Warehouse IMEI / Serial Numbers Section -->
                        <div id="quick-imei-section" class="d-none u-d-none">
                            <x-ui.section-box variant="blue" title="{{__('db.IMEI / Serial numbers')}}" icon="tag">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>{{__('db.Warehouse')}}</th>
                                                <th>{{__('db.IMEI or Serial Numbers')}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($lims_warehouse_list as $warehouse)
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="imei_warehouse_id[]" value="{{$warehouse->id}}">
                                                    {{$warehouse->name}}
                                                </td>
                                                <td><input type="text" name="imei_number[]" class="form-control form-control-sm" placeholder="SN12345, SN12346..."></td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-ui.section-box>
                        </div>

                        <!-- Promotional Price Section -->
                        <div data-usage-side="sale" id="quick-promo-container" class="d-none u-d-none">
                            <x-ui.section-box variant="blue" title="{{__('db.Promotional Price')}}" icon="pricing">
                                <div class="zolo-promo-vertical-form">
                                    <div class="form-group mb-2">
                                        <label class="font-weight-bold">{{__('db.Promotional Price')}}</label>
                                        <input type="number" name="promotion_price" class="form-control form-control-sm" step="any" placeholder="0.00" />
                                    </div>
                                    <div class="form-group mb-2">
                                        <label class="font-weight-bold">{{__('db.Promotion Starts')}}</label>
                                        <input type="date" name="starting_date" class="form-control form-control-sm" />
                                    </div>
                                    <div class="form-group mb-0">
                                        <label class="font-weight-bold">{{__('db.Promotion Ends')}}</label>
                                        <input type="date" name="last_date" class="form-control form-control-sm" />
                                    </div>
                                </div>
                            </x-ui.section-box>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex align-items-center justify-content-between">
                    <button type="button" class="btn btn-sm btn-secondary comm-bottom-btn" data-dismiss="modal">{{ __('db.Cancel') }}</button>
                    <button type="submit" class="btn btn-sm btn-primary comm-bottom-btn comm-bottom-btn-primary u-bg-primary u-bc-primary u-fw-600" id="btn-quick-create-submit">
                        <i class="dripicons-checkmark"></i> 💾 {{ __('db.Save & Add to Voucher') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#quick-is-optical').on('change', function () {
        var isChecked = $(this).is(':checked');
        if (isChecked) {
            $('#quick-optical-container').show(200);
            $('#quick-product-type-col').show(200);
            $('#quick-brand-col').removeClass('col-6').addClass('col-md-4 col-12');
            $('#quick-category-col').removeClass('col-6').addClass('col-md-4 col-12');
            $('#quick-optical-option').addClass('is-active');
        } else {
            $('#quick-optical-container').hide(200);
            $('#quick-product-type-col').hide(200);
            $('#quick-brand-col').removeClass('col-md-4 col-12').addClass('col-6');
            $('#quick-category-col').removeClass('col-md-4 col-12').addClass('col-6');
            $('#quick-optical-option').removeClass('is-active');
        }
    });

    $('#quick-item-product-type').on('change', function() {
        var txt = $(this).find('option:selected').text();
        $('#quick-optical-type-label').text(txt && $(this).val() ? txt : 'None selected');
    });
    if ($('#quick-item-product-type').val()) {
        $('#quick-optical-type-label').text($('#quick-item-product-type option:selected').text());
    }
});
</script>
