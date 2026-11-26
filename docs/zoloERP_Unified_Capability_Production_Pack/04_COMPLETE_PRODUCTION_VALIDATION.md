# 04 — Complete Production Validation and Release Gates

**Workstream:** PR14–PR16 plus U1/U2 integration tests | **Scope:** existing ERP + Business Knowledge + shared discovery + MCP prepare/status | **Decision:** NO-GO on assessed baseline.

## 1. Evidence and release discipline

This file consolidates acceptance, not a report that tests were run. Reuse the original PR8–PR16 specification embedded in document 03, including its 25 end-to-end cases A01–A25. Add U1 cases D01–D16 and U2 cases M01–M20. All must be traceable to tests, data, results and owner signoff. Baseline PRs #40–#46 were confirmed unchanged and draft on 25 November 2026. The last observed failure on #45 and #46 is `MySQL / operations` with `principal_job_orders.financial_year_id` missing from the operations fixture schema. [PR6 evidence](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/44783687174), [PR7 evidence](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/44785796351). No fresh CI was run for this specification.

Each PR must record immutable commit SHA, branch/base, migration IDs, feature flag settings, exact test commands, run URLs, pass/fail/skip counts, failed-case details, evidence artifacts and owners. A skipped required test is not a pass; a green read-only suite is not a posting test. Final release acceptance is on the integrated SHA, not accumulated success on different PR heads. Do not alter fixtures to bypass tenancy or weaken existing assertions. Use feature-on and feature-off tests and approved test data only.

## 2. Gate G0 — database, CI and regressions (PR8 prerequisite)

- Reproduce and fix `TextileScenarioMatrixTest::test_dyeing_unit_processes_customer_material_without_owning_it_and_bills_only_the_charge`. Inspect existing operations/legacy fixture builders, normal migrations, restore process and `PrincipalJobService`. Verify additive FY ownership migration on actual connection used by every suite, and correct upgrade/backfill. No silent removal of FY column or filters. Ambiguous historical orders remain quarantined from AI until business-reviewed remediation.
- Run MySQL 8.0 and 8.4 fresh and upgrade schema tests, production-like anonymized backup/restore, migration timing and rollback rehearsal, missing/ambiguous fiscal years, FY/branch linked historical records and closed period rules. Test manual ERP flows while AI off; no regression due to new migration or flags.
- Run all existing CI jobs: autonomy safety, commercial, operations, compliance, company, delivery, legacy, concurrency, AI, credit metering and clean DB acceptance. Use repository-defined PHPUnit configs and workflows, not invented commands. Confirm exact CI configuration in repository. Example focused test after setting isolated DB env: `php artisan test --filter=test_dyeing_unit_processes_customer_material_without_owning_it_and_bills_only_the_charge` (adapt to actual operations test config). Record actual run.
- Require static analysis/lint, composer/npm security advisory scan, dependency/secret scanning, no unresolved critical/high release-risk vulnerabilities, reviewed exceptions and documented upgrade constraints. Protect branch and require CI/owner reviewers.

## 3. Gate G1 — capability discovery and integration

Run D01–D16 and M01–M20 from documents 02/01. Include current read-only MCP clients, both supported protocol versions, internal SDK, REST discovery and Automation Builder. Compare safe schema semantics, permissions, draft task state, normalized payload, tax preview and final authoritative approved receipt across applicable interfaces. Revalidate every call; stale discovery is not permission. Test unknown alias/operation, schema drift, permission and token revocation, flags off, unsupported versions and safe errors. Assert denied calls produce no domain write or provider call. Assert prepare-only requests change only draft/task/audit metadata; no PO, GRN, bill, stock, journal, payment or outbound message created. MCP cannot approve/execute; if user reviews and approves in ERP, only then verify the normal action executor creates a durable receipt.

Maintain a release capability inventory: canonical key, enabled interfaces/operations, version, required roles, reviewed knowledge reference, evidence adapters, risk, approval, tests and pilot scope. Every advertised capability must be tested. Incomplete ones explicitly hidden/unsupported. Do not widen automation policy automatically through discovery.

## 4. Gate G2 — business correctness and finance signoff

Test in MySQL with representative companies, registered/unregistered parties, intra/interstate, goods/services, multiple warehouses/UOM, lot/stock, open/closed financial years and existing transactions. Expected ledger/tax outcomes must be independently agreed with Finance/GST owner; do not rely on model arithmetic or this document as tax advice.

