# 13 — Chat API, web UI, streaming and source navigation

## No SPA rewrite for Phase 1

Keep zoloERP's Blade, Bootstrap 4 and existing authenticated layout. Build a progressively enhanced `/ai` page with a small isolated JavaScript module. Avoid introducing another frontend framework until the AI feature is stable.

## Proposed authenticated endpoints

Route registration should follow the current Laravel 13 app's API/boot pattern; **route names and paths below are new proposals**, not existing routes.

| Method/path | Purpose | Initial rule |
|---|---|---|
| `GET /ai` | Blade AI workspace | `auth`, `ai.use`, selected company context |
| `POST /api/v1/ai/threads` | Create owned thread | `auth:sanctum` or session auth; company access required |
| `GET /api/v1/ai/threads` | List own, current-company threads | paginated, no other-user results |
| `GET /api/v1/ai/threads/{id}` | Fetch owned messages and metadata | ownership + scope recheck, redaction |
| `POST /api/v1/ai/chat` | Ask question; returns response or run ID | throttled; max prompt length; read-only |
| `GET /api/v1/ai/runs/{id}` | Poll run state and result | owner and current company scope |
| `POST /api/v1/ai/runs/{id}/cancel` | Cancel pending/running read run | owned run only; cooperative cancellation |
| `POST /api/v1/ai/runs/{id}/feedback` | Flag poor answer | actor ownership |
| `GET /api/v1/ai/settings` | Current permitted agents/models | role-appropriate view |

Phase 2 separate `POST /api/v1/ai/proposals/{id}/approve|reject`, with stronger auth, CSRF and explicit action confirmation. Do **not** implement those write routes in Phase 1.

## Example chat payload

```json
{
  "thread_id": "server-issued-thread-uuid",
  "message": "Show my top five customers by outstanding balance",
  "options": { "as_of": "2026-11-10" }
}
```

Do not accept arbitrary `company_id`, `branch_id`, permission arrays, tool names or provider credentials from the model or request without independently authorising requested scope. Where existing ERP UI sends context headers, process them only through its trusted `company.context` middleware and membership checks.

## Example successful response

```json
{
  "run_id": "UUID",
  "thread_id": "UUID",
  "status": "completed",
  "message": "Here are your largest outstanding customers as of 2026-11-10.",
  "facts": [{ "kind": "currency", "key": "total_outstanding", "value": "842500.00", "currency": "INR" }],
  "scope": { "company_id": 22, "branch_id": 9, "financial_year_id": 17 },
  "sources": [{ "title": "Customer ageing", "href": "/report/customer?as_of=2026-11-10" }],
  "warnings": [],
  "usage": { "tool_count": 1 }
}
```

Example values are synthetic. Source URL generation must match real named routes, with permissions checked before opening the report.

## Error responses

- `401 AI_UNAUTHENTICATED`
- `403 AI_TOOL_FORBIDDEN` / `AI_SCOPE_INVALID`
- `404 AI_THREAD_NOT_FOUND` for threads outside user scope
- `422 AI_INPUT_INVALID` / `AI_FY_OUT_OF_RANGE`
- `429 AI_QUOTA_EXCEEDED` / rate-limited
- `502 AI_PROVIDER_UNAVAILABLE` / upstream failure
- `503 AI_DISABLED` / emergency feature shutoff

A model call that fails must not return “0 outstanding” as a success result. Distinguish lack of data from tool failure.

## UI sections

1. Main navigation entry “AI Workspace” behind feature/permission flags.
2. Conversation title and history, selected company/branch/FY, provider privacy notice.
3. Input with char limit, send/stop, loading and error states.
4. Source-linked answer cards and bounded tables; totals rendered from deterministic `facts`, not copied from generated prose.
5. Reconciliation warning, as-of date, request ID, share/export only after tenant-safe review.
6. Feedback control “accurate / inaccurate / missing data” with bounded notes.
7. Accessible keyboard navigation, focus, semantic table headings and responsive layout.

## Streaming phase

After synchronous MVP: use SDK streaming and a server-supported SSE endpoint (`Content-Type: text/event-stream`), heartbeat and explicit event types (`run.started`, `content.delta`, `tool.started`, `tool.finished`, `run.completed`, `run.failed`). For complex jobs, choose poll or Laravel broadcasting rather than holding PHP-FPM processes. Ensure auth token renewal, disconnect/cancel behaviour and no cross-thread leakage. Never reveal raw tool payloads/hidden instructions to browser.

## Browser acceptance

Ask/receive answer; switch company/branch; stale tab; revoked permission; network disconnect; mobile layout; correct format INR and decimals; clicking source opens authorised report; XSS attempt in product/customer name is escaped; two concurrent tabs do not cross-scope.
