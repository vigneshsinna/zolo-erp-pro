{{-- Draft tab strip of a document entry page. public/js/document-drafts.js fills it; the page loads that script with its adapter. --}}
@once
    @push('css')
        <link rel="stylesheet" href="{{ asset('css/document-drafts.css') }}?v={{ filemtime(public_path('css/document-drafts.css')) }}">
    @endpush
@endonce
<div id="document-tabs" class="document-tabs" hidden>
    <div class="document-tabs-lead" title="Multi-draft workspace">
        <i class="fa fa-clone" aria-hidden="true"></i>
        <span class="document-tabs-title">DRAFTS</span>
    </div>
    <div id="document-tab-list" class="document-tab-list" role="tablist" aria-label="Open {{ $draftLabel }} drafts"></div>
    <button type="button" id="document-tab-add" class="document-tab-add" title="New draft{{ !empty($draftKey) ? ' ('.$draftKey.')' : '' }}" aria-label="New draft">
        <i class="fa fa-plus mr-1" aria-hidden="true"></i><span>New Draft</span>
    </button>
    <span id="document-tab-count" class="document-tab-count" title="Open drafts"></span>
    <span id="document-tab-status" class="document-tab-status" role="status" aria-live="polite"></span>
</div>
<div id="document-draft-banner" class="document-draft-banner" role="alert" hidden></div>
