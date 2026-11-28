# 05 — Complete remaining work, acceptance and release plan

## Baseline and decisions

Pinned branch `2359b644` commit claims R01–R09 complete; previous source review verified transaction resolver, optical extraction V2, sales invoice media flag, optical review, Rx allocation/reversal improvements and tests. Do not rebuild them. Tests using `Http::fake` are useful regressions, not live NVIDIA validation. Latest checked branch unchanged; no fresh CI or local runtime access. Knowledge source `definitions.json`, `core/processes.json`, `industries/opticals/processes.json` still draft at inspected head. Governance hardening tests contain reviewed fixtures; they do not constitute real owner signoff. Review actual all definitions/current feature matrix before release. Original screenshot proves timeout symptom, not exact operation. New screenshot is desired UI. No implementation or test execution in this specification.

## 1. Ordered work packages

| Work | Scope | Release gate |
|---|---|---|
| R13 | async task/API, durable private storage and progress stages, existing service reuse | Upload returns 202 while provider blocked; no sync fallback |
| R14 | dedicated queue, timeout/attempt hierarchy, recovery scheduling and rate limiting | Real worker, provider 429/timeout and deadline tests |
| R17a | actual workspace progress card wired to R13, browser vertical slice | UI01–UI03 with real app and worker, old loading line gone |
| R15 | bounded image/PDF/audio preprocessing and measured optical readability | 5.6MB PNG, multi-page and exact-power accuracy |
| R16 | durable idempotency, credit/token reconciliation, worker kill/replay | one task/result; attempt and ledger verified |
| R17b | complete UI states, retry, restore, review, scope, mobile/accessibility and served asset verification | UI01–UI14 in file 03 |
| R06 completion | actual owner-reviewed eligible knowledge/actions/processes; review/manifest/version integrity | no draft promotion without evidence; executable verified vertical slice |
| R10 | live model evaluation after async pipeline | approved labeled corpus, actual provider and metering |
| R11/R18 | full security, finance, concurrency and failure recovery incl async | actual MySQL and worker tests, staging 5/10/20 |
| R12 | local and staging UAT, configuration, migration, deployment and rollback | exact release SHA, named signoff, approved pilot |

R17a is an explicit early vertical slice; do not defer real UI until the end. R17b finishes the same renderer. Do not expose new MCP approvals or rebuild action engine. R08/R09 source present, still validate actual domain parity and exposure. Additional previously unsupported expense-only AP, invoice-before-receipt, cross-GST transfer and other industries are separate domain backlog if required. Keep unsupported paths explicit/disabled; do not claim full ERP scope. Direct PO-less purchase and SO-less sales remain valid when authoritative receipt/dispatch policy supports. Review flags/license and actual role scopes for all paths. No production activation of incomplete features.

## 2. Automated integration matrix

Use actual MySQL fixtures and independent sessions for races; fake provider only to control known failure modes, real worker for async integration. Assert actual database state, domain document/stock/GL counts, provider attempt and credit records, stage/version and task link. Existing regression suites must remain green.

