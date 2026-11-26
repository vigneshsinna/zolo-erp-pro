# 22 — Architecture Decision Log and Open Business Questions

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

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
| ADR-009 | First commercial action vertical slice: Purchase Order, following admin-limited initial setup | Demonstrates low-risk commercial workflow after the Setup Agent proves authorised create/preview/receipt |
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


---

## V3 accepted design decisions
| ID | Decision | Rationale |
|---|---|---|
| ADR-011 | Dedicated AI Setup Agent, before business actions | Faster time-to-first-transaction for a new company without building duplicate ERP setup rules |
| ADR-012 | Separate BootstrapSetupPrincipal from company-scoped AiPrincipal | Creating the first company happens before any company/branch/FY exists; require installation/group-admin permission |
| ADR-013 | Existing `CompanyDirectoryService::createCompany` remains creation authority | It already atomically provisions company, MAIN branch, FY, warehouse, COA, industry and starter masters |
| ADR-014 | 'Business vertical' maps to `IndustryCatalog` profile/subtype + company capabilities, unless a separate real domain is confirmed | No new global vertical master should be invented without code/business evidence |
| ADR-015 | Document lifecycle state restricts every AI edit | Submitted/posted records cannot be generically updated; use only supported amendment/cancellation/reversal/return adjustments |
| ADR-016 | Approval binds exact draft revision and authoritative record state | Revisions and changed status invalidate approval and demand fresh review |
| ADR-017 | No direct use of legacy unscoped CRUD controllers as privileged AI command adapters | UOM/warehouse/customer/supplier flows require reviewed company-scoped services and explicit permission, not `request()->all()` |

## V3 open setup decisions to validate with product/finance
- Meaning of company group versus financial consolidation, allowable group-admin actions, ownership when creating ungrouped companies.
- Required legal company fields by country, registration/GST dates, profile selection and whether multi-industry configuration is supported (current code has one industry profile/subtype per company).
- Whether initial UOM and product/party imports may be saved as drafts without posting, and what admin approval threshold applies.
- How to handle existing companies with posted documents when changing base currency, FY dates, state/GSTIN, industry subtype or warehouse status.
- Which master lookups, product starter templates and company readiness conditions are mandatory for each industry.
- How to support optional opening balances without injecting stock or ledger data outside reviewed opening-balance services.
