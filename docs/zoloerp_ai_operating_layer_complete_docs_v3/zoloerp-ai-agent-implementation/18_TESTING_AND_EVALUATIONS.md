# 18 — Quality Engineering, Golden Data and Adversarial Tests

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Verification pyramid
| Layer | Required tests |
|---|---|
| Unit | Schema, prompt-to-intent structure, entity matching, quotas, decimal validation, state transitions, error codes |
| Integration | Real Laravel facades/commands on disposable MySQL 8.0/8.4; company/branch/FY and domain permissions |
| SDK | Fake provider/tool declaration, actual SDK HTTP fake, NVIDIA-compatible tool requests and retries |
| Browser | Chat history, source links, responsive preview forms, edit/confirm/cancel, sanitized Markdown and upload errors |
| Workflow | Clarify/resume, parallel attempts, record change, stale proposal, out-of-order events and idempotent retry |
| Finance | AR/AP reconciliation, tax, exchange, credit/debit note, stock projection/ledger and accounting effects |
| Operations | Queue crash/timeout, post-commit response loss, cancellation, provider 429, tool-call budget |
| Security | Cross-company, branch, FY, revocation, forged tool/route, prompt injection and side-effect gating |

## Golden fixture suites
`tests/Fixtures/Ai/` data should include two companies, two branches, two FYs, catalog products with UOM variants/batches, suppliers/customers sharing names, inactive records, orders, receipts, sales/purchase invoices, returns, allocations, GST exceptions and closed periods. Every scenario includes expected intent, required slots, permitted capabilities, exact authoritative totals, allowed source records, decision/warnings, and expected state/receipt.

## Approval and command invariants
- Zero AI-triggered business writes during read-only Copilot phase.
- Zero unauthorized or cross-tenant operations; no caching/replay leak.
- Exactly one business side effect per approved idempotency key despite concurrent workers or client retries.
- No auto-approval based on text, model confidence or previous approval for changed payload.
- No hardcoded GST, fabricated pricing, bypassed credit or stock policy.
- No direct model SQL/ORM insert; command service calls only.
- Correct undo/reverse method through ERP services, not arbitrary row deletion.

## Scenario acceptance examples
- `Create order for ABC`: multiple matches → ask choice and remember confirmed ID.
- `100 units cotton` with `box` inventory UOM → enforce valid UOM conversion and round rules.
- `Buy same invoice twice`: duplicate detection prevents second posting.
- `Goods receipt already posted`: bill posting must not produce duplicate stock movement.
- `Invoice partly paid`: payment must allocate correctly, balance and status sourced from ERP.
- `Company changes during approval`: old proposal no longer executable.
- `Model unavailable after posting`: status lookup returns receipt; retry does not duplicate.
- `AI says 18% GST`: domain service determines rate; mismatched model claim flagged/ignored.

## Release evidence
Exact commit SHA, installed package versions, commands, all PHPUnit totals/skips/riskies, DB schema evidence, source-vs-AI money comparisons, provider fixture and budget, negative security matrix, browser screenshots, performance/latency, rollback results and sign-offs. Never claim full pass when only AI subset passes. Prevent weakening existing test expectations solely to get green CI.

## Reference scenario catalogue
See `35_SCENARIO_EVALUATION_CATALOG.md` for a test-by-test release matrix covering chat, master data, purchasing, sales, inventory, accounting, documents and background automation.


---

## V3 mandatory setup and lifecycle regression
Use `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md` for full first-run tests. Include installation-admin vs group-admin scope; first company where no `CompanyContext` exists; duplicate-create retry; existing master preservation; industry/subtype units and capabilities; FY series/COA/GST provisioning; safe product and party matching; multi-step onboarding recovery; and final readiness gate. Confirm that the current AI has no `company.create` write capability until its new registry is implemented.

Lifecycle tests must include draft edit and reapproval, submitted amendment allowed/denied, posted invoice direct edit denied, returned order correction, partial-paid invoice, existing stock/ledger postings, closed FY, stale status at execution, revision invalidates approval, concurrent user edit and no duplicate reversal. Assert **zero unauthorised DB changes** and real ERP receipt for permitted transitions.
