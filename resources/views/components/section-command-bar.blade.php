{{-- Dark command bar for a sidebar section (same look as the Product & Inventory / Settings bars): section title,
     one pill per screen the user may open, and the page's own action buttons in the slot.
     Usage: <x-section-command-bar title="People" icon="dripicons-user-group" badge="..." :links="$links" active="users"> buttons </x-section-command-bar>
     Each link: ['key', 'permission', 'route', 'icon', 'label']. --}}
@props(['title', 'icon' => 'dripicons-view-apps', 'badge' => null, 'links' => [], 'active' => null])
@php
    $user = Auth::user();
    $admin = $user && in_array((int) $user->role_id, [1, 2], true);
    $allow = function (string $permission) use ($user, $admin) {
        if ($admin) return true;
        try { return $user && $user->can($permission); } catch (\Throwable $e) { return false; }
    };
    $switches = app(\App\Services\Platform\MenuSwitches::class);
@endphp
@once
    @push('css')
        <link rel="stylesheet" href="{{ versioned_asset('css/product-workspace.css') }}" type="text/css">
    @endpush
@endonce
<div class="comm-command-bar section-command-bar">
    <div class="comm-title-group">
        <h1><i class="{{ $icon }} text-primary"></i> {{ $title }}</h1>
        @if($badge)<span class="comm-title-badge">{{ $badge }}</span>@endif
    </div>
    @php $shown = array_filter($links, fn ($link) => Route::has($link[2]) && $allow($link[1]) && $switches->routeAllowed($link[2])); @endphp
    @if($shown)
        <ul class="comm-nav-pills" aria-label="{{ $title }} screens">
            @foreach($shown as [$key, $permission, $route, $linkIcon, $label])
                <li><a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ route($route) }}" @if($active === $key) aria-current="page" @endif><i class="{{ $linkIcon }}"></i> {{ $label }}</a></li>
            @endforeach
        </ul>
    @endif
    <div class="comm-actions">{{ $slot }}</div>
</div>
