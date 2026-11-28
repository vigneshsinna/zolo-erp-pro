# 03 — R17: Implement the real Copilot UI, not a static mock or loading message

## 1. Required visual outcome

The supplied reference `image(20261127-170219).png` (1037 × 577) shows a six-stage progress card. Match its hierarchy and behavior inside the existing ERP chat workspace. Do not paste the reference as an image, create a separate demo page, or replace the old spinner with another single line. Keep the existing purple zoloERP identity, conversation history, composer, AI engine/credits controls, and review center. The reference is an illustrative desired state, not proof of real extraction.

```text
┌─────────────────────────────────────────────────────────────┐
│  zoloERP Copilot                                             │
│  Preparing your sales invoice                    Processing  │
│  samplebill.png · 5.6 MB · View source (authorized preview)   │
│                                                              │
│  ✓  Attachment uploaded                          Completed   │
│  ✓  Image optimized and validated                 Completed   │
│  ◌  Extracting optical invoice details                       │
│     Nemotron Omni · Processing document                      │
│  ○  Resolving customer and lenses                   Pending   │
│  ○  Checking stock, GST and pricing                 Pending   │
│  ○  Preparing sales invoice review                  Pending   │
│                                                              │
│  You can continue working while processing.          Cancel*  │
└─────────────────────────────────────────────────────────────┘
```

The user message already contains the instruction and attachment; do not duplicate it unnecessarily inside the card. `Cancel` only where current backend state permits. If active company or transaction type not determined, use neutral labels. For purchase use supplier/receiving/appropriate pricing, not customer/dispatch. For non-optical use product, not lenses. For voice use transcript review and actual applicable stages. All visual states derive from actual server state.

## 2. Files that MUST be changed or explicitly verified

| File / function | Required behavior |
|---|---|
| `public/js/ai-media-input.js`, `submitStagedAttachment` (392–516) | Replace current `loadingCard` construction at 410–411 and synchronous AJAX success/error contract. Create persistent progress card, submit async task, consume 202, poll and route result. Keep existing `appendMessage` once, attachment lifecycle and scope guard. |
| Same file, `retryPreparation` and other loading placeholders around 550/589 | Route retry via same task/status controller. Remove old one-line loading UI on every feature-on media path, including saved media and failed extraction. No duplicate handlers or uploads. |
| `resources/views/backend/ai/workspace.blade.php` | Inspect actual send handler, `zoloAiMedia` integration, thread switching/loading, and asset/script order. Wire progress renderer and restoration in production `/ai` workspace, not test page. Respect existing conditional media/optical flags. |
| `public/css/pages/ai-workspace.css` | Scoped responsive card and interaction styles. Retain `versioned_asset` inclusion. |
| `public/css/pages/ai-optical.css`, `public/js/ai-optical-workspace.js` | Reuse actual optical review grid and lens resolution. Verify correct profile and field mapping, not independent mock. |
| `public/js/ai-business-workflow.js`, `ai-conversation-workflow.js`, entity picker, action details | Preserve task/revision/approval and interactive review; connect result through existing renderer; no duplicate proposal UI. |
| Proposed `public/js/ai-media-progress.js` | Optional small reusable renderer/controller; include it using `versioned_asset` before media input; export explicit integration API and test actual DOM. If implemented inside existing file, no extra script necessary. |
| Existing protected media/status routes and view config | Expose async capability, task status URLs and flags; never hardcode path or skip CSRF/scope headers. |

Search all source and served assets for old literal `Validating document, resolving records, and automatically preparing ERP proposal with` and other spinner-only variants. Feature-on execution must never fall through to them. If legacy API compatibility requires retaining a string, it must be unreachable from new workspace submission; test it. Do not remove correct existing audio or supplier review paths. Do not fix with only CSS or a model name change.

## 3. Rendering specification

