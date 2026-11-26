# 25 — Product Vision, User Outcomes and Autonomy Policy

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Product outcome
Transform zoloERP from a menu-first transactional ERP into an **AI-assisted operating workspace**. A permitted user can say or upload what happened in the business; the system understands it, resolves master data, validates the real ERP rules, previews effects, asks for only missing fields and completes a safe, approved operation. The ERP remains the system of record. Every AI task must be recoverable, explainable, cancellable before commit, and linked to the resulting ERP record.

## Primary personas and journeys
| Persona | Typical request | Successful experience |
|---|---|---|
| Shop owner | "How much is due? Order the low-stock products." | Accurate report + prepared PO(s), no silent order approval |
| Purchase operator | "Enter the supplier's bill from this PDF." | Extract, match, correct, preview GST and save/post with permission |
| Sales clerk | "Invoice Kumar for the 20 blue shirts we shipped." | Find customer/product/order/dispatch, confirm amounts, issue approved invoice |
| Warehouse operator | "Transfer these cartons to warehouse B." | Confirm UOM, batches and stock, preview and execute authorised transfer |
| Accountant | "Explain negative margin and prepare a correction journal." | Reconciled explanation; a balanced draft requiring higher approval |
| Factory operator | "Start production for 500 garments from BOM X." | Check BOM, availability, capacity; plan and approve production |
| Company admin | "Create 100 products from this Excel." | Column mapping, deduplication, row errors, dry-run, bulk approval |

## One user experience, multiple entry modes
1. **Ask:** answer with source-linked facts, no ERP mutation.
2. **Prepare:** fill a structured proposed task from text/voice/form/upload; ask clarification.
3. **Review:** editable detailed preview, validation summary, planned effects and missing fields.
4. **Execute:** authorized approval of exact immutable payload, call established ERP commands.
5. **Track:** progress timeline, receipt, undo/reversal guidance, errors and recoverable task state.
6. **Automate:** only preapproved, bounded scheduled reads/draft preparation; transactional effects remain governed by company approval policy.

## Autonomous levels and defaults
| Level | Description | Initial product policy |
|---|---|---|
| A0 | Explain and navigate | Auto read, within user's permission |
| A1 | Fill form and suggest options | Auto preparation, no business mutation |
| A2 | Save nonposted draft | Requires explicit confirmation of affected master/business data |
| A3 | Submit operational transaction | Exact approval, live validation, idempotency and audit |
| A4 | Financial/stock posting | Stronger permission and human confirmation; optional dual approval |
| A5 | Scheduled tasks | Read/draft by opt-in; financial sending/payment and destructive actions never silently enabled |

An agent's confidence score does **not** change approval level. Approval varies by operation and company policy, not by how naturally the user phrases it.

## Product north star
**Time from natural-language instruction to verified, correctly recorded ERP outcome, with zero unauthorized or incorrect financial effects.** Measure task completion, clarifications, preview edits, posting accuracy, support burden and real user time saved, not only chatbot response quality.

## Definition of excellent UX
- One simple input, plus optional voice/file/photo, accessible from every module.
- Never ask for a field the ERP can safely determine from existing authorized context; never invent missing required data.
- Show no more than a few relevant candidate record matches, with disambiguating codes/UOM/status.
- Use correct Indian currency/number formatting and multilingual text support without changing stored decimal rules.
- Display both draft/posted states; no claiming a bill exists until its service receipt is committed.
- Let user edit or cancel and resume without starting the task over; show appropriate approvals.

## Non-goals
Generic freeform SQL assistant, UI click-bot that bypasses backend policy, fully unreviewed financial autonomy, full rewrite of ERP frontend, second ledger implementation, replacing accountants, using RAG as a reporting database, or installing multiple agent frameworks for appearance.

## Done when
First an authorized administrator can create an initial company (with included MAIN/FY/warehouse/COA) through a reviewed setup proposal and confirm its readiness; then one internal user creates a real Purchase Order from natural language while another with no purchase permission is denied; both can inspect a clear audit trail. The same platform then handles an invoice, stock task, and customer master workflow without recreating generic LLM/security infrastructure.


---

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.
