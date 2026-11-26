# 10 — Acceptance Criteria, Evidence Matrix and Definition of Done

**Purpose:** Give Product, Engineering, QA, Security and Finance an objective way to decide when the architectural improvements are complete. These are **target criteria**, not claims of implementation.

## 1. Functional acceptance criteria (Given / When / Then)

### AC-01 — Automatically prepare a valid supplier invoice

**Given** I am an authorized ZoloERP user with a supplier invoice image or PDF,  
**When** I upload it to Copilot and request a purchase bill,  
**Then** the software extracts the details, resolves records and displays one purchase-bill review card without separate “Confirm Extraction” and “Stage ERP Proposal” steps; no ERP write occurs before final approval.

### AC-02 — Show existing warehouses without requiring a name

**Given** a purchase-bill workflow has no selected warehouse,  
**When** I open Destination Warehouse,  
**Then** I can browse/search the warehouses available for my company, branch and action, with real names and useful branch details.

### AC-03 — Only one safe match

**Given** there is exactly one verified eligible warehouse or an explicitly configured valid default,  
**When** Copilot resolves the warehouse,  
**Then** it is selected and labeled with a reason, and I can choose another authorized warehouse before approval.

### AC-04 — Ambiguous warehouse names

**Given** more than one eligible warehouse matches,  
**When** Copilot prepares the bill,  
**Then** it shows the matching options, does not silently pick the first match and keeps final approval disabled until I select a valid warehouse.

### AC-05 — No warehouse found

**Given** no existing warehouse is suitable and I have warehouse-creation permission,  
**When** I choose Create New,  
**Then** Copilot opens an editable, prefilled master setup form, checks for existing duplicates and shows an authorized creation proposal before writing any record.

### AC-06 — Creation denied when permission missing

**Given** I lack warehouse-creation permission,  
**When** I review missing-warehouse options,  
**Then** creation is disabled with a helpful explanation and a forged creation API request is also denied.

### AC-07 — Resume the original task

**Given** I approved creation of a missing warehouse,  
**When** the ERP returns the new warehouse receipt,  
**Then** its ERP ID is bound to the original purchase-bill task, the bill is revalidated and the same review card resumes without requiring another upload.

### AC-08 — Master creation and bill creation are distinct approvals

**Given** the bill depends on a newly created warehouse,  
**When** I approve the warehouse master,  
**Then** the purchase bill is still only a proposal and requires its own valid signed approval before posting.

### AC-09 — Correct field update API

**Given** I edit supplier, warehouse, invoice number or line item details,  
**When** I choose Submit Details / Update & Revalidate,  
**Then** the client submits the actual `corrections` contract with the latest `expected_revision`, and the server returns fresh readiness and preview status.

### AC-10 — No stale or misleading warehouse labels

**Given** warehouse selection is unresolved or changed,  
**When** the review or booked screen is shown,  
**Then** it never displays a fabricated `Main Warehouse` fallback; it shows the authoritative resolved or receipt warehouse or `Not selected`.

### AC-11 — Source validation blocks incorrect financial outcomes

**Given** the supplier GSTIN, product HSN/UOM, tax rate, PO/GRN or totals conflict with ERP rules,  
**When** the final proposal is prepared,  
**Then** the system displays a blocking error or explicit clarification and never posts unvalidated amounts.

### AC-12 — Approved proposals cannot be changed silently

**Given** a proposal version is approved,  
**When** a user or process changes a validated field or source entity becomes stale,  
**Then** the original approval is unusable and a new preview/version/approval is required.

### AC-13 — Duplicate clicks are harmless

**Given** a purchase-bill proposal has been approved,  
**When** approval/execute is submitted twice or a queue job retries,  
**Then** the system returns the existing receipt without duplicating purchase bill, inventory or GL entries.

### AC-14 — Tenant/branch/FY isolation

**Given** I am authenticated to company A/branch A/FY A,  
**When** I attempt to fetch company B records, another branch's restricted warehouse or another user's task/media,  
**Then** the server denies access and returns no unauthorized metadata.

### AC-15 — Safe AI/tool boundaries

**Given** an invoice, voice transcript, RAG excerpt or MCP response contains commands to create/post without authorization,  
**When** the model processes it,  
**Then** the text is treated as untrusted source content and cannot bypass registered tools or approvals.

### AC-16 — Accurate usage metering

**Given** the company has an allowance and users issue concurrent model requests,  
**When** model calls are dispatched, retried or fail,  
**Then** reservations and settlements are recorded once per provider attempt and the company cannot exceed its usable credit balance.

### AC-17 — Traceable execution without sensitive logs

**Given** a model call or purchase-bill action fails,  
**When** support searches by its trace/reference ID,  
**Then** support can see scoped failure stage, model/provider, elapsed time, credit status and resolution guidance without raw invoice personal data or secrets.

### AC-18 — Real runtime edge controls

**Given** production edge protection is configured,  
**When** malformed, abusive or rate-exceeding requests arrive,  
**Then** the actual deployed ingress limits/rules are demonstrably enforced and a reversible operational rollback exists.

