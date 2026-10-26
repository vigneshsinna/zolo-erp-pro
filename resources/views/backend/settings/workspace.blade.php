@extends('backend.layout.main')
@section('title', 'Settings')
@section('content')
{{-- Every settings page on one screen, grouped by area; the filter narrows it as you type. --}}
<section class="settings-workspace">
    <header class="settings-workspace-head">
        <h1>Settings</h1>
        <input type="search" id="settings-filter" class="form-control" placeholder="Find a setting…" aria-label="Find a setting" autocomplete="off" autofocus>
    </header>
    <div class="settings-workspace-grid" id="settings-grid">
        @foreach($sections as $section)
            <section class="settings-group" aria-labelledby="settings-group-{{ $loop->index }}">
                <h2 id="settings-group-{{ $loop->index }}"><i class="{{ $section['icon'] }}" aria-hidden="true"></i> {{ $section['title'] }}</h2>
                <ul class="list-unstyled">
                    @foreach($section['items'] as $item)
                        <li><a href="{{ $item['url'] }}" data-search="{{ strtolower($item['label'].' '.$section['title'].' '.$item['keywords']) }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <p class="settings-empty" id="settings-empty" hidden>No setting matches.</p>
    </div>
</section>
@endsection

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/settings-workspace.css') }}">
@endpush

@push('scripts')
<script>
(function () {
    var input = document.getElementById('settings-filter'), grid = document.getElementById('settings-grid');
    var links = Array.prototype.slice.call(grid.querySelectorAll('a[data-search]'));
    function visible() { return links.filter(function (a) { return !a.parentNode.hidden; }); }
    input.addEventListener('input', function () {
        var words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        links.forEach(function (a) {
            var text = a.getAttribute('data-search');
            a.parentNode.hidden = !words.every(function (w) { return text.indexOf(w) !== -1; });
        });
        grid.querySelectorAll('.settings-group').forEach(function (group) {
            group.hidden = !group.querySelector('li:not([hidden])');
        });
        document.getElementById('settings-empty').hidden = visible().length > 0;
    });
    input.addEventListener('keydown', function (e) {
        var first = visible()[0];
        if (e.key === 'Enter' && first) { e.preventDefault(); window.location.href = first.href; }
        if (e.key === 'ArrowDown' && first) { e.preventDefault(); first.focus(); }
        if (e.key === 'Escape') { input.value = ''; input.dispatchEvent(new Event('input')); }
    });
    grid.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        var list = visible(), at = list.indexOf(document.activeElement);
        if (at === -1) return;
        e.preventDefault();
        if (e.key === 'ArrowUp' && at === 0) input.focus();
        else (list[at + (e.key === 'ArrowDown' ? 1 : -1)] || list[at]).focus();
    });
})();
</script>
@endpush
