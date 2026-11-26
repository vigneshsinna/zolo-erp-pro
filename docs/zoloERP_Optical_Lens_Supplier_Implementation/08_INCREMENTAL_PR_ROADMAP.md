# 08 — Incremental PR roadmap and delivery contract

## Starting point

Branch `feature/ai-unified-input-clarification` at inspected commit `e299b862c33f70cff8f86cfcbb36c1326da6dde5`. The earlier PR1–PR16 and U1/U2 work must be reconciled with current HEAD, not re-implemented from older snapshots. Use new branch names and proposed work package IDs below (not actual GitHub PR numbers). User asked for specification files; no code or GitHub mutations have been performed in this review.

Each package begins with actual source inspection and impact matrix, small reviewable PR, tests enabled and disabled, no silent fallback, no independent engine. Do not merge until required CI and review pass; dependencies must be integrated and final SHA revalidated. Keep pilot flags off. If source already implements a requirement, add evidence/test rather than duplicate feature. Use owner-approved changes for financial or clinical-sensitive logic.

| PR / dependency | Scope / target files | Acceptance |
|---|---|---|
| O01 baseline | Reconcile previous docs/PRs with pinned HEAD; full CI, migration path, textile FY regression; `docs/ai/release/*` evidence | G0; update gap register with exact results; no false green |
| O02 after O01 | Modular knowledge schema/registry/validator/source manifest; `app/Ai/Knowledge/*`, `resources/ai/knowledge/*` | Existing definitions compatible; deterministic hash, negative validator tests; G1 |
| O03 after O02 | Industry profile context using `IndustryCatalog`, `IndustryProfileService`; reviewed branch roles only if necessary; scope reader | Profile and tenancy tests, no unsafe fallback or profile reset |
| O04 after O03 | Optical catalog/identity mapping; `RecordResolutionService`, `ProductQueryService`, `Product`, attribute/variant/stock contracts; data reconciliation | Exact signed power/identity, UOM, no ambiguous legacy mapping; T03–T06 |
| O05 after O04 | Optical availability and power matrix; `InventoryAvailabilityService`, `UomConversionService`, authorized branch projection; no duplicate ledger | T07–T08, T29, T55; read-only zero model credits |
| O06 after O04 | Shared optical schema/requirements fragment; PO/bill/SO/CommercialDocument schemas; stable task line IDs and correction provenance | Fields accepted only when profile/action permits, no drop or injection, preview hash tests |
| O07 after O05/O06 | Direct and linked purchasing; `PurchaseDocumentClassifier`, existing PO/bill/GRN providers/adapters, matching and actual receipt | C01–C07, T09–T16; invoice-before-receipt unsupported/hold until real approved route |
| O08 after O05/O06 | Sales knowledge entities/facts/routes; SO, invoice, quotation/DC adapters, `CreditControlService`, order lifecycle | C08–C14, T17–T25; direct sale supported; no double stock |
| O09 after O07/O08 | Fiscal/tax/charges/settlements/returns/reversal parity; existing commercial and accounting services; add missing reviewed domain routes only | G3; manual-vs-AI journal/stock/open-item reconciliation; unsupported hidden |
| O10 after O05/O09 | Multi-store transfers, reorder and transit/partial receiving if absent; existing inventory services and scope guard | T29–T31; authorized transfer only, legal tax handling, retry-safe |
| O11 after O08/O09 | Optional Rx lab/optical job state, own/customer material, reuse existing production and job-work actions | T35–T39; no stock-only forced lab; owner signoff |
| O12 after O11 (or stock-only O08) | Lab/packing slip, shipment, returns and warranty workflow integration, UI source refs | T32–T40; verified prints and QC/reversal |
| O13 after O07–O12 as applicable | Expand existing U1 `CapabilityCatalog`, compiler, dispatcher, policies, providers; missing setup actions; no rebuild | T26–T28, G1; schema and channel parity |
| O14 after O13 | Copilot optical grid, workflow timeline, direct-vs-linked routes, model provenance, UI corrections; existing workspace and Review Center | G7 UI; T53–T54; desktop/mobile; no invented voucher |
| O15 after O13/O14 | Optional MCP additional proposal capabilities, token grants/dedup/status; automation suggestions and metrics | T42–T48; no external approval/execute, no hidden writes |
| O16 after enabled work | Enabled-feature security and evidence hardening, concurrency/kill switch, privacy, queue/recovery | G4; T41–T52, no critical defects |
| O17 after O16 | Live Nemotron evaluation, credits and staging 5/10/20-VU; model and k6 reports | G5/G6, measured thresholds approved, no synthetic-pass claim |
| O18 after O17 | Pilot UAT, migration/backup/rollback drill, owner signoffs, rollout | G7/G8; exact SHA GO/NO-GO; flag-by-flag rollout |

