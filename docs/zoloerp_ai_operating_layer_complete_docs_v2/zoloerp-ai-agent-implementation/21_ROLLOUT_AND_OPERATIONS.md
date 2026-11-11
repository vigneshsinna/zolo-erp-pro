# 21 — Product Rollout, Quotas, Monitoring and Support Runbook

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Rollout cohorts
Development (synthetic) → isolated staging with controlled provider → internal read-only pilot → action-preview pilot → exactly one permitted PO write capability → sales/purchase bill pilots → module-by-module production enablement. Access controlled by company and exact capability, with emergency server-side disable.

## Business value metrics
Time to create PO/invoice compared with manual forms; percentage of complete tasks after one clarification; mismatched supplier/product resolution rate; preview edits per document; duplicate prevention; approval turnaround; successful/failed operations; user satisfaction; cost per verified task; data-entry errors per 100 transactions.

## Technical metrics
User prompts, model steps/HTTP attempts and tool calls; provider 429/errors; p50/p95 time; top tools; workflow queue wait and stuck tasks; per-company token/cost; financial mismatch warnings; branch access denies; duplicate idempotency claims. Store redacted counters; maintain immutable business receipts.

## Customer controls
Company admin enables AI read and individual action capabilities; role admin assigns `ai.use` + necessary business permissions; approvers separately authorised. Users can see task scope, incomplete fields, past approvals, expiry, audits and the source ERP records. Scheduled workflows require opt-in, frequency/time zone, target scope, stop conditions and cancellation.

## Operational failure runbooks
| Incident | Immediate action | Recovery |
|---|---|---|
| Provider 429/outage | Safe error, no synthetic business data; throttle | Retry read with bounds; action receipts remain unchanged |
| Report mismatch | Disable affected read capability | Compare real ERP report/ledger, correct facade and add golden test |
| Stuck awaiting approval | Notify task owner or expire per policy | Revalidate fresh business snapshot, don't auto-approve |
| Duplicate posting suspected | Disable action capability; preserve receipts | Inspect idempotency logs and ERP document IDs; approved reversal only |
| Cross-tenant data suspected | Global/company AI kill switch; revoke model/MCP creds | Security investigation, trace/audit preservation and notification policy |
| Worker crash after commit | Don't repeat blind write | Query receipt using idempotency key; reconcile task status |
| Bad document extraction | Quarantine result | Re-review source, correct fields and replay validation without posting |

## Support readiness
Dedicated owner/on-call, runbook for common provider errors and role gates, restore procedure, business escalation for invoice/tax errors, business-approved correction/reversal policy, privacy/retention notice and cost alerts. Record realistic VPS process capacity before enabling queues.
