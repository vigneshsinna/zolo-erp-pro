# Real-browser proof for document entry

These specs drive the **normal `/sales` and `/purchases` pages** with real key presses (Playwright, headless Chrome).
They never use a standalone entry screen, so they are what certifies the consolidated entry workflow.

| Spec | Proves |
|---|---|
| `document-entry.spec.mjs` | F2/F12 from an unrelated page, one-shot `?new=1`, focus, no sidebar/toolbar entry duplicating a key, `+` draft tab, the key again keeps the bill as a draft and opens one more (blank tab reused), autosave, two-tab isolation (party, lines, serial/batch tracking, payment), reload restores draft tabs, 409 *Load latest* and *Keep mine as a new tab*, the 10-tab limit, a refused post keeps its draft, DC / GRN / Quotation autosave and reopen their own drafts |
| `document-aids.spec.mjs` | `?` shortcut help, F6 new item, previous rates, party outstanding / pending bills, copy bill, extended stock tracking per tab |
| `party-details.spec.mjs` | Party details strip (GSTIN, address, credit) always matches the party picker: fresh page, real pick, `+` tab, tab switch, reload, `?new=1`; the New Party modal GSTIN check uses its own badge |
| `document-layout.spec.mjs` | Desktop viewport fit at 1366×768 and 1280×720, visible Save/actions with growing item lists and expanded details, Enter/Tab/Shift+Tab, modal focus return, item search after new/reset, and register placement. Optional rendered fixtures cover source-bound returns, edit forms, quotation conversions, packing challans, and legacy exchange entry. |
| `bill-stock-payment.spec.mjs` | Rendered Blade fixtures with real local scripts: payment due defaults and validation, dynamic required markers, product switching clears old stock references, mobile layout, and separate receipt/voucher printing. Backend effects are verified by `BillStockPaymentWorkflowTest`; browser requests never post business records. |

Run `node tests/Browser/sale-dialogs.spec.mjs` against the same seeded disposable server to verify multi-item selection, row detail editing, and the quick-create dialogs on Sales. Quick-create responses are intercepted.

For the isolated bill/payment browser check, set `ERP_EXPORT_BILL_PAYMENT_BROWSER=1` and run `BillStockPaymentWorkflowTest` and `DocumentEntryWebTest` first. Then run `node tests/Browser/bill-stock-payment.spec.mjs` with `PLAYWRIGHT_CORE` configured. Fixture HTML and screenshots stay under `scratch/`.

For AI workspace merge regression checks, run `AiChatApiAndWorkspaceTest::test_workspace_view_renders_for_authenticated_actor`
with `ERP_EXPORT_AI_WORKSPACE_BROWSER=1`, `ERP_TEST_MYSQL=1`, and an explicitly configured disposable `zolo_test_*` database.
Then run `node tests/Browser/Ai/workspace-merge.spec.mjs` with `PLAYWRIGHT_CORE` configured.
The spec checks enabled/disabled media controls, desktop/mobile layout, chat, attachment retry and clearing,
proposal handoff, approval receipts, in-chat PDF viewing, and clarification selection. All HTTP requests are intercepted.

Run `node tests/Browser/product-lookup-blur.spec.mjs` with the same Playwright settings to check the actual sales/purchase
change handlers with the real product lookup module. It covers local products, party aliases, unknown products,
HTTP failures, and input/party changes during lookup. It requires no database or server and performs no ERP writes.

## Running them

1. **Disposable database with the real schema.** Clone the seeded development database into a throw-away MySQL (never point the specs at data you care about):

   ```bash
   mysqldump -h127.0.0.1 -P3307 -uzolo_erp -p… --no-tablespaces --skip-comments --single-transaction zoloerp > clone.sql
   mysql -h127.0.0.1 -P33084 -uroot -p… -e "CREATE DATABASE zolo_browser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -h127.0.0.1 -P33084 -uroot -p… --force zolo_browser < clone.sql
   export DB_HOST=127.0.0.1 DB_PORT=33084 DB_DATABASE=zolo_browser DB_USERNAME=root DB_PASSWORD=…
   php artisan migrate --force
   php artisan tinker --execute="DB::table('users')->where('id',1)->update(['password'=>Hash::make('Browser!2026')]);"
   ```

   (`C:\Users\<you>\zolo-mysql84` is the disposable MySQL used by the PHP suites; start it with its `start.cmd`.)

