# zoloERP Autonomous AI Operating Layer — Concepts Brief & Brainstorm Synthesis

* **Author:** vigneshsinna
* **Date:** 2026-11-11
* **Source Base:** `refactor/erp-copilot-stabilization` @ `14195d8`
* **Reference Specifications:** `docs/zoloerp-ai-agent-implementation/docs/ai` (v3 Complete Index)
* **Synthesized Lenses:** `egm-design`, `egm-codebase-details`, `egm-zoom-out`, `egm-comprehension`, `egm-implementation-readiness`, `egm-structural`, `egm-inventive-catalyst`

---

## Overview

The zoloERP Autonomous AI Operating Layer transitions zoloERP Pro from its current read-only Copilot baseline (14 read tool families in `AiToolManifest` with zero write capabilities) into an enterprise-governed **AI Command Center**. It enables multi-modal conversational intake (text, voice, image/documents), entity disambiguation, governed transaction execution, and administrative setup.

The overarching design invariant is: **Untrusted Model, Authoritative Server**. Large Language Models act strictly as uncommitted planners, slot extractors, and formatters. All calculations, state validations, authorization checks, and transaction commits are strictly owned by established Laravel domain services (`CompanyDirectoryService`, `CommercialApplicationService`, `OrderLifecycleService`).

---

## Analysis

### 1. Multi-Lens Goldfish Review Synthesis

The 7-lens Goldfish review swarm identified critical blockers and structural risks that must be resolved prior to functional implementation:

#### A. Security & Route Segregation (Mode A vs Mode B)
* **Risk (🔴 Blocker):** Routing Mode A bootstrap requests through regular tenant chat endpoints (`/api/v1/ai/chat`) creates severe tenant leakage risk or prompts developers to introduce wildcard bypasses (`company_id = 0` or `null`) into tenant middleware.
* **Resolution:** Mode A is strictly segregated under dedicated endpoints: `/api/v1/ai/setup/bootstrap/*`. These routes are guarded by an `InstallationAdminGate` (`CompanyDirectoryService::isInstallationAdmin`) using `BootstrapSetupPrincipal`. Regular tenant middleware remains completely uncompromised.
* **Context Transition:** The Mode A completion receipt returns an authoritative transition payload (`company_id`, `switch_context_url`, session token), allowing the UI to re-anchor into standard `CompanyContext` before Mode B begins.

#### B. Concurrency, Locking & Short Transaction Lifetimes
* **Risk (🔴 Blocker):** `CompanyDirectoryService::createCompany` opens and commits its own `DB::transaction(...)`. If the AI layer opens an outer transaction or holds database locks during model inference, token generation, or user preview pauses, connection pool exhaustion and deadlocks will occur.
* **Resolution:** `$domainOwnsTransaction = true` is declared on action descriptors. The AI orchestrator NEVER holds a database transaction open while waiting for the model or user. Transactions occur strictly within the final domain execution dispatch window.

#### C. Idempotency & Replay Prevention
* **Risk (🔴 Blocker):** Duplicate approval clicks or network timeouts can lead to duplicate company creation or unique constraint crashes.
* **Resolution:** Every proposal enforces an `idempotency_key` (UUID v7). The proposal state transitions atomically: `AwaitingApproval -> Executing -> Completed`. A duplicate approval returns the existing execution receipt with HTTP 200 rather than re-running domain logic.

#### D. Transaction Lifecycle Enforcement & Double-Entry Accounting
* **Risk (🟡 Major):** Natural-language instructions to edit existing transactions ("change quantity on bill PB-1025") must never trigger direct SQL `UPDATE` statements on submitted, posted, or paid records.
* **Resolution:** The system enforces `DomainLifecyclePolicy`. Editable drafts may update permitted fields via draft services; submitted or posted records strictly reject direct edits and route the user to formal, ERP-sanctioned amendment, Credit/Debit Note, or Reversal workflows.

#### E. Dual UI Experience & Invalidation UX
* **Risk (🟡 Major):** If an approval is invalidated due to live state changes (closed FY, row version mismatch), users need clear feedback rather than generic errors.
* **Resolution:** The Blade UI handles the `Invalidated` state explicitly, notifying the user: *"Record state changed in ERP since preview generation. The previous approval signature has been revoked for security."* A one-click "Regenerate Preview" button is provided.

---

### 2. Inventive Contradictions & Solutions (TRIZ Analysis)

