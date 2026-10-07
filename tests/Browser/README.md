# Real-browser proof for document entry

These specs drive the **normal `/sales` and `/purchases` pages** with real key presses (Playwright, headless Chrome).
They never use a standalone entry screen, so they are what certifies the consolidated entry workflow.

| Spec | Proves |
|---|---|
| `document-entry.spec.mjs` | F2/F12 from an unrelated page, one-shot `?new=1`, focus, visible "+ New Bill" fallback, dirty dialog (Save draft / Discard / Cancel), autosave, two-tab isolation (party, lines, serial/batch tracking, payment), reload restores draft tabs, 409 *Load latest* and *Keep mine as a new tab*, the 10-tab limit, a refused post keeps its draft |
| `document-aids.spec.mjs` | `?` shortcut help, F6 new item, previous rates, party outstanding / pending bills, copy bill, extended stock tracking per tab |

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

## Known limits of the cloned database

The development data has no chart of accounts, so a post is *refused* ("Configure an active leaf account for inventory.").
`document-entry.spec.mjs` therefore asserts the refusal keeps the draft and its tab, and asserts draft deletion if accounting
is configured. The successful-post path (draft removed in the same transaction) is covered by `WorkspaceDraftTest`.
`document-aids.spec.mjs` answers the three read endpoints with fixed JSON; their server behaviour is covered by PHP tests.
