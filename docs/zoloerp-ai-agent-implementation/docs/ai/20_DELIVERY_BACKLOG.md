# 20 — Complete implementation backlog and acceptance criteria

## Priority legend

**P0** MVP foundation (read-only); **P1** broaden authoritative insights; **P2** governed actions/integrations; **P3** optional advanced agent stack. Stories are sized in relative points, not calendar commitments. Each story is done only after code review, tests, documentation and security gates.

## PH0 — Foundation

| Story | Pts | Dependencies | Acceptance criteria |
|---|---:|---|---|
| AI-001 Current-baseline audit | 3 | None | Exact `main` SHA, domain service contracts, PHP/Composer and queue state recorded |
| AI-002 Install Laravel AI SDK | 5 | AI-001 | Composer lock audit clean; SDK config/migrations reviewed; no ERP regression |
| AI-003 `AiPrincipal` and context guard | 8 | AI-001 | Server-derived user/company/branch/FY; async revalidation; cross-tenant denials |
| AI-004 Model gateway + provider config | 5 | AI-002 | Keys server-side; provider/model allowlist; budgets/timeouts; fail-safe errors |
| AI-005 Tool manifest / `ErpToolExecutor` | 8 | AI-003 | Typed validators, permission checks, audit, limited data, no generic SQL |
| AI-006 AI schema and ownership | 5 | AI-002, AI-003 | SDK history ownership mapping, run/tool/usage schema and retention tests |
| AI-007 Logging/privacy policy | 5 | AI-004 | Redacted logs, correlation IDs, no PII or secrets |
| AI-008 Feature flag / company enablement | 3 | AI-003 | `AI_ENABLED=false` default, emergency kill switch, permission gating |

## PH1 — Read-only copilot MVP

| Story | Pts | Dependencies | Acceptance criteria |
|---|---:|---|---|
| AI-010 Receivables read facade | 8 | AI-003 | Exact open-item/control reconciliation, actor-explicit auth, branch/FY scope |
| AI-011 Customer outstanding SDK tool | 5 | AI-005, AI-010 | Tool schema limits, source ref, no model-selected ownership fields |
| AI-012 `ErpCopilot` agent | 5 | AI-004, AI-011 | Only read tool, no invented numbers, safe provider failure |
| AI-013 Chat endpoints | 8 | AI-006, AI-012 | Auth, CSRF, throttle, thread ownership and run states |
| AI-014 Blade AI workspace | 8 | AI-013 | Responsive, accessible, source-linked facts, no stored XSS |
| AI-015 Cross-tenant and adversarial test pack | 8 | AI-010..014 | 0 cross-company leaks and 0 unauthorized tools |
| AI-016 Golden-set financial accuracy | 5 | AI-010..014 | Figures match deterministic ageing; reconciliation warnings displayed |
| AI-017 Staging pilot acceptance | 5 | AI-015..016 | Named test company, screenshots, logs, costs, rollback exercised |

## PH1+ — Extend read-only tools

| Story | Pts | Acceptance criteria |
|---|---:|---|
| AI-020 Sales summary and top products | 8 | Branch-scope and returns validated; source-linked totals |
| AI-021 Inventory availability facade | 8 | Refactor existing service for explicit company/warehouse authorization; projection/ledger variance visible |
| AI-022 Finance reports | 8 | Actor-explicit trial balance/P&L with exact decimals; year scope |
| AI-023 Report cards and charts | 5 | Values from tools only; no LLM-computed chart data |
| AI-024 Async jobs + Horizon | 8 | Redis verified, dedicated queues, retries and no ERP starvation |

## PH2 — MCP and controlled proposals

| Story | Pts | Acceptance criteria |
|---|---:|---|
| AI-030 Laravel MCP server | 8 | Authenticated scoped read-only tools; exact `ErpToolExecutor` parity |
| AI-031 External MCP client allowlist | 5 | SSRF restrictions, per-user token scopes, no unrestricted tool discovery |
| AI-032 Action proposal schema/service | 8 | Immutable typed drafts, risk labels, source snapshot, TTL |
| AI-033 Approval/rejection UI | 5 | Explicit user review, RBAC, no chat-text approvals |
| AI-034 Action executor and idempotency | 13 | Fresh domain validation, concurrency-safe, no duplicate posting |
| AI-035 Langfuse redacted tracing | 5 | Synthetic traces, retention, redaction and opt-in flag |

## PH3 — Knowledge/document workflows

| Story | Pts | Acceptance criteria |
|---|---:|---|
| AI-040 Document upload quarantine + ACL | 8 | Scanner/type/size checks, ownership and secure storage |
| AI-041 Embedding pipeline and Qdrant | 8 | Tenant filter, document ACL recheck, deletion/revocation |
| AI-042 Cited knowledge answers | 8 | Source sections and version; no instruction injection |
| AI-043 Supplier invoice extraction draft | 13 | Field confidence, human edits, validation, no auto-posting |

## PH4 — Evaluate advanced orchestration

| Story | Pts | Acceptance criteria |
|---|---:|---|
| AI-050 Neuron AI workflow PoC | 5 | Bounded same-tool comparison, checkpoint test and cost metrics |
| AI-051 Ollama / self-hosted model PoC | 5 | Model license, hardware capacity, privacy and accuracy parity |
| AI-052 Scheduled briefings | 8 | User opt-in, scoped data, recurrence and delivery caps |

## Release gates

**Gate F**: Framework packages installed, no regression, 0 serious advisories. **Gate S**: 0 cross-company leaks and read tool denies verified. **Gate A**: exact AR/stock/accounting parity on golden fixtures. **Gate O**: provider timeout/queue/cost and kill switch tested. **Gate P**: restricted staging pilot and production approval. Each phase must clear earlier gates before enabling new risk levels.

## Required PR structure

One PR per independent domain step where feasible: foundation, principal/tool executor, receivables service, SDK tool/agent, chat/UI, tests/pilot. Separate Redis/Horizon, MCP and write proposals into later PRs. Include changed files, assumptions, migration review, risk summary, test output and security evidence.
