<dl class="small mb-2 pl-2 border-left">
    @foreach($details as $field => $value)
        <dt>{{ is_int($field) ? 'Line '.($field + 1) : ucfirst(str_replace('_', ' ', $field)) }}</dt>
        <dd class="text-break mb-2">
            @if(is_array($value) && ($depth ?? 0) < 6)
                @include('backend.ai.partials.action-details', ['details' => $value, 'depth' => ($depth ?? 0) + 1])
            @else
                {{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($value ?? '—') }}
            @endif
        </dd>
    @endforeach
</dl>
