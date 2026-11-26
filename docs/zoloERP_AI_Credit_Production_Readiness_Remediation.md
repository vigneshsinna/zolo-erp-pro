# zoloERP Pro — AI Credit Metering Production-Readiness Remediation Plan

**Document type:** Implementation-ready engineering handoff  
**Prepared:** 16 November 2026  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Target branch:** `feature/ai-unified-input-clarification`  
**Reviewed HEAD:** `a97391c829f8d60ed24b322dd934eded630fc4f0` (verified identical to the branch during this review)  
**Framework:** Laravel / PHP, MySQL and the existing AI Gateway / company authorization / approval model  
**Scope:** Remediate the unresolved credit-safety, evaluation-evidence, security, concurrency, CI, and live-provider benchmarking findings. **This document is a recommendation, not completed code or verified passing CI.**

> **Release decision:** Do not enable customer-paid AI billing until the P0 issues and their reproducible tests pass on the exact commit intended for release. A local/generated `PASSED_READY_FOR_DEPLOYMENT` JSON value is not deployment authorization.

**17 November implementation update:** The exporter now reads JUnit, the full PHPUnit discovery plan and run-bound dataset events without booting Laravel or writing database records. It rejects missing, skipped, duplicate, stale, partial or mismatched evidence. The 250 events establish dataset validation, not 250 completed commercial workflows. It no longer manufactures model usage, latency, credits, ledger reconciliation or security passes. Unmapped security rules remain `NOT_EVIDENCED`; simultaneous reservation testing, run-bound ledger reconciliation and live-model validation remain separate unfinished gates. Automated checks can pass while the release gate remains `INCOMPLETE`. The findings below describe the reviewed baseline unless this update explicitly supersedes them.

---

## 1. Executive summary — what must change

The latest branch contains a real central credit-metering implementation, model gateway, billing services, test corpus and test classes. The five prior findings have received code changes. However, several remaining gaps mean that customer charges, wallet safety and automated release evidence are **not yet sufficiently trustworthy**.

| ID | Priority | Finding in reviewed code | Required outcome |
|---|---|---|---|
| **P0-01** | **Critical** | `AiCreditSettlementService` debits full actual credits even when actual usage exceeds the reservation and remaining wallet capacity; `balance()` uses `max(0, ...)` and can conceal an underlying negative balance. | Reserve a valid provider-bounded worst-case amount before each attempt; isolate exceptional provider overages, never conceal or silently permit negative prepaid wallets, and stop new billable dispatches until reconciled. |
| **P0-02** | **Critical** | `scripts/export_ai_evaluation_report.php` constructs some outcomes directly: Copilot sets `$passed = true`, report counts and several security verdicts are hard-coded, and model usage for fixtures can be synthetic. | Consume actual test-run events / JUnit, verified database ledgers, and model outputs. Report mock and real runs separately. **Never invent a PASS or metric.** |
| **P0-03** | **Critical** | Report-export "5/10/20 VU" loops execute sequentially in one PHP process and compute throughput from loop timings; `performance/ai_credit_load.js` mainly exercises read-only GET endpoints. | Run genuine 5, 10 and 20 concurrent authenticated workers against a test-only, write-capable credit API and a fake provider; capture raw k6/DB/Redis evidence. |
| **P0-04** | **Critical** | Evaluation exporter uses `Company::firstOrCreate()` / `User::firstOrCreate()` and a known `secret-eval-2026` password without an evident test-environment precondition. | Forbid evaluation mutations in non-test environments; provision random credentials and isolated databases; never create privileged evaluation users in production. |
| **P0-05** | **Critical** | No AI-credit GitHub Actions workflow or successful check run was found for reviewed HEAD; the standalone benchmark's USD cap derives from local JSON artifacts plus a fixed estimated cost. | Add reproducible CI with JUnit/test artifacts and a robust paid-provider benchmark quota using server-side atomic reservations and actual cost reconciliation. |
| **P1-06** | High | Calculator uses BC Math, but converts to float to `round` INR minor units and `ceil` credits. Exporter uses a different rounded USD/INR pipeline. | One exact, versioned integer/decimal credit formula in both runtime and reports; eliminate the prior six one-credit inconsistencies; property and boundary tests. |
| **P1-07** | High | `ModelCostCalculator::estimatePreflightCredits()` rejects calls over a configured limit (good), but model token/media assumptions are not proven to be a true upper bound. | Explicit model/output limits, image/audio billing units, bounded retries, and per-provider price snapshots; fail closed when upper bound cannot be established. |
| **P1-08** | High | `ai_credits.enabled=false` returns a zero-charge stub; existing reservation idempotency returns prior reservation without comparing request payloads. | No unmetered customer/provider dispatch in production and strict idempotency payload binding. |
| **P1-09** | High | `sweepExpiredReservations()` releases holds for expired dispatches even when provider billability is uncertain. | Use a reconciliation state and documented provider-confirmation/timeout policy; never free a possibly billed attempt for uncontrolled retrial. |
| **P1-10** | High | Only one live Omni invoice benchmark (`BILL-001`) has been evidenced; it misread a critical GSTIN. Other 99 are predominantly synthetic/fixture-based. | Separate genuine multimodal accuracy benchmark from deterministic accounting tests; ensure invalid GSTIN or uncertain money fields never auto-post. |

### Verified source paths

- `app/Ai/Billing/Services/AiCreditReservationService.php`
- `app/Ai/Billing/Services/AiCreditSettlementService.php`
- `app/Ai/Billing/Services/ModelCostCalculator.php`
- `app/Ai/Billing/Services/AiCreditReconciliationService.php`
- `app/Ai/Billing/Services/AiCreditMeter.php`
- `app/Ai/Gateway/ApprovedModelGateway.php`
- `config/ai_credits.php`
- `scripts/export_ai_evaluation_report.php`
- `scripts/run_ai_benchmark.sh`
- `scripts/run_live_omni_benchmark.php`
- `performance/ai_credit_load.js`
- `phpunit.ai.xml`
- `tests/Feature/Ai/Billing/CreditLedgerRulesTest.php`
- `tests/Feature/Ai/Billing/ConcurrentCreditLoadTest.php`
- `tests/Feature/Ai/Billing/CreditSecurityTest.php`
- `tests/Feature/Ai/Evaluations/IndianInvoiceCorpusTest.php`
- `tests/Feature/Ai/Gateway/ProviderGatewayRateLimitAndSettlementTest.php`
- `artifacts/ai-evaluation/…`

