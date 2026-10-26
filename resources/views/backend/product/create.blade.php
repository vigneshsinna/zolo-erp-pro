@extends('backend.layout.main')
@section('rich-editor', '1')
@section('image-upload', '1')

@if(in_array('ecommerce',explode(',',$general_setting->modules)) || in_array('restaurant',explode(',',$general_setting->modules)))
@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/shared-product-create.css') }}">
@endpush
@endif

@section('content')
<x-product-command-bar active="products"></x-product-command-bar>
<section class="forms">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card product-single-screen-card">
                    <div class="card-header d-flex align-items-center justify-content-between py-2">
                        <div class="d-flex align-items-center gap-2">
                            <h4 class="mb-0 font-weight-bold u-fs-1_05rem">{{__('db.add_product')}}</h4>
                            <span class="text-muted small ml-2 font-italic">{{__('db.The field labels marked with * are required input fields')}}.</span>
                        </div>
                        <div class="card-header-actions d-flex align-items-center gap-2">
                            <a href="{{route('products.index')}}" class="btn btn-sm btn-outline-secondary">{{__('db.Cancel')}}</a>
                            <x-ui.button type="submit" variant="primary" size="sm" id="submit-btn" icon="dripicons-checkmark">{{__('db.add_product')}}</x-ui.button>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <form id="product-form">
                            <div class="zolo-product-grid">

                                <!-- BOX 1: Primary Identity & Categorization (Blue) -->
                                <x-ui.section-box variant="blue" title="{{__('db.Product Details')}}" icon="box">
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Product Type')}} *</label>
                                                <select name="type" required class="form-control selectpicker" id="type">
                                                    <option value="standard">Standard</option>
                                                    <option value="combo">Combo</option>
                                                    <option value="digital">Digital</option>
                                                    <option value="service">Service</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Barcode Symbology')}} *</label>
                                                <select name="barcode_symbology" required class="form-control selectpicker">
                                                    <option value="C128">Code 128</option>
                                                    <option value="C39">Code 39</option>
                                                    <option value="UPCA">UPC-A</option>
                                                    <option value="UPCE">UPC-E</option>
                                                    <option value="EAN8">EAN-8</option>
                                                    <option value="EAN13">EAN-13</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group">
                                                <label>{{__('db.Product Name')}} *</label>
                                                <input type="text" name="name" class="form-control" id="name" aria-describedby="name" required placeholder="{{ __('db.Type product name') }}">
                                                <span class="validation-msg text-danger small" id="name-error"></span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group">
                                                <label>{{__('db.Product Code')}} *</label>
                                                <div class="input-group">
                                                    <input type="text" name="code" class="form-control" id="code" aria-describedby="code" required placeholder="{{ __('db.Enter or generate code') }}">
                                                    <div class="input-group-append">
                                                        <button id="genbutton" type="button" class="btn btn-sm btn-default" title="{{__('db.Generate')}}"><i class="fa fa-refresh"></i></button>
                                                    </div>
                                                </div>
                                                <span class="validation-msg text-danger small" id="code-error"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Brand')}}</label>
                                                <div class="input-group">
                                                    <select name="brand_id" class="selectpicker form-control" data-live-search="true" data-live-search-style="begins" title="{{ __('db.Select Brand...') }}">
                                                        @foreach($lims_brand_list as $brand)
                                                            <option value="{{$brand->id}}">{{$brand->title}}</option>
                                                        @endforeach
                                                    </select>
                                                    <div class="input-group-append">
                                                        <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#addBrand" title="{{ __('db.Add Brand') }}"><i class="dripicons-plus"></i></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.category')}} *</label>
                                                <div class="input-group">
                                                    <select name="category_id" required class="selectpicker form-control" data-live-search="true" data-live-search-style="begins" title="{{ __('db.Select Category...') }}">
                                                        @foreach($lims_category_list as $category)
                                                            <option value="{{$category->id}}">{{$category->name}}</option>
                                                        @endforeach
                                                    </select>
                                                    <div class="input-group-append">
                                                        <button type="button" class="btn btn-default btn-sm category-model" data-toggle="modal" data-target="#category-modal" title="{{ __('db.Add Category') }}"><i class="dripicons-plus"></i></button>
                                                    </div>
                                                </div>
                                                <span class="validation-msg text-danger small"></span>
                                            </div>
                                        </div>
                                        <div id="unit" class="col-12">
                                            <div class="row">
                                                <div class="col-12 form-group">
                                                    <label>{{__('db.Product Unit')}} *</label>
                                                    <div class="input-group">
                                                        <select required class="form-control selectpicker" name="unit_id">
                                                            <option value="" disabled selected>{{ __('db.Select Product Unit...') }}</option>
                                                            @foreach($lims_unit_list as $unit)
                                                                @if($unit->base_unit==null)
                                                                    <option value="{{$unit->id}}">{{$unit->unit_name}}</option>
                                                                @endif
                                                            @endforeach
                                                        </select>
                                                        <div class="input-group-append">
                                                            <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#createUnitModal" title="{{ __('db.Add Unit') }}"><i class="dripicons-plus"></i></button>
                                                        </div>
                                                    </div>
                                                    <span class="validation-msg text-danger small"></span>
                                                </div>
                                                <div class="col-6 form-group">
                                                    <label>{{__('db.Sale Unit')}}</label>
                                                    <select class="form-control selectpicker" name="sale_unit_id"></select>
                                                </div>
                                                <div class="col-6 form-group">
                                                    <label>{{__('db.Purchase Unit')}}</label>
                                                    <select class="form-control selectpicker" name="purchase_unit_id"></select>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="digital" class="col-12">
                                            <div class="form-group">
                                                <label>{{__('db.Attach File')}} *</label>
                                                <input type="file" id="file" name="file" class="form-control">
                                                <span class="validation-msg text-danger small"></span>
                                            </div>
                                        </div>
                                    </div>
                                </x-ui.section-box>

                                <!-- BOX 2: Pricing, Margins & Tax (Emerald) -->
                                <x-ui.section-box variant="emerald" title="{{__('db.Pricing & Tax')}}" icon="pricing">
                                    <div class="row">
                                        <div id="cost" class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Product Cost')}} *</label>
                                                <input type="number" name="cost" required class="form-control" step="any" placeholder="0.00">
                                                <div class="alert alert-warning very-small-text d-none p-1 position-absolute" id="product-cost-warning">
                                                    Cost must be higher than 0!
                                                </div>
                                                <span class="validation-msg text-danger small"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{ __('db.Profit Margin Type') }}</label>
                                                <select name="profit_margin_type" class="form-control selectpicker" required>
                                                    <option value="percentage">Percentage (%)</option>
                                                    <option value="flat">Flat</option>
                                                </select>
                                                <span class="validation-msg text-danger small"></span>
                                            </div>
                                        </div>
                                        <div id="profit_margin" class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Profit Margin')}}</label>
                                                <input type="number" name="profit_margin" required class="form-control" step="0.01" placeholder="0.00">
                                                <span class="validation-msg text-danger small"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Product Price')}} *</label>
                                                <input type="number" name="price" required class="form-control" step="any" placeholder="0.00">
                                                <div class="alert alert-warning very-small-text d-none p-1 position-absolute" id="product-price-warning">
                                                    Price must be higher than Cost!
                                                </div>
                                                <span class="validation-msg text-danger small"></span>
                                            </div>
                                            <input type="hidden" name="qty" value="{{number_format(0, $general_setting->decimal, '.', '')}}">
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Wholesale Price')}}</label>
                                                <input type="number" name="wholesale_price" class="form-control" step="any" placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Daily Sale Objective')}} <i class="dripicons-question" data-toggle="tooltip" title="{{__('db.Minimum qty which must be sold in a day')}}"></i></label>
                                                <input type="number" name="daily_sale_objective" class="form-control" step="any" placeholder="0">
                                            </div>
                                        </div>

                                        @if(\App\Services\Tax\GstSetupService::usesGst())
                                            @include('backend.product.partials.gst-fields', ['product' => null])
                                        @else
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Product Tax')}}</label>
                                                <div class="input-group">
                                                    <select name="tax_id" class="selectpicker form-control">
                                                        <option value="">No Tax</option>
                                                        @foreach($lims_tax_list as $tax)
                                                            <option value="{{$tax->id}}">{{$tax->name}}</option>
                                                        @endforeach
                                                    </select>
                                                    <div class="input-group-append">
                                                        <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#addTax"><i class="dripicons-plus"></i></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        @endif

                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{__('db.Tax Method')}} <i class="dripicons-question" data-toggle="tooltip" title="{{__('db.Exclusive: Poduct price = Actual product price + Tax Inclusive: Actual product price = Product price - Tax')}}"></i></label>
                                                <select name="tax_method" class="form-control selectpicker">
                                                    <option value="1">{{__('db.Exclusive')}}</option>
                                                    <option value="2">{{__('db.Inclusive')}}</option>
                                                </select>
                                            </div>
                                        </div>

                                        @include('backend.product.partials.stock-policy', ['product' => null, 'colClass' => 'col-6'])

                                        <!-- Warranty and Guarantee -->
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{ __('db.Warranty') }}</label>
                                                <div class="input-group">
                                                    <input type="number" name="warranty" min="1" class="form-control" placeholder="1">
                                                    <select name="warranty_type" class="form-control selectpicker">
                                                        <option value="days">{{ __('db.Days') }}</option>
                                                        <option value="months" selected>{{ __('db.Months') }}</option>
                                                        <option value="years">{{ __('db.Years') }}</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label>{{ __('db.Guarantee') }}</label>
                                                <div class="input-group">
                                                    <input type="number" name="guarantee" min="1" class="form-control" placeholder="1">
                                                    <select name="guarantee_type" class="form-control selectpicker">
                                                        <option value="days">{{ __('db.Days') }}</option>
                                                        <option value="months" selected>{{ __('db.Months') }}</option>
                                                        <option value="years">{{ __('db.Years') }}</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        @foreach($custom_fields as $field)
                                        @if(!$field->is_admin || \Auth::user()->role_id == 1)
                                            <div class="col-12">
                                                <div class="form-group">
                                                    <label>{{$field->name}}</label>
                                                    @if($field->type == 'text')
                                                        <input type="text" name="{{str_replace(' ', '_', strtolower($field->name))}}" value="{{$field->default_value}}" class="form-control" @if($field->is_required){{'required'}}@endif>
                                                    @elseif($field->type == 'number')
                                                        <input type="number" name="{{str_replace(' ', '_', strtolower($field->name))}}" value="{{$field->default_value}}" class="form-control" @if($field->is_required){{'required'}}@endif>
                                                    @elseif($field->type == 'textarea')
                                                        <textarea rows="2" name="{{str_replace(' ', '_', strtolower($field->name))}}" value="{{$field->default_value}}" class="form-control" @if($field->is_required){{'required'}}@endif></textarea>
                                                    @elseif($field->type == 'select')
                                                        <?php $option_values = explode(",", $field->option_value); ?>
                                                        <select class="form-control selectpicker" name="{{str_replace(' ', '_', strtolower($field->name))}}" @if($field->is_required){{'required'}}@endif>
                                                            @foreach($option_values as $value)
                                                                <option value="{{$value}}" @if($value == $field->default_value){{'selected'}}@endif>{{$value}}</option>
                                                            @endforeach
                                                        </select>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                        @endforeach
                                    </div>
                                </x-ui.section-box>

                                <!-- BOX 3: Media, Details & SEO (Purple) -->
                                <x-ui.section-box variant="purple" title="{{__('db.Media, Details & SEO')}}" icon="media">
                                    <div class="form-group">
                                        <label>{{__('db.Product Image')}} <i class="dripicons-question" data-toggle="tooltip" title="{{__('db.You can upload multiple image Only jpeg, jpg, png, gif file can be uploaded First image will be base image')}}"></i></label>
                                        <div id="imageUpload" class="dropzone"></div>
                                        <span class="validation-msg text-danger small" id="image-error"></span>
                                    </div>

                                    <div class="form-group">
                                        <label>{{__('db.Product Details')}}</label>
                                        <textarea name="product_details" class="form-control no-tiny" rows="2" placeholder="{{ __('db.Type product details') }}"></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>{{__('db.Product Tags')}}</label>
                                        <input type="text" name="tags" class="form-control" placeholder="Tag1, Tag2...">
                                        <span class="validation-msg text-danger small" id="tags-error"></span>
                                    </div>

                                    @if(in_array('ecommerce',explode(',',$general_setting->modules)) || in_array('restaurant',explode(',',$general_setting->modules)))
                                    <div class="row">
                                        <div class="col-6 form-group">
                                            <label>{{ __('Meta Title') }} *</label>
                                            <input type="text" name="meta_title" class="form-control" placeholder="{{ __('db.Meta Title') }}">
                                        </div>
                                        <div class="col-6 form-group">
                                            <label>{{ __('Meta Description') }} *</label>
                                            <input type="text" name="meta_description" class="form-control" placeholder="{{ __('db.Meta Description') }}">
                                        </div>
                                        <div class="col-12 form-group related-section">
                                            <label>{{__('db.Related Products')}}</label>
                                            <input type="text" id="search_products" class="form-control" placeholder="{{ __('db.Search related products...') }}">
                                            <div class="search_result"></div>
                                            <div class="selected_items mt-1"></div>
                                            <textarea class="selected_ids hidden no-tiny" name="products" style="display:none"></textarea>
                                        </div>
                                    </div>
                                    @endif

                                    @if(in_array('restaurant',explode(',',$general_setting->modules)))
                                    <div class="row">
                                        <div class="col-12 form-group extra-section">
                                            <label>{{__('db.Extras')}}</label>
                                            <input type="text" id="search_addons" class="form-control" placeholder="{{ __('db.Search extras...') }}">
                                            <div class="search_result_addon"></div>
                                            <div class="selected_addons mt-1"></div>
                                            <textarea class="selected_addon_ids hidden no-tiny" name="extras" style="display:none"></textarea>
                                        </div>
                                        <div class="col-6 form-group">
                                            <label>{{__('db.Kitchen')}}</label>
                                            <select id="kitchen_id" name="kitchen_id" class="selectpicker form-control" title="Select kitchen...">
                                                @foreach($kitchen_list as $kitchen)
                                                <option value="{{$kitchen->id}}">{{$kitchen->name}}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6 form-group">
                                            <label>{{__('db.Menu Type')}}</label>
                                            <select required id="menu_type" name="menu_type[]" class="selectpicker form-control" multiple>
                                                @foreach($menu_type_list as $menu_type)
                                                <option value="{{$menu_type->id}}">{{$menu_type->name}}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @endif
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
                                    <label for="alert_quantity">{{ __('db.Alert Quantity') }}</label>
                                    <input type="number" name="alert_quantity" id="alert_quantity" class="form-control form-control-sm" step="any" placeholder="{{ __('db.Minimum alert qty') }}">
                                </div>

                                <div class="zolo-inventory-stripe-divider"></div>

                                <div class="zolo-inventory-stripe-toggles">
                                    <label id="stock-section" class="zolo-toggle-pill" data-role="amber" for="chk_initial_stock" title="{{ __('db.Initial Stock') }}">
                                        <input type="checkbox" name="is_initial_stock" id="chk_initial_stock" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Initial Stock')}}</span>
                                    </label>

                                    <label id="variant-option" class="zolo-toggle-pill" data-role="purple" for="is-variant" title="{{ __('db.This product has variant') }}">
                                        <input name="is_variant" type="checkbox" id="is-variant" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.This product has variant')}}</span>
                                    </label>

                                    <label id="diffPrice-option" class="zolo-toggle-pill" data-role="emerald" for="is-diffPrice" title="{{ __('db.Different price by warehouse') }}">
                                        <input name="is_diffPrice" type="checkbox" id="is-diffPrice" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Different price by warehouse')}}</span>
                                    </label>

                                    <label id="batch-option" class="zolo-toggle-pill" data-role="amber" for="is-batch" title="{{ __('db.Batch & expiry date') }}">
                                        <input name="is_batch" type="checkbox" id="is-batch" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Batch & expiry date')}}</span>
                                    </label>

                                    <label id="imei-option" class="zolo-toggle-pill" data-role="blue" for="is-imei" title="{{ __('db.IMEI / Serial numbers') }}">
                                        <input name="is_imei" type="checkbox" id="is-imei" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.IMEI / Serial numbers')}}</span>
                                    </label>

                                    <label class="zolo-toggle-pill" data-role="blue" for="promotion" title="{{ __('db.Promotional Price') }}">
                                        <input name="promotion" type="checkbox" id="promotion" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Promotional Price')}}</span>
                                    </label>

                                    <label id="featured" class="zolo-toggle-pill" data-role="purple" for="chk_featured" title="{{ __('db.Featured') }}">
                                        <input type="checkbox" name="featured" id="chk_featured" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Featured')}}</span>
                                    </label>

                                    <label class="zolo-toggle-pill" data-role="emerald" for="chk_embeded" title="{{ __('db.Embedded Barcode') }}">
                                        <input type="checkbox" name="is_embeded" id="chk_embeded" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Embedded Barcode')}}</span>
                                    </label>

                                    @if(in_array('ecommerce',explode(',',$general_setting->modules)) || in_array('restaurant',explode(',',$general_setting->modules)))
                                    <label class="zolo-toggle-pill is-active" data-role="purple" for="is_online" title="{{ __('db.Sell Online') }}">
                                        <input name="is_online" type="checkbox" id="is_online" value="1" checked>
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.Sell Online')}}</span>
                                    </label>
                                    @endif

                                    @if(in_array('ecommerce',explode(',',$general_setting->modules)))
                                    <label class="zolo-toggle-pill is-active" data-role="emerald" for="in_stock" title="{{ __('db.In Stock') }}">
                                        <input name="in_stock" type="checkbox" id="in_stock" value="1" checked>
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.In Stock')}}</span>
                                    </label>
                                    @endif

                                    @if (\Schema::hasColumn('products', 'woocommerce_product_id'))
                                    <label class="zolo-toggle-pill" data-role="rose" for="is_sync_disable" title="Disable WooCommerce">
                                        <input name="is_sync_disable" type="checkbox" id="is_sync_disable" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>Disable WooCommerce</span>
                                    </label>
                                    @endif

                                    @if(in_array('restaurant',explode(',',$general_setting->modules)))
                                    <label class="zolo-toggle-pill" data-role="blue" for="is_addon" title="{{ __('db.This is topping') }}">
                                        <input name="is_addon" type="checkbox" id="is_addon" value="1">
                                        <span class="zolo-switch-track"><span class="zolo-switch-thumb"></span></span>
                                        <span>{{__('db.This is topping')}}</span>
                                    </label>
                                    @endif
                                </div>
                            </div>

                            <!-- Extended Conditional Details Container (4-column grid mirroring top primary boxes) -->
                            <div class="product-extended-details-container mt-2">
                                <!-- Warehouse Initial Stock Section (1 grid column) -->
                                <div id="initial-stock-section">
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

                                <!-- Warehouse Differential Price Section (1 grid column) -->
                                <div id="diffPrice-section">
                                    <x-ui.section-box variant="emerald" title="{{__('db.Different Warehouse Price')}}" icon="pricing">
                                        <div class="table-responsive">
                                            <table id="diffPrice-table" class="table table-hover table-sm">
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
                                <div id="variant-section">
                                    <x-ui.section-box variant="purple" title="{{__('db.Product Variants')}}" icon="tag">
                                        <div id="variant-input-section">
                                            <div class="row align-items-end">
                                                <div class="col-md-4 form-group mb-2">
                                                    <label class="font-weight-bold">{{__('db.Option')}} * <i class="dripicons-question" data-toggle="tooltip" title="{{__('db.Size, Color etc')}}"></i></label>
                                                    <input type="text" name="variant_option[]" class="form-control form-control-sm variant-field" placeholder="{{ __('db.Size, Color etc') }}">
                                                </div>
                                                <div class="col-md-7 form-group mb-2">
                                                    <label class="font-weight-bold">{{__('db.Value')}} * <i class="dripicons-question" data-toggle="tooltip" title="{{__('db.Enter variant value seperated by comma')}}"></i></label>
                                                    <input type="text" name="variant_value[]" class="type-variant form-control form-control-sm variant-field">
                                                </div>
                                                <div class="col-md-1 form-group mb-2 text-center">
                                                    <button type="button" class="delVarOption btn btn-danger btn-sm" title="{{__('db.delete')}}"><i class="dripicons-cross"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="my-2">
                                            <button type="button" class="btn btn-info btn-xs add-more-variant"><i class="dripicons-plus"></i> {{__('db.Add More Variant')}}</button>
                                        </div>
                                        <div class="table-responsive">
                                            <table id="variant-table" class="table table-hover table-sm variant-list mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>{{__('db.name')}}</th>
                                                        <th>{{__('db.Item Code')}}</th>
                                                        <th>{{__('db.Additional Cost')}}</th>
                                                        <th>{{__('db.Additional Price')}}</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </x-ui.section-box>
                                </div>

                                <!-- Warehouse Batch & Expiry Date Section -->
                                <div id="batch-section">
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
                                                        <td>
                                                            <div class="input-group">
                                                                <div class="input-group-prepend">
                                                                    <div class="input-group-text"><i class="dripicons-calendar"></i></div>
                                                                </div>
                                                                <input type="text" name="expired_date[]" class="form-control form-control-sm datepicker" placeholder="dd-mm-yyyy">
                                                            </div>
                                                        </td>
                                                        <td><input type="number" name="batch_qty[]" min="0" class="form-control form-control-sm" placeholder="0"></td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </x-ui.section-box>
                                </div>

                                <!-- Warehouse IMEI / Serial Numbers Section -->
                                <div id="imei-section">
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
                                <div id="promo-container">
                                    <x-ui.section-box variant="blue" title="{{__('db.Promotional Price')}}" icon="pricing">
                                        <div class="zolo-promo-vertical-form">
                                            <div class="form-group mb-2" id="promotion_price">
                                                <label class="font-weight-bold">{{__('db.Promotional Price')}}</label>
                                                <input type="number" name="promotion_price" class="form-control form-control-sm" step="any" placeholder="0.00" />
                                            </div>
                                            <div class="form-group mb-2" id="start_date">
                                                <label class="font-weight-bold">{{__('db.Promotion Starts')}}</label>
                                                <div class="input-group">
                                                    <div class="input-group-prepend">
                                                        <div class="input-group-text"><i class="dripicons-calendar"></i></div>
                                                    </div>
                                                    <input type="text" name="starting_date" id="starting_date" class="form-control form-control-sm" />
                                                </div>
                                            </div>
                                            <div class="form-group mb-0" id="last_date">
                                                <label class="font-weight-bold">{{__('db.Promotion Ends')}}</label>
                                                <div class="input-group">
                                                    <div class="input-group-prepend">
                                                        <div class="input-group-text"><i class="dripicons-calendar"></i></div>
                                                    </div>
                                                    <input type="text" name="last_date" id="ending_date" class="form-control form-control-sm" />
                                                </div>
                                            </div>
                                        </div>
                                    </x-ui.section-box>
                                </div>

                                <!-- Combo Products Section -->
                                <div id="combo">
                                    <x-ui.section-box variant="blue" title="{{__('db.Combo Products')}}" icon="box">
                                        <div class="search-box input-group mb-2">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-barcode"></i></span>
                                            </div>
                                            <input type="text" name="product_code_name" id="lims_productcodeSearch" placeholder="{{ __('db.Please type product code and select') }}" class="form-control form-control-sm" />
                                        </div>
                                        <div class="table-responsive">
                                            <table id="myTable" class="table table-hover order-list mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>{{__('db.product')}}</th>
                                                        <th>{{__('db.Wastage Percent')}}</th>
                                                        <th>{{__('db.Quantity')}}</th>
                                                        <th>{{__('db.Unit Cost')}}</th>
                                                        <th>{{__('db.Unit Price')}}</th>
                                                        <th>{{__('db.Sub total')}}</th>
                                                        <th class="text-center"><i class="dripicons-trash"></i></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="combo_product_list_table"></tbody>
                                            </table>
                                        </div>
                                    </x-ui.section-box>
                                </div>
                            </div>

                            <!-- Bottom Action Bar -->
                            <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top">
                                <span class="text-muted small"><i class="dripicons-information mr-1"></i> {{ __('db.The field labels marked with * are required input fields') }}.</span>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{route('products.index')}}" class="btn btn-sm btn-outline-secondary">{{__('db.Cancel')}}</a>
                                    <button type="button" class="btn btn-sm btn-primary zolo-btn-primary" onclick="$('#submit-btn').click();">
                                        <i class="dripicons-checkmark mr-1"></i> {{__('db.add_product')}}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Brand Create Modal Start -->
    <div id="addBrand" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
        <div role="document" class="modal-dialog">
          <div class="modal-content">
            <form id="brand-form">
            <div class="modal-header">
              <h5 id="exampleModalLabel" class="modal-title">{{__('db.Add Brand')}}</h5>
              <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
            </div>
            <div class="modal-body">
              <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
                <div class="form-group">
                    <label>{{__('db.Title')}} *</label>
                    <input type="text" name="title" class="form-control" placeholder="{{ __('db.Type brand title') }}" required>
                </div>
                <div class="form-group">
                    <label>{{__('db.Image')}}</label>
                    {{-- {{Form::file('image', array('class' => 'form-control'))}} --}}
                    <input type="file" name="image" class="form-control">
                </div>
                @if(in_array('ecommerce',explode(',',$general_setting->modules)) || in_array('restaurant',explode(',',$general_setting->modules)))
                <div class="row">
                    <div class="col-md-12 mt-3">
                        <h6><strong>{{ __('For SEO') }}</strong></h6>
                        <hr>
                    </div>
                    <div class="col-md-12 form-group">
                        <label>{{ __('Meta Title') }}</label>
                        {{Form::text('page_title',null,array('class' => 'form-control', 'placeholder' => __('db.Meta Title')))}}
                    </div>
                    <div class="col-md-12 form-group">
                        <label>{{ __('Meta Description') }}</label>
                        {{Form::text('short_description',null,array('class' => 'form-control', 'placeholder' => __('db.Meta Description')))}}
                    </div>
                </div>
                @endif
                <div class="form-group">
                    <input type="hidden" name="ajax" value="1">
                    <button type="button" class="btn btn-primary brand-submit-btn">{{__('db.submit')}}</button>
                </div>
            </div>
            </form>
          </div>
        </div>
    </div>
    <!-- Brand Create Modal End -->
    <!-- Tax Create Modal Start -->
    <div id="addTax" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
        <div role="document" class="modal-dialog">
            <div class="modal-content">
                {!! Form::open(['route' => 'tax.store', 'method' => 'post', 'id' => 'tax-form']) !!}
                <div class="modal-header">
                    <h5 id="exampleModalLabel" class="modal-title">{{__('db.Add Tax')}}</h5>
                    <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
                </div>
                <div class="modal-body">
                    <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
                    <form>
                        <div class="form-group">
                        <label>{{__('db.Tax Name')}} *</label>
                        {{Form::text('name',null,array('required' => 'required', 'class' => 'form-control'))}}
                        </div>
                        <div class="form-group">
                            <label>{{__('db.Rate')}}(%) *</label>
                            {{Form::number('rate',null,array('required' => 'required', 'class' => 'form-control', 'step' => 'any'))}}
                        </div>
                        <input type="hidden" name="ajax" value="1">
                        <button type="button" class="btn btn-primary tax-submit-btn">{{__('db.submit')}}</button>
                    </form>
                </div>
            {{ Form::close() }}
            </div>
        </div>
    </div>
    <!-- Tax Create Modal End -->

    <!-- Unit Create Modal Start -->
    @include('backend.unit.add_unit_modal')
