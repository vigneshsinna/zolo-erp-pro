# 21 — Feature flags, rollout, model costs and operating policy

## Feature policy

Global `AI_ENABLED=false` and company `ai_feature_settings.enabled=false` by default. Separate flags: `AI_READ_ONLY`, `AI_STREAMING_ENABLED`, `AI_MCP_ENABLED`, `AI_ACTION_PROPOSALS_ENABLED`, `AI_RAG_ENABLED`, `AI_TRACE_ENABLED`. Disabled means both UI and server tools deny; not merely hiding a menu item.

## Rollout cohorts

1. **Local dev:** Synthetic data, mocked SDK, no real provider writes.
2. **Staging:** Opt-in cloud provider with explicit cost budget and synthetic or approved masked company dataset.
3. **Internal pilot:** One named company, one branch, nominated report readers, read-only tools.
4. **Controlled customers:** Gradually enable by company and feature capability after privacy and reconciliation sign-off.
5. **General availability:** Only after monitoring, support, failover, security and cost controls demonstrate stability.

## Commercial and operational metrics

- User adoption: active permitted users, repeat usage and helpful answers per session.
- Accuracy: financial exact-match, stock correctness, source-validity and reconciliation warnings.
- Reliability: p50/p95 response, tool denial/failure rate, queue delay, provider 429 and timeouts.
- Cost: spend by company/agent/tool/model, input/output tokens, abandoned work, effective cost per answered business question.
- Security: denied cross-company requests, prompt-injection blocks, PII redaction failures, unapproved action attempts.

## Cost guardrails

Define per-provider and per-company: daily/monthly budget, per-request hard token cap, max history tokens, max tool calls, concurrency and alert thresholds. Do not put unbounded ledger rows in the prompt. Use cheaper approved models for simple intent routing only after accuracy evaluation. Cache source **metadata** carefully; invalidate business data in relation to transactions and permissions (avoid caching cross-company reports).

## Incident runbook

**Leak suspected:** immediately disable AI and MCP flags, stop AI workers, revoke affected provider/MCP tokens, preserve redacted audit IDs, isolate logs, assess access, follow incident policy and regulatory obligations. **Wrong financial number:** disable affected tool, compare result to authoritative ledger, report correction, add golden fixture and fix service/tool boundary. **Provider outage:** show safe outage; no false zeros, no automatic payment attempts. **Queue runaway:** stop AI queues only; core ERP queues continue.

## Retention and privacy

Retention policy must be contract/jurisdiction reviewed. Default to minimal audit metadata; optional conversations only with user/company ownership checks; encrypted proposal payloads; document deletion/revocation cascades to vector stores; customer data export/delete workflows separated from GL records that must be retained for statutory purposes. Do not silently send sensitive customer records to LLM provider.

## Definition of operational readiness

On-call owner assigned; model/provider and feature settings documented; cost alert tested; safe kill switch; provider outage drill; tenant privacy drill; Redis/Horizon worker health alerts; data-subject export/delete path documented; manual approval boundaries understood by support and finance users.
