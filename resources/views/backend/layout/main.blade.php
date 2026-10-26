@php
$companyContext = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
$activeCompany = $companyContext ? \App\Models\Company::with('group')->find($companyContext->companyId) : null;
$activeYear = $companyContext ? \Illuminate\Support\Facades\DB::table('fiscal_years')->where('id', $companyContext->financialYearId)->value('name') : null;
@endphp
<!DOCTYPE html>
<html dir="@if( Config::get('app.locale') == 'ar' || $general_setting->is_rtl){{'rtl'}}@endif" @if(Route::currentRouteName() != 'sale.pos') class="app-shell" @endif>

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  @if(!config('database.connections.zoloerp_landlord'))
  <link rel="icon" type="image/png" href="{{url('logo', $general_setting->favicon ?? $general_setting->site_logo)}}" />
  <title>{{$general_setting->site_title}}</title>
  <meta name="description" content="">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="all,follow">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <!-- Critical Anti-FOUC Sidebar & Theme Rules: Render dark sidebar immediately on first paint with zero transitions -->
  <link rel="stylesheet" href="{{ versioned_asset('css/pages/layout-main.css') }}">
  <!-- Bootstrap CSS-->
  <link rel="stylesheet" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap.min.css') ?>" type="text/css">
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap-toggle/css/bootstrap-toggle.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap-toggle/css/bootstrap-toggle.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-datepicker.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="<?php echo versioned_asset('vendor/jquery-timepicker/jquery.timepicker.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/jquery-timepicker/jquery.timepicker.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap/css/awesome-bootstrap-checkbox.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap/css/awesome-bootstrap-checkbox.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-select.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-select.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- Font Awesome CSS-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/font-awesome/css/font-awesome.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/font-awesome/css/font-awesome.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- Drip icon font-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/dripicons/webfont.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/dripicons/webfont.css') ?>" rel="stylesheet">
  </noscript>

  <!-- jQuery Circle-->
  <link rel="preload" href="<?php echo versioned_asset('css/grasp_mobile_progress_circle-1.0.0.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('css/grasp_mobile_progress_circle-1.0.0.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- Custom Scrollbar-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/malihu-custom-scrollbar-plugin/jquery.mCustomScrollbar.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/malihu-custom-scrollbar-plugin/jquery.mCustomScrollbar.css') ?>" rel="stylesheet">
  </noscript>

  @if(Route::currentRouteName() != '/')
  <!-- date range stylesheet-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/daterange/css/daterangepicker.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/daterange/css/daterangepicker.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- table sorter stylesheet-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/datatable/dataTables.bootstrap4.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/datatable/dataTables.bootstrap4.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="{{ versioned_asset('vendor/datatable/fixedHeader.bootstrap.min.css') }}" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="{{ versioned_asset('vendor/datatable/fixedHeader.bootstrap.min.css') }}" rel="stylesheet">
  </noscript>
  <link rel="preload" href="{{ versioned_asset('vendor/datatable/responsive.bootstrap.min.css') }}" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="{{ versioned_asset('vendor/datatable/responsive.bootstrap.min.css') }}" rel="stylesheet">
  </noscript>
  @endif

  <link rel="stylesheet" href="<?php echo versioned_asset('css/style.default.css') ?>" id="theme-stylesheet" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/dropzone.css') ?>">
  <!-- Custom stylesheet - for your changes-->
  <link rel="stylesheet" href="<?php echo versioned_asset('css/custom-' . $general_setting->theme) ?>" type="text/css" id="custom-style">

  @if( Config::get('app.locale') == 'ar' || $general_setting->is_rtl)
  <!-- RTL css -->
  <link rel="stylesheet" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-rtl.min.css') ?>" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/custom-rtl.css') ?>" type="text/css" id="custom-style">
  @endif
  @else
  <link rel="icon" type="image/png" href="{{url('logo', $general_setting->favicon ?? $general_setting->site_logo)}}" />
  <title>{{$general_setting->site_title}}</title>
  <meta name="description" content="">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="all,follow">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <!-- Bootstrap CSS-->
  <link rel="stylesheet" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap.min.css') ?>" type="text/css">
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap-toggle/css/bootstrap-toggle.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap-toggle/css/bootstrap-toggle.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-datepicker.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-datepicker.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/jquery-timepicker/jquery.timepicker.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/jquery-timepicker/jquery.timepicker.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap/css/awesome-bootstrap-checkbox.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap/css/awesome-bootstrap-checkbox.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-select.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-select.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- Font Awesome CSS-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/font-awesome/css/font-awesome.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/font-awesome/css/font-awesome.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- Drip icon font-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/dripicons/webfont.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/dripicons/webfont.css') ?>" rel="stylesheet">
  </noscript>

  <!-- jQuery Circle-->
  <link rel="preload" href="<?php echo versioned_asset('css/grasp_mobile_progress_circle-1.0.0.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('css/grasp_mobile_progress_circle-1.0.0.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- Custom Scrollbar-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/malihu-custom-scrollbar-plugin/jquery.mCustomScrollbar.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/malihu-custom-scrollbar-plugin/jquery.mCustomScrollbar.css') ?>" rel="stylesheet">
  </noscript>

  @if(Route::currentRouteName() != '/')
  <!-- date range stylesheet-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/daterange/css/daterangepicker.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/daterange/css/daterangepicker.min.css') ?>" rel="stylesheet">
  </noscript>
  <!-- table sorter stylesheet-->
  <link rel="preload" href="<?php echo versioned_asset('vendor/datatable/dataTables.bootstrap4.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="<?php echo versioned_asset('vendor/datatable/dataTables.bootstrap4.min.css') ?>" rel="stylesheet">
  </noscript>
  <link rel="preload" href="{{ versioned_asset('vendor/datatable/fixedHeader.bootstrap.min.css') }}" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="{{ versioned_asset('vendor/datatable/fixedHeader.bootstrap.min.css') }}" rel="stylesheet">
  </noscript>
  <link rel="preload" href="{{ versioned_asset('vendor/datatable/responsive.bootstrap.min.css') }}" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="{{ versioned_asset('vendor/datatable/responsive.bootstrap.min.css') }}" rel="stylesheet">
  </noscript>
  @endif

  <link rel="stylesheet" href="<?php echo versioned_asset('css/style.default.css') ?>" id="theme-stylesheet" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/dropzone.css') ?>">
  <!-- Custom stylesheet - for your changes-->
  <link rel="stylesheet" href="<?php echo versioned_asset('css/custom-' . $general_setting->theme) ?>" type="text/css" id="custom-style">

  @if( Config::get('app.locale') == 'ar' || $general_setting->is_rtl)
  <!-- RTL css -->
  <link rel="stylesheet" href="<?php echo versioned_asset('vendor/bootstrap/css/bootstrap-rtl.min.css') ?>" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/custom-rtl.css') ?>" type="text/css" id="custom-style">
  @endif
  @endif

  <!-- Google fonts -->
  @if($general_setting->font_css)
    {!! $general_setting->font_css !!}
  @else
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,100..900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
  <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,100..900&display=swap" rel="stylesheet"></noscript>
  @endif

  <!-- zoloERP Design System: tokens (brand colours) first, components after page CSS -->
  <link rel="stylesheet" href="<?php echo versioned_asset('css/zolo-tokens.css') ?>" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/zolo-utilities.css') ?>" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/zolo-erp-neo.css') ?>" type="text/css" id="zolo-neo-style">
  <!-- One-screen app shell: compact header, viewport-locked page, lists sized to fit -->
  <link rel="stylesheet" href="<?php echo versioned_asset('css/app-shell.css') ?>" type="text/css">
  <link rel="stylesheet" href="<?php echo versioned_asset('css/field-help.css') ?>" type="text/css">

  @stack('css')

  <link rel="stylesheet" href="<?php echo versioned_asset('css/zolo-components.css') ?>" type="text/css">

  <!-- Sidebar links: fetch the page on hover so the click opens it without waiting on the server.
       Prefetch requests do not touch the session's previous URL (Laravel skips Sec-Purpose: prefetch). -->
  <script type="speculationrules">
  {"prefetch": [{"where": {"and": [
    {"selector_matches": "nav.side-navbar a[href]"},
    {"not": {"selector_matches": "[href^='#'], [href^='javascript'], [target], [download], [data-no-prefetch], [href*='logout'], [href*='backup']"}}
  ]}, "eagerness": "moderate"}]}
  </script>

  <!-- Custom CSS from general settings -->
  {!! $general_setting->custom_css !!}
