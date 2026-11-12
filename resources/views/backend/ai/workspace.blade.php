@extends('backend.layout.main')

@section('content')
    <div class="container-fluid ai-workspace-container">
        <div class="ai-workspace-grid">
            <aside class="ai-sidebar-column" id="ai-history" aria-label="Conversation history">
                <div class="card ai-sidebar-card">
                    <div class="ai-panel-heading">
                        <h2>Conversations <span id="thread-count" class="ai-count"></span></h2>
                        <button id="btn-new-thread" class="btn btn-sm btn-primary" type="button">
                            <i class="dripicons-plus" aria-hidden="true"></i> New chat
                        </button>
                    </div>
                    <div class="ai-sidebar-body">
                        <div class="ai-scope-badge">
                            <span class="ai-eyebrow">Company workspace</span>
                            <strong>{{ $company->legal_name ?? $company->name }}</strong>
                            <span>Branch {{ $context->branchId }} &middot; FY ID {{ $context->financialYearId }}</span>
                        </div>
                        <label class="ai-history-search" for="thread-search">
                            <i class="dripicons-search" aria-hidden="true"></i>
                            <input id="thread-search" type="search" placeholder="Search conversations" autocomplete="off">
                        </label>
                        <div id="thread-list" aria-label="Saved conversations">
                            <div class="text-center text-muted py-4 small" id="thread-loading" role="status">
                                <span class="spinner-border spinner-border-sm mr-1"></span> Loading conversations…
                            </div>
                        </div>
                        <p id="thread-search-empty" class="small text-muted text-center py-3" hidden>No matching conversations.</p>
                        <div class="ai-guardrail-status">
                            <span class="ai-policy"><i class="dripicons-lock" aria-hidden="true"></i>
                                {{ $isReadOnly ? 'Read-only access' : 'Approval required for actions' }}</span>
                            <span>Scoped to your company and permissions.</span>
                        </div>
                    </div>
                </div>
            </aside>

            <section class="ai-chat-column" aria-label="zoloERP Copilot">
                <div class="card ai-chat-card">
                    <header class="ai-chat-header">
                        <button id="btn-toggle-history" class="btn ai-history-toggle" type="button"
                            aria-controls="ai-history" aria-expanded="false" aria-label="Show conversations">
                            <i class="dripicons-menu" aria-hidden="true"></i>
                        </button>
                        <span class="ai-copilot-mark" aria-hidden="true"><i class="dripicons-conversation"></i></span>
                        <div class="ai-chat-heading">
                            <h1>zoloERP Copilot <span class="ai-availability {{ $isAiEnabled ? '' : 'is-disabled' }}">{{ $isAiEnabled ? 'Available' : 'Disabled' }}</span></h1>
                            <p id="active-thread-title">New conversation</p>
                        </div>
                        <div class="ai-header-actions">
                            @if($model)
                                <details class="ai-engine-details">
                                    <summary><i class="dripicons-stack" aria-hidden="true"></i> AI engine <span aria-hidden="true">⌄</span></summary>
                                    <div class="ai-engine-popover">
                                        <strong>Server-managed models</strong>
                                        <p>Model selection follows your ERP configuration.</p>
                                        <span class="ai-eyebrow">Configured default · {{ $provider }}</span>
                                        <code>{{ $model }}</code>
                                    </div>
                                </details>
                            @endif
                            @if($isAdmin ?? false)
                                <button id="btn-toggle-telemetry" class="btn ai-telemetry-toggle-btn" type="button"
                                    aria-controls="ai-telemetry-panel" aria-expanded="false">
                                    <i class="dripicons-meter" aria-hidden="true"></i> Usage
                                </button>
                            @endif
                        </div>
                    </header>

                    @if($isAdmin ?? false)
                        <section id="ai-telemetry-panel" class="ai-telemetry-panel" aria-label="AI usage and provider limits" hidden>
                            <div class="ai-telemetry-heading">
                                <div><strong>AI usage</strong><span id="telemetry-updated" role="status">Loading usage…</span></div>
                                <button id="btn-refresh-telemetry" class="btn btn-sm btn-outline-secondary" type="button">Refresh</button>
                            </div>
                            <div class="ai-telemetry-grid">
                                <div class="ai-usage-stat">
                                    <span>Provider requests / minute</span>
                                    <strong id="telemetry-rpm-badge">—</strong>
                                    <span id="telemetry-rpm-status">Checking provider limit…</span>
                                    <div class="ai-usage-track"><span id="telemetry-rpm-fill"></span></div>
                                </div>
                                <div class="ai-usage-stat">
                                    <span>Company model calls today (UTC)</span><strong id="telemetry-model-calls">—</strong>
                                    <span><span id="telemetry-tool-calls">—</span> ERP tool calls</span>
                                </div>
                                <div class="ai-usage-stat">
                                    <span>Company tokens today (UTC)</span><strong id="telemetry-tokens-used">—</strong>
                                    <span>Daily limit: <span id="telemetry-token-limit">—</span></span>
                                </div>
                            </div>
                            <p class="ai-usage-explainer"><span id="telemetry-provider">Provider</span>: shared provider limit across workspaces.
                                Counts HTTP attempts, including retries. One chat may use several requests.
                                <span id="telemetry-reset"></span></p>
                        </section>
                    @endif

                    <div id="chat-messages" role="log" aria-label="Conversation messages" aria-live="polite" aria-relevant="additions" aria-busy="false">
                        <div class="ai-welcome-box">
                            <span class="ai-eyebrow">Your business, in focus</span>
                            <h2>What would you like<br>to work on?</h2>
                            <p>Explore your numbers, check stock, or prepare your next order. All in your company workspace.</p>
                            <div class="ai-prompt-grid">
                                <button class="prompt-suggestion" type="button" data-prompt="Show customer outstanding breakdown">
                                    <i class="dripicons-wallet" aria-hidden="true"></i><strong>Who owes us?</strong><span>Review customer outstanding balances</span>
                                </button>
                                <button class="prompt-suggestion" type="button" data-prompt="How much stock for all the products are there?">
                                    <i class="dripicons-box" aria-hidden="true"></i><strong>Check stock</strong><span>See what's available across products</span>
                                </button>
                                <button class="prompt-suggestion" type="button" data-prompt="Show my top 5 customers by outstanding balance">
                                    <i class="dripicons-graph-bar" aria-hidden="true"></i><strong>Prioritize collections</strong><span>Find the top five outstanding balances</span>
                                </button>
                                <button class="prompt-suggestion" type="button" data-prompt="Help me prepare a purchase order. Show me the details before placing the order.">
                                    <i class="dripicons-document-edit" aria-hidden="true"></i><strong>Prepare an order</strong><span>Start a purchase order for review</span>
                                </button>
                            </div>
                            <div class="ai-welcome-note"><i class="dripicons-shield" aria-hidden="true"></i> ERP records power your answers. You review proposed actions.</div>
                        </div>
                    </div>

                    <footer class="ai-composer-footer">
                        <form id="chat-form">
                            @csrf
                            <div class="ai-composer">
                                <label class="sr-only" for="chat-input">Message zoloERP Copilot</label>
                                <textarea id="chat-input" class="ai-chat-input" rows="2"
                                    placeholder="Ask a question or describe a task…" aria-describedby="ai-composer-hint"
                                    maxlength="4000" required></textarea>
                                <div class="ai-composer-toolbar">
                                    <span id="ai-response-status" role="status">Connected to your ERP</span>
                                    <button id="btn-dictate" class="btn btn-sm btn-outline-secondary" type="button" hidden
                                        aria-label="Start voice input" aria-pressed="false" title="Dictation uses your browser speech service. Review before sending.">Dictate</button>
                                    <button id="btn-send" class="btn btn-primary" type="submit" aria-label="Send message">
                                        <span id="btn-send-text">Send <i class="dripicons-direction ml-1" aria-hidden="true"></i></span>
                                        <span id="btn-send-spinner" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Working…</span>
                                    </button>
                                </div>
                            </div>
                            <div class="ai-composer-hint" id="ai-composer-hint">
                                <span>Enter to send <span class="ai-hint-separator">·</span> Shift + Enter for a new line</span>
                                <span id="char-counter">0 / 4000</span>
                            </div>
                        </form>
                    </footer>
                </div>
            </section>
        </div>
    </div>
    <!-- In-Chat PDF Preview Modal -->
    <div class="modal fade" id="ai-pdf-preview-modal" tabindex="-1" role="dialog" aria-labelledby="ai-pdf-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document" style="max-width: 950px; height: 90vh;">
            <div class="modal-content h-100 shadow border-0">
                <div class="modal-header py-2 bg-light d-flex justify-content-between align-items-center">
                    <h5 class="modal-title font-weight-bold" id="ai-pdf-modal-title"><i class="dripicons-document mr-1 text-primary"></i> Document PDF</h5>
                    <div class="d-flex align-items-center">
                        <a id="ai-pdf-download-btn" href="#" target="_blank" download class="btn btn-sm btn-primary mr-2"><i class="dripicons-download mr-1"></i> Download PDF</a>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                </div>
                <div class="modal-body p-0 bg-secondary" style="height: calc(100% - 55px);">
                    <iframe id="ai-pdf-iframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('css')
    <link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-workspace.css') }}" type="text/css">
