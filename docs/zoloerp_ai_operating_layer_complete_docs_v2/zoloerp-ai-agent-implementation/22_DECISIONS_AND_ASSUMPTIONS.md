# 22 — Architecture Decision Log and Open Business Questions

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Accepted architecture decisions
| ID | Decision | Evidence or reason |
|---|---|---|
| ADR-001 | Laravel AI SDK remains model/agent integration | Current branch already uses it |
| ADR-002 | Explicit `AiToolManifest` + `GenericSdkTool` for reads | Implemented shared adapter; no reflection-discovered arbitrary methods |
| ADR-003 | Authenticated `AiPrincipal` and domain authorization on every tool | Current principal/factory/executor architecture |
| ADR-004 | ERP service owns calculation and posting, not LLM | Financial/stock correctness and existing business transactions |
| ADR-005 | Current Copilot stays read-only until CI/sign-off | Prevent legacy proposal path and accounting bypass |
| ADR-006 | One Command Center + shared capabilities; specialist agents optional | Avoid duplicated services and agent dependency coupling |
| ADR-007 | Action registry is separate from read registry | Distinct risk classification and explicit approved command boundary |
| ADR-008 | Explicit approval binds sealed command and version | Prevent model self-approval and stale data edits |
| ADR-009 | First action vertical slice: Purchase Order, then receipt/bill | Demonstrates user value with less financial/stock risk than direct bill posting |
| ADR-010 | MCP/Qdrant/Langfuse/Neuron optional/deferred | Not needed for core in-app actions; reduces VPS and security burden |

## Outstanding decisions (must be resolved, not guessed)
| Question | Owner to consult | Gate |
|---|---|---|
| Is the first PO a quotation/request, draft or approved order in ERP's true lifecycle? | Product + purchasing domain owner | Before PO action schema |
| When does a receipt update stock and liability in each company configuration? | Inventory + finance | Before purchase receipt/bill workflow |
| How do purchase bills link to receipts and avoid double stock? | Purchasing + finance | Before bill posting |
| Which sales document statuses generate revenue/COGS/stock? | Sales + accounting | Before invoice posting |
| Exact GST place-of-supply, tax-inclusive and party eligibility rules | Tax owner | Before monetary preview/transaction |
| Currency rate policy and rounding | Finance | Before foreign-currency writes |
| Who can create versus approve high-risk documents? Segregation of duties? | Company administrator + finance | Before approval enablement |
| Customer/supplier duplicate and fuzzy match thresholds | Data operations/product | Before master create capability |
| Reversal/cancellation rules and operational recovery | Finance + engineering | Before any financial action |
| Provider data processing/retention and customer privacy notice | Security/legal | Before live prompts/uploads |
| Synchronous vs queued workflow limits, VPS resources | Operations | Before long-running tasks |
| Actual scheduled action autonomy allowed for each tenant | Product + admin | Before recurring writes/sends |

## Change-log rule
When an ADR changes, record date, author, old/new position, impacted modules, migrations, tests, feature flag and rollback. Decisions must be confirmed from current code/business owners; this pack does not fill missing business rules with guesses.
