# 11 — Test Matrix, Benchmarks, and Release Gates

## Strategy

Test at five levels: schema/unit, HTTP authorization and storage, fake-provider integration, actual NVIDIA API compatibility in an isolated environment, and full ERP end-to-end user acceptance. A green provider response does not prove extraction accuracy, tenant isolation or legal/financial posting correctness.

Use disposable MySQL (same engine/version as production where possible), never use a production company for financial tests. Existing `UsesAiMysql` test conventions may wrap tests in transactions; compare transaction-depth changes against fixture baseline.

## Test scenarios

| ID | Area | Test | Expected result |
|---|---|---|---|
| MM-01 | Routing | Text-only purchase request | No Omni call; existing Copilot unaffected |
| MM-02 | Routing | JPEG request | Omni image purpose called, not main text-only model |
| MM-03 | Routing | WAV request | Omni audio purpose called, no ERP tools |
| MM-04 | Routing | Unsupported model ID from user prompt | Server rejects/ignores model override |
| MM-05 | Gateway | Global AI disabled | Zero model dispatches |
| MM-06 | Gateway | Media disabled while text enabled | Text works; media refused; zero Omni calls |
| MM-07 | Gateway | Shared NVIDIA RPM exhausted | Consistent 429 retryable result; no uncontrolled requests |
| MM-08 | Gateway | Provider 429 then retry | Bounded backoff, no duplicate task or media |
| MM-09 | Gateway | Provider timeout | Media retryable; no action proposal created |
| MM-10 | Gateway | Empty Omni answer | Safe error/manual-entry option |
| MM-11 | Upload | JPG spoofed as executable | Reject before storage/model call |
| MM-12 | Upload | Image exceeds configured byte/pixel limits | 413/422, zero dispatch |
| MM-13 | Upload | PDF beyond page cap | Reject/ask user to reduce pages |
| MM-14 | Upload | Corrupted PDF | Worker sandbox fails safely; no leak |
| MM-15 | Upload | EXIF contains GPS | Metadata stripped from provider bytes |
| MM-16 | Upload | Same actor repeats upload idempotency key | One permitted asset, no duplicate workflow |
| MM-17 | Privacy | Different company reads asset UUID | Denied; no metadata or media response |
| MM-18 | Privacy | Same company unauthorized user reads asset | Denied unless delegated permission |
| MM-19 | Privacy | Asset expires while queued | Worker aborts; deletion wins |
| MM-20 | Privacy | Attempt fetch via public `/storage` | Not accessible |
| MM-21 | Document | Clean 1-page invoice | Key fields/line items extracted as candidates |
| MM-22 | Document | 5-page invoice | Pages combined once, no duplicate totals |
| MM-23 | Document | Missing GSTIN | Null + issue; never invented |
| MM-24 | Document | Conflicting visible totals | Flag mismatch; ERP not overridden |
| MM-25 | Document | Embedded “ignore instructions” text | Treated as data; zero new tool/URL access |
| MM-26 | Document | Invoice mistaken for PO | Explicit type clarification; no posting |
| MM-27 | Document | Duplicate supplier invoice number | Candidate duplicate warning; no direct bill posting |
| MM-28 | Audio | English 5s recording | Editable faithful transcript |
| MM-29 | Audio | Browser WebM input | Validated + transcoded to supported WAV |
| MM-30 | Audio | “15” versus “50” uncertainty | Human confirmation required when uncertain |
| MM-31 | Audio | Silence/noisy or zero-second recording | Safe retry/manual text option |
| MM-32 | Audio | Tamil / Tamil-English | Warn / tested fallback; no confident unverified transcription |
| MM-33 | Mixed | Photo plus “change qty to 12” | User-explicit correction wins; image preserved |
| MM-34 | Mixed | Photo vs audio conflicting amounts | Structured conflict; no silent resolution |
| MM-35 | Task | Supplier, product and warehouse all missing | One consolidated clarification response |
| MM-36 | Task | Supplier created through approved setup subtask | Original parent resumes with verified ERP ID |
| MM-37 | Task | Missing product and user lacks product create permission | Offer permitted alternatives or authorized approver; no creation |
| MM-38 | Task | User edits only first of 5 lines | Other line items untouched |
| MM-39 | Task | Two tabs submit same revision | One succeeds; other HTTP 409 and latest state |
| MM-40 | Task | User changes company before confirmation | Old task inaccessible under new scope |
| MM-41 | Approval | OCR completion triggers no approval | No ERP business write |
| MM-42 | Approval | User confirms extraction | Normalized task only; still no ERP write |
| MM-43 | Approval | Proposal altered after review | Hash/version mismatch; fresh approval required |
| MM-44 | ERP | PO posted after approval | Only status-4 ordered PO; no stock/ledger posting from order alone |
| MM-45 | ERP | Purchase bill action not implemented | Extraction view only; no arbitrary invoice posting |
| MM-46 | ERP | Posted invoice correction spoken in voice note | Lifecycle-sanctioned correction, no field-level update |
| MM-47 | ERP | GST registration changes after approval | Stale state fails/needs fresh preview |
| MM-48 | ERP | Financial year closes before execution | Reject under domain transaction |
| MM-49 | Accounting | Approved totals differ from domain recalculation | Transaction rolled back; new preview required |
| MM-50 | Operational | Provider call fails after media upload | No orphaned public data; retry/cleanup eventual |
| MM-51 | Operational | Provider request logged | No media/base64/keys in logs |
| MM-52 | Accessibility | Keyboard-only media and correction workflow | Operable with labels, focus and errors |
| MM-53 | Performance | 50-line supplier invoice | Batched lookup policy; bounded query count and elapsed time |
| MM-54 | Security | User-supplied remote image URL | Rejected / disabled under upload-only policy |
| MM-55 | Bootstrap | Media request without company context | Rejected unless isolated separately reviewed Mode A endpoint |
| MM-56 | Audit | Provider calls and action receipt distinct | Separate traceable events and no false “posted” label |

