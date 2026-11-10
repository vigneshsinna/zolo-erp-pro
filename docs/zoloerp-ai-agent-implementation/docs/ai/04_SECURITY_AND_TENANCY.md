# 04 — Security, multi-company tenancy and permission model

## Fundamental rule

**The AI model is an untrusted planner and text formatter, not a security principal.** It cannot choose its own user, company, branch, financial year, permission or tool scope. Every tool executes as a currently authenticated and authorised **human actor**.

## `AiPrincipal` proposal

Immutable server-created record: `user_id`, `company_id`, `branch_id`, `financial_year_id`, `tenant_boundary_id?`, `is_active`, `permitted_tool_names`, `request_id`. Store enough to re-resolve later, but **never** treat a serialized list of permissions as permanent authorization.

### Request construction

1. Authenticate via existing web session or Sanctum. Validate API scope and CSRF where applicable.
2. Use `CompanyContextResolver::resolve(authUserId, requestedCompanyId?, requestedBranchId?, requestedFyId?)`, only after verifying the chosen values against existing membership/branch rules.
3. Apply existing effective-role logic, business feature flags, branch, read/report permissions and data sensitivity restrictions. Use Spatie only where those permissions are actually wired to the business domain; do not infer authority from a role label.
4. Attach `AiPrincipal` as an **application-owned value** to the run. Tool invocations call `AiPrincipalAuthorizer::refresh(principal, tool)` and fail closed.
5. Async worker reloads the user, validates activity/membership and selected FY again; actor identities are not re-created via `auth()->login()` shortcuts.

## Explicit blockers in existing services

- `FinancialReportService::ageing()` and various other finance methods call `CompanyWriteGuard::context($context, auth()->id())`. A background AI job **does not automatically have HTTP authentication**. Introduce a read-specific, actor-explicit facade that preserves the service's authorization and reporting semantics. Refactor shared services only with regression coverage; do not spoof global auth in a job.
- `InventoryAvailabilityService::forProduct()` accepts only IDs and some raw DB queries, without authoritative company scope. Create `AuthorizedInventoryQuery` that verifies product/warehouse/stock identity ownership and branch before calling the core calculation; extend its implementation for safe joins if necessary.
- `CompanyInsightService::companyInsight(companyId)` is company-wide. Never expose it to branch-limited actors without branch-aware correctness and business permission checks.

## Tool policy matrix

| Permission tier | Examples | AI exposure |
|---|---|---|
| `ai.use` + domain report read | Trial balance, customer dues, sales by item | PH1 read tools, bounded |
| `ai.draft` + business create permission | Purchase/collection reminder draft | PH2 draft only; no send/post |
| `ai.approve` + transaction permission | Approval of a vetted proposal | PH2 separate confirmation endpoint |
| `ai.admin` | Model/usage policy, tracing settings | PH1 administrator controls |
| Highly sensitive | Journal posting, payment, stock quantity, fiscal-year close, payroll | Not enabled in MVP; separate security/design review |

The permission strings above are **proposals** to create/map; they do not yet exist in the repo. Effective roles can vary by company; revalidate both scope and permission for each operation.

## Prompt injection and unsafe data

- Treat user-supplied documents, invoice descriptions, company names, tool results and RAG chunks as **data**. Never let their text override system policies, call tools or reveal secrets.
- Validate model function call JSON on the server; use explicit schemas and allowlisted IDs; reject SQL fragments, URL fetch requests or code instructions in a tool field.
- Never expose broad `WebFetch`, file-system write/delete, process execution, unrestricted MCP clients or database console tools to general users.
- Escape AI output in Blade. Sanitise limited Markdown through a maintained renderer; prevent stored XSS and unsafe links.
- No raw sensitive PII, tokens, salary records, payment credentials, tax IDs or confidential documents in observability or provider requests unless explicitly approved.
- Apply retention, deletion, export, and model-training/data-processing agreements per customer contract and jurisdiction.

## Cross-company and cross-branch attack tests

1. Company A user submits company B ID in chat, API, tool JSON, URL and conversation replay → deny.
2. Single-branch user requests all-company report or another branch's warehouse → deny unless ordinary ERP permits full-company scope.
3. Closed financial year; proposal created before lock date → reject at execution.
4. Revoked membership or deleted actor after a queued run → fail closed before any business read/write.
5. User changes company between model tool selection and tool execution → discard/revalidate; do not run using old scope silently.
6. User opens another user's `conversationId`, `runId` or proposal UUID → 404/403 without data leak.
7. MCP client with missing/expired/under-scoped token → reject, even for tool discovery where it leaks sensitive metadata.
8. RAG vector search includes other-company chunks despite malicious query text → server filters and document ACL prevent disclosure.

## Audit fields

Record `actor_id`, `company_id`, `branch_id`, `financial_year_id`, `tool_name`, `action`, `resource_type/id`, `permission_decision`, `model_name`, `prompt_version`, `request_id`, `approval_id`, `idempotency_key`, `status`, time, error code, and deterministic source references. Hash/redact prompts as default; allow short-lived raw content only under controlled debugging policy.

## Operational security

Keys in environment secret manager only; never exposed to browser. Lock model provider egress; limit request bodies; segregate AI queue/Redis; use separate tokens for MCP; audit dependencies and upstream versions; record abuse alerts and emergency AI-disable switch. Explicit opt-in for customer data leaving managed infrastructure.
