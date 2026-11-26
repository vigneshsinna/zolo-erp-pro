# 06 — Observability, Credit Metering, Rate Limits and Performance

## 1. What is already in the code

- `app/Ai/Gateway/ApprovedModelGateway.php`: approved provider/model allowlists, perception-vs-reasoning separation, rate admission, token/credit reservation.
- `app/Ai/Billing/Services/`: `AiCreditMeter`, reservation, settlement, reconciliation, entitlement, monthly grants, top-ups and price calculator.
- `app/Ai/Services/AiAuditRecorder.php`: redacted/limited read-tool audit records.
- `app/Ai/Telemetry/`: `TelemetryDispatcher`, `ModelTelemetry`, `LangfuseTelemetrySink`; external export behind configuration.
- `app/Ai/Jobs/ExportModelTelemetry.php` and `app/Ai/Jobs/ExecuteApprovedAction.php`; optional Redis queue.
- `config/ai_infrastructure.php` defines `AI_LANGFUSE_ENABLED`, `AI_ASYNC_EXECUTION_ENABLED`, queue settings and other flags.
- Credit migrations (`2026_11_16...`) and `.github/workflows/ai-credit-evaluation.yml` exist.

**Do not claim central credit metering is production accurate until reconciling real provider usage and concurrency.** Similarly, Langfuse is *optional* rather than verified deployed.

## 2. Work package OPS-01 — End-to-end correlation and audit

A single user task should be traceable across:

```text
browser_task_id
  → HTTP request_id / trace_id
  → media_id + extraction_revision
  → model_invocation_id / provider_attempt_id
  → workflow_task_id + task_revision
  → action_key + proposal_id + version + payload_hash
  → approval_id + actor_id
  → execution_id + idempotency_key
  → ERP resource ID + receipt
  → credit reservation / settlement entries
```

### Tasks

1. Create a common, non-sensitive `trace_id`/`request_id` context in `RequestCorrelation` middleware; propagate through queue workers and nested tool calls.
2. Log state transitions (`received`, `prepared`, `needs_input`, `approved`, `completed`, `failed`) as structured events with actor/company *hashed or scoped according to destination*.
3. Establish one audit record for each authorization decision and separate records for preview, approval and execution. Audit records must not contain raw invoice bodies, prompts, tokens or secrets.
4. Add a user-visible support reference ID on failed tasks; admins can use it to find related logs without exposing tenant data.
5. Ensure metrics do not confuse HTTP attempts, model invocations, SDK steps, tools and credit ledger transactions.
6. Integrate external telemetry only with a reviewed allowlist and verified configuration; don't ship raw model prompts to Langfuse by default.

### Suggested structured event contract (new, illustrative)

```json
{
  "trace_id": "trace_...",
  "event": "ai.proposal.approved",
  "task_id": "uuid",
  "proposal_id": "uuid",
  "proposal_version": 3,
  "action_key": "purchase_bill.create.v1",
  "principal_hash": "sha256:...",
  "company_hash": "sha256:...",
  "duration_ms": 84,
  "status": "approved",
  "error_code": null,
  "raw_input": null
}
```

## 3. Work package OPS-02 — Credit-meter correctness

### Keep existing services; prove these invariants

1. Every model dispatch reserves estimated credits **before** sending a billable provider request, respecting company allowance and top-ups.
2. Successful dispatch settles **actual** usage exactly once by unique provider attempt ID.
3. Failures, timeouts and unknown provider outcomes do not silently drop charges or double-charge. Use explicit unknown/pending reconciliation state where appropriate.
4. A request declined locally for insufficient credits does **not** dispatch to the provider.
5. Concurrent calls from 5/10/20 users cannot overspend a common company balance; DB row/locking correctness and retry/reconciliation are tested.
6. Monthly grant and top-up credits preserve company ownership and accurate financial/event timing; idempotent webhooks.
7. Credit unit prices and model rates are versioned/recorded so historical costs can be explained after pricing changes.
8. All attempts (text, image, PDF, audio, retries, summarization, embeddings when billable) obey the same metering policy, with explicit exclusions if applicable.

### Review files