| ID | Scenario | Expected invariant |
|---|---|---|
| A01 | Provider blocked >120 s | Web returns 202 promptly; no HTTP500; real stage running |
| A02 | Queue connection sync / unavailable | No inline provider call; explicit configuration/queued failure |
| A03 | Ingest invalid MIME, corrupt PNG, huge pixels, oversized upload | Bounded admission, no unauthorized storage/provider call |
| A04 | 5.6 MB PNG and optimized derivative | Readable signed powers; real measured timings; no request timeout |
| A05 | multi-page PDF partial failure | checkpoint successful pages; retry only required pages; no false full extraction |
| A06 | Audio initial/reply and supplier photo | explicit existing review; no unintended invoice preparation |
| A07 | Sales bill vs purchase bill, neutral bill, negated intent | correct route or targeted clarification |
| A08 | Incoming invoice used for outgoing sales | source conflict/resale confirmation; no copied supplier or cost |
| A09 | Optical RE/LE, SPH/CYL signs, axis and unit | exact attributes/identity, no wrong-product fallback |
| A10 | non-optical tenant | appropriate generic schema and UI, no optical leakage |
| A11 | Duplicate upload key concurrent, changed payload | one task/asset; conflict on changed payload |
| A12 | Lost admission response | recover same task by key; no duplicate provider or quota |
| A13 | Queue dispatch lost after commit | durable outbox/sweeper recovers, no duplicated execution |
| A14 | Duplicate delivery/lease expiry/stale worker | CAS/fencing, no stale result or duplicate preparation |
| A15 | Provider 429 | scheduled backoff, no tight loop, actual rate budget |
| A16 | Provider timeout/5xx | bounded attempts across retries, uncertain outcome explicit |
| A17 | Provider 400/401/TLS | terminal nonretryable, no fallback |
| A18 | Invalid/truncated response | safe schema rejection/manual review, no invented invoice |
| A19 | Deadline exceeded, worker stalled | recoverable status, no infinite processing |
| A20 | Cancel before dispatch or during provider request | stops downstream stage; actual usage reconciled |
| A21 | Kill before provider call, after send, after response | durable attempt recovery, no blind duplicate call |
| A22 | Kill after extraction or proposal commit | reuse result, no duplicated proposals |
| A23 | Kill before credit settle/refund | durable reconciliation, no double charge/refund |
| A24 | Credit exhaustion or concurrent balance reservation | blocked safely, no overspend |
| A25 | Retry existing extraction and deterministic edit | zero additional model invocation/credit |
| A26 | Cross-company, branch, FY, actor task/media/status | no leak or write, even with real foreign IDs |
| A27 | Permission/license revocation while queued | reauthorize worker; fail closed |
| A28 | Thread/task revision change mid-processing | stale result cannot modify current draft |
| A29 | Prompt injection/file name XSS/nested authority | treated as data, no escalation or unsafe output |
| A30 | Existing sales invoice feature flag disabled | extraction safe; no forced SO or posting |
| A31 | Direct purchase received vs not received | correct supported route, no phantom stock |
| A32 | Direct optical sales invoice no SO | correct exact stock/AR/revenue/tax, approved only |
| A33 | PO/GRN and SO/DC partial linked invoices | no duplicate stock movement or overbilling |
| A34 | GST/non-GST/charges/discount and actual settlement | manual vs AI exact domain parity |
| A35 | Last-stock concurrent sale/transfer | no wrong power/over-allocation |
| A36 | Rx job wrong customer/source/qty, duplicate allocation/reversal | deny unrelated evidence, correct posted links |
| A37 | Proposal changed or expired, approval bypass | no posting; exact current version required |
| A38 | MCP existing read/prepare and new policy | no approval/execute; correct scopes and flags |
| A39 | Draft/withdrawn knowledge, changed profile/hash | no new unreviewed workflow; approved slice works |
| A40 | Browser UI01–UI14 | real progress, restore, retry, scope, review and actual served assets |
| A41 | Existing legacy workflows with flag off | no regression or bypass |
| A42 | Migration upgrade/recovery and rollback | media/task/audit/credit/ERP history preserved |

Inspect actual PHPUnit config, CI and browser setup before commands. Run existing `TransactionIntentResolverTest`, `SalesInvoiceMediaWorkflowTest`, `OpticalMediaPerceptionTest`, `OpticalRoundTripAndInvalidationTest`, `OpticalLookupAndUiContractTest`, `OpticalRxHardeningTest`, `OpticalCommercialReconciliationAndParityTest`, `OpticalMcpAndAutomationExpansionTest`, `KnowledgePlannerHardeningTest`, `OmniRequestPayloadTest` plus all ERP acceptance and credit suites. Proposed new tests: `AsyncMediaAdmissionTest`, `MediaProcessingTaskStateTest`, `MediaWorkerRecoveryMysqlTest`, `MediaCreditReconciliationTest`, `MediaProviderBudgetTest`, `CopilotMediaProgressBrowserTest`. Test names suggestions, not existing files. Report actual pass/fail/skip/assertions and commands. Full final SHA CI including MySQL 8.0/8.4 (if supported), migration upgrade, operations/textile regressions, commercial, compliance, concurrency, security, static/lint. A test that is skipped for missing credentials/worker does not pass its mandatory gate.

## 3. Actual live NVIDIA evaluation (R10/R18)

Use approved staging, privacy-authorized or synthetic invoices/prescriptions with ground truth. Do not send real wearer/contact data without authority. Use actual approved Nemotron Omni and text model via gateway, no unapproved fallback. 100 bill images/PDFs (include dense optical powers, signs, RE/LE, different layouts, GST, direct and linked, poor quality, multi-page), 100 realistic Copilot tasks (purchase/sales, stock/store, edits, customer/supplier, optional lab), 50 difficult scenarios (ambiguous route, incoming resale, wrong sign, missing master, mismatches, prompt injection, stale approval, provider failure). Include English/Tamil/mixed where relevant. Label exact fields, intent, source roles, acceptable clarification, actual approved domain result. Record provider/model, prompt/schema/profile hash, derivative dimensions/bytes, page count, per-stage elapsed, attempts, token/credit records, expected/actual, errors and corrections. Separate real benchmark from fake-provider corpus. Compare original PNG and optimized derivative on holdout; no accuracy tradeoff hidden by average.

