@extends('backend.layout.main')
@section('content')
    <x-success-message key="message" />
    <x-error-message key="not_permitted" />

    <x-settings-command-bar>
        @if (!empty($asset_id))
            <a href="https://business.facebook.com/latest/whatsapp_manager/message_templates?asset_id={{ $asset_id }}" target="_blank" rel="noopener" class="btn btn-primary"><i class="dripicons-plus"></i> {{ __('db.manage_template') }}</a>
        @endif
    </x-settings-command-bar>

    <section class="settings-workspace-view">

        <div class="table-responsive">
            <table id="templates-table" class="table">
                <thead>
                    <tr>
                        <th class="not-exported"></th>
                        <th>{{ __('db.name') }}</th>
                        <th>{{ __('db.language') }}</th>
                        <th>{{ __('db.category') }}</th>
                        <th>{{ __('db.status') }}</th>
                        <th class="not-exported">{{ __('db.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($templates as $key => $tpl)
                        <tr data-id="{{ $tpl['name'] }}">
                            <td>{{ $key }}</td>
                            <td>{{ $tpl['name'] }}</td>
                            <td>{{ $tpl['language'] }}</td>
                            <td>{{ $tpl['category'] ?? '' }}</td>
                            <td>{{ $tpl['status'] ?? '' }}</td>
                            <td>
                                <form action="{{ route('whatsapp.template.delete', $tpl['name']) }}" method="POST" onsubmit="return confirmDelete()">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-danger"><i class="dripicons-trash"></i> {{ __('db.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection

@push('scripts')
    <script type="text/javascript">
        $("ul#whatsapp").siblings('a').attr('aria-expanded', 'true');
        $("ul#whatsapp").addClass("show");
        $("ul#whatsapp #whatsapp-templates-menu").addClass("active");

        $('#templates-table').DataTable({
            "order": [],
            'language': {
                'lengthMenu': '_MENU_ {{ __('db.records per page') }}',
                "info": '<small>{{ __('db.Showing') }} _START_ - _END_ (_TOTAL_)</small>',
                "search": '{{ __('db.Search') }}',
                'paginate': {
                    'previous': '<i class="dripicons-chevron-left"></i>',
                    'next': '<i class="dripicons-chevron-right"></i>'
                }
            },
            'columnDefs': [{
                    "orderable": false,
                    'targets': [0, 5]
                },
                {
                    'render': function(data, type, row, meta) {
                        if (type === 'display') {
                            data =
                                '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>';
                        }

                        return data;
                    },
                    'checkboxes': {
                        'selectRow': true,
                        'selectAllRender': '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>'
                    },
                    'targets': [0]
                }
            ],
            'select': {
                style: 'multi',
                selector: 'td:first-child'
            },
            'lengthMenu': [
                [10, 25, 50, -1],
                [10, 25, 50, "All"]
            ],
            dom: '<"row"lfB>rtip',
            buttons: [{
                    extend: 'pdf',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible'
                    },
                },
                {
                    extend: 'excel',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible'
                    },
                },
                {
                    extend: 'csv',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible'
                    },
                },
                {
                    extend: 'print',
                    exportOptions: {
                        columns: ':visible:Not(.not-exported)',
                        rows: ':visible'
                    },
                },
                {
                    extend: 'colvis',
                    text: '<i title="" class="fa fa-eye"></i>',
                    columns: ':gt(0)'
                },
            ],
        });

    </script>
@endpush