2. **Serve the app against it** (same environment variables):

   ```bash
   APP_URL=http://127.0.0.1:8091 php -S 127.0.0.1:8091 -t public server.php
   ```

3. **Run** (needs `playwright-core` and a Chrome install; install the package anywhere and point `PLAYWRIGHT_CORE` at its `index.mjs`):

   ```bash
   export PLAYWRIGHT_CORE=/path/to/node_modules/playwright-core/index.mjs
   BASE=http://127.0.0.1:8091 ADMIN_USER=admin ADMIN_PASSWORD='Browser!2026' node tests/Browser/document-entry.spec.mjs
   BASE=http://127.0.0.1:8091 ADMIN_USER=admin ADMIN_PASSWORD='Browser!2026' node tests/Browser/document-aids.spec.mjs
   ```

   Set `HEADED=1` to watch, `BROWSER_CHANNEL=msedge` for Edge.

For layout verification, run `node tests/Browser/document-layout.spec.mjs` against the same disposable server.
Set `ERP_EXPORT_DOCUMENT_LAYOUT=1` when running `DocumentEntryWebTest` and `DocumentLayoutFixtureTest` with `phpunit.documents-web.xml`
and `ComplianceBrowserFixtureTest` with the default PHPUnit configuration to export source-bound returns, edit forms,
quotation conversions, packing challan creation, and exchange pages
under `scratch`. Then set `DOCUMENT_LAYOUT_FIXTURES` to that absolute directory when running the layout spec.
The browser serves these rendered Blade pages through intercepted local routes; it does not post their source records.
Set `DOCUMENT_KIND` to one entry kind for a focused rerun. Screenshots are written beside the optional fixtures.
The layout spec also checks the cloned database's existing purchase at `/purchases/1/edit`; use a seeded clone containing that record.
Packing challan finalization is exported only when an existing packing slip linked to a sale is available;
the current clone has none, so that populated workflow has no browser proof.

## Known limits of the cloned database

The development data has no chart of accounts, so a post is *refused* ("Configure an active leaf account for inventory.").
`document-entry.spec.mjs` therefore asserts the refusal keeps the draft and its tab, and asserts draft deletion if accounting
is configured. The successful-post path (draft removed in the same transaction) is covered by `WorkspaceDraftTest`.
`document-aids.spec.mjs` answers the three read endpoints with fixed JSON; their server behaviour is covered by PHP tests.

For returned job-work stock names, set `ERP_EXPORT_JOB_STOCK_BROWSER=1` and run `JobWorkStockNamingTest`, then `node tests/Browser/job-work-stock-name.spec.mjs`. The intercepted fixtures cover the correction form, bill fields, linked GRN details and both stock report rows without real business writes.

For inventory navigation and Transfers, set `ERP_EXPORT_INVENTORY_BROWSER=1` and run `InventoryWorkspaceTest`, then `node tests/Browser/inventory-workspace.spec.mjs` with `PLAYWRIGHT_CORE` configured. All requests are intercepted. It verifies grouped navigation, date/warehouse filtering, totals, details, create/import links, count/adjustment tabs, lot views and desktop/mobile empty states without business writes.

For Accounting, set `ERP_EXPORT_ACCOUNTING_BROWSER=1` and run `AccountingWorkspaceTest`, `AccountingSetupUiTest` and `CompanyAccountingWebTest`. Run `node tests/Browser/accounting-workspace.spec.mjs` with `PLAYWRIGHT_CORE` configured. Fixtures verify transfer direction, submitted lines, voucher modes, mapping search, account controls and shared Accounting screens at desktop and mobile widths. All requests are intercepted; screenshots and generated HTML stay in `scratch/`.
