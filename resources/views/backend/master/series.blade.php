@extends('backend.layout.main')

@section('content')
<x-settings-command-bar />
<x-success-message key="message" />
<x-error-message key="not_permitted" />

@php
    $typeLabels = ['sale' => 'Sales (F2)', 'purchase' => 'Purchase (F12)', 'quotation' => 'Quotation (Alt+F10)', 'sale_payment' => 'Receipt (F6)',
        'purchase_payment' => 'Payment (F5)', 'journal' => 'Journal (F7)', 'sale_credit_note' => 'Sales Return / Credit Note',
        'purchase_debit_note' => 'Purchase Return / Debit Note', 'delivery_challan' => 'Customer DC', 'grn' => 'Supplier GRN',
        'purchase_order' => 'Purchase Order', 'sales_order' => 'Sales Order'];
    $label = fn ($type) => $typeLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
    $example = fn ($row) => $row->prefix.str_pad((string) $row->next_number, max(1, (int) $row->padding), '0', STR_PAD_LEFT).$row->suffix;
@endphp

<section class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="font-weight-bold text-dark mb-0"><i class="dripicons-list text-primary mr-2"></i> Voucher Series (Ctrl+F9)</h3>
            <small class="text-muted">Numbering for this branch and financial year. Set prefixes before the first document; a series that has numbered documents keeps its format.</small>
        </div>
        @if($canManage)
        <button class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#createModal">
            <i class="dripicons-plus mr-1"></i> Add Voucher Series
        </button>
        @endif
    </div>

    @if($errors->any())<div class="alert alert-danger py-2"><ul class="mb-0 pl-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="series-table">
                    <thead class="bg-light text-muted text-uppercase u-fs-11px">
                        <tr>
                            <th>Series Type</th>
                            <th>Series Name</th>
                            <th>Prefix</th>
                            <th>Suffix</th>
                            <th>Next No</th>
                            <th>Digits</th>
                            <th>Next number prints as</th>
                            <th>Default</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($series as $ser)
                        <tr>
                            <td><span class="badge badge-secondary text-uppercase">{{ $label($ser->document_type) }}</span></td>
                            <td class="font-weight-bold text-dark">{{ $ser->code }} @if($ser->used)<span class="badge badge-light text-muted" title="Has numbered documents: prefix, suffix and digits are fixed">Used</span>@endif</td>
                            <td><code>{{ $ser->prefix ?: '—' }}</code></td>
                            <td><code>{{ $ser->suffix ?: '—' }}</code></td>
                            <td class="font-weight-bold text-primary">{{ $ser->next_number }}</td>
                            <td>{{ $ser->padding }}</td>
                            <td><code>{{ $example($ser) }}</code></td>
                            <td>
                                @if($ser->is_default)
                                    <span class="badge badge-success">Yes</span>
                                @else
                                    <span class="badge badge-light text-muted">No</span>
                                @endif
                            </td>
                            <td class="text-right text-nowrap">
                                @if($canManage)
                                <button type="button" class="btn btn-sm btn-outline-primary series-edit" title="Edit"
                                    data-url="{{ route('document-series.update', $ser->id) }}" data-type="{{ $label($ser->document_type) }}" data-code="{{ $ser->code }}"
                                    data-prefix="{{ $ser->prefix }}" data-suffix="{{ $ser->suffix }}" data-next="{{ $ser->next_number }}"
                                    data-padding="{{ $ser->padding }}" data-default="{{ $ser->is_default ? 1 : 0 }}" data-used="{{ $ser->used ? 1 : 0 }}">
                                    <i class="dripicons-pencil"></i>
                                </button>
                                @unless($ser->used)
                                <form action="{{ route('document-series.destroy', $ser->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this series?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="dripicons-trash"></i></button>
                                </form>
                                @endunless
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">No voucher series for this branch and year yet. The first document creates one automatically, or click "Add Voucher Series".</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