**Do not recreate existing wallet or ledger tables without checking the current migrations.** Introduce additive changes only as needed, with rollback-safe behavior.

---

## 2. P0-01 — Guarantee prepaid credit safety during actual usage settlement

### Current behavior and the accounting problem

Reviewed settlement logic currently follows the equivalent of:

```php
$held = (int) $reservation->remaining_held_credits;
$debited = $creditsToDebit;              // full actual usage, good for audit
$overage = max(0, $debited - $held);
// spend is incremented even when wallet funds cannot cover the difference
```

Charging the full model cost fixes silent undercharging, **but it does not itself prevent overspending**. If a user has 10 credits left and a model uses 35 while only 10 were reserved, the provider has already run. You cannot retroactively stop that API call. The system must distinguish:

1. **Provider expense incurred** (always recorded, even when unexpected).
2. **Customer prepaid credit debit** (never silently more than prepaid funds under a no-overage plan).
3. **Unfunded provider liability / variance** (covered by the platform or explicitly contracted overage, not hidden).

### Required policy

- Prepaid customer **available credits may not go negative**. If an unexpected charge exceeds the original hold, seek an additional atomic hold against still-available credits **only under the same period and while the period is locked**.
- If sufficient funds remain, settle the full amount and mark `settled_with_extended_hold` (or equivalent audited state).
- If there are insufficient funds, settle only the affordable **customer credit debit** and write the remainder to a **separate provider-cost/unfunded-expense ledger** (not as a hidden customer debt). Mark wallet `reconcile_required` or `frozen_for_ai_dispatch`; alert operations.
- Do not discard actual provider usage. Ensure the total provider-cost record equals the sum of customer-funded cost + provider-cost variance.
- Never report a negative balance as zero without also exposing and alerting on the underlying invariant breach. Avoid `max(0, rawAvailable)` as the only internal representation.
- No automatic charging beyond prepaid credits without a deliberately enabled, customer-accepted postpaid contract.
- Freeze **new** AI dispatch on an unreconciled critical overage. Non-AI ERP functions remain usable.

### Recommended preflight flow

Reserve per **provider attempt**, not per user message:

```text
1. Authenticate actor and resolve subscription + mapped company.
2. Enforce AI permission, model allowlist, account status, media cap and rate limit.
3. Determine approved provider, exact rate-card version and FX version.
4. Compute a conservative MAX cost from:
   - actual tokenized prompt or safe documented upper bound,
   - enforced max_output_tokens,
   - expected image/page/audio/video units and provider pricing rules,
   - explicit retry policy (new hold per billable retry),
   - per-request maximum and tenant quota.
5. Reject if max cannot be bounded, price is unknown, or wallet cannot hold it.
6. Atomically reserve max credits using SELECT ... FOR UPDATE and unique idempotency key.
7. Dispatch one provider attempt; enforce the same output limit at provider API.
8. Record all token/media usage including failures, timeouts and retries.
9. Under lock: settle actual cost; release unused amount; record variance if over budget.
10. Return balance with source-of-truth ledger reconciliation and no negative prepaid amounts.
```

### Files to update

**`ModelCostCalculator.php`**

- Add `estimateMaximumCharge(ProviderCallSpec $spec, RateCardSnapshot $rate, FxSnapshot $fx): CreditEstimate` (sample name). Required fields: model/provider/purpose, token limits, image/audio unit caps, retry attempt identity and policy version.
- Assert the estimated maximum does not exceed `ai_credits.max_credits_per_request`. **Reject**; never silently `min()` the estimate.
- Reject unbounded or unsupported media billing shapes, unknown models/units, invalid/expired rate-card and missing FX snapshot.
- Use the **same** calculator method for runtime debit and test output. The quote shown to the user may be approximate, but dispatch reservation must use the conservatively bounded amount.

**`AiCreditReservationService.php`**

- Ensure the whole `(account_id, period_id, run_id, operation_key, provider, model, request_digest, policy_version, rate_version)` is bound to the idempotency key. Same key + different payload → `409 IDEMPOTENCY_CONFLICT`, **not** replay of an unrelated reservation.
- Enforce nonnegative `estimatedMaxCredits`; require active period/account; reject `reconcile_required` or frozen wallet.
- Lock wallet/period before checking spend, active holds and media entitlements. Use fixed lock acquisition order across all writers.
- Restrict `grace_overage_credits` to zero for prepaid customer plans unless an explicitly approved contract exists.

**`AiCreditSettlementService.php`**

- Validate reservation belongs to the expected account/period/model/attempt; unique provider attempt IDs and unique ledger IDs.
- Use distinct `provider_cost`, `customer_credits_debited`, `held_released`, `unfunded_provider_cost` accounting; record billable failed attempts when necessary.
- When actual exceeds held, attempt additional hold against true available credits inside the transaction. If insufficient, **never** increment customer `spent_credits` past grant/entitlement; record variance, freeze and reconcile.
- Read back `granted − spent − reserved − expired` and assert `>= 0` before transaction commit for a prepaid account; rollback and record an operational incident if inconsistent.
- Return explicit outcomes: `settled`, `settled_with_extended_hold`, `reconcile_required`, `provider_cost_unfunded`, `idempotent_replay`. Avoid allowing a normal `settled_with_overage` status to imply all invariants passed.
- Lock the reservation and period in a consistent order with the reserve/top-up services, and validate uniqueness constraints at database level.

**`AiCreditReconciliationService.php`**

- Do not automatically release an expired **in-flight** hold as though it were never billed. Classify `never_dispatched`, `provider_confirmed_not_billed`, `billed`, and `unknown_billability`.
- For `unknown_billability`, preserve a bounded liability/hold state, escalate and reconcile using provider request IDs; release according to approved risk policy only after documented evidence.
- Independently compare period counters with immutable ledger rows, payment grants, active holds and provider billable attempts. Emit an actionable incident ID for discrepancies.

### Suggested additive schema (only if existing tables cannot express the state)

`ai_provider_cost_variances`: `id`, `account_id`, `period_id`, `reservation_id`, `provider_attempt_id` unique, `provider`, `model`, `actual_cost_minor_inr`, `customer_funded_credits`, `unfunded_cost_minor_inr`, `rate_card_version`, `fx_version`, `incident_status`, `created_at`, `resolved_at`, `resolution_reason`.

