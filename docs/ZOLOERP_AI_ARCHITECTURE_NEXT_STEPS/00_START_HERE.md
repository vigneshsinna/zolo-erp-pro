# ZoloERP Pro AI — Architecture Modernisation: Start Here

**Purpose:** Repository-grounded, implementable next steps for making ZoloERP AI Copilot secure, intuitive, observable, testable, and deployable.

**Repository:** `vigneshsinna/zolo-erp-pro`  
**Inspected remote branch:** `feature/ai-unified-input-clarification`  
**Reference inspected commit:** `132cf772bcc5b499c376f73c8f346b53b17f60ba` (17 November 2026)  
**Note:** The exact remote branch `feature/ai-unified-input` was not listed at review time. **Do not assume the inspected SHA is still HEAD.** Check `git ls-remote`/`git rev-parse` before starting and reconcile differences.

> These files are **implementation specifications**, not an assertion that every service is activated or proven in production. No repository changes were made to create this pack. Any statements about deployments, WAF, running queue workers, model-provider connectivity and alerts must be verified in the target environment.

## Outcome

A single ZoloERP Copilot can take text, images, PDFs and voice, prepare a valid ERP action, resolve supplier/product/warehouse references intelligently, collect missing details in one responsive review card, and execute only after appropriate human approval. Its security, model costs, audit trails and release quality are visible to operators.

## Files in this pack — recommended reading order

| File | Focus | Main audience |
|---|---|---|
| `00_START_HERE.md` | Scope, order, ownership, checkpoints | All |
| `01_CURRENT_VS_TARGET_ARCHITECTURE.md` | Verified logical architecture, target diagrams, gaps | Tech lead / architect |
| `02_EDGE_SECURITY_IDENTITY_PRIVACY.md` | WAF/API edge, tenancy, PII, threats, secrets | Infra / security / backend |
| `03_COPILOT_ORCHESTRATION_AND_APPROVAL.md` | Central coordinator, tools, state, proposal, safe execution | Backend / AI engineer |
| `04_INTELLIGENT_ENTITY_LOOKUP_AND_UI.md` | Shared pickers, side panel, creation and workflow resumption | Frontend / backend / UX |
| `05_MCP_DOCUMENT_KNOWLEDGE_AND_STATE.md` | MCP boundaries, optional Qdrant, sessions, untrusted content | Integrations / backend |
| `06_OBSERVABILITY_CREDITS_AND_PERFORMANCE.md` | Logging, correlation, credits, latency, rate limits, alerts | SRE / finance / backend |
| `07_TEST_STRATEGY_AND_EVALUATION.md` | API, browser, security, finance, live and fixture evaluation | QA / CI |
| `08_DEPLOYMENT_ROLLOUT_RUNBOOK.md` | Flags, staged releases, smoke, rollback, recovery | DevOps / release manager |
| `09_CODEX_EXECUTION_TASKS.md` | Ordered, copyable task prompts with file touchpoints | Codex implementer |
| `10_ACCEPTANCE_CRITERIA_AND_EVIDENCE.md` | QA scenarios, traceability and definition of done | PO / QA / reviewers |
| `11_ENTITY_UI_DETAILED_REFERENCE.md` | Existing detailed 659-line entity UI spec, retained for implementation | Frontend / backend |

## Priority sequence

| Priority | Work package | Prerequisite | Release outcome |
|---|---|---|---|
| P0 | Baseline, tests, permissions and feature flags | None | Measurable starting point |
| P0 | Fix the current `slots_patch` versus `corrections` payload mismatch; eliminate misleading warehouse defaults | Baseline | Reliable bill reviews and data safety |
| P0 | Confirm strong tenant/branch/FY authorization and approval/idempotency checks | Baseline | Fail-closed financial execution |
| P1 | Searchable shared ERP entity selectors and safe match policies | P0 | No memorized warehouse IDs |
| P1 | Unified workflow card and controlled dependent-master resumption | Entity lookups | One cohesive user experience |
| P1 | Structured privacy controls, correlation, alerts and credit-meter visibility | P0 | Operational safeguards |
| P1 | Evaluation suite + end-to-end/browser evidence + staged rollout | P0–P1 | Release confidence |
| P2 | Optional MCP/RAG hardening and operations dashboards | P1 | Extensibility without arbitrary writes |
| P2 | External WAF/edge configuration and deeper infra resilience | Infra access | Verified platform controls |

P0/P1/P2 are **implementation priorities**, not claims about feature availability.

## Non-negotiable invariants

1. **Never execute financial, stock or master-data writes directly from model text or untrusted documents.** Use `ActionProposalService`, `ActionApprovalService`, `ApprovedActionExecutor` and domain command adapters.
2. **Never use an unscoped ERP record:** every lookup and action is authorized to the active user/company/branch/FY as applicable; enforce on the server, never only in browser filters.
3. **Do not auto-create products, suppliers, warehouses or units on lookup failure.** Show existing candidates first; master creation is a governed, authorized action.
4. **Do not silently select the first partial match.** Exact/unique eligible matches or explicit configured defaults only; ambiguous selections block approval.
5. **Never skip purchase-bill GST/HSN/UOM/stock/PO/GRN/duplicate-invoice validation.** Recalculate in authoritative ERP services.
6. **Approval signs an immutable proposal version.** Edits invalidate approval; changed source state requires a fresh preview.
7. **Execution is idempotent** against user double-clicks, client retry and queue re-delivery.
8. **Preserve model/credit accounting** for each attempt, including failures and retries, with accurate reserve/settle/release semantics.
9. **Prompt/document content cannot grant permission** or choose arbitrary tool URLs or destructive side effects.
10. **Deployment is gated by demonstrated evidence,** not by the presence of tests or migration files alone.

## First-day checklist

```bash
# Inspect and do not modify product code during baseline
pwd
git status --short
git remote -v
git branch -a
git fetch origin --prune
git rev-parse HEAD
git rev-parse origin/feature/ai-unified-input-clarification
php -v
php artisan --version
php artisan route:list --path=ai
php artisan test --filter=Ai   # Inspect phpunit.ai.xml before substituting suites
```

Also inspect `.env` **keys and enabled/disabled state only**; never display secret values in logs, PR comments or test artifacts. Confirm PHP, Laravel, Redis, MySQL, queue worker, scheduler and deployed SHA with operators. If source and the documented baseline differ, update this pack's assumptions before implementation.

## Workflow / PR discipline

- Use a dedicated feature branch; make one independently testable PR per work package.
- For each PR: describe current defect, changed contracts, permission implications, tests, screenshots where UI is changed, and rollback path.
- Prefer existing Laravel service boundaries and Blade/jQuery/JS design system. **Do not introduce React, a new backend framework or a separate multi-agent runtime merely to achieve the UX.**
- Never change main/production secrets, database records, payment/financial settings or production flags during development verification.
- Run security/authorization tests before and after UI integration; run the exact user journey in a seeded staging company.

## Cross-reference

The detailed entity selection specification originally reviewed commit `1b4745d`. It is included unchanged as `11_ENTITY_UI_DETAILED_REFERENCE.md`; **reconcile it with the newer inspected commit `132cf77` before following line-specific references**. This new pack takes precedence for architecture sequencing and release gates.
