# AI infrastructure operations

The MCP endpoint, asynchronous execution, and external telemetry are disabled by default. No deployment, worker, external account, or production database change is implied by adding these adapters.

## Routes and migration

Load `routes/ai-infrastructure.php` once from `RouteServiceProvider::map()` outside other route groups. Apply the additive migration through the normal deployment migration gate. It creates fixed token-to-actor/company/branch/financial-year bindings; client parameters and headers cannot change those bindings.

`POST /mcp/erp` supports stateless JSON-RPC MCP initialization, ping, tool discovery, and read tool calls. `GET /mcp/erp` returns 405 because server-initiated SSE is not offered. The reviewed protocol versions are `2025-11-25` and `2025-06-18`; clients must negotiate a supported version. Tool aliases come from the existing manifest. Discovery intersects the server allowlist, token binding, explicit token scopes, and current ERP permissions. Tool execution uses `ErpToolExecutor`, preserving native schemas, tenancy, and audit decisions.

Enable `AI_MCP_ENABLED` only after reviewing `ai_infrastructure.mcp.tools`. Add exact browser origins to `AI_MCP_ALLOWED_ORIGINS` if browser clients need access. Missing Origin does not bypass bearer authentication. A normal wildcard ERP token does not grant MCP access.

Provision a token from an administrator's terminal, substituting authorized IDs:

```text
php artisan ai:mcp-token ACTOR_ID COMPANY_ID BRANCH_ID FY_ID --tool=receivables.outstanding.v1 --days=30
```

The command displays the secret once. Store it in the client's secret manager. Revoke the Sanctum token or deactivate its binding to revoke access. Bindings and tokens expire independently; both must remain valid. There is no unrestricted outbound MCP URL fetcher or client-driven tool registration.

## Isolated workers

Set `AI_QUEUE_CONNECTION` to a configured asynchronous queue connection. The Redis connection's `retry_after` is 90 seconds; execution timeout is 60 seconds. Mutation jobs have one attempt and rely on the existing atomic execution claim and domain idempotency receipt. They never retry an uncertain posting automatically.

```text
php artisan queue:work redis --queue=ai-execution --tries=1 --timeout=60 --sleep=1
php artisan queue:work redis --queue=ai-telemetry --tries=3 --timeout=10 --sleep=3
```

Run these under the production process supervisor, with ERP transaction queues retaining independent capacity. `config/horizon.php` defines separate `ai-execution` and `ai-telemetry` pools for deployments that install a compatible, reviewed Laravel Horizon package. This repository does not currently install Horizon; plain Laravel workers support the implemented jobs without it. Enabling a Horizon dashboard also requires its normal administrative authorization gate.

Enable `AI_ASYNC_EXECUTION_ENABLED` together with existing action and company switches after worker readiness is verified. The async dispatch service accepts only the current signed approved proposal. API and session endpoints support explicit dispatch and execution polling. A worker reloads proposal scope and actor permissions; stale versions, revoked membership, kill switches, and invalid approvals cannot post. Completed jobs return the existing ERP receipt on redelivery.

## Optional external telemetry

Set `AI_LANGFUSE_ENABLED`, `LANGFUSE_URL`, `LANGFUSE_PUBLIC_KEY`, and `LANGFUSE_SECRET_KEY` only after privacy and operations review. Default allowed destinations are the EU and US Langfuse cloud hosts over HTTPS. A reviewed self-hosted origin requires an explicit configuration allowlist change. Redirects, URL credentials, arbitrary paths, query strings, and non-HTTPS destinations are rejected.

The gateway calls `TelemetryDispatcher::recordModel()` after its independent local audit record. Export jobs use `ai-telemetry`; queue or remote failures do not fail an ERP response. Events contain keyed actor/company pseudonyms, trace/span IDs, provider/model, status, duration, HTTP attempts, and token counts. They contain no prompt, output, customer identity, tool payload, raw exception, or API secret. Optional USD estimates use explicitly configured private contract rates per million tokens and are marked estimates. No public pricing is assumed.

The adapter exports OTLP JSON to `/api/public/otel/v1/traces` with the v4 ingestion header. It does not use the legacy trace ingestion API scheduled for removal on November 16, 2026. Validate with mocked HTTP and disposable databases before enabling any real account.

## Opt-in scheduled stock reads and purchase preparation

Load `routes/ai-schedules.php` once outside other route groups. Apply additive migration `2026_11_12_010002_create_ai_scheduled_rules.php`. Add the following guarded schedule to the application's existing scheduler:

```php
if (config('ai_infrastructure.scheduling.enabled', false)) {
    $schedule->command('ai:run-schedules')->everyMinute()->withoutOverlapping()->onOneServer();
}
```

The shared scheduler lock requires the deployment's normal shared cache. Start a separate bounded worker:

```text
php artisan queue:work redis --queue=ai-scheduled --tries=1 --timeout=60 --sleep=3
```

`AI_SCHEDULING_ENABLED` defaults to false. Authenticated API and session endpoints under `/api/v1/ai/schedules` and `/ai/schedules` create and list actor-owned rules, cancel a rule with its expected revision, and read run history. Create requires explicit `opt_in=true`, a name, the active branch's warehouse, up to 50 exact product IDs, a minimum interval of 60 minutes, a daily run cap of 1 through 24, and expiry within 90 days. Each line provides `minimum_qty` in the product's base unit and `order_qty` in its purchase unit. These are distinct quantities and are not silently converted or inferred.

Optional `prepare_purchase_order=true` also requires the purchase permission and an explicitly selected active supplier. A low-stock run reads authoritative branch stock and reservations. It stores an in-app alert, and may call the same `ActionPreparationService` as an interactive request to create a clarification task or pending purchase proposal. It never approves, executes, posts, or sends. Unreconciled stock blocks preparation. An existing current proposal or collecting task prevents duplicate draft preparation until review or expiry.

Every worker revalidates membership, tool permissions, company/global switches, branch warehouse, products, supplier, rule revision, expiry, and financial-year date. Cancellation and expiry stop queued work. Atomic company/rule locks enforce per-rule and per-company daily caps; a unique rule/time key and transactional run status prevent duplicate delivery from preparing twice. Dispatch failure and worker failure persist safe error codes. Rules expire and are explicitly cancellable; no schedule is silently enabled by installation.

## Source contracts

- [MCP Streamable HTTP specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
- [Langfuse public API and supported trace ingestion](https://langfuse.com/docs/api-and-data-platform/features/public-api)
- [Langfuse OTLP schema](https://github.com/langfuse/langfuse/blob/main/fern/apis/server/definition/opentelemetry.yml)
- [Langfuse OpenTelemetry attributes](https://langfuse.com/integrations/native/opentelemetry)