- Card inside `#chat-messages`, aligned left, max width ~920px or available chat width, full width on small screens, 16–20px padding, subtle border, rounded 12–16px, neutral theme-aware surface. Stage rows use 20px icon column, flexible label and optional status column. No massive modal. Card persists through polling, refresh and result; replace only the pending review area, keep compact completed summary.
- Header: intended verified action (or Preparing document), state badge, attachment filename and size (safe text), optional authorized source link. No model label until actual model dispatch/attempt metadata from backend. Completed green check, active purple spinner plus readable status, pending gray outline, warning/failed icon and safe message, skipped labeled Not applicable. Animation restrained and honors reduced motion. Provide icon plus text, not color only.
- Stage list: actual `upload/preprocess/extract/resolve/validate/prepare` state. Show page `2 of 5` only from confirmed page manifest/counters, not estimated progress. Show retry wait from server next_retry_at (countdown informational only), elapsed since actual start if useful, no fake percent or promised completion time.
- When input needed, keep completed stages and display targeted clarification in same card/review region. Highlight missing customer/supplier/lens/warehouse, receipt/dispatch fact, ambiguous route, missing source confirmation. Disable approval until valid proposal. Never show green validation for unresolved entity. If review ready, compact stages with actual timestamps and open existing review automatically if active thread (once). Show exactly correct Sales Invoice Review/Purchase Bill Review. No action approved automatically.
- Error: friendly reason and correlation ID, actual saved-media status, `Retry extraction` / `Retry preparation` according to failed stage, `Enter details manually` through existing workflow, `View source`, `Dismiss` (UI only). Retry uses saved task/media and existing extraction if possible; user explicitly sees if new model attempt may use credits. No raw `[HTTP_500] Maximum execution time ...` in ordinary UI, preserve diagnostic in restricted logs. If upload response lost show Checking previous submission, look up by request key, only resend same key if not found. Do not restore attachment as a new submission after server accepted task.
- Queue unavailable: if verified worker heartbeat missing/stale, show Queued — worker unavailable, no invented processing. Browser network failure: show Connection interrupted — checking status, distinguish from job failed. A stale task is not automatically re-extracted. Keep one timer per active task, suspend hidden tabs or use slower polling; resume after reconnect/visibility. No full-page reload or resetting composer.

## 4. JS integration sketch (adapt to existing jQuery/API helpers)

The code below is an implementation contract, not drop-in source. Reuse existing `mediaUrl`, `baseHeaders`, `activeScopeEpoch`, `isResponseCurrent`, `getWs`, `renderUnifiedWorkflowCard` and audio routing. Add stable request key, task controller and version guard. Check actual `callback` callers: release composer/send lock on admission, not on provider completion; preserve success/error semantics for old path without duplicate user message. Ensure user can continue conversation without incorrectly binding a new draft to pending media.

```js
// Pseudocode: all network responses and text rendered safely.
const activeMediaTasks = new Map();
async function submitAsyncMedia(staged, instruction, threadId) {
    const key = staged.clientRequestId ??= crypto.randomUUID();
    const epoch = activeScopeEpoch;
    const card = mountMediaProgressCard({key, fileName: staged.fileName});
    // append user message exactly once, keep key on lost response
    const fd = buildExistingMediaForm(staged, instruction, threadId);
    fd.append('client_request_id', key);
    fd.append('async', '1');
    const snapshot = await admitOrRecoverByRequestKey(fd, key);
    if (!isResponseCurrent(epoch, snapshot.thread_id)) return;
    bindCardToTask(card, snapshot.processing_task_id ?? snapshot.task_id);
    applySnapshot(card, snapshot); // do not auto-complete later stages
    scheduleNextPoll(card, snapshot);
}
function applySnapshot(card, s) {
    if (s.state_version <= card.lastVersion) return;
    if (!isResponseCurrent(card.epoch, s.thread_id)) return;
    card.lastVersion = s.state_version;
    updateStageNodes(card, s.stages); // textContent / jQuery .text()
    updateActions(card, s.state, s.retryable, s.next_retry_at);
    if (s.state === 'review_ready' || s.state === 'needs_input' ||
        s.state === 'needs_confirmation') {
        renderExistingReviewOnce(card, s); // action + task revision keyed
    }
    if (isTerminalOrAwaitingHuman(s.state)) stopPolling(card);
}
```