No destructive migration. For any status enum/check constraint, migrate allowed values before emitting a new status.

### Acceptance tests

- **BILL-01:** Wallet 10 available, reserve 10, actual 35; consumer debit **≤ 10**, provider cost fully recorded, variance 25-credit-equivalent, account frozen/reconciling, no new model dispatch.
- **BILL-02:** Wallet 100 available, reserve 10, actual 35; atomically fund extra 25, debit 35, no negative balance, release correct residual.
- **BILL-03:** Two parallel settlements contend for the same remaining 20; combined debit never exceeds remaining wallet, no provider expenses lost.
- **BILL-04:** Unknown image pricing; request denied before provider execution.
- **BILL-05:** Output tokens exceed enforced API max due to malformed adapter; reject or classify provider-violation and record variance, not a clean success.
- **BILL-06:** Duplicate settled provider attempt returns same ledger ID, amount and status; one ledger debit only.
- **BILL-07:** A timeout with unknown billability remains unreconciled rather than becoming free.
- **BILL-08:** `ai_credits.enabled=false` in a production customer request must fail closed or require an explicitly scoped administrator-authorized no-charge policy; no general zero-credit bypass.
- **BILL-09:** Reusing same idempotency key with changed payload returns 409, with zero new provider attempts.
- **BILL-10:** Decimal boundary amounts (₹0.00999999, ₹0.01000000, ₹0.01000001) and versioned FX changes debit identically across runtime, CSV and ledger.

---

## 3. P1 — Make rate card and rounding calculations authoritative

### Current code to reconcile

In `ModelCostCalculator.php`, BC Math computes USD and INR but converts to PHP float for `cost_minor_inr` and for `ceil($totalInr / 0.01)`. The exporter has separately rounded `$usdCost` to 6 places and `$inrCost` to 4. Those pipelines can disagree near whole-credit boundaries.

### Preferred calculation

- Store model token/media prices and FX rate in **DECIMAL**, versioned by effective time/provider/model/fee unit.
- Use **BC Math or dedicated decimal arithmetic end to end**, including integer ceil; do not use intermediate binary floats for credit debit.
- Define **one** rounding rule: debit credits = `ceil(exact_cost_INR / 0.01)` for `cost_v1`, with explicit min-charge and zero-price policy. Keep `provider_cost_minor_inr` and `credits` as **separate** representations; never infer actual charge from the rounded credit count alone.
- Calculate provider image/audio billing from real provider usage units; do not charge both tokenized image and flat-image fee unless that provider actually bills both.
- Snapshot price and FX version **at reservation**, not once at report time; every debit references its exact snapshot.
- `rateCardMissing()` must apply to *required* units. For optional image/audio units not used by a request, zero is valid; for an invoice image request, missing image price is not.

### Decimal ceil pseudocode

```php
// Pseudocode only; implement exact decimal ceiling with bccomp/bcmod or a tested library.
$usd = exact_sum_of_billed_units($units, $rateCard);
$inr = bcmul($usd, $fxRate, $precision);
$creditsDecimal = bcdiv($inr, '0.01', $precision);
$debitCredits = decimal_ceil_to_int($creditsDecimal); // no float cast
```

### Tests

- Create a golden set for the **six previously disputed model calls** and thousands of randomized boundary values; compare exporter, runtime calculator and ledger under identical snapshots.
- Validate `0`, sub-paisa, exactly one paisa, large high-token request, very expensive image/audio, cached tokens, multiple images/pages, FX version change.
- Assert unknown model never silently uses a generic $/1M fallback; reject before dispatch.

---

## 4. P0-02 — Replace generated PASS reports with actual evidence

### Why the current exporter is not yet an independent validator

The reviewed `scripts/export_ai_evaluation_report.php` still constructs output from fixtures/manifests and assigns PASS in paths without executing the actual tested operation:

- Copilot scenario loop sets `$passed = true` and generates proposal IDs and usage records.
- Several security rows and suite summary values are reported as `PASSED` rather than collected from test assertions.
- Fixture-based `InvoiceExtractionV1` normalization is useful but is not a live OCR prediction. Only `BILL-001` links to a separately recorded live model output.
- A “reconciled” ledger is built from the exporter’s own CSV totals instead of **independently querying actual `ai_credit_ledger`, `ai_credit_periods` and `ai_model_invocations`**.
- Random latency values must not be shown as observed p50/p95/p99.

### Required redesign

Make the exporter an **evidence reader**, not an outcome simulator:

```text
Actual PHPUnit / Laravel feature tests (real DB, fake or live provider as tagged)
       ├── test assertions / failures (JUnit XML)
       ├── structured scenario events (JSONL: one event per tested scenario)
       ├── DB transaction identifiers + immutable ledger query snapshots
       ├── provider invocation IDs + actual usage from the adapter
       ├── k6 raw summary + per-profile metrics
       └── optional live-model comparison JSON
                       ↓
              Evidence aggregator
                       ↓
          verified summary + release gates
```

Introduce `App\Ai\Evaluation\ScenarioEvidenceRecorder` (or a test-support recorder) with a stable event schema:

```json
{
  "scenario_id": "BILL-001",
  "git_sha": "<actual SHA>",
  "test_run_id": "<UUID>",
  "test_file": "tests/Feature/Ai/Evaluations/IndianInvoiceCorpusTest.php",
  "execution_mode": "fixture_mocked_model",
  "status": "passed",
  "assertion_count": 12,
  "provider_attempt_ids": ["<attempt ID if executed>"],
  "ledger_entry_ids": ["<ledger ID if charged>"],
  "source_media_sha256": "<SHA-256>",
  "expected_snapshot_sha256": "<SHA-256>",
  "actual_output_sha256": "<SHA-256>",
  "latency_ms": 321,
  "error_codes": []
}
```

> This JSON is a schema illustration. None of these values may be fabricated. **If an item was never executed, omit the event or label it `not_run`; do not mark it PASS.**

### Required report groups

1. **Deterministic `fixture_mocked_model` CI:** proves validation, business rules, authorization, transaction safety and credit accounting with controlled model responses.
2. **`live_model_dispatch` benchmark:** proves actual image/text model response, latency, billed tokens and field correctness. Separate pass rate and sample size.
3. **`load_test` (k6):** real concurrent request measurements, raw k6 JSON and exact server build.
4. **`reconciliation`:** exact DB queries and linked provider attempts. Use account/period context; account for refunds, expiries and top-ups.
5. **`security`:** executed PHPUnit test names and assertions, HTTP results and real before/after DB state.