Stock-only supplier pilot can leave O11 optional with lab actions disabled, but cannot skip commercial, optical identity, tax, isolation or release gates. If company really operates internal/external Rx lab then O11 is mandatory. Expense-only AP, invoice-before-receipt and other unimplemented domain routes are explicitly blocked until finance-approved, authoritative feature and full tests are available; do not force them into generic bill. No false claim that all 90 common modules are implemented for pilot. Scope of other industries remains future work; registry supports extension without enabling irrelevant functions.

## Traceability by document

- 01: GAP01–11, O01 establishes actual statuses and baseline. Update on every head movement.
- 02: BK01–90; O02/03/07/08/09 implement reviewed P0 definitions and facts; no automatic draft promotion.
- 03: OLS01–30; O04/05/06/10 primary domain work; exact stock identity first.
- 04: P1–P8, S1–S9, C01–20; O07–09 and O13 required.
- 05: L01–16; O11/12 conditional based on actual business profile.
- 06: O13–15; preserve U1/U2, extend only reviewed tool contracts.
- 07: T01–58, G0–G8; O16–18 evidence; every earlier PR contributes its own acceptance tests.

## Proposed tests (names are suggestions, use repository conventions)

| PR | Test classes / evidence |
|---|---|
| O02–03 | `ModularKnowledgeRegistryTest`, `IndustryKnowledgeScopeTest`, existing BusinessKnowledgeGovernanceTest |
| O04–05 | `OpticalLensIdentityTest`, `OpticalPowerMatrixMysqlTest`, `OpticalAuthorizedStoreAvailabilityTest` |
| O06 | `OpticalCommercialSchemaRoundTripTest`, `OpticalCorrectionApprovalTest` |
| O07–09 | `OpticalDirectPurchaseBillTest`, `OpticalLinkedPurchaseParityTest`, `OpticalDirectSalesInvoiceTest`, `OpticalOrderDispatchInvoiceParityTest`, `OpticalFinancialParityTest` |
| O10–12 | `OpticalStoreTransferConcurrencyTest`, `OpticalLabWorkflowTest`, `OpticalPackingAndReturnTest` |
| O13–15 | `OpticalCapabilityExposureTest`, `OpticalMcpProposalTest`, `OpticalCopilotBrowserTest`, `OpticalDeterministicAutomationTest` |
| O16–18 | `OpticalCrossTenantSecurityTest`, `OpticalApprovalReplayTest`, real k6, corpus and signed UAT reports |

Tests must actually run with feature flags on and production-like DB. Don't count a parameterized suite as covering unexecuted cases. No test weakening to achieve green. Use current test names and configs where already available.

## Required report after every PR

```markdown
# Oxx — Implementation and verification report
- Base / head SHA / PR URL:
- Scope and source files reused:
- New or changed contracts/migrations:
- Knowledge IDs / action keys / interface changes:
- Enabled and default-off behavior:
- Security and finance invariants:
- Test commands, environment, actual passed/failed/skipped, assertions, logs:
- Manual parity results and limitations:
- Remaining blockers, decisions and explicit unsupported capabilities:
- Rollback and audit preservation:
- Review/owner signoff status:
- Merge readiness / release readiness (separate):
```

## Implementation instruction to coding agent

Read README and baseline file first. Resolve actual current branch and compare to pinned snapshot. Read the entire relevant module before editing; identify existing domain services, authority and test fixtures. Start O01, then implement dependencies. Reuse already implemented capabilities; do not create alternative action engine or external MCP approval. Do not guess migrations, class methods, policy permissions, current GST rates, UOM factors or stock identities. Where a source contract is ambiguous, fail closed and document required owner decision. Every new action must have reviewed capability, typed inputs, preview and immutable approval, domain-transactional execution and verified idempotent receipt. Every deterministic task must show zero model calls/credits. Persist evidence after each PR. Finish only with actual source-backed implementation and acceptance results, not a claim based on a design document.
