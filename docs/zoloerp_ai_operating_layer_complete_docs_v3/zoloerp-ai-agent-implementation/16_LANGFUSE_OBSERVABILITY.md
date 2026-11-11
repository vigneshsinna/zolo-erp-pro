# 16 — AI Observability, Telemetry, Costs and Business Audit

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Three independent kinds of telemetry
1. **Product:** useful answers/tasks completed, clarification rate, review edits, task abandonment, user satisfaction.
2. **Model:** provider, model, HTTP dispatch attempts, retries, model steps, tokens, duration, failure type and cost.
3. **Business execution:** validated capabilities, policy decisions, approval identity, proposal hash, actual ERP command and immutable receipt. Business audit remains in zoloERP even when external telemetry is down.

## Existing baseline
`ApprovedModelGateway` includes provider allowlist, RPM limiting, token reservations and `ai_model_invocations` telemetry. Current UI has telemetry badges. Preserve distinction between user requests, provider HTTP requests, model steps and tool calls; do not claim one chat always equals one API call. Model pricing is an estimate if provider doesn't report exact billing metadata.

## Optional Langfuse
Use a `TelemetrySink` interface and redacted adapter after privacy review. Trace IDs can include pseudonymous tenant/actor hashes, agent/tool key, token counts, latency, error and reconciliation. By default **exclude** raw customer names, invoices, phone/email/GSTIN, secret keys, tool payloads and sensitive chat text. Keep tracing external/dedicated and resilient to outages.

## Metrics and thresholds
| Metric | Why it matters | Initial policy |
|---|---|---|
| Tool selection success on supported prompts | Intent quality | Baseline target ≥95% in reviewed test set |
| Authoritative number match | Financial integrity | 100% on approved golden fixtures |
| Unauthorized data/tool leaks | Tenant safety | Zero |
| Duplicate postings on retries | Transaction integrity | Zero |
| Clarification completion | Data-entry usability | Measure and improve without guessing missing required fields |
| Preview edit rate | Extraction/entity match quality | Segment by module and document type |
| p50/p95 latency & token cost | Customer responsiveness | Establish measured service budget before GA |
| Workflow stuck/expired ratio | Reliability | Alert and manual recovery path |

## Evaluation and audit strategy
Every versioned capability has golden contract tests and source references. Sample anonymous intent and failure taxonomy; no full prompt logging without controlled debugging policy. Signed audit decisions record who authorized, exactly what changed, which business command was called, and the ERP receipt. Expose support-safe trace IDs in UI.

## Incident response
If wrong financial facts are served, disable affected read capability, reconcile against source ledger and add fixture. If unexpected transaction occurs, disable action command path, preserve receipts and review idempotency. If data leak suspected, disable AI, contain credentials and follow incident process. Never erase business audit merely to reset a failed AI run.


---
