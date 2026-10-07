{{-- Central document accelerators. The list is permission-filtered for UX only; every target page authorises again on the server. --}}
<link rel="stylesheet" href="{{ asset('css/document-shortcuts.css') }}?v={{ filemtime(public_path('css/document-shortcuts.css')) }}">
<script type="application/json" id="document-shortcut-data">@json($documentShortcuts)</script>
@push('scripts')
<script src="{{ asset('js/document-shortcuts.js') }}?v={{ filemtime(public_path('js/document-shortcuts.js')) }}"></script>
@endpush