`AiCreditReservationService.php`, `AiCreditSettlementService.php`, `AiCreditReconciliationService.php`, `MonthlyCreditGrantService.php`, `ModelCostCalculator.php`, `AiTopupService.php`, `AiCreditController.php`, `ApprovedModelGateway.php`, `config/ai_credits.php`.

## 4. Work package OPS-03 — SLOs and dashboards

Choose and approve numerical targets after a staging performance baseline. Proposed metrics:

| Metric | How to measure | Alert concept |
|---|---|---|
| Chat dispatch latency p50/p95/p99 | Server telemetry by model/provider | Sustained p95 regression vs baseline |
| Invoice extraction success | Valid extracted schema / attempted extraction | Sudden error spike |
| End-to-end bill preview latency | Send → ready/needs_input UI | Sustained regression |
| Clarification completion | Blocked → successfully resolved | Repeated failure or abandonment spike |
| Model rate-limit events | 429, provider dispatch delays/retries | Rate saturation |
| Credit consistency | Ledger vs reservations vs provider charges | Any unexplained balance drift |
| Approval and execution errors | 409, 403, stale snapshot, executor fail | Unexpected increase |
| Duplicate-prevention events | Idempotency recovery count | Spike may indicate client retry bugs |
| Queue delay / retries | Job queued → started; failed jobs | Backlog above approved threshold |
| MCP/RAG availability | Allowed call success/error by server | Optional feature degradation |

Create an operations-only dashboard (role-restricted) for traces, errors, credits, model selection, p95 latency and per-provider attempts. **Do not** expose unrestricted invoice/tenant data in global dashboards.

## 5. Work package OPS-04 — Practical performance improvements

- Avoid duplicate model calls when the input/asset/extraction revision has not changed. Persist idempotency keys on server, not only browser.
- Use a single media `prepare` path for extraction + validated proposal, reusing media IDs on retry.
- Implement browser loading states with request cancellation for entity directory typing; debounce search (~250–350ms), server cap/pagination, cache only safe scoped results briefly. Invalidate on company/branch/FY switch.
- Do not re-run Omni simply to change a warehouse ID; re-run deterministic entity resolution and proposal preview instead.
- Use database indexes on company, branch, state, reservation/ledger unique attempt IDs, proposal status/version and lookup keys after verifying current migrations and query plans.
- Dispatch long-running approved actions through existing async infrastructure **only if** reliable queue workers are configured; short execution can remain synchronous with timeouts.
- Keep streaming/partial progress user-friendly but do not claim success until the server returns a receipt.

## 6. Benchmark plan

| Load | Scenarios | Capture |
|---|---|---|
| 1 user | Baseline voice/image upload, simple bill | Model calls, tokens, credits, latency |
| 5 users | Simultaneous bill preparation | p95, errors, duplicated tasks |
| 10 users | Mixed chat + uploads + entity lookups | Provider throttles, DB query load |
| 20 users | Burst across multiple companies | Tenant isolation, credit overspend, queue lag |
| 20 users (one company) | Shared credit balance contention | Atomic reservations, no negative allowance |

Use synthetic invoices and isolated test companies. Collect separate baselines for mocked providers and paid live provider tests; record rate limits and configuration. Never conclude production capacity from mocks alone.

## 7. Recoverability and incident response

- Provider failure: keep attachment and server task ID; retry existing media, never reupload implicitly.
- Credit settlement uncertain: reconcile provider attempt asynchronously and surface pending cost status to support.
- Queue job failure: retry with same idempotency key; if previously completed, return existing receipt.
- High error rate: disable AI execution feature flag before touching normal ERP transaction code.
- Telemetry export failure: business execution remains unaffected; queue/export retry with bounded retention.

## 8. Definition of done

- Every model call and governed ERP action can be traced with correlation identifiers.
- Credit ledger remains nonnegative under concurrency and reconciles against accepted model usage.
- Real-time dashboards show model/provider, token/credit counts, errors and latency without raw PII.
- Production alarms and human ownership are documented and exercised in staging.
- Load results compare mocked vs live provider explicitly, with evidence artifact links in release PR.