Proposed thresholds to approve before run: >=95% correct intent on labeled representative tasks, >=98% exact extraction of readable required fields with per-field precision/recall/coverage, no wrong signed-power identity accepted, zero wrong final financial totals or unauthorized/duplicate posting, 100% deterministic cases zero model calls/credits. Clarification is safe but not exact extraction success. Record abstentions separately. No claim of accuracy until measured. Real provider quota and pricing from actual config/account, not historical assumed 35 RPM. Validate all held/debited/refunded/uncertain reservations and actual attempts. If live provider unavailable, record blocker and keep release gate open.

## 4. Staging concurrency and performance (R11/R18)

5, 10, 20 users independently (separate sessions and scoped credentials), actual database and workers; use controlled provider fake for fault/long latency load, approved live limited calls for real latency/quota. Mix exact text tasks, image/PDF uploads, status polling, retries, review edits, approved posting, transfers and last-stock race. Include worker crash and restart, lost responses, outbox recovery, queue backlog, shared RPM, credit depletion. No load against production balances or uncontrolled provider consumption. Record upload bytes/time, server admission p50/p95/p99, queue wait, stage processing, provider latency/attempts/429, polling load, DB lock waits, memory, CPU, failure/retry counts, credits and actual stock/ledger reconciliation. Target p95 admission <=3s after upload on agreed hardware (proposed), no raw timeout HTTP500 under supported fixtures, no safety errors and <1% unexpected server errors as proposed initial threshold; model processing SLO set from actual measured baseline. Do not promise extraction completion within fixed time independent of provider. Verify queue visibility > hard timeout, no lease overlaps causing duplicate attempts, and actual worker heartbeat. Include same invoice retried after partial failure. Adapt existing k6 script; read-only tests not evidence of write concurrency.

## 5. Governance, UAT and final release (R06/R12)

Review existing process/action definitions and source/manifest/owner references for exact supported optical wholesale slice: direct purchase, direct sale, linked PO/GRN and SO/DC, exact stock/transfer, settlement and optional Rx lab if business supports. Obtain real Product/Finance/Inventory/Optical Operations/Security approval; no generated signatures. Keep drafts hidden/unexecutable; approved policies cannot grant extra permissions. Test reviewed process through real verified stages/receipts, including source reversals, partial fulfillment and exact approval. Direct bills no fabricated orders. Review licensing and flags for actual test company. No unsupervised approval or MCP execution.

Actual `/ai` UAT: user uploads sample invoice (authorized), sees progress states and sales/purchase review, resolves exact lens/customer/supplier and warehouse, corrects fields, previews source vs domain totals, approves current version, verifies invoice and stock/ledger. Repeat timeout/retry, refresh, switch scope, voice/PDF, direct bill without predecessor, linked invoice and optional lab. Capture before/after real screenshots and Network trace as file 03. Finance validates taxes, journal, AP/AR and stock; operator confirms exact power and process. Test actual enabled configuration, not solely disabled flags.

| Gate | Evidence | Status at specification |
|---|---|---|
| G0 | Final integrated SHA full CI and upgrade/migration | Not verified for new branch |
| G1 | Async admission, actual worker, recovery and budgets | Missing implementation |
| G2 | Real browser progress UI and served asset proof | Missing implementation |
| G3 | Optical/financial correctness + R01–R09 regression | Source exists; live acceptance pending |
| G4 | Tenant security, approval, idempotency/credit recovery | Pending integrated tests |
| G5 | Live 250-case benchmark, actual metering | Pending |
| G6 | 5/10/20-user staging and recovery | Pending |
| G7 | Reviewed knowledge, named owner UAT and rollout/rollback | Pending |

Release decision: **NO-GO for unrestricted AI activation until gates pass**. Controlled pilot only for fully verified scoped capabilities, with unresolved/deferred features disabled and explicit approval. Stop admissions for wrong power, unauthorized action, financial mismatch, data leakage, duplicated attempts/posting or stuck/incorrect credits; recover durable tasks, do not delete ledger or posted records. Keep existing ERP non-AI operations available where safe. Record exact SHA, deployment asset hash, flags/worker configuration, commands/results, test evidence, unresolved risks and signoffs. No claim that code is complete just because commit description says R01–R09 or UI shows model name.
