@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-operations.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('css/pages/ai-automation.css') }}">
@endpush

@section('content')
<main class="container-fluid ai-ops ai-automation py-3" aria-labelledby="agent-performance-title">
    <header class="ai-ops-head">
        <div>
            <span class="ai-ops-eyebrow">ZoloERP / AI Operations</span>
            <h1 id="agent-performance-title">Agent performance</h1>
            <p>Measured activity from scheduled ERP checks and human-reviewed outcomes in your selected company, branch and financial year.</p>
        </div>
        <nav class="ai-automation-actions" aria-label="Performance navigation">
            <a class="btn btn-primary" href="{{ route('ai.builder.index') }}">Automation Builder</a>
            <a class="btn btn-outline-secondary" href="{{ route('ai.operations') }}">AI Operations</a>
        </nav>
    </header>

    @if(session('message'))
        <div role="status" class="alert alert-success">{{ session('message') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="alert alert-danger">
            <strong>Review could not be recorded.</strong>
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="ai-automation-alert" role="note">
        <strong>What these numbers mean</strong>
        A completed automation is a finished low-stock check or draft preparation, not an automatically posted financial transaction.
        Accuracy requires an actual human review. Gross savings are reviewer estimates based on time saved and an entered hourly benchmark; they are not audited profit or realized financial savings.
    </div>

    <div class="ai-automation-period" aria-label="Reporting period">
        <span class="ai-ops-note">Period (UTC):</span>
        @foreach([7,30,90] as $days)
            <a class="btn btn-sm {{ $metrics['period_days'] === $days ? 'btn-primary' : 'btn-outline-secondary' }}"
                href="{{ route('ai.performance.index', ['days' => $days]) }}"
                @if($metrics['period_days'] === $days) aria-current="page" @endif>{{ $days }} days</a>
        @endforeach
    </div>

    @unless($metrics['enabled'])
        <section class="ai-ops-panel mt-3" aria-label="Performance unavailable">
            <h2>Automation monitoring is not active</h2>
            <p>Company AI or scheduling is disabled. There are no active scheduled-run metrics to display.</p>
        </section>
    @else
        <section class="ai-automation-metrics" aria-label="Automation performance summary">
            <div class="ai-automation-metric">
                <span>Completed automation runs</span>
                <strong>{{ number_format($metrics['completed']) }}</strong>
                <p>{{ number_format($metrics['total']) }} scheduled checks during this period. These are not automatic ERP postings.</p>
            </div>
            <div class="ai-automation-metric">
                <span>Exceptions needing attention</span>
                <strong>{{ number_format($metrics['exceptions']) }}</strong>
                <p>{{ $metrics['failed'] }} failed runs and {{ $metrics['reconciliation'] }} linked proposals requiring reconciliation.</p>
            </div>
            <div class="ai-automation-metric">
                <span>Reviewed outcome accuracy</span>
                <strong>{{ $metrics['accuracy_percent'] === null ? 'Not measured' : number_format($metrics['accuracy_percent'], 1) . '%' }}</strong>
                <p>{{ $metrics['correct_count'] }} marked correct out of {{ $metrics['reviewed_count'] }} human-reviewed completed checks. Not an AI document-extraction accuracy score.</p>
            </div>
            <div class="ai-automation-metric">
                <span>Estimated gross labour savings</span>
                <strong>₹{{ number_format($metrics['estimated_gross_savings_minor'] / 100, 2) }}</strong>
                <p>Only reviewer-entered time benchmarks. No model expenses, operational overhead or accounting benefit assumed.</p>
            </div>
            <div class="ai-automation-metric">
                <span>Average execution latency</span>
                <strong>{{ $metrics['average_execution_ms'] === null ? 'No data' : number_format($metrics['average_execution_ms']) . ' ms' }}</strong>
                <p>Elapsed time from run start to completion; excludes time waiting in the queue.</p>
            </div>
            <div class="ai-automation-metric">
                <span>P95 execution latency</span>
                <strong>{{ $metrics['p95_sample_ms'] === null ? 'No data' : number_format($metrics['p95_sample_ms']) . ' ms' }}</strong>
                <p>Based on up to 5,000 most recent finished runs. {{ $metrics['latency_sample_count'] }} timed runs in period.</p>
            </div>
            <div class="ai-automation-metric">
                <span>Stock alerts / PO drafts</span>
                <strong>{{ number_format($metrics['alerts']) }} / {{ number_format($metrics['drafts']) }}</strong>
                <p>Low-stock items flagged and purchase-order proposals prepared for human approval.</p>
            </div>
            <div class="ai-automation-metric">
                <span>Model cost attributed to runs</span>
                <strong>₹{{ number_format($metrics['attributed_model_cost_minor'] / 100, 2) }}</strong>
                <p>{{ number_format($metrics['attributed_credits_debited']) }} recorded model credits for matched run IDs. Deterministic stock checks need no model.</p>
            </div>
        </section>

        <div class="ai-ops-lower">
            <section class="ai-ops-panel" aria-labelledby="agent-volume-heading">
                <h2 id="agent-volume-heading">Recent automation runs</h2>
                <p>Completed and failed checks by UTC day. Last {{ min(14, $metrics['period_days']) }} days within the selected period.</p>
                @php $maxDaily = max(1, max(array_column($metrics['daily'], 'total') ?: [0])); @endphp
                <div class="ai-automation-daily" role="list">
                    @foreach($metrics['daily'] as $day)
                        <div class="ai-automation-daily-row" role="listitem">
                            <span>{{ $day['day'] }}</span>
                            <progress class="ai-automation-progress" max="{{ $maxDaily }}" value="{{ $day['completed'] }}"
                                aria-label="{{ $day['day'] }} completed checks"></progress>
                            <small>{{ $day['completed'] }} complete · {{ $day['failed'] }} failed</small>
                        </div>
                    @endforeach
                </div>
            </section>
            <section class="ai-ops-panel" aria-labelledby="agent-measurement-heading">
                <h2 id="agent-measurement-heading">How measurements are calculated</h2>
                <p>Figures use existing run records and immutable reviewer assessments, not predicted values from an LLM.</p>
                <dl class="ai-automation-definitions">
                    <dt>Success rate</dt>
                    <dd>{{ $metrics['total'] ? number_format(100 * $metrics['completed'] / $metrics['total'], 1) . '%' : 'No runs' }} — completed runs divided by all scheduled runs in scope, including queued and cancelled.</dd>
                    <dt>Human-reviewed accuracy</dt>
                    <dd>Correct assessments divided by all assessments. Results without a review never count as accurate.</dd>
                    <dt>Estimated gross savings</dt>
                    <dd>Sum of reviewer-estimated minutes saved × reviewer-entered hourly benchmark ÷ 60. Never counted for incorrect outcomes.</dd>
                    <dt>Exceptions</dt>
                    <dd>Failed runs plus linked proposals requiring reconciliation; a low-stock alert alone is not a failure.</dd>
                    <dt>AI costs</dt>
                    <dd>Only metered invocation records directly matched to eligible automation run IDs. Normal Copilot usage is not attributed here.</dd>
                </dl>
                <div class="ai-automation-footnote" role="note">
                    <strong>Not yet measured:</strong> automatic financial-posting accuracy, realized cost savings, and accuracy of OCR/invoice extraction across a verified ground-truth corpus.
                </div>
            </section>
        </div>
        <section class="ai-ops-panel" aria-labelledby="agent-recent-heading">
            <div class="ai-automation-panel-heading">
                <div>
                    <h2 id="agent-recent-heading">Recent run evidence and outcome reviews</h2>
                    <p>Reviews are permanent and available only to users with existing AI action-approval permission.</p>
                </div>
                <span class="ai-automation-counter">Latest 25 runs</span>
            </div>
            <div class="table-responsive ai-automation-activity-table">
                <table class="table table-sm ai-automation-table">
                    <thead><tr>
                        <th scope="col">Automation</th><th scope="col">Scheduled UTC</th>
                        <th scope="col">Status</th><th scope="col">Review</th>
                    </tr></thead>
                    <tbody>
                    @forelse($metrics['recent'] as $run)
                        <tr>
                            <td>{{ $run['name'] }}</td>
                            <td>{{ $run['scheduled_for'] }}</td>
                            <td>
                                {{ ucwords(str_replace('_', ' ', $run['status'])) }}
                                @if($run['error_code'])<small class="d-block">{{ $run['error_code'] }}</small>@endif
                            </td>
                            <td>
                                @if($run['reviewed'])
                                    <span class="ai-automation-pill">Reviewed</span>
                                @elseif($metrics['can_review'] && $run['status'] === 'completed')
                                    <details class="ai-automation-review">
                                        <summary>Assess outcome</summary>
                                        <form method="POST" action="{{ route('ai.performance.review') }}" class="mt-2">
                                            @csrf
                                            <input type="hidden" name="run_id" value="{{ $run['run_id'] }}">
                                            <input type="hidden" name="days" value="{{ $metrics['period_days'] }}">
                                            <div class="form-group">
                                                <label for="review-outcome-{{ $loop->index }}">Outcome</label>
                                                <select class="form-control" id="review-outcome-{{ $loop->index }}" name="outcome">
                                                    <option value="correct">Correct</option>
                                                    <option value="incorrect">Incorrect</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="review-minutes-{{ $loop->index }}">Estimated minutes saved</label>
                                                <input id="review-minutes-{{ $loop->index }}" class="form-control" type="number" name="minutes_saved" min="0" max="240" value="0" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="review-rate-{{ $loop->index }}">Hourly benchmark (₹)</label>
                                                <input id="review-rate-{{ $loop->index }}" class="form-control" type="number" name="hourly_rate_rupees" min="0" max="10000" value="0" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="review-notes-{{ $loop->index }}">Evidence / notes</label>
                                                <textarea id="review-notes-{{ $loop->index }}" name="notes" class="form-control" rows="2" maxlength="500"
                                                    placeholder="Explain observed outcome, not private document content"></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Record assessment</button>
                                        </form>
                                    </details>
                                @else
                                    <span class="ai-ops-note">{{ $run['status'] === 'completed' ? 'Not yet reviewed' : 'Requires completed run' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No scheduled runs for this period. Create an automation in the Builder and process it through the existing worker.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endunless
</main>
@endsection
