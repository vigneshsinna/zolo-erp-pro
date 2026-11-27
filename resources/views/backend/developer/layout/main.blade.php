<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Developer Portal · SaaS Licensing & Multi-Group Control')</title>
    <link rel="stylesheet" href="{{ versioned_asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('vendor/dripicons/webfont.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('vendor/font-awesome/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-utilities.css') }}">
    <link rel="stylesheet" href="{{ asset('css/zolo-erp-neo.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-components.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/developer.css') }}">
    @stack('styles')
</head>
<body class="dev-page {{ ($theme ?? 'light') === 'dark' ? 'dark-mode' : '' }}">
<header class="dev-topbar">
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-link dev-toggle-btn text-decoration-none" id="dev-sidebar-toggle" aria-label="Toggle developer sidebar" title="Toggle Navigation">
            <i class="dripicons-menu"></i>
        </button>
        <a href="{{ route('developer.index') }}" class="dev-brand">
            <span class="dev-brand-mark" aria-hidden="true">z</span>
            <div>
                <div class="dev-topbar-title">zoloERP Developer Portal</div>
                <div class="dev-topbar-sub">Multi-Tenant SaaS · Groups, Independent Companies, Licenses &amp; Modules</div>
            </div>
        </a>
    </div>
    <div class="dev-topbar-actions">
        <a href="{{ route('company.select') }}" class="btn btn-secondary btn-sm"><i class="dripicons-exit"></i> ERP Workspace</a>
        <button type="button" class="btn btn-secondary btn-sm" id="dev-theme-toggle" title="Toggle light or dark theme"><i class="dripicons-brightness-max"></i> Theme</button>
        <span class="dev-user-pill"><i class="dripicons-code" aria-hidden="true"></i> {{ Auth::user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">Sign Out</button>
        </form>
    </div>
</header>

<div class="dev-app-layout">
    <nav class="side-navbar dev-side-navbar d-print-none" id="dev-sidebar">
        @include('backend.developer.layout.sidebar')
    </nav>

    <main class="dev-workspace" id="dev-workspace-main">
        @if(session('message'))
            <div class="alert alert-success dev-flash" role="status">{{ session('message') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger dev-flash" role="alert">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</div>

<script src="{{ versioned_asset('vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ versioned_asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script>
(function() {
    // Theme toggle handler
    const btn = document.getElementById('dev-theme-toggle');
    if (btn) {
        btn.addEventListener('click', function() {
            const isDark = document.body.classList.toggle('dark-mode');
            const theme = isDark ? 'dark' : 'light';
            document.cookie = 'theme=' + theme + '; path=/; max-age=' + (60 * 60 * 24 * 365);
        });
    }

    // Sidebar collapse toggle handler
    const toggle = document.getElementById('dev-sidebar-toggle');
    const layout = document.querySelector('.dev-app-layout');
    if (toggle && layout) {
        toggle.addEventListener('click', function() {
            layout.classList.toggle('dev-sidebar-collapsed');
        });
    }

    // Live search for developer menu
    const searchInput = document.getElementById('dev-menu-search');
    const menuItems = document.querySelectorAll('#dev-side-menu li:not(.sidebar-heading)');
    const headings = document.querySelectorAll('#dev-side-menu .sidebar-heading');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            if (!q) {
                menuItems.forEach(el => el.style.display = '');
                headings.forEach(el => el.style.display = '');
                return;
            }
            menuItems.forEach(el => {
                const text = el.textContent.toLowerCase();
                const kw = el.querySelector('a')?.getAttribute('data-keywords') || '';
                const match = text.includes(q) || kw.toLowerCase().includes(q);
                el.style.display = match ? '' : 'none';
            });
            headings.forEach(h => {
                let next = h.nextElementSibling;
                let hasVisible = false;
                while (next && !next.classList.contains('sidebar-heading')) {
                    if (next.style.display !== 'none') {
                        hasVisible = true;
                        break;
                    }
                    next = next.nextElementSibling;
                }
                h.style.display = hasVisible ? '' : 'none';
            });
        });
    }
})();
</script>
@stack('scripts')
</body>
</html>