Each JSONL row must retain independent `expected`, `actual`, `match`, `test_source`, `is_synthetic`, `model`, `provider`, `attempt_id`, `run_id` and credit settlement references. Do not clone `expected_fields` into `extracted_fields` and then claim measured extraction accuracy.

### Release-gate derivation

- Compute `passed_scenarios` from **unique executed scenario IDs with real PASS test events**; fail on missing IDs, duplicates, skipped fixtures, incomplete tests, unverifiable references or exceptions.
- The `SEC-01..20` and `CR-01..25` summaries must list exact **PHPUnit test method → tested security rule** mappings. Do not assume 20 rules were executed because 20 entries exist in a JSON array.
- `ledger.is_reconciled=true` only after comparing DB aggregates and independent recorded provider attempts. Any mismatch must fail release gate.
- `PASSED_READY_FOR_DEPLOYMENT` must be emitted only by the protected CI gate after required jobs pass. Prefer distinct statuses: `UNIT_PASS`, `MOCKED_EVALUATION_PASS`, `LOAD_PASS`, `LIVE_BENCHMARK_PASS`, `PRODUCTION_GATE_PASS`.
- Never generate or output artificial latency, HTTP status, ledger ID, model tokens, proposal ID or voucher receipt as “observed.” Simulation data may be stored separately with an explicit `simulation` label.

### Tests for evidence integrity

- **EVD-01:** Delete one invoice scenario event → exporter must fail, not 99/100 incorrectly report 100/100.
- **EVD-02:** Change one assertion to failure → summary marks failed, GitHub job fails.
- **EVD-03:** Replace one model prediction with incorrect GSTIN → actual extraction mismatch and required review flag; no 100% field score.
- **EVD-04:** Alter an independent ledger posting → reconciliation fails, report cannot assert zero discrepancy.
- **EVD-05:** No k6 raw output → load test status `not_run`/`unverified`, not PASS.
- **EVD-06:** Only 1 live image executed → clearly report `live_model_samples=1`, never label it a 100-image model benchmark.
- **EVD-07:** Fail PHPUnit due to a fatal error / missing database → exporter fails closed, no new PASS artifacts.
- **EVD-08:** Security evidence references an unexecuted PHPUnit test method → fail build.

---

## 5. P0-03 — Genuine 5 / 10 / 20-user load and race-condition tests

### Current gap

`performance/ai_credit_load.js` is a valid start but currently calls balance, pack-catalog and estimate **GET** endpoints. It does not exercise competing credit holds and settlement. The exporter's "VU" profiles execute nested `for` loops in one PHP process, which does not create simultaneous database connections or test real row-lock contention.

### Proposed approach

1. Use **isolated** staging/CI MySQL and Redis with test account wallets and synthetic users. Never run charge-generating load on the real NVIDIA free endpoint or on real customers.
2. Add a non-production-only `/__test/ai/credit-reserve-settle` endpoint or run a dedicated worker RPC to the real reservation service. Register this route **only** in a test service provider when `APP_ENV=testing` plus a test secret. Ensure production `route:list` contains no such route.
3. Use a fake provider that can return deterministic 200, 429, 500, timeout, delayed response and variable usage. Track exact provider calls and billable retries.
4. For each profile run real **parallel workers** and hold concurrent transactions long enough to expose contention.
5. Use isolated (a) **same-wallet** requests, (b) independent-wallet requests, and (c) multiple authorized companies sharing one wallet. Never infer tenant isolation from separate wallets alone.
6. Capture raw k6 `--summary-export`, Influx/Prometheus metrics if available, MySQL lock wait/deadlock metrics, Redis limiter events and credit ledger snapshots.
7. Separate GET throughput from **POST hold/settle throughput** and **provider gateway throughput**; do not compare internal HTTP requests/minute with NVIDIA 35-RPM provider requests/minute.

### Required load profiles

| Profile | Concurrent VUs | Duration | Workload | Success criteria |
|---|---:|---|---|---|
| L01 | 5 | 2 min | ~50% reserve/settle, 25% balance, 25% mock model | No duplicate debits, no incorrect grants |
| L02 | 10 | 5 min | Same-wallet bursts, different users/companies | Locks are honored; no negative balance |
| L03 | 20 | 5 min | Same-wallet burst near exhaustion | Exactly affordable operations accepted; others structured 402/409 |
| L04 | 20 | 5 min | Fake provider 429/timeout/5xx and retries | Bounded retries, all attempt IDs accounted for |
| L05 | 20 | 3 min | Month-rollover plus top-up event and requests | One grant, stable period assignment, no double spend |
| L06 | 20 | 3 min | Replayed settlement IDs, repeated approval requests | One posting per unique attempt/action |

### k6 changes

`performance/ai_credit_load.js` should use per-VU user tokens, real POST requests and an explicit scenario tag. Use thresholds for `checks`, `http_req_failed`, p95 latency, failures expected from insufficient credits (count these separately, not as server errors) and reconciliation. Fail the CI job on overspend, duplicate provider attempts, unaccounted holds, missing VUs or missing raw results.

**Illustrative** k6 excerpt (adapt to the test endpoint and auth):

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

