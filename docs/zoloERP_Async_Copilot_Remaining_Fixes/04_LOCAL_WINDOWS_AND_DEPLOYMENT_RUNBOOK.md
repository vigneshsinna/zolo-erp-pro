# 04 — Local Windows setup, diagnosis and deployment runbook

## Before implementation / reproduce original error

Run from actual Laravel project directory serving `httpzolo-erp-pro.test.test`. Verify web server document root and active checkout; do not assume CLI and web PHP use same ini. Commands are diagnostic examples, not commands already executed here. Do not expose `.env`, keys or private invoice contents.

```powershell
git branch --show-current
git rev-parse HEAD
git status --short
php -v
php --ini
php artisan about
Get-Content .\storage\logs\laravel.log -Tail 150
Select-String -Path .\public\js\ai-media-input.js -Pattern 'automatically preparing ERP proposal','processing-tasks','prepare_now'
```

Check the actual local stack web PHP error log as well (IIS/Apache/Nginx/Herd/Laragon, whichever used). Search request timestamp/correlation for timeout, cURL 28, provider timeout, preprocess, storage, SQL, credit errors. CLI `php --ini` does not prove FPM/web settings. Do not create a public phpinfo endpoint. Browser DevTools Network: find actual `/media/prepare` request, response code/timing, duplicate calls, JS asset URL; do not share unredacted payload with credentials or private invoice. Record SHA, effective config, user/company scope, PHP version, sanitized timings. Compare small readable JPEG only as diagnostic; no proof size alone caused timeout. Inspect actual billing ledger before retry if a reservation was held.

```powershell
php artisan tinker
```

```php
config('queue.default');
config('queue.connections.database.retry_after');
config('ai_multimodal.timeout_seconds');
config('ai_multimodal.max_retry_attempts');
config('ai_multimodal.allow_direct_sales_invoice');
config('ai_multimodal.max_image_dimension');
```

Do not enable sales invoice unless current test tenant permissions and feature/licensing authorize it. Existing source flag `AI_MULTIMODAL_ALLOW_DIRECT_SALES_INVOICE=true` only for approved test tenant; it does NOT fix synchronous timeout.

## After implementing async PRs

Use dedicated connection/queue rather than changing unrelated ERP jobs. Prefer database queue for simple local setup if existing schema and driver support it; Redis for production. Proposed config keys are illustrative; wire actual `config/ai_multimodal.php`, `config/queue.php` and worker dispatch connection explicitly. Example local configuration after PR implementation:

```dotenv
# Existing flag, only in approved test environment
AI_MULTIMODAL_ALLOW_DIRECT_SALES_INVOICE=true
# New proposed keys: implement and verify config mapping first
AI_MEDIA_ASYNC_ENABLED=true
AI_MEDIA_QUEUE_CONNECTION=ai_media_database
AI_MEDIA_QUEUE_NAME=ai-perception
AI_MEDIA_QUEUE_RETRY_AFTER=240
AI_MEDIA_JOB_TIMEOUT=180
AI_MULTIMODAL_TIMEOUT_SECONDS=75
AI_MEDIA_PROVIDER_IMMEDIATE_RETRIES=0
```

`ai_media_database` is a proposed Laravel connection name, not existing connection. Define driver `database`, actual jobs table/connection, queue name and retry_after from config; verify `after_commit` or durable outbox dispatch. If using existing `database` connection in development, `QUEUE_CONNECTION=database` may be acceptable but verify impact on all other queued jobs and actual retry_after. Do not assume adding env variable alone changes hardcoded config. Do not run migrations against production casually. Inspect existing jobs/failed_jobs tables and migrations first; only create if absent, use version-supported artisan command. Review pending migrations, back up test data, run in local test tenant.

```powershell
php artisan migrate:status
php artisan config:clear
php artisan view:clear
php artisan route:clear
# After inspecting migration and target database:
php artisan migrate
# Dedicated worker in another terminal (only after connection exists)
php artisan queue:work ai_media_database --queue=ai-perception --timeout=180 --tries=2 --sleep=1
```

Use configured job attempt policy from file 02; CLI tries is not the sole provider retry limit. On Windows native PHP, PCNTL may be missing; do not rely solely on worker timeout. Use WSL/Linux/container for hard timeout and kill tests; ensure DB/storage paths and worker environment match web process. Worker must have access to private media, DB, cache/lock backend, same approved provider config and correct TLS. Restart workers after code/config deployment (`php artisan queue:restart` for supported workers, supervised restart if needed). Verify process and heartbeat; no scheduler/worker assumed running because dispatch returned 202. If queue is empty inspect task/outbox; do not blindly retry all failed jobs. Local queue worker does not fix original synchronous route unless R13 actually changes it.

## UI deployment verification — why repeated UI changes may not appear

1. Confirm `workspace.blade.php` from actual document root loads expected `versioned_asset('js/ai-media-input.js')`, optional new renderer, and CSS. Check branch and server checkout. If helper hashes or manifests assets, inspect actual implementation; regenerate supported asset version/manifest if needed. Existing files in `public/js` may be served directly; do not assume npm build will update them. If Vite/Mix applies to another asset, follow actual repo build script.
2. Open DevTools Network with Disable cache, hard reload, inspect JS response. Search for new renderer/stage keys and old spinner text. Verify asset URL/query version/hash, no stale reverse-proxy/CDN/service-worker caching, no 404 or CSP error. Inspect console for script syntax/init error and duplicate send listeners.
3. Send actual file in authorized test workspace. Verify the actual handler calls async route, returns 202 and makes status GET requests; if it still sends synchronous `/prepare` with no async contract, implementation/wiring or flags wrong. Check response payload and scope; no synthetic progress in browser. If polling returns queued forever, verify actual worker/queue/heartbeat/connection and job claim; if processing stuck inspect stage and lease/deadline.
4. Check route/config cache and deployment PHP OPcache/restart as appropriate to actual stack. `php artisan optimize:clear` is an optional local diagnostic; understand it may clear app cache, do not run indiscriminately in production. Use targeted cache clears above. Reload actual web server if OPcache stale. Do not delete production cache/queue to hide bug.
5. Compare screenshot to file 03; verify multiple actual stage states, retry, refresh, correct review. Save sanitized asset proof and branch SHA. If actual served asset is current but UI still one line, trace actual Send callback and feature flag; fix call-site not just unused renderer. No claim of done until browser tests pass.

## Production deployment

Use additive schema and migration rehearsal against backup/sanitized copy. Separate dedicated queue connection with retry_after > worker timeout; Redis persistent configured with proper visibility and supervisor/systemd/Horizon only where existing stack supports it. Use one or controlled multiple workers based on shared quota; configure restart, bounded memory/time, health checks and heartbeat, task/outbox recovery scheduler, private storage and locks shared across processes, logs/alerts. Ensure same feature/permission config across web and workers. Stop new admissions and safely drain/reconcile on deployment; do not abandon in-flight provider attempts. Do not process expired/revoked scope. Supervisor timeout/stop wait must align with worker deadline and lease. Run canary with explicit tenant and actual model before wider enablement. Set flags off by default and no sync fallback. Rollback disable admissions, preserve tasks, settle/reconcile provider attempts, retain media/audit under policy, revert UI safely with compatible API; never delete posted ERP records or credit ledger to reset state. If async tasks exist, retain status/recovery endpoints until resolved. Record deployment SHA/asset hashes and smoke-test actual app, not only CI.