| Scenario | Mandatory assertions |
|---|---|
| PO -> partial GRN -> bill | Correct source IDs, supplier, warehouse, base UOM, open quantities, allowed price differences; no duplicate stock movement |
| Direct goods bill | Appropriate stock, AP/tax and source policy; duplicate invoice rejected |
| Service bill | No physical stock movement; correct accounting location, supplier payable and SAC/tax treatment |
| Expense-only AP | Dedicated approved GL/AP contract; no dummy inventory/service line; if not implemented, release scope excludes it |
| GST / Non-GST / exempt / RCM / ITC | Domain-calculated tax, exact decimal totals, lawful reviewed classification, no AI tax override |
| Outward job work | Company-owned dispatch/return, accepted/rejected/loss, processing bill, eligible cost allocation and COGS/closing stock |
| Customer-owned job work | Off-owned-stock quantity trace, permitted loss/return, returned-unbilled quantity service invoice and AR; no company-owned inventory capitalization |
| Reversal / credit note / uncertain outcome | Compensating auditable transaction; linked original, no destructive deletion, no duplicate ledger entries |
| Source change after preview | Current-version approval and source-state check prevent stale posting |

For each financial scenario assert exact domain resource IDs and versioned approval, balanced debit/credit, quantities and UOM, stock movement count and valuation, AP/AR open items, tax totals, audit event and idempotency. Test normal manual ERP/API and approved AI posting against same expected outcomes. Validate all actual event and predecessor record relations, partial receipts, repeated steps and close criteria. Run entire original A01–A25 matrix. Gate requires zero unauthorized posting, zero unexplained ledger imbalance or wrong final financial total, zero duplicate inventory/ledger posting and Finance/Operations signoff.

## 5. Gate G3 — security, privacy, reliability (PR14)

Use at least two companies, two branches per company, two FYs, distinct purchasing/finance/operations/read-only/admin users, and read-only vs prepare-only bound MCP clients. Include own and other actor's task/proposal IDs. Test matrix across chat, SDK tools, MCP, REST, automation, queue execution, document/media and status endpoints. Verify true negative checks using valid-looking foreign IDs rather than only random nonexistent IDs.

| Threat/failure | Required test and invariant |
|---|---|
| Cross-tenant / branch / FY / actor | Data and operation denied; model context, preview, search, metrics and logs reveal no foreign records |
| Privilege escalation | Inject authority fields at top/nested levels, fake role/permissions, wildcard/revoked MCP token; no grant |
| Approval bypass | Direct API/MCP calls, tampered payload/hash/signature, old version, expired/changed permissions; no posting |
| Prompt injection | Supplier notes, OCR, PDF, audio, customer data and external tool result contain fake instructions; treat as data only |
| Replay/concurrency | 20 parallel same request/approved action, stale revisions, duplicate bill/receipt, deadlocks; exactly one legitimate commit |
| Workflow evidence spoof | Wrong receipt/order, reversed receipt, repeated or fabricated event, out-of-order step, knowledge revoked; block dependent posting |
| Worker crash / timeout | Crash before and after domain commit, before result persistence; recover from authoritative idempotency receipt; never blindly replay uncertain action |
| Provider/queue outage | Preserve drafts, bounded retry/backoff, rate limit and circuit breaker; no fallback to unapproved model or direct execution |
| Media/PII | File ACL, size/type limits, malformed content, retention/deletion policy, redaction, credentials absent in logs |
| Stale cache | Membership, permissions, feature flags and client binding changed after discovery; invocation denies immediately |

Run tests with separate actual DB sessions for race scenarios, not sequential mocks. Verify durable uniqueness/CAS and locks, consistent lock order and no unnecessary LLM calls while transactions are locked. Use scoped execution recovery with current exact approval/version and domain receipt. Record denial codes and audit redaction; never reveal SQL or secrets in error responses. Require zero cross-tenant disclosures or unauthorized actions and successful revocation/emergency stop drill. Security reviewer signs off on threat model and results.

## 6. Gate G4 — live model accuracy and credit metering (PR15)

Existing 120 deterministic synthetic phrases are regression tests, not a live model benchmark. Prepare privacy-authorized held-out corpora and ground truth before tuning. Use secure staging with actual approved Nemotron model(s) and approved perception path. No live customer financial posting.

| Corpus | Minimum | Coverage |
|---|---:|---|
| Real Indian purchase bills | 100 | GSTIN, HSN/SAC, CGST/SGST/IGST, discounts, totals, rounding, handwriting, multi-page, RCM, non-GST, conflicting fields |
| Complete Copilot requests | 100 | Master creation, PO/GRN/bills, sales and job work, corrections, ambiguous trade direction, multiple fields per turn, approval/rejection |
| Difficult/negative cases | 50 | Missing masters, wrong UOM, ambiguous supplier/warehouse, tax mismatch, duplicates, stale approval, prompt injection, ownership, insufficient stock |

