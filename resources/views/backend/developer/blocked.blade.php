<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>License Restricted · zoloERP</title>
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/zolo-components.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/developer.css') }}">
</head>
<body class="dev-blocked-page">
    <main class="dev-blocked-card" role="alert">
        <div>
            <span class="dev-badge dev-badge-blocked">{{ strtoupper($status) }}</span>
        </div>
        <h1>{{ $company ? ($company->trade_name ?: $company->legal_name) : 'Company' }} access paused</h1>
        <p class="dev-muted">{{ $message }}</p>
        <p class="dev-muted">Your accounting books, inventory ledger, and historical vouchers remain safely stored. Contact your platform developer or account manager to renew or reactivate this subscription.</p>
        <div class="dev-blocked-actions">
            <a href="{{ route('company.select', ['manage' => 1]) }}" class="btn btn-primary btn-sm">Switch Company</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm">Sign Out</button>
            </form>
        </div>
    </main>
</body>
</html>
