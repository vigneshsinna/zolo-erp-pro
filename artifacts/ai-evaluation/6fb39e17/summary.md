# zoloERP AI Credit Metering & Automated Evaluation Report

- **Git Commit:** `6fb39e17f15cbaf15567a3601bf31471a356c93e` (`6fb39e17`)
- **Evaluation Date:** `2026-11-16T17:42:04+00:00`
- **Evaluation Tier:** **Automated CI Invariant & Security Verification** (Synthetic Media & Fixture-Mocked Perception/Reasoning Responses; Zero Cloud Spend)
- **Credit Policy Version:** `cost_v1` (1 credit = ₹0.01 INR paisa; 100 credits = ₹1.00)
- **Dataset Version:** `2026.11-v1`
- **Overall Verdict:** **PASSED_READY_FOR_DEPLOYMENT (250 / 250 Scenarios Passed)**

> [!NOTE]
> **CI vs Live Provider Execution (Test Plan Section 8.1 & 16):**
> These automated test suites execute against the real, authoritative ERP services (`ApprovedModelGateway`, `AiCreditMeter`, `CompanyContextResolver`, `GstCalculationService`, `GeneralLedgerService`) and MySQL row locks using synthetic media and recorded/fixture responses. This verifies accounting invariants, concurrency locks, and zero-write safety barriers deterministically without incurring unbounded live API charges ($15–$20 per CI run).
> Live cloud provider execution (direct to NVIDIA NIM / Gemini API) is maintained as a separate, explicitly budgeted manual/nightly opt-in benchmark (`bash scripts/run_ai_benchmark.sh --provider=nvidia --budget-cap-usd=5.00`).

---

## 0. Automated CI Test & Evidence Verification

| Evidence Artifact | Target Source | Records Verified | Status |
|---|---|:---:|:---:|
| **Full PHPUnit AI Test Suite** | `artifacts/junit-ai.xml` | 294 tests, 3241 assertions (0 errors, 0 failures) | **PASSED** |
| **Authentic Scenario Evidence** | `artifacts/scenario-events.jsonl` | 250 / 250 scenario execution events recorded | **VERIFIED** |
| **Prepaid Safety & Ledger Suite** | `tests/Feature/Ai/Billing/PrepaidCreditSafetyTest.php` | 7 / 7 passed, 39 assertions | **PASSED** |
| **Concurrent Load & Settle** | `tests/Feature/Ai/Billing/ConcurrentCreditLoadTest.php` | 5 / 5 passed, 56 assertions | **PASSED** |
| **Provider Gateway Quota Suite** | `tests/Feature/Ai/Gateway/ProviderGatewayRateLimitAndSettlementTest.php` | 4 / 4 passed, 28 assertions | **PASSED** |
| **Security & Tenancy Suite** | `tests/Feature/Ai/Billing/CreditSecurityTest.php` | 7 / 7 passed, 22 assertions | **PASSED** |

---

## 1. Model Configuration & Pricing Rate Cards

| Role | Authoritative Model | Billable Rates (USD) | Effective Unit Cost (INR @ 84.50) |
|---|---|---|---|
| **Visual Document Perception** | `nvidia/nemotron-3-nano-omni-30b-a3b-reasoning` | In: $0.00000030 / token<br>Out: $0.00000090 / token<br>Image: $0.00150000 / image | ~₹0.1914 per single-page bill (~20 credits)<br>~₹0.3557 per multi-page bill (~36 credits) |
| **Copilot Tool Reasoning** | `nvidia/nemotron-3.5-lightning-30b-a3b` | In: $0.00000020 / token<br>Out: $0.00000060 / token | ~₹0.0179 - ₹0.0195 per step (~2 credits) |

---

## 2. Release Gates & Audit Invariants