</head>

<body class="@if($theme == 'dark')dark-mode dripicons-brightness-low @endif  @if(Route::currentRouteName() == 'sale.pos') pos-page @endif">
  <div id="loader" style="display:none" aria-hidden="true"></div>
  <!-- Side Navbar -->
  <nav class="side-navbar d-print-none">
    <script>
      (function() {
        try {
          var s = localStorage.getItem('zolo_sidebar_state');
          if (s === 'icon-only') document.currentScript.parentElement.classList.add('icon-only');
          else if (s === 'full-hide') document.currentScript.parentElement.classList.add('full-hide', 'shrink');
        } catch(e) {}
      })();
    </script>
    <span class="brand-big">
      <a href="{{url('/dashboard') }}" class="zolo-brand-link">
        <div class="zolo-brand-container">
          <div class="zolo-brand-mark">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M4 6.5H20L10 17.5H20" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="zolo-brand-badge">zolo<span class="zolo-accent">ERP</span></div>
        </div>
      </a>
    </span>
    @include('backend.layout.sidebar')
  </nav>

  <div class="page">
    <script>
      (function() {
        try {
          var s = localStorage.getItem('zolo_sidebar_state');
          if (s === 'icon-only') document.currentScript.parentElement.classList.add('sidebar-icon-only');
          else if (s === 'full-hide') document.currentScript.parentElement.classList.add('sidebar-full-hide', 'active');
          if (localStorage.getItem('zolo_topbar_hidden') === '1') {
            document.documentElement.classList.add('topbar-hidden');
          }
        } catch(e) {}
      })();
    </script>
    <!-- navbar-->
    @if(Route::currentRouteName() != 'sale.pos')
    <div id="reveal-topbar-strip" class="reveal-topbar-strip" title="Click to show top bar (Shortcut: Ctrl+Shift+F)">
      <i class="dripicons-chevron-down"></i> Show Top Bar (Ctrl+Shift+F)
    </div>
    <header class="container-fluid">
      <nav class="navbar">
        <a id="toggle-btn" href="#" class="menu-btn zolo-sidebar-toggle" title="Collapse to icons (1st tap)" data-state="expanded" aria-label="Toggle sidebar navigation" role="button">
          <div class="toggle-icon-bars">
            <span class="bar bar-1"></span>
            <span class="bar bar-2"></span>
            <span class="bar bar-3"></span>
          </div>
          <div class="toggle-state-indicator">
            <span class="dot dot-1"></span>
            <span class="dot dot-2"></span>
            <span class="dot dot-3"></span>
          </div>
        </a>

        @hasSection('navbar-center')
          <div class="navbar-center-area flex-grow-1 mx-2 d-flex align-items-center justify-content-between overflow-hidden u-minw-0">
            @yield('navbar-center')
          </div>
        @endif

        <ul class="nav-menu list-unstyled d-flex flex-md-row align-items-md-center">
          @if($activeCompany)
          <li class="nav-item company-switch"><a class="company-switch-link" href="{{ route('company.select', request()->is('workspace*') ? ['manage' => 1, 'return' => 'workspace'] : ['manage' => 1]) }}" title="Switch company" aria-label="Switch company: {{ $activeCompany->trade_name ?: $activeCompany->legal_name }}">
            <span class="company-switch-mark" aria-hidden="true">{{ collect(preg_split('/\s+/', trim($activeCompany->trade_name ?: $activeCompany->legal_name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</span>
            @if($activeCompany->group)<span class="company-switch-group">{{ $activeCompany->group->name }}</span>@endif
            <span class="company-switch-name">{{ $activeCompany->trade_name ?: $activeCompany->legal_name }}</span>
            @if($activeYear)<span class="company-switch-year">{{ $activeYear }}</span>@endif
            <span class="company-switch-caret" aria-hidden="true">&#8645;</span></a></li>
          @endif
          @unless($companyContext)
          <div class="dropdown">

            <a class="btn-pos btn-sm" type="button" data-toggle="dropdown" aria-expanded="false">
              <i class="dripicons-plus"></i>
            </a>
            <ul class="dropdown-menu">
              <?php
              $category_permission_active = $role_has_permissions_list->where('name', 'category')->first();
              ?>
              @if($category_permission_active)
              <li class="dropdown-item"><a data-toggle="modal" data-target="#category-modal">{{__('db.Add Category') }}</a></li>
              @endif
              <?php
              $add_permission_active = $role_has_permissions_list->where('name', 'products-add')->first();
              ?>
              @if($add_permission_active)
              <li class="dropdown-item"><a href="{{route('products.create') }}">{{__('db.add_product') }}</a></li>
              @endif
              <?php
              $add_permission_active = $role_has_permissions_list->where('name', 'purchases-add')->first();
              ?>
              @if($add_permission_active)
              <li class="dropdown-item"><a href="{{route('purchases.create') }}">{{ __('db.Add Purchase') }}</a></li>
              @endif
              <?php
              $sale_add_permission_active = $role_has_permissions_list->where('name', 'sales-add')->first();
              ?>
              @if($sale_add_permission_active)
              <li class="dropdown-item"><a href="{{route('sales.create') }}">{{ __('db.Add Sale') }}</a></li>
              @endif
              <?php
              $expense_add_permission_active = $role_has_permissions_list->where('name', 'expenses-add')->first();
              ?>
              @if($expense_add_permission_active)
              <li class="dropdown-item"><a data-toggle="modal" data-target="#expense-modal"> {{ __('db.Add Expense') }}</a></li>
              @endif
              <?php
              $quotation_add_permission_active = $role_has_permissions_list->where('name', 'quotes-add')->first();
              ?>
              @if($quotation_add_permission_active)
              <li class="dropdown-item"><a href="{{route('quotations.create') }}">{{ __('db.Add Quotation') }}</a></li>
              @endif
              <?php
              $transfer_add_permission_active = $role_has_permissions_list->where('name', 'transfers-add')->first();
              ?>
              @if($transfer_add_permission_active)
              <li class="dropdown-item"><a href="{{route('transfers.create') }}">{{ __('db.Add Transfer') }}</a></li>
              @endif
              <?php
              $return_add_permission_active = $role_has_permissions_list->where('name', 'returns-add')->first();
              ?>
              @if($return_add_permission_active)
              <li class="dropdown-item"><a href="#" data-toggle="modal" data-target="#add-sale-return"> {{ __('db.Add Return') }}</a></li>
              @endif
              <?php
              $purchase_return_add_permission_active = $role_has_permissions_list->where('name', 'purchase-return-add')->first();
              ?>
              @if($purchase_return_add_permission_active)
              <li class="dropdown-item"><a href="#" data-toggle="modal" data-target="#add-purchase-return"> {{ __('db.Add Purchase Return') }}</a></li>
              @endif
              <?php
              $user_add_permission_active = $role_has_permissions_list->where('name', 'users-add')->first();
              ?>
              @if($user_add_permission_active)
                <li class="dropdown-item"><a href="{{route('user.create') }}">{{ __('db.Add User') }}</a></li>
              @endif
              <?php
              $customer_add_permission_active = $role_has_permissions_list->where('name', 'customers-add')->first();
              ?>
              @if($customer_add_permission_active)
              <li class="dropdown-item"><a href="{{route('customer.create') }}">{{ __('db.Add Customer') }}</a></li>
              @endif
              <?php
              $biller_add_permission_active = $role_has_permissions_list->where('name', 'billers-add')->first();
              ?>
              @if($biller_add_permission_active)
              <li class="dropdown-item"><a href="{{route('biller.create') }}">{{ __('db.Add Biller') }}</a></li>
              @endif
              <?php
              $supplier_add_permission_active = $role_has_permissions_list->where('name', 'suppliers-add')->first();
              ?>
              @if($supplier_add_permission_active)
              <li class="dropdown-item"><a href="{{route('supplier.create') }}">{{ __('db.Add Supplier') }}</a></li>
              @endif
            </ul>
          </div>
          @endunless
          <?php
          $empty_database_permission_active = $role_has_permissions_list->where('name', 'empty_database')->first();

          $sale_add_permission_active = $role_has_permissions_list->where('name', 'sales-add')->first();

          $product_qty_alert_active = $role_has_permissions_list->where('name', 'product-qty-alert')->first();

          $general_setting_permission_active = $role_has_permissions_list->where('name', 'general_setting')->first();

          $language_setting_active = $role_has_permissions_list->where('name', 'language_setting')->first();

          ?>
          {{-- POS button hidden for now (2026-10-09).
          @if($sale_add_permission_active)
          <li class="nav-item"><a class="btn-pos btn-sm" href="{{route('sale.pos') }}"><i class="dripicons-shopping-bag"></i><span> POS</span></a></li>
          @endif
          --}}
          <li class="nav-item"><button type="button" class="topbar-shortcuts" data-shortcut-help title="Keyboard shortcuts (?)"><i class="dripicons-information" aria-hidden="true"></i><span>Shortcuts</span><kbd>?</kbd></button></li>
          <li class="nav-item d-none d-lg-block"><a id="switch-theme" data-toggle="tooltip" title="{{ __('Switch Theme') }}"><i class="dripicons-brightness-max"></i></a></li>
          @if(config('database.connections.zoloerp_landlord'))
          <li class="nav-item"><a target="_blank" href="{{'https://'.env('CENTRAL_DOMAIN').'/contact-for-renewal?id='.$subdomain}}" data-toggle="tooltip" title="{{ __('Renew Subscription') }}"><i class="dripicons-clockwise"></i></a></li>
          @endif
          <li class="nav-item d-none d-lg-block"><a id="btnFullscreen" data-toggle="tooltip" title="{{ __('Full Screen') }}"><i class="dripicons-expand"></i></a></li>
          {{-- Cash register list hidden for now (2026-10-09).
          @if(\Auth::user()->role_id <= 2) <li class="nav-item"><a href="{{route('cashRegister.index') }}" data-toggle="tooltip" title="{{ __('Cash Register List') }}"><i class="dripicons-archive"></i></a></li>
            @endif
          --}}
            @unless($companyContext)
            @php
            $total_notifications = $alert_product + $dso_alert_product_no + $expire_alert_products + Auth::user()->unreadNotifications->where('data.reminder_date', date('Y-m-d'))->count();
            @endphp

            <li class="nav-item" id="notification-icon">

                <a rel="nofollow" data-toggle="tooltip" title="{{ __('Notifications') }}" class="nav-link dropdown-item">
                    <i class="dripicons-bell"></i>

                    @if($product_qty_alert_active && $total_notifications > 0)
                        <span class="badge badge-danger notification-number">{{ $total_notifications }}</span>
                    @endif
                </a>

                <ul class="right-sidebar">

                    {{-- No notifications --}}
                    @if($total_notifications == 0)
                    <li class="notifications text-center">
                        <span class="text-muted">No notifications available</span>
                    </li>
                    @else

                        {{-- Quantity Alert --}}
                        @if($alert_product > 0)
                        <li class="notifications">
                            <a href="{{ route('report.qtyAlert') }}" class="btn btn-link">
                                {{ $alert_product }} product exceeds alert quantity
                            </a>
                        </li>
                        @endif

                        {{-- DSO Alert --}}
                        @if($dso_alert_product_no > 0)
                        <li class="notifications">
                            <a href="{{ route('report.dailySaleObjective') }}" class="btn btn-link">
                                {{ $dso_alert_product_no }} product could not fulfill daily sale objective
                            </a>
                        </li>
                        @endif

                        {{-- Expire Alert --}}
                        @if($expire_alert_products > 0)
                        <li class="notifications">
                            <a href="{{ route('report.productExpiry') }}" class="btn btn-link">
                                {{ $expire_alert_products }} product will expire within {{ $product_qty_alert_active->expiry_alert_days ?? 0  }} days
                            </a>
                        </li>
                        @endif

                        {{-- Reminder Notifications --}}
                        @foreach(Auth::user()->unreadNotifications->where('data.reminder_date', date('Y-m-d')) as $notification)
                        <li class="notifications">
                            @if($notification->data['document_name'])
                            <a target="_blank" href="{{ route('documents.file', ['notification', $notification->data['document_name']]) }}" class="btn btn-link">
                                {{ $notification->data['message'] }}
                            </a>
                            @else
                            <a href="#" class="btn btn-link">{{ $notification->data['message'] }}</a>
                            @endif
                        </li>
                        @endforeach

                    @endif

                </ul>
            </li>
            @endunless
            {{-- Language menu hidden for now (2026-10-09).
            <li class="nav-item">
              <a rel="nofollow" title="{{ __('db.language') }}" data-toggle="tooltip" class="nav-link dropdown-item"><i class="dripicons-web"></i></a>
              <ul class="right-sidebar">
                @foreach ($languages as $language)
                <li>
                    <a href="{{ url('language_switch/'.$language->id) }}" class="btn btn-link">{{ $language->name }}</a>
                </li>
                @endforeach

                @if (!config('app.user_verified'))
                  @if ($language_setting_active)
                      <li id="languages" class="u-bg-xf0f0f0 u-p-10px u-r-5px">
                          <a href="{{route('languages')}}"> {{__('db.Languages')}} <span class="u-fs-16px">→</span></i></a>
                      </li>
                  @endif
                @endif
              </ul>
            </li>
            --}}
            <li class="nav-item">
              <a rel="nofollow" data-toggle="tooltip" class="nav-link dropdown-item"><i class="dripicons-user"></i> <span>{{ucfirst(Auth::user()->name)}}</span> <i class="fa fa-angle-down"></i>
              </a>
              <ul class="right-sidebar">
                <li>
                  <a href="{{route('user.profile', ['id' => Auth::id()])}}"><i class="dripicons-user"></i> {{ __('db.profile') }}</a>
                </li>
                @if($general_setting_permission_active)
                <li>
                  <a href="{{route('setting.general') }}"><i class="dripicons-gear"></i> {{ __('db.settings') }}</a>
                </li>
                @endif
                <li>
                  <a href="{{url('my-transactions/'.date('Y').'/'.date('m'))}}"><i class="dripicons-swap"></i> {{ __('db.My Transaction') }}</a>
                </li>
                @if(Auth::user()->role_id != 5)
                <li>
                  <a href="{{url('holidays/my-holiday/'.date('Y').'/'.date('m'))}}"><i class="dripicons-vibrate"></i> {{ __('db.My Holiday') }}</a>
                </li>
                @endif
                @if($empty_database_permission_active)
                <li>
                  <a href="#" onclick="event.preventDefault(); var c = prompt('All business data of every company will be lost. Type DELETE ALL DATA to confirm.'); if (c === 'DELETE ALL DATA') { var f = document.getElementById('empty-database-form'); f.confirmation.value = c; f.submit(); }"><i class="dripicons-stack"></i> {{ __('db.Empty Database') }}</a>
                  <form id="empty-database-form" method="POST" action="{{ route('setting.emptyDatabase') }}" style="display:none">@csrf<input type="hidden" name="confirmation" value=""></form>
                </li>
                @endif
                <li>
                  <a href="{{ route('logout') }}" onclick="event.preventDefault();
                                        document.getElementById('logout-form').submit();"><i class="dripicons-power"></i>
                    {{ __('db.logout') }}
                  </a>
                  <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none">
                    @csrf
                  </form>
                </li>
              </ul>
            </li>
        </ul>
      </nav>
    </header>
    @endif


    <div id="content">
      @yield('content')
    </div>

    <footer class="main-footer">
      <div class="container-fluid">
        <div class="row">
          <div class="col-sm-12 text-center">
            <p>&copy; {{ date('Y') }} <strong>Animazon's zoloERP</strong>. All Rights Reserved. &bull; Enterprise ERP &amp; Double-Entry Accounting System</p>
          </div>
        </div>
      </div>
    </footer>

    <!-- notification modal (opened from All Notifications) -->
    @if(!$companyContext || request()->routeIs('notifications.index'))
    <div id="notification-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Send Notification') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'notifications.store', 'method' => 'post', 'files'=> true]) !!}
            <div class="row">
              <?php
              // Only colleagues of the active company: other companies' users and emails stay private.
              $notifyCompany = request()->attributes->get(\App\Services\Platform\CompanyContext::class)?->companyId ?? session('company_id');
              $lims_user_list = DB::table('users')->where([
                ['is_active', true],
                ['id', '!=', \Auth::user()->id]
              ])->when(\Illuminate\Support\Facades\Schema::hasTable('company_user'), fn ($query) => $query->whereIn('id',
                  DB::table('company_user')->where('company_id', $notifyCompany ?? 0)->select('user_id')))->get();
              ?>
              <div class="col-md-4 form-group">
                <input type="hidden" name="sender_id" value="{{\Auth::id()}}">
                <label>{{ __('User') }} *</label>
                <select name="receiver_id" class="selectpicker form-control" required data-live-search="true" data-live-search-style="begins" title="Select user...">
                  @foreach($lims_user_list as $user)
                  <option value="{{$user->id}}">{{$user->name . ' (' . ($user->email ?? ''). ')'}}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-4 form-group">
                <label>{{ __('Reminder Date') }}</label>
                <input type="text" name="reminder_date" class="form-control date" value="{{date('d-m-Y') }}">
              </div>
              <div class="col-md-4 form-group">
                <label>{{ __('Attach Document') }}</label>
                <input type="file" name="document" class="form-control">
              </div>
              <div class="col-md-12 form-group">
                <label>{{ __('Message') }} *</label>
                <textarea rows="5" name="message" class="form-control" required></textarea>
              </div>
            </div>
            <div class="form-group">
              <button type="submit" class="btn btn-primary ">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end notification modal -->
    @endif

    <!-- Category Modal -->
    <div id="category-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          {!! Form::open(['route' => 'category.store', 'method' => 'post', 'files' => true, 'id' => 'category-form']) !!}
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Add Category') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            <div class="row">
              <div class="col-md-6 form-group">
                <label>{{ __('name') }} *</label>
                {{Form::text('name',null,array('required' => 'required', 'class' => 'form-control', 'placeholder' => __('db.Type category name')))}}
                <x-validation-error fieldName="name" />
              </div>
              <div class="col-md-6 form-group">
                <label for="category-default-hsn">Item group default HSN / SAC</label>
                <input id="category-default-hsn" name="default_hsn_code" class="form-control" maxlength="8" inputmode="numeric" placeholder="Optional creation default">
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Image') }}</label>
                <input type="file" name="image" class="form-control">
                <x-validation-error fieldName="image" />
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Parent Category') }}</label>
                <select name="parent_id" class="form-control selectpicker" id="parent">
                  <option value="">No {{ __('parent') }}</option>
                  @foreach(($categories_list ?? []) as $category)
                  <option value="{{$category->id}}">{{$category->name}}</option>
                  @endforeach
                </select>
                <x-validation-error fieldName="parent_id" />
              </div>
              @if (\Schema::hasColumn('categories', 'woocommerce_category_id'))
              <div class="col-md-6 form-group mt-4">
                <input class="mt-3" name="is_sync_disable" type="checkbox" id="is_sync_disable" value="1">&nbsp; {{ __('Disable Woocommerce Sync') }}
                <x-validation-error fieldName="is_sync_disable" />
              </div>
              @endif

              @if(in_array('ecommerce',explode(',',$general_setting->modules)))
              <div class="col-md-12 mt-3">
                <h6><strong>{{ __('For Website') }}</strong></h6>
                <hr>
              </div>

              <div class="col-md-6 form-group">
                <label>{{ __('Icon') }}</label>
                <input type="file" name="icon" class="form-control">
              </div>
              <div class="col-md-6 form-group">
                <input class="mt-5" type="checkbox" name="featured" id="featured" value="1"> <label>{{ __('List on category dropdown') }}</label>
              </div>
              @endif
            </div>

            @if(in_array('ecommerce',explode(',',$general_setting->modules)))
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
              <input type="hidden" class="category-ajax-check" name="ajax" value="0">
              <button type="submit" class="btn btn-primary category-submit-btn">{{ __('submit') }}</button>
            </div>
          </div>
          {{ Form::close() }}
        </div>
      </div>
    </div>
    <!-- Category Modal -->

    <!-- expense modal -->
    {{-- With a company selected, the expense and income modals render only on their own list pages (their Add buttons);
         the other legacy modals stay off. --}}
    @if(!$companyContext || request()->routeIs('expenses.index'))
    <div id="expense-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog modal-dialog-centered">
        <div class="modal-content u-r-8px u-ov-hidden u-b-1px-solid-border-strong u-sh-0-10px-25px-5px-rgba-15-23-42-0_2">
          <div class="modal-header u-bg-ink u-c-xffffff u-p-12px-18px u-bb-none">
            <h5 id="exampleModalLabel" class="modal-title font-weight-bold u-fs-15px u-c-xffffff">
              <i class="dripicons-wallet mr-1"></i> {{ __('Add Expense') }}
            </h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close u-c-xffffff u-op-0_8"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'expenses.store', 'method' => 'post','files' => true]) !!}
            <?php
            $lims_expense_category_list = DB::table('expense_categories')->where('is_active', true)->get();
            if (Auth::user()->role_id > 2)
              $lims_warehouse_list = \App\Models\Warehouse::where([
                ['is_active', true],
                ['id', Auth::user()->warehouse_id]
              ])->get();
            else
              $lims_warehouse_list = \App\Models\Warehouse::where('is_active', true)->get();
            $lims_account_list = \App\Models\Account::where('is_active', true)->get();
            ?>
            <div class="row">
              <div class="col-md-6 form-group">
                <label>{{ __('Date') }}</label>
                <input type="text" name="created_at" class="form-control date" placeholder="{{__('db.Choose date')}}" value="{{date($general_setting->date_format,strtotime('now'))}}"/>
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Expense Category') }} *</label>
                <select name="expense_category_id" class="selectpicker form-control" required data-live-search="true" data-live-search-style="begins" title="Select Expense Category...">
                    <option value="0">Employee Expense</option>
                    @foreach($lims_expense_category_list as $expense_category)
                  <option value="{{$expense_category->id}}">{{$expense_category->name . ' (' . $expense_category->code. ')'}}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Warehouse') }} *</label>
                <select name="warehouse_id" class="selectpicker form-control" required data-live-search="true" data-live-search-style="begins" title="Select Warehouse...">
                  @foreach($lims_warehouse_list as $warehouse)
                  <option value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Amount') }} *</label>
                <input type="number" name="amount" step="any" required class="form-control">
              </div>
              <div class="col-md-12 u-w-100pct" id="employee-fields" style="display:none" >
                <div class="row">
                        <div class="col-md-6 form-group">
                            <label>{{ __('db.select Employee') }}</label>
                            <select id="expense-employee" name="employee_id" class="selectpicker form-control"
                                data-live-search="true" title="Select Employee...">
                                @foreach(\App\Models\Employee::where('is_active', 1)->get() as $emp)
                                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>{{ __('db.Select Type') }} *</label>
                            <select id="expense-type" name="expense_type" class="selectpicker form-control" disabled>
                                <option value="">{{ __('db.Select Type') }}</option>
                            </select>
                        </div>

                    </div>
              <div class="col-md-6 form-group">
                <label> {{ __('Account') }}</label>
                <select class="form-control selectpicker" name="account_id">
                  @foreach($lims_account_list as $account)
                  @if($account->is_default)
                  <option selected value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                  @else
                  <option value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                  @endif
                  @endforeach
                </select>
              </div>




                <div class="col-md-6">
                        <div class="form-group">
                            <label>{{__('db.Attach Document')}}</label>
                            <i class="dripicons-question" data-toggle="tooltip" title="Only jpg, jpeg, png, gif, pdf, csv, docx, xlsx and txt file is supported"></i>
                            <input type="file" name="document" class="form-control" />
                            @if($errors->has('extension'))
                                <span>
                                    <strong>{{ $errors->first('extension') }}</strong>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

            </div>

            <div class="form-group">
              <label>{{ __('Note') }}</label>
              <textarea name="note" rows="3" class="form-control"></textarea>
            </div>
            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    @endif
    <!-- end expense modal -->
    <!-- income modal start -->
    @if(!$companyContext || request()->routeIs('incomes.index'))
    <div id="income-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog modal-dialog-centered">
        <div class="modal-content u-r-8px u-ov-hidden u-b-1px-solid-border-strong u-sh-0-10px-25px-5px-rgba-15-23-42-0_2">
          <div class="modal-header u-bg-ink u-c-xffffff u-p-12px-18px u-bb-none">
            <h5 id="exampleModalLabel" class="modal-title font-weight-bold u-fs-15px u-c-xffffff">
              <i class="dripicons-rocket mr-1"></i> {{ __('Add Income') }}
            </h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close u-c-xffffff u-op-0_8"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'incomes.store', 'method' => 'post']) !!}
            <?php
            $lims_income_category_list = DB::table('income_categories')->where('is_active', true)->get();
            if (Auth::user()->role_id > 2)
              $lims_warehouse_list = \App\Models\Warehouse::where([
                ['is_active', true],
                ['id', Auth::user()->warehouse_id]
              ])->get();
            else
              $lims_warehouse_list = \App\Models\Warehouse::where('is_active', true)->get();
            $lims_account_list = \App\Models\Account::where('is_active', true)->get();
            ?>
            <div class="row">
              <div class="col-md-6 form-group">
                <label>{{ __('Date') }}</label>
                <input type="text" name="created_at" class="form-control date" placeholder="{{__('db.Choose date')}}" value="{{date($general_setting->date_format,strtotime('now'))}}"/>
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Income Category') }} *</label>
                <select name="income_category_id" class="selectpicker form-control" required data-live-search="true" data-live-search-style="begins" title="Select Income Category...">
                  @foreach($lims_income_category_list as $income_category)
                  <option value="{{$income_category->id}}">{{$income_category->name . ' (' . $income_category->code. ')'}}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Warehouse') }} *</label>
                <select name="warehouse_id" class="selectpicker form-control" required data-live-search="true" data-live-search-style="begins" title="Select Warehouse...">
                  @foreach($lims_warehouse_list as $warehouse)
                  <option value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-6 form-group">
                <label>{{ __('Amount') }} *</label>
                <input type="number" name="amount" step="any" required class="form-control">
              </div>
              <div class="col-md-6 form-group">
                <label> {{ __('Account') }}</label>
                <select class="form-control selectpicker" name="account_id">
                  @foreach($lims_account_list as $account)
                  @if($account->is_default)
                  <option selected value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                  @else
                  <option value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                  @endif
                  @endforeach
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>{{ __('Note') }}</label>
              <textarea name="note" rows="3" class="form-control"></textarea>
            </div>
            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    @endif
    <!-- income modal end -->

    @unless($companyContext)
    <!-- sale return modal -->
    <div id="add-sale-return" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          {!! Form::open(['route' => 'return-sale.create', 'method' => 'get']) !!}
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">Add Sale Return</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            <div class="row">
              <div class="col-md-6">
                <div class="form-group">
                  <label>{{ __('Sale Reference') }} *</label>
                  <input type="text" name="reference_no" class="form-control">
                </div>
              </div>
            </div>
            {{Form::submit('Submit', ['class' => 'btn btn-primary'])}}
          </div>
          {!! Form::close() !!}
        </div>
      </div>
    </div>
    <!-- end sale return modal -->

    <!-- purchase return modal -->
    <div id="add-purchase-return" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          {!! Form::open(['route' => 'return-purchase.create', 'method' => 'get']) !!}
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">Add Purchase Return</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            <div class="row">
              <div class="col-md-6">
                <div class="form-group">
                  <label>{{ __('Purchase Reference') }} *</label>
                  <input type="text" name="reference_no" class="form-control">
                </div>
              </div>
            </div>
            {{Form::submit('Submit', ['class' => 'btn btn-primary'])}}
          </div>
          {!! Form::close() !!}
        </div>
      </div>
    </div>
    <!-- end purchase return modal -->

    <!-- account modal -->
    <div id="account-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Add Account') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'accounts.store', 'method' => 'post']) !!}
            <div class="form-group">
              <label>{{ __('Account No') }} *</label>
              <input type="text" name="account_no" required class="form-control">
            </div>
            <div class="form-group">
              <label>{{ __('name') }} *</label>
              <input type="text" name="name" required class="form-control">
            </div>
            <div class="form-group">
              <label>{{ __('Initial Balance') }}</label>
              <input type="number" name="initial_balance" step="any" class="form-control">
            </div>
            <div class="form-group">
              <label>{{ __('Note') }}</label>
              <textarea name="note" rows="3" class="form-control"></textarea>
            </div>
            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end account modal -->

    <!-- account statement modal -->
    <div id="account-statement-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Account Statement') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'accounts.statement', 'method' => 'post']) !!}
            <div class="row">
              <div class="col-md-6 form-group">
                <label> {{ __('Account') }}</label>
                <select class="form-control selectpicker" name="account_id">
                  @foreach($lims_account_list as $account)
                  <option value="{{$account->id}}">{{$account->name}} [{{$account->account_no}}]</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-6 form-group">
                <label> {{ __('Type') }}</label>
                <select class="form-control selectpicker" name="type">
                  <option value="0">{{ __('All') }}</option>
                  <option value="1">{{ __('Debit') }}</option>
                  <option value="2">{{ __('Credit') }}</option>
                </select>
              </div>
              <div class="col-md-12 form-group">
                <label>{{ __('Choose Your Date') }}</label>
                <div class="input-group">
                  <input type="text" class="account-statement-daterangepicker-field form-control" required />
                  <input type="hidden" name="start_date" />
                  <input type="hidden" name="end_date" />
                </div>
              </div>
            </div>
            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end account statement modal -->

    <!-- warehouse modal -->
    <div id="warehouse-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Warehouse Report') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'report.warehouse', 'method' => 'post']) !!}

            <div class="form-group">
              <label>{{ __('Warehouse') }} *</label>
              <select name="warehouse_id" class="selectpicker form-control" required data-live-search="true" id="warehouse-id" data-live-search-style="begins" title="Select warehouse...">
                @foreach($lims_warehouse_list as $warehouse)
                <option value="{{$warehouse->id}}">{{$warehouse->name}}</option>
                @endforeach
              </select>
            </div>

            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
            <input type="hidden" name="end_date" value="{{date('Y-m-d') }}" />

            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end warehouse modal -->

    <!-- user modal -->
    <div id="user-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('User Report') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'report.user', 'method' => 'post']) !!}
            <?php
            $lims_user_list = DB::table('users')->where('is_active', true)->get();
            ?>
            <div class="form-group">
              <label>{{ __('User') }} *</label>
              <select name="user_id" class="selectpicker form-control" required data-live-search="true" id="user-id" data-live-search-style="begins" title="Select user...">
                @foreach($lims_user_list as $user)
                <option value="{{$user->id}}">{{$user->name . ' (' . $user->phone. ')'}}</option>
                @endforeach
              </select>
            </div>

            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
            <input type="hidden" name="end_date" value="{{date('Y-m-d') }}" />

            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end user modal -->

    <!-- biller modal -->
    <div id="biller-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Biller Report') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'report.biller', 'method' => 'post']) !!}
            <?php
            $lims_biller_list = DB::table('billers')->where('is_active', true)->get();
            ?>
            <div class="form-group">
              <label>{{ __('Biller') }} *</label>
              <select name="biller_id" class="selectpicker form-control" required data-live-search="true" id="user-id" data-live-search-style="begins" title="Select biller...">
                @foreach($lims_biller_list as $biller)
                <option value="{{$biller->id}}">{{$biller->name . ' (' . $biller->phone_number. ')'}}</option>
                @endforeach
              </select>
            </div>

            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
            <input type="hidden" name="end_date" value="{{date('Y-m-d') }}" />

            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end biller modal -->

    <!-- customer modal -->
    <div id="customer-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Customer Report') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'report.customer', 'method' => 'post']) !!}
            <?php
            $lims_customer_list = DB::table('customers')->where('is_active', true)->get();
            ?>
            <div class="form-group">
              <label>{{ __('customer') }} *</label>
              <select name="customer_id" class="selectpicker form-control" required data-live-search="true" id="customer-id" data-live-search-style="begins" title="Select customer...">
                @foreach($lims_customer_list as $customer)
                <option value="{{$customer->id}}">{{$customer->name . ' (' . $customer->phone_number. ')'}}</option>
                @endforeach
              </select>
            </div>

            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
            <input type="hidden" name="end_date" value="{{date('Y-m-d') }}" />

            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end customer modal -->

    <!-- customer group modal -->
    <div id="customer-group-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Customer Group Report') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'report.customer_group', 'method' => 'post']) !!}
            <?php
            $lims_customer_group_list = DB::table('customer_groups')->where('is_active', true)->get();
            ?>
            <div class="form-group">
              <label>{{ __('Customer Group') }} *</label>
              <select name="customer_group_id" class="selectpicker form-control" required data-live-search="true" id="customer-group-id" data-live-search-style="begins" title="Select customer group...">
                @foreach($lims_customer_group_list as $customer_group)
                <option value="{{$customer_group->id}}">{{$customer_group->name}}</option>
                @endforeach
              </select>
            </div>

            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
            <input type="hidden" name="end_date" value="{{date('Y-m-d') }}" />

            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end customer group modal -->

    <!-- supplier modal -->
    <div id="supplier-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true" class="modal fade text-left">
      <div role="document" class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 id="exampleModalLabel" class="modal-title">{{ __('Supplier Report') }}</h5>
            <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="dripicons-cross"></i></span></button>
          </div>
          <div class="modal-body">
            <p class="italic"><small>{{ __('The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'report.supplier', 'method' => 'post']) !!}
            <?php
            $lims_supplier_list = DB::table('suppliers')->where('is_active', true)->get();
            ?>
            <div class="form-group">
              <label>{{ __('Supplier') }} *</label>
              <select name="supplier_id" class="selectpicker form-control" required data-live-search="true" id="supplier-id" data-live-search-style="begins" title="Select Supplier...">
                @foreach($lims_supplier_list as $supplier)
                <option value="{{$supplier->id}}">{{$supplier->name . ' (' . $supplier->phone_number. ')'}}</option>
                @endforeach
              </select>
            </div>

            <input type="hidden" name="start_date" value="{{date('Y-m').'-'.'01'}}" />
            <input type="hidden" name="end_date" value="{{date('Y-m-d') }}" />

            <div class="form-group">
              <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
            </div>
            {{ Form::close() }}
          </div>
        </div>
      </div>
    </div>
    <!-- end supplier modal -->
    @endunless
  </div>
  @if(!config('database.connections.zoloerp_landlord'))
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/jquery.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/jquery-ui.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/bootstrap-datepicker.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/jquery.timepicker.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/popper.js/umd/popper.min.js') ?>">
  </script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/bootstrap/js/bootstrap.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/bootstrap-toggle/js/bootstrap-toggle.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/bootstrap/js/bootstrap-select.min.js') ?>"></script>
  @if(Route::currentRouteName() == 'sale.pos' || View::hasSection('virtual-keyboard'))
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/keyboard/js/jquery.keyboard.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/keyboard/js/jquery.keyboard.extension-autocomplete.js') ?>"></script>
  @endif
  <script type="text/javascript" src="<?php echo versioned_asset('js/grasp_mobile_progress_circle-1.0.0.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery.cookie/jquery.cookie.js') ?>"></script>
  @hasSection('charts')
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/chart.js/Chart.min.js') ?>"></script>
  <script type="text/javascript" src="{{ versioned_asset('js/charts-custom.js') }}"></script>
  @endif
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery-validation/jquery.validate.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/malihu-custom-scrollbar-plugin/jquery.mCustomScrollbar.concat.min.js') ?>"></script>
  @if( Config::get('app.locale') == 'ar' || $general_setting->is_rtl)
  <script type="text/javascript" src="<?php echo versioned_asset('js/front_rtl.js') ?>"></script>
  @else
  <script type="text/javascript" src="<?php echo versioned_asset('js/front.js') ?>"></script>
  @endif

  @if(Route::currentRouteName() != '/')
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/daterange/js/moment.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/daterange/js/knockout-3.4.2.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/daterange/js/daterangepicker.min.js') ?>"></script>
  @hasSection('rich-editor')
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/tinymce/js/tinymce/tinymce.min.js') ?>"></script>
  @endif
  @hasSection('image-upload')
  <script type="text/javascript" src="<?php echo versioned_asset('js/dropzone.js') ?>"></script>
  @endif

  <!-- table sorter js-->
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/jquery.dataTables.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/dataTables.bootstrap4.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/dataTables.buttons.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.bootstrap4.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.colVis.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.html5.min.js') ?>"></script>
  <script src="{{ versioned_asset('js/table-exports.js') }}" data-base="{{ asset('vendor/datatable') }}" data-arabic="{{ Config::get('app.locale') == 'ar' ? '1' : '0' }}"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.printnew.js') ?>"></script>

  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/sum().js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/dataTables.checkboxes.min.js') ?>"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/datatable/dataTables.fixedHeader.min.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/datatable/dataTables.responsive.min.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/datatable/responsive.bootstrap.min.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('js/table-polish.js') }}"></script>
  @endif
  @else
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/jquery.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/jquery-ui.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/bootstrap-datepicker.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery/jquery.timepicker.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/popper.js/umd/popper.min.js') ?>">
  </script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/bootstrap/js/bootstrap.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/bootstrap-toggle/js/bootstrap-toggle.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/bootstrap/js/bootstrap-select.min.js') ?>"></script>
  @if(Route::currentRouteName() == 'sale.pos' || View::hasSection('virtual-keyboard'))
  <script type="text/javascript" src="{{ versioned_asset('vendor/keyboard/js/jquery.keyboard.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/keyboard/js/jquery.keyboard.extension-autocomplete.js') }}"></script>
  @endif

  <script type="text/javascript" src="<?php echo versioned_asset('js/grasp_mobile_progress_circle-1.0.0.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery.cookie/jquery.cookie.js') ?>"></script>
  @hasSection('charts')
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/chart.js/Chart.min.js') ?>"></script>
  <script type="text/javascript" src="{{ versioned_asset('js/charts-custom.js') }}"></script>
  @endif
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/jquery-validation/jquery.validate.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/malihu-custom-scrollbar-plugin/jquery.mCustomScrollbar.concat.min.js') ?>"></script>
  @if( Config::get('app.locale') == 'ar' || $general_setting->is_rtl)
  <script type="text/javascript" src="<?php echo versioned_asset('js/front_rtl.js') ?>"></script>
  @else
  <script type="text/javascript" src="<?php echo versioned_asset('js/front.js') ?>"></script>
  @endif

  @if(Route::currentRouteName() != '/')
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/daterange/js/moment.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/daterange/js/knockout-3.4.2.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/daterange/js/daterangepicker.min.js') ?>"></script>
  @hasSection('rich-editor')
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/tinymce/js/tinymce/tinymce.min.js') ?>"></script>
  @endif
  @hasSection('image-upload')
  <script type="text/javascript" src="<?php echo versioned_asset('js/dropzone.js') ?>"></script>
  @endif

  <!-- table sorter js-->
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/jquery.dataTables.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/dataTables.bootstrap4.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/dataTables.buttons.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.bootstrap4.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.colVis.min.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.html5.min.js') ?>"></script>
  <script src="{{ versioned_asset('js/table-exports.js') }}" data-base="{{ asset('vendor/datatable') }}" data-arabic="{{ Config::get('app.locale') == 'ar' ? '1' : '0' }}"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/buttons.printnew.js') ?>"></script>

  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/sum().js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('vendor/datatable/dataTables.checkboxes.min.js') ?>"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/datatable/dataTables.fixedHeader.min.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/datatable/dataTables.responsive.min.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('vendor/datatable/responsive.bootstrap.min.js') }}"></script>
  <script type="text/javascript" src="{{ versioned_asset('js/table-polish.js') }}"></script>

  <script type="text/javascript" src="{{ versioned_asset('js/barcode-qrcode-scanner_plugin.js') }}"></script>

  @endif
  @endif
  @stack('scripts')

  <script type="text/javascript">
    $.ajaxSetup({
      headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
      }
    });
    var theme = <?php echo json_encode($theme); ?>;
    if (theme == 'dark') {
      $('body').addClass('dark-mode');
      $('#switch-theme i').addClass('dripicons-brightness-low');
    } else {
      $('body').removeClass('dark-mode');
      $('#switch-theme i').addClass('dripicons-brightness-max');
    }
    $('#switch-theme').click(function() {
      if (theme == 'light') {
        theme = 'dark';
        var url = <?php echo json_encode(route('switchTheme', 'dark')); ?>;
        $('body').addClass('dark-mode');
        $('#switch-theme i').addClass('dripicons-brightness-low');
      } else {
        theme = 'light';
        var url = <?php echo json_encode(route('switchTheme', 'light')); ?>;
        $('body').removeClass('dark-mode');
        $('#switch-theme i').addClass('dripicons-brightness-max');
      }

      $.get(url, function(data) {
        console.log('theme changed to ' + theme);
      });
    });

    var alert_product = <?php echo json_encode($alert_product) ?>;

    if ($(window).outerWidth() > 1199 && !localStorage.getItem('zolo_sidebar_state')) {
      $('nav.side-navbar').removeClass('shrink');
    }

    function showPage() {
      document.getElementById("loader").style.display = "none";
      document.getElementById("content").style.display = "block";
      setTimeout(function() {
        window.dispatchEvent(new Event('resize'));
      }, 50);
    }

    $(showPage);

    var user_verified = <?php echo json_encode(config('app.user_verified') ? '1' : '0'); ?>;
    window.user_verified = user_verified;

    $("div.alert").delay(4000);

    // Position table actions in the viewport while preserving the table's scroll boundary.
    function positionTableDropdown(menu) {
      var group = menu.closest('.btn-group, .dropdown');
      var toggle = group.querySelector('.dropdown-toggle');
      if (!toggle) return;
      var rect = toggle.getBoundingClientRect();
      var height = menu.getBoundingClientRect().height;
      var width = menu.getBoundingClientRect().width;
      var below = window.innerHeight - rect.bottom - 8;
      var top = below >= height || below >= rect.top - 8 ? rect.bottom : rect.top - height;
      var left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
      menu.style.setProperty('--zolo-dropdown-left', left + 'px');
      menu.style.setProperty('--zolo-dropdown-top', Math.max(8, Math.min(top, window.innerHeight - height - 8)) + 'px');
    }

    var $ancestorContainers = function($el) {
      return $el.parents('.table-responsive, .card-body, .card, .dataTables_wrapper, .modal-body, .modal-content, section');
    };

    // Register row menus sit in a scrolling viewport inside clipped cards: pin the open menu to the screen next to its
    // button (it stays in the row, so delegated item handlers still match) and close it when the table scrolls.
    var pinnedMenu = ['position', 'transform', 'top', 'left', 'bottom', 'right', 'z-index', 'max-height', 'overflow-y'];
    $(document).on('shown.bs.dropdown', '.comm-table-viewport', function (e) {
      var $menu = $(e.target).children('.dropdown-menu'), $toggle = $(e.target).children('.dropdown-toggle');
      if (!$menu.length || !$toggle.length) return;
      // Popper would re-apply its absolute placement over ours; Bootstrap recreates it on the next open.
      var dropdown = $toggle.data('bs.dropdown');
      if (dropdown && dropdown._popper) { dropdown._popper.destroy(); dropdown._popper = null; }
      var rect = $toggle[0].getBoundingClientRect(), height = $menu.outerHeight(), width = $menu.outerWidth();
      var below = window.innerHeight - rect.bottom - 4, above = rect.top - 4;
      var top = height <= below || below >= above ? rect.bottom + 2 : Math.max(4, rect.top - height - 2);
      var values = {position: 'fixed', transform: 'none', top: top + 'px', left: Math.max(4, rect.right - width) + 'px', bottom: 'auto', right: 'auto',
        'z-index': '1060', 'max-height': Math.max(below, above) + 'px', 'overflow-y': 'auto'};
      $.each(values, function (name, value) { $menu[0].style.setProperty(name, value, 'important'); });
      $menu.attr('data-pinned-menu', '1');
    });
    $(document).on('hidden.bs.dropdown', '.comm-table-viewport', function (e) {
      var menu = $(e.target).children('.dropdown-menu[data-pinned-menu]')[0];
      if (!menu) return;
      pinnedMenu.forEach(function (name) { menu.style.removeProperty(name); });
      menu.removeAttribute('data-pinned-menu');
    });
    document.addEventListener('scroll', function (e) {
      if (e.target.classList && e.target.classList.contains('comm-table-viewport')) {
        $(e.target).find('.dropdown-menu[data-pinned-menu]').siblings('.dropdown-toggle').dropdown('toggle');
      }
    }, true);

    $(document).on('show.bs.dropdown', function (e) {
      var $target = $(e.target);
      var $toggle = $target.find('.dropdown-toggle');
      var $menu = $target.find('.dropdown-menu');
      var $group = $target.closest('.btn-group, .dropdown');

      $ancestorContainers($target).addClass('dropdown-active');

      if ($toggle.length) {
        var offset = $toggle.offset();
        var toggleHeight = $toggle.outerHeight();
        var menuHeight = $menu.outerHeight() || 190;
        var windowHeight = $(window).height();
        var scrollTop = $(window).scrollTop();
        var spaceBelow = windowHeight - (offset.top - scrollTop + toggleHeight);
        var spaceAbove = (offset.top - scrollTop);

        if (spaceBelow < (menuHeight + 20) && spaceAbove > spaceBelow) {
          $group.addClass('dropup');
        } else {
          $group.removeClass('dropup');
        }
      }
    });

    $(document).on('shown.bs.dropdown', function (e) {
      var group = e.target;
      if (!group.closest('.table-responsive') || group.classList.contains('bootstrap-select')) return;
      var menu = group.querySelector('.dropdown-menu');
      if (!menu) return;
      $(menu).data('table-menu-style', menu.getAttribute('style'));
      menu.classList.add('zolo-table-dropdown');
      positionTableDropdown(menu);
    });

    $(document).on('hidden.bs.dropdown', function (e) {
      var menu = e.target.querySelector('.zolo-table-dropdown');
      if (menu) {
        menu.classList.remove('zolo-table-dropdown');
        var original = $(menu).data('table-menu-style');
        if (original) menu.setAttribute('style', original);
        else menu.removeAttribute('style');
        $(menu).removeData('table-menu-style');
      }
      var $target = $(e.target);
      $target.closest('.btn-group, .dropdown').removeClass('dropup');
      if (!$('.dropdown.show, .btn-group.show, .bootstrap-select.show, div.dt-button-collection:visible').length) {
        $('.table-responsive, .card-body, .card, .dataTables_wrapper, .modal-body, .modal-content, section').removeClass('dropdown-active');
      }
    });

    window.addEventListener('resize', function () {
      document.querySelectorAll('.zolo-table-dropdown').forEach(positionTableDropdown);
    });
    document.addEventListener('scroll', function (e) {
      document.querySelectorAll('.zolo-table-dropdown').forEach(function (menu) {
        if (!menu.contains(e.target)) positionTableDropdown(menu);
      });
    }, true);

    // 2. DataTables Buttons Collection (ColVis eye button, Export collections)
    $(document).on('click', '.buttons-collection, .buttons-colvis', function () {
      var $btn = $(this);
      $ancestorContainers($btn).addClass('dt-collection-active dropdown-active');

      setTimeout(function () {
        var $collection = $('div.dt-button-collection');
        if ($collection.length) {
          $collection.css({
            'z-index': '99999',
            'max-height': 'calc(100vh - 140px)',
            'overflow-y': 'auto'
          });
          $ancestorContainers($collection).addClass('dt-collection-active dropdown-active');
        }
      }, 20);
    });

    $(document).on('click', '.dt-button-background', function () {
      $('.dt-collection-active').removeClass('dt-collection-active');
      if (!$('.dropdown.show, .btn-group.show, .bootstrap-select.show').length) {
        $('.table-responsive, .card-body, .card, .dataTables_wrapper, section').removeClass('dropdown-active');
      }
    });

    // 3. Bootstrap-Select Dropdowns
    $(document).on('show.bs.select', function (e) {
      $ancestorContainers($(e.target)).addClass('dropdown-active select-active');
    });

    $(document).on('hidden.bs.select', function () {
      if (!$('.dropdown.show, .btn-group.show, .bootstrap-select.show, div.dt-button-collection:visible').length) {
        $('.table-responsive, .card-body, .card, .modal-body, .modal-content, section').removeClass('dropdown-active select-active');
      }
    });

    // 4. MutationObserver for dynamic dropdown popovers
    if (window.MutationObserver) {
      var dtGlobalObserver = new MutationObserver(function () {
        var $col = $('div.dt-button-collection:visible');
        if ($col.length) {
          $ancestorContainers($col).addClass('dt-collection-active dropdown-active');
        } else if (!$('.dropdown.show, .btn-group.show, .bootstrap-select.show').length) {
          $('.dt-collection-active').removeClass('dt-collection-active');
        }
      });
      dtGlobalObserver.observe(document.body, { childList: true, subtree: true });
    }

    function confirmDelete() {
      if (confirm("Are you sure want to delete?")) {
        return true;
      }
      return false;
    }

    $("li#notification-icon").on("click", function(argument) {
      $.get('notifications/mark-as-read', function(data) {
        $("span.notification-number").text(alert_product);
      });
    });

    $("a#add-account").click(function(e) {
      e.preventDefault();
      $('#account-modal').modal();
    });

    $("a#account-statement").click(function(e) {
      e.preventDefault();
      $('#account-statement-modal').modal();
    });

    $("a#customer-report-link").click(function(e) {
      e.preventDefault();
      $('#customer-modal').modal();
    });

    $("a#customer-group-report-link").click(function(e) {
      e.preventDefault();
      $('#customer-group-modal').modal();
    });

    $("a#supplier-report-link").click(function(e) {
      e.preventDefault();
      $('#supplier-modal').modal();
    });

    $("a#due-report-link").click(function(e) {
      e.preventDefault();
      $("#customer-due-report-form").submit();
    });

    $("a#supplier-due-report-link").click(function(e) {
      e.preventDefault();
      $("#supplier-due-report-form").submit();
    });

    $(".account-statement-daterangepicker-field").daterangepicker({
      callback: function(startDate, endDate, period) {
        var start_date = startDate.format('YYYY-MM-DD');
        var end_date = endDate.format('YYYY-MM-DD');
        var title = start_date + ' To ' + end_date;
        $(this).val(title);
        $('#account-statement-modal input[name="start_date"]').val(start_date);
        $('#account-statement-modal input[name="end_date"]').val(end_date);
      }
    });

    $('.date').datepicker({
      format: "dd-mm-yyyy",
      autoclose: true,
      todayHighlight: true
    });

    $('.selectpicker').selectpicker({
      style: 'btn-link',
    });

    // Ensure DataTables length dropdowns remain clean native selects without Popper interference
    $(document).on('init.dt', function(e, settings) {
      var $select = $(settings.nTableWrapper).find('.dataTables_length select');
      if ($select.data('selectpicker')) {
        $select.selectpicker('destroy');
      }
    });


     // on change expense category
    $('select[name="expense_category_id"]').on('changed.bs.select', function () {
        let cat = $(this).val();

        if (cat == "0") {
            // employee expense selected → show employee fields
            $('#employee-fields').show();

            // enable type
            $("#expense-type").prop("disabled", false);

            let options = `
                <option value="expense_load">Expense</option>
                <option value="advance">Advance</option>
            `;
            $("#expense-type").html(options).selectpicker('refresh');

        } else {
            // hide when not employee expense
            $('#employee-fields').hide();

            // reset fields
            $("#expense-employee").val('').selectpicker('refresh');
            $("#expense-type").html('<option value="">Select Type</option>')
                .prop("disabled", true)
                .selectpicker('refresh');
        }
    });

    // Top Bar Toggle & Focus Mode Handler
    $(document).on('click', '#btn-toggle-topbar', function(e) {
        e.preventDefault();
        $('html, body').addClass('topbar-hidden');
        try { localStorage.setItem('zolo_topbar_hidden', '1'); } catch(e) {}
    });
    $(document).on('click', '#reveal-topbar-strip', function(e) {
        e.preventDefault();
        $('html, body').removeClass('topbar-hidden');
        try { localStorage.setItem('zolo_topbar_hidden', '0'); } catch(e) {}
    });
    $(document).on('keydown', function(e) {
        if ((e.ctrlKey && e.shiftKey && (e.key === 'F' || e.key === 'f')) || (e.altKey && (e.key === 't' || e.key === 'T'))) {
            e.preventDefault();
            var isHidden = $('html').hasClass('topbar-hidden') || $('body').hasClass('topbar-hidden');
            if (isHidden) {
                $('html, body').removeClass('topbar-hidden');
                try { localStorage.setItem('zolo_topbar_hidden', '0'); } catch(e) {}
            } else {
                $('html, body').addClass('topbar-hidden');
                try { localStorage.setItem('zolo_topbar_hidden', '1'); } catch(e) {}
            }
        }
    });

  </script>
  <script type="text/javascript" src="<?php echo versioned_asset('js/sidebar-state.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('js/app-shell.js') ?>"></script>
  <script type="text/javascript" src="<?php echo versioned_asset('js/field-help.js') ?>"></script>
</body>

</html>
