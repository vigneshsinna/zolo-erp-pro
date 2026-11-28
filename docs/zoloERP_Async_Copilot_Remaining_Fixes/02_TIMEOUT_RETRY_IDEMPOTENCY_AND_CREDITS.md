# 02 — R14–R16: Timeout budgets, optimization, recovery and credit safety

## 1. Correct timeout hierarchy (proposed initial settings)

Current 75 s HTTP timeout × up to 3 attempts × up to 5 PDF pages can exceed the 120 s web limit even without ERP validation. Existing job retry count of 2 can compound calls. Make one layer own retry policy. Treat the previous 225 s number as possible per-page provider budget, not observed latency. Measure real stages. Do not set unlimited timeouts or disable provider TLS validation.

| Layer | Proposed initial policy |
|---|---|
| Web upload/admission | bounded request, no provider calls; target p95 server-side <= 3 s after upload completes on agreed staging fixture; measure upload separately |
| Provider connect | 10 s, within request budget |
| Per-page request | 60–75 s, clamp to remaining deadline |
| Provider immediate retries | 0 on worker path initially; schedule delayed retry instead |
| Page job execution | 180 s initial including overhead; worker/platform may enforce a different lower bound |
| Dedicated queue visibility/retry_after | 240 s or measured > worker/job timeout + safety margin; do not alter unrelated queue globally |
| Task deadline | explicit bounded per-image/PDF/audio policy (e.g. 10 min total including queue wait for single image; decide from actual measurements); separate timeout from cancellation |
| Attempts | max 2 provider attempts per page total initially, persisted across queue delivery; 429 deferrals bounded by absolute deadline and separate deferral counter |
| Backoff | scheduled jittered 15/30/60 s for eligible transient failures, honoring Retry-After and remaining budget; no sleeping PHP request or queue worker |

Numbers are engineering starting points; validate actual deployment supervisor, PHP, proxy, Redis/database visibility, provider limits and OS capabilities. Laravel worker timeout uses PCNTL where available; Windows native PHP may not enforce it. Use provider HTTP timeouts + persisted deadline/lease everywhere and Linux/WSL/container staging for actual process-kill/worker timeout testing. `retry_after` must exceed effective hard execution bound; a lease alone cannot stop an already-running external call. PDF page jobs, not one unbounded 5× timeout worker. Limit per-task/page concurrency and queue size. Never silently run on `sync` when async enabled. Return configuration-unavailable or queued-with-no-worker status using verified heartbeat, no hidden fallback. Add config validation and health checks.

## 2. Provider retry and error classification

Modify `NvidiaOmniPerceptionClient`, `MediaExtractionService`, `ApprovedModelGateway` and existing job or coordinator. Expose configurable max retries (currently hardcoded 2), per-page timeout, global attempt budget, deadline and cancellation token where feasible. Use monotonic elapsed clock for within-process timing and persisted UTC deadline across processes. Before every call ensure approved provider/model, quota, rate limit, credits, task lease and remaining budget. Provider request ID/correlation recorded if available. Do not reuse request IDs as proof provider deduplicates calls unless provider documents that guarantee. Never automatically retry unknown outcome solely because no response was received; classify, reconcile, and require explicit operator/user choice if provider billing unknown under policy.

| Error | Behavior |
|---|---|
| 429 | honor Retry-After (seconds or valid date), delay release/reschedule; no tight loop, bounded deferrals |
| Connect/timeout, selected 5xx | retry only if policy permits and budget remains; record actual attempt, distinguish possible remote completion |
| 400/422 schema/request | terminal until input corrected, not automatic retry |
| 401/403 provider | configuration problem, safe message, alert; no repeat |
| TLS/cert, unsupported model, blocked feature | terminal configuration or permission issue; no weaker fallback |
| Invalid/truncated JSON | manual review or explicitly bounded retry; never fabricate extracted fields or ignore incomplete pages |
| Worker killed / lost result | reconcile attempt and durable extraction before scheduling anything; uncertain usage explicit |
| Insufficient credits / license | no provider dispatch, safe status and resume policy after authorized correction |

Use existing gateway for every actual provider dispatch; no provider calls in controller, deterministic resolution or status endpoints. Respect shared provider RPM across all routes/workers (previous 35 RPM is planning assumption; read actual configured quota). Rate limiter concurrency atomic across workers, bounded per-tenant fairness/backpressure, prevent one PDF monopolizing capacity. Metrics distinguish admitted, sent, responded, failed, uncertain and retry-scheduled attempts. No raw invoice/prescription data in logs. Keep approved model identity and actual attempt timing; UI displays model only when actual dispatch metadata exists.

## 3. Image and PDF performance (R15)

