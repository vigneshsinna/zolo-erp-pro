# 04 — Security, Tenancy, Privacy and Approval Model

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Non-negotiable invariants
An LLM is **not** a user, authorization server, accountant or posting engine. Every operation executes as a currently authenticated human identity in a validated company, branch and financial year. The model cannot supply those identities, grant permissions, change role flags or select arbitrary data sources.

## Context design
Server-created `AiPrincipal` must include actor, company, branch, FY, current role/capabilities, request/run ID and revocation-aware status. Reconstruct scope from `CompanyContextResolver` on every tool and at queued execution time. The saved conversation scope is not a substitute for fresh permission checks. A user changing branch/FY must not see prior data without re-authorization; replayed facts must be filtered or omitted.

## Least-privilege matrix
| Capability | Default | Required controls |
|---|---|---|
| Reporting | Read only | `ai.use` + exact domain report permission + active company context |
| Master draft | Prepare only | `ai.use`, module read and create permission, scoped proposed records |
| Master change | Explicit reviewed save | Permission for each field, duplicate checks, version lock and audit |
| Purchase/sales order | Proposal and approval | Domain create/approve permission, price/tax/party/stock validation |
| Purchase bill/sales invoice | Proposal and explicit posting approval | Accounting period, supplier/customer, tax, duplicates, idempotency |
| Journal, refunds, bank, stock adjustments, payroll | Restricted by default | Separate high-risk security/business approval, dual control when required |
| Outbound messages | Draft only by default | Consent, intended recipient, content approval, delivery receipt |

## Prompt/tool/model safety
Closed input schemas; bounded lengths, enums and numbers; reject unknown fields (especially ownership fields). Use exact whitelisted service adapters. The model may suggest a record ID only as a candidate; verify company ownership and access. Treat uploaded invoice descriptions, supplier names, document text, AI output and external MCP data as untrusted content, not instructions. No raw SQL/shell/file-write functions available to model.

## Action approvals
A proposal displays exact details, quantity/UOM/currency/tax, predicted accounting and stock effects, warnings, expiry, source versions, and all items that will change. An explicit server-authenticated user gesture approves its payload hash; **"yes" in conversational text is not approval**. Immediately before execution validate same company/branch/FY, membership, permission, source record version, stock, tax, document number, duplicate invoice rules and accounting locks. Fail closed if anything changed.

## Privacy and retention
Classify ERP data: public help, internal catalog, confidential commercial, sensitive customer/supplier, tax/finance, highly restricted payroll/bank. Minimize what leaves the server, secure provider agreements and data region, do not log full prompts/tool outputs by default. Redact secret keys, phone, email, GSTIN, bank and payroll values from generic telemetry where not needed. Consent/retention/export/delete must follow actual contracts. Use encrypted proposal payloads and bounded audit metadata.

## Threat tests (must pass)
1. Cross-company, cross-branch, other FY, deleted actor and permission revocation at every step, including history and queued work.
2. Forged tool name, extra JSON fields, model-supplied `company_id` and opaque resource identifiers from another tenant.
3. Prompt injection inside invoice OCR, product name, report note and supplier PDF.
4. Same proposal approved twice, two users racing approval, retried job after successful posting, stale price/tax, FY closed after approval.
5. Unauthorized valuation, tax filing, journal, payment and outbound message requests.
6. XSS/unsafe links in assistant responses and persisted chat history.
7. Quota/rate/cost exhaustion, provider 429/timeout and queue outage with no business side effect.

## Emergency controls
Global and per-company kill switches; independent read and action flags; per-capability disabling. Disabling action execution must block server endpoints/jobs, not merely hide UI. Revoke provider/MCP secrets and suspend workers after suspected data exposure; preserve redacted audit trail and follow incident response.
