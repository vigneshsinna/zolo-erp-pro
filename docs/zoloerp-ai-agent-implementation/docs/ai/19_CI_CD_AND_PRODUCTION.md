# 19 — CI/CD, rollout gates and production deployment

## Baseline deployment context

zoloERP runs on PHP 8.5 Laravel 13 with a production release workflow and shared VPS considerations. The AI implementation must **not** switch PHP for unrelated hosted applications. Inspect current `.github/workflows/company-foundation.yml`, `.github/workflows/deploy.yml` and `deploy/deploy.sh` before modifying anything. These files have already undergone careful upgrade/release hardening.

## Pull-request CI additions

Add a job `ai-static-and-unit` and an `ai-integration` job, then wire them into the existing **aggregate acceptance gate** (do not change release governance without owner approval).

```yaml
# Illustrative workflow fragment; merge into the actual existing workflow after review.
# Do not duplicate the entire workflow or bypass its protected checks.
ai-static-and-unit:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.5'
        extensions: pdo_mysql, zip, gd, intl, mbstring, bcmath
        coverage: none
    - run: composer install --no-interaction --prefer-dist --no-progress --no-scripts
    - run: composer validate --strict
    - run: composer check-platform-reqs --lock
    - run: composer audit --locked
    - run: php vendor/bin/phpunit --testsuite "AI Unit"
```

The test suite must be defined first. Use clean database MySQL jobs with `AI_ENABLED=false`, and separate synthetic data AI integration tests with `AI_ENABLED=true` and SDK fakes. Do not run provider-billed tests in ordinary CI. Add static secret scanning; `npm audit` with a documented legacy baseline. If Horizon/Redis enabled, add a Redis service to AI integration CI only.

## Deployment prerequisites

1. PR reviewed; all existing ERP suites and AI gates pass; application data contract, migrations, security/privacy sign-off and model DPA accepted.
2. Full operator-tested DB backup; inventory, AR/AP, trial balance and document fingerprints recorded.
3. PHP 8.5 CLI, dedicated FPM, scheduler and AI queue worker versions compatible. No new process takes workers from core ERP.
4. New migrations are **AI-owned additive** schema changes only and dry-run on production-matched snapshot; no business document modifications.
5. Secrets provisioned in secure environment; AI feature flag **off** by default.
6. Preflight package/platform checks, route/view cache tests, source links and disabled MCP/web route verification.

## Deployment order

- Deploy code and migrations behind disabled `AI_ENABLED`.
- Confirm existing ERP login, reports, stock, financial pages and PHP-FPM health.
- Configure secure model provider key and allowlisted model; check provider egress.
- Enable AI for one internal test company and two permitted roles; exercise only read-only tools.
- If enabling Redis/Horizon, start dedicated AI workers under process monitor and check that ERP document/transaction queues are unaffected.
- Expand only after successful audit, privacy checks, golden set and manual sign-off; document exact rollback toggles.

## Rollback

First **disable AI feature flags** and stop AI workers; do not roll back shared ERP business migrations to turn off AI. Revoke model/MCP credentials if compromised. If code rollback is necessary, validate compatibility of SDK vendor tables and new additive schema. A failed action proposal should be recoverable without altering ledger or stock. Preserve auditable receipts and retention policy.

## Staging and smoke probes

- AI off: API returns safe disabled status; no outbound provider requests.
- AI on for allowed company: read-only receivables question works; source reconciles; history access scoped.
- Another company/branch and revoked user denied.
- Provider down: safe error; no worker/job leak; no business state change.
- Core ERP trial balance, P&L, stock and sale/purchase screens still work.
- Queue lag and PHP memory within thresholds, no adverse effect on shared VPS.

## Danger flags and change controls

- Do not auto-deploy AI after merge without review of existing manual production dispatch and business approval policy.
- Do not expose the Horizon dashboard or MCP route publicly.
- Do not publish `.env`, cached secrets, data dumps or raw trace payloads.
- Do not install Ollama/Qdrant/Langfuse stack on the shared ERP VPS as a shortcut.