| Quality & Security Gate | Metric / Invariant | Result | Status |
|---|---|:---:|:---:|
| **Total Test Scenarios** | 250 automated fixtures | 250 / 250 | **PASSED_READY_FOR_DEPLOYMENT** |
| **Suite A: Indian Invoices** | 100 bills (10 families × 10 variants) | 100 / 100 | **PASSED** |
| **Suite B: ERP Copilot Requests** | 100 natural language intents (5 families × 20 cases) | 100 / 100 | **PASSED** |
| **Suite C: Difficult Edge Cases** | 50 adversarial & stress inputs (10 risk groups × 5 cases) | 50 / 50 | **PASSED** |
| **Suite D: Concurrency Load** | 5, 10, 20 VUs on internal credit metering engine | 0 overspend | **PASSED** |
| **Suite E: Security & Tenancy** | SEC-01 through SEC-20 security rules | 20 / 20 | **PASSED** |
| **Suite F: Ledger Accounting Rules** | CR-01 through CR-25 invariants | 0 discrepancy | **PASSED** |
| **GSTIN Checksum Invariant** | 100% of nonblank supplier GSTINs pass Modulo-36 check digit | 90 / 90 valid | **PASSED** |
| **Zero Unapproved ERP Writes** | Ledger/Inventory immutable prior to review | 0 mutations | **PASSED** |
| **Zero Cross-Tenant Leakage** | CompanyContextResolver server enforcement | 0 leaks | **PASSED** |
| **Zero Negative Balances** | Atomic preflight reservation hold | 0 negative | **PASSED** |

---

## 3. Test Suite Breakdown

### Suite A: 100 Indian Purchase Bills (`BILL-001` .. `BILL-100`)
- **Document Families Covered (10 items each):**
  - `F01`: B2B Intrastate Taxable Goods (CGST + SGST equal split, 0 IGST)
  - `F02`: B2B Interstate Taxable Goods (IGST only, 0 CGST/SGST)
  - `F03`: Multi-Rate Items (Mixed 0%, 5%, 12%, 18%, 28% GST)
  - `F04`: Line-Level & Header Discounts (₹100 header discount explicitly captured in extracted fields; reconciled with subtotal)
  - `F05`: Round-Off, Freight & Decimal Quantities (₹150 freight charges explicitly captured in extracted fields; reconciled with grand total)
  - `F06`: Unregistered Suppliers (Nullable GSTIN, reverse charge check)
  - `F07`: Debit Notes & Supplier Adjustments
  - `F08`: Multi-Page Invoices (Page continuation, carry-forward totals)
  - `F09`: Semi-Handwritten Numeric Bills (Validation & threshold alerts)
  - `F10`: Dense Industrial / Textile Item Schedules (Includes 50-line `BILL-098`, 75-line `BILL-099`, and 100-line `BILL-100` invoices with dynamic canvas sizing up to 2,680px)
- **10 Synthetic Visual Variants:** A4 clean, thermal narrow, perspective skew, low-light blur, low-resolution scan, multiple summary boxes, bilingual labels, overstamps, dense grids, repeated headers.
- **GSTIN Checksum Verification:** Every registered supplier GSTIN strictly passes the Indian Modulo-36 Luhn algorithm implemented in `App\Services\Tax\Gstin::normalize()`. Family F06 correctly validates the unregistered supplier exemption with null GSTIN.
- **Header Adjustments & ERP Tax Invariant:** All 100 invoices strictly satisfy the ERP Section 15 tax formula:
  $$	ext{Grand Total} = 	ext{Subtotal} - 	ext{Header Discount} + 	ext{Freight} + 	ext{CGST} + 	ext{SGST} + 	ext{IGST} + 	ext{Round Off}$$
- **Live Multimodal Model Benchmark:** Genuine dispatch of `BILL-001.png` to `nvidia/nemotron-3-nano-omni-30b-a3b-reasoning` verified live API extraction with 91.67% accuracy, 1,600 input tokens, 475 output tokens, and full arithmetic validity (persisted at `artifacts/ai-evaluation/live-benchmarks/live_omni_benchmark_BILL-001.json`).

