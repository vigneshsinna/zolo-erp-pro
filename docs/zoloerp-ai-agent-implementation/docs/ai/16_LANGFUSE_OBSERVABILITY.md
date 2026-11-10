# 16 — Langfuse, evaluation traces and usage observability (optional PH2)

## Project

https://github.com/langfuse/langfuse and https://langfuse.com/docs. Integration goal: monitor latency, tool calls, model requests, token/cost usage, user feedback and test quality **without sending unredacted ERP business information to telemetry**.

## Adapter design

Implement `AiTelemetrySink` interface with `NullTelemetrySink` (default), `DatabaseUsageSink` (local minimal metrics) and an optional `LangfuseTelemetrySink`. Integrate with Laravel AI SDK events (`AgentPrompted`, `ToolInvoked`, `AgentFailed`, etc. as supported by the installed version) or a vetted OpenTelemetry pipeline. Do **not** assume an unofficial PHP Langfuse SDK package name; verify actual current integrations and endpoints first.

## Trace fields allowed by default

`trace_id`, `request_id`, `company_hash`, `actor_hash`, `agent_key`, `prompt_version`, provider/model identifier, response status, token counts, estimated cost, duration, tool names, authorisation outcome, error category, reconciliation flag, feedback score. **No** plain customer names, raw prompts, full invoices, bank details, account balances, passwords, login tokens, secret keys or unredacted tool outputs.

## Redaction policy

- Default `AI_TRACE_ENABLED=false` until security/privacy sign-off.
- Store in-app minimal audit data even with external tracing disabled.
- Never log full prompts/responses by default; use controlled sampling and bounded redacted retention for debugging only.
- Separate production, staging and test traces; keep test fixtures synthetic.
- Record model providers' usage and costs in `ai_usage_counters` irrespective of Langfuse availability.
- If telemetry endpoint times out, drop or queue telemetry, **never block business user workflows indefinitely**.

## Evaluation datasets

Version gold-standard datasets for receivables totals, overdue dates, inventory availability, company/branch scope denials, source citation validity and prompt-injection attacks. Compare answer correctness **against deterministic ERP service outputs**, not an LLM judging itself as the sole signal.

## Operational charts

- Success/failure/denial by tool and company; response p50/p95, provider rate limits, total tokens and costs.
- Wrong/fabricated number reports, missing citations and overdue/stock reconciliation warnings.
- Queued run age, cancellations, model/provider drift and error budgets.
- Privacy/PII scanner violations and unexpected sensitive text in logs.

## Rollout gate

1. Confirm endpoint region, storage, tenant boundaries and provider policy.
2. Evaluate self-hosted infrastructure requirements and secret management; avoid co-hosting heavy Langfuse stack on a constrained shared ERP VPS.
3. Capture a synthetic trace only; inspect actual emitted payload to prove redaction.
4. Add deletion/retention, incident runbook and on-call alert before enabling in pilot.
