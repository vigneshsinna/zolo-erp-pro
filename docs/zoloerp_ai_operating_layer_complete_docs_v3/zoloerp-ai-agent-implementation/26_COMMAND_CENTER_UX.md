# 26 — AI Command Center, Conversational Forms and Review UX

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## UX principle
**Chat for intent, forms for precision, ERP services for truth.** Avoid a 20-message dialogue to collect an invoice's 20 fields. The model extracts as many as possible, then the UI opens an editable table/form with the missing fields highlighted. The AI remains available to answer field-specific questions. Never hide current company/branch/FY or whether a record is draft, awaiting approval or posted.

## Entry points
- Global navigation: AI Command Center (conversations + task inbox + approvals).
- Command bar embedded in purchases, sales, inventory, accounting and masters; prebind current module and authorized context.
- Existing ERP record contextual action: "Explain this invoice", "Prepare similar PO", "Find overdue for this customer"; server rechecks ownership.
- Upload drop zone (PDF/image/Excel/CSV) routes to document-aware task type and secure extraction.
- Voice optional after privacy/accuracy testing, especially number, GST, UOM and local-language names; spoken output isn't confirmation of high-risk posting.

## UI flow example: "Create bill from ABC Traders"
1. Chat receives request; shows intent: `Purchase Bill` and company/branch/FY.
2. AI queries supplier matches; if more than one, shows name + GSTIN suffix/code + location as allowed; user selects exact record.
3. Line item editor shows product/SKU/UOM/quantity/rate/discount/tax status with auto-complete and validation per row.
4. Missing mandatory fields are inline, not re-requested conversationally. A clear `Validate & Preview` button recalculates everything in the ERP.
5. Preview shows supplier invoice number, taxable value, GST split, grand total, due date and **whether this is linked to existing goods receipt**.
6. For an authorised user, `Approve & Post` displays exact payload and requires explicit deliberate action; no model text approval.
7. UI displays committed bill ID/status plus links to stock/GL effects actually reported by ERP services. Failed commands show which fields or state failed and how to retry safely.

## Components and contracts
| Component | Data contract | Critical interaction |
|---|---|---|
| `IntentSummaryCard` | intent, confidence, scope, status, risk | Change intended task before data entry |
| `EntityPicker` | up to N authorized candidates + stable codes | Clear ambiguity; never auto-pick between near matches |
| `MissingFields` | JSON schema slot name, reason, allowed values | Field validation; retain confirmed values |
| `LineItemGrid` | server-backed product ID, UOM, qty, rate, tax refs | Edit recalculates preview; no hidden arithmetic |
| `EvidenceSource` | ERP source link/ref and as-of date | Open authorised original; no arbitrary links |
| `ActionPreview` | proposal ID/version/hash, exact effects, warnings | Explicit approve/edit/reject; stale preview expires |
| `TaskTimeline` | state/events, next actor, receipts | Resume, cancel before commit, inspect failures |
| `DocumentReviewGrid` | OCR values/confidence, field source bbox/page | User corrects uncertain extraction |

## Draft-saving semantics
A chat task can save incomplete **AI task state** without creating any business record. A proper ERP draft uses an explicit domain draft service and controlled permission after preview. Avoid confusing an AI plan with an actual supplier bill. Display state labels consistently: "Preparing", "Needs details", "Ready for review", "Approved", "Posting", "Posted", "Failed".

## Accessibility and mobile
Keyboard-select candidates, table row editing, focus/announcement for errors, WCAG contrast, no text in color alone, mobile-friendly card views, stable scroll for chat, confirmation dialog sized for long bills, currency accessible to screen readers. CSS must follow design-token convention; move inline style to approved page stylesheet to avoid known CI regression.

## Safety-critical UX rules
- Inactive account/warehouse, insufficient stock, closed FY, duplicate supplier invoice, invalid GST and ambiguous product block preview or approval.
- When a pricing/tax/stock lookup changes after preview, show "Recalculate and review"; discard old approval hash.
- Never show `success` before server receipt; no client-side optimistic financial posting.
- A single click can create multiple domain records only if the workflow preview clearly lists each effect and the domain supports a reviewed composite transaction.
- If user switches companies mid-task, pause and revalidate; don't carry selected supplier IDs across companies.

## Acceptance journeys
Desktop/mobile chat→PO; upload→bill review; CSV→product batch import; multi-turn clarification; shared device cross-user logout; stale browser tab; duplicate click; provider offline after posted receipt; forbidden journal by clerk. Record user journey video/screenshot and actual audit/receipt for each.


---

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.
