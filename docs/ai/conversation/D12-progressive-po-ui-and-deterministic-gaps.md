# D12 — Progressive PO Conversation and Deterministic-First Gap Assessment

Base: \`feature/ai-unified-input-clarification\` after merged PR #22. This is a **UX refinement**, not a replacement for D01–D11/T00–T11.

## Progressive Purchase Order interface

The previous consolidated card exposed supplier, products, warehouse, payment, date and optional fields simultaneously. For small screens and conversational work it was too long.

D12 retains the same versioned task, ERP validation, approval, scoped directory choices and voice handling, but presents **only one editable section at a time**. The current question guides the section initially displayed. Users may select another section using the step selector or Previous/Next. “Show all fields” is an explicit optional advanced action.

- Sections: Supplier; Products & Rate; Warehouse; Cash/Credit; Order Date; Other details (optional).
- Outstanding requirements remain available behind an expandable “View outstanding checks” control, rather than appearing as a long list.
- The sticky composer remains the single place for chat, browser dictation, voice recording and attachments. A single message may still provide multiple independent values.
- The server asks the next actionable question but still returns the complete \`required_inputs\` list. Nothing is hidden from ERP validation.
- Clicking a verified record still uses \`choice_set_id\`/\`choice_id\`; direct edits still send \`expected_revision\` with a batch of corrections. No extra model calls, financial executions or ledger writes are introduced.
- New master creation keeps the separate approval and can present its own missing-field collection.
- Any existing user-provided value should remain in the same draft when the visible step changes.

## Deterministic first, model second: verified scope

**Partially implemented, not complete.**

Existing:
- \`ConversationTurnRouter\` and \`ConversationalSlotInterpreter\` handle deterministic PO intent, scoped directory browsing, many field corrections, task revisions and approval gating before calling a model.
- \`ReadCustomerOutstanding\` and \`ReadInventoryStockSummary\` are authoritative PHP/SQL read facades behind permission-checked read-only handlers.
- \`VerifiedSummaryBuilder\` creates deterministic summaries of authorized ERP fact payloads.
- \`ApprovedModelGateway\` meters actual model steps, with \`NVIDIA_RPM_LIMIT\` defaulting to 35, and credit reservations around provider execution.

Gap:
- General questions such as “show receivables aging” and “show warehouse stock summary” still normally reach the \`ErpCopilot\` model to choose the read tool. Although the result is ERP-derived, provider calls can be avoided for high-confidence structured intents.
- A dedicated deterministic PO ↔ GRN ↔ Purchase Bill exception matcher has not been verified in this implementation.
- Stock vs reorder-level comparison is not part of the existing \`ReadInventoryStockSummary\` output; do not claim this has been implemented.
- Do **not** send ambiguous or unsupported finance instructions to a write route using a default action; ask for clarification.

### Recommended independent follow-up

1. Introduce a small allowlisted deterministic read-intent dispatcher **before** \`ErpCopilot\` for *unambiguous*, structured queries only; reuse \`ErpToolExecutor\`, principal authorization, audit records and \`VerifiedSummaryBuilder\` without bypassing scope checks.
2. Build dedicated, domain-tested read services for PO/GRN/Bill matching exceptions and stock vs reorder thresholds. Handle partial GRNs, tolerances, unit conversions, returns and tax differences in ERP code, not in LLM output.
3. Only use \`ApprovedModelGateway\` for OCR, unstructured interpretation, natural-language ambiguity or writing. Record route decision, tool IDs, whether a model was used, tokens and credits.
4. Add automated checks asserting **0 model calls and 0 AI credits** for deterministic read routes and **bounded model calls** for complex requests.
5. For ambiguous requests, clarify intent rather than guessing; never invent or silently choose a financial transaction type.

## QA checklist

- Initial PO task shows one compact section and step navigation, not six tall sections.
- The server returns all required field errors, but the chat bubble asks one next question.
- Switching sections does not create extra chat turns, ERP drafts or model requests.
- User can still search/select/create approved records; changed numbers pass a revision-checked PATCH.
- “Show all fields” and “View outstanding checks” remain available for power users.
- Date, warehouse, payment and purchase rates are never silently agreed by defaults.
- Mobile at 390px has no horizontal overflow. Typing, dictation and confirmed voice continue the same task.
- Separate signed approval is still mandatory before creating a PO.
