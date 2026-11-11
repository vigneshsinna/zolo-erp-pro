# 13 — Authenticated Chat, Task API and AI Command Center Frontend

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## UX architecture
Maintain Laravel Blade/Bootstrap integration in existing ERP navigation; no full SPA rewrite required. Build reusable, accessible task cards and modal/drawer components so a user can work from **chat, existing module screens, a document upload or a guided form**. Every task always has a visible company/branch/FY, operation type and approval state.

## Existing baseline API (inspect routes, don't recreate)
Current `AiChatController` handles threads/chat, tool history and read-only proposal denial. Preserve exact existing routes, session/Sanctum, CSRF, data format, model usage and provider errors. Don't weaken read-only guard to make action demos work.

## Proposed *future* route families
| Endpoint concept | Method | Policy |
|---|---|---|
| `/api/v1/ai/tasks` | POST | New task for authenticated actor/context, intent allowlist |
| `/api/v1/ai/tasks/{id}` | GET | Owned+scoped task and current state |
| `/api/v1/ai/tasks/{id}/clarify` | POST | Validate and save specific missing inputs, bump version |
| `/api/v1/ai/tasks/{id}/preview` | POST | Run deterministic domain validation, generate sealed proposal |
| `/api/v1/ai/proposals/{id}/approve` | POST | Strong auth, exact payload hash, explicit confirm, CSRF |
| `/api/v1/ai/proposals/{id}/reject` | POST | Scoped action and reason |
| `/api/v1/ai/tasks/{id}/cancel` | POST | Only before irreversible business commit |
| `/api/v1/ai/tasks/{id}/events` | GET/SSE | Scoped progress; no secret/tool internals |
| `/api/v1/ai/documents/ingestions` | POST | Reviewed upload ACL, quarantine and size/type limits |
| `/api/v1/ai/capabilities` | GET | Only enabled capabilities for actor/company; descriptions safe |

These names are **proposals**, not existing routes. Use a separate future controller/service rather than adding write logic to the current chat controller.

## UI components
`AI Command Bar` (available inside every ERP module); `Conversation Panel`; `Task Timeline`; `Entity Match Selector`; `Line Item Editor`; `Amount/Tax Summary`; `Warnings & Validation`; `Approval Dialog`; `ERP Receipt`; `Document Review Grid`; `Source Link`. User should be able to edit line items in a form, not retype the entire sentence in chat. Provide keyboard navigation and mobile layout.

## Rendering and safety
Display financial fact cards from validated server data, not model prose. Markdown is sanitised (forbid arbitrary HTML/images and validate anchors). Only same-origin, authorised ERP links from backend. Handle XSS inside product/customer names, stale sessions and multiple tabs. A model failure after transaction creation must show server receipt from status lookup, not a false "failed to create" or a duplicate retry.

## Events and progress
`task.created`, `intent.detected`, `needs_input`, `records_matched`, `preview.ready`, `approval.required`, `action.started`, `action.completed`, `action.failed`, `task.cancelled`. Every event has task/version/correlation ID; fetch over scoped route. For long-lived workflows, use polling/private broadcasts rather than keeping PHP-FPM busy indefinitely.

## Privacy notice
Explain model provider and which business fields are sent in minimal form; obtain company-specific opt-in as needed. Do not expose keys, raw tool args, hidden instructions or sensitive audit fields in browser payloads.


---

## V3: dual-mode onboarding workspace and lifecycle correction UX
Before a company exists, offer an **Admin Initial Setup** experience with a securely authorized bootstrap task (do not call company-scoped chat routes that require a missing context). The setup review shows group/company/industry/FY, the starter resources automatically created by `CompanyDirectoryService`, and any pending company-scoped follow-up masters. After commit, show company ID and MAIN branch/FY/warehouse readiness and explicitly enter the new context. Separate 'Preview company' from 'Create company' and protect double clicks/idempotent retries.

For record-edit requests, show current ERP status prominently. If already submitted/posted, replace a generic Edit button with **Available corrections** derived from the ERP domain (for example: amend, cancel, return, reverse, credit/debit note). A user editing a proposed draft sees `Revision 2 — needs fresh validation and approval`; if currently posted, no editable form should imply a direct overwrite. See `26`, `37`, `38`.
