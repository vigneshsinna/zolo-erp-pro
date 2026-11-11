# zoloERP AI Operating Layer v3 — Implementation Master Plan

**Source code reviewed:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). This is architecture and documentation, **not newly implemented code**.

## Product Goal
One AI Command Center supports multi-modal intake (text, voice, image/documents). It reads ERP information, guides initial setup, fills forms, resolves suppliers/customers/products/UOM, displays authoritative editable previews, requires explicit human approval, and executes allowed actions via existing Laravel domain services. The model never receives arbitrary SQL or direct record-edit privileges.

Review and approval are delivered across two coordinated interfaces:
1. **AI Workspace (`workspace.blade.php`):** Interactive conversational turns, slot gathering, clarifying questions, live preview cards, and in-place "Change Request" editing.
2. **Action Review Center (Dedicated Operational Desk):** Cross-module pending approval queue, multi-item approval, audit history, and execution receipts.

## Current ERP and AI Status
The current Copilot has read-only tools through `AiToolManifest`, `GenericSdkTool` and `ErpToolExecutor`; it has **no** AI-enabled company, group, purchase, sales, journal or stock write tool. Existing Laravel code already supports company groups and an atomic `CompanyDirectoryService::createCompany` operation that transactionally provisions company, MAIN branch, first FY, main warehouse, chart of accounts, industry settings, and starter masters. ReviewedProductImport and related setup services support additional masters. These services must be exposed only through separately built, approved setup capabilities.

**Latest CI:** GitHub Actions run [42870009218](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42870009218) passed **11/11 jobs** including AI, operations, commercial, clean MySQL and aggregate gate on `14195d8`. Provider staging and real-domain business signoff remain independent gates.

## Recommended Implementation Order
1. **PH0 — Confirm read-only Copilot:** preserve passing acceptance CI, verify financial source accuracy and bounded NVIDIA real-provider tool calls.
2. **PH1 — Secure Action Foundation:** explicit action registry separate from read manifest, task state, resolution/validation, typed preview, immutable approval binding, short-transaction execution adapter, Action Review Center, and idempotent command/receipt. Flag off by default.
3. **PH1.5 — Initial Setup & Onboarding Agent:**
   - **Mode A (Pre-Company Bootstrap):** Dedicated bootstrap API (`/api/v1/ai/setup/bootstrap/*`), `BootstrapSetupPrincipal` (strictly limited installation-admin identity, not general superuser), optional company group, atomic company creation via `CompanyDirectoryService::createCompany`.
   - **Mode B (Company-Scoped Setup):** Transition to `CompanyContextResolver`, configure industry, extra branches, warehouses, units, products, parties, tax, and setup readiness validation. Human-approved creation, never unattended.
4. **PH2 — Purchase Order:** natural-language product/supplier matching, validated preview via `CommercialApplicationService`, approval, real domain command, and receipt.
5. **PH3 — Goods Receipt and Purchase Bill:** match received stock and vendor invoices; avoid duplicate stock/GL.
6. **PH4 — Sales and Collections:** quotations, orders, dispatch, invoices, allocations and approvals.
7. **PH5 — Masters and restricted finance/inventory/manufacturing operations:** lifecycle-aware changes, accounting corrections/returns only where real domain supports.
8. **PH6 — Document and bulk entry:** image/PDF/CSV/Excel extraction, previews, imports and per-row receipts.
9. **PH7 — Controlled autonomy:** scheduled read/draft workflows, opt-in execution policy, optional MCP/RAG/trace.

## Normative Lifecycle Policy
**Before posting:** AI may revise proposal, resolve and validate again, show an updated preview and obtain a **new human approval** for every revision. **After submission or posting:** AI may **not** directly edit ERP records; it must check the actual record status and use only the ERP-supported amendment, cancel, return, credit/debit note, reversal or adjustment workflow. Immediately before execution, reload authorization, status/version, FY, stock/accounting dependencies and approved payload hash. A changed state invalidates the old approval. Existing ERP domain services remain the authority.

## Short Transaction Lifetime & Domain Ownership
Database transactions must remain strictly short-lived. `DB::transaction()` must never be held open while an LLM generates tokens, gathers slots, or awaits human review. At execution time, revalidate under appropriate row locks and delegate to the authoritative ERP service's existing transactional boundary. If that service already manages the transaction (e.g., `CompanyDirectoryService::createCompany`), preserve its existing ownership instead of introducing conflicting transaction logic.

## Source-Confirmed Setup Services
- `CompanyDirectoryService::createGroup`, `createCompany`, `installStarterMasters`.
- `CompanySetupService::update`, `branch`, `location`.
- `IndustryCatalog`, `IndustryProfileService` for `general_trading`, `fmcg`, `textile`, `timber`, `solar` and their subtypes.
- `CompanyProfileService`, `GstSetupService`, `TaxSetupService` for profile and registration.
- `ProductMasterService`, `ReviewedProductImport` for product catalog.
- Legacy `UnitController`, `WarehouseController`, `CustomerController`, `SupplierController` are **not** approved AI action adapters by virtue of existing; review and introduce scoped domain services.

## Core New Documents
- `37_GUIDED_INITIAL_SETUP_AGENT.md` — complete guided company/master onboarding with Mode A bootstrap and Mode B scoped configuration.
- `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` — strict post-submit/posting edit and correction constraints.
- `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md` — source mappings and 33 setup acceptance scenarios.

See `docs/zoloerp-ai-agent-implementation/docs/ai/README.md` for all 40 documents. Documentation pack does not modify the repository or enable writes.
