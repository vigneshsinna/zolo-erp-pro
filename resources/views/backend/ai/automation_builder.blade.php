@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-automation.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops ai-automation py-3" data-catalog-url="{{ route('ai.builder.catalog') }}" aria-labelledby="automation-builder-title">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / AI Operations</span>
            <h1 id="automation-builder-title">Automation Builder</h1>
            <p>Create monitored stock checks and optionally prepare purchase-order drafts from validated stock levels.</p>
        </div>
        <nav class="ai-automation-actions" aria-label="Automation navigation">
            <a class="btn btn-outline-primary" href="{{ route('ai.performance.index') }}">Agent performance</a>
            <a class="btn btn-outline-secondary" href="{{ route('ai.operations') }}">AI Operations</a>
        </nav>
    </header>

    <div class="ai-automation-alert" role="note">
        <strong>Human approval remains required.</strong>
        The supported automation checks inventory using ERP rules. It may prepare a purchase-order proposal, but never posts a PO, bill, stock adjustment or ledger entry automatically.
    </div>
    @if(session('message'))
        <div class="alert alert-success" role="status">{{ session('message') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Automation was not saved.</strong>
            <ul class="mb-0">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if(!$enabled)
        <section class="ai-ops-panel" aria-label="Scheduling is disabled">
            <h2>Scheduling is disabled</h2>
            <p>Your company or installation has not enabled scheduled AI checks. An administrator must activate the existing AI scheduling service and its queue worker before automations can run.</p>
            <p class="ai-ops-note">This screen cannot override disabled scheduling, company access or your inventory permissions.</p>
        </section>
    @else
        <section class="ai-automation-intro" aria-label="Available automation types">
            <div class="ai-automation-intro-item">
                <span class="ai-automation-pill">Live</span>
                <h2>Low-stock monitoring</h2>
                <p>Read ERP on-hand and reserved quantities. Flag products at or below their configured minimum.</p>
            </div>
            <div class="ai-automation-intro-item">
                <span class="ai-automation-pill">Approval required</span>
                <h2>Purchase-order preparation</h2>
                <p>Prepare a reviewable PO draft when matching stock falls below the minimum. The user must still approve it.</p>
            </div>
            <div class="ai-automation-intro-item">
                <span class="ai-automation-pill ai-automation-pill-muted">Not connected</span>
                <h2>Email, bank and WhatsApp triggers</h2>
                <p>No inbound external-trigger connector is exposed by this builder. Unsupported triggers cannot be saved.</p>
            </div>
        </section>

        <div class="ai-automation-layout">
            <section class="ai-ops-panel" aria-labelledby="automation-new-heading">
                <div class="ai-automation-panel-heading">
                    <div>
                        <h2 id="automation-new-heading">Create an automation</h2>
                        <p>Choose the location, stock products, limits and action. Each rule is owned by your ERP account.</p>
                    </div>
                    <span class="ai-automation-counter">Limit: {{ $maxActiveRules }} active rules per user</span>
                </div>
                <form method="POST" action="{{ route('ai.builder.store') }}" id="ai-automation-form">
                    @csrf
                    <div class="form-group">
                        <label for="automation-name">Automation name <span class="text-danger">*</span></label>
                        <input id="automation-name" class="form-control" type="text" name="name" maxlength="120"
                            required placeholder="Reorder cotton fabric at warehouse threshold" value="{{ old('name') }}">
                    </div>

                    <div class="form-group ai-builder-lookup-group">
                        <label for="builder-warehouse-query">Warehouse <span class="text-danger">*</span></label>
                        <input id="builder-warehouse-query" type="search" class="form-control ai-builder-lookup" data-kind="warehouse"
                            data-target="builder-warehouse-id" autocomplete="off" placeholder="Search and select your branch warehouse" aria-controls="builder-warehouse-options">
                        <select id="builder-warehouse-options" class="form-control ai-builder-options" data-target="builder-warehouse-id"
                            aria-label="Warehouse search results" size="5" hidden></select>
                        <input type="hidden" id="builder-warehouse-id" name="warehouse_id" value="">
                        <small class="form-text text-muted">Only active warehouses in your selected ERP branch can be used.</small>
                    </div>

                    <div class="form-group">
                        <label for="automation-action">What should happen when stock is low?</label>
                        <select id="automation-action" name="prepare_purchase_order" class="form-control">
                            <option value="0" @selected((string) old('prepare_purchase_order', '0') === '0')>Record alert only</option>
                            @if($canPreparePo)
                                <option value="1" @selected((string) old('prepare_purchase_order') === '1')>Prepare purchase-order draft for approval</option>
                            @endif
                        </select>
                        @unless($canPreparePo)
                            <small class="form-text text-muted">Your ERP permissions allow monitoring but not PO preparation.</small>
                        @endunless
                    </div>

                    @if($canPreparePo)
                    <div class="form-group ai-builder-lookup-group" id="ai-builder-supplier-group" hidden>
                        <label for="builder-supplier-query">Supplier for draft PO <span class="text-danger">*</span></label>
                        <input id="builder-supplier-query" type="search" class="form-control ai-builder-lookup" data-kind="supplier"
                            data-target="builder-supplier-id" autocomplete="off" placeholder="Search and select an active supplier" aria-controls="builder-supplier-options">
                        <select id="builder-supplier-options" class="form-control ai-builder-options" data-target="builder-supplier-id"
                            aria-label="Supplier search results" size="5" hidden></select>
                        <input type="hidden" id="builder-supplier-id" name="supplier_id" value="">
                    </div>
                    @endif

                    <fieldset class="ai-builder-products">
                        <legend>Products and reorder thresholds</legend>
                        <p class="ai-ops-note">Search each stock item by name or code. Quantity values use the product's configured units; draft order quantity uses the purchase unit.</p>
                        <div id="ai-builder-items">
                            <div class="ai-builder-item" data-index="0">
                                <div class="form-group ai-builder-lookup-group">
                                    <label for="builder-product-query-0">Stock product <span class="text-danger">*</span></label>
                                    <input id="builder-product-query-0" type="search" class="form-control ai-builder-lookup" data-kind="product"
                                        data-target="builder-product-0" autocomplete="off" placeholder="Find product by name or SKU"
                                        aria-controls="builder-product-results-0">
                                    <select id="builder-product-results-0" class="form-control ai-builder-options" data-target="builder-product-0"
                                        aria-label="Product search results" size="5" hidden></select>
                                    <input type="hidden" id="builder-product-0" name="items[0][product_id]" value="">
                                </div>
                                <div class="ai-builder-quantities">
                                    <div class="form-group">
                                        <label for="builder-min-0">Minimum available quantity</label>
                                        <input type="number" id="builder-min-0" class="form-control" name="items[0][minimum_qty]" required min="0" max="999999999" step="0.0001" value="5">
                                    </div>
                                    <div class="form-group">
                                        <label for="builder-order-0">Draft order quantity</label>
                                        <input type="number" id="builder-order-0" class="form-control" name="items[0][order_qty]" required min="0.0001" max="999999999" step="0.0001" value="10">
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger ai-builder-remove" disabled>Remove product</button>
                            </div>
                        </div>
                        <button type="button" id="ai-builder-add-item" class="btn btn-outline-primary btn-sm mt-2">Add another product</button>
                        <span id="ai-builder-count" class="ai-ops-note ml-2" role="status">1 of 50 products</span>
                    </fieldset>

                    <div class="ai-builder-settings">
                        <div class="form-group">
                            <label for="automation-frequency">Check frequency</label>
                            <select id="automation-frequency" class="form-control" name="interval_minutes">
                                @foreach([60 => 'Every hour', 180 => 'Every 3 hours', 360 => 'Every 6 hours', 720 => 'Every 12 hours', 1440 => 'Every day', 10080 => 'Every week'] as $minutes => $label)
                                    <option value="{{ $minutes }}" @selected((int) old('interval_minutes', 1440) === $minutes)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="automation-run-limit">Maximum runs per day</label>
                            <input type="number" class="form-control" id="automation-run-limit" name="max_runs_per_day"
                                min="1" max="24" value="{{ old('max_runs_per_day', 2) }}" required>
                        </div>
                        <div class="form-group">
                            <label for="automation-expires">Automatically expire after</label>
                            <select id="automation-expires" class="form-control" name="expires_days">
                                @foreach([1, 7, 30, 90] as $days)
                                    <option value="{{ $days }}" @selected((int) old('expires_days', 30) === $days)>{{ $days }} {{ $days === 1 ? 'day' : 'days' }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="ai-builder-optin">
                        <label for="automation-opt-in">
                            <input type="checkbox" id="automation-opt-in" name="opt_in" value="1" required>
                            I authorize these scheduled stock checks for my current company, branch and financial year.
                        </label>
                        <p class="ai-ops-note">The existing server-side scheduler rechecks company access and permissions at execution. This approval does not approve or post any purchase order.</p>
                    </div>
                    <div class="ai-automation-form-actions">
                        <button type="submit" id="ai-builder-submit" class="btn btn-primary">Create automation</button>
                        <span id="ai-builder-feedback" class="ai-ops-note" role="status"></span>
                    </div>
                </form>
            </section>

            <section class="ai-ops-panel ai-builder-list" aria-labelledby="automation-existing-heading">
                <div class="ai-automation-panel-heading">
                    <div>
                        <h2 id="automation-existing-heading">Your automations</h2>
                        <p>Active, expired and cancelled rules created by your account.</p>
                    </div>
                    <span class="ai-automation-counter">{{ count($rules) }} listed</span>
                </div>
                @forelse($rules as $rule)
                    @php
                        $inputs = json_decode($rule['inputs_json'] ?? '{}', true) ?: [];
                        $isActive = (bool) $rule['is_active'] && $rule['expires_at'] > now('UTC')->toDateTimeString();
                    @endphp
                    <article class="ai-builder-existing">
                        <div class="ai-automation-rule-heading">
                            <strong>{{ $rule['name'] }}</strong>
                            <span class="ai-automation-pill {{ $isActive ? '' : 'ai-automation-pill-muted' }}">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                        </div>
                        <p>{{ count($inputs['items'] ?? []) }} products · {{ $rule['prepare_purchase_order'] ? 'Draft PO for approval' : 'Alert only' }}</p>
                        <small>Every {{ $rule['interval_minutes'] }} minutes · Next: {{ $rule['next_run_at'] }} UTC</small>
                        <small>Expires: {{ $rule['expires_at'] }} UTC · Revision {{ $rule['revision'] }}</small>
                        <div class="ai-automation-actions">
                            <a href="{{ route('ai.builder.runs', ['id' => $rule['id']]) }}" class="btn btn-outline-primary btn-sm">Run history</a>
                            @if($isActive)
                                <form method="POST" action="{{ route('ai.builder.cancel', ['id' => $rule['id']]) }}">
                                    @csrf
                                    <input type="hidden" name="revision" value="{{ $rule['revision'] }}">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm-cancel>Cancel rule</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="ai-ops-empty">No automations yet. Create your first low-stock check using the form.</p>
                @endforelse
                <p class="ai-ops-note mt-3">Scheduling uses the existing queue worker. Creating a rule does not run it immediately in this browser.</p>
            </section>
        </div>
    @endif
</main>

<template id="ai-builder-row-template">
    <div class="ai-builder-item" data-index="__INDEX__">
        <div class="form-group ai-builder-lookup-group">
            <label for="builder-product-query-__INDEX__">Stock product <span class="text-danger">*</span></label>
            <input id="builder-product-query-__INDEX__" type="search" class="form-control ai-builder-lookup" data-kind="product"
                data-target="builder-product-__INDEX__" autocomplete="off" placeholder="Find product by name or SKU"
                aria-controls="builder-product-results-__INDEX__">
            <select id="builder-product-results-__INDEX__" class="form-control ai-builder-options" data-target="builder-product-__INDEX__"
                aria-label="Product search results" size="5" hidden></select>
            <input type="hidden" id="builder-product-__INDEX__" name="items[__INDEX__][product_id]" value="">
        </div>
        <div class="ai-builder-quantities">
            <div class="form-group">
                <label for="builder-min-__INDEX__">Minimum available quantity</label>
                <input type="number" id="builder-min-__INDEX__" class="form-control" name="items[__INDEX__][minimum_qty]"
                    required min="0" max="999999999" step="0.0001" value="5">
            </div>
            <div class="form-group">
                <label for="builder-order-__INDEX__">Draft order quantity</label>
                <input type="number" id="builder-order-__INDEX__" class="form-control" name="items[__INDEX__][order_qty]"
                    required min="0.0001" max="999999999" step="0.0001" value="10">
            </div>
        </div>
        <button type="button" class="btn btn-outline-danger btn-sm ai-builder-remove">Remove product</button>
    </div>
</template>
@endsection

@push('scripts')
<script src="{{ versioned_asset('js/ai-automation-builder.js') }}"></script>
@endpush
