# 11 — Laravel MCP server and client integration

## Purpose and release gating

Use [Laravel MCP](https://github.com/laravel/mcp) to expose a **small, authenticated set of zoloERP read tools** to explicitly trusted external AI clients, or to consume vetted external MCP services. **Do not expose MCP publicly during PH1**. In-process Laravel AI tools should call `ErpToolExecutor` directly rather than making an HTTP call back into our own MCP server.

Official docs: https://laravel.com/docs/13.x/mcp.

## Installation after PH1 approval

```bash
composer require laravel/mcp
php artisan vendor:publish --tag=ai-routes
php artisan make:mcp-server ErpReadServer
# inspect generated server/tool classes and route bootstrap integration
```

The package publishes `routes/ai.php`, separate from `routes/erp-ai.php` (internal chat endpoints).

## Server registration sketch

```php
<?php
// routes/ai.php — after verifying actual installed package API
use App\Mcp\Servers\ErpReadServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/zolo-erp', ErpReadServer::class)
    ->middleware(['auth:sanctum', 'throttle:ai-mcp']);
```

- Define the rate limiter `ai-mcp` yourself. Use Sanctum bearer scopes or a standards-compliant OAuth flow based on client needs.
- Tool-level authorization remains mandatory. Authentication alone is not sufficient.
- Server must not expose all ERP models, public resources, write operations or raw SQL tools.
- MCP server registry initially contains only `GetOutstandingTool` and perhaps `GetSalesSummaryTool`, both calling `ErpToolExecutor`.

## Adapter pattern

```
MCP Request
   -> Sanctum/OAuth user identity
   -> MCP server/tool input schema
   -> AiPrincipalFactory + existing CompanyContextResolver
   -> ErpToolExecutor (same as in-process SDK)
   -> authoritative read facade
   -> tenant-scoped, redacted MCP response
```

Model-provided `company_id` and `branch_id` are **never authority**. If client must select a company, the server re-resolves it for the authenticated user; deny ambiguous or unauthorised selection.

## Secure MCP tool implementation

Create `App\Mcp\Tools\GetOutstandingTool` via the installed package generator. Its `handle(Laravel\Mcp\Request $request): Laravel\Mcp\Response` must:

1. Read the authenticated user from `$request->user()` and enforce API token scopes.
2. Validate `as_of` and `limit`, rejecting excessive values.
3. Build an `AiPrincipal` with a **server-authorised** `CompanyContext`.
4. Call `ErpToolExecutor::run('receivables.outstanding.v1', principal, validated)`.
5. Return bounded JSON text (or structured MCP response supported by installed version) with scope/date/sources and explicit errors. Do not include secrets, raw customer exports or internal SQL.

## External MCP client policy

- Maintain allowed MCP server URLs and provider-specific credentials in a server-side registry. Restrict destination URLs and address ranges against SSRF; block local/cloud metadata destinations by default.
- No general use of `Client::web($userProvidedUrl)` or `FileStorage::all()`.
- Bind external client tools to a named requesting actor; map scopes and identity explicitly. Use OAuth or user-specific tokens for third-party accounts; never share ERP's owner token across tenants.
- Inspect discovered tool schema, permissions, actions and destinations before exposing to user/agent. Default external tools to disabled and read-only.
- Record request/audit IDs, redacted parameters, provider, external server ID and response status.

## Testing checklist

- No token: 401. Valid token but missing tool permission: deny.
- Cross-company/branch requested in tool parameters: deny.
- Tool discovery reveals only enabled, permitted tools.
- Rate limit, malformed JSON, invalid date, oversized `limit`, replay and expired tokens all denied.
- A malicious external MCP tool description cannot override system policy or invoke extra tools.
- No web MCP routes registered in production when feature flag disabled.
- Tool parity: identical internal SDK and MCP tool requests produce the same deterministic result and audit policy.
