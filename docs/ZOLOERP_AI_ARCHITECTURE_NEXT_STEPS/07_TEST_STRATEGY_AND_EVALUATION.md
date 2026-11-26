# 07 — Test Strategy, Agent Evaluation and CI Release Gates

**Test principle:** a feature is not “done” because its class, migration or test case exists. Demonstrate realistic, repeatable end-to-end behavior with evidence tied to the exact release SHA.

## 1. Current repository foundations

- `.github/workflows/ai-credit-evaluation.yml`: named “AI Credit Metering & Release Evaluation”; includes PHP lint, MySQL/Redis test services, full AI PHPUnit suite and evidence export.
- `tests/Feature/Ai/Evaluations/IndianInvoiceCorpusTest.php`: 100-case invoice manifest check; sample document-family tests are more limited than 100 paid real extractions.
- `tests/Feature/Ai/Evaluations/ErpCopilotCorpusTest.php`: 100-request manifest, fixture/model dispatch and zero unapproved-write checks.
- `tests/Feature/Ai/Evaluations/DifficultScenarioCorpusTest.php`: difficult inputs.
- `tests/Feature/Ai/Commercial/PurchaseBillActionTest.php`: purchase bill preview/approval/stock/GRN and source-state safety.
- `tests/Feature/Ai/Multimodal/NormalizedInputWorkflowTest.php`, `InvoiceExtractionTest.php`: media and workflow tests.
- `tests/Browser/Ai/multimodal-workspace.spec.mjs`, `drift-workspace.spec.mjs`, `workspace-merge.spec.mjs`: browser scripts.
- `app/Ai/Evaluation/ScenarioEvidenceRecorder.php`, `ExecutedEvidenceReport.php`: evidence structures and verification.

**Caution:** Manifest of 100 invoices is not equivalent to executing 100 real Omni extractions. Release report must label tests `fixture`, `recorded`, `mocked`, `sandbox_live_provider` or `production_observed` without overclaiming.

## 2. Test layers and gates

| Layer | Tests | Required gate |
|---|---|---|
| Unit | Resolver ranking, DTO schemas, state transition, pricing/rounding, credit calculator | All passing |
| Feature/API | Authorization, tenant scope, correction API, proposals, approvals, MCP, credits | All passing |
| Domain integration | Purchase bill/PO/GRN, ledger, stock, GST, duplicate avoidance | All passing |
| Browser / accessibility | Single bill card, picker, new warehouse panel, refresh, mobile, keyboard | All critical scenarios passing |
| Async & concurrency | 5/10/20 users, double submit, queue retry, credit contention | No overspend, no duplicate writes |
| Security | IDOR, prompt injection, cross-tenant, signed approval tamper, malicious source | Zero critical leaks/bypass |
| Model quality | Actual provider invoice extraction, text/voice intent, field accuracy | Meet agreed accuracy target |
| Release smoke | Staging+production canary with safe synthetic data | All critical checks passing |

## 3. Required ERP user-journey tests

### E2E-01 — One unique eligible warehouse

1. Seed current company/branch with a single eligible, configured warehouse.
2. Upload invoice image/PDF with supplier, tax and line items.
3. Observe one unified review card, warehouse automatically selected with reason, no separate extraction/handoff approvals.
4. Verify preview values from ERP adapter (not arbitrary model totals).
5. Click `Approve & Create`, verify one purchase bill, one receipt and correct ledger/stock impact.

### E2E-02 — Ambiguous warehouses

1. Seed multiple eligible warehouses with similar names.
2. Ensure `RecordResolutionService` returns candidate status, not `first()`.
3. Open side picker, search, select a warehouse by ERP ID.
4. Server revalidates; amount/tax/branch consequences update in proposal.
5. Approval unavailable until all required ambiguities are resolved.

### E2E-03 — Missing warehouse

1. Seed no suitable warehouse and authorize `setup.warehouse.create.v1` + relevant permissions.
2. Open “Create new warehouse” from the same bill card.
3. Confirm duplicate check, required attributes and master proposal preview.
4. Before approval verify **zero** warehouse/bill writes.
5. Approve master creation, verify parent task bound to newly created warehouse and resumed.
6. Approve final bill separately; verify correct transaction and no duplicate work.

### E2E-04 — Edit and revalidate

Change supplier, warehouse, product/UOM, quantity, invoice date and invoice number. Verify `PATCH .../inputs` sends `{expected_revision, corrections:[...]}`. Stale revision gets 409 and guided reload. Previously approved hash cannot execute a mutated proposal.

### E2E-05 — GRN-linked bill

Create valid GRN in a test company, then approve a purchase bill linked to it. Verify GRN accrual settlement, no duplicate physical stock receipt and correct AP postings.

### E2E-06 — Provider/network recovery

Timeout or 429 during extraction, transient DB error and browser refresh. Keep existing media/task; never create duplicate bills or incorrectly debit credits.

