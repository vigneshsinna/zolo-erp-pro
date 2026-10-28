@extends('backend.layout.main')
@section('title', 'Developer')
@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/developer.css') }}">
@endpush
@section('content')
<x-section-command-bar title="Developer" icon="dripicons-code" badge="Admin logins & company sections" :links="[]" />

@if(session('message'))<div class="alert alert-success dev-flash" role="status">{{ session('message') }}</div>@endif
@if($errors->any())<div class="alert alert-danger dev-flash" role="alert">{{ $errors->first() }}</div>@endif

<section class="dev-console">
    {{-- Admin logins of every company --}}
    <div class="dev-card">
        <h2><i class="dripicons-user-group" aria-hidden="true"></i> Admin logins</h2>
        <form method="POST" action="{{ route('developer.admins.store') }}" class="dev-add-admin">
            @csrf
            <input name="name" class="form-control" placeholder="Name" value="{{ old('name') }}" required maxlength="255" aria-label="Name">
            <input name="email" type="email" class="form-control" placeholder="Email (login)" value="{{ old('email') }}" required maxlength="255" aria-label="Email">
            <input name="password" type="password" class="form-control" placeholder="Password (8+)" required minlength="8" maxlength="100" autocomplete="new-password" aria-label="Password">
            <div class="dev-company-ticks" role="group" aria-label="Companies">
                @foreach($companies as $c)
                    <label><input type="checkbox" name="company_ids[]" value="{{ $c->id }}" @checked(in_array($c->id, old('company_ids', [$company->id ?? 0])))> {{ $c->label }}</label>
                @endforeach
            </div>
            <button class="btn btn-primary"><i class="dripicons-plus"></i> Add admin</button>
        </form>
        <div class="dev-table-wrap">
            <table class="table dev-table">
                <thead><tr><th>Admin</th><th>Companies</th><th>Status</th><th>Change</th></tr></thead>
                <tbody>
                @forelse($admins as $admin)
                    @php $mine = $memberships[$admin->id] ?? []; @endphp
                    <tr>
                        <td><strong>{{ $admin->name }}</strong><br><span class="dev-muted">{{ $admin->email }}</span></td>
                        <td>
                            <form method="POST" action="{{ route('developer.admins.companies', $admin->id) }}" class="dev-inline">
                                @csrf
                                <div class="dev-company-ticks">
                                    @foreach($companies as $c)
                                        <label><input type="checkbox" name="company_ids[]" value="{{ $c->id }}" @checked(in_array((int) $c->id, $mine, true))> {{ $c->label }}</label>
                                    @endforeach
                                </div>
                                <button class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        </td>
                        <td><span class="dev-status {{ $admin->is_active ? 'is-on' : 'is-off' }}">{{ $admin->is_active ? 'Active' : 'Blocked' }}</span></td>
                        <td>
                            <form method="POST" action="{{ route('developer.admins.password', $admin->id) }}" class="dev-inline">
                                @csrf
                                <input name="password" type="password" class="form-control form-control-sm" placeholder="New password" minlength="8" maxlength="100" required autocomplete="new-password" aria-label="New password for {{ $admin->name }}">
                                <button class="btn btn-secondary btn-sm">Set</button>
                            </form>
                            <form method="POST" action="{{ route('developer.admins.status', $admin->id) }}" class="dev-inline">
                                @csrf
                                <button class="btn btn-sm {{ $admin->is_active ? 'btn-danger' : 'btn-primary' }}">{{ $admin->is_active ? 'Block login' : 'Activate' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="dev-muted">No admin logins yet. Add one above.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Sections of the active company --}}
    <form method="POST" action="{{ route('developer.switches') }}" class="dev-card dev-switches">
        @csrf
        <div class="dev-switches-head">
            <h2><i class="dripicons-toggles" aria-hidden="true"></i> Sections for {{ $company->trade_name ?? 'this company' }}</h2>
            <button class="btn btn-primary"><i class="dripicons-checkmark"></i> Save sections</button>
        </div>
        <p class="dev-muted">Untick a section or a screen to switch it off for everyone in this company (developers still see it). Switch company at the top bar to set another company.</p>
        <div class="dev-section-grid">
            @foreach($catalog as $section)
                @php $sectionOn = !in_array($section['key'], $off, true); @endphp
                <fieldset class="dev-section" data-section>
                    <legend><label><input type="checkbox" name="on[]" value="{{ $section['key'] }}" data-section-toggle @checked($sectionOn)> {{ $section['label'] }}</label></legend>
                    @foreach($section['items'] as $item)
                        <label class="dev-item"><input type="checkbox" name="on[]" value="{{ $item['key'] }}" @checked(!in_array($item['key'], $off, true)) @disabled(!$sectionOn)> {{ $item['label'] }}</label>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
    </form>
</section>
@endsection

@push('scripts')
<script>
// A switched-off section takes all its screens with it: their ticks are greyed out until the section is on again.
document.querySelectorAll('[data-section-toggle]').forEach(function (toggle) {
    toggle.addEventListener('change', function () {
        toggle.closest('[data-section]').querySelectorAll('.dev-item input').forEach(function (box) { box.disabled = !toggle.checked; });
    });
});
</script>
@endpush