Original 5.6 MB PNG is not necessarily excessive or the root cause. Current preprocessing downsizes to max dimension 2048, keeps PNG for PNG sources and strips metadata. Measure original bytes, decoded pixels, derivative bytes/dimensions, encoding time, request payload bytes, provider latency and field accuracy. Never sacrifice small optical signs/decimal points, invoice number or GST fields for a target file size. Retain private original under existing retention policy and use sanitized derivative only for provider. Proposed adaptive encoder:

- Validate content magic and dimensions before costly decode; bound pixel count, decompression, memory, time, page count and output bytes. Use existing Intervention Image, GD/Imagick and safe PDF rasterizer/audio transcoder; do not allow arbitrary process args or external URL ingestion.
- Orientation/metadata strip, preserve aspect ratio, optionally compare lossless optimized PNG vs quality 85–92 JPEG for photographed bills with readability checks; retain lossless or higher resolution for dense prescription grids/text; benchmark against labeled holdout. Do not force JPEG for all files. Avoid repeated full-size base64 copies, release buffers after each page; provider data URL base64 increases transfer size by about one third before JSON overhead. Keep provider request limit distinct from upload limit.
- For multi-page PDF, render/process each page with stable order and page manifest; checkpoint validated page extraction. Retry only missing/failed pages, merge deterministically using existing schema and page provenance. Never mark incomplete extraction as fully verified. Review visible page coverage. Reject corrupt/oversized documents cleanly. No arbitrary cropping that removes header, footer or eye signs. Optional high-resolution region re-extraction requires explicit measured need, budget and provenance; no repeated model calls just for cosmetic formatting.
- For audio, cap duration and transcoder time and preserve transcript review. A successful transcript is not proof of intended action.

## 4. Durable idempotency and reconciliation (R16)

Use existing media and extraction idempotency plus a processing-task-level unique key, not just UI disabling Send. Key binds authenticated actor/company/branch/FY, thread, client request ID and canonical input hash. Persist instruction/action/resolution/revision and original file hash; reuse only when same semantic operation. Changed instruction/force extraction is a new explicit revision. Persist per-stage checkpoints keyed by task/version/page. Unique provider attempt record per task/page/attempt; CAS lease claim and fencing. Unique task-to-existing proposal association with stable preparation idempotency key. Never hold DB transaction over provider call. Separate local duplicate prevention from remote exactly-once guarantee (not established). Retry safe local operations; uncertain external attempt goes to reconciliation. Check existing result after crash before issuing another request. Old browser responses cannot restore obsolete approval. `needs_input` edits and retry reuse stable workflow line IDs and existing requirements; no repeated extraction for mere price/warehouse edit.

Credit ledger: reuse `ApprovedModelGateway`, existing `CreditMeter`, token reservations, invocation records and existing credit recovery services. Inspect `admitPerceptionDispatch` reservation/settlement/failure path; retries currently occur within callback, so verify attempt-level accounting rather than assume each HTTP retry independently reserved. Extend it if necessary with durable attempt ID and actual provider usage. Ensure each actually billed provider attempt settled once, unused reservation released once under policy, failed/unknown usage handled explicitly. Do not promise all failures free or blindly refund when provider may have billed. Record unknown status, reconcile from provider usage where available or approved policy/manual review, release/settle only after documented reconciliation. No invented token counts or credits. Process death can skip PHP finally/catch; scheduled stale-reservation reconciliation must distinguish live lease, durable success, unsent and uncertain provider attempts. Prevent credit overspend under concurrent requests and double refund; use existing ledger unique IDs and atomic locks/CAS. Upload quotas similarly deduped. Log only sanitized attempt/credit identifiers, not PII or credentials. Expose actual reserved/charged/refunded/uncertain status in approved usage view; ordinary status poll consumes zero credits.

## 5. Required measurements and acceptance

- 5.6 MB representative PNG and actual sample if authorized, compare readable JPEG/PNG derivative, measure every phase. Provider latency/attempt counts observable. No web 500 even if provider response held >120 s. Don't claim speed improvement without measurement.
- Provider timeouts, 429, 401, 5xx, TLS, malformed JSON, partial PDF, cancellation and deadline tests; max attempts verified across queue retries and restart, no immediate retry storm.
- 20 duplicate submissions and queue redeliveries across independent MySQL sessions: one task, no duplicate proposal or extra extraction absent explicitly scheduled attempt.
- Worker kill before send, after send, after response, after extraction commit, after proposal commit and before credit settlement; no stale result writes, safe recovery and audit. Use genuine worker/process tests on supported platform, not sequential mocks alone.
- Credit tests: before/after ledger, exhausted balance, duplicate settlement, retry, timeout, ambiguous outcome and stale reservation; no extra debit on reuse/poll, no incorrect refund or bypass. Exact provider RPM enforcement on concurrent tasks. No model invocation for tax, arithmetic, lookup, deterministic normalization or review edits.