export const options = {
  scenarios: {
    five: { executor: 'constant-vus', vus: 5, duration: '2m', startTime: '0s' },
    ten: { executor: 'constant-vus', vus: 10, duration: '5m', startTime: '3m' },
    twenty: { executor: 'constant-vus', vus: 20, duration: '5m', startTime: '9m' },
  },
  thresholds: { checks: ['rate>0.99'], http_req_duration: ['p(95)<500'] }
};
export default function () {
  const token = __ENV.TEST_TOKENS?.split(',')[__VU - 1] || __ENV.TEST_TOKEN;
  const res = http.post(`${__ENV.BASE_URL}/__test/ai/credit-reserve-settle`,
    JSON.stringify({ idempotency_key: uuidv4(), max_credits: 10, fake_provider_delay_ms: 300 }),
    { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' } });
  check(res, { 'accepted or correctly insufficient': r => [200, 201, 402].includes(r.status) });
}
```

**Production security warning:** Do not deploy the illustrative endpoint or rely on externally hosted k6 helper imports in a locked-down CI without pinning/vendor review. Generate a UUID locally if possible.

### Pass thresholds

- **Zero** `raw_available < 0` for prepaid wallets; **zero** duplicate attempts, grants or debits.
- Every accepted call has a unique reservation + provider attempt or a proven idempotent replay.
- Each provider attempt is either settled or in a tracked reconciliation state; zero silent abandoned holds.
- 35-RPM NVIDIA limiter test: exactly as configured, per provider **across all app workers**, with provider calls distinguished from internal API traffic.
- p95 reservation performance target from staging baseline (initial target < 250 ms); no unmeasured blanket promise for external model latency.
- Raw load outputs match aggregate totals and exactly count the VUs/duration actually executed.

---

## 6. P0-04 — Secure evaluation scripts and test credentials

### Current risk

`scripts/export_ai_evaluation_report.php` calls `Company::firstOrCreate()` and `User::firstOrCreate()` and creates an evaluation account with a predictable password and `role_id=1`. Although designed for testing, that is unsafe if someone executes the exporter against a non-test database. `phpunit.ai.xml` also contains example database credentials and forces a test DB name; verify these credentials are **never** deployed to production or reused externally.

### Required controls

1. Use a dedicated `App\Support\AiEvaluation\AssertSafeEvaluationEnvironment` called **before** any DB write or model call by all evaluation/fixture generators, the exporter and benchmark scripts.
2. Require `APP_ENV=testing` (or a distinct explicitly approved sandbox environment) **and** `AI_EVALUATION_ENABLED=true` **and** an allowlisted test database name/prefix. Consider a separate CLI confirmation token for staging live benchmark.
3. Check the actual **connected database name and host** before writing; a local environment variable alone is insufficient if the DB DSN points to production.
4. If environment checks fail, exit non-zero **before** creating users, wallets, ledger rows or paying for model calls.
5. Do not create an evaluation admin by setting `role_id=1` blindly. Prefer factories with minimal scoped roles, random passwords, one-time tokens and explicit company/FY memberships. Limit the test principal to only what each scenario requires.
6. Clean test records using a dedicated test DB or tagged test run IDs; do not `delete all` production tables or purge other tenant data.
7. Keep API keys in GitHub Actions secrets/environment and ensure they are masked in logs; don't commit `.env`, image source PII, or raw model output containing secrets.
8. Prefer separate **test-only** commands (`ai:evaluation:run`, `ai:evaluation:export`) over a general script callable from deployed production directories.

### Example guard pseudocode

```php
private static function assertSafe(): void
{
    $env = app()->environment();
    $db = DB::connection()->getDatabaseName();

    if ($env !== 'testing'
        || ! config('ai_evaluation.enabled', false)
        || ! str_starts_with($db, 'zolo_test_')) {
        throw new RuntimeException('AI evaluations may run only against the isolated test database.');
    }
}
```

This code is illustrative: the real guard must also confirm host/connection identity and be enforced in all test entrypoints. For an intentionally permitted live staging benchmark, use a separate allowlist and credentials, **not** this test-only code path.

### Tests

- **SEC-E01:** `APP_ENV=production`, test command invoked → hard failure, **zero** new users or ledger rows.
- **SEC-E02:** `APP_ENV=testing` but DB DSN points to a non-test database → hard failure.
- **SEC-E03:** Missing `AI_EVALUATION_ENABLED` → hard failure.
- **SEC-E04:** Test user password never fixed; no user with `secret-eval-2026` created.
- **SEC-E05:** Dedicated test run cannot read other tenant documents/wallets.
- **SEC-E06:** CLI logs and GitHub artifacts contain no API token, password, private image URL or raw PII.

---

## 7. P0-05 — Reproducible GitHub Actions checks and trustworthy benchmark budgets

### Current gap

The repository currently has `company-foundation.yml` and `deploy.yml` in `.github/workflows/`, but no committed `.github/workflows/ai-credit-evaluation.yml` was found, and no successful AI-credit check run was visible for reviewed HEAD. An exporter log saying "all tests pass" is not equivalent to a GitHub Actions check with test reports.

### New workflow (proposed path)

`.github/workflows/ai-credit-evaluation.yml`

**Triggers:** PR targeting the integration branch and pushes to `feature/ai-unified-input-clarification`; optional manual `workflow_dispatch` for live-provider benchmark with explicit budget.

**Test jobs:**

1. **`static-and-schema`:** checkout pinned SHA, PHP version/extensions, `composer validate`, syntax lint (`php -l` for all changed PHP), Pint/static analyzer, migration sanity checks.
2. **`unit-tests`:** `php vendor/bin/phpunit -c phpunit.ai.xml --testsuite 'AI release checks' --log-junit artifacts/junit-ai.xml`; isolate fixtures.
3. **`db-integration`:** MySQL + Redis services; migrate fresh test DB; execute billing, gateway, tenant and human-approval tests with real transactions and row locks.
4. **`evaluation-corpus`:** run 100+100+50 deterministic fixture-driven tests, output actual event records; verify 250 scenario IDs all have executed assertions; require separate `fixture_mocked_model` label.
5. **`concurrency`:** start isolated Laravel API and fake provider, execute k6 5/10/20 VUs, export raw summary and compare wallet/ledger after load.
6. **`security`:** role/tenant/media access, webhook HMAC, approval replay, idempotent payment and negative balance tests; output JUnit.
7. **`evidence-and-gate`:** collect only completed job artifacts, independently reconcile ledger and provider logs, generate summary JSON/MD, and fail if any critical suite missing/failed.
8. **`live-provider-benchmark`**: **manual/nightly opt-in only**, with secret, approved vendor terms, cost budget and sanitized synthetic invoices. Results never inflate the deterministic 250/250 count.

**Workflow protections:** `permissions: contents: read`, concurrency group for destructive test DB, least privilege secrets, separate environments for staging paid API keys, limited artifact retention, branch/PR protection requiring relevant statuses, and no production deploy on mere report JSON status.

### Example CI build steps

```bash
set -euo pipefail
find app tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
test "${ERP_TEST_MYSQL}" = 1
[[ "${DB_DATABASE}" =~ ^zolo_test_[a-z0-9_]+$ ]]
export AI_EVALUATION_RUN_ID="${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
mkdir -p artifacts/ai-evaluation
rm -f artifacts/scenario-events.jsonl artifacts/junit-ai.xml
php artisan migrate --env=testing --force
php vendor/bin/phpunit -c phpunit.ai.xml --list-tests-xml artifacts/ai-test-plan.xml
php vendor/bin/phpunit -c phpunit.ai.xml --log-junit artifacts/junit-ai.xml
php scripts/export_ai_evaluation_report.php \
  --junit=artifacts/junit-ai.xml \
  --test-plan=artifacts/ai-test-plan.xml \
  --scenario-events=artifacts/scenario-events.jsonl \
  --output-dir=artifacts/ai-evaluation/current
```

These exporter arguments are implemented. For local runs, assign a unique `AI_EVALUATION_RUN_ID` instead of the GitHub expression. The workflow exports diagnostic evidence and uploads raw artifacts after a test failure. It does not convert a failed test run into a successful check. No ledger snapshot or k6 input is currently accepted as a verified release gate; those reports explicitly remain unevidenced or unrun.

### Make benchmark cap meaningful

Current `scripts/run_ai_benchmark.sh` passes a budget argument to `run_live_omni_benchmark.php`, but the live PHP script currently sums local JSON artifacts and assumes a fixed future cost of `$0.005`. This is not an atomic spending cap: parallel runners, deleted files or unexpected provider billing can exceed it.

For **paid live benchmarks**:

- Store each provider-budget reservation in an **atomic shared database ledger** keyed by `benchmark_account`, date/window and run. Consider a separate hard budget from customer credits.
- Bound cost from actual image/media units and enforced max output; abort before each provider call if unavailable.
- Enforce an atomic global limit across **parallel GitHub runners**; local files are evidence snapshots, not the spend authority.
- Set per-run call count, per-day USD cap, provider RPM/token cap, timeout and retry cap. Every retry needs its own budget reservation if independently billable.
- Record actual provider request IDs, returned usage, model price version and cost, compare with invoice/usage dashboard, and reconcile unknown billability.
- If cap cannot be bounded or quota service unavailable, **fail closed**.
- Test overshoot simulation, two parallel runners reserving the last cents, stale/removed JSON, retries and provider 500/timeouts.

### Release artifacts

```text
artifacts/ai-credit-release/<run-id>/<git-sha>/
  build-manifest.json                 # SHA, branch, PHP, DB migration and policy versions
  junit-ai.xml                       # actual PHPUnit results
  scenario-events.jsonl              # 250+ executed fixture assertions
  invoice-results.jsonl              # actual expected vs obtained (mock explicitly labeled)
  copilot-results.jsonl
  difficult-results.jsonl
  security-rule-coverage.json         # SEC rule -> tested method and CI assertion
  ledger-db-export.json               # audited source-of-truth DB snapshot (redacted)
  model-attempts-redacted.jsonl
  k6-5-10-20-raw.json                 # real k6 metrics, not generated loops
  credit-reconciliation.json          # computed from independent sources
  live-model-benchmark.json           # only when executed, separate status
  summary.json
  summary.md
  failed-scenarios.txt
```

The CI gate must verify artifact SHA/run ID equality, no missing tests, and that every claim maps to a file/test/DB query. **Never place customer credentials, invoices or secrets in public CI artifacts.**

---

## 8. P1-10 — Real Nemotron Omni invoice accuracy and governed ERP actions

### Current live evidence

The existing `live_omni_benchmark_BILL-001.json` records one actual Nano Omni extraction with **91.67% field accuracy**, but the supplier GSTIN did not match the expected document. The normalized response indicated an invalid supplier GSTIN and `is_verified_by_erp=false`. This demonstrates why a correct invoice grand total is **not sufficient** for safe posting.

### Real-model test design

Use two distinct suites:

**A. Deterministic integration suite (PR checks, fake model):** 100 Indian invoices, 100 Copilot requests, 50 edge cases; proves ERP validations, credit metering, approval gates and tenant security. No claim about actual OCR accuracy.

**B. Live provider benchmark (opt-in paid sandbox):** Run real Nano Omni on **all 100 test images** when budget/terms allow. Preserve source-media checksum, provider response ID, exact raw predicted fields (safely redacted), expected ground truth, provider usage and billed cost; grade each field independently. Include:

- Registered/unregistered GSTIN; checksum, correct supplier identity, duplicate supplier candidates.
- HSN/SAC, quantities, UOM conversions, CGST/SGST/IGST split, discounts, freight, round-offs and invoice arithmetic.
- Clean prints, low resolution, tilted scans, handwriting, bilingual labels and invalid documents.
- Multipage and 50/75/100-line invoice scenarios; enforce the configured page/token limits or clearly mark those scenarios `unsupported_by_plan` rather than a successful full extraction.
- Vision source attribution where supported. No hallucinated GSTIN, bank account, tax rate, supplier or SKU.
- Explicit `needs_review` when critical fields are missing, uncertain or inconsistent, even if the model claims high confidence.
- Credits per page, actual total model calls, retry rate, p50/p95/p99 latency and provider 429 rate.

**Release safety gate:** Any critical mismatch (GSTIN, invoice total/tax split, HSN/GST rate, tax state, supplier, quantity/UOM) blocks automatic posting. A human may correct and approve a draft only after authoritative ERP validation; approval hash must bind the corrected version.

### Live benchmark reporting

Report `n_live_documents`, `n_total_pages`, document-family coverage, `critical_field_exact_match_rate`, `false_accept_rate`, `manual_review_rate`, `line_item_recall`, `total_reconciliation_rate`, `cost_per_successfully_verified_invoice`, and `provider_call_count`. Do not extrapolate one bill to all 100. Define quality thresholds with Product/QA/Finance after baseline; prioritize **zero unreviewed financial mistakes** over nominal extraction completeness.

---

## 9. Additional implementation risks worth including in the same PRs

**RISK-01 — Disabled-metering stub:** `AiCreditReservationService` and settlement can return a zero-charge stub when `ai_credits.enabled=false`. Ensure this is restricted to a clearly marked safe/non-commercial mode; prevent live customer model calls when the meter is off. Fail closed, or require a signed, separately auditable internal exemption.

**RISK-02 — Monthly media cap:** The current `balance()` estimates monthly media use by counting `ai_media_assets` rows, which may not equal **billable pages** (one asset can have multiple pages). Introduce page-unit events and tenant/subscription-appropriate caps; avoid off-by-one period boundaries.

**RISK-03 — Pricing changes:** Model prices and USD/INR rates should be immutable per settled provider attempt; do not recalculate old customer transactions with a newly deployed config.

**RISK-04 — Rate-limiter coordination:** Ensure the NVIDIA 35-RPM bucket uses shared Redis storage across web, Horizon and separate job workers, not process-local cache. Distinguish rate-limit 429 from no-credit 402.

**RISK-05 — No phantom charge on provider error:** Some provider failures occur **after** billable work. Use explicit billability classification and separate attempt IDs; do not categorically release all holds on exception.

**RISK-06 — Payment refunds/top-ups:** Paid, refunded and replayed webhook events must have separate immutable ledger entries with unique external payment IDs. Never allow a refund to silently create negative prepaid balance or unlock additional AI calls.

**RISK-07 — Company and fiscal-year authority:** Shared subscription credits must not mean shared ERP business data. Enforce company, branch, fiscal year and actor authorization before any model call that retrieves company context and again before action execution.

**RISK-08 — Versioned approval:** AI credit deductions do not authorize posting. Approved actions must use the existing immutable hash, explicit approval and authoritative Purchase/Sales/Accounting services. Rejection or correction invalidates an earlier approval.

**RISK-09 — GitHub Actions observability:** Current repository has no visible AI-credit workflow check on reviewed HEAD. A successful unrelated `company-foundation` action is **not** evidence this suite passed.

**RISK-10 — Provider licensing:** Never route customer workload to a trial API endpoint if provider terms prohibit commercial/production use.

---

## 10. Verification checklist and test matrix

| Category | Required automated test IDs / scenarios | Gate |
|---|---|---|
| Wallet and overage | BILL-01..10; CR-01..25; provider attempt retries | No negative prepaid wallets, no untracked provider expense |
| Monthly allowance | New periods, suspension, leap month, 1-year/3-year prepaid cycles, top-up refunds | Exactly one grant per eligible period/event |
| Price precision | Six prior rounding cases, boundary pennies/paise, 10k randomized decimal cases, model/FX rate changes | Runtime = authoritative ledger = report |
| Real bill fixtures | BILL-001..100 with source PNG/PDF checksums and expected JSON | 100 deterministic integration cases executed; live rate reported separately |
| Copilot | COP-001..100, drafts/corrections/missing entities | No write before approved canonical payload |
| Difficult | EDGE-001..050, invalid tax/UOM/stock/entity/warehouse/period, prompt injection | No unauthorized ERP write, correct explicit error |
| Security | SEC-01..20, plus test environment security SEC-E01..06 | No cross-tenant leak; zero test user creation in prod |
| Concurrent credit ops | Real 5/10/20 VUs, same-wallet and mixed-wallet, 429 and retry storms | No overspend, raw load evidence present |
| Model gateway | Shared NVIDIA RPM, model allowlist, missing rate, error billability | No unmetered provider call, correctly classified holds |
| CI reproducibility | GitHub Actions run at exact protected SHA with JUnit + k6 + DB evidence | All required jobs green, no missing artifacts |
| Live quality | NVIDIA Omni 100-image benchmark (optional staging, paid & capped) | Separate signed-off QA quality gate before automated accounting posting |

### Suggested read-only SQL reconciliation examples

Adapt column names and wallet scope based on the actual migration; run against a safe test database.

```sql
-- Compare period aggregate debit with immutable ledger debits.
SELECT p.id AS period_id, p.spent_credits,
       COALESCE(SUM(CASE WHEN l.kind='usage_debit' THEN -l.amount_credits ELSE 0 END),0)
          AS ledger_debited
FROM ai_credit_periods p
LEFT JOIN ai_credit_ledger l ON l.period_id=p.id
GROUP BY p.id, p.spent_credits
HAVING spent_credits <> ledger_debited;

-- Find prepaid wallets whose TRUE computed balance is negative.
SELECT id, account_id, period_start_utc,
       (included_granted + topups_granted + refunded_credits
           - spent_credits - reserved_credits) AS raw_available
FROM ai_credit_periods
WHERE (included_granted + topups_granted + refunded_credits
           - spent_credits - reserved_credits) < 0;

-- Duplicate attempt/debit check.
SELECT provider_attempt_id, COUNT(*) AS cnt
FROM ai_credit_ledger
WHERE kind='usage_debit' AND provider_attempt_id IS NOT NULL
GROUP BY provider_attempt_id
HAVING COUNT(*) > 1;
```

A zero-row result from these checks is necessary but **not sufficient**: also independently join provider attempts to reservations and payment grants and check unknown billability or unfunded provider-cost events.

---

## 11. Recommended PR sequence

Use **small PRs** rather than a single giant fix. For every PR, include tests, reproducible command output, migration strategy, before/after sample and risk assessment.

| PR | Title | Primary files | Acceptance |
|---|---|---|---|
| **PR-01** | Strict prepaid credit settlement + variance accounting | Reservation, settlement, reconciliation services, additive migration | BILL-01..09, no negative wallet or untracked cost |
| **PR-02** | Exact rate-card pricing and idempotency safety | Calculator, config, gateway, DTOs/tests | Decimal goldens, no missing model rate fallback, conflicting keys denied |
| **PR-03** | Safe evaluation environment | Evaluation scripts, test provider, CLI guards, test principal factories | SEC-E01..06, production execution blocked |
| **PR-04** | Evidence-driven report exporter | `export_ai_evaluation_report.php`, scenario recorder, report parser | Fake/missing test evidence fails; true statuses/DB matched |
| **PR-05** | Real concurrent workload harness | k6, test-only API and parallel PHP/MySQL tests, fake provider | Raw 5/10/20 VU evidence, no overspend/duplicate bills |
| **PR-06** | AI-credit CI pipeline | `.github/workflows/ai-credit-evaluation.yml`, `phpunit.ai.xml`, secrets/protection | Protected SHA has reproducible green status and artifacts |
| **PR-07** | Safe, limited live-model benchmark | Benchmark scripts, budget ledger, live artifact schema | Atomic $ cap, real usage/cost, no mixed synthetic/live scores |
| **PR-08** | Customer pilot and UI/reconciliation readiness | ERP credit UI, operations alerts, dashboards, runbook | Incident alerts, no false available balance, human approval enforced |

**Dependency order:** PR-01/02/03 before PR-04; PR-04/05 before PR-06; PR-07 and PR-08 only after core billing/evidence checks are trusted.

---

## 12. Deployment plan, rollback and operations

### Staged rollout

1. Deploy additive schema changes with credit enforcement **disabled for all paying tenants**, but do **not** permit an unmetered paid customer model call. Existing AI functionality should remain behind the current approved entitlement gate.
2. Run **shadow metering** on internal/synthetic tenants: measure planned debit and compare provider invoice without deducting customer funds. Clearly mark shadow events as non-billable.
3. Enable the wallet for **internal test company** only, verify monthly allowances/top-ups, expiry, alerts, idempotency and ledger reconciliation.
4. Roll out to a small **commercially licensed model provider** pilot with explicit customer credit policy and page limits; retain manual invoice review.
5. Observe for at least one billing-cycle boundary or use accelerated time tests plus carefully monitored period migration. Reconcile actual API provider invoices and customer debits daily.
6. Expand only when no critical billing/authorization incidents and approved success metrics are maintained.

### Kill switch and rollback

- Kill switch disables **new model dispatch**; it must not destroy settled billing history or stop reconciliation/receipt processing.
- Existing in-flight reservations finish in a reconciled state; uncertain provider attempts go to incident review.
- On emergency regression, revert application behavior to previously safe gating while leaving additive columns/tables intact; don't drop the ledger.
- Keep provider API budget caps active independently of ERP credit flags.
- Export audited wallet snapshots before any migration that changes pricing/rate versions.
- Maintain an incident runbook: undercharge/overcharge, missing provider usage, negative raw balance, duplicated payment event, unknown billing rate, stale hold, expired account, rate-limit storm, unauthorized posting, and disclosure risk.

### Operational alert thresholds

- **Immediate P0 alert:** `raw_available < 0`, duplicate credit debit, missing provider-attempt ledger reference, cross-tenant access violation, unapproved financial write, live dispatch with disabled credit enforcement.
- **P1 alert:** `reconcile_required` aged beyond configured SLA, provider attempts not linked to settled/reserved/reconciled billing state, mismatched provider invoice/credit cost, repeated 429, abnormal bill-image error rate.
- **P2 alert:** low remaining tenant credits, approaching page limit, routine latency regression, top-up failures.

---

## 13. Definition of done and sign-off

**Do not mark `PRODUCTION_GATE_PASS` until all of the following are objectively true:**

- [ ] Prepaid wallet remains nonnegative under over-budget provider usage and 5/10/20-user contention. Provider excess cost is explicitly recorded and routed for operations review.
- [ ] Each model attempt, retry and failure has a unique tracked billing state; no phantom unmetered calls and no duplicate debits.
- [ ] Rounding and rate/FX versioning match across the exact runtime service, immutable ledger and evidence reports.
- [ ] Missing rate cards, unbounded inputs and credit-disabled production bypasses are rejected before provider dispatch.
- [ ] Evaluation scripts fail safely against any production or non-allowlisted database, and use nonpredictable temporary credentials.
- [ ] All 250 deterministic scenarios have **actual executed test evidence**, not generated PASS labels; exceptions/skips/incomplete tests fail CI.
- [ ] Security suite includes real HTTP and DB before/after assertions, with all tenant boundaries and approval hashes intact.
- [ ] Genuine k6 5/10/20-user concurrency runs against credit POST operations and fake provider, with raw logs and reconciled DB evidence.
- [ ] GitHub Actions AI-credit workflow is green on the **exact release SHA**, with JUnit/k6/DB evidence attached. No direct deployment relies solely on `summary.json`.
- [ ] Optional live Omni benchmark uses a trustworthy shared spending cap, real provider attempt IDs and cost records, and separates live from mocked cases.
- [ ] Invoice GSTIN or monetary mismatch always triggers manual correction and approval; no unreviewed accounting posting.
- [ ] Security + Finance + Product + QA approve pilot entitlements, GST/privacy/legal terms and release metrics.

### Ownership

- **Backend/Billing:** reservation, settlement, price/FX, top-ups, ledger and reconciliation.
- **AI/Multimodal:** bounded model routing, provider attempts and live image benchmarks.
- **QA:** 250 deterministic tests, evidence integrity, actual field-comparison grading.
- **SRE/DevOps:** GitHub Actions, test databases, k6, concurrency and cost budgets.
- **Security:** tenancy, approval boundaries, evaluation environment restrictions, webhook integrity and secrets.
- **Product/Finance:** credit allowance and top-up terms, page caps, refund/overage policy and release sign-off.

---

## 14. Engineering prompt for the implementation team

> Implement the remediation plan in this document against the **current** `feature/ai-unified-input-clarification` branch. Verify HEAD and existing Laravel conventions first. Work in additive, independently reviewed PRs. Begin with strict prepaid credit safety, independent provider-cost variance accounting, idempotent reservation and settlement, and exact decimal pricing. Do not weaken existing company/branch/FY authorization or human approval. Replace generated evaluation PASS records with verified PHPUnit/DB/k6 artifacts, secure evaluation execution against test-only databases, add genuine concurrent 5/10/20-VU wallet tests, and create a protected AI-credit GitHub Actions workflow. Keep live-model QA separate and under an atomic spending cap. Do not label production-ready without independently reproducible green CI evidence and the release checklist in section 13.

---

## 15. Source references (reviewed code, not external pricing claims)

- [Credit reservation service](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/app/Ai/Billing/Services/AiCreditReservationService.php)
- [Credit settlement service](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/app/Ai/Billing/Services/AiCreditSettlementService.php)
- [Exact cost calculator](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/app/Ai/Billing/Services/ModelCostCalculator.php)
- [Credit reconciliation service](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/app/Ai/Billing/Services/AiCreditReconciliationService.php)
- [Approved model gateway](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/app/Ai/Gateway/ApprovedModelGateway.php)
- [Evaluation exporter](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/scripts/export_ai_evaluation_report.php)
- [Live Omni benchmark](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/scripts/run_live_omni_benchmark.php)
- [k6 load test](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/performance/ai_credit_load.js)
- [Ledger tests](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/tests/Feature/Ai/Billing/CreditLedgerRulesTest.php)
- [Concurrent credit tests](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/tests/Feature/Ai/Billing/ConcurrentCreditLoadTest.php)
- [Existing AI PHPUnit configuration](https://github.com/vigneshsinna/zolo-erp-pro/blob/a97391c829f8d60ed24b322dd934eded630fc4f0/phpunit.ai.xml)

**Evidence limitation:** This document is grounded in the accessible GitHub source at the reviewed commit. It does not assert that source changes have been applied, that a GitHub Actions AI-credit workflow has run, or that any live model benchmark was executed during preparation of this document.