## 4. Indian document quality matrix

Cover **100 distinct bills** or more, tagged by source and characteristics:

- GSTIN, state/place of supply, intra-state CGST+SGST vs inter-state IGST.
- HSN/SAC per item, tax-inclusive/exclusive amounts and credit/module distinctions only if supported.
- Percentage and absolute discounts, line vs document totals, round-off, freight/charges.
- Different units (kg, mtr, pcs, box) with valid conversion and product UOM restrictions.
- Low quality scans, skew, handwritten values, stamps, multilingual labels, long/small invoices, multi-page PDFs.
- Duplicate invoice number, supplier mismatch, cancellation/credit note, zero-rated/exempt/unregistered supplier conditions.
- Extra lines, mixed currencies and unsupported source layouts must fail/clarify safely, not silently coerce.

**Quality measures:** exact-match extraction per field (supplier invoice number, GSTIN, date, quantities, unit prices, tax rate, total), numeric tolerance rules **specified before testing**, ambiguous/confidence rates, manual corrections and unauthorized-write count. Do not assume all 100 images actually exist/live-tested because a manifest lists 100 items.

## 5. 100 Copilot request suite

Distribute across purchasing, sales, products, suppliers, accounting, inventory, corrections and missing-master flows. Verify:

- Intent/action-key accuracy and allowed tool selection.
- No direct financial write from LLM-generated text.
- Correct slot extraction and clarification across complete vs partial requests.
- Correct company/branch/FY and permission boundaries.
- No fabricated success receipts, item IDs or supplier IDs.
- Model call count, credit settlement and latency per scenario.

## 6. 50 difficult requests

At least 50 cases covering ambiguous suppliers, missing products, duplicate warehouses, wrong unit, unknown HSN, conflicting GSTIN, OCR mismatch, no branch warehouse, unauthorized role, stale approval, cross-company media, invoices with prompt injection, concurrency and multiple changing corrections.

Every test must record expected outcome: `preview`, `clarify`, `deny`, `safe_failure` or `approved_execution`. Failing closed can be a **pass** where input isn't valid.

## 7. Security tests that must fail closed

| Attack | Required result |
|---|---|
| Forged `warehouse_id` from other company | Deny; no metadata leakage |
| Forged media/task/proposal UUID | Deny or 404; no operation |
| Altered proposal JSON/hash or stale version | Deny execution |
| User approves after losing permission | Deny |
| Repeat approval/execute button with same key | Exactly one business receipt |
| Malicious invoice instructs to skip approval | Treated as untrusted content |
| External MCP requests an unlisted tool | Deny |
| Remote URL supplies internal IP / redirects | Deny outbound MCP request |
| Attempt to exhaust credit with 20 concurrent requests | No negative credits/overspend |
| Bad PDFs, spoofed MIME and oversized media | Early rejection without ERP writes |

## 8. CI and evidence artifacts

For each qualifying PR/release create immutable evidence tied to the specific SHA:

```text
artifacts/ai-evaluation/<sha>/
  baseline.json
  phpunit-junit.xml
  fixture-scenario-results.jsonl
  live-provider-results.jsonl      # only when explicitly run and labeled live
  playwright-report/
  performance-summary.json
  security-summary.json
  credit-ledger-reconciliation.json
  deployment-smoke.json
  release-decision.md
```

Evidence must include test count, pass/fail/skip, environment, provider/model, commit SHA, limitations and whether paid live calls were used. Redact identifiers and receipts. A failed or skipped critical test blocks release; do not silently mark incomplete reports green.

## 9. Commands (verify scripts/config first)

```bash
# Review actual scripts and PHPUnit suites
cat phpunit.ai.xml
cat package.json
php artisan route:list --path=ai

# Examples; adapt to repository setup
php vendor/bin/phpunit -c phpunit.ai.xml
php artisan test --filter=PurchaseBillActionTest
php artisan test --filter=NormalizedInputWorkflowTest
# Use the Playwright command defined in package.json; do not guess its setup.
```

CI workflow `.github/workflows/ai-credit-evaluation.yml` can be extended with browser/security/release gates. Paid live-provider benchmarks should be **opt-in** and carry cost/credit controls; never trigger them on every untrusted PR.

## 10. Definition of done

- All authorized and unauthorized scenarios have explicit outcomes.
- No unsafe master or financial write without signed approval.
- No duplicate AP/stock/ledger entries after retries.
- Field extraction quality meets pre-approved metric threshold with real provider evidence.
- 100/100 invoice fixtures, 100/100 Copilot cases and 50/50 difficult cases are not just listed but have meaningful traceable execution evidence **or clearly reported as not executed**.
- Chrome/Edge desktop and narrow viewport accessibility flows pass.
- CI release decision correctly goes red when critical evidence is missing.
