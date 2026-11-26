# Production deployment (VPS)

Production releases are automatically deployed to the VPS via GitHub Actions
`workflow_run` as soon as the **ERP completion acceptance** CI suite (`company-foundation.yml`)
passes with 100% green status on a push/merge to `main`.

A manual **Deploy to VPS** workflow dispatch from `main` is also available for
preflight checks, manual release overrides, or emergency rollbacks. The deploy
operation targets an accepted main commit that has passed the CI acceptance gate.

## Before the PHP 8.5 cutover

1. Run **Deploy to VPS** with **preflight**, target PHP 8.5, current PHP 8.3,
   and the release SHA. Preflight can run on the upgrade branch. It stages a locked
   install and verifies the PHP 8.5 ERP FPM socket, extensions, and reload permission.
2. Record the currently deployed SHA, Nginx socket, scheduler PHP and backup receipt.
   Rehearse restoring the backup and booting both releases against a private
   copy on the production MySQL version. Compare stock, trial balance, AR/AP,
   and sales and purchase totals. Verify authenticated bill and report flows.
3. Install and enable the ERP-only PHP 8.5 FPM pool on
   `/run/php/zolo-erp-php85.sock` with user `zoloerp` and group `www-data`.
   Keep PHP 8.3 and its `/run/php/zolo-erp.sock` pool available for rollback.
   The `zoloerp` sudo rules must permit reload of both ERP PHP services.
4. In the release window, pause the ERP cron entry and any ERP workers. Enter
   maintenance mode with the current release's CLI. Point only the ERP Nginx
   virtual host at the target socket, test the Nginx configuration, and reload Nginx.
5. Dispatch **deploy** with the approved SHA, PHP 8.5, and the currently deployed
   CLI version. The script prepares dependencies in a private directory,
   switches the checkout under maintenance, verifies migration status for the
   application and Manufacturing, rebuilds view cache, reloads FPM, and verifies the local Nginx login before reopening traffic. A
   cutover failure leaves maintenance active.
6. Verify public login and authorized read-only bill, inventory and accounting
   pages. Change only the ERP scheduler and worker commands to `php8.5`.
   Verify `php8.5 artisan schedule:list` and resume them.

The live app is `/var/www/zolo-erp`. Use the versioned PHP executables for this
app. Leave other sites, their sockets, and their databases alone.
Do not run `config:cache` because the application reads `env()` at runtime.

## Rollback

Pause ERP jobs and keep the site in maintenance mode. Point the ERP Nginx
virtual host back to the previous release's FPM socket and reload Nginx. Dispatch
**rollback** from `main` with the previous SHA and its PHP version. Rollback
reinstalls that release's locked dependencies and skips migrations. Resume the
previous scheduler and workers only after the previous release passes smoke tests.

If an attempted migration changed the schema or data incompatibly, keep writes
paused. Use the recovery decision and the restore-tested backup from the
release window. Restoring a snapshot requires reconciling any writes made
after it was taken.

## GitHub secrets

| Secret | Purpose |
| --- | --- |
| `VPS_HOST` and `VPS_PORT` | SSH endpoint |
| `VPS_USER` | ERP deploy account |
| `VPS_SSH_KEY` | ERP deploy account key |
| `VPS_KNOWN_HOSTS` | Pinned VPS SSH host key |
