# PR 08: Automation Builder and Agent Performance Monitoring

## Implemented

Automation Builder at /ai/operations/builder:
- Opted-in low-stock checks using the existing scheduled rule service, not another execution engine.
- Active catalogue search for warehouses (branch), suppliers (company), and stock products (company), with server-side revalidation.
- Up to 50 products per rule; minimum and order quantities; alert-only or PO draft preparation.
- Interval 1/3/6/12/24/168 hours, per-rule daily cap, expiry 1/7/30/90 days, cancellation and run history.
- Financial posting and automatic ERP approval are NOT implemented. A purchase order always needs normal human approval.

Agent performance at /ai/operations/performance:
- 7/30/90 day filters; actual completed, failed, queued, cancelled runs.
- Count of flagged stock products, PO drafts and exceptions. Average and sample-p95 elapsed time.
- Linked model cost/credits only for exact run IDs; ordinary Copilot costs are excluded.
- Human-reviewed outcome accuracy; until reviewed the UI says Not measured.
- One immutable approver review per completed run, correct/incorrect.
- Reviewer-estimated gross labour savings: minutes saved × reviewer-entered hourly rate / 60, calculated in integer paise by PHP. This is NOT audited profit.

## Automated tests

Use only the disposable test MySQL database and the existing MySQL fixture safeguards.

~~~bash
php artisan migrate --env=testing --force
php vendor/bin/phpunit -c phpunit.ai.xml --filter 'AiAutomationBuilderAndPerformanceTest|ScheduledRulesTest|AiOperationsPageTest'
php vendor/bin/phpunit tests/Unit/DesignTokensTest.php
node --check public/js/ai-automation-builder.js
~~~

## Manual QA

1. Enable global and company AI, scheduling and its existing async worker in staging. Open AI Operations > Manage automations.
2. Search for an active warehouse, supplier and stock product. The warehouse must belong to the chosen branch.
3. Create a daily alert-only rule for product with minimum 5, order quantity 10, max 2 runs/day and 30-day expiry.
4. Create a PO-draft rule with an active supplier. Trigger the existing due-rule dispatcher and queue worker. Confirm that a low-stock event can prepare an unapproved PO proposal, and NOT post a PO, invoice, ledger or stock movement.
5. Add/remove products, try duplicate products, unknown IDs, invalid quantities, missing opt-in, a stale cancel revision, and >50 products. These must fail safely.
6. View rule history to confirm run completion, number of alerts and elapsed time.
7. Open Agent performance (7/30/90 days). Compare totals, failures, alerts, drafts and timing against scoped schedule run data.
8. Before any reviews, accuracy must be Not measured, savings ₹0.00. As an approver, review a correct completed run with 30 min saved and ₹600/hour benchmark. Estimated gross savings must be ₹300.00.
9. Repeat the same review or mark an incorrect result with positive savings. Both must be rejected. Incorrect review with reason and zero savings is allowed.
10. Switch company, branch and financial year. No foreign catalogue records, rules, run history, review evidence or cost information must appear. Nonapprovers must not be able to submit reviews.
11. Disable scheduling or company AI. Builder must show disabled state and reject create/catalog requests.
12. Test mobile/dark mode, keyboard lookup, CSRF, latency and quote/error escaping. Ensure PHP tax/stock calculations never invoke an LLM.
13. Compare purchases, bills, inventory movement, journals, approvals and model credits before/after editing automation config and reviews. No business writes occur.

## Operational requirements

- AI_SCHEDULING_ENABLED=true and a configured non-sync queue worker for ai-scheduled; due-rule dispatch must run.
- AI_ACTION_FOUNDATION_ENABLED=true and purchases-add capability when preparing an unapproved PO.
- Optional AI_INTERNAL_EVENT_INGESTION_ENABLED=true for internal event inbox capture.
- Do NOT enable AI_ACTION_EXECUTION_ENABLED to make stock checks work. Human signatures remain required.

## Limits

The Builder currently exposes the implemented low-stock rule type, not arbitrary AI-written rules, external bank/WhatsApp/email connectors, or automatic financial posting.
Successful automation means the scheduled check completed, not that any financial transaction was posted.
Accuracy is from human-reviewed low-stock outcomes, not purchase-bill OCR ground truth.
Savings are reviewer-entered gross labour estimates, not realized financial returns.
P95 is based on up to 5,000 most recent finished runs.

## Release gate

CI MySQL 8.0/8.4 migrations, AI scheduler and Builder fixtures, security, JS syntax and design-token checks must pass. A staging worker must exercise alert-only and PO-draft scheduled rules before merging.