### Suite B: 100 ERP Copilot Invocations (`COP-001` .. `COP-100`)
- **Workflow Family Distribution (5 families × 20 parameterized scenarios = 100 cases):**
  - **Purchase Orders:** 20 items (`COP-001` .. `COP-020`)
  - **Sales Orders:** 20 items (`COP-021` .. `COP-040`)
  - **Product Master Creation:** 20 items (`COP-041` .. `COP-060`)
  - **Supplier Master Creation:** 20 items (`COP-061` .. `COP-080`)
  - **Corrections & Proposal Revisions:** 20 items (`COP-081` .. `COP-100`)
- **Proposal Lifecycle & Governed Execution:** Each Copilot invocation demonstrates proposal UUID generation, staged draft slots (`is_draft: true`), human approval gate requirement (`requires_approval: true`), and `execution_receipt: null`, guaranteeing zero unapproved ledger mutations prior to human sign-off.

### Suite C: 50 Difficult Edge Cases (`EDGE-001` .. `EDGE-050`)
- **Authentic ERP Problem Payloads Evaluated (10 risk groups × 5 cases = 50 items):**
  - `EDGE-001..005`: Missing SKUs / uncataloged items (e.g. Egyptian Cotton 120s, 400GSM Thermal Fleece, R-134a Gas) $	o$ Product lookup returns null; halts before PO creation (422 `PRODUCT_NOT_FOUND`).
  - `EDGE-006..010`: Ambiguous supplier names (e.g. Sharma Enterprises, Sri Krishna Traders) $	o$ Refuses silent auto-selection; requires clarification (422 `AMBIGUOUS_ENTITY`).
  - `EDGE-011..015`: Incompatible UOMs (e.g. Kilograms for discrete water cans, Liters for sewing needles) $	o$ Rejects with 422 `INVALID_UOM`.
  - `EDGE-016..020`: Invalid GSTIN checksum / state code / regex syntax $	o$ Rejects with 422 `TAX_DETERMINATION_FAILED`.
  - `EDGE-021..025`: Unauthorized warehouse zone / inactive depot $	o$ Fails closed with 403 `WAREHOUSE_FORBIDDEN`.
  - `EDGE-026..030`: Insufficient stock / negative inventory attempt $	o$ Halts execution before stock movement (422 `INSUFFICIENT_STOCK`).
  - `EDGE-031..035`: Posting voucher in closed fiscal year $	o$ Denied before ledger booking (422 `PERIOD_CLOSED`).
  - `EDGE-036..040`: Prompt injection in input text / SQL injection in entity names $	o$ Sanitized parameter binding; treated strictly as untrusted string literal; 0 unapproved writes (200 `PROMPT_INJECTION_DEFENSE`).
  - `EDGE-041..045`: Provider 429 rate limit / quota exhaustion $	o$ Triggers exponential backoff and releases reserved credit holds (429 `PROVIDER_RATE_LIMIT`).
  - `EDGE-046..050`: Replaying conflicting payload on identical idempotency key $	o$ Rejects with 409 `IDEMPOTENCY_CONFLICT`.

---

## 4. Credit Metering & Ledger Reconciliation

| Metric | Recorded Value | Reconciled Status |
|---|---:|:---:|
| **Active Billing Period** | November 2026 (`prd_202611_active`) | Active |
| **Included Monthly Allowance** | 20000 credits (₹200.00 value) | Verified |
| **Top-Up Credits Purchased** | 15000 credits (₹150.00 value) | Verified |
| **Total Available Allowance** | 35000 credits | Verified |
| **Invoice Perception Credits Debited** | 2260 credits | 100 Bills @ ~20-36 credits |
| **Copilot Reasoning Credits Debited** | 284 credits | 100 Cases @ 2-4 credits |
| **Total Credits Debited (CSV & JSONL)** | **2544 credits** | **100% Cross-File Match** |
| **Total Ledger Debits Recorded** | **2544 credits** | **100% Cross-File Match** |
| **Reserved Holds In-Flight** | 0 credits | Cleared |
| **Net Available Balance** | **32456 credits** | **1:1 Matched** |
| **Audit Discrepancy** | **0 credits** | **Zero Variance** |

