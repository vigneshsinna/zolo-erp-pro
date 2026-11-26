# 20 — Full Delivery Backlog and Dependency Gates

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Priority: finish read-only foundation, then business action vertical slices
Phases below are **not simultaneous**. Each phase has a separate PR, defined operator/business owner and exit gate. Story points are estimates relative to the team, not deadlines.

### PH0 — Complete the existing Copilot refactor (current branch)
| ID | Deliverable | Dependencies | Exit gate |
|---|---|---|---|
| CP-001 | Fix `DesignTokensTest` failures in Copilot workspace | Current refactor | UI CSS in approved page styles; both tests green |
| CP-002 | Investigate `ProductAliasTest` 5 vs 4 failure | Commercial code and baseline | Behavior reviewed, fixed or test updated with justified evidence; full suite green |
| CP-003 | Verify sales/GST/AR/open order/stock golden parity | Domain services and fixtures | Exact scoped data and financial controls reconcile |
| CP-004 | NVIDIA-compatible real staging tool calls and telemetry | Approved provider budget | Model invocation and retries accurate; safe failures |
| CP-005 | Freeze read contracts, docs, release evidence | CP-001..004 | PR approved; complete ERP acceptance green |

### PH1 — Future Action Foundation (new branch, flags off)
| ID | Deliverable | Dependencies | Exit gate |
|---|---|---|---|
| AC-001 | One capability inventory of ERP actions and domain owners | CP-005 | Signed action matrix and risk classification |
| AC-002 | `ActionCapabilityRegistry` and `BusinessCommandAdapter` interface | AC-001 | Explicit allowlist; no reflection-driven arbitrary calls |
| AC-003 | Intent schema, entity resolver and missing-slot manager | AC-001 | Ambiguous matches require confirmation |
| AC-004 | Durable task/proposal state with migration and versioning | AC-002 | Atomic transitions, retention, approval hash |
| AC-005 | Preview/approval UI and secure endpoints | AC-004 | Edit/approve/cancel; stronger permission and revalidation |
| AC-006 | Idempotent approved executor and receipt | AC-002..005 | Race/retry, reversal, and post-commit loss tests pass |
| AC-007 | Audit and kill switches | AC-002 | Zero unauthorized writes under adverse tests |

### PH2 — First useful AI transaction: Purchase Order
| ID | Deliverable | Dependencies | Exit gate |
|---|---|---|---|
| PO-001 | Supplier/product/UOM/warehouse matching | AC-003 | Correct ownership, aliases, ambiguity |
| PO-002 | Preview pricing and GST through domain service | PO-001 | No hardcoded tax or LLM monetary math |
| PO-003 | Validated PO draft/save and approval | AC-004..006, PO-002 | One actual PO receipt with idempotency and source links |
| PO-004 | Pause/resume and document review UX | PO-003 | User can edit and continue multi-turn workflow |
| PO-005 | Staging business UAT | PO-001..004 | Accounting/stock unaffected when only order created |

### PH3 — Purchase receipt and supplier bill
| ID | Deliverable | Exit gate |
|---|---|---|
| PB-001 | Goods receipt lifecycle/stock integration | No duplicate receipt, correct stock policy and ledger effects |
| PB-002 | Supplier invoice draft, duplicate check, GST/FX and posting | Approved posting through purchase domain service; exact receipt |

### PH4 — Sales and collections
| ID | Deliverable | Exit gate |
|---|---|---|
| SL-001 | Sales quotation and order draft/submit | Customer matching, pricing, discount/stock/credit checks |
| SL-002 | Sales invoice issue and payment allocation proposal | No duplicate sales stock/COGS or GL postings; correct balances |
| SL-003 | Collections drafts and controlled send | Never send without contact-specific policy/approval |

### PH5 — Other ERP modules
| ID | Deliverable | Exit gate |
|---|---|---|
| MA-001 | Customer/supplier/product guided create/update | Scoped validation, duplicate resolution, version check |
| IV-001 | Warehouse transfer and stock adjustment | Transfer safe, adjustments high-risk approval |
| FI-001 | Journal draft and posting | Balanced entries, period/account permissions, no duplicate |
| MF-001 | Production/job work planning | Industry authorization, BOM and material validation |

### PH6 — Document and bulk entry
| ID | Deliverable | Exit gate |
|---|---|---|
| DC-001 | OCR/extraction/upload quarantine and editable mapping | Duplicate detection, evidence/confidence, human correction |
| BK-001 | Bulk import plan/dry-run/status | Per-row errors, partial batch recovery, no unsanctioned posts |

### PH7 — Controlled recurring autonomy and optional integrations
`AU-001` opt-in scheduled low-stock read/proposals; `AU-002` payment reminder draft scheduler; `AU-003` task inbox and approval delegation; `OP-001` Redis/Horizon queue throughput; `OP-002` optional MCP; `KN-001` policy RAG; `EV-001` Langfuse/provider/model evaluation. All require scoped permissions, bounded runtime and measurable business benefit.

## Release gates (all mandatory)
**G0** green current ERP CI + read-only model contract. **G1** capability inventory and approved domain mappings. **G2** zero unauthorized writes + proposal hashes + approval concurrency. **G3** first actual PO receipt with review and retry. **G4** lifecycle accounting/stock reconciliation. **G5** document/bulk and scheduled workflow security. Separate production enablement per company/capability.

## PR and deliverables format
Each PR includes before/after behavior, exact SHA, changed services, tests with figures and errors, privacy/security considerations, measured latency and costs, migration + rollback, and current known issues. Never treat a “plan drafted” checkbox as “code shipped.”
