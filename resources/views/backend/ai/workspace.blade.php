    @extends('backend.layout.main')

    @section('content')
    <div class="container-fluid py-3 ai-workspace-container">
        <div class="row">
            <!-- Sidebar: Conversation Threads & Context -->
            <div class="col-md-3 col-lg-3 mb-3">
                <div class="card shadow-sm border-0 ai-sidebar-card">
                    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2 flex-shrink-0">
                        <span class="font-weight-bold text-dark"><i class="dripicons-conversation mr-1"></i> AI Sessions</span>
                        <button id="btn-new-thread" class="btn btn-sm btn-outline-primary" title="Start new conversation">
                            <i class="dripicons-plus"></i> New
                        </button>
                    </div>
                    <div class="card-body p-2 d-flex flex-column" style="min-height: 0;">
                        <!-- Active Scope Badge -->
                        <div class="p-2 mb-2 rounded bg-light border flex-shrink-0" style="font-size: 0.8rem;">
                            <div class="text-muted small text-uppercase font-weight-bold">Active Tenancy</div>
                            <div class="font-weight-bold text-truncate">{{ $company->legal_name ?? $company->name }}</div>
                            <div class="text-secondary small">FY ID: {{ $context->financialYearId }} &bull; Branch: {{ $context->branchId }}</div>
                        </div>

                        <!-- Threads List -->
                        <div id="thread-list" class="list-group list-group-flush flex-grow-1" style="overflow-y: auto; min-height: 0;">
                            <div class="text-center text-muted py-4 small" id="thread-loading">
                                <span class="spinner-border spinner-border-sm text-primary mr-1"></span> Loading sessions...
                            </div>
                        </div>

                        <!-- Guardrail Status & Privacy Notice -->
                        <div class="mt-auto pt-2 border-top flex-shrink-0" style="font-size: 0.75rem;">
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
                    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2 flex-shrink-0">
                        <div>
                            <h5 class="mb-0 font-weight-bold text-dark">
                                <i class="dripicons-sparkles text-primary mr-1"></i> zoloERP Copilot
                            </h5>
                            <small class="text-muted" id="active-thread-title">New Conversation</small>
                        </div>
                        <div class="d-flex align-items-center">
                            @if($isAdmin ?? false)
                            <button id="btn-toggle-telemetry" class="btn btn-xs btn-outline-info mr-2 py-1 px-2" style="font-size: 0.75rem;" title="View AI telemetry & rate limits">
                                <i class="dripicons-meter mr-1"></i> <span id="header-rpm-badge" class="badge badge-success" style="font-size: 0.7rem;">RPM: --/35</span>
                            </button>
                            @endif
                            @if($model)
                            <div>
                                <span class="badge badge-light border text-muted" style="font-size: 0.75rem;">
                                    Model: {{ $model }}
                                </span>
                            </div>
                            @endif
                        </div>
                    </div>

                @if($isAdmin ?? false)
                <!-- Admin Telemetry Panel -->
                <div id="ai-telemetry-panel" class="border-bottom bg-light px-3 py-2 flex-shrink-0" style="display: none; font-size: 0.8rem;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div class="d-flex align-items-center mr-3 my-1">
                            <span class="text-muted mr-1">Provider RPM:</span>
                            <span id="telemetry-rpm-badge" class="badge badge-success mr-2 font-weight-bold">-- / 35</span>
                            <span id="telemetry-rpm-status" class="small text-muted font-italic">Healthy</span>
                        </div>
                        <div class="d-flex align-items-center mr-3 my-1">
                            <span class="text-muted mr-1">Today's Invocations:</span>
                            <span class="badge badge-light border mr-1"><strong id="telemetry-model-calls">0</strong> Model Calls</span>
                            <span class="badge badge-light border"><strong id="telemetry-tool-calls">0</strong> ERP Tools</span>
                        </div>
                        <div class="d-flex align-items-center mr-3 my-1">
                            <span class="text-muted mr-1">Tokens Today:</span>
                            <span id="telemetry-tokens-used" class="font-weight-bold text-dark">0</span>
                            <span class="text-muted small ml-1">/ <span id="telemetry-token-limit">100,000</span></span>
                        </div>
                        <div class="my-1">
                            <button id="btn-refresh-telemetry" class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" title="Refresh metrics">
                                <i class="dripicons-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
                @endif

                    <!-- Messages Feed -->
                    <div id="chat-messages" class="card-body p-3 flex-grow-1" style="overflow-y: auto; overflow-x: hidden; min-height: 0; background-color: #f8f9fa;">
                        <!-- Welcome Hero / Default Message -->
                        <div class="ai-welcome-box p-4 text-center my-auto">
                            <div class="mb-3">
                                <span class="badge-pill p-3" style="background: rgba(99, 102, 241, 0.1); color: var(--z-primary, #6366f1); font-size: 2rem;">
                                    <i class="dripicons-sparkles"></i>
                                </span>
                            </div>
                            <h5 class="font-weight-bold">How can I assist your business operations today?</h5>
                            <p class="text-muted small mx-auto" style="max-width: 540px;">
                                I query real-time customer outstanding dues, general ledgers, and warehouse inventory stock with strict accounting accuracy.
                            </p>
                            <div class="d-flex justify-content-center flex-wrap mt-3">
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="Show customer outstanding breakdown">
                                    "Customer outstanding breakdown"
                                </button>
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="How much stock for all the products are there?">
                                    "Total stock for all products"
                                </button>
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="Show my top 5 customers by outstanding balance">
                                    "Top 5 customers with dues"
                                </button>
                                <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="Check control account reconciliation status">
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
                                <textarea id="chat-input" class="form-control border-right-0" rows="1" 
                                placeholder="Ask about outstanding dues, customer aging, or financial balances..."
                                maxlength="4000" style="resize: none;" required></textarea>
                            <div class="input-group-append">
                                <button id="btn-send" class="btn btn-primary px-3" type="submit">
                                    <span id="btn-send-text"><i class="dripicons-direction mr-1"></i> Send</span>
                                    <span id="btn-send-spinner" class="spinner-border spinner-border-sm" style="display:none;" role="status"></span>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1 px-1">
                            <small class="text-muted" style="font-size: 0.7rem;">
                                Press <kbd>Enter</kbd> to send, <kbd>Shift + Enter</kbd> for line break
                            </small>
                            <small id="char-counter" class="text-muted" style="font-size: 0.7rem;">0 / 4000</small>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('css')