1. **Zero-Trust vs. Conversational Ergonomics:**
   - *Contradiction:* Users expect fluid chat interaction; financial accounting demands zero-trust Spatie gates.
   - *Resolution (Plane & Temporal Separation):* Conversational slot filling and preview generation operate in uncommitted read/draft space (Autonomy A1). Final execution is gated behind a discrete, cryptographically signed approval record (Autonomy A3).
2. **Pre-Tenancy vs. Multi-Tenant Scoping:**
   - *Contradiction:* Tenancy Law 1 requires all queries to scope by `company_id`; initial setup has no company.
   - *Resolution (Two-Tier Identity):* Mode A runs under `BootstrapSetupPrincipal` on isolated endpoints. Once provisioned, the session transitions irreversibly to standard `CompanyContext`.
3. **Interactive Editing vs. Signature Immutability:**
   - *Contradiction:* Users frequently revise parameters in chat ("change quantity to 50"); cryptographic signatures demand immutable payloads.
   - *Resolution (Versioned Proposal Tree):* Any change request increments the proposal version (`vN -> vN+1`), invalidates the prior HMAC signature, and generates a new canonical hash requiring fresh approval.
4. **Orchestrator Transaction vs. Domain Ownership:**
   - *Contradiction:* The AI Orchestrator needs rollback guarantees; domain services (`createCompany`) already encapsulate their own transactions.
   - *Resolution (Preserved Domain Ownership):* The orchestrator validates state pre-flight and delegates transactional boundaries entirely to the domain service (`$domainOwnsTransaction = true`).

---

## Goals vs. Non-Goals

### In-Scope Goals
* Synchronizing the complete v3 documentation (40 docs, 01–39 + README) into the repository.
* Building the **Shared Action Foundation (PH1)**: Action registry, typed preview adapters, cryptographic HMAC approval binding, short-transaction execution adapter, and the dedicated Action Review Center.
* Building the **Guided Initial Setup Agent (PH1.5)**: Mode A pre-company bootstrap principal, human-approved company creation via `CompanyDirectoryService`, and Mode B company-scoped master configuration.
* Building the **Commercial Action Slice (PH2)**: Natural-language Purchase Order AI workflow.
* Enforcing **Domain Lifecycle Policies**: Status-aware transition guards blocking unauthorized post-submit record mutations.

### Out-of-Scope Non-Goals
* Fully unassisted or unattended financial/inventory postings (Autonomy A4/A5 prohibited).
* Direct Eloquent SQL updates bypassing domain services.
* Exposing write capabilities inside the read-only `AiToolManifest`.
* Holding database transactions open during LLM inference or user review pauses.

---

## Alternatives Weighed

1. **Wildcard Tenant Middleware (`company_id = 0`):**
   - *Rejected:* Weakens Tenancy Law 1 and creates severe risk of cross-tenant data leakage if an endpoint fails to check permissions.
2. **Direct Action Calling via AI SDK Tools:**
   - *Rejected:* Bypasses human review, creates race conditions, and violates financial auditability.
3. **Universal `posted` Column Check:**
   - *Rejected:* Different ERP modules use distinct lifecycle states (`status`, `sale_status`, `payment_status`). Requires per-module `DomainLifecyclePolicy`.
4. **Implementing Purchase Workflows Before Setup:**
   - *Rejected:* Without clean company and master setup, operational transactions lack reliable foreign key targets, leading to brittle mock setups.

---

## Key Decisions & Staging for Next Steps

* **Documentation Replacement Gate:** Complete. All 40 specification documents synchronized in `docs/zoloerp-ai-agent-implementation/docs/ai/`.
* **Stage 1 (PH1 — Shared Action Foundation):**
  - Implement `ActionCapabilityRegistry` and `ActionDefinition` (`$domainOwnsTransaction = true`).
  - Add additive migrations: `ai_action_proposals`, `ai_action_approvals`, `ai_action_executions`.
  - Implement `ApprovedActionExecutor` with HMAC approval binding and short-transaction lifecycle guards.
  - Deliver dedicated Action Review Center operational desk.
* **Stage 2 (PH1.5 — Guided Initial Setup Agent):**
  - Mode A: Dedicated `/api/v1/ai/setup/bootstrap/*` routes and `BootstrapSetupPrincipal`.
  - Connect `CompanyDirectoryService::createCompany` for 1-step atomic provisioning.
  - Mode B: Transition session to `CompanyContext` and configure master catalogs.
* **Stage 3 (PH2 — Purchase Order Workflow):**
  - Natural-language item/supplier resolution via `CommercialApplicationService`.
