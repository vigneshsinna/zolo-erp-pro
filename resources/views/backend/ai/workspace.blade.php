@extends('backend.layout.main')

@section('content')
    <div class="container-fluid py-3 ai-workspace-container">
        <div class="row">
            <!-- Sidebar: Conversation Threads & Context -->
            <div class="col-md-3 col-lg-3 mb-3">
                <div class="card shadow-sm border-0 ai-sidebar-card">
                    <div
                        class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2 flex-shrink-0">
                        <span class="font-weight-bold text-dark"><i class="dripicons-conversation mr-1"></i> AI
                            Sessions</span>
                        <button id="btn-new-thread" class="btn btn-sm btn-outline-primary" title="Start new conversation">
                            <i class="dripicons-plus"></i> New
                        </button>
                    </div>
                    <div class="card-body p-2 d-flex flex-column ai-sidebar-body">
                        <!-- Active Scope Badge -->
                        <div class="p-2 mb-2 rounded bg-light border flex-shrink-0 ai-scope-badge">
                            <div class="text-muted small text-uppercase font-weight-bold">Active Tenancy</div>
                            <div class="font-weight-bold text-truncate">{{ $company->legal_name ?? $company->name }}</div>
                            <div class="text-secondary small">FY ID: {{ $context->financialYearId }} &bull; Branch:
                                {{ $context->branchId }}</div>
                        </div>

                        <!-- Threads List -->
                        <div id="thread-list" class="list-group list-group-flush flex-grow-1">
                            <div class="text-center text-muted py-4 small" id="thread-loading">
                                <span class="spinner-border spinner-border-sm text-primary mr-1"></span> Loading sessions...
                            </div>
                        </div>

                        <!-- Guardrail Status & Privacy Notice -->
                        <div class="mt-auto pt-2 border-top flex-shrink-0 ai-guardrail-status">
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge {{ $isAiEnabled ? 'badge-success' : 'badge-danger' }} mr-1">
                                    {{ $isAiEnabled ? 'Active' : 'Disabled' }}
                                </span>
                                <span class="badge {{ $isReadOnly ? 'badge-info' : 'badge-warning' }}">
                                    {{ $isReadOnly ? 'Read-Only Policy' : 'Proposals Active' }}
                                </span>
                            </div>
                            <div class="text-muted">
                                <i class="dripicons-shield mr-1"></i> Tenant-isolated execution. Server authoritative.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="col-md-9 col-lg-9 mb-3">
                <div class="card shadow-sm border-0 ai-chat-card">
                    <!-- Header -->
                    <div
                        class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2 flex-shrink-0">
                        <div>
                            <h5 class="mb-0 font-weight-bold text-dark">
                                <i class="dripicons-sparkles text-primary mr-1"></i> zoloERP Copilot
                            </h5>
                            <small class="text-muted" id="active-thread-title">New Conversation</small>
                        </div>
                        <div class="d-flex align-items-center">
                            @if($isAdmin ?? false)
                                <button id="btn-toggle-telemetry"
                                    class="btn btn-xs btn-outline-info mr-2 py-1 px-2 ai-telemetry-toggle-btn"
                                    title="View AI telemetry & rate limits">
                                    <i class="dripicons-meter mr-1"></i> <span id="header-rpm-badge"
                                        class="badge badge-success ai-rpm-badge">RPM: --/35</span>
                                </button>
                            @endif
                            @if($model)
                                <div>
                                    <span class="badge badge-light border text-muted ai-status-badge">
                                        Model: {{ $model }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($isAdmin ?? false)
                        <!-- Admin Telemetry Panel -->
                        <div id="ai-telemetry-panel" class="border-bottom bg-light px-3 py-2 flex-shrink-0"
                            style="display: none;">
                            <div class="d-flex flex-wrap justify-content-between align-items-center">
                                <div class="d-flex align-items-center mr-3 my-1">
                                    <span class="text-muted mr-1">Provider RPM:</span>
                                    <span id="telemetry-rpm-badge" class="badge badge-success mr-2 font-weight-bold">-- /
                                        35</span>
                                    <span id="telemetry-rpm-status" class="small text-muted font-italic">Healthy</span>
                                </div>
                                <div class="d-flex align-items-center mr-3 my-1">
                                    <span class="text-muted mr-1">Today's Invocations:</span>
                                    <span class="badge badge-light border mr-1"><strong id="telemetry-model-calls">0</strong>
                                        Model Calls</span>
                                    <span class="badge badge-light border"><strong id="telemetry-tool-calls">0</strong> ERP
                                        Tools</span>
                                </div>
                                <div class="d-flex align-items-center mr-3 my-1">
                                    <span class="text-muted mr-1">Tokens Today:</span>
                                    <span id="telemetry-tokens-used" class="font-weight-bold text-dark">0</span>
                                    <span class="text-muted small ml-1">/ <span id="telemetry-token-limit">100,000</span></span>
                                </div>
                                <div class="my-1">
                                    <button id="btn-refresh-telemetry"
                                        class="btn btn-xs btn-outline-secondary py-0 px-2 ai-telemetry-refresh-btn"
                                        title="Refresh metrics">
                                        <i class="dripicons-clockwise"></i> Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Messages Feed -->
                    <div id="chat-messages" class="card-body p-3 flex-grow-1">
                        <!-- Welcome Hero / Default Message -->
                        <div class="ai-welcome-box p-4 text-center my-auto">
                            <div class="mb-3">
                                <span class="badge-pill p-3 ai-welcome-badge">
                                    <i class="dripicons-sparkles"></i>
                                </span>
                            </div>
                            <h5 class="font-weight-bold">How can I assist your business operations today?</h5>
                            <p class="text-muted small mx-auto ai-welcome-desc">
                                I query real-time customer outstanding dues, general ledgers, and warehouse inventory stock
                                with strict accounting accuracy.
                            </p>
                            <div class="d-flex justify-content-center flex-wrap mt-3">
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion"
                                    data-prompt="Show customer outstanding breakdown">
                                    "Customer outstanding breakdown"
                                </button>
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion"
                                    data-prompt="How much stock for all the products are there?">
                                    "Total stock for all products"
                                </button>
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion"
                                    data-prompt="Show my top 5 customers by outstanding balance">
                                    "Top 5 customers with dues"
                                </button>
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion"
                                    data-prompt="Check control account reconciliation status">
                                    "AR reconciliation status"
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Input Footer -->
                    <div class="card-footer bg-white border-top p-2 flex-shrink-0">
                        <form id="chat-form" class="m-0">
                            @csrf
                            <div class="input-group">
                                <textarea id="chat-input" class="form-control border-right-0 ai-chat-input" rows="1"
                                    placeholder="Ask about outstanding dues, customer aging, or financial balances..."
                                    maxlength="4000" required></textarea>
                                <div class="input-group-append">
                                    <button id="btn-send" class="btn btn-primary px-3" type="submit">
                                        <span id="btn-send-text"><i class="dripicons-direction mr-1"></i> Send</span>
                                        <span id="btn-send-spinner" class="spinner-border spinner-border-sm"
                                            style="display:none;" role="status"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1 px-1">
                                <small class="text-muted ai-footer-hint">
                                    Press <kbd>Enter</kbd> to send, <kbd>Shift + Enter</kbd> for line break
                                </small>
                                <small id="char-counter" class="text-muted ai-char-counter">0 / 4000</small>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('css')
    <link rel="stylesheet" href="{{ asset('css/pages/ai-workspace.css') }}" type="text/css">
@endpush

@push('scripts')
    <script src="{{ asset('vendor/marked.min.js') }}"></script>
    <script src="{{ asset('vendor/purify.min.js') }}"></script>
    <script>
        (function ($) {
            'use strict';

            if (!@json($canUseCopilot)) {
                $('#thread-list').text('Copilot access is unavailable in this context.');
                $('#btn-new-thread, #chat-input, #btn-send, .prompt-suggestion').prop('disabled', true);
                return;
            }

            let currentThreadId = null;
            let loadingHistory = false;
            let sending = false;
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            const aiBaseUrl = '{{ url("ai") }}';

            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            // Headers for API calls
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-AI-Scope': '{{ $context->companyId }}/{{ $context->branchId }}/{{ $context->financialYearId }}'
            };

            // Load available threads on boot
            function loadThreads() {
                $.ajax({
                    url: '{{ route("ai.threads.index") }}',
                    method: 'GET',
                    headers: headers,
                    success: function (res) {
                        const list = $('#thread-list');
                        list.empty();
                        if (!res.threads || res.threads.length === 0) {
                            list.html('<div class="text-center text-muted py-3 small">No previous sessions.</div>');
                            return;
                        }
                        res.threads.forEach(function (th) {
                            const activeClass = (th.id === currentThreadId) ? 'active' : '';
                            const item = $('<a href="#" class="list-group-item list-group-item-action py-2 px-2 thread-item ' + activeClass + '" data-id="' + th.id + '">' +
                                '<div class="font-weight-bold text-truncate ai-thread-item-title">' + $('<div>').text(th.title).html() + '</div>' +
                                '<small class="text-muted ai-thread-item-time">' + th.updated_at + '</small>' +
                                '</a>');
                            list.append(item);
                        });
                    },
                    error: function () {
                        $('#thread-list').html('<div class="text-center text-danger py-2 small">Failed to load sessions.</div>');
                    }
                });
            }

            // Switch or create thread
            $('#btn-new-thread').on('click', function () {
                if (sending || loadingHistory) return;
                currentThreadId = null;
                $('#active-thread-title').text('New Conversation');
                $('#thread-list .thread-item').removeClass('active');
                $('#chat-messages').html('<div class="ai-welcome-box p-4 text-center my-auto">' +
                    '<h5 class="font-weight-bold">New Session Started</h5>' +
                    '<p class="text-muted small">Ask an authoritative accounting or receivables question.</p>' +
                    '</div>');
            });

            $(document).on('click', '.thread-item', function (e) {
                e.preventDefault();
                if (sending || loadingHistory) return;
                loadingHistory = true;
                const id = $(this).data('id');
                currentThreadId = id;
                $('#thread-list .thread-item').removeClass('active');
                $(this).addClass('active');

                $('#active-thread-title').text($(this).find('.font-weight-bold').text());
                $('#chat-messages').html('<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm"></span> Loading history...</div>');

                $.ajax({
                    url: '{{ route("ai.threads.index") }}/' + encodeURIComponent(id),
                    method: 'GET',
                    headers: headers,
                    success: function (res) {
                        $('#chat-messages').empty();
                        if (res.messages && res.messages.length > 0) {
                            res.messages.forEach(function (msg) {
                                appendMessage(msg.role, msg.content, msg.sources, msg.warnings, msg.facts);
                            });
                        } else {
                            $('#chat-messages').html('<div class="text-center text-muted py-3 small">No messages in this session.</div>');
                        }
                    },
                    error: function () {
                        $('#chat-messages').empty().append($('<div class="text-danger p-3"></div>').text('Could not load this conversation.'));
                    },
                    complete: function () {
                        loadingHistory = false;
                    }
                });
            });

            // Prompt Suggestions
            $(document).on('click', '.prompt-suggestion', function () {
                const text = $(this).data('prompt');
                $('#chat-input').val(text).focus();
                $('#char-counter').text(text.length + ' / 4000');
            });

            // Char counter
            $('#chat-input').on('input', function () {
                const len = $(this).val().length;
                $('#char-counter').text(len + ' / 4000');
            });

            // Send on Enter
            $('#chat-input').on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    $('#chat-form').submit();
                }
            });

            function renderMarkdown(rawText) {
                const text = String(rawText ?? '');
                const escapeText = () => $('<div>').text(text).html().replace(/\r?\n/g, '<br>');

                if (
                    typeof window.marked?.parse !== 'function' ||
                    typeof window.DOMPurify?.sanitize !== 'function'
                ) {
                    return escapeText();
                }

                try {
                    const html = window.marked.parse(text, { gfm: true, breaks: true });
                    return window.DOMPurify.sanitize(html, { USE_PROFILES: { html: true }, FORBID_TAGS: ['a', 'img'] });
                } catch (error) {
                    console.error('Markdown rendering failed.');
                    return escapeText();
                }
            }

            function erpHref(value) {
                if (typeof value !== 'string' || !/^\/[a-z0-9]/i.test(value) || value.includes('\\')) return null;
                try {
                    const url = new URL(value, window.location.origin);
                    return url.origin === window.location.origin ? url.href : null;
                } catch {
                    return null;
                }
            }

            // Append Message helper
            function appendMessage(role, text, sources, warnings, facts) {
                $('.ai-welcome-box').remove();
                const isUser = (role === 'user');
                const container = $('<div class="d-flex mb-3 ' + (isUser ? 'justify-content-end' : 'justify-content-start') + '"></div>');
                const bubble = $('<div class="chat-bubble ' + (isUser ? 'chat-user' : 'chat-assistant shadow-sm') + '"></div>');

                if (isUser) {
                    const textDiv = $('<div></div>').text(text);
                    bubble.append(textDiv);
                } else {
                    const textDiv = $('<div class="markdown-body"></div>').html(renderMarkdown(text));
                    bubble.append(textDiv);
                }

                (facts || []).forEach(function (fact) {
                    const data = fact.data || {};
                    const currencySymbol = (data.currency === 'INR') ? '₹' : (data.currency + ' ');

                    if (fact.tool === 'receivables.outstanding.v1') {
                        const isReconciled = data.reconciliation?.is_reconciled;
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');

                        // Header
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-document-edit text-primary mr-1"></i> Customer Outstanding · ' + $('<div>').text(fact.as_of).html() + '</span>'));

                        const badge = isReconciled
                            ? $('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Reconciled</span>')
                            : $('<span class="badge badge-warning px-2 py-1"><i class="dripicons-warning mr-1"></i>Variance Reported</span>');
                        cardHeader.append(badge);
                        card.append(cardHeader);

                        // Body with KPIs
                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');

                        kpiRow.append($('<div class="col-6 border-right"></div>')
                            .append('<div class="text-muted ai-kpi-label">Total Outstanding</div>')
                            .append('<div class="font-weight-bold text-dark ai-kpi-value">' + currencySymbol + $('<div>').text(data.total_outstanding).html() + '</div>'));

                        kpiRow.append($('<div class="col-6"></div>')
                            .append('<div class="text-muted ai-kpi-label">Customers with Dues</div>')
                            .append('<div class="font-weight-bold text-dark ai-kpi-value">' + $('<div>').text(data.total_customers_with_dues).html() + '</div>'));

                        cardBody.append(kpiRow);

                        // Table of Top Customers
                        if (data.top_customers && data.top_customers.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
                            table.append('<thead class="thead-light"><tr>' +
                                '<th>Customer</th>' +
                                '<th class="text-right">Outstanding</th>' +
                                '<th class="text-center">Invoices</th>' +
                                '<th class="text-right">Max Overdue</th>' +
                                '</tr></thead>');

                            const tbody = $('<tbody></tbody>');
                            data.top_customers.forEach(function (cust) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td class="font-weight-bold"></td>').text(cust.customer_name));
                                tr.append($('<td class="text-right"></td>').text(currencySymbol + cust.outstanding_amount));
                                tr.append($('<td class="text-center"></td>').text(cust.invoice_count));
                                tr.append($('<td class="text-right"></td>').text(cust.max_days_overdue + ' days'));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }

                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'inventory.stock_summary.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');

                        // Header
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-box text-primary mr-1"></i> Stock Availability · ' + $('<div>').text(data.warehouse || 'All Warehouses').html() + ' · ' + $('<div>').text(fact.as_of).html() + '</span>'));

                        const badge = (data.out_of_stock_count === 0)
                            ? $('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Healthy Stock</span>')
                            : $('<span class="badge badge-info px-2 py-1"><i class="dripicons-information mr-1"></i>Stock Reported</span>');
                        cardHeader.append(badge);
                        card.append(cardHeader);

                        // Body with KPIs
                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');

                        kpiRow.append($('<div class="col-3 border-right"></div>')
                            .append('<div class="text-muted ai-kpi-label">Tracked Products</div>')
                            .append('<div class="font-weight-bold text-dark ai-kpi-value">' + $('<div>').text(data.total_products_tracked).html() + '</div>'));

                        kpiRow.append($('<div class="col-3 border-right"></div>')
                            .append('<div class="text-muted ai-kpi-label">In Stock</div>')
                            .append('<div class="font-weight-bold text-success ai-kpi-value">' + $('<div>').text(data.in_stock_count).html() + '</div>'));

                        kpiRow.append($('<div class="col-3 border-right"></div>')
                            .append('<div class="text-muted ai-kpi-label">Out of Stock</div>')
                            .append('<div class="font-weight-bold ' + (data.out_of_stock_count > 0 ? 'text-danger' : 'text-dark') + ' ai-kpi-value">' + $('<div>').text(data.out_of_stock_count).html() + '</div>'));

                        kpiRow.append($('<div class="col-3"></div>')
                            .append('<div class="text-muted ai-kpi-label">Stock Valuation</div>')
                            .append('<div class="font-weight-bold text-dark ai-kpi-value">' + $('<div>').text(data.total_stock_value == null ? 'Unavailable' : currencySymbol + data.total_stock_value).html() + '</div>'));

                        cardBody.append(kpiRow);

                        // Grouped Units Summary
                        if (data.units_summary && data.units_summary.length > 0) {
                            const unitBadges = $('<div class="d-flex flex-wrap align-items-center mb-2 px-1"></div>');
                            unitBadges.append('<span class="text-muted mr-2 small font-weight-bold ai-unit-label">By Unit:</span>');
                            data.units_summary.forEach(function (u) {
                                unitBadges.append($('<span class="badge badge-light border mr-1 mb-1 font-weight-normal py-1 px-2 ai-unit-chip">' +
                                    '<strong>' + $('<div>').text(u.unit).html() + ':</strong> ' +
                                    $('<div>').text(u.on_hand).html() + ' on-hand (avail: ' + $('<div>').text(u.available).html() + ')' +
                                    '</span>'));
                            });
                            cardBody.append(unitBadges);
                        }

                        // Table of Products
                        if (data.top_products && data.top_products.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
                            table.append('<thead class="thead-light"><tr>' +
                                '<th>Product</th>' +
                                '<th>Unit</th>' +
                                '<th class="text-right">On Hand</th>' +
                                '<th class="text-right">Reserved</th>' +
                                '<th class="text-right">Available</th>' +
                                '<th class="text-right">Valuation</th>' +
                                '</tr></thead>');

                            const tbody = $('<tbody></tbody>');
                            data.top_products.forEach(function (prod) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td class="font-weight-bold"></td>').text(prod.name + ' (' + prod.code + ')'));
                                tr.append($('<td></td>').text(prod.unit));
                                tr.append($('<td class="text-right"></td>').text(prod.on_hand));
                                tr.append($('<td class="text-right text-muted"></td>').text(prod.reserved));
                                tr.append($('<td class="text-right font-weight-bold ' + (prod.available <= 0 ? 'text-danger' : 'text-success') + '"></td>').text(prod.available));
                                tr.append($('<td class="text-right"></td>').text(prod.stock_value == null ? '—' : currencySymbol + prod.stock_value));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }

                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'receivables.invoices.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const customerName = data.customer ? data.customer.name : 'Customer Receivables';
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-document text-primary mr-1"></i> ' + $('<div>').text(customerName).html() + ' · Invoices as of ' + $('<div>').text(fact.as_of).html() + '</span>'));
                        const overdueAmt = parseFloat(data.totals ? data.totals.overdue_amount : 0);
                        const badge = (overdueAmt > 0)
                            ? $('<span class="badge badge-warning px-2 py-1"><i class="dripicons-warning mr-1"></i>Overdue: ' + currencySymbol + $('<div>').text(data.totals.overdue_amount).html() + '</span>')
                            : $('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Current</span>');
                        cardHeader.append(badge);
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        if (data.totals) {
                            const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');
                            kpiRow.append($('<div class="col-3 border-right"><div class="text-muted ai-kpi-label">Invoices</div><div class="font-weight-bold text-dark">' + (data.invoice_count || 0) + '</div></div>'));
                            kpiRow.append($('<div class="col-3 border-right"><div class="text-muted ai-kpi-label">Original Sum</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.totals.original_amount || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-3 border-right"><div class="text-muted ai-kpi-label">Allocated</div><div class="font-weight-bold text-success">' + currencySymbol + (data.totals.allocated_amount || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-3"><div class="text-muted ai-kpi-label">Remaining Due</div><div class="font-weight-bold text-primary">' + currencySymbol + (data.totals.outstanding_amount || '0.00') + '</div></div>'));
                            cardBody.append(kpiRow);
                        }

                        if (data.invoices && data.invoices.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
                            table.append('<thead class="thead-light"><tr><th>Invoice #</th><th>Doc Date</th><th>Due Date</th><th class="text-right">Original</th><th class="text-right">Allocated</th><th class="text-right">Remaining</th><th class="text-center">Status</th><th></th></tr></thead>');
                            const tbody = $('<tbody></tbody>');
                            data.invoices.forEach(function (inv) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td class="font-weight-bold"></td>').text(inv.invoice_number));
                                tr.append($('<td></td>').text(inv.invoice_date));
                                tr.append($('<td></td>').text(inv.due_date));
                                tr.append($('<td class="text-right text-muted"></td>').text(currencySymbol + inv.original_amount));
                                tr.append($('<td class="text-right text-success"></td>').text(currencySymbol + inv.allocated_amount));
                                tr.append($('<td class="text-right font-weight-bold text-dark"></td>').text(currencySymbol + inv.outstanding_amount));
                                let stBadge = inv.status === 'paid' ? '<span class="badge badge-success">Paid</span>' : (inv.status === 'partially_paid' ? '<span class="badge badge-info">Partially Paid</span>' : '<span class="badge badge-warning">Open</span>');
                                tr.append($('<td class="text-center"></td>').html(stBadge));
                                const linkTd = $('<td></td>');
                                const href = erpHref(inv.source_link);
                                if (href) linkTd.append($('<a class="btn btn-xs btn-outline-primary py-0 px-1" target="_blank" rel="noopener">View</a>').attr('href', href));
                                tr.append(linkTd);
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'receivables.invoice_allocations.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-list text-primary mr-1"></i> Payment Allocations · ' + (data.invoice?.invoice_number || '') + '</span>'));
                        cardHeader.append($('<span class="badge badge-info px-2 py-1">' + (data.allocations_count || 0) + ' Payments</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        if (data.allocations && data.allocations.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
                            table.append('<thead class="thead-light"><tr><th>Date</th><th>Type</th><th>Ref #</th><th class="text-right">Amount</th></tr></thead>');
                            const tbody = $('<tbody></tbody>');
                            data.allocations.forEach(function (al) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td></td>').text(al.date));
                                tr.append($('<td></td>').text(al.type));
                                tr.append($('<td class="font-weight-bold"></td>').text(al.contra_reference));
                                tr.append($('<td class="text-right text-success font-weight-bold"></td>').text(currencySymbol + al.allocated_amount));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'sales.summary.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-graph-bar text-primary mr-1"></i> Sales Analytics · ' + $('<div>').text(data.warehouse || 'All Branch Warehouses').html() + '</span>'));
                        cardHeader.append($('<span class="badge badge-success px-2 py-1">' + (data.totals?.sales_count || 0) + ' Sales</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');
                        kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Gross Sales</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.totals?.gross_sales || '0.00') + '</div></div>'));
                        kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Total Tax</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.totals?.total_tax || '0.00') + '</div></div>'));
                        kpiRow.append($('<div class="col-4"><div class="text-muted ai-kpi-label">Net Invoiced</div><div class="font-weight-bold text-success">' + currencySymbol + (data.totals?.net_sales || '0.00') + '</div></div>'));
                        cardBody.append(kpiRow);

                        if (data.top_products && data.top_products.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table-sm"></table>');
                            table.append('<thead class="thead-light"><tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">Revenue</th></tr></thead>');
                            const tbody = $('<tbody></tbody>');
                            data.top_products.forEach(function (p) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td class="font-weight-bold"></td>').text(p.name + ' (' + p.code + ')'));
                                tr.append($('<td class="text-right"></td>').text(p.total_qty + (p.unit ? ' ' + p.unit : '')));
                                tr.append($('<td class="text-right font-weight-bold text-success"></td>').text(currencySymbol + p.total_revenue));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'payables.summary.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-user-id text-primary mr-1"></i> Supplier Payables · ' + $('<div>').text(fact.as_of).html() + '</span>'));
                        cardHeader.append($('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Reconciled</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');
                        kpiRow.append($('<div class="col-6 border-right"><div class="text-muted ai-kpi-label">Total Payables</div><div class="font-weight-bold text-danger">' + currencySymbol + (data.total_outstanding || '0.00') + '</div></div>'));
                        kpiRow.append($('<div class="col-6"><div class="text-muted ai-kpi-label">Suppliers with Dues</div><div class="font-weight-bold text-dark">' + (data.supplier_count_with_dues || 0) + '</div></div>'));
                        cardBody.append(kpiRow);

                        if (data.top_suppliers && data.top_suppliers.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
                            table.append('<thead class="thead-light"><tr><th>Supplier</th><th class="text-right">Balance Owed</th><th class="text-center">Bills</th><th class="text-right">Max Overdue</th></tr></thead>');
                            const tbody = $('<tbody></tbody>');
                            data.top_suppliers.forEach(function (sup) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td class="font-weight-bold"></td>').text(sup.name));
                                tr.append($('<td class="text-right text-danger font-weight-bold"></td>').text(currencySymbol + sup.open_balance));
                                tr.append($('<td class="text-center"></td>').text(sup.invoice_count));
                                tr.append($('<td class="text-right"></td>').text(sup.max_days_overdue + ' days'));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'accounting.statements.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const stType = data.statement_type || 'profit_and_loss';
                        const typeLabel = stType === 'profit_and_loss' ? 'Profit & Loss Statement' : (stType === 'balance_sheet' ? 'Balance Sheet' : 'Trial Balance');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-graph-pie text-primary mr-1"></i> ' + typeLabel + '</span>'));
                        cardHeader.append($('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Verified Statement</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');
                        if (stType === 'profit_and_loss') {
                            kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Revenue</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.summary?.total_revenue || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Gross Profit</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.summary?.gross_profit || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-4"><div class="text-muted ai-kpi-label">Net Income</div><div class="font-weight-bold text-success">' + currencySymbol + (data.summary?.net_income || '0.00') + '</div></div>'));
                        } else if (stType === 'balance_sheet') {
                            kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Assets</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.summary?.total_assets || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Liabilities</div><div class="font-weight-bold text-danger">' + currencySymbol + (data.summary?.total_liabilities || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-4"><div class="text-muted ai-kpi-label">Equity</div><div class="font-weight-bold text-primary">' + currencySymbol + (data.summary?.total_equity || '0.00') + '</div></div>'));
                        } else {
                            kpiRow.append($('<div class="col-6 border-right"><div class="text-muted ai-kpi-label">Total Debit</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.summary?.total_debit || '0.00') + '</div></div>'));
                            kpiRow.append($('<div class="col-6"><div class="text-muted ai-kpi-label">Total Credit</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.summary?.total_credit || '0.00') + '</div></div>'));
                        }
                        cardBody.append(kpiRow);
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'tax.gst_summary.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-percent text-primary mr-1"></i> GST Breakdown</span>'));
                        cardHeader.append($('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Statutory Projection</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');
                        kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Taxable Value</div><div class="font-weight-bold text-dark">' + currencySymbol + (data.total_taxable_value || '0.00') + '</div></div>'));
                        kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">CGST + SGST</div><div class="font-weight-bold text-dark">' + currencySymbol + ((parseFloat(data.total_cgst || 0) + parseFloat(data.total_sgst || 0))).toFixed(2) + '</div></div>'));
                        kpiRow.append($('<div class="col-4"><div class="text-muted ai-kpi-label">Total Tax Liability</div><div class="font-weight-bold text-danger">' + currencySymbol + (data.total_tax_liability || '0.00') + '</div></div>'));
                        cardBody.append(kpiRow);
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'copilot.help.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-question text-primary mr-1"></i> Copilot Authorized Capabilities</span>'));
                        cardHeader.append($('<span class="badge badge-primary px-2 py-1">' + (data.total_authorized_tools || 0) + ' Tools Active</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        if (data.suggested_questions && data.suggested_questions.length > 0) {
                            cardBody.append('<div class="text-muted small font-weight-bold mb-2">Click any suggested question below to try:</div>');
                            const pillContainer = $('<div class="d-flex flex-wrap"></div>');
                            data.suggested_questions.forEach(function (q) {
                                const btn = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-2 font-weight-normal text-left ai-chip-btn"></button>')
                                    .text('💬 ' + q)
                                    .click(function () { $('#chat-input').val(q).focus(); });
                                pillContainer.append(btn);
                            });
                            cardBody.append(pillContainer);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'manufacturing.boms.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-gear text-primary mr-1"></i> Bill of Materials (BOM) · ' + (data.total_boms_found || 0) + ' Found</span>'));
                        cardHeader.append($('<span class="badge badge-info px-2 py-1">Manufacturing</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        if (data.boms && data.boms.length > 0) {
                            data.boms.forEach(function (b) {
                                const bomBox = $('<div class="border rounded p-2 mb-2 bg-light"></div>');
                                bomBox.append('<div class="d-flex justify-content-between font-weight-bold text-dark ai-bom-title">' +
                                    '<span>' + $('<div>').text(b.output_product.name).html() + ' (' + $('<div>').text(b.code).html() + ' v' + b.version + ')</span>' +
                                    '<span class="badge badge-secondary">' + b.output_product.output_qty + ' ' + b.output_product.uom + '</span>' +
                                    '</div>');

                                if (b.components && b.components.length > 0) {
                                    const compList = $('<div class="mt-1 ai-comp-list"></div>');
                                    b.components.forEach(function (c) {
                                        compList.append('<div class="text-muted d-flex justify-content-between border-top py-1">' +
                                            '<span>• ' + $('<div>').text(c.component_name).html() + '</span>' +
                                            '<span class="font-weight-bold text-dark">' + c.qty + ' ' + c.uom + '</span>' +
                                            '</div>');
                                    });
                                    bomBox.append(compList);
                                }
                                cardBody.append(bomBox);
                            });
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'jobwork.status.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-swap text-primary mr-1"></i> Job Work Processing Report</span>'));
                        const summary = data.summary || {};
                        const overdueBadge = (summary.overdue_count > 0)
                            ? $('<span class="badge badge-danger px-2 py-1">Overdue: ' + summary.overdue_count + '</span>')
                            : $('<span class="badge badge-success px-2 py-1">All Current</span>');
                        cardHeader.append(overdueBadge);
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        const kpiRow = $('<div class="row no-gutters text-center border-bottom pb-2 mb-2"></div>');
                        kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Orders Pending</div><div class="font-weight-bold text-dark">' + (summary.total_orders_pending || 0) + '</div></div>'));
                        kpiRow.append($('<div class="col-4 border-right"><div class="text-muted ai-kpi-label">Pending Qty</div><div class="font-weight-bold text-dark">' + (summary.total_pending_qty ?? 'By product') + '</div></div>'));
                        kpiRow.append($('<div class="col-4"><div class="text-muted ai-kpi-label">Pending Value</div><div class="font-weight-bold text-primary">' + currencySymbol + (summary.total_pending_value || '0.00') + '</div></div>'));
                        cardBody.append(kpiRow);

                        if (data.orders && data.orders.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table-sm"></table>');
                            table.append('<thead class="thead-light"><tr><th>Order #</th><th>Processor</th><th>Product</th><th class="text-right">Qty</th><th class="text-right">Return By</th><th class="text-center">Status</th></tr></thead>');
                            const tbody = $('<tbody></tbody>');
                            data.orders.forEach(function (o) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td class="font-weight-bold"></td>').text(o.reference_no));
                                tr.append($('<td></td>').text(o.processor?.name || ''));
                                tr.append($('<td></td>').text(o.product?.name || ''));
                                tr.append($('<td class="text-right font-weight-bold"></td>').text(o.pending_qty));
                                tr.append($('<td class="text-right"></td>').text(o.return_by || '-'));
                                let stBadge = o.status === 'overdue' ? '<span class="badge badge-danger">Overdue</span>' : (o.status === 'due_soon' ? '<span class="badge badge-warning">Due Soon</span>' : '<span class="badge badge-success">OK</span>');
                                tr.append($('<td class="text-center"></td>').html(stBadge));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'orders.open.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-cart text-primary mr-1"></i> Open Orders &amp; Fulfilment</span>'));
                        cardHeader.append($('<span class="badge badge-primary px-2 py-1">' + (data.summary?.total_open_orders || 0) + ' Open</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-2 bg-white"></div>');
                        if (data.orders && data.orders.length > 0) {
                            const tableWrapper = $('<div class="table-responsive"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table-sm"></table>');
                            table.append('<thead class="thead-light"><tr><th>Type</th><th>Order #</th><th>Party</th><th class="text-right">Total</th><th class="text-right">Open Due</th><th class="text-center">Status</th></tr></thead>');
                            const tbody = $('<tbody></tbody>');
                            data.orders.forEach(function (o) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td></td>').html(o.order_type === 'sale' ? '<span class="badge badge-info">Sale</span>' : '<span class="badge badge-secondary">Purchase</span>'));
                                const href = erpHref(o.source_link);
                                const reference = $('<td class="font-weight-bold"></td>').text(o.reference_no);
                                if (href) reference.empty().append($('<a></a>').attr('href', href).text(o.reference_no));
                                tr.append(reference);
                                tr.append($('<td></td>').text(o.party?.name || ''));
                                tr.append($('<td class="text-right"></td>').text(o.grand_total == null ? '—' : currencySymbol + o.grand_total));
                                tr.append($('<td class="text-right font-weight-bold text-danger"></td>').text(o.open_balance == null ? '—' : currencySymbol + o.open_balance));
                                tr.append($('<td class="text-center"><span class="badge badge-warning">' + $('<div>').text(o.status).html() + '</span></td>'));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'documents.preview.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-document-edit text-primary mr-1"></i> Document Preview · ' + $('<div>').text(data.reference_no || '').html() + '</span>'));
                        cardHeader.append($('<span class="badge badge-success px-2 py-1"></span>').text(data.status || 'Active'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-3 bg-white"></div>');
                        cardBody.append('<div class="row no-gutters mb-2 ai-diff-row">' +
                            '<div class="col-6">Party: <strong>' + $('<div>').text(data.party?.name || '').html() + '</strong></div>' +
                            '<div class="col-6 text-right">Date: <strong>' + $('<div>').text(data.date || '').html() + '</strong></div>' +
                            '</div>');
                        const footer = $('<div class="d-flex justify-content-between font-weight-bold text-dark border-top pt-2"></div>');
                        footer.append($('<span></span>').text('Grand Total: ' + currencySymbol + (data.grand_total || '0.00')));
                        const href = erpHref(data.view_link);
                        if (href) footer.append($('<a target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0 px-2">Open Document ↗</a>').attr('href', href));
                        cardBody.append(footer);
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'knowledge.query.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-information text-primary mr-1"></i> Company Policy &amp; Terms</span>'));
                        cardHeader.append($('<span class="badge badge-info px-2 py-1">Verified Knowledge</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-3 bg-white small"></div>');
                        const kw = data.knowledge || {};
                        if (kw.company) {
                            cardBody.append($('<div class="font-weight-bold"></div>').text(kw.company.name));
                            (kw.company.branches || []).forEach(branch => cardBody.append($('<div></div>').text(branch.name)));
                        }
                        if (kw.tax_policy) {
                            cardBody.append($('<div class="text-muted"></div>').text('GST registration: ' + (kw.tax_policy.gstin || kw.tax_policy.registration_type)));
                        }
                        if (kw.return_policy) {
                            cardBody.append($('<div class="text-muted"></div>').text('Return approval window: ' + kw.return_policy.approval_window_days + ' days'));
                        }
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'purchase_order.create.v1' || fact.tool === 'action.purchase_order.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-cart text-primary mr-1"></i> Purchase Order Proposal · ' + $('<div>').text(data.supplier?.name || 'Supplier').html() + '</span>'));
                        cardHeader.append($('<span class="badge badge-warning px-2 py-1"><i class="dripicons-hourglass mr-1"></i>Requires Approval</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-3 bg-white"></div>');
                        cardBody.append('<div class="row no-gutters mb-2 ai-diff-row">' +
                            '<div class="col-6">Warehouse: <strong>' + $('<div>').text(data.warehouse?.name || '').html() + '</strong></div>' +
                            '<div class="col-6 text-right">Date: <strong>' + $('<div>').text(data.date || '').html() + '</strong></div>' +
                            '</div>');

                        if (data.items && data.items.length > 0) {
                            const tableWrapper = $('<div class="table-responsive my-2"></div>');
                            const table = $('<table class="table table-sm table-hover mb-0 ai-fact-table"></table>');
                            table.append('<thead class="thead-light"><tr>' +
                                '<th>Item</th>' +
                                '<th class="text-right">Qty</th>' +
                                '<th class="text-right">Cost</th>' +
                                '<th class="text-right">Total</th>' +
                                '</tr></thead>');

                            const tbody = $('<tbody></tbody>');
                            data.items.forEach(function (item) {
                                const tr = $('<tr></tr>');
                                tr.append($('<td></td>').append($('<div class="font-weight-bold"></div>').text(item.product_name || item.product_code || 'Product')).append($('<small class="text-muted"></small>').text(item.product_code || '')));
                                tr.append($('<td class="text-right"></td>').text(item.qty));
                                tr.append($('<td class="text-right"></td>').text(currencySymbol + item.net_unit_cost));
                                tr.append($('<td class="text-right font-weight-bold"></td>').text(currencySymbol + (item.line_total || item.total || '0.00')));
                                tbody.append(tr);
                            });
                            table.append(tbody);
                            tableWrapper.append(table);
                            cardBody.append(tableWrapper);
                        }

                        const totals = data.totals || {};
                        const footer = $('<div class="border-top pt-2"></div>');
                        const totalsRow = $('<div class="row no-gutters small text-muted mb-2">' +
                            '<div class="col-4">Subtotal: <strong class="text-dark">' + currencySymbol + (totals.subtotal || '0.00') + '</strong></div>' +
                            '<div class="col-4 text-center">Tax: <strong class="text-dark">' + currencySymbol + (totals.tax_total || '0.00') + '</strong></div>' +
                            '<div class="col-4 text-right">Grand Total: <strong class="text-dark font-weight-bold">' + currencySymbol + (totals.grand_total || '0.00') + '</strong></div>' +
                            '</div>');
                        footer.append(totalsRow);

                        if (data.proposal_id) {
                            const actionRow = $('<div class="d-flex justify-content-end align-items-center mt-2 pt-2 border-top"></div>');
                            const btnApprove = $('<button type="button" class="btn btn-sm btn-success mr-2 py-1 px-3"><i class="dripicons-checkmark mr-1"></i>Approve & Place Order</button>');
                            const btnReject = $('<button type="button" class="btn btn-sm btn-outline-danger py-1 px-3"><i class="dripicons-cross mr-1"></i>Reject</button>');

                            btnApprove.on('click', function () {
                                btnApprove.prop('disabled', true).text('Approving...');
                                btnReject.prop('disabled', true);
                                $.ajax({
                                    url: aiBaseUrl + '/proposals/' + data.proposal_id + '/approve',
                                    method: 'POST',
                                    headers: headers,
                                    data: JSON.stringify({ expected_version: data.version || 1 }),
                                    success: function (res) {
                                        actionRow.html('<span class="badge badge-success p-2"><i class="dripicons-checkmark mr-1"></i>Order Placed: PO #' + (res.execution?.receipt?.details?.reference_no || res.proposal_id) + '</span>');
                                    },
                                    error: function (xhr) {
                                        btnApprove.prop('disabled', false).text('Approve & Place Order');
                                        btnReject.prop('disabled', false);
                                        alert('Approval failed: ' + (xhr.responseJSON?.error_message || 'Unexpected error'));
                                    }
                                });
                            });

                            btnReject.on('click', function () {
                                btnApprove.prop('disabled', true);
                                btnReject.prop('disabled', true).text('Rejecting...');
                                $.ajax({
                                    url: aiBaseUrl + '/proposals/' + data.proposal_id + '/reject',
                                    method: 'POST',
                                    headers: headers,
                                    data: JSON.stringify({ expected_version: data.version || 1 }),
                                    success: function (res) {
                                        actionRow.html('<span class="badge badge-secondary p-2"><i class="dripicons-cross mr-1"></i>Proposal Cancelled</span>');
                                    },
                                    error: function (xhr) {
                                        btnApprove.prop('disabled', false);
                                        btnReject.prop('disabled', false).text('Reject');
                                        alert('Rejection failed: ' + (xhr.responseJSON?.error_message || 'Unexpected error'));
                                    }
                                });
                            });

                            actionRow.append(btnApprove).append(btnReject);
                            footer.append(actionRow);
                        }

                        cardBody.append(footer);
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'workflow.clarification.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card ai-clarification-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        const taskIdShort = data.task_id ? String(data.task_id).substring(0, 8) : 'New';
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-question text-warning mr-1"></i> Details Required for Proposal · Task #' + $('<div>').text(taskIdShort).html() + '</span>'));
                        cardHeader.append($('<span class="badge badge-warning px-2 py-1"><i class="dripicons-hourglass mr-1"></i>Collecting Inputs (Rev ' + (data.task_revision || 1) + ')</span>'));
                        card.append(cardHeader);

                        const cardBody = $('<div class="card-body p-3 bg-white"></div>');

                        // Confirmed Fields
                        if (data.confirmed_fields && data.confirmed_fields.length > 0) {
                            const confBox = $('<div class="mb-2"></div>');
                            confBox.append('<div class="small font-weight-bold text-success mb-1"><i class="dripicons-checkmark mr-1"></i>Confirmed Details:</div>');
                            const confPills = $('<div class="d-flex flex-wrap"></div>');
                            data.confirmed_fields.forEach(function (cf) {
                                confPills.append($('<span class="badge mr-1 mb-1 py-1 px-2 ai-confirmed-pill"></span>')
                                    .html('<strong>' + $('<div>').text(cf.label || cf.field).html() + ':</strong> ' + $('<div>').text(cf.display || cf.value || '').html()));
                            });
                            confBox.append(confPills);
                            cardBody.append(confBox);
                        }

                        // Pending Checks
                        if (data.pending_checks && data.pending_checks.length > 0) {
                            const pendingBox = $('<div class="mb-2 small text-muted"><i class="dripicons-information text-info mr-1"></i><strong>Pending Checks:</strong> ' + $('<div>').text(data.pending_checks.join(' · ')).html() + '</div>');
                            cardBody.append(pendingBox);
                        }

                        // Required Inputs (Issues to resolve)
                        const inputsContainer = $('<div class="ai-clarification-inputs border-top pt-2 mt-2"></div>');
                        if (data.required_inputs && data.required_inputs.length > 0) {
                            inputsContainer.append('<div class="small font-weight-bold text-dark mb-2">Please complete or clarify the missing information:</div>');
                            data.required_inputs.forEach(function (issue) {
                                const issueBox = $('<div class="form-group mb-3 ai-clarification-issue" data-field="' + issue.field + '"></div>');
                                const statusBadgeClass = issue.status === 'ambiguous' ? 'badge-info' : (issue.status === 'invalid' ? 'badge-danger' : 'badge-warning');
                                
                                issueBox.append('<div class="d-flex justify-content-between align-items-center mb-1">' +
                                    '<label class="small font-weight-bold mb-0 text-dark">' + $('<div>').text(issue.label || issue.field).html() + '</label>' +
                                    '<span class="badge ' + statusBadgeClass + ' py-0 px-1 small">' + $('<div>').text(issue.status).html() + '</span>' +
                                    '</div>');
                                issueBox.append('<div class="small text-muted mb-2">' + $('<div>').text(issue.reason).html() + '</div>');

                                // Phase 2.2: Intelligent Three-Way Disambiguation Triad
                                if (issue.resolution_options && issue.resolution_options.length > 0) {
                                    const triadContainer = $('<div class="d-flex flex-wrap ai-triad-container mb-2 p-1 border rounded bg-light"></div>');
                                    const triadPanel = $('<div class="ai-triad-panel border rounded p-2 bg-white mb-2"></div>');

                                    const optSelect = issue.resolution_options.find(function (o) { return o.key === 'select_existing'; });
                                    const optCreate = issue.resolution_options.find(function (o) { return o.key === 'create_new'; });
                                    const optCorrect = issue.resolution_options.find(function (o) { return o.key === 'correct_name'; });

                                    const btnSelect = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 ai-triad-btn"><i class="dripicons-search mr-1"></i>' + $('<div>').text(optSelect ? optSelect.label : 'Select existing').html() + '</button>');
                                    const btnCreate = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 ai-triad-btn"><i class="dripicons-plus mr-1"></i>' + $('<div>').text(optCreate ? optCreate.label : 'Create new').html() + '</button>');
                                    const btnCorrect = $('<button type="button" class="btn btn-sm btn-outline-secondary mb-1 ai-triad-btn"><i class="dripicons-pencil mr-1"></i>' + $('<div>').text(optCorrect ? optCorrect.label : 'Correct name').html() + '</button>');

                                    if (optCreate && !optCreate.can_create) {
                                        btnCreate.prop('disabled', true).addClass('disabled').attr('title', optCreate.disabled_reason || 'Permission denied');
                                    }

                                    triadContainer.append(btnSelect).append(btnCreate).append(btnCorrect);
                                    issueBox.append(triadContainer).append(triadPanel);

                                    function switchTriadMode(mode) {
                                        triadContainer.find('.ai-triad-btn').removeClass('active btn-dark text-white').addClass('btn-outline-secondary');
                                        triadPanel.empty();

                                        if (mode === 'select_existing') {
                                            btnSelect.removeClass('btn-outline-secondary').addClass('active btn-dark text-white');
                                            if (issue.candidates && issue.candidates.length > 0) {
                                                const candidateGroup = $('<div class="d-flex flex-wrap ai-candidate-group mb-1"></div>');
                                                issue.candidates.forEach(function (cand) {
                                                    const label = cand.name + (cand.code ? ' (' + cand.code + ')' : '');
                                                    const candBtn = $('<button type="button" class="btn btn-sm btn-outline-primary mr-1 mb-1 ai-candidate-btn"></button>')
                                                        .attr('data-field', issue.field)
                                                        .attr('data-value', cand.id)
                                                        .text(label);

                                                    candBtn.on('click', function () {
                                                        if ($(this).hasClass('active')) {
                                                            $(this).removeClass('active btn-primary').addClass('btn-outline-primary');
                                                            issueBox.removeAttr('data-resolved-val');
                                                        } else {
                                                            candidateGroup.find('.ai-candidate-btn').removeClass('active btn-primary').addClass('btn-outline-primary');
                                                            $(this).addClass('active btn-primary').removeClass('btn-outline-primary');
                                                            issueBox.attr('data-resolved-val', cand.id);
                                                        }
                                                    });
                                                    candidateGroup.append(candBtn);
                                                });
                                                triadPanel.append('<div class="small font-weight-bold text-dark mb-1">Choose an existing active record:</div>').append(candidateGroup);
                                            } else {
                                                const searchInput = $('<input type="text" class="form-control form-control-sm ai-clarification-input" placeholder="Enter exact record ID or search query">')
                                                    .attr('data-field', issue.field);
                                                triadPanel.append('<div class="small text-muted mb-1">Enter existing record ID or search term:</div>').append(searchInput);
                                            }
                                        } else if (mode === 'correct_name') {
                                            btnCorrect.removeClass('btn-outline-secondary').addClass('active btn-dark text-white');
                                            const prefilledVal = (optCreate && optCreate.prefilled_slots && optCreate.prefilled_slots.name) ? optCreate.prefilled_slots.name : '';
                                            const textInput = $('<input type="text" class="form-control form-control-sm ai-clarification-input">')
                                                .attr('data-field', issue.field)
                                                .attr('value', prefilledVal)
                                                .attr('placeholder', 'Type corrected ' + (issue.label || issue.field) + ' name');
                                            triadPanel.append('<div class="small text-muted mb-1">Update search term to retry matching:</div>').append(textInput);
                                        } else if (mode === 'create_new') {
                                            btnCreate.removeClass('btn-outline-secondary').addClass('active btn-dark text-white');
                                            triadPanel.append('<div class="small text-info mb-2"><i class="dripicons-information mr-1"></i>' + $('<div>').text(optCreate.description || 'Provide required fields for master creation.').html() + '</div>');
                                            const loading = $('<div class="small text-muted py-2"><i class="dripicons-loading mr-1"></i> Fetching master requirements...</div>');
                                            triadPanel.append(loading);

                                            $.ajax({
                                                url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/dependent-masters/spawn',
                                                method: 'POST',
                                                headers: headers,
                                                data: JSON.stringify({ field: issue.field }),
                                                success: function (spawnRes) {
                                                    loading.remove();
                                                    const formBody = $('<div class="ai-master-form-body bg-light p-2 rounded border"></div>');
                                                    const pre = spawnRes.prefilled || {};
                                                    const defs = spawnRes.tenant_defaults || {};

                                                    // Master fields layout
                                                    if (spawnRes.entity_type === 'supplier') {
                                                        formBody.append('<div class="form-group mb-1"><label>Supplier Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + $('<div>').text(pre.name || '').html() + '" placeholder="e.g. ABC Traders"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Company Legal Name</label><input type="text" class="form-control form-control-sm mf-company-name" placeholder="Legal entity name"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>GSTIN / VAT Number</label><input type="text" class="form-control form-control-sm mf-vat" placeholder="29ABCDE1234F1Z5"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Phone Number</label><input type="text" class="form-control form-control-sm mf-phone" placeholder="Phone"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>City</label><input type="text" class="form-control form-control-sm mf-city" placeholder="City"></div>');
                                                    } else if (spawnRes.entity_type === 'product') {
                                                        formBody.append('<div class="form-group mb-1"><label>Product Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + $('<div>').text(pre.name || '').html() + '" placeholder="Product Name"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Product Code / SKU *</label><input type="text" class="form-control form-control-sm mf-code" value="' + $('<div>').text(pre.code || '').html() + '" placeholder="SKU Code"></div>');
                                                        
                                                        const unitSelect = $('<select class="form-control form-control-sm mf-unit"></select>');
                                                        if (defs.units && defs.units.length > 0) {
                                                            defs.units.forEach(function (u) {
                                                                const isSel = (pre.unit_id && pre.unit_id === u.id) ? ' selected' : '';
                                                                unitSelect.append('<option value="' + u.id + '"' + isSel + '>' + $('<div>').text(u.unit_name + ' (' + u.unit_code + ')').html() + '</option>');
                                                            });
                                                        }
                                                        formBody.append($('<div class="form-group mb-1"><label>Primary Unit *</label></div>').append(unitSelect));

                                                        formBody.append('<div class="form-group mb-1"><label>Cost Price (₹)</label><input type="number" step="0.01" class="form-control form-control-sm mf-cost" value="' + (pre.cost || pre.price || '') + '" placeholder="0.00"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Selling Price (₹)</label><input type="number" step="0.01" class="form-control form-control-sm mf-price" value="' + (pre.price || pre.cost || '') + '" placeholder="0.00"></div>');

                                                        if (defs.categories && defs.categories.length > 0) {
                                                            const catSelect = $('<select class="form-control form-control-sm mf-category"><option value="">-- Default Category --</option></select>');
                                                            defs.categories.forEach(function (c) {
                                                                catSelect.append('<option value="' + c.id + '">' + $('<div>').text(c.name).html() + '</option>');
                                                            });
                                                            formBody.append($('<div class="form-group mb-1"><label>Category</label></div>').append(catSelect));
                                                        }
                                                    } else if (spawnRes.entity_type === 'warehouse') {
                                                        formBody.append('<div class="form-group mb-1"><label>Warehouse Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + $('<div>').text(pre.name || '').html() + '" placeholder="Warehouse Name"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Phone</label><input type="text" class="form-control form-control-sm mf-phone" placeholder="Phone"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Address / Location</label><input type="text" class="form-control form-control-sm mf-address" placeholder="Address"></div>');
                                                    } else if (spawnRes.entity_type === 'customer') {
                                                        formBody.append('<div class="form-group mb-1"><label>Customer Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + $('<div>').text(pre.name || '').html() + '" placeholder="Customer Name"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Phone</label><input type="text" class="form-control form-control-sm mf-phone" placeholder="Phone"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>GSTIN</label><input type="text" class="form-control form-control-sm mf-vat" placeholder="GSTIN"></div>');
                                                    }

                                                    // Gate 1: Preview & Approval buttons
                                                    const previewArea = $('<div class="mt-2 pt-2 border-top"></div>');
                                                    const btnPreview = $('<button type="button" class="btn btn-sm btn-primary ai-btn-preview-master py-1 px-3"><i class="dripicons-preview mr-1"></i>Preview Master Proposal</button>');
                                                    previewArea.append(btnPreview);
                                                    formBody.append(previewArea);
                                                    triadPanel.append(formBody);

                                                    btnPreview.on('click', function () {
                                                        btnPreview.prop('disabled', true).text('Validating & Preparing Preview...');
                                                        
                                                        const masterData = {
                                                            name: formBody.find('.mf-name').val(),
                                                        };
                                                        if (spawnRes.entity_type === 'supplier') {
                                                            masterData.company_name = formBody.find('.mf-company-name').val() || null;
                                                            masterData.vat_number = formBody.find('.mf-vat').val() || null;
                                                            masterData.phone_number = formBody.find('.mf-phone').val() || null;
                                                            masterData.city = formBody.find('.mf-city').val() || null;
                                                        } else if (spawnRes.entity_type === 'product') {
                                                            masterData.code = formBody.find('.mf-code').val();
                                                            masterData.unit_id = formBody.find('.mf-unit').val() ? parseInt(formBody.find('.mf-unit').val(), 10) : null;
                                                            masterData.cost = formBody.find('.mf-cost').val() ? parseFloat(formBody.find('.mf-cost').val()) : 0;
                                                            masterData.price = formBody.find('.mf-price').val() ? parseFloat(formBody.find('.mf-price').val()) : 0;
                                                            if (formBody.find('.mf-category').val()) {
                                                                masterData.category_id = parseInt(formBody.find('.mf-category').val(), 10);
                                                            }
                                                        } else if (spawnRes.entity_type === 'warehouse') {
                                                            masterData.phone = formBody.find('.mf-phone').val() || null;
                                                            masterData.address = formBody.find('.mf-address').val() || null;
                                                        } else if (spawnRes.entity_type === 'customer') {
                                                            masterData.phone_number = formBody.find('.mf-phone').val() || null;
                                                            masterData.vat_number = formBody.find('.mf-vat').val() || null;
                                                        }

                                                        $.ajax({
                                                            url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/dependent-masters/preview',
                                                            method: 'POST',
                                                            headers: headers,
                                                            data: JSON.stringify({
                                                                field: issue.field,
                                                                data: masterData
                                                            }),
                                                            success: function (prevRes) {
                                                                btnPreview.hide();
                                                                const prop = prevRes.proposal;
                                                                const propBox = $('<div class="ai-master-preview-box mt-2"></div>');
                                                                propBox.append('<div class="font-weight-bold text-dark mb-1"><i class="dripicons-document mr-1 text-primary"></i> Gate 1: ' + $('<div>').text(prop.action_title || prop.action_key).html() + ' (v' + prop.version + ')</div>');
                                                                propBox.append('<div class="small text-muted mb-2">Proposal #' + $('<div>').text(prop.id.substring(0, 8)).html() + ' · Target: ' + $('<div>').text(masterData.name).html() + '</div>');
                                                                
                                                                const btnApprove = $('<button type="button" class="btn btn-sm btn-success ai-btn-approve-master mr-2 py-1 px-3"><i class="dripicons-checkmark mr-1"></i>Approve & Create Master</button>');
                                                                const btnEdit = $('<button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2">Edit Form</button>');

                                                                btnApprove.on('click', function () {
                                                                    btnApprove.prop('disabled', true).text('Executing in ERP...');
                                                                    btnEdit.prop('disabled', true);

                                                                    $.ajax({
                                                                        url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/dependent-masters/approve-and-execute',
                                                                        method: 'POST',
                                                                        headers: headers,
                                                                        data: JSON.stringify({
                                                                            field: issue.field,
                                                                            master_proposal_id: prop.id,
                                                                            expected_version: prop.version || 1
                                                                        }),
                                                                        success: function (resExec) {
                                                                            triadContainer.remove();
                                                                            triadPanel.remove();
                                                                            issueBox.attr('data-resolved', 'true');
                                                                            issueBox.attr('data-resolved-id', resExec.newly_created.resource_id);
                                                                            
                                                                            const successBadge = $('<div class="ai-resumed-success-badge p-2 mb-2"><i class="dripicons-checkmark text-success mr-1"></i><strong>' + $('<div>').text(resExec.newly_created.resource_type.toUpperCase()).html() + ' Created & Linked:</strong> ' + $('<div>').text(resExec.newly_created.name || ('#' + resExec.newly_created.resource_id)).html() + ' (ID: #' + resExec.newly_created.resource_id + '). Task resumed at Rev ' + resExec.revision + '.</div>');
                                                                            issueBox.append(successBadge);

                                                                            if (resExec.can_prepare_proposal) {
                                                                                const readyBanner = $('<div class="alert alert-success mt-2 mb-0 p-2 d-flex justify-content-between align-items-center">' +
                                                                                    '<span class="small font-weight-bold"><i class="dripicons-checkmark mr-1"></i>All details ready! Purchase Order proposal can now be generated.</span>' +
                                                                                    '<button type="button" class="btn btn-sm btn-success ai-btn-prepare-final-po py-1 px-3"><i class="dripicons-document mr-1"></i>Generate Purchase Order Proposal</button>' +
                                                                                    '</div>');
                                                                                readyBanner.find('.ai-btn-prepare-final-po').on('click', function () {
                                                                                    $(this).prop('disabled', true).text('Preparing Proposal...');
                                                                                    $.ajax({
                                                                                        url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/prepare-proposal',
                                                                                        method: 'POST',
                                                                                        headers: headers,
                                                                                        success: function () {
                                                                                            readyBanner.html('<span class="small font-weight-bold text-success"><i class="dripicons-checkmark mr-1"></i>Purchase Order Proposal generated successfully! Review & approve in chat or Review Center.</span>');
                                                                                        },
                                                                                        error: function (e) {
                                                                                            alert('Error generating proposal: ' + (e.responseJSON?.message || 'Failed'));
                                                                                        }
                                                                                    });
                                                                                });
                                                                                cardBody.append(readyBanner);
                                                                            }
                                                                        },
                                                                        error: function (errExec) {
                                                                            btnApprove.prop('disabled', false).text('Approve & Create Master');
                                                                            btnEdit.prop('disabled', false);
                                                                            alert('Execution failed: ' + (errExec.responseJSON?.message || 'Error executing proposal'));
                                                                        }
                                                                    });
                                                                });

                                                                btnEdit.on('click', function () {
                                                                    propBox.remove();
                                                                    btnPreview.show().prop('disabled', false).text('Preview Master Proposal');
                                                                });

                                                                propBox.append(btnApprove).append(btnEdit);
                                                                previewArea.append(propBox);
                                                            },
                                                            error: function (errPrev) {
                                                                btnPreview.prop('disabled', false).text('Preview Master Proposal');
                                                                alert('Preview failed: ' + (errPrev.responseJSON?.message || 'Invalid master data'));
                                                            }
                                                        });
                                                    });
                                                },
                                                error: function (err) {
                                                    loading.html('<span class="text-danger small">Failed to load master requirements: ' + $('<div>').text(err.responseJSON?.message || 'Error').html() + '</span>');
                                                }
                                            });
                                        }
                                    }

                                    btnSelect.on('click', function () { switchTriadMode('select_existing'); });
                                    btnCreate.on('click', function () { switchTriadMode('create_new'); });
                                    btnCorrect.on('click', function () { switchTriadMode('correct_name'); });

                                    // Default initial view
                                    if (issue.candidates && issue.candidates.length > 0) {
                                        switchTriadMode('select_existing');
                                    } else {
                                        switchTriadMode('select_existing');
                                    }
                                } else if (issue.candidates && issue.candidates.length > 0) {
                                    const candidateGroup = $('<div class="d-flex flex-wrap ai-candidate-group mb-1"></div>');
                                    issue.candidates.forEach(function (cand) {
                                        const label = cand.name + (cand.code ? ' (' + cand.code + ')' : '');
                                        const candBtn = $('<button type="button" class="btn btn-sm btn-outline-primary mr-1 mb-1 ai-candidate-btn"></button>')
                                            .attr('data-field', issue.field)
                                            .attr('data-value', cand.id)
                                            .text(label);

                                        candBtn.on('click', function () {
                                            if ($(this).hasClass('active')) {
                                                $(this).removeClass('active btn-primary').addClass('btn-outline-primary');
                                            } else {
                                                candidateGroup.find('.ai-candidate-btn').removeClass('active btn-primary').addClass('btn-outline-primary');
                                                $(this).addClass('active btn-primary').removeClass('btn-outline-primary');
                                            }
                                        });
                                        candidateGroup.append(candBtn);
                                    });
                                    issueBox.append(candidateGroup);
                                } else {
                                    const textInput = $('<input type="text" class="form-control form-control-sm ai-clarification-input">')
                                        .attr('data-field', issue.field)
                                        .attr('placeholder', 'Enter ' + (issue.label || issue.field));
                                    issueBox.append(textInput);
                                }
                                inputsContainer.append(issueBox);
                            });
                        }
                        cardBody.append(inputsContainer);

                        // Actions Footer
                        if (data.task_id) {
                            const footer = $('<div class="d-flex justify-content-end align-items-center mt-3 pt-2 border-top"></div>');
                            const btnSubmit = $('<button type="button" class="btn btn-sm btn-primary mr-2 py-1 px-3 ai-btn-submit-clarification"><i class="dripicons-direction mr-1"></i>Submit Details</button>');
                            const btnCancel = $('<button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 ai-btn-cancel-clarification"><i class="dripicons-cross mr-1"></i>Cancel</button>');

                            btnSubmit.on('click', function () {
                                const corrections = [];
                                inputsContainer.find('.ai-clarification-issue').each(function () {
                                    if ($(this).attr('data-resolved') === 'true') {
                                        return; // Already resolved and persisted via Gate 1
                                    }
                                    const field = $(this).attr('data-field');
                                    const explicitVal = $(this).attr('data-resolved-val');
                                    const activeBtn = $(this).find('.ai-candidate-btn.active');
                                    if (explicitVal) {
                                        corrections.push({
                                            field: field,
                                            value: explicitVal,
                                            confirmation: 'user_selected'
                                        });
                                    } else if (activeBtn.length > 0) {
                                        corrections.push({
                                            field: field,
                                            value: activeBtn.attr('data-value'),
                                            confirmation: 'user_selected'
                                        });
                                    } else {
                                        const inputVal = $(this).find('.ai-clarification-input').val();
                                        if (inputVal && String(inputVal).trim() !== '') {
                                            corrections.push({
                                                field: field,
                                                value: String(inputVal).trim()
                                            });
                                        }
                                    }
                                });

                                if (corrections.length === 0) {
                                    alert('Please select, create, or enter details for at least one item.');
                                    return;
                                }

                                btnSubmit.prop('disabled', true).text('Updating...');
                                btnCancel.prop('disabled', true);

                                $.ajax({
                                    url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/inputs',
                                    method: 'PATCH',
                                    headers: headers,
                                    data: JSON.stringify({
                                        expected_revision: data.task_revision || 1,
                                        corrections: corrections
                                    }),
                                    success: function (res) {
                                        if (res.evaluation && res.evaluation.is_ready) {
                                            btnSubmit.text('Preparing Proposal...');
                                            $.ajax({
                                                url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/prepare-proposal',
                                                method: 'POST',
                                                headers: headers,
                                                success: function (prepRes) {
                                                    footer.html('<span class="badge badge-success p-2"><i class="dripicons-checkmark mr-1"></i>All details confirmed! Proposal ready. Send message or refresh to review.</span>');
                                                },
                                                error: function (prepXhr) {
                                                    btnSubmit.prop('disabled', false).text('Submit Details');
                                                    btnCancel.prop('disabled', false);
                                                    alert('Preparation failed: ' + (prepXhr.responseJSON?.message || 'Error'));
                                                }
                                            });
                                        } else {
                                            footer.html('<span class="badge badge-info p-2"><i class="dripicons-checkmark mr-1"></i>Updated! Additional details required. Refreshing...</span>');
                                        }
                                    },
                                    error: function (xhr) {
                                        btnSubmit.prop('disabled', false).text('Submit Details');
                                        btnCancel.prop('disabled', false);
                                        alert('Update failed: ' + (xhr.responseJSON?.message || 'Conflict or invalid input'));
                                    }
                                });
                            });

                            btnCancel.on('click', function () {
                                btnSubmit.prop('disabled', true);
                                btnCancel.prop('disabled', true).text('Cancelling...');
                                $.ajax({
                                    url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/cancel',
                                    method: 'POST',
                                    headers: headers,
                                    success: function () {
                                        footer.html('<span class="badge badge-secondary p-2"><i class="dripicons-cross mr-1"></i>Task Cancelled</span>');
                                    },
                                    error: function (xhr) {
                                        btnSubmit.prop('disabled', false);
                                        btnCancel.prop('disabled', false).text('Cancel');
                                        alert('Cancel failed: ' + (xhr.responseJSON?.message || 'Error'));
                                    }
                                });
                            });

                            footer.append(btnSubmit).append(btnCancel);
                            cardBody.append(footer);
                        }

                        card.append(cardBody);
                        bubble.append(card);
                    }
                });

                // Sources
                if (sources && sources.length > 0) {
                    const sourcesDiv = $('<div class="mt-2 pt-2 border-top small"><strong>Verified Sources:</strong></div>');
                    sources.forEach(function (s) {
                        const href = erpHref(s.href);
                        if (href) {
                            sourcesDiv.append($('<div></div>').append($('<a class="text-primary" target="_blank" rel="noopener"></a>')
                                .attr('href', href).text(s.title)));
                        }
                    });
                    bubble.append(sourcesDiv);
                }

                // Warnings
                if (warnings && warnings.length > 0) {
                    const warnDiv = $('<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small"><i class="dripicons-warning mr-1"></i> ' + $('<div>').text(warnings.join(' ')).html() + '</div>');
                    bubble.append(warnDiv);
                }

                container.append(bubble);
                $('#chat-messages').append(container);
                $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
            }

            // Submit Chat
            $('#chat-form').on('submit', function (e) {
                e.preventDefault();
                if (sending || loadingHistory) return;
                const input = $('#chat-input');
                const message = input.val().trim();
                if (!message) return;
                sending = true;

                appendMessage('user', message);
                input.val('');
                $('#char-counter').text('0 / 4000');

                // Loading state
                $('#btn-send-text').hide();
                $('#btn-send-spinner').show();
                $('#btn-send').prop('disabled', true);

                $.ajax({
                    url: '{{ route("ai.chat") }}',
                    method: 'POST',
                    headers: headers,
                    data: JSON.stringify({
                        message: message,
                        thread_id: currentThreadId
                    }),
                    success: function (res) {
                        if (res.thread_id && !currentThreadId) {
                            currentThreadId = res.thread_id;
                            loadThreads();
                        }

                        appendMessage('assistant', res.message, res.sources, res.warnings, res.facts);
                        loadTelemetry();
                    },
                    error: function (xhr) {
                        let err = {};
                        try {
                            err = xhr.responseJSON || (xhr.responseText ? JSON.parse(xhr.responseText) : {});
                        } catch (e) {
                            err = {};
                        }

                        let errMsg = err.error_message || err.message;
                        let errCode = err.error_code;

                        if (!errMsg) {
                            if (xhr.status === 504) {
                                errCode = 'GATEWAY_TIMEOUT';
                                errMsg = 'The server or AI model took too long to respond (504 Gateway Timeout). Please try again in a few moments.';
                            } else if (xhr.status === 502) {
                                errCode = 'BAD_GATEWAY';
                                errMsg = 'Unable to reach the AI model provider or backend service (502 Bad Gateway). The upstream model provider may be queued or unresponsive.';
                            } else if (xhr.status === 419) {
                                errCode = 'SESSION_EXPIRED';
                                errMsg = 'Your session has expired. Please refresh the page and try again.';
                            } else if (xhr.status === 0) {
                                errCode = 'NETWORK_ERROR';
                                errMsg = 'Network connection failed or the request was interrupted.';
                            } else {
                                errCode = errCode || ('HTTP_' + (xhr.status || 'ERROR'));
                                errMsg = xhr.statusText ? ('Request failed: ' + xhr.statusText) : 'An unexpected error occurred while processing your request.';
                            }
                        } else if (!errCode) {
                            errCode = 'AI_ERROR';
                        }

                        appendMessage('assistant', '[' + errCode + '] ' + errMsg);
                    },
                    complete: function () {
                        sending = false;
                        $('#btn-send-text').show();
                        $('#btn-send-spinner').hide();
                        $('#btn-send').prop('disabled', false);
                    }
                });
            });

            // Admin Telemetry & Monitoring
            function loadTelemetry() {
                if (!@json($isAdmin ?? false)) return;
                $.ajax({
                    url: '{{ route("ai.monitoring") }}',
                    method: 'GET',
                    headers: headers,
                    success: function (res) {
                        if (!res || !res.rpm) return;
                        const rpm = res.rpm;
                        const badge = $('#telemetry-rpm-badge');
                        const headerBadge = $('#header-rpm-badge');
                        const text = rpm.current + ' / ' + rpm.limit;
                        badge.text(text);
                        if (headerBadge.length) {
                            headerBadge.text('RPM: ' + text);
                        }

                        badge.removeClass('badge-success badge-warning badge-danger');
                        if (headerBadge.length) {
                            headerBadge.removeClass('badge-success badge-warning badge-danger');
                        }
                        if (rpm.status === 'limited') {
                            badge.addClass('badge-danger');
                            if (headerBadge.length) headerBadge.addClass('badge-danger');
                            $('#telemetry-rpm-status').text('Rate Limited!').addClass('text-danger').removeClass('text-muted');
                        } else if (rpm.status === 'warning') {
                            badge.addClass('badge-warning');
                            if (headerBadge.length) headerBadge.addClass('badge-warning');
                            $('#telemetry-rpm-status').text('High Usage').addClass('text-warning').removeClass('text-muted');
                        } else {
                            badge.addClass('badge-success');
                            if (headerBadge.length) headerBadge.addClass('badge-success');
                            $('#telemetry-rpm-status').text('Healthy').addClass('text-muted').removeClass('text-danger text-warning');
                        }

                        if (res.today) {
                            $('#telemetry-model-calls').text(res.today.model_invocations || 0);
                            $('#telemetry-tool-calls').text(res.today.erp_tool_calls || 0);
                            $('#telemetry-tokens-used').text((res.today.total_tokens || 0).toLocaleString());
                            $('#telemetry-token-limit').text((res.today.daily_token_limit || 100000).toLocaleString());
                        }
                    }
                });
            }

            $('#btn-toggle-telemetry').on('click', function () {
                $('#ai-telemetry-panel').slideToggle(150);
                loadTelemetry();
            });

            $('#btn-refresh-telemetry').on('click', function () {
                loadTelemetry();
            });

            // Boot
            loadThreads();
            loadTelemetry();

        })(jQuery);
    </script>
@endpush