<style>
.ai-workspace-container .ai-chat-card,
.ai-workspace-container .ai-sidebar-card {
    height: calc(100vh - 170px);
    min-height: 560px;
    max-height: 860px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    border-radius: 8px;
}
.ai-workspace-container #chat-messages {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: hidden;
    background-color: #f8f9fa;
    scroll-behavior: smooth;
}
.ai-workspace-container #thread-list {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: hidden;
}
.ai-workspace-container .card-footer {
    flex-shrink: 0;
    position: relative;
    z-index: 5;
    background-color: #ffffff;
}
.ai-workspace-container .chat-bubble {
    max-width: 85%;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 0.88rem;
    line-height: 1.5;
    word-break: break-word;
}
.ai-workspace-container .chat-user {
    background-color: var(--z-primary, #6366f1);
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 2px;
    white-space: pre-wrap;
}
.ai-workspace-container .chat-assistant {
    background-color: #ffffff;
    color: #212529;
    align-self: flex-start;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 2px;
}
.ai-workspace-container .markdown-body table {
    width: 100%;
    margin: 10px 0;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.ai-workspace-container .markdown-body th,
.ai-workspace-container .markdown-body td {
    padding: 6px 10px;
    border: 1px solid #e2e8f0;
    text-align: left;
}
.ai-workspace-container .markdown-body th {
    background-color: #f1f5f9;
    font-weight: 600;
    color: #334155;
}
.ai-workspace-container .markdown-body tr:nth-child(even) {
    background-color: #f8fafc;
}
.ai-workspace-container .markdown-body p {
    margin-bottom: 0.5rem;
}
.ai-workspace-container .markdown-body p:last-child {
    margin-bottom: 0;
}
.ai-workspace-container .markdown-body ul,
.ai-workspace-container .markdown-body ol {
    padding-left: 1.25rem;
    margin-bottom: 0.5rem;
}
.ai-workspace-container .markdown-body blockquote {
    border-left: 3px solid #6366f1;
    background: #f8fafc;
    padding: 6px 12px;
    margin: 8px 0;
    border-radius: 0 4px 4px 0;
    color: #475569;
    font-size: 0.84rem;
}
.ai-workspace-container .markdown-body h1,
.ai-workspace-container .markdown-body h2,
.ai-workspace-container .markdown-body h3,
.ai-workspace-container .markdown-body h4,
.ai-workspace-container .markdown-body h5 {
    font-weight: 600;
    margin-top: 10px;
    margin-bottom: 6px;
    color: #0f172a;
}
.ai-workspace-container .markdown-body h3 { font-size: 0.96rem; }
.ai-workspace-container .markdown-body h4 { font-size: 0.90rem; }
.ai-workspace-container .markdown-body code {
    background-color: #f1f5f9;
    color: #e11d48;
    padding: 2px 4px;
    border-radius: 4px;
    font-size: 0.82rem;
}
.ai-workspace-container .markdown-body pre {
    background-color: #0f172a;
    color: #f8fafc;
    padding: 10px;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.8rem;
}
.ai-workspace-container .erp-fact-card {
    border-radius: 8px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
}
.ai-workspace-container .erp-fact-card thead th {
    font-size: 0.76rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    border-top: none;
}
.ai-workspace-container .due-table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-top: 10px;
    overflow: hidden;
}
</style>
@endpush

@push('scripts')
<script src="{{ asset('vendor/marked.min.js') }}"></script>
<script src="{{ asset('vendor/purify.min.js') }}"></script>
<script>
(function($) {
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
            success: function(res) {
                const list = $('#thread-list');
                list.empty();
                if (!res.threads || res.threads.length === 0) {
                    list.html('<div class="text-center text-muted py-3 small">No previous sessions.</div>');
                    return;
                }
                res.threads.forEach(function(th) {
                    const activeClass = (th.id === currentThreadId) ? 'active' : '';
                    const item = $('<a href="#" class="list-group-item list-group-item-action py-2 px-2 thread-item ' + activeClass + '" data-id="' + th.id + '">' +
                        '<div class="font-weight-bold text-truncate" style="font-size: 0.82rem;">' + $('<div>').text(th.title).html() + '</div>' +
                        '<small class="text-muted" style="font-size: 0.7rem;">' + th.updated_at + '</small>' +
                    '</a>');
                    list.append(item);
                });
            },
            error: function() {
                $('#thread-list').html('<div class="text-center text-danger py-2 small">Failed to load sessions.</div>');
            }
        });
    }

    // Switch or create thread
    $('#btn-new-thread').on('click', function() {
        if (sending || loadingHistory) return;
        currentThreadId = null;
        $('#active-thread-title').text('New Conversation');
        $('#thread-list .thread-item').removeClass('active');
        $('#chat-messages').html('<div class="ai-welcome-box p-4 text-center my-auto">' +
            '<h5 class="font-weight-bold">New Session Started</h5>' +
            '<p class="text-muted small">Ask an authoritative accounting or receivables question.</p>' +
        '</div>');
    });

    $(document).on('click', '.thread-item', function(e) {
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
            success: function(res) {
                $('#chat-messages').empty();
                if (res.messages && res.messages.length > 0) {
                    res.messages.forEach(function(msg) {
                        appendMessage(msg.role, msg.content, msg.sources, msg.warnings, msg.facts);
                    });
                } else {
                    $('#chat-messages').html('<div class="text-center text-muted py-3 small">No messages in this session.</div>');
                }
            },
            error: function() {
                $('#chat-messages').empty().append($('<div class="text-danger p-3"></div>').text('Could not load this conversation.'));
            },
            complete: function() {
                loadingHistory = false;
            }
        });
    });

    // Prompt Suggestions
    $(document).on('click', '.prompt-suggestion', function() {
        const text = $(this).data('prompt');
        $('#chat-input').val(text).focus();
        $('#char-counter').text(text.length + ' / 4000');
    });

    // Char counter
    $('#chat-input').on('input', function() {
        const len = $(this).val().length;
        $('#char-counter').text(len + ' / 4000');
    });

    // Send on Enter
    $('#chat-input').on('keydown', function(e) {
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
            return window.DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
        } catch (error) {
            console.error('Markdown rendering failed.');
            return escapeText();
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

        (facts || []).forEach(function(fact) {
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
                    .append('<div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase;">Total Outstanding</div>')
                    .append('<div class="font-weight-bold text-dark" style="font-size: 1.05rem;">' + currencySymbol + $('<div>').text(data.total_outstanding).html() + '</div>'));

                kpiRow.append($('<div class="col-6"></div>')
                    .append('<div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase;">Customers with Dues</div>')
                    .append('<div class="font-weight-bold text-dark" style="font-size: 1.05rem;">' + $('<div>').text(data.total_customers_with_dues).html() + '</div>'));

                cardBody.append(kpiRow);

                // Table of Top Customers
                if (data.top_customers && data.top_customers.length > 0) {
                    const tableWrapper = $('<div class="table-responsive"></div>');
                    const table = $('<table class="table table-sm table-hover mb-0" style="font-size: 0.8rem;"></table>');
                    table.append('<thead class="thead-light"><tr>' +
                        '<th>Customer</th>' +
                        '<th class="text-right">Outstanding</th>' +
                        '<th class="text-center">Invoices</th>' +
                        '<th class="text-right">Max Overdue</th>' +
                        '</tr></thead>');

                    const tbody = $('<tbody></tbody>');
                    data.top_customers.forEach(function(cust) {
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
                    .append('<div class="text-muted" style="font-size: 0.70rem; text-transform: uppercase;">Tracked Products</div>')
                    .append('<div class="font-weight-bold text-dark" style="font-size: 1.05rem;">' + $('<div>').text(data.total_products_tracked).html() + '</div>'));

                kpiRow.append($('<div class="col-3 border-right"></div>')
                    .append('<div class="text-muted" style="font-size: 0.70rem; text-transform: uppercase;">In Stock</div>')
                    .append('<div class="font-weight-bold text-success" style="font-size: 1.05rem;">' + $('<div>').text(data.in_stock_count).html() + '</div>'));

                kpiRow.append($('<div class="col-3 border-right"></div>')
                    .append('<div class="text-muted" style="font-size: 0.70rem; text-transform: uppercase;">Out of Stock</div>')
                    .append('<div class="font-weight-bold ' + (data.out_of_stock_count > 0 ? 'text-danger' : 'text-dark') + '" style="font-size: 1.05rem;">' + $('<div>').text(data.out_of_stock_count).html() + '</div>'));

                kpiRow.append($('<div class="col-3"></div>')
                    .append('<div class="text-muted" style="font-size: 0.70rem; text-transform: uppercase;">Est. Valuation</div>')
                    .append('<div class="font-weight-bold text-dark" style="font-size: 1.05rem;">' + currencySymbol + $('<div>').text(data.total_stock_value).html() + '</div>'));

                cardBody.append(kpiRow);

                // Grouped Units Summary
                if (data.units_summary && data.units_summary.length > 0) {
                    const unitBadges = $('<div class="d-flex flex-wrap align-items-center mb-2 px-1"></div>');
                    unitBadges.append('<span class="text-muted mr-2 small font-weight-bold" style="font-size: 0.72rem;">By Unit:</span>');
                    data.units_summary.forEach(function(u) {
                        unitBadges.append($('<span class="badge badge-light border mr-1 mb-1 font-weight-normal py-1 px-2" style="font-size: 0.75rem;">' +
                            '<strong>' + $('<div>').text(u.unit).html() + ':</strong> ' +
                            $('<div>').text(u.on_hand).html() + ' on-hand (avail: ' + $('<div>').text(u.available).html() + ')' +
                        '</span>'));
                    });
                    cardBody.append(unitBadges);
                }

                // Table of Products
                if (data.top_products && data.top_products.length > 0) {
                    const tableWrapper = $('<div class="table-responsive"></div>');
                    const table = $('<table class="table table-sm table-hover mb-0" style="font-size: 0.8rem;"></table>');
                    table.append('<thead class="thead-light"><tr>' +
                        '<th>Product</th>' +
                        '<th>Unit</th>' +
                        '<th class="text-right">On Hand</th>' +
                        '<th class="text-right">Reserved</th>' +
                        '<th class="text-right">Available</th>' +
                        '<th class="text-right">Valuation</th>' +
                        '</tr></thead>');

                    const tbody = $('<tbody></tbody>');
                    data.top_products.forEach(function(prod) {
                        const tr = $('<tr></tr>');
                        tr.append($('<td class="font-weight-bold"></td>').text(prod.name + ' (' + prod.code + ')'));
                        tr.append($('<td></td>').text(prod.unit));
                        tr.append($('<td class="text-right"></td>').text(prod.on_hand));
                        tr.append($('<td class="text-right text-muted"></td>').text(prod.reserved));
                        tr.append($('<td class="text-right font-weight-bold ' + (prod.available <= 0 ? 'text-danger' : 'text-success') + '"></td>').text(prod.available));
                        tr.append($('<td class="text-right"></td>').text(currencySymbol + prod.stock_value));
                        tbody.append(tr);
                    });
                    table.append(tbody);
                    tableWrapper.append(table);
                    cardBody.append(tableWrapper);
                }

                card.append(cardBody);
                bubble.append(card);
            }
        });

        // Sources
        if (sources && sources.length > 0) {
            const sourcesDiv = $('<div class="mt-2 pt-2 border-top small"><strong>Verified Sources:</strong></div>');
            sources.forEach(function(s) {
                const source = new URL(s.href, window.location.origin);
                if (source.origin === window.location.origin) {
                    sourcesDiv.append($('<div></div>').append($('<a class="text-primary" target="_blank" rel="noopener"></a>')
                        .attr('href', source.href).text(s.title)));
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
    $('#chat-form').on('submit', function(e) {
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
            success: function(res) {
                if (res.thread_id && !currentThreadId) {
                    currentThreadId = res.thread_id;
                    loadThreads();
                }

                appendMessage('assistant', res.message, res.sources, res.warnings, res.facts);
                loadTelemetry();
            },
            error: function(xhr) {
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
            complete: function() {
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
            success: function(res) {
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

    $('#btn-toggle-telemetry').on('click', function() {
        $('#ai-telemetry-panel').slideToggle(150);
        loadTelemetry();
    });

    $('#btn-refresh-telemetry').on('click', function() {
        loadTelemetry();
    });

    // Boot
    loadThreads();
    loadTelemetry();

})(jQuery);
</script>
@endpush
