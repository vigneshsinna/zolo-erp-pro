<div class="{{ $colClass ?? 'col-6' }} form-group">
    <label for="negative-stock-policy">{{ __('db.Negative stock') ?? 'Negative stock' }}</label>
    <select id="negative-stock-policy" name="negative_stock_policy" class="form-control selectpicker">
        @foreach(['' => 'Inherit company policy', 'allow' => 'Allow', 'warn' => 'Allow with warning', 'block' => 'Block'] as $value => $label)
        <option value="{{ $value }}" @selected((string) old('negative_stock_policy', $product->negative_stock_policy ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <small class="text-muted d-block mt-1">{{ __('db.Serial, batch and stock identity restrictions still apply.') ?? 'Serial, batch and stock identity restrictions still apply.' }}</small>
</div>