@endpush

@push('scripts')
    <script src="{{ asset('vendor/marked.min.js') }}"></script>
    <script src="{{ asset('vendor/purify.min.js') }}"></script>
    <script>
        (function ($) {
            'use strict';

            const workspace = $('.ai-workspace-container');
            const welcomeMarkup = $('.ai-welcome-box').prop('outerHTML');
            function fitWorkspace() {
                workspace[0].style.setProperty('--ai-workspace-top', Math.ceil(workspace[0].getBoundingClientRect().top + parseFloat(workspace.css('padding-top'))) + 'px');
            }
            fitWorkspace();
            $(window).on('resize', fitWorkspace);
            window.visualViewport?.addEventListener('resize', fitWorkspace);

            $('#btn-toggle-history').on('click', function () {
                const open = !workspace.hasClass('is-history-open');
                workspace.toggleClass('is-history-open', open);
                $(this).attr({ 'aria-expanded': String(open), 'aria-label': open ? 'Hide conversations' : 'Show conversations' });
                if (open) $('#thread-search')[0].focus({ preventScroll: true });
            });
            function closeHistory() {
                workspace.removeClass('is-history-open');
                $('#btn-toggle-history').attr({ 'aria-expanded': 'false', 'aria-label': 'Show conversations' });
            }
            $(document).on('keydown.aiWorkspace', function (event) {
                if (event.key === 'Escape') {
                    if (workspace.hasClass('is-history-open')) {
                        closeHistory();
                        $('#btn-toggle-history')[0].focus({ preventScroll: true });
                    }
                    $('.ai-engine-details').prop('open', false);
                }
            });
            $(document).on('click.aiWorkspace', function (event) {
                if (!$(event.target).closest('.ai-engine-details').length) $('.ai-engine-details').prop('open', false);
                if (!$(event.target).closest('#ai-history, #btn-toggle-history').length) closeHistory();
            });

            if (!@json($canUseCopilot)) {
                $('#thread-list').text('Copilot access is unavailable in this context.');
                $('#ai-response-status').text('Copilot access unavailable');
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

            function filterThreads() {
                const query = $('#thread-search').val().trim().toLocaleLowerCase();
                let visible = 0;
                $('#thread-list .thread-item').each(function () {
                    const match = $(this).find('.ai-thread-item-title').text().toLocaleLowerCase().includes(query);
                    $(this).prop('hidden', !match);
                    if (match) visible++;
                });
                $('#thread-search-empty').prop('hidden', !query || visible > 0);
            }
            $('#thread-search').on('input', filterThreads);
            $(document).on('click', '#btn-retry-threads', loadThreads);

            // Load available threads on boot
            function loadThreads() {
                $.ajax({
                    url: '{{ route("ai.threads.index") }}',
                    method: 'GET',
                    headers: headers,
                    success: function (res) {
                        const list = $('#thread-list');
                        list.empty();
                        $('#thread-count').text(res.threads?.length || '');
                        $('#thread-search-empty').prop('hidden', true);
                        if (!res.threads || res.threads.length === 0) {
                            list.html('<div class="text-center text-muted py-3 small">Your conversations will appear here.</div>');
                            return;
                        }
                        $('#thread-count').text(res.threads.length);
                        res.threads.forEach(function (th) {
                            const active = th.id === currentThreadId;
                            const item = $('<button type="button" class="thread-item"></button>')
                                .attr({ 'data-id': th.id, 'aria-current': active ? 'true' : 'false', title: th.title })
                                .toggleClass('active', active)
                                .append($('<span class="ai-thread-item-title"></span>').text(th.title))
                                .append($('<span class="ai-thread-item-time"></span>').text(th.updated_at));
                            list.append(item);
                        });
                        filterThreads();

                        // Auto-restore active session across refreshes
                        const savedThreadId = sessionStorage.getItem('ai_active_thread');
                        if (!currentThreadId && res.threads.length > 0) {
                            const targetId = (savedThreadId && res.threads.some(function(t) { return t.id === savedThreadId; })) ? savedThreadId : res.threads[0].id;
                            $('#thread-list .thread-item[data-id="' + targetId + '"]').trigger('click');
                        }
                    },
                    error: function () {
                        $('#thread-list').html('<div class="ai-history-error" role="status">Could not load conversations.<br><button id="btn-retry-threads" class="btn btn-sm btn-outline-secondary" type="button">Try again</button></div>');
                    }
                });
            }

            // Switch or create thread
            $('#btn-new-thread').on('click', function () {
                if (sending || loadingHistory) return;
                currentThreadId = null;
                sessionStorage.removeItem('ai_active_thread');
                $('#active-thread-title').text('New conversation');
                $('#thread-list .thread-item').removeClass('active').attr('aria-current', 'false');
                $('#chat-messages').html(welcomeMarkup);
                closeHistory();
                $('#chat-input')[0].focus({ preventScroll: true });
            });

            $(document).on('click', '.thread-item', function (e) {
                e.preventDefault();
                if (sending || loadingHistory) return;
                loadingHistory = true;
                closeHistory();
                $('#chat-messages').attr('aria-busy', 'true');
                const id = $(this).data('id');
                currentThreadId = id;
                sessionStorage.setItem('ai_active_thread', id);
                $('#thread-list .thread-item').removeClass('active').attr('aria-current', 'false');
                $(this).addClass('active').attr('aria-current', 'true');

                $('#active-thread-title').text($(this).find('.ai-thread-item-title').text());
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
                            $('#chat-messages').html(welcomeMarkup);
                        }
                    },
                    error: function () {
                        $('#chat-messages').empty().append($('<div class="text-danger p-3"></div>').text('Could not load this conversation.'));
                    },
                    complete: function () {
                        loadingHistory = false;
                        $('#chat-messages').attr('aria-busy', 'false');
                    }
                });
            });

            // Prompt Suggestions
            $(document).on('click', '.prompt-suggestion', function () {
                const text = $(this).data('prompt');
                $('#chat-input').val(text);
                $('#chat-input')[0].focus({ preventScroll: true });
                $('#chat-input').trigger('input');
            });

            // Char counter
            $('#chat-input').on('input', function () {
                const len = $(this).val().length;
                $('#char-counter').text(len + ' / 4000');
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 144) + 'px';
            });

            // Send on Enter
            $('#chat-input').on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.originalEvent?.isComposing) {
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

            function appendReceiptCardToChat(refNo, details, pdfUrl, resType, currencySymbol) {
                $('.ai-welcome-box').remove();
                const container = $('<div class="ai-message ai-message-assistant mt-3"></div>');
                container.append($('<div class="ai-message-label">zoloERP Copilot</div>'));
                const bubble = $('<div class="chat-bubble chat-assistant"></div>');

                const isPurchase = (resType === 'purchase_order' || resType === 'purchase');
                const titleText = isPurchase ? 'Purchase Order Receipt' : 'Sales Order Receipt';
                const textDiv = $('<div class="markdown-body"></div>').html(
                    '<p><i class="dripicons-checkmark text-success mr-1"></i> <strong>' + titleText + ' (' + $('<div>').text(refNo).html() + ')</strong> has been successfully booked in the ERP register. Your official print receipt is ready below.</p>'
                );
                bubble.append(textDiv);

                const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-document text-primary mr-1"></i> Official Receipt · ' + $('<div>').text(refNo).html() + '</span>'));
                cardHeader.append($('<span class="badge badge-success px-2 py-1"><i class="dripicons-checkmark mr-1"></i>Booked in ERP</span>'));
                card.append(cardHeader);

                const cardBody = $('<div class="card-body p-3 bg-white"></div>');
                const partyName = details?.supplier_name || details?.customer_name || 'Authorized Party';
                const grandTotal = details?.grand_total || '0.00';
                cardBody.append('<div class="row no-gutters mb-2 ai-diff-row">' +
                    '<div class="col-6">Party: <strong>' + $('<div>').text(partyName).html() + '</strong></div>' +
                    '<div class="col-6 text-right">Status: <strong class="text-success">Booked &amp; Allocated</strong></div>' +
                    '</div>');

                const cardFooter = $('<div class="d-flex justify-content-between align-items-center font-weight-bold text-dark border-top pt-2"></div>');
                cardFooter.append($('<span></span>').text('Grand Total: ' + currencySymbol + grandTotal));

                const actions = $('<div class="d-flex align-items-center"></div>');
                if (pdfUrl) {
                    actions.append($('<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 mr-2 btn-preview-pdf"><i class="dripicons-preview mr-1"></i>View PDF in Chat</button>').attr('data-pdf-url', pdfUrl).attr('data-title', refNo));
                    actions.append($('<a class="btn btn-sm btn-primary py-1 px-2" target="_blank" download><i class="dripicons-download mr-1"></i>Download PDF Receipt</a>').attr('href', pdfUrl));
                }
                cardFooter.append(actions);
                cardBody.append(cardFooter);
                card.append(cardBody);
                bubble.append(card);

                container.append(bubble);
                $('#chat-messages').append(container);
                scrollToBottom();
            }

            // Append Message helper
            function appendMessage(role, text, sources, warnings, facts) {
                $('.ai-welcome-box').remove();
                const isUser = (role === 'user');
                const container = $('<div class="ai-message ' + (isUser ? 'ai-message-user' : 'ai-message-assistant') + '"></div>');
                container.append($('<div class="ai-message-label"></div>').text(isUser ? 'You' : 'zoloERP Copilot'));
                const bubble = $('<div class="chat-bubble ' + (isUser ? 'chat-user' : 'chat-assistant') + '"></div>');

                if (isUser) {
                    const textDiv = $('<div></div>').text(text);
                    bubble.append(textDiv);
                } else {
                    const textDiv = $('<div class="markdown-body"></div>').html(renderMarkdown(text));
                    bubble.append(textDiv);
                }

                (facts || []).forEach(function (fact) {
                    const data = fact.data || {};
                    const currencySymbol = (data.currency === 'INR' || !data.currency) ? '₹' : (data.currency + ' ');

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
                        const footer = $('<div class="d-flex justify-content-between align-items-center font-weight-bold text-dark border-top pt-2"></div>');
                        footer.append($('<span></span>').text('Grand Total: ' + currencySymbol + (data.grand_total || '0.00')));
                        const docPdfUrl = data.pdf_url || (data.id ? aiBaseUrl + '/documents/' + (data.document_type === 'purchase_order' ? 'purchase' : 'sale') + '/' + data.id + '/pdf' : null);
                        const actionGroup = $('<div class="d-flex align-items-center"></div>');
                        if (docPdfUrl) {
                            actionGroup.append($('<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 mr-2 btn-preview-pdf"><i class="dripicons-preview mr-1"></i>View PDF in Chat</button>').attr('data-pdf-url', docPdfUrl).attr('data-title', data.reference_no || 'Document'));
                            actionGroup.append($('<a class="btn btn-sm btn-primary py-1 px-2" target="_blank" download><i class="dripicons-download mr-1"></i>Download PDF</a>').attr('href', docPdfUrl));
                        }
                        footer.append(actionGroup);
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
                    } else if (data.proposal_id || fact.tool === 'purchase_order.create.v1' || fact.tool === 'action.purchase_order.v1') {
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        cardHeader.append($('<span class="font-weight-bold text-dark small"></span>').text((data.document_type || 'ERP Action') + ' Proposal · ' + (data.supplier?.name || data.customer?.name || data.customer_name || data.action_key || 'Review')));
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
                                tr.append($('<td class="text-right"></td>').text(currencySymbol + (item.net_unit_cost ?? item.net_unit_price ?? item.cost ?? '')));
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
                            const actionRow = $('<div class="d-flex justify-content-end align-items-center mt-2 pt-2 border-top flex-wrap"></div>');
                            const btnDraftPdf = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-2 py-1 px-2 btn-preview-pdf" title="View draft receipt PDF in chat"><i class="dripicons-document mr-1"></i>Draft PDF</button>')
                                .attr('data-pdf-url', aiBaseUrl + '/proposals/' + data.proposal_id + '/pdf')
                                .attr('data-title', (data.document_type || 'Draft') + ' - ' + data.proposal_id.substring(0, 8));
                            const btnApprove = $('<button type="button" class="btn btn-sm btn-success mr-2 py-1 px-3">Approve & Execute</button>');
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
                                        if (res.status === 'queued') {
                                            actionRow.empty().append($('<span role="status" class="badge badge-info p-2"></span>').text('Approved. Execution queued. View Action Review for progress.'));
                                        } else if (res.status === 'completed') {
                                            const receipt = res.execution?.receipt || {};
                                            const refNo = receipt.details?.reference_no || receipt.resource_id || res.proposal_id;
                                            const resType = receipt.resource_type;
                                            const resId = receipt.resource_id || receipt.details?.purchase_id || receipt.details?.sale_id;
                                            let pdfUrl = null;
                                            if (resId && (resType === 'purchase_order' || resType === 'purchase')) {
                                                pdfUrl = aiBaseUrl + '/documents/purchase/' + resId + '/pdf';
                                            } else if (resId && (resType === 'sales_order' || resType === 'sale')) {
                                                pdfUrl = aiBaseUrl + '/documents/sale/' + resId + '/pdf';
                                            } else if (data.proposal_id) {
                                                pdfUrl = aiBaseUrl + '/proposals/' + data.proposal_id + '/pdf';
                                            }
                                            const successContainer = $('<div class="d-flex align-items-center flex-wrap"></div>');
                                            successContainer.append($('<span role="status" class="badge badge-success p-2 mr-2 mb-1"></span>').html('<i class="dripicons-checkmark mr-1"></i>Booked: <strong>' + $('<div>').text(refNo).html() + '</strong>'));
                                            if (pdfUrl) {
                                                successContainer.append($('<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 mr-2 mb-1 btn-preview-pdf"><i class="dripicons-preview mr-1"></i>View PDF in Chat</button>').attr('data-pdf-url', pdfUrl).attr('data-title', refNo));
                                                successContainer.append($('<a class="btn btn-sm btn-primary py-1 px-2 mb-1" target="_blank" download><i class="dripicons-download mr-1"></i>Download PDF Receipt</a>').attr('href', pdfUrl));
                                            }
                                            actionRow.empty().append(successContainer);

                                            // Append authoritative receipt directly back into the conversation!
                                            appendReceiptCardToChat(refNo, receipt.details, pdfUrl, resType, currencySymbol);
                                        } else {
                                            actionRow.empty().append($('<span role="status" class="badge badge-success p-2"></span>').text('Approved. Execution is disabled.'));
                                        }
                                    },
                                    error: function (xhr) {
                                        btnApprove.prop('disabled', false).text('Approve & Execute');
                                        btnReject.prop('disabled', false);
                                        const errMsg = xhr.responseJSON?.message 
                                            || xhr.responseJSON?.error_message 
                                            || (xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors).flat().join(' ') : null)
                                            || 'Unexpected error';
                                        alert('Approval failed: ' + errMsg);
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
                                        const errMsg = xhr.responseJSON?.message 
                                            || xhr.responseJSON?.error_message 
                                            || (xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors).flat().join(' ') : null)
                                            || 'Unexpected error';
                                        alert('Rejection failed: ' + errMsg);
                                    }
                                });
                            });

                            actionRow.append(btnDraftPdf).append(btnApprove).append(btnReject);
                            footer.append(actionRow);
                        }

                        cardBody.append(footer);
                        card.append(cardBody);
                        bubble.append(card);
                    } else if (fact.tool === 'workflow.clarification.v1') {
                        let currentTaskRevision = parseInt(data.task_revision || 1, 10);
                        const card = $('<div class="card border shadow-none mt-2 mb-1 erp-fact-card ai-clarification-card"></div>');
                        const cardHeader = $('<div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center"></div>');
                        const taskIdShort = data.task_id ? String(data.task_id).substring(0, 8) : 'New';
                        cardHeader.append($('<span class="font-weight-bold text-dark small"><i class="dripicons-question text-warning mr-1"></i> Details Required for Proposal · Task #' + $('<div>').text(taskIdShort).html() + '</span>'));
                        const revBadge = $('<span class="badge badge-warning px-2 py-1"><i class="dripicons-hourglass mr-1"></i>Collecting Inputs (Rev <span class="ai-rev-badge">' + currentTaskRevision + '</span>)</span>');
                        cardHeader.append(revBadge);
                        card.append(cardHeader);

                        function updateTaskRevision(newRev) {
                            currentTaskRevision = parseInt(newRev, 10);
                            cardHeader.find('.ai-rev-badge').text(currentTaskRevision);
                        }

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

                                    const btnSelect = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 ai-triad-btn"><i class="dripicons-search mr-1" aria-hidden="true"></i>' + $('<div>').text(optSelect?.label || 'Select existing').html() + '</button>');
                                    const btnCreate = $('<button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 ai-triad-btn"><i class="dripicons-plus mr-1" aria-hidden="true"></i>' + $('<div>').text(optCreate?.label || 'Create new').html() + '</button>');
                                    const btnCorrect = $('<button type="button" class="btn btn-sm btn-outline-secondary mb-1 ai-triad-btn"><i class="dripicons-pencil mr-1" aria-hidden="true"></i>' + $('<div>').text(optCorrect?.label || 'Correct name').html() + '</button>');

                                    if (optCreate && !optCreate.can_create) {
                                        btnCreate.prop('disabled', true).addClass('disabled').attr('title', optCreate.disabled_reason || 'Permission denied');
                                    }

                                    triadContainer.append(btnSelect).append(btnCreate).append(btnCorrect);
                                    issueBox.append(triadContainer).append(triadPanel);

                                    function switchTriadMode(mode) {
                                        triadContainer.find('.ai-triad-btn').removeClass('active btn-dark text-white').addClass('btn-outline-secondary').attr('aria-pressed', 'false');
                                        ({ select_existing: btnSelect, create_new: btnCreate, correct_name: btnCorrect })[mode]?.attr('aria-pressed', 'true');
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

                                                        if (defs.tax_categories && defs.tax_categories.length > 0) {
                                                            const taxSelect = $('<select class="form-control form-control-sm mf-tax-category"></select>');
                                                            defs.tax_categories.forEach(function (tc) {
                                                                const isSel = (pre.tax_category_id && pre.tax_category_id === tc.id) ? ' selected' : '';
                                                                taxSelect.append('<option value="' + tc.id + '"' + isSel + '>' + $('<div>').text(tc.name).html() + '</option>');
                                                            });
                                                            formBody.append($('<div class="form-group mb-1"><label>GST Rate / Tax Category *</label></div>').append(taxSelect));
                                                        }

                                                        formBody.append('<div class="form-group mb-1"><label>HSN Code</label><input type="text" class="form-control form-control-sm mf-hsn" value="' + $('<div>').text(pre.hsn_code || '').html() + '" placeholder="e.g. 5208"></div>');
                                                    } else if (spawnRes.entity_type === 'warehouse') {
                                                        formBody.append('<div class="form-group mb-1"><label>Warehouse Name *</label><input type="text" class="form-control form-control-sm mf-name" value="' + $('<div>').text(pre.name || '').html() + '" placeholder="Warehouse Name"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Phone</label><input type="text" class="form-control form-control-sm mf-phone" placeholder="Phone"></div>');
                                                        formBody.append('<div class="form-group mb-1"><label>Address / Location *</label><input type="text" class="form-control form-control-sm mf-address" placeholder="Address"></div>');
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
                                                            if (formBody.find('.mf-tax-category').val()) {
                                                                masterData.tax_category_id = parseInt(formBody.find('.mf-tax-category').val(), 10);
                                                            }
                                                            if (formBody.find('.mf-hsn').val()) {
                                                                masterData.hsn_code = formBody.find('.mf-hsn').val().trim();
                                                            }
                                                        } else if (spawnRes.entity_type === 'warehouse') {
                                                            masterData.phone = formBody.find('.mf-phone').val() || null;
                                                            masterData.address = formBody.find('.mf-address').val() || '-';
                                                        } else if (spawnRes.entity_type === 'customer') {
                                                            masterData.phone_number = formBody.find('.mf-phone').val() || null;
                                                            masterData.tax_no = formBody.find('.mf-vat').val() || null;
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
                                                                            if (resExec.revision) {
                                                                                updateTaskRevision(resExec.revision);
                                                                            }
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

                                function submitPatch(retryWithLatest) {
                                    btnSubmit.prop('disabled', true).text('Updating...');
                                    btnCancel.prop('disabled', true);

                                    $.ajax({
                                        url: aiBaseUrl + '/workflow-tasks/' + data.task_id + '/inputs',
                                        method: 'PATCH',
                                        headers: headers,
                                        data: JSON.stringify({
                                            expected_revision: currentTaskRevision,
                                            corrections: corrections
                                        }),
                                        success: function (res) {
                                            if (res.task && res.task.revision) {
                                                updateTaskRevision(res.task.revision);
                                            }
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
                                            if (xhr.status === 409 && !retryWithLatest) {
                                                // Task revision conflict: auto-sync with latest task revision from server and retry seamlessly
                                                $.ajax({
                                                    url: aiBaseUrl + '/workflow-tasks/' + data.task_id,
                                                    method: 'GET',
                                                    headers: headers,
                                                    success: function (latestTaskRes) {
                                                        if (latestTaskRes.task && latestTaskRes.task.revision) {
                                                            updateTaskRevision(latestTaskRes.task.revision);
                                                            submitPatch(true);
                                                            return;
                                                        }
                                                        btnSubmit.prop('disabled', false).text('Submit Details');
                                                        btnCancel.prop('disabled', false);
                                                        alert('Update failed: Task revision conflict. Please refresh to reload state.');
                                                    },
                                                    error: function () {
                                                        btnSubmit.prop('disabled', false).text('Submit Details');
                                                        btnCancel.prop('disabled', false);
                                                        alert('Update failed: ' + (xhr.responseJSON?.message || 'Conflict or invalid input'));
                                                    }
                                                });
                                                return;
                                            }

                                            btnSubmit.prop('disabled', false).text('Submit Details');
                                            btnCancel.prop('disabled', false);
                                            alert('Update failed: ' + (xhr.responseJSON?.message || 'Conflict or invalid input'));
                                        }
                                    });
                                }

                                submitPatch(false);
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
                input.val('').trigger('input');
                $('#char-counter').text('0 / 4000');

                // Loading state
                $('#btn-send-text').hide();
                $('#btn-send-spinner').prop('hidden', false);
                $('#ai-response-status').text('Checking your request…');
                $('#chat-messages').attr('aria-busy', 'true').append('<div id="ai-thinking" class="ai-message ai-thinking"><span><span class="spinner-border spinner-border-sm mr-2" aria-hidden="true"></span>Working on your request…</span></div>');
                $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
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
                            sessionStorage.setItem('ai_active_thread', currentThreadId);
                            loadThreads();
                        }

                        $('#ai-thinking').remove();
                        appendMessage('assistant', res.message, res.sources, res.warnings, res.facts);
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

                        $('#ai-thinking').remove();
                        appendMessage('assistant', '[' + errCode + '] ' + errMsg);
                    },
                    complete: function () {
                        sending = false;
                        $('#btn-send-text').show();
                        $('#btn-send-spinner').prop('hidden', true);
                        $('#ai-thinking').remove();
                        $('#chat-messages').attr('aria-busy', 'false');
                        $('#ai-response-status').text('Connected to your ERP');
                        if (!$('#ai-telemetry-panel').prop('hidden')) loadTelemetry();
                        $('#btn-send').prop('disabled', false);
                    }
                });
            });

            // Provider limits describe a shared HTTP dispatch bucket, not chat messages.
            let telemetryLoading = false;
            function loadTelemetry() {
                if (!@json($isAdmin ?? false) || telemetryLoading) return;
                telemetryLoading = true;
                $('#btn-refresh-telemetry').prop('disabled', true);
                $('#telemetry-updated').text('Refreshing…');
                $.ajax({
                    url: '{{ route("ai.monitoring") }}',
                    method: 'GET',
                    headers: headers,
                    success: function (res) {
                        if (!res?.rpm) {
                            $('#telemetry-updated').text('Usage unavailable. Try refreshing.');
                            return;
                        }
                        const rpm = res.rpm;
                        const limited = rpm.limit > 0;
                        $('#telemetry-rpm-badge').text(limited ? rpm.current + ' / ' + rpm.limit : 'No limit');
                        $('#telemetry-provider').text(rpm.provider);
                        $('#telemetry-rpm-status').text(!limited ? 'Provider limiter disabled' : {
                            limited: 'Limit reached', warning: 'Approaching limit', healthy: 'Within limit'
                        }[rpm.status] || 'Status unavailable');
                        $('#telemetry-rpm-fill').css('width', (limited ? Math.min(100, Math.max(0, rpm.current / rpm.limit * 100)) : 0) + '%')
                            .toggleClass('is-warning', rpm.status === 'warning').toggleClass('is-limited', rpm.status === 'limited');
                        $('#telemetry-reset').text(limited ? 'Current ' + (rpm.window_seconds || 60) + '-second window. ' +
                            (rpm.reset_in_seconds > 0 ? 'Resets in ' + rpm.reset_in_seconds + 's at this refresh.' : 'No active window.') : '');
                        if (res.today) {
                            $('#telemetry-model-calls').text(res.today.model_invocations.toLocaleString());
                            $('#telemetry-tool-calls').text(res.today.erp_tool_calls.toLocaleString());
                            $('#telemetry-tokens-used').text(res.today.total_tokens.toLocaleString());
                            $('#telemetry-token-limit').text(res.today.daily_token_limit.toLocaleString());
                        }
                        $('#telemetry-updated').text('Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
                    },
                    error: function () {
                        $('#telemetry-updated').text('Refresh failed. Previous values may be out of date.');
                    },
                    complete: function () {
                        telemetryLoading = false;
                        $('#btn-refresh-telemetry').prop('disabled', false);
                    }
                });
            }
            $('#btn-toggle-telemetry').on('click', function () {
                const open = $('#ai-telemetry-panel').prop('hidden');
                $('#ai-telemetry-panel').prop('hidden', !open);
                $(this).attr('aria-expanded', String(open));
                if (open) loadTelemetry();
            });
            $('#btn-refresh-telemetry').on('click', loadTelemetry);
            if (@json($isAdmin ?? false)) {
                window.setInterval(function () {
                    if (!document.hidden && !$('#ai-telemetry-panel').prop('hidden')) loadTelemetry();
                }, 15000);
            }

            // In-Chat PDF Preview modal handler
            $(document).on('click', '.btn-preview-pdf', function (e) {
                e.preventDefault();
                const pdfUrl = $(this).attr('data-pdf-url') || $(this).data('pdf-url');
                const title = $(this).attr('data-title') || $(this).data('title') || 'Receipt';
                if (!pdfUrl) return;
                $('#ai-pdf-modal-title').html('<i class="dripicons-document mr-1 text-primary"></i> Receipt / Voucher: <strong>' + $('<div>').text(title).html() + '</strong>');
                $('#ai-pdf-download-btn').attr('href', pdfUrl);
                $('#ai-pdf-iframe').attr('src', pdfUrl);
                $('#ai-pdf-preview-modal').modal('show');
            });

            $('#ai-pdf-preview-modal').on('hidden.bs.modal', function () {
                $('#ai-pdf-iframe').attr('src', '');
            });

            // Boot
            loadThreads();

        })(jQuery);
    </script>
@endpush