</section>
@endsection
@push('scripts')
<script type="text/javascript">

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $("#is_addon").on('click', function(){
        if($("#is_addon").prop('checked') == false){
            $('.extra-section,.related-section').css('display','block');
        }else{
            $('.extra-section,.related-section').css('display','none');
        }
    })

    @if(in_array('ecommerce',explode(',',$general_setting->modules)) || in_array('restaurant',explode(',',$general_setting->modules)))
    $('#search_products').on('input', function() {
        var item = $(this).val();
        $('.search_result').html('<div class="d-block text-center"><div class="spinner-border text-secondary" role="status"><span class="sr-only">Loading...</span></div></div>');

        if(item.length >= 3){
            $.ajax({
                type: "get",
                url: "{{url('search')}}/" + item,
                success: function(data) {
                    $('.search_result').html('').css('height','200px');
                    $.each(data,function(key, value){
                        var image = value.image.split(',');
                        $('.search_result').append('<div data-id="'+value.id+'"><img src="{{asset("images/product/small/")}}/'+image[0]+'"><h4>'+value.name+'</h4><i class="dripicons-checkmark d-none"></i></div>')
                    })
                }
            })
        } else if (item.length < 3) {
            $('.search_result').html('');
        }
    });

    $(document).on('click','.search_result div',function(){
        $(this).find('i').removeClass('d-none');
        var selected_item = '<div data-id="'+$(this).data('id')+'">'+$(this).html()+'<span class="remove_item"><i class="dripicons-cross"></i></span></div>';
        if ($('.selected_ids').html().indexOf($(this).data('id')) === -1){
            $('.selected_items').prepend(selected_item);
            $('.selected_ids').append($(this).data('id')+',');
            $('.selected_items .dripicons-checkmark').addClass('d-none');
        }
    });

    $(document).on('click','.remove_item',function(){
        var item = $(this).parent().remove();
        var remove_id = $(this).parent().data('id');
        var selected_ids = $('.selected_ids').html().replace(remove_id+',','');
        $('.selected_ids').html(selected_ids);

    });
    @endif

    @if(in_array('restaurant',explode(',',$general_setting->modules)))
    $('#search_addons').on('input', function() {
        var item = $(this).val();
        $('.search_result_addon').html('<div class="d-block text-center"><div class="spinner-border text-secondary" role="status"><span class="sr-only">Loading...</span></div></div>');

        if(item.length >= 3){
            $.ajax({
                type: "get",
                url: "{{url('search')}}/" + item,
                success: function(data) {
                    $('.search_result_addon').html('').css('height','200px');
                    $.each(data,function(key, value){
                        var image = value.image.split(',');
                        $('.search_result_addon').append('<div data-id="'+value.id+'"><img src="{{asset("images/product/small/")}}/'+image[0]+'"><h4>'+value.name+'</h4><i class="dripicons-checkmark d-none"></i></div>')
                    })
                }
            })
        } else if (item.length < 3) {
            $('.search_result_addon').html('');
        }
    });

    $(document).on('click','.search_result_addon div',function(){
        $(this).find('i').removeClass('d-none');
        var selected_addon = '<div data-id="'+$(this).data('id')+'">'+$(this).html()+'<span class="remove_item"><i class="dripicons-cross"></i></span></div>';
        if ($('.selected_addon_ids').html().indexOf($(this).data('id')) === -1){
            $('.selected_addons').prepend(selected_addon);
            $('.selected_addon_ids').append($(this).data('id')+',');
            $('.selected_addons .dripicons-checkmark').addClass('d-none');
        }
    });

    $(document).on('click','.remove_item',function(){
        var item = $(this).parent().remove();
        var remove_addon_id = $(this).parent().data('id');
        var selected_addon_ids = $('.selected_addon_ids').html().replace(remove_addon_id +',','');
        $('.selected_addon_ids').html(selected_addon_ids);

    });
    @endif

    $("ul#product").siblings('a').attr('aria-expanded','true');
    $("ul#product").addClass("show");
    $("ul#product #product-create-menu").addClass("active");

    @if(config('database.connections.zoloerp_landlord'))
        numberOfProduct = <?php echo json_encode($numberOfProduct)?>;
        $.ajax({
            type: 'GET',
            async: false,
            url: '{{route("package.fetchData", $general_setting->package_id)}}',
            success: function(data) {
                if(data['number_of_product'] > 0 && data['number_of_product'] <= numberOfProduct) {
                    localStorage.setItem("message", "You don't have permission to create another product as you already exceed the limit! Subscribe to another package if you wants more!");
                    location.href = "{{route('products.index')}}";
                }
            }
        });
    @endif

    $("#digital").hide();
    $("#combo").hide();
    $("#variant-section").hide();
    $("#initial-stock-section").hide();
    $("#diffPrice-section").hide();
    $("#batch-section").hide();
    $("#imei-section").hide();
    $("#promo-container").hide();
    $("#promotion_price").hide();
    $("#start_date").hide();
    $("#last_date").hide();
    var variantPlaceholder = <?php echo json_encode(__('db.Enter variant value seperated by comma')); ?>;
    var variantIds = [];
    var combinations = [];
    var oldCombinations = [];
    var oldAdditionalCost = [];
    var oldAdditionalPrice = [];
    var step;
    var numberOfWarehouse = <?php echo json_encode(count($lims_warehouse_list)) ?>;

    $('[data-toggle="tooltip"]').tooltip();

    $('#genbutton').on("click", function(){
      $.get('gencode', function(data){
        $("input[name='code']").val(data);
      });
    });

    $('.add-more-variant').on("click", function() {
        var htmlText = '<div class="row align-items-end"><div class="col-md-4 form-group mb-2"><label>Option *</label><input type="text" name="variant_option[]" class="form-control form-control-sm variant-field" placeholder="Size, Color etc..."></div><div class="col-md-7 form-group mb-2"><label>Value *</label><input type="text" name="variant_value[]" class="type-variant form-control form-control-sm variant-field"></div><div class="col-md-1 form-group mb-2 text-center"><button type="button" class="delVarOption btn btn-danger btn-sm"><i class="dripicons-cross"></i></button></div></div>';
        $("#variant-input-section").append(htmlText);
        $('.type-variant').tagsInput();
    });

    $(document).on("click", '.delVarOption', function() {
        $(this).parent().parent().remove();
        $('.type-variant').tagsInput();
    });

    //start variant related js
    $(function() {
        $('.type-variant').tagsInput();
    });

    (function($) {
        var delimiter = [];
        var inputSettings = [];
        var callbacks = [];

        $.fn.addTag = function(value, options) {
            options = jQuery.extend({
                focus: false,
                callback: true
            }, options);
            this.each(function() {
                var id = $(this).attr('id');
                var tagslist = $(this).val().split(_getDelimiter(delimiter[id]));
                if (tagslist[0] === '') tagslist = [];

                value = jQuery.trim(value);

                if ((inputSettings[id].unique && $(this).tagExist(value)) || !_validateTag(value, inputSettings[id], tagslist, delimiter[id])) {
                    $('#' + id + '_tag').addClass('error');
                    return false;
                }

                $('<span>', {class: 'tag'}).append(
                    $('<span>', {class: 'tag-text'}).text(value),
                    $('<button>', {class: 'tag-remove'}).click(function() {
                        return $('#' + id).removeTag(encodeURI(value));
                    })
                ).insertBefore('#' + id + '_addTag');
                tagslist.push(value);

                $('#' + id + '_tag').val('');
                if (options.focus) {
                    $('#' + id + '_tag').focus();
                } else {
                    $('#' + id + '_tag').blur();
                }

                $.fn.tagsInput.updateTagsField(this, tagslist);

                if (options.callback && callbacks[id] && callbacks[id]['onAddTag']) {
                    var f = callbacks[id]['onAddTag'];
                    f.call(this, this, value);
                }

                if (callbacks[id] && callbacks[id]['onChange']) {
                    var i = tagslist.length;
                    var f = callbacks[id]['onChange'];
                    f.call(this, this, value);
                }

                $(".type-variant").each(function(index) {
                    variantIds.splice(index, 1, $(this).attr('id'));
                });

                //start custom code
                first_variant_values = $('#'+variantIds[0]).val().split(_getDelimiter(delimiter[variantIds[0] ]));
                combinations = first_variant_values;
                step = 1;
                while(step < variantIds.length) {
                    var newCombinations = [];
                    for (var i = 0; i < combinations.length; i++) {
                        new_variant_values = $('#'+variantIds[step]).val().split(_getDelimiter(delimiter[variantIds[step] ]));
                        for (var j = 0; j < new_variant_values.length; j++) {
                            newCombinations.push(combinations[i]+'/'+new_variant_values[j]);
                        }
                    }
                    combinations = newCombinations;
                    step++;
                }
                var rownumber = $('table.variant-list tbody tr:last').index();
                if(rownumber > -1) {
                    oldCombinations = [];
                    oldAdditionalCost = [];
                    oldAdditionalPrice = [];
                    $(".variant-name").each(function(i) {
                        oldCombinations.push($(this).text());
                        oldAdditionalCost.push($('table.variant-list tbody tr:nth-child(' + (i + 1) + ')').find('.additional-cost').val());
                        oldAdditionalPrice.push($('table.variant-list tbody tr:nth-child(' + (i + 1) + ')').find('.additional-price').val());
                    });
                }
                $("table.variant-list tbody").remove();
                var newBody = $("<tbody>");
                for(i = 0; i < combinations.length; i++) {
                    var variant_name = combinations[i];
                    var item_code = variant_name+'-'+$("#code").val();
                    var newRow = $("<tr>");
                    var cols = '';
                    cols += '<td class="variant-name">'+variant_name+'<input type="hidden" name="variant_name[]" value="' + variant_name + '" /></td>';
                    cols += '<td><input type="text" class="form-control item-code" name="item_code[]" value="'+item_code+'" /></td>';
                    //checking if this variant already exist in the variant table
                    oldIndex = oldCombinations.indexOf(combinations[i]);
                    if(oldIndex >= 0) {
                        cols += '<td><input type="number" class="form-control additional-cost" name="additional_cost[]" value="'+oldAdditionalCost[oldIndex]+'" step="any" /></td>';
                        cols += '<td><input type="number" class="form-control additional-price" name="additional_price[]" value="'+oldAdditionalPrice[oldIndex]+'" step="any" /></td>';
                    }
                    else {
                        cols += '<td><input type="number" class="form-control additional-cost" name="additional_cost[]" value="" step="any" /></td>';
                        cols += '<td><input type="number" class="form-control additional-price" name="additional_price[]" value="" step="any" /></td>';
                    }
                    newRow.append(cols);
                    newBody.append(newRow);
                }
                $("table.variant-list").append(newBody);
                //end custom code
            });
            return false;
        };

        $.fn.removeTag = function(value) {
            value = decodeURI(value);

            this.each(function() {
                var id = $(this).attr('id');

                var old = $(this).val().split(_getDelimiter(delimiter[id]));

                $('#' + id + '_tagsinput .tag').remove();

                var str = '';
                for (i = 0; i < old.length; ++i) {
                    if (old[i] != value) {
                        str = str + _getDelimiter(delimiter[id]) + old[i];
                    }
                }

                $.fn.tagsInput.importTags(this, str);

                if (callbacks[id] && callbacks[id]['onRemoveTag']) {
                    var f = callbacks[id]['onRemoveTag'];
                    f.call(this, this, value);
                }
            });

            return false;
        };

        $.fn.tagExist = function(val) {
            var id = $(this).attr('id');
            var tagslist = $(this).val().split(_getDelimiter(delimiter[id]));
            return (jQuery.inArray(val, tagslist) >= 0);
        };

        $.fn.importTags = function(str) {
            var id = $(this).attr('id');
            $('#' + id + '_tagsinput .tag').remove();
            $.fn.tagsInput.importTags(this, str);
        };

        $.fn.tagsInput = function(options) {
            var settings = jQuery.extend({
                interactive: true,
                placeholder: variantPlaceholder,
                minChars: 0,
                maxChars: null,
                limit: null,
                validationPattern: null,
                width: 'auto',
                height: 'auto',
                autocomplete: null,
                hide: true,
                delimiter: ',',
                unique: true,
                removeWithBackspace: true
            }, options);

            var uniqueIdCounter = 0;

            this.each(function() {
                if (typeof $(this).data('tagsinput-init') !== 'undefined') return;

                $(this).data('tagsinput-init', true);

                if (settings.hide) $(this).hide();

                var id = $(this).attr('id');
                if (!id || _getDelimiter(delimiter[$(this).attr('id')])) {
                    id = $(this).attr('id', 'tags' + new Date().getTime() + (++uniqueIdCounter)).attr('id');
                }

                var data = jQuery.extend({
                    pid: id,
                    real_input: '#' + id,
                    holder: '#' + id + '_tagsinput',
                    input_wrapper: '#' + id + '_addTag',
                    fake_input: '#' + id + '_tag'
                }, settings);

                delimiter[id] = data.delimiter;
                inputSettings[id] = {
                    minChars: settings.minChars,
                    maxChars: settings.maxChars,
                    limit: settings.limit,
                    validationPattern: settings.validationPattern,
                    unique: settings.unique
                };

                if (settings.onAddTag || settings.onRemoveTag || settings.onChange) {
                    callbacks[id] = [];
                    callbacks[id]['onAddTag'] = settings.onAddTag;
                    callbacks[id]['onRemoveTag'] = settings.onRemoveTag;
                    callbacks[id]['onChange'] = settings.onChange;
                }

                var markup = $('<div>', {id: id + '_tagsinput', class: 'tagsinput'}).append(
                    $('<div>', {id: id + '_addTag'}).append(
                        settings.interactive ? $('<input>', {id: id + '_tag', class: 'tag-input', value: '', placeholder: settings.placeholder}) : null
                    )
                );

                $(markup).insertAfter(this);

                $(data.holder).css('width', settings.width);
                $(data.holder).css('min-height', settings.height);
                $(data.holder).css('height', settings.height);

                if ($(data.real_input).val() !== '') {
                    $.fn.tagsInput.importTags($(data.real_input), $(data.real_input).val());
                }

                // Stop here if interactive option is not chosen
                if (!settings.interactive) return;

                $(data.fake_input).val('');
                $(data.fake_input).data('pasted', false);

                $(data.fake_input).on('focus', data, function(event) {
                    $(data.holder).addClass('focus');

                    if ($(this).val() === '') {
                        $(this).removeClass('error');
                    }
                });

                $(data.fake_input).on('blur', data, function(event) {
                    $(data.holder).removeClass('focus');
                });

                if (settings.autocomplete !== null && jQuery.ui.autocomplete !== undefined) {
                    $(data.fake_input).autocomplete(settings.autocomplete);
                    $(data.fake_input).on('autocompleteselect', data, function(event, ui) {
                        $(event.data.real_input).addTag(ui.item.value, {
                            focus: true,
                            unique: settings.unique
                        });

                        return false;
                    });

                    $(data.fake_input).on('keypress', data, function(event) {
                        if (_checkDelimiter(event)) {
                            $(this).autocomplete("close");
                        }
                    });
                } else {
                    $(data.fake_input).on('blur', data, function(event) {
                        $(event.data.real_input).addTag($(event.data.fake_input).val(), {
                            focus: true,
                            unique: settings.unique
                        });

                        return false;
                    });
                }

                // If a user types a delimiter create a new tag
                $(data.fake_input).on('keypress', data, function(event) {
                    if (_checkDelimiter(event)) {
                        event.preventDefault();

                        $(event.data.real_input).addTag($(event.data.fake_input).val(), {
                            focus: true,
                            unique: settings.unique
                        });

                        return false;
                    }
                });

                $(data.fake_input).on('paste', function () {
                    $(this).data('pasted', true);
                });

                // If a user pastes the text check if it shouldn't be splitted into tags
                $(data.fake_input).on('input', data, function(event) {
                    if (!$(this).data('pasted')) return;

                    $(this).data('pasted', false);

                    var value = $(event.data.fake_input).val();

                    value = value.replace(/\n/g, '');
                    value = value.replace(/\s/g, '');

                    var tags = _splitIntoTags(event.data.delimiter, value);

                    if (tags.length > 1) {
                        for (var i = 0; i < tags.length; ++i) {
                            $(event.data.real_input).addTag(tags[i], {
                                focus: true,
                                unique: settings.unique
                            });
                        }

                        return false;
                    }
                });

                // Deletes last tag on backspace
                data.removeWithBackspace && $(data.fake_input).on('keydown', function(event) {
                    if (event.keyCode == 8 && $(this).val() === '') {
                         event.preventDefault();
                         var lastTag = $(this).closest('.tagsinput').find('.tag:last > span').text();
                         var id = $(this).attr('id').replace(/_tag$/, '');
                         $('#' + id).removeTag(encodeURI(lastTag));
                         $(this).trigger('focus');
                    }
                });

                // Removes the error class when user changes the value of the fake input
                $(data.fake_input).keydown(function(event) {
                    // enter, alt, shift, esc, ctrl and arrows keys are ignored
                    if (jQuery.inArray(event.keyCode, [13, 37, 38, 39, 40, 27, 16, 17, 18, 225]) === -1) {
                        $(this).removeClass('error');
                    }
                });
            });

            return this;
        };

        $.fn.tagsInput.updateTagsField = function(obj, tagslist) {
            var id = $(obj).attr('id');
            $(obj).val(tagslist.join(_getDelimiter(delimiter[id])));
        };

        $.fn.tagsInput.importTags = function(obj, val) {
            $(obj).val('');

            var id = $(obj).attr('id');
            var tags = _splitIntoTags(delimiter[id], val);

            for (i = 0; i < tags.length; ++i) {
                $(obj).addTag(tags[i], {
                    focus: false,
                    callback: false
                });
            }

            if (callbacks[id] && callbacks[id]['onChange']) {
                var f = callbacks[id]['onChange'];
                f.call(obj, obj, tags);
            }
        };

        var _getDelimiter = function(delimiter) {
            if (typeof delimiter === 'undefined') {
                return delimiter;
            } else if (typeof delimiter === 'string') {
                return delimiter;
            } else {
                return delimiter[0];
            }
        };

        var _validateTag = function(value, inputSettings, tagslist, delimiter) {
            var result = true;

            if (value === '') result = false;
            if (value.length < inputSettings.minChars) result = false;
            if (inputSettings.maxChars !== null && value.length > inputSettings.maxChars) result = false;
            if (inputSettings.limit !== null && tagslist.length >= inputSettings.limit) result = false;
            if (inputSettings.validationPattern !== null && !inputSettings.validationPattern.test(value)) result = false;

            if (typeof delimiter === 'string') {
                if (value.indexOf(delimiter) > -1) result = false;
            } else {
                $.each(delimiter, function(index, _delimiter) {
                    if (value.indexOf(_delimiter) > -1) result = false;
                    return false;
                });
            }

            return result;
        };

        var _checkDelimiter = function(event) {
            var found = false;

            if (event.which === 13) {
                return true;
            }

            if (typeof event.data.delimiter === 'string') {
                if (event.which === event.data.delimiter.charCodeAt(0)) {
                    found = true;
                }
            } else {
                $.each(event.data.delimiter, function(index, delimiter) {
                    if (event.which === delimiter.charCodeAt(0)) {
                        found = true;
                    }
                });
            }

            return found;
         };

         var _splitIntoTags = function(delimiter, value) {
             if (value === '') return [];

             if (typeof delimiter === 'string') {
                 return value.split(delimiter);
             } else {
                 var tmpDelimiter = '∞';
                 var text = value;

                 $.each(delimiter, function(index, _delimiter) {
                     text = text.split(_delimiter).join(tmpDelimiter);
                 });

                 return text.split(tmpDelimiter);
             }

             return [];
         };
    })(jQuery);
    //end of variant related js

    tinymce.init({
      selector: 'textarea:not(.no-tiny)',
      height: 130,
      plugins: [
        'advlist autolink lists link image charmap print preview anchor textcolor',
        'searchreplace visualblocks code fullscreen',
        'insertdatetime media table contextmenu paste code wordcount'
      ],
      toolbar: 'insert | undo redo |  formatselect | bold italic backcolor  | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat',
      branding:false
    });
    $('select[name="type"]').on('change', function() {
        if($(this).val() == 'combo'){
            $("input[name='cost']").prop('required',false);
            $("select[name='unit_id']").prop('required',false);
            hide();
            $("#profit_margin").show(300);
            $("#stock-section").hide(300);
            $("#cost").show(300);
            $("#unit").show(300);
            $("#combo").show(300);
            //$("input[name='price']").prop('disabled',true);
            $("#is-variant").prop("checked", false);
            $("#is-diffPrice").prop("checked", false);
            $("#variant-section, #variant-option, #diffPrice-option, #diffPrice-section").hide(300);
        }
        else if($(this).val() == 'digital'){
            $("input[name='cost']").prop('required',false);
            $("select[name='unit_id']").prop('required',false);
            $("input[name='file']").prop('required',true);
            hide();
            $("#profit_margin").hide(300);
            $("#digital").show(300);
            $("#combo").hide(300);
            $("#stock-section").show(300);
            $("input[name='price']").prop('disabled',false);
            $("#is-variant").prop("checked", false);
            $("#is-diffPrice").prop("checked", false);
            $("#variant-section, #variant-option, #diffPrice-option, #diffPrice-section, #batch-option").hide(300);
        }
        else if($(this).val() == 'service') {
            $("input[name='cost']").prop('required',false);
            $("select[name='unit_id']").prop('required',false);
            $("input[name='file']").prop('required',true);
            hide();
            $("#profit_margin").hide(300);
            $("#stock-section").show(300);
            $("#combo").hide(300);
            $("#digital").hide(300);
            $("input[name='price']").prop('disabled',false);
            $("#is-variant").prop("checked", false);
            $("#is-diffPrice").prop("checked", false);
            $("#variant-section, #variant-option, #diffPrice-option, #diffPrice-section, #batch-option, #imei-option").hide(300);
        }
        else if($(this).val() == 'standard') {
            $("input[name='cost']").prop('required',true);
            $("select[name='unit_id']").prop('required',true);
            $("input[name='file']").prop('required',false);
            $("#stock-section").show(300);
            $("#cost").show(300);
            $("#profit_margin").show(300);
            $("#unit").show(300);
            $("#alert-qty").show(300);
            $("#variant-option, #diffPrice-option, #batch-option, #imei-option").show(300);
            $("#digital").hide(300);
            $("#combo").hide(300);
            $("input[name='price']").prop('disabled',false);
        }
        applyDynamicGridSpans();
    });

    $('select[name="unit_id"]').on('change', function() {

        unitID = $(this).val();
        if(unitID) {
            populate_category(unitID);
        }else{
            $('select[name="sale_unit_id"]').empty();
            $('select[name="purchase_unit_id"]').empty();
        }
    });
    <?php $productArray = []; ?>
    var lims_product_code = [
        @foreach($lims_product_list_without_variant as $product)
            <?php
                $productArray[] = htmlspecialchars($product->code) . ' (' . preg_replace('/[\n\r]/', "<br>", htmlspecialchars($product->name)) . ')';
            ?>
        @endforeach
        @foreach($lims_product_list_with_variant as $product)
            <?php
                $productArray[] = htmlspecialchars($product->item_code) . ' (' . preg_replace('/[\n\r]/', "<br>", htmlspecialchars($product->name)) . ')';
            ?>
        @endforeach
            <?php
                echo  '"'.implode('","', $productArray).'"';
            ?> ];

    var lims_productcodeSearch = $('#lims_productcodeSearch');

    lims_productcodeSearch.autocomplete({
        source: function(request, response) {
            var matcher = new RegExp(".?" + $.ui.autocomplete.escapeRegex(request.term), "i");
            response($.grep(lims_product_code, function(item) {
                return matcher.test(item);
            }));
        },
        select: function(event, ui) {
            var data = ui.item.value;
            $.ajax({
                type: 'GET',
                url: 'lims_product_search',
                data: {
                    data: data
                },
                success: function(responseData) {
                    data = responseData[0];
                    console.log(data)
                    //console.log(data);
                    var flag = 1;
                    $(".varient_id").each(function() {
                        if ($(this).val() == data[1]) {
                            alert('Duplicate input is not allowed!')
                            flag = 0;
                        }
                    });
                    $("input[name='product_code_name']").val('');
                    if(flag){
                        var newRow = $("<tr>");
                        var cols = '';
                        cols += '<td>' + data[0] +' [' + data[1] + ']</td>';
                        cols += `<td>
                                    <div class="input-group">
                                        <input type="number" name="wastage_percent[]" class="form-control wastage_percent" value="0"/>
                                        <div class="input-group-append">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                </td>`;
                        cols += `<td>
                                    <div class="input-group u-maxw-unset">
                                        <input type="number"
                                            class="form-control qty"
                                            min="1"
                                            name="product_qty[]"
                                            value="1"
                                            step="any"
                                            placeholder="Qty"
                                            aria-label="Quantity">
                                        <div class="input-group-append">
                                            `+data[13]+`
                                        </div>
                                    </div>
                                </td>`;
                        cols += '<td><input type="number" class="form-control unit_cost" name="product_unit_cost[]" value="' + data[10] + '"/></td>';
                        cols += '<td><input type="number" class="form-control unit_price" name="unit_price[]" value="' + data[2] + '" step="any"/></td>';
                        cols += '<td><input type="number" class="form-control subtotal" name="subtotal[]" value="' + data[2] + '" step="any"/></td>';
                        cols += '<td><button type="button" class="ibtnDel btn btn-sm btn-danger">X</button></td>';
                        cols += '<input type="hidden" class="product-id" name="product_id[]" value="' + data[8] + '"/>';
                        cols += '<input type="hidden" class="" name="variant_id[]" value="' + data[9] + '"/>';
                        cols += '<input type="hidden" class="product_unit_price" name="" value="' + data[2] + '"/>';
                        cols += '<input type="hidden" class="product_unit_cost" name="" value="' + data[10] + '"/>';
                        cols += '<input type="hidden" class="varient_id" name="" value="' + data[1] + '"/>';

                        newRow.append(cols);
                        $(".combo_product_list_table").append(newRow);
                        calculate_price();
                    }
                }
            });
        }
    });

    //Change quantity or unit price
    $("#myTable").on('input', '.qty , .unit_cost, .unit_price', function() {
        calculate_price();
    });

    //Delete product
    $("table.order-list tbody").on("click", ".ibtnDel", function(event) {
        $(this).closest("tr").remove();
        calculate_price();
    });

    function hide() {
        $("#cost").hide(300);
        $("#unit").hide(300);
        $("#alert-qty").hide(300);
    }

    function calculate_price() {
        var price = 0;
        var cost = 0
        $(".qty").each(function() {
            rowindex = $(this).closest('tr').index();
            quantity =  $(this).val();
            unit_price = $('table.order-list tbody tr:nth-child(' + (rowindex + 1) + ') .product_unit_price').val();
            product_unit_price = $('table.order-list tbody tr:nth-child(' + (rowindex + 1) + ') .product_unit_price').val();
            product_unit_cost = $('table.order-list tbody tr:nth-child(' + (rowindex + 1) + ')  .product_unit_cost').val();

            // price += quantity * unit_price;
            unit_cost = $('table.order-list tbody tr:nth-child(' + (rowindex + 1) + ') .unit_cost').val();
            cost += quantity * unit_cost;

            // subtotal calculation
            let $row = $(this).closest('tr');
            let qty = parseFloat($(this).val()) || 0;

            // Get selected option and its data attributes
            let $selectedOption = $row.find('.combo_unit_id option:selected');
            let operator = $selectedOption.data('operator');
            let operationValue = parseFloat($selectedOption.data('operation_value')) || 1;

            // Convert quantity based on operator
            let convertedQty = quantity;
            if (operator === '*') {
                convertedQty = quantity * operationValue;
            } else if (operator === '/') {
                convertedQty = quantity / operationValue;
            }

            console.log(
                'subtotal :' + unit_price,
                'product_unit_price :' + product_unit_price,
                'product_unit_cost :' + product_unit_cost,
                'convertedQty : ' + convertedQty
            )

            // Calculate subtotal using convertedQty
            let subtotal = convertedQty * unit_price;
            let total_unit_cost = convertedQty * product_unit_cost;
            let total_unit_price = convertedQty * product_unit_price;
            cost += convertedQty * unit_cost;
            price += subtotal;
            // Update subtotal field
            $row.find('.unit_cost').val(total_unit_cost.toFixed(2));
            $row.find('.unit_price').val(total_unit_price.toFixed(2));
            $row.find('.subtotal').val(subtotal.toFixed(2));
        });
        $('input[name="price"]').val(price.toFixed(2));

        let total_cost = 0;
        $('input[name="product_unit_cost[]"]').each(function() {
            let value = parseFloat($(this).val()) || 0;
            total_cost += value;
        });
        $('input[name="cost"]').val(total_cost.toFixed(2));

    }

    function populate_category(unitID){
        $.ajax({
            url: 'saleunit/'+unitID,
            type: "GET",
            dataType: "json",
            success:function(data) {
                  $('select[name="sale_unit_id"]').empty();
                  $('select[name="purchase_unit_id"]').empty();
                  $.each(data, function(key, value) {
                      $('select[name="sale_unit_id"]').append('<option value="'+ key +'">'+ value +'</option>');
                      $('select[name="purchase_unit_id"]').append('<option value="'+ key +'">'+ value +'</option>');
                  });
                  $('.selectpicker').selectpicker('refresh');
            },
        });
    }

    $('input[name="profit_margin"]').val("{{ $general_setting->default_margin_value }}");

    let marginType = $('select[name="profit_margin_type"]').val();

    $('input[name="profit_margin"]').on("input", function () {
        if (marginType === "flat" && parseFloat($(this).val()) < 0) {
            $(this).val(0);
        }
    });

    // Profit type change
    $('select[name="profit_margin_type"]').on("change", function () {
        marginType = $(this).val();

        // update placeholder
        $('input[name="profit_margin"]').attr(
            "placeholder",
            marginType === "percentage" ? "% value" : "Flat amount"
        );

        recalcPrice();
    }).trigger("change");

    // Recalc price function
    function recalcPrice() {
        let cost   = parseFloat($('input[name="cost"]').val()) || 0;
        let margin = parseFloat($('input[name="profit_margin"]').val()) || 0;

        let price =
            marginType === "percentage"
                ? cost + (cost * margin / 100)
                : cost + margin;

        $('input[name="price"]').val(price.toFixed(2)).trigger("change");
    }

    // Recalc margin when price edited manually
    function recalcMargin() {
        let price = parseFloat($('input[name="price"]').val()) || 0;
        let cost  = parseFloat($('input[name="cost"]').val()) || 0;

        let margin =
            marginType === "percentage"
                ? ((price - cost) / cost * 100).toFixed(2)
                : (price - cost).toFixed(2);

        $('input[name="profit_margin"]').val(margin);
    }

    // Live calculations
    $('input[name="cost"], input[name="profit_margin"]').on("input", function () {
        recalcPrice();
    });

    $('input[name="price"]').on("input", function () {
        recalcMargin();
    });

    // Warning UI
    $('input[name="price"], input[name="cost"]').on("change keyup", function () {

        let curCost  = parseFloat($('input[name="cost"]').val()) || 0;
        let curPrice = parseFloat($('input[name="price"]').val()) || 0;

        if (isNaN(curPrice) || curPrice === 0) {
            $('#product-price-warning').addClass('d-none');
            return;
        }

        if (curCost <= 0) {
            $('#product-cost-warning').removeClass('d-none');
        } else {
            $('#product-cost-warning').addClass('d-none');
        }

        if (curPrice <= curCost) {
            $('#product-price-warning').removeClass('d-none');
        } else {
            $('#product-price-warning').addClass('d-none');
        }
    });

    $("input[name='is_initial_stock']").on("change", function () {
        if ($(this).is(':checked')) {
            if(numberOfWarehouse > 0)
                $("#initial-stock-section").show(300);
            else {
                alert('Please create warehouse first before adding stock!');
                $(this).prop("checked", false);
            }
        }
        else {
            $("#initial-stock-section").hide(300);
        }
        applyDynamicGridSpans();
    });

    $("input[name='is_batch']").on("change", function () {
        if ($(this).is(':checked')) {
            $("#batch-section").show(300);
        }
        else {
            $("#batch-section").hide(300);
        }
        applyDynamicGridSpans();
    });

    $("input[name='is_imei']").on("change", function () {
        if ($(this).is(':checked')) {
            $("#imei-section").show(300);
        }
        else {
            $("#imei-section").hide(300);
        }
        applyDynamicGridSpans();
    });

    // Synchronize toggle pill visual active state
    $('.zolo-toggle-pill input[type="checkbox"]').on("change", function () {
        var $pill = $(this).closest('.zolo-toggle-pill');
        if ($(this).is(':checked')) {
            $pill.addClass('is-active');
        } else {
            $pill.removeClass('is-active');
        }
    });

    // Seamless click on prepend icon opens/focuses corresponding input
    $(document).on('click', '.input-group-prepend', function() {
        $(this).closest('.input-group').find('input').focus();
    });

    // Initialize datepicker on batch expiry fields
    $('#batch-section .datepicker').datepicker({
        format: "dd-mm-yyyy",
        startDate: "<?php echo date('d-m-Y'); ?>",
        autoclose: true,
        todayHighlight: true
    });

    $("input[name='is_variant']").on("change", function () {
        if ($(this).is(':checked')) {
            $("#variant-section").show(300);
            $(".variant-field").prop("required", true);
        }
        else {
            $("#variant-section").hide(300);
            $(".variant-field").prop("required", false);
        }
        applyDynamicGridSpans();
    });

    $("input[name='is_diffPrice']").on("change", function () {
        if ($(this).is(':checked')) {
            $("#diffPrice-section").show(300);
        }
        else {
            $("#diffPrice-section").hide(300);
        }
        applyDynamicGridSpans();
    });

    $( "#promotion" ).on( "change", function() {
        if ($(this).is(':checked')) {
            $("#starting_date").val($.datepicker.formatDate('dd-mm-yy', new Date()));
            $("#promo-container").show(300);
            $("#promotion_price").show(300);
            $("#start_date").show(300);
            $("#last_date").show(300);
        }
        else {
            $("#promo-container").hide(300);
            $("#promotion_price").hide(300);
            $("#start_date").hide(300);
            $("#last_date").hide(300);
        }
        applyDynamicGridSpans();
    });

    function applyDynamicGridSpans() {
        var isCombo = $('select[name="type"]').val() === 'combo';

        var sections = [
            {
                id: 'initial-stock-section',
                $el: $('#initial-stock-section'),
                isWide: false,
                active: !isCombo && $("input[name='is_initial_stock']").is(':checked')
            },
            {
                id: 'diffPrice-section',
                $el: $('#diffPrice-section'),
                isWide: false,
                active: !isCombo && $("input[name='is_diffPrice']").is(':checked')
            },
            {
                id: 'imei-section',
                $el: $('#imei-section'),
                isWide: false,
                active: !isCombo && $("input[name='is_imei']").is(':checked')
            },
            {
                id: 'promo-container',
                $el: $('#promo-container'),
                isWide: false,
                active: $("#promotion").is(':checked')
            },
            {
                id: 'variant-section',
                $el: $('#variant-section'),
                isWide: true,
                active: !isCombo && $("input[name='is_variant']").is(':checked')
            },
            {
                id: 'batch-section',
                $el: $('#batch-section'),
                isWide: true,
                active: !isCombo && $("input[name='is_batch']").is(':checked')
            },
            {
                id: 'combo',
                $el: $('#combo'),
                isWide: true,
                active: isCombo
            }
        ];

        var spanClasses = 'zolo-grid-span-1 zolo-grid-span-2 zolo-grid-span-3 zolo-grid-span-4';
        sections.forEach(function(s) {
            if (s.$el.length) {
                s.$el.removeClass(spanClasses);
            }
        });

        var activeSections = sections.filter(function(s) {
            return s.active && s.$el.length;
        });

        var count = activeSections.length;
        if (count === 0) return;

        if (isCombo) {
            $('#combo').addClass('zolo-grid-span-4');
            return;
        }

        if (count === 1) {
            activeSections[0].$el.addClass('zolo-grid-span-4');
        }
        else if (count === 2) {
            var wideList = activeSections.filter(function(s) { return s.isWide; });
            var compactList = activeSections.filter(function(s) { return !s.isWide; });

            if (wideList.length === 1 && compactList.length === 1) {
                compactList[0].$el.addClass('zolo-grid-span-1');
                wideList[0].$el.addClass('zolo-grid-span-3');
            } else {
                activeSections[0].$el.addClass('zolo-grid-span-2');
                activeSections[1].$el.addClass('zolo-grid-span-2');
            }
        }
        else if (count === 3) {
            var wideList = activeSections.filter(function(s) { return s.isWide; });
            var compactList = activeSections.filter(function(s) { return !s.isWide; });

            if (wideList.length === 1 && compactList.length === 2) {
                compactList[0].$el.addClass('zolo-grid-span-1');
                compactList[1].$el.addClass('zolo-grid-span-1');
                wideList[0].$el.addClass('zolo-grid-span-2');
            } else if (wideList.length === 0) {
                activeSections[0].$el.addClass('zolo-grid-span-2');
                activeSections[1].$el.addClass('zolo-grid-span-1');
                activeSections[2].$el.addClass('zolo-grid-span-1');
            } else if (wideList.length === 2 && compactList.length === 1) {
                wideList[0].$el.addClass('zolo-grid-span-2');
                wideList[1].$el.addClass('zolo-grid-span-2');
                compactList[0].$el.addClass('zolo-grid-span-4');
            }
        }
        else if (count === 4) {
            var wideList = activeSections.filter(function(s) { return s.isWide; });
            var compactList = activeSections.filter(function(s) { return !s.isWide; });

            if (wideList.length === 0) {
                activeSections.forEach(function(s) {
                    s.$el.addClass('zolo-grid-span-1');
                });
            } else if (wideList.length === 1) {
                compactList[0].$el.addClass('zolo-grid-span-1');
                compactList[1].$el.addClass('zolo-grid-span-1');
                wideList[0].$el.addClass('zolo-grid-span-2');
                compactList[2].$el.addClass('zolo-grid-span-4');
            } else if (wideList.length === 2) {
                wideList[0].$el.addClass('zolo-grid-span-2');
                wideList[1].$el.addClass('zolo-grid-span-2');
                compactList[0].$el.addClass('zolo-grid-span-2');
                compactList[1].$el.addClass('zolo-grid-span-2');
            }
        }
        else {
            var rowRem = 4;
            var rowItems = [];
            activeSections.forEach(function(s, idx) {
                var need = s.isWide ? 2 : 1;
                if (need > rowRem) {
                    if (rowRem > 0 && rowItems.length) {
                        var last = rowItems[rowItems.length - 1];
                        var curClass = last.$el.attr('class') || '';
                        var curSpan = (curClass.match(/zolo-grid-span-(\d)/) || [0, 1])[1];
                        last.$el.removeClass(spanClasses).addClass('zolo-grid-span-' + (parseInt(curSpan) + rowRem));
                    }
                    rowRem = 4;
                    rowItems = [];
                }
                s.$el.addClass('zolo-grid-span-' + need);
                rowRem -= need;
                rowItems.push(s);
                if (idx === activeSections.length - 1 && rowRem > 0 && rowItems.length) {
                    var last = rowItems[rowItems.length - 1];
                    var curClass = last.$el.attr('class') || '';
                    var curSpan = (curClass.match(/zolo-grid-span-(\d)/) || [0, 1])[1];
                    last.$el.removeClass(spanClasses).addClass('zolo-grid-span-' + (parseInt(curSpan) + rowRem));
                }
            });
        }
    }

    var starting_date = $('#starting_date');
    starting_date.datepicker({
     format: "dd-mm-yyyy",
     startDate: "<?php echo date('d-m-Y'); ?>",
     autoclose: true,
     todayHighlight: true
     });

    var ending_date = $('#ending_date');
    ending_date.datepicker({
     format: "dd-mm-yyyy",
     startDate: "<?php echo date('d-m-Y'); ?>",
     autoclose: true,
     todayHighlight: true
     });

    $(window).keydown(function(e){
        if (e.which == 13) {
            var $targ = $(e.target);

            if (!$targ.is("textarea") && !$targ.is(":button,:submit")) {
                var focusNext = false;
                $(this).find(":input:visible:not([disabled],[readonly]), a").each(function(){
                    if (this === e.target) {
                        focusNext = true;
                    }
                    else if (focusNext){
                        $(this).focus();
                        return false;
                    }
                });

                return false;
            }
        }
    });
    //dropzone portion
    Dropzone.autoDiscover = false;

    jQuery.validator.setDefaults({
        errorPlacement: function (error, element) {
            if(error.html() == 'Select Category...')
                error.html('This field is required.');
            $(element).closest('div.form-group').find('.validation-msg').html(error.html());
        },
        highlight: function (element) {
            $(element).closest('div.form-group').removeClass('has-success').addClass('has-error');
        },
        unhighlight: function (element, errorClass, validClass) {
            $(element).closest('div.form-group').removeClass('has-error').addClass('has-success');
            $(element).closest('div.form-group').find('.validation-msg').html('');
        }
    });

    function validate() {
        // console.log('validate function: ' + 1516);
        var product_code = $("input[name='code']").val();
        var barcode_symbology = $('select[name="barcode_symbology"]').val();
        var exp = /^\d+$/;

        if(!(product_code.match(exp)) && (barcode_symbology == 'UPCA' || barcode_symbology == 'UPCE' || barcode_symbology == 'EAN8' || barcode_symbology == 'EAN13') ) {
            alert('Product code must be numeric.');
            return false;
        }
        else if(product_code.match(exp)) {
            if(barcode_symbology == 'UPCA' && product_code.length > 11){
                alert('Product code length must be less than 12');
                return false;
            }
            else if(barcode_symbology == 'EAN8' && product_code.length > 7){
                alert('Product code length must be less than 8');
                return false;
            }
            /*else if(barcode_symbology == 'EAN13' && product_code.length > 12){
                alert('Product code length must be less than 13');
                return false;
            }*/
        }

        if( $("#type").val() == 'combo' ) {
            var rownumber = $('table.order-list tbody tr:last').index();
            if (rownumber < 0) {
                alert("Please insert product to table!")
                return false;
            }
        }
        if($("#is-variant").is(":checked")) {
            rowindex = $("table#variant-table tbody tr:last").index();
            if (rowindex < 0) {
                alert('This product has variant. Please insert variant to table');
                return false;
            }
        }
        $("input[name='price']").prop('disabled',false);
        return true;
    }

    /*$('#submit-btn').on("click", function (e) {
        $('#submit-btn').attr('disabled','true').html('<span class="spinner-border text-light" role="status"></span> {{__("db.Saving")}}...');
    })*/

    $(".dropzone").sortable({
        items:'.dz-preview',
        cursor: 'grab',
        opacity: 0.5,
        containment: '.dropzone',
        distance: 20,
        tolerance: 'pointer',
        stop: function () {
          var queue = myDropzone.getAcceptedFiles();
          newQueue = [];
          $('#imageUpload .dz-preview .dz-filename [data-dz-name]').each(function (count, el) {
                var name = el.innerHTML;
                queue.forEach(function(file) {
                    if (file.name === name) {
                        newQueue.push(file);
                    }
                });
          });
          myDropzone.files = newQueue;
        }
    });

    myDropzone = new Dropzone('div#imageUpload', {
        addRemoveLinks: true,
        autoProcessQueue: false,
        uploadMultiple: true,
        parallelUploads: 100,
        maxFilesize: 12,
        paramName: 'image',
        clickable: true,
        method: 'POST',
        url: "{{route('products.store')}}",
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        renameFile: function(file) {
            var dt = new Date();
            var time = dt.getTime();
            return time + file.name;
        },
        acceptedFiles: ".jpeg,.jpg,.png,.gif",
        init: function () {
            var myDropzone = this;
            $('#submit-btn').on("click", function (e) {
                e.preventDefault();
                if ( $("#product-form").valid() && validate() ) {
                    $('#submit-btn').attr('disabled','true').html('<span class="spinner-border text-light" role="status"></span> {{__("db.Saving")}}...');
                    tinyMCE.triggerSave();
                    if(myDropzone.getAcceptedFiles().length) {
                        myDropzone.processQueue();
                    }
                    else {
                        var formData = new FormData();
                        var data = $("#product-form").serializeArray();
                        $.each(data, function (key, el) {
                            formData.append(el.name, el.value);
                        });
                        var file = $('#file')[0].files;
                        if(file.length > 0)
                            formData.append('file',file[0]);

                        $.ajax({
                            type:'POST',
                            url:"{{route('products.store')}}",
                            data: formData,
                            contentType: false,
                            processData: false,
                            success:function(response) {
                                //console.log(response);
                                location.href = '../products';
                            },
                            error:function(response) {
                                // console.log(response);
                              if(response.responseJSON.errors.name) {
                                  $("#name-error").text(response.responseJSON.errors.name);
                              }
                              else if(response.responseJSON.errors.code) {
                                  $("#code-error").text(response.responseJSON.errors.code);
                              }
                            },
                        });
                    }
                }
            });

            this.on('sending', function (file, xhr, formData) {
                // Append all form inputs to the formData Dropzone will POST
                var data = $("#product-form").serializeArray();
                $.each(data, function (key, el) {
                    formData.append(el.name, el.value);
                });
                var file = $('#file')[0].files;
                if(file.length > 0)
                    formData.append('file',file[0]);
                // console.log(formData);
            });
        },
        error: function (file, response) {
            console.log(response.message, 'hi');
            if(response.message) {
              $("#name-error").text(response.message);
              this.removeAllFiles(true);
            }
            else if(response.code) {
              $("#code-error").text(response.code);
              this.removeAllFiles(true);
            }
            else {
              try {
                  var res = JSON.parse(response);
                  if (typeof res.message !== 'undefined' && !$modal.hasClass('in')) {
                      $("#success-icon").attr("class", "fas fa-thumbs-down");
                      $("#success-text").html(res.message);
                      $modal.modal("show");
                  } else {
                      if ($.type(response) === "string")
                          var message = response; //dropzone sends it's own error messages in string
                      else
                          var message = response.message;
                      file.previewElement.classList.add("dz-error");
                      _ref = file.previewElement.querySelectorAll("[data-dz-errormessage]");
                      _results = [];
                      for (_i = 0, _len = _ref.length; _i < _len; _i++) {
                          node = _ref[_i];
                          _results.push(node.textContent = message);
                      }
                      return _results;
                  }
              } catch (error) {
                  console.log(error, 'hi');
              }
            }
        },
        successmultiple: function (file, response) {
            location.href = '../products';
            //console.log(file, response);
        },
        completemultiple: function (file, response) {
            console.log(file, response, "completemultiple");
        },
        reset: function () {
            console.log("resetFiles");
            this.removeAllFiles(true);
        }
    });
    // brand create ajax start
    $('.brand-submit-btn').on("click", function() {
        $.ajax({
            type:'POST',
            url:'{{route('brand.store')}}',
            data: $("#brand-form").serialize(),
            success:function(response) {
                key = response['id'];
                value = response['title'];
                $('select[name="brand_id"]').append('<option value="'+ key +'">'+ value +'</option>');
                $('select[name="brand_id"]').val(key);
                $('.selectpicker').selectpicker('refresh');
                $("#addBrand").modal('hide');
            }
        });
    });
    $('.category-model').on("click", function() {
        $('.category-submit-btn').prop('type', 'button');
        $('.category-ajax-check').val(1);
    });
    // category create ajax start
    $('.category-submit-btn').on("click", function() {
        $.ajax({
            type:'POST',
            url:'{{route('category.store')}}',
            data: $("#category-form").serialize(),
            success:function(response) {
                key = response['id'];
                value = response['name'];
                $('select[name="category_id"]').append('<option value="'+ key +'">'+ value +'</option>');
                $('select[name="category_id"]').val(key);
                $('.selectpicker').selectpicker('refresh');
                $("#category-modal").modal('hide');
            }
        });
    });
    // Tax Ajax Create
        // category create ajax start
        $('.tax-submit-btn').on("click", function() {
        $.ajax({
            type:'POST',
            url:'{{route('tax.store')}}',
            data: $("#tax-form").serialize(),
            success:function(response) {
                key = response['id'];
                value = response['name'];
                $('select[name="tax_id"]').append('<option value="'+ key +'">'+ value +'</option>');
                $('select[name="tax_id"]').val(key);
                $('.selectpicker').selectpicker('refresh');
                $("#addTax").modal('hide');
            }
        });
    });
</script>

<script>
$(document).on('click', '#create_unit', function (e) {
    e.preventDefault();

    let form = $(this).closest('form');
    let formData = form.serialize();

    $.ajax({
        url: form.attr('action'),
        method: 'POST',
        data: formData,
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function (response) {
            if (response.success) {
                // close modal
                $('#createUnitModal').modal('hide');

                // clear form
                form.trigger('reset');

                // append new unit to dropdown (example)
                if (response.unit) {
                    $("select[name='unit_id']").append(
                        `<option value="${response.unit.id}">${response.unit.unit_name}</option>`
                    ).selectpicker('refresh');
                }

            } else {
            }
        }
    });

    // Initial calculation of dynamic responsive grid spans
    applyDynamicGridSpans();
});
</script>

@endpush