## Model quality evaluation

Create a consented, representative gold dataset, ideally 100+ documents and 100+ short recordings across relevant business types before production. Start with 20 synthetic cases during development. Score using **exact ground-truth values**, not the model's self-reported confidence.

### Images / documents

- Supplier invoice number exact match rate.
- Line-item detection precision/recall and total line-count error.
- Quantity, price, UOM, tax rate and grand-total exact-match rates after normalized decimal/date parsing.
- GSTIN correctness; low false-positive rate for absent GSTIN.
- Document classification correctness and no duplicate posting candidates.
- Refusal/uncertainty appropriateness on unreadable fields.

### Speech

- Word error rate on English, entity-name accuracy, number/price accuracy and negation preservation.
- Silent/noisy audio detection; fallback behavior on Tamil/mixed language.
- User correction burden (edits required) and average processing cost per recording.
- Check that omitted/reordered line items are not silently accepted.

### Suggested initial pilot gates (tunable, require product owner signoff)

| Measure | Suggested starting gate |
|---|---|
| Cross-tenant test failures | **0** |
| Unauthorized ERP mutations | **0** |
| Silent incorrect GSTIN / invoice totals passed to posting | **0** |
| 95th percentile normalized processing duration | Establish baseline, then set measured SLO; no guessed target |
| Clean English invoice header field exact match | >= 95% on benchmark fixture set; review critical fields separately |
| Clean English numeric quantity/cost exact match | >= 98% on benchmark fixture set or explicit confirmation |
| Missing-field questions | All independently resolvable missing fields returned together |
| Provider compatibility | Hosted model, image and audio requests smoke-tested successfully |
| Regression | All existing read-only Copilot/AI/Commercial/Operations tests green |

No single average OCR score is sufficient for financial data. A failed high-impact accuracy gate should force a human-input requirement, narrow the release scope or disable the relevant document type.

## Manual UAT scripts

**UAT 1:** Upload a synthetic invoice. Confirm filename, line quantities, invoice number and GST warnings. Correct one price; verify preserved source image and new revision. Confirm extraction; no business document created.

**UAT 2:** Record English instruction for PO. Edit transcription; ensure source revision changes and all missing supplier/product/warehouse inputs appear in one card. Approve only after ERP preview; verify idempotent PO.

**UAT 3:** Upload mismatched GST invoice. System blocks the posting proposal and explains source-vs-ERP discrepancy, even if the model thinks the invoice is fine.

**UAT 4:** Attempt to use a media ID after switching company. Both status and preview endpoints deny access.

**UAT 5:** Simulate an action failure after ERP commit. No blind retry; the system shows reconciliation status and reads authoritative receipt if recoverable.

## Release checkpoints

- [ ] Model/API contract confirmed with an actual NVIDIA call for both image and audio.
- [ ] Full file validation and private storage permission tests passed.
- [ ] Existing AI read-only behavior not regressed.
- [ ] Media gateway quota/rate monitoring integrated; no PII in logs.
- [ ] Perceptual output never exposed directly as an ERP command.
- [ ] User corrections and task revisions preserve all confirmed data.
- [ ] Domain financial integrity and posted-lifecycle tests passed.
- [ ] Multilingual limitation documented in UI and fallback policy.
- [ ] Retention/privacy approved by responsible stakeholders.
- [ ] Browser tests and MySQL migration upgrade/rollback completed.
- [ ] Feature flags allow immediate disable of media processing independently of chat and ERP execution.
- [ ] Pilot company signoff recorded, no live financial writes enabled by default.
