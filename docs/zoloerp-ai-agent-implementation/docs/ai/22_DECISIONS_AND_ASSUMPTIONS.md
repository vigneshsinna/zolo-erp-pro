# 22 — Architecture decisions and open assumptions

Each item must become an ADR or be resolved in the first implementation PR. **Source-derived** refers to inspected GitHub `main`; **proposed** refers to this plan; **needs verification** must not be represented as shipped.

| ID | Status | Decision / assumption | Evidence / resolution required |
|---|---|---|---|
| ADR-001 | Adopt proposed | `laravel/ai` core SDK, no custom LLM HTTP stack | Confirm Composer/PHP compatibility and vendor API after install |
| ADR-002 | Adopt proposed | zoloERP-owned `ErpToolExecutor` shared by SDK + MCP | Tests prove no bypass; no arbitrary SQL |
| ADR-003 | Source-derived | Existing app uses Laravel 13/PHP 8.5 + Sanctum and `CompanyContextResolver` | Repository `composer.json` and service classes |
| ADR-004 | Pending | SDK conversation history vs stateless/bounded history | Confirm vendor table ID types, privacy and ownership mapping |
| ADR-005 | Adopt proposed | Read-only receivables agent first | Compare exact AR ageing and control account reconciliation |
| ADR-006 | Pending | Effective tool RBAC mapping to current permissions/Spatie/company override | Inspect actual role-policy enforcement across finance/stock APIs |
| ADR-007 | Source-derived gap | `FinancialReportService` uses HTTP `auth()->id()` in core reports | Introduce actor-explicit read facade/refactor with regression |
| ADR-008 | Source-derived gap | `InventoryAvailabilityService` lacks explicit `CompanyContext` parameter | Refactor/wrap before inventory tool exposure |
| ADR-009 | Proposed | `AI_ENABLED=false` default, company flags and read-only mode | Implement config and kill-switch tests |
| ADR-010 | Needs verification | Redis and Horizon operational on target VPS | Check Redis process, ACLs, queue configs and worker capacity |
| ADR-011 | Adopt proposed | No direct model writes; approval proposal state machine | PH2 separate security/finance review |
| ADR-012 | Proposed | Laravel MCP added only when server authentication and executor parity is tested | Confirm package route and token setup |
| ADR-013 | Proposed | Managed model provider first; Ollama later only if metrics justify | Provider DPA, data residency, model choice, pricing |
| ADR-014 | Proposed | Langfuse external/optional and redacted | Legal and telemetry payload inspection |
| ADR-015 | Proposed | Qdrant only for approved knowledge | ACL filters + source revocation test |
| ADR-016 | Proposed | Neuron AI evaluated in isolated PoC, not mixed by default | Compare real workflow benefit/complexity |
| ADR-017 | Pending | Whether branch-limited users may access full-company dashboards | Derive from existing ERP business policy, not model defaults |
| ADR-018 | Pending | Exact ERP route links and data presentation schemas | Inspect current named routes and stable report API output |
| ADR-019 | Pending | Whether current live PHP 8.5 deployment and queue are ready | Operational check; repository code cannot confirm server state |
| ADR-020 | Adopt proposed | Preserve existing ERP deploy gate; no auto-launch of AI | Release manager to approve staging/production rollout |

## Decisions to capture before PH1 deployment

- Which model provider/model, primary region and retention policy are contractually approved?
- Which user roles have `ai.use` and AR report read rights in **each company**?
- Are branch-specific or company-wide aggregates legally/product-correct for each role?
- What is the retention period for conversations, traces, encrypted proposal drafts and knowledge sources?
- What are hard cost limits, query timeouts and provider outage behaviours?
- Are customers informed that prompts/business data are processed by a third-party model?
- What is the approved use of document ingestion, invoices and PII?
- Who is the deployment approver, AI on-call owner and incident escalation contact?

## Change-log protocol

When a decision changes, record date, owner, superseded design, reason, affected documents, migration risk and proof/test evidence. Never silently reinterpret a source-derived finding into a new implementation claim.