---

## 5. Concurrency & Performance Benchmark (k6)

> [!IMPORTANT]
> **Subsystem Scope & Provider Decoupling:**
> This load test targets the internal ERP Credit Metering and Reservation APIs (`GET /api/v1/ai/credits/balance`, `GET /api/v1/ai/credits/packs`, `GET /api/v1/ai/credits/estimate`, `POST /__test/ai/credit-reserve-settle`, and `AiCreditReservationService::hold()`).
> External model provider dispatches (e.g. NVIDIA NIM 35 RPM limit) are decoupled and governed downstream by `ApprovedModelGateway`'s rate limiter (`RateLimiter::attempt('ai:rpm:nvidia', 35)`).
> Full end-to-end provider gateway resilience is verified by `ProviderGatewayRateLimitAndSettlementTest` (4/4 tests passed, 28 assertions), which validates:
> 1. Strict 35 RPM rate-limiting enforcement on the NVIDIA bucket (35 requests admitted, 36th returns 429).
> 2. Complete release of credit reservation holds with zero ledger debits upon provider failure.
> 3. Atomic, idempotent credit settlement and ledger debits upon successful response.
> 4. Automatic cleanup of dangling holds upon retry exhaustion.
> The benchmark below exercises the internal database engine, MySQL row-locking (`lockForUpdate()`), and connection pooling under multi-user enterprise load, demonstrating 1,200+ requests/minute capacity without deadlocks or credit overspend.

| Scenario Profile | Virtual Users (VUs) | Requests | Requests / Minute | p50 Latency | p95 Latency | Error Rate | Overspend |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Profile L01** | 5 VUs | 50 | 1606 req/min | 36.57 ms | 46.95 ms | 0.00% | None |
| **Profile L02** | 10 VUs | 200 | 1597 req/min | 36.89 ms | 47.53 ms | 0.00% | None |
| **Profile L03** | 20 VUs | 500 | 1598 req/min | 36.81 ms | 47.01 ms | 0.00% | None |

---

## 6. Security & Tenancy Rules Verification (SEC-01 .. SEC-20)

All 20 security rules from the architectural specification were verified with concrete HTTP response codes, assertion counts, and database before/after mutation checks:

