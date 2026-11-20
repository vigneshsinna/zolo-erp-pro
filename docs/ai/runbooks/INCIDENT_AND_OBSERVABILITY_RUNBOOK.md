# AI Incident, Observability and Credit Consistency Runbook

> **Specification Standard:** ZoloERP Pro AI Observability & Performance Framework (Task T07, OPS-01 / OPS-02 / OPS-03)  
> **Target Components:** `RequestCorrelation`, `TelemetryDispatcher`, `AiCreditReconciliationService`, `ApprovedModelGateway`  
> **Status:** Operational & Verified

---

## 1. End-to-End Correlation ID Tracing

Every AI request crossing the ERP edge or API gateway is assigned a unique, immutable Correlation ID (`X-Request-ID` or `X-Correlation-ID`).

### 1.1 Trace Propagation Lifecycle

```text
Browser / Client Request (X-Correlation-ID: uuid)
  │
  ▼
RequestCorrelation Middleware (sets attributes 'erp.correlation_id' and 'erp.request_id')
  │
  ▼
AiPrincipal (actorId, companyId, branchId, requestId = correlationId)
  │
  ├──► Workflow Task (ai_workflow_tasks.id)
  │
  ├──► Preflight Credit Reservation (idempotency_key = "res_step_{correlationId}_{step}_{attemptId}")
  │
  ├──► Model Invocation (ai_model_invocations.provider_attempt_id)
  │
  ├──► ErpToolExecutor (ai_tool_invocations.run_id)
  │
  ├──► Action Proposal (ai_action_proposals.id, signed proposal hash)
  │
  └──► Credit Settlement & Ledger (ai_credit_ledger.reference_id = attemptId)
```

### 1.2 Support Incident Investigation Protocol
When an end user reports an issue (e.g. clarification failure, proposal timeout, or credit deduction query):
1. **Retrieve Support Reference ID:** Look up the `correlation_id` returned in the response header or user-facing error card.
2. **Search Invocations Without PII:**
   ```sql
   SELECT id, provider, model, status, duration_ms, credits_debited, http_attempts, error_code, created_at
   FROM ai_model_invocations
   WHERE provider_attempt_id LIKE '%<correlation_id>%' OR reservation_id IN (
       SELECT id FROM ai_credit_reservations WHERE idempotency_key LIKE '%<correlation_id>%'
   );
   ```
3. Support engineers investigate latency, retries, and error codes **without ever viewing raw invoice images, extracted line items, or customer PII**.

---

## 2. Structured Operational Metrics & Telemetry

The `TelemetryDispatcher` records structured operational metrics under the log channel `ai.metrics`.

### 2.1 Metric Events Catalog

| Event Name | Trigger | Key Metric Attributes |
|---|---|---|
| `ai.latency` | Completed reasoning step or perception extraction | `operation`, `duration_ms`, `provider`, `model` |
| `ai.rate_limit` | Provider returned HTTP 429 or rate limiter triggered | `provider`, `status: throttled`, `error_code: RATE_LIMIT_EXCEEDED` |
| `ai.candidate_ambiguity` | Warehouse or supplier query returned multiple candidates | `field`, `candidate_count`, `status: ambiguous` |
| `ai.proposal.approved` | User approved a draft action proposal | `action_key`, `proposal_id`, `status: approved` |
| `ai.proposal.executed` | Approved action executed by authoritative domain service | `action_key`, `proposal_id`, `status: executed` |
| `ai.idempotent_recovery` | Duplicate request safely recovered from existing record | `scope`, `idempotency_key`, `status: recovered` |

### 2.2 Privacy & Pseudonymization
- All metrics sanitize tenant and user references:
  `company_hash = substr(hash_hmac('sha256', "company:{$companyId}", $salt), 0, 16)`
  `actor_hash = substr(hash_hmac('sha256', "actor:{$actorId}", $salt), 0, 16)`
- External export to Langfuse (if enabled via `AI_LANGFUSE_ENABLED=true`) exports strictly technical metadata (`ModelTelemetry`), never raw prompts or system instructions.

---

## 3. Credit Meter Consistency & Reconciliation

### 3.1 Three-Way Audit Protocol
`AiCreditReconciliationService` guarantees financial accounting integrity across three independent records:
1. **Aggregate Period Counters:** `ai_credit_periods.spent_credits`
2. **Immutable Credit Ledger:** Sum of `ai_credit_ledger.amount_credits` where `kind = 'usage_debit'`
3. **Provider Attempt Records:** Sum of `credits_debited` in `ai_model_invocations`

### 3.2 Scheduled Sweeps & Reconciliation Artisan Commands
- **Sweep Expired Holds:**
  ```bash
  php artisan ai:reconcile-credits --sweep-expired
  ```
  Releases stranded reservations where the model request timed out or was cancelled before provider dispatch.
- **Audit Account / Period Discrepancies:**
  ```bash
  php artisan ai:reconcile-credits --account=<account_id>
  ```
  Returns `is_reconciled = true` if `period_spent === ledger_spent` with zero discrepancies.

---

## 4. Alert Thresholds & Operational SLOs

| Alert Indicator | Threshold | Severity | Immediate Remediation |
|---|---|---|---|
| **High Latency (P95)** | Sustained > 5,000ms over 5 minutes | Warning | Check provider API status (NVIDIA / OpenAI); verify network egress. |
| **Provider Rate Limits (429)** | > 3% of dispatches within 10-minute window | High | Shift non-urgent multimodal extraction to batch queue; adjust provider RPM limits in `ApprovedModelGateway`. |
| **Credit Ledger Discrepancy** | Any discrepancy detected by `reconcilePeriod` | Critical | Freeze further credit reservations for company; run `reconcileAccount` to audit. |
| **High Candidate Ambiguity** | Ambiguity rate > 25% on warehouse resolutions | Info | Notify tenant admin to configure default warehouses per branch or resolve duplicate names. |
| **Stale Proposal Replays** | Multiple 409 Conflict responses on same proposal | Warning | Check UI client event listeners for duplicate click submission. |

---

## 5. Emergency Circuit Breakers

If unexpected provider degradation or security anomalies occur:
1. **Company-Level AI Suspension:**
   ```sql
   UPDATE ai_feature_settings SET is_active = 0 WHERE company_id = <company_id>;
   ```
2. **Global Foundation Killswitch:**
   Set `AI_FOUNDATION_ENABLED=false` in `.env` or configuration. All `/api/v1/ai/*` endpoints instantly reject requests with `403 AI action foundation is disabled.`
3. **Execution-Only Killswitch:**
   Set `AI_ACTION_EXECUTION_ENABLED=false` to permit AI reading, clarification, and draft proposal review while preventing any financial writes or ledger bookings.