### AC-19 — Optional MCP/RAG does not change financial authority

**Given** external MCP or document knowledge is enabled,  
**When** Copilot retrieves reference information,  
**Then** access is authorized/read-only and financial records are still queried and changed only through authoritative ERP services.

### AC-20 — Release evidence is reproducible

**Given** a release candidate SHA,  
**When** the AI release pipeline runs,  
**Then** the report links executed test cases and environment-specific evidence, clearly distinguishing fixture/mock from live provider results, and blocks deployment if critical checks fail.

## 2. Nonfunctional criteria

| Quality | Target behavior | How to prove |
|---|---|---|
| Accessibility | Keyboard usable picker, focus returned after closing, screen-reader labels and mobile drawer | Playwright/a11y audit and manual QA |
| Responsiveness | Desktop two-panel review, mobile single panel/bottom sheet; no horizontal overflow | Cross-viewport screenshots |
| Usability | No need to know internal supplier/product/warehouse IDs; no redundant confirmation | Moderated test + browser journeys |
| Reliability | Task resumes after reload/network interruption without duplicate transaction | Browser reconnect and retry tests |
| Performance | p95 target based on measured staging baseline, separate model and ERP latency | Load report 1/5/10/20 users |
| Security | No unauthorized cross-company/branch/FY data, permission or approval bypass | API adversarial tests |
| Privacy | Minimize outbound payloads; no secrets/raw PII in telemetry exports | Payload inspection and log scan |
| Cost | No negative credits or double-charge; actual usage reconciliation | Concurrency + ledger audit |
| Maintainability | One shared picker, one typed workflow response, domain services reused | Code review + import/call-graph scan |
| Operability | Alerts, trace IDs, feature-flag rollback and safe incident procedures | Staging game day and SRE sign-off |

## 3. Evidence ownership and traceability

| Workstream | Task | Required evidence | Reviewer |
|---|---|---|---|
| Baseline | T00 | Git SHA, route/service inventory, baseline test counts | Tech lead |
| Correction contract | T01 | Feature + browser tests, network payload inspection | Backend/QA |
| Safe record matching | T02 | Unique/multi/none/wrong-branch test corpus | Backend/Finance |
| Directory API | T03 | Scoped endpoint contract + auth tests | Security/Backend |
| Picker UI | T04 | Desktop/mobile/keyboard screenshots and Playwright | UX/QA |
| Missing masters | T05 | Signed master approval, resumption, bill receipt | Finance/Backend |
| Privacy | T06 | Model payload review + PII/prompt injection tests | Security |
| Observability/credits | T07 | Trace and credit reconciliation reports | SRE/Finance |
| WAF/edge | T08 | Actual deployed WAF/TLS/abuse test evidence | Infra/Security |
| MCP/RAG | T09 | Allowlist/ACL/SSRF/prompt-injection test evidence | Security |
| Evaluation | T10 | JUnit/Playwright/load + fixture/live labels | QA/Tech lead |
| Rollout | T11 | Canary, monitored metrics, Go/No-go, rollback drill | Release/SRE |

## 4. Explicit blockers (cannot waive casually)

- Unauthorized ERP financial or master-data write.
- Any cross-tenant data leak.
- Wrong warehouse selected automatically from a partial or truncated match.
- No fresh approval for materially changed bill.
- Incorrect CGST/SGST/IGST, HSN/UOM mismatch or stock/AP double posting.
- Duplicate bill caused by retry or concurrent approval.
- Credit overspend, unexplained double debit or incorrect top-up ownership.
- Raw credentials/secrets in telemetry, public preview URLs or PR artifacts.
- “All tests passed” with missing/skipped critical scenario evidence.

## 5. Definition of Done (release level)

```text
[ ] Changes are implemented on a reviewed feature branch; exact diff/commit recorded.
[ ] New feature flags are safe by default, documented and reversible.
[ ] Corrected slots_patch/corrections path passes both UI flows.
[ ] Warehouse matching never silently uses first partial hit.
[ ] Shared picker works for real authorized ERP records without requiring names/IDs.
[ ] Missing-master creation is authorized, previewed and separately approved.
[ ] Purchase bill resumes and receives its own final signed approval.
[ ] GST/stock/GL/GRN/duplicate invoice invariants verified.
[ ] Tenancy, privacy, prompt injection, idempotency and credit tests pass.
[ ] Required fixture and paid-provider evidence are clearly labeled and linked.
[ ] Edge/WAF/observability items either runtime-verified or explicitly deferred and gated.
[ ] Staging smoke and non-AI ERP regression tests pass.
[ ] SRE/Finance/Security/QA/Tech Lead sign off; rollback plan rehearsed.
[ ] No production feature claimed as operational without runtime proof.
```

## 6. Summary for Product Owner

Your main product outcome is not “add more agents.” It is:

**One Copilot conversation → one understandable ERP review → guided record selection → secure approved actions → verifiable receipt.**

The supporting architecture must ensure the model never substitutes for permission checks, tax calculations, transaction ownership or cost controls.