@if($canManage)
<div class="modal fade" id="createModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <form action="{{ route('document-series.store') }}" method="POST">
                @csrf
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="dripicons-plus mr-1"></i> Add Voucher Series</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="font-weight-bold" for="create-type">Series Type *</label>
                        <select name="document_type" id="create-type" class="form-control" required>
                            @foreach($types as $type)<option value="{{ $type }}" @selected(old('document_type', 'sale') === $type)>{{ $label($type) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="create-code">Series Name / Code *</label>
                        <input type="text" name="code" id="create-code" class="form-control text-uppercase" placeholder="e.g. WHOLESALE, MJ-22" maxlength="50" pattern="[A-Za-z0-9_\-]+" title="Letters, digits, - or _" value="{{ old('code') }}" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="create-prefix">Prefix</label><input type="text" name="prefix" id="create-prefix" class="form-control" maxlength="100" placeholder="e.g. INV-" value="{{ old('prefix') }}"></div>
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="create-suffix">Suffix</label><input type="text" name="suffix" id="create-suffix" class="form-control" maxlength="50" placeholder="e.g. /26-27" value="{{ old('suffix') }}"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="create-next">Start / Next No *</label><input type="number" name="next_number" id="create-next" class="form-control" value="{{ old('next_number', 1) }}" min="1" required></div>
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="create-padding">Digits (zero padding) *</label><input type="number" name="padding" id="create-padding" class="form-control" value="{{ old('padding', 5) }}" min="1" max="18" required></div>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" name="is_default" value="1" class="custom-control-input" id="isDefaultCheck" @checked(old('is_default'))>
                        <label class="custom-control-label font-weight-bold" for="isDefaultCheck">Default for this type</label>
                    </div>
                    <small class="text-muted d-block mt-2">Numbers restart each financial year (each year has its own series).</small>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Series</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <form method="POST" id="edit-form">
                @csrf
                @method('PUT')
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="dripicons-pencil mr-1"></i> Edit Voucher Series <span id="edit-title"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="alert alert-warning py-2 d-none" id="edit-used">This series has already numbered documents. Prefix, suffix and digits stay as printed, and the next number can only go up. For a new format, add a new series and make it the default.</p>
                    <div class="row">
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="edit-prefix">Prefix</label><input type="text" name="prefix" id="edit-prefix" class="form-control" maxlength="100"></div>
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="edit-suffix">Suffix</label><input type="text" name="suffix" id="edit-suffix" class="form-control" maxlength="50"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="edit-next">Next No *</label><input type="number" name="next_number" id="edit-next" class="form-control" min="1" required></div>
                        <div class="col-md-6 form-group"><label class="font-weight-bold" for="edit-padding">Digits (zero padding) *</label><input type="number" name="padding" id="edit-padding" class="form-control" min="1" max="18" required></div>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" name="is_default" value="1" class="custom-control-input" id="edit-default">
                        <label class="custom-control-label font-weight-bold" for="edit-default">Default for this type</label>
                    </div>
                    <small class="text-muted d-block mt-2">Next number prints as <code id="edit-example"></code></small>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    'use strict';
    var form = document.getElementById('edit-form');
    if (!form) return;
    var field = function (id) { return document.getElementById(id); };
    function example() {
        var next = String(field('edit-next').value || ''), digits = Math.max(1, parseInt(field('edit-padding').value, 10) || 1);
        while (next.length < digits) next = '0' + next;
        field('edit-example').textContent = field('edit-prefix').value + next + field('edit-suffix').value;
    }
    ['edit-prefix', 'edit-suffix', 'edit-next', 'edit-padding'].forEach(function (id) { field(id).addEventListener('input', example); });
    document.querySelectorAll('.series-edit').forEach(function (button) {
        button.addEventListener('click', function () {
            var d = button.dataset, used = d.used === '1';
            form.action = d.url;
            field('edit-title').textContent = '· ' + d.type + ' · ' + d.code;
            field('edit-prefix').value = d.prefix; field('edit-suffix').value = d.suffix;
            field('edit-next').value = d.next; field('edit-padding').value = d.padding;
            field('edit-next').min = used ? d.next : 1;
            ['edit-prefix', 'edit-suffix', 'edit-padding'].forEach(function (id) { field(id).readOnly = used; });
            field('edit-default').checked = d.default === '1';
            field('edit-used').classList.toggle('d-none', !used);
            example();
            $('#editModal').modal('show');
            setTimeout(function () { (used ? field('edit-next') : field('edit-prefix')).focus(); }, 300);
        });
    });
    @if($errors->any() && old('document_type'))
    $('#createModal').modal('show');
    @endif
})();
</script>
@endpush