| ID | Security Rule | Target Method / Endpoint | HTTP / Result | DB Mutations Blocked | Verdict |
|---|---|---|:---:|:---:|:---:|
| **SEC-01** | Tenant and user credit history isolation | `GET /api/v1/ai/credits/usage?user_id={user_b}` | 200 (Filtered) | 0 foreign leaks | **PASSED** |
| **SEC-02** | Cross-tenant context header override attempt | `GET /api/v1/ai/credits/balance` [Header: X-Company: 999] | 200 (Context=1082) | 0 overrides | **PASSED** |
| **SEC-03** | Cross-company credit wallet debit attempt | `CreditAccountResolver::assertCompanyBelongsToAccount()` | 403 Forbidden | 0 debits | **PASSED** |
| **SEC-04** | Parent-subsidiary shared wallet isolation | `CreditAccountResolver::attachCompany()` | 200 (Mapped) | Explicit map only | **PASSED** |
| **SEC-05** | Branch/FY mismatch during action | `CompanyWriteGuard::assertBranchAndFiscalYear()` | 422 Unprocessable | 0 writes | **PASSED** |
| **SEC-06** | User lacking ai.use permission | `POST /api/v1/ai/chat` | 403 Forbidden | 0 model calls | **PASSED** |
| **SEC-07** | Global AI or multimodal switch off | `ApprovedModelGateway::admitPerceptionDispatch()` | 403 Forbidden | 0 dispatches | **PASSED** |
| **SEC-08** | User edits proposal after approval | `ActionProposalService::applyChangeRequest()` | 200 (New v2) | v1 invalidated | **PASSED** |
| **SEC-09** | Actor self-approves restricted action | `ActionApprovalService::approve()` | 422 Unprocessable | 0 approvals | **PASSED** |
| **SEC-10** | Concurrent duplicate proposal approval clicks | `ActionApprovalService::approve()` [Double Click] | 409 Conflict | Exactly 1 purchase | **PASSED** |
| **SEC-11** | Prompt injection says "post without approval" | `POST /api/v1/ai/chat` [Injection Payload] | 200 (Literal) | 0 unapproved writes | **PASSED** |
| **SEC-12** | Forged webhook HMAC signature | `POST /api/v1/billing/webhooks/razorpay` | 403 Forbidden | 0 credit grants | **PASSED** |
| **SEC-13** | Replaying same payment webhook 100x | `AiTopupService::recordPaymentSuccess()` | 200 (Idempotent) | Exactly 1 grant | **PASSED** |
| **SEC-14** | Concurrent holds exceed available wallet balance | `AiCreditReservationService::hold()` | 402 Payment Req | 0 overdraft | **PASSED** |
| **SEC-15** | Webhook callback after company deactivation | `POST /api/v1/billing/webhooks/razorpay` | 422 Unprocessable | 0 auto-grants | **PASSED** |
| **SEC-16** | Unapproved model ID in request | `ApprovedModelGateway::resolveModel()` | 422 Unprocessable | 0 dispatches | **PASSED** |
| **SEC-17** | Unknown provider rate card or pricing missing | `ModelCostCalculator::calculate()` | 422 Unprocessable | 0 unmetered calls | **PASSED** |
| **SEC-18** | Cross-tenant private media URL guessing | `GET /api/v1/ai/media/download/{tenant_b_id}` | 403 Forbidden | 0 media leaks | **PASSED** |
| **SEC-19** | Telemetry and trace redaction of secrets/PII | `AiAuditRecorder::recordInvocationTrace()` | 200 (Redacted) | 0 secret leaks | **PASSED** |
| **SEC-20** | Replaying settled provider attempt ID | `AiCreditSettlementService::settle()` [Twice] | 200 (Idempotent) | 0 double debits | **PASSED** |

---

## 7. Evidence Artifact Index

All generated machine-readable test evidence files are stored in `artifacts/ai-evaluation/6fb39e17/`:
- `summary.json`: High-level evaluation metrics, model rate cards, and gate status.
- `summary.md`: This comprehensive verification report.
- `invoice-100-results.jsonl`: Line-by-line verification outcomes, image paths, tokens, and expected vs extracted fields for all 100 Indian purchase bills.
- `copilot-100-results.jsonl`: Step results, intents, proposals, and zero unapproved write receipts for all 100 Copilot scenarios (5 families × 20 cases).
- `difficult-50-results.jsonl`: Safeguard, barrier, and execution traces for all 50 authentic difficult scenarios.
- `credit-ledger-reconciliation.json`: Exact mathematical audit of wallet ledger vs active period balance (0 credit discrepancy).
- `concurrency-5-10-20.json`: Internal credit metering engine concurrency, latency, and throughput data.
- `security-tests.json`: Detailed execution evidence for SEC-01 through SEC-20 security rules with HTTP codes and assertion counts.
- `failed-scenario-ids.txt`: List of failed scenario IDs (empty on successful evaluation).
- `model-cost-and-credit-usage.csv`: Granular token, USD, INR, and credit debits across all dispatches (reconciling 1:1 with the ledger).
- `artifacts/ai-evaluation/live-benchmarks/live_omni_benchmark_BILL-001.json`: Actual live NVIDIA Nemotron 3 Nano Omni execution on `BILL-001.png` comparing model extraction directly against ground truth.
