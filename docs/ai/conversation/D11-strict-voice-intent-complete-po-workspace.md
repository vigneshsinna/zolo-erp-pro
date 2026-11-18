# D11 — Strict Voice Intent & Complete Conversational Purchase Order Workspace

## Baseline and objective

Base: \`feature/ai-unified-input-clarification\` after PR #19, commit \`104158bd0ab00b3a27c7327fa78793b69fb291b9\`.

This is a follow-up to D01–D08 and the existing T00–T11 architecture pack. **Do not reimplement those phases.** The new scope fixes incorrect voice-to-Purchase-Bill routing, unsupported conversational rate updates, silent warehouse and master-rate defaults, and sequential-only UI.

## Target experience

User can say, type, dictate, or upload a recording:

> “Create a purchase order for ABC Traders, Cotton Shirt Blue, 10 pairs at ₹600, Main Warehouse, Credit 30 days.”

The same unposted PO task displays **one consolidated workspace** with:

1. Supplier: current selection, eligible supplier matches, name/GSTIN search and permission-gated new supplier initiation.
2. Product lines: existing product selection/search, stable line IDs, quantity, unit and Rate entry, additional products, and governed new product creation.
3. Destination Warehouse: active branch-scoped choices/search or governed new warehouse initiation. One remaining warehouse is **never automatically selected** in conversational PO mode.
4. Order Date: visible date input with ERP suggested default where applicable; editable before approval.
5. Payment Terms: Cash or Credit and required credit days.
6. Remaining mandatory data: every unresolved required field shown together; prompts prioritize the most important unresolved field but never hide the rest.
7. Input modes: direct form changes using a single revisioned API patch; chat/text; browser dictation with manual review; recorded/uploaded voice with confirmed transcription.
8. Proposal and approval: all values validated by authoritative ERP services, then a separate signed, versioned human approval for PO creation.

A product-master cost or sales price can be displayed as a **reference** but never becomes an agreed PO Rate without user confirmation. A missing rate cannot silently become ₹0.

## Strict intent handling

The UI must not call the purchase-bill renderer simply because action_key is omitted. Missing or contradictory action identifiers fail closed with a clarification message. First-turn audio is bound to an owned conversation, extracted, reviewed and explicitly confirmed before starting a PO task. The model's \`intent_guess\` does **not** authorize a write workflow.

Examples:

| Confirmed speech | Outcome |
|---|---|
| Create a purchase order | Start PO conversation after transcript confirmation |
| Create a PO for ABC Traders | Start PO conversation after transcript confirmation |
| Create a purchase bill | Do not create a PO or convert to a bill; clarify unsupported action in this conversational route |
| Create an order | Unclear: no write task staged |
| Create a purchase order and bill | Conflicting: no write task staged |
| What is the PO number? | Read-only question, not an action |

## UX data contracts

- Directory candidates come from the existing company/branch-scoped entity-options service. Clicks use versioned \`choice_set_id\` and \`choice_id\`; never accept a client-supplied unscreened master ID from model output.
- The complete workspace GETs \`/ai/workflow-tasks/{task_id}\` to obtain current slots, requirements and revision.
- Typed changes use \`PATCH /ai/workflow-tasks/{task_id}/inputs\` with \`expected_revision\` and \`corrections: [{field,value,confirmation:'user_edited'}]\`.
- Inputs do not change ERP documents directly. They update the collecting task, force re-evaluation and generate a proposal only after required values are validated.
- Form edits can be applied in one batch and in any order. Chat/dictation/voice updates use the same task and preserve other fields.
- Stale revisions and expired choices fail with 409. Re-fetch before retrying; never blindly replay changed values.
- All agent-proposed master creation actions remain separate, approved dependent-master proposals. Their completion resumes the parent task.

## Internal names vs displayed names

| Internal schema | User interface |
|---|---|
| \`items[*].net_unit_cost\` | Purchase **Rate** (₹) |
| \`items[*].qty\` | Quantity |
| \`warehouse_id\` | Destination Warehouse |
| \`payment_terms_type\` | Cash / Credit |
| \`credit_days\` | Credit Days |

The ERP's internal \`net_unit_cost\` stays unchanged for compatibility; only presentation and conversational synonyms change.

## Code touchpoints

- \`VoiceIntentResolver\`: explicit speech-only intent, ambiguous/wrong type refusal.
- \`AiMediaController\`, \`VoiceWorkflowReply\`, \`AiChatController\`, \`NormalizedInputBuilder\`, \`NormalizedInputWorkflowBridge\`: verified first-voice routing, transcription review, no bill fallback.
- \`ConversationalSlotInterpreter\`: deterministic corrections such as “change that unit cost to 600” or “rate 600”.
- \`PurchaseOrderRequirementsProvider\`, \`InputCompletenessEvaluator\`: no implicit warehouse or rate/terms acceptance in conversational mode; show all missing fields.
- \`TaskCorrectionService\`: invalid rate is rejected, not coerced to 0; preserve explicit user changes.
- \`ConversationTurnRouter\`: all outstanding requirements visible alongside prioritized advice.
- \`ai-conversation-po-workspace.js\`: consolidated desktop/mobile card with in-place editing and master choices.
- \`ai-conversation-workflow.js\`, \`ai-media-input.js\`, \`ai-workspace.css\`, \`workspace.blade.php\`: workspace integration, speech controls, strict UI action verification.

## QA acceptance and regression

1. PO speech opens transcript confirmation and subsequently a PO-only collecting task. No Purchase Bill Review or proposal is displayed.
2. Ambiguous speech or conflicting intent never silently chooses a transaction type, even when the perception provider reports a confident intent guess.
3. Form displays supplier, products, destination warehouse, date, rate and Cash/Credit together without waiting for sequential questions.
4. Selecting a product fills only that draft line; adding another product never overwrites earlier ones.
5. Typing Rate ₹600 or saying “change that unit cost to 600” changes exactly the intended line; quantity and other values persist.
6. When multiple product lines exist, an unspecified “change rate” must not arbitrarily target a line.
7. The only active warehouse in a branch is shown as an eligible choice, not preselected without confirmation.
8. Master cost and supplier credit defaults remain suggestions. Confirmed amount/terms must be recorded explicitly; no invented zero rate.
9. Enter several fields in the consolidated form and apply one revision-checked batch update. Verify the same task receives the correction and remains unposted.
10. Browser dictation, text chat, and recorded voice all reach the same task; the user can review and edit transcripts before changes.
11. Missing master creation requires its own governed approval; when completed, resume the original PO with other values preserved.
12. Stale choices, wrong actor/branch/company/FY and concurrent edits fail without cross-tenant writes.
13. Once all requirements are satisfied, show a pricing/tax preview and require explicit final PO approval.
14. No direct payment, stock, journal or purchase-bill posting is triggered by the PO-creation conversation.

## Validation and rollout

Run the guarded MySQL conversation and multimodal feature tests, full AI suite, normal PO UI suite, and browser fixtures in the existing repository CI. Browser fixtures mock provider responses and do **not** prove actual voice quality. Use a disposable staging company for real microphone, uploaded audio, provider latency, transcription accuracy, GST, UOM and concurrency verification.

The branch introduces **no migration**. Code revert restores prior behavior without deleting historical ERP/audit records. Keep \`AI_CONVERSATION_ENABLED=false\` by default outside authorized staging. Do not merge or enable financial execution until all release gates are satisfied.