Include English, Tamil and mixed language where relevant; diverse vendors/layouts; avoid train/eval overlap. Use consented data and redaction where possible. Label expected intent, verified entity references, extracted fields and allowable clarification/abstention, authoritative final monetary results. Evaluate typed chat, voice transcript confirmation and image/PDF review. Save anonymized expected/actual results, failure analysis, model ID, prompts/contract version, provider invocation IDs, latency, credit evidence and owner review. Do not claim accuracy from synthetic data or label abstentions as correct automatically.

Proposed acceptance targets (Product/Finance/Security must ratify before execution):

- >=95% correct intent on labeled held-out requests; report confusion matrix, unknown/abstention separately and end-to-end completion/correction rates.
- >=98% precision on verified key financial fields; report recall and coverage too. Critical fields include supplier/invoice identity, GSTIN, HSN/SAC, quantity/UOM, unit rate and amounts/tax. Zero accepted invented ERP IDs and zero incorrect final posted totals; low-confidence cases require human review or clarification.
- **100% deterministic test cases:** zero model dispatches and zero model credits for deterministic permission checks, stock/GST/math/UOM, structured reads, MCP prepare/status and exact supported commands. Verify `ai_model_invocations` plus credit ledger, not just mocks.
- All model calls via `ApprovedModelGateway` and existing credit meter. Reconcile reserves/settlements/refunds against actual provider attempts, retries and tokens; check 429, timeout, cancellation, monthly allowance, top-up, simultaneous reservation and depletion. No charges for uncalled models; no bypass or double settlement. Validate approved provider quotas from actual account configuration (35 RPM was historical planning, not guaranteed current limit).

Do not reduce thresholds after evaluation to hide failures. Model may abstain safely; product owner must assess excessive abstention and usability. Separate model quality from authoritative deterministic correctness.

## 7. Gate G5 — performance, concurrency and monitoring

Use authorized production-like staging, isolated data and provider credentials. Read-only k6 harness `tests/k6/ai_business_workflow_concurrency.js` exists; expand workload for **actual write** and proposal paths separately. Do not run against production or real customer balances. Use distinct actors/tenants and explicit approved fixture actions. Test 5, 10 and 20 concurrent users, plus targeted duplicate/replay stress. Suggested initial engineering SLOs below are proposed, not measured promises; ratify against deployment capacity.

| Metric | Initial target / measurement |
|---|---|
| Read-only list/discovery p95 | <1.5 s (existing script threshold; measure on staging) |
| Deterministic check service latency | Aim <20 ms for simple indexed local checks; separately measure full request p95 |
| Structured proposal preparation p95 | Set from realistic line-item workload and baseline, without model call |
| Model-assisted end-to-end p50/p95/p99 | Measure separately for text, image/PDF and voice; agree SLO by workload |
| HTTP failure | <1% excluding expected deliberate negative cases; report business denials separately |
| Duplicate/unauthorized postings | 0 |
| Queue/lock/provider throttle | Observe queue depth, DB lock waits, 429s, bounded retries and recovery duration; set approved ceilings |

Use mixed workloads: discovery/list/read; chat exact and ambiguous; MCP prepare retry; task correction; approvals via authorized human/test harness; post/status/reconciliation; multi-line and partial receipt; queue restart and quota exhaustion. Record dataset size, VUs, throughput, timings, errors, CPU/memory, DB queries, lock waits, Redis/queue, model calls/tokens/credits and posting count. Test 5/10/20 individually, not as a single average. Prove no cross-tenant result leak and correct financial balances after load. Avoid arbitrary fixed user sleep that masks provider quota saturation.

Extend existing telemetry rather than creating a second metering subsystem. Dashboard metrics should include channel (SDK/MCP/REST/automation), capability key/version, request/intent classification, completed vs failed/uncertain actions, proposal-to-approval and approval-to-post time, exceptions, recovery success, provider attempts/latency, credits per completed outcome and actual deterministic-call ratio. Distinguish approval rejection from technical failure and plans from posted transactions. Track extraction accuracy only from labeled feedback/evaluation; cost savings only from documented baseline/time assumptions, never invented. Use redacted structured logs, correlation IDs, scoped access, bounded cardinality and alerts for duplicate/unauthorized actions, reconciliation backlog, failed migrations, provider 429s/credit anomalies. Rehearse alert and recovery drill.

## 8. Gate G6 — UI/UAT, operations and pilot (PR16)

