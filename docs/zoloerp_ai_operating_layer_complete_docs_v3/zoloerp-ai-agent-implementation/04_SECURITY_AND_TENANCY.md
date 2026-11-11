# 04 — Security, Tenancy, Privacy and Approval Model

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Non-Negotiable Tenancy & Security Invariants
An LLM is **not** a user, authorization server, accountant, or posting engine. Every operation executes as a currently authenticated human identity in a validated company, branch, and financial year. The model cannot supply those identities, grant permissions, change role flags, or select arbitrary data sources.

---

## Two Distinct Security Modes for Setup & Operations

To prevent weakening tenancy boundaries, the architecture enforces two distinct execution modes:

### Mode A — Pre-Company Bootstrap (Installation Administrator)
- **Scope:** Operates strictly *before* a company, branch, or financial year exists.
- **Access Control:** Accessible exclusively to an authenticated installation administrator (or an authorized group admin creating a company within their managed group under `CompanyDirectoryService::canManageGroup`).
- **Dedicated Entry Point:** Runs through dedicated bootstrap endpoints (`/api/v1/ai/setup/bootstrap/*`), completely isolated from regular tenant chat controllers and middleware.
- **Principal Object:** Governed by `BootstrapSetupPrincipal`—a server-owned DTO representing a strictly limited installation-admin identity, **not** a general-purpose superuser. It contains allowed bootstrap capability keys, rate budgets, task IDs, and expiry.
- **Single Atomic Operation:** Invokes `CompanyDirectoryService::createCompany` once in a single database transaction, automatically provisioning:
  1. Company linked to an optional company group.
  2. Main branch (`MAIN`).
  3. First financial year.
  4. Main warehouse assigned to the main branch.
  5. Chart of accounts and semantic mappings.
  6. Industry profile and capability defaults.
  7. Starter masters (customer group, biller, cash/bank accounts, document series).
- **Security Invariant:** Never fabricate placeholder `company_id = 0` or fake FY contexts to bypass tenant middleware.

### Mode B — Company-Scoped Configuration & Operations
- **Scope:** Active business operations and post-creation master setup.
- **Transition:** Occurs strictly upon receipt of verified `CompanyDirectoryService::createCompany` execution.
- **Principal Object:** Constructed by `CompanyContextResolver::forActor()`, binding authenticated actor, exact `company_id`, `branch_id`, active `financial_year_id`, and Spatie capabilities.
- **Execution:** Every subsequent setup step (units, extra warehouses, tax registrations, products, suppliers, customers, opening balances) executes under exact company permissions and domain services.

---

## Proposal Hashing & Cryptographic Approval Binding

**SHA-256 alone is not an authorization mechanism.**

1. **Canonical Proposal Hash:** The system generates a canonical SHA-256 hash of the immutable proposal payload containing all resolved business fields (line items, rates, taxes, UOM, ledger accounts, totals, and predicted effects).
2. **Cryptographic Approval Binding:** The approval record explicitly binds:
   ```text
   approval_signature = HMAC_SHA256(
       payload_hash + action_key + action_version + approver_id +
       company_id + branch_id + financial_year_id + source_state_hash + timestamp,
       server_secret
   )
   ```
3. **Independent Server Authorization:** The server independently verifies the approver's active session, role, company membership, and module capabilities upon approval submission. A valid payload hash alone never grants write authority.
4. **Invalidation on Stale State:** If the proposal is edited ("Change Request"), the version increments (vN → vN+1), requiring a new hash and a fresh human approval. If the underlying ERP record or dependencies change prior to commit, the previous approval is invalidated.

---

## Short Transaction Lifetime & Domain Ownership

1. **Zero Open Transactions During Inference or Review:**
   - Database transactions must remain strictly short-lived.
   - `DB::transaction()` must **never** be held open while an LLM generates tokens, asks clarification questions, or awaits human review in the AI Workspace or Action Review Center.
2. **Execution-Time Revalidation:**
   - At the moment of execution, row locks (`lockForUpdate()`) and state re-validation are acquired inside a brief, atomic execution block.
3. **Preserved Domain Service Ownership:**
   - If an authoritative ERP service already manages its own database transaction (e.g., `CompanyDirectoryService::createCompany`, `CommercialApplicationService`), the AI executor must preserve that existing transactional boundary rather than introducing conflicting nested or parallel transaction logic.

---

## Least-Privilege & Autonomy Matrix

The system operates strictly within **Autonomy Levels A0 to A3**:

| Level | Description | Permitted Capabilities | Approval Policy |
|---|---|---|---|
| **A0** | Explain and navigate | Read tools (`AiToolManifest`) | Automatic read within actor's Spatie permissions |
| **A1** | Disambiguate and fill slots | Intent parsing, record lookup | Automatic preparation, zero ERP state mutation |
| **A2** | Save non-posted draft | Master drafts, quotation drafts | Explicit human confirmation of affected records |
| **A3** | Submit operational transaction | Orders, receipts, setup creation | Exact hash-bound approval + fresh live revalidation |
| **A4 / A5** | Unattended posting / Background execution | Direct financial/stock posting | **STRICTLY PROHIBITED.** No unattended posting |

---

## Prompt, Tool, and Model Safety
- **Closed Input Schemas:** Bounded lengths, enums, decimals, and quantities. Reject any unexpected fields, especially ownership fields (`company_id`, `actor_id`).
- **Untrusted Input Classification:** Treat user prompts, uploaded documents, OCR text, and model responses as untrusted data.
- **Fail-Closed Execution:** Deny execution on permission failure, validation error, or stale version state. Provide sanitized, user-safe diagnostics.
- **Dual Interface:** Interactive preview and editing in the AI Workspace (`workspace.blade.php`); pending approval queue and audit log in the dedicated Action Review Center.
