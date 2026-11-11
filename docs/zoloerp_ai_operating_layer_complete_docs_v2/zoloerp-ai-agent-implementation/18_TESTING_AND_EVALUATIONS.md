# 18 — Quality Engineering, Golden Data and Adversarial Tests

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

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
