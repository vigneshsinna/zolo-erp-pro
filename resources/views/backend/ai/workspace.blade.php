@extends('backend.layout.main')

@section('content')
<div class="container-fluid py-3 ai-workspace-container">
    <div class="row">
        <!-- Sidebar: Conversation Threads & Context -->
        <div class="col-md-3 col-lg-3 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                    <span class="font-weight-bold text-dark"><i class="dripicons-conversation mr-1"></i> AI Sessions</span>
                    <button id="btn-new-thread" class="btn btn-sm btn-outline-primary" title="Start new conversation">
                        <i class="dripicons-plus"></i> New
                    </button>
                </div>
                <div class="card-body p-2 d-flex flex-column" style="min-height: 500px;">
                    <!-- Active Scope Badge -->
                    <div class="p-2 mb-2 rounded bg-light border" style="font-size: 0.8rem;">
                        <div class="text-muted small text-uppercase font-weight-bold">Active Tenancy</div>
                        <div class="font-weight-bold text-truncate">{{ $company->legal_name ?? $company->name }}</div>
                        <div class="text-secondary small">FY ID: {{ $context->financialYearId }} &bull; Branch: {{ $context->branchId }}</div>
                    </div>

                    <!-- Threads List -->
                    <div id="thread-list" class="list-group list-group-flush flex-grow-1 overflow-auto" style="max-height: 420px;">
                        <div class="text-center text-muted py-4 small" id="thread-loading">
                            <span class="spinner-border spinner-border-sm text-primary mr-1"></span> Loading sessions...
                        </div>
                    </div>

                    <!-- Guardrail Status & Privacy Notice -->
                    <div class="mt-auto pt-2 border-top" style="font-size: 0.75rem;">
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
            <div class="card shadow-sm border-0 d-flex flex-column" style="min-height: 600px;">
                <!-- Header -->
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                    <div>
                        <h5 class="mb-0 font-weight-bold text-dark">
                            <i class="dripicons-sparkles text-primary mr-1"></i> zoloERP Copilot
                        </h5>
                        <small class="text-muted" id="active-thread-title">New Conversation</small>
                    </div>
                    @if($model)
                    <div>
                        <span class="badge badge-light border text-muted" style="font-size: 0.75rem;">
                            Model: {{ $model }}
                        </span>
                    </div>
                    @endif
                </div>

                <!-- Messages Feed -->
                <div id="chat-messages" class="card-body p-3 flex-grow-1 overflow-auto" style="max-height: 520px; background-color: #f8f9fa;">
                    <!-- Welcome Hero / Default Message -->
                    <div class="ai-welcome-box p-4 text-center my-auto">
                        <div class="mb-3">
                            <span class="badge-pill p-3" style="background: rgba(99, 102, 241, 0.1); color: var(--z-primary, #6366f1); font-size: 2rem;">
                                <i class="dripicons-sparkles"></i>
                            </span>
                        </div>
                        <h5 class="font-weight-bold">How can I assist your financial operations today?</h5>
                        <p class="text-muted small mx-auto" style="max-width: 500px;">
                            I query real-time general ledgers, customer dues, and open item allocations with strict accounting accuracy.
                        </p>
                        <div class="d-flex justify-content-center flex-wrap mt-3">
                            <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="Show my top 5 customers by outstanding balance">
                                "Top 5 customers with overdue dues"
                            </button>
                            <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="What is the total accounts receivable balance?">
                                "Total receivables balance"
                            </button>
                            <button class="btn btn-sm btn-outline-secondary m-1 prompt-suggestion" data-prompt="Check control account reconciliation status">
                                "AR control account reconciliation"
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Input Footer -->
                <div class="card-footer bg-white border-top p-2">
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
.ai-workspace-container .chat-bubble {
    max-width: 82%;
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 0.88rem;
    line-height: 1.45;
    word-break: break-word;
}
.ai-workspace-container .chat-user {
    background-color: var(--z-primary, #6366f1);
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 2px;
}
.ai-workspace-container .chat-assistant {
    background-color: #ffffff;
    color: #212529;
    align-self: flex-start;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 2px;
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

    // Append Message helper
    function appendMessage(role, text, sources, warnings, facts) {
        $('.ai-welcome-box').remove();
        const isUser = (role === 'user');
        const container = $('<div class="d-flex mb-3 ' + (isUser ? 'justify-content-end' : 'justify-content-start') + '"></div>');
        const bubble = $('<div class="chat-bubble ' + (isUser ? 'chat-user' : 'chat-assistant shadow-sm') + '"></div>');

        // Text content sanitized against XSS
        const textDiv = $('<div></div>').text(text);
        bubble.append(textDiv);

        (facts || []).forEach(function(fact) {
            if (fact.tool !== 'receivables.outstanding.v1') return;
            const data = fact.data;
            const results = $('<div class="mt-2 pt-2 border-top small"></div>');
            results.append($('<strong></strong>').text('Verified receivables · ' + fact.as_of));
            results.append($('<div></div>').text(data.currency + ' ' + data.total_outstanding + ' outstanding · '
                + data.total_customers_with_dues + ' customers with dues'));
            if (data.top_customers.length) {
                const customers = $('<ul class="mb-0 pl-3"></ul>');
                data.top_customers.forEach(function(customer) {
                    customers.append($('<li></li>').text(customer.customer_name + ' · ' + data.currency + ' ' + customer.outstanding_amount));
                });
                results.append(customers);
            }
            bubble.append(results);
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

    // Boot
    loadThreads();

})(jQuery);
</script>
@endpush