Use `setTimeout` scheduled after previous response, not overlapping `setInterval`. Start at ~2 s, back off on idle/network errors up to bounded 10–15 s, respect server hint. Abort fetch/jQuery XHR on thread/company switch and dispose timer. Scope epoch and server version checks BOTH required. Never follow untrusted status URL from model; use same-origin route generated by server and validated. Store task IDs server-side with authorized thread; browser local storage may store only opaque request key/ID if needed, never tokens, invoice/clinical data or full task result. Restore cards by thread endpoint on load; dedupe using persisted task ID (not DOM timestamp). Terminal render idempotent even on repeated polls. Retry/cancel button disabled while request in flight and uses expected version. Use `aria-live=polite` for meaningful stage change, accessible status label, keyboard actions, reduced motion and visible focus. Render text using `.text()`/textContent; do not inject untrusted HTML from documents or provider. Existing markdown sanitize remains mandatory.

## 5. Transaction review must remain functional

After stage card, render actual existing review with correct transaction direction, source ownership and action key. Preserve editable optical line grid, source preview, exact lens candidates only (no unrelated shirt/linen defaults), SPH/CYL signed decimals, eye, AXIS/ADD/DIA when applicable, product/variant, qty/UOM/rate and source vs ERP totals. Direct sales/purchase do not require SO/PO. Incoming supplier bill used for resale requires explicit confirmation, customer, selling rate and dispatch location; never copy cost as price. Confirm source if required, handle missing fields in chat and structured review, no new AI call for deterministic edits. Tax/stock checks via domain services; show actual warnings and verified totals. Preview and approval exact version; changes invalidate previous approval. Use real `ApprovedActionExecutor` receipt to display Posted, never job status. Preserve media card and source reference after execution.

## 6. Browser acceptance — mandatory, not optional

Use existing browser harness or add Playwright/Dusk according to repo conventions. Serve actual app routes/assets and use test company + worker + controllable provider fake. Do not test isolated generated HTML. Test viewport 1440×900 and mobile ~390×844. Provide screenshots and sanitized network trace. Required checks:

| ID | Browser action | Expected evidence |
|---|---|---|
| UI01 | Open actual `/ai`, attach PNG, send sales bill | One user message, six-stage card, actual HTTP 202, no old spinner-only text |
| UI02 | Hold fake provider response while polling | upload/preprocess completed only after events; extraction running; later rows pending; model metadata from backend |
| UI03 | Release fake provider, need customer | Correct sales review/clarification; no false completed validation |
| UI04 | Provide exact lens and customer, preview | Current version and actual totals, no unnecessary model call; correct review type |
| UI05 | Timeout/429/worker unavailable | Friendly failure/retry wait and saved attachment; no fake progress, no HTTP500 raw message |
| UI06 | Retry using saved task | No duplicate upload or user message; actual new attempt only if required |
| UI07 | Refresh while extracting; open different thread and return | Same task/card restored; no second extraction; terminal review once |
| UI08 | Switch company/branch while response pending | Old card cannot appear in new scope; old poll aborted, backend access denied appropriately |
| UI09 | Double click Send, duplicate response, out-of-order poll | One task, one card, no stale regression |
| UI10 | Voice initial/reply, supplier photo, PDF partial, non-optical | Correct conditional labels, preserved confirmation and old functionality |
| UI11 | Mobile keyboard/focus, long filename, many rows, dark mode | No horizontal page overflow, readable icons/status, accessible buttons |
| UI12 | Inspect served JS/CSS and network cache | Actual build contains new renderer, correct route/version; test old string absent from feature-on DOM |
| UI13 | Approval and post, change after preview | No automatic posting; old approval invalid; verified ERP receipt only |
| UI14 | XSS in filename/model text; polling network offline | Escaped text, recoverable network status, no duplicate call |

A screenshot showing only one static card is insufficient. Show distinct actual queued, extraction, needs-input, review and error/retry states across time. Verify feature flag on/off and local browser after hard refresh. Report exact asset URL/hash and loaded branch SHA to catch stale deployments. The supplied reference is the visual acceptance target; not a production screenshot. UI signoff requires actual user-visible behavior, not tests of new classes alone.