Validate Copilot text/voice/image/PDF, responsive mobile/desktop, keyboard/focus, accessibility, errors, loading, entity picker, correction, lost session/reconnect, approval center and workflow status. Show unposted vs posted accurately, explicit GST/Non-GST and line totals, preview source/warnings, separate approvals, actual receipt and audit trail. MCP status and secure review link must work with expired/revoked binding and signed-in wrong actor, without exposing data. Automation Builder must show scope, eligible capability and approval policy correctly. UAT owners include purchasing, inventory, finance, operations and end users.

Existing default-off flags:

```dotenv
AI_BUSINESS_KNOWLEDGE_ENABLED=false
AI_BUSINESS_SEMANTIC_ENABLED=false
AI_BUSINESS_WORKFLOW_PLANNER_ENABLED=false
AI_BUSINESS_SERVICE_BILL_ENABLED=false
AI_BUSINESS_JOB_WORK_ENABLED=false
AI_MCP_ENABLED=false
AI_MCP_OUTBOUND_ENABLED=false
```

Proposed additional flags (implement and verify config mapping):

```dotenv
AI_SHARED_CAPABILITY_DISCOVERY_ENABLED=false
AI_MCP_GOVERNED_PROPOSALS_ENABLED=false
```

Also verify actual existing action foundation/execution, async queue and tenant/role/client controls. Do not infer deployed settings from config defaults. Deploy additive migration first, check schema and workers/cache, and do not activate features just because migration passed. Record backup/restore, deployment runbook and support contact/escalation.

### Staged rollout

1. G0 green; merge reviewed integration stack in dependency order with flags off. No production feature exposure before migration and recovery gates.
2. Internal read-only shared discovery/knowledge pilot; verify compatibility and privacy; monitor actual data access.
3. Internal guided draft preparation and UI pilot; no automatic financial posting.
4. Finance/operations approved controlled PO/GRN/goods/service bill and both job-work directions only where G2–G5 pass; reviewed process variants only.
5. External MCP read compatibility, then single approved proposal-only action pilot with bound clients. No external approve/execute. Confirm credential issuance/revocation and review handoff.
6. Wider rollout only after 24–72h proposed monitored observation or approved equivalent and all gates/signoffs. Do not claim complete release if expense AP or either job-work direction is excluded; publish exact supported scope.

### Emergency stop and recovery

Disable affected channel/feature/tenant grants and revoke client tokens; stop new preparations/approved execution as appropriate. Preserve proposals, approvals, audit, idempotency and posted records. Drain or quarantine in-flight work according to existing executor semantics, distinguish not-started from uncertain/post-commit. Reconcile via domain receipts before retry. Never delete posted ledger, inventory or bills; use reviewed reversal procedures. Test restored application and schema compatibility, and record recovery-time and recovery-point objectives with operations team. No automatic rollback of destructive DB schema changes.

## 9. Final release checklist and evidence template

| Gate | Required evidence | Owner | Initial status |
|---|---|---|---|
| G0 | Integrated CI green, named FY test fixed, migration/restore drill | Engineering/QA | Blocked on assessed CI |
| G1 | D01–D16, M01–M20, schema/discovery/channel parity report | Engineering + Security | Not run for proposed changes |
| G2 | A01–A25, ledger/stock/tax reconciliation, signed domain fixtures | Finance, Inventory, Operations | Pending |
| G3 | Threat matrix, MySQL race/recovery, revoked access, zero unsafe results | Security + Engineering | Pending |
| G4 | 100 bills + 100 tasks + 50 difficult cases, metering reconciliation | Product + AI QA + Finance | Pending |
| G5 | 5/10/20-user read/write staging reports, alerts, agreed SLOs | Engineering/SRE | Pending |
| G6 | UAT, feature flags, rollout/rollback drill, supported scope | Product + Release lead | Pending |

For every gate record: release SHA; environment and approved test tenant; test dataset/fixture version; configuration and model version; commands and CI URLs; pass/fail/skip counts; expected vs observed results; evidence location; unresolved defect and severity; reviewer and signed approval date. All mandatory gates must pass at final integrated SHA. No waived security/financial correctness invariant. Optional product-scope deferral needs explicit owner decision, disabled capability and honest scope announcement.

**Final decision template:** `GO` / `NO-GO` / `LIMITED PILOT` (with exact supported capabilities, tenants, flags, expiry and owner). Require Product, Finance/GST, Operations/Inventory, Security, Engineering/QA and Release/Ops signoffs. Until evidence exists, status remains **NO-GO for full production release**.
