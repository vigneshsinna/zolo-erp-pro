# zoloERP — Phased Execution Plan (General ERP on zoloERP Pro)

> **Audience:** an engineer or a coding agent (Claude/Codex) that runs ONE phase per fresh chat session.
> **Spec pack:** `documents/zolo_erp_implementation_docs/00..30` (authoritative for *what*). This file says *in what order, against which real code, and how to prove it worked*.
> **Behaviour reference:** `optech_erp_modernization_project_plan.html` (11 Optech videos, 17 screens). Use it for operator UX only — **not** for architecture (see §0.6).
> **Generated:** 2026-10-03 from a full read of the spec pack + HTML plan + 4 codebase discovery passes.

---

## Canonical execution contract and stabilization decision

Document 26 owns the canonical phases 0–12. Discovery is preparatory work within Phase 0; it is not a separate numbered phase. This plan follows that numbering.

This revision adopts audit F-13 Option B: additive, inactive foundation packages may be delivered while dependent legacy defects remain open. This permits schema/context primitives, not activation or a declaration that the platform phase is complete. New capabilities wait for complete Phase 1 isolation and its acceptance gates.

| Defect | Responsible phase | Gate and reason |
|---|---|---|
| D1 invalid journal call signatures | 5 accounting; 9 affected operations | Fix before enabling affected transaction paths; shared posting contract owns the invariant |
| D2 missing/misclassified accounts | 5 accounting; 9 operations | Semantic mappings and balanced effect tests before affected posting |
| D3 missing route targets | 0 baseline | Correct handler/view and route reflection before affected routes are exposed |
| D4 stale settings cache | 1 company integration | Invalidate by company before switching or settings activation |
| D5 non-atomic legacy writers | 4 inventory; 6 commercial | No affected writer cutover without transaction/rollback proof |
| D6 unsafe runtime custom-field DDL | 1 security containment; 2 attributes | Freeze unsafe creation and enforce permissions before platform activation; normalized attributes replace it |
| D7 unregistered role alias | 1 authorization | Correct before protected route activation |
| D8 shared user-role cache | 1 company integration | Key by user/company before multi-company activation |
| D9 production reversal gaps | 9 manufacturing | Keep affected operations outside activation until line history/reversal tests pass |
| D10 unguarded optional routes | 1 authorization; 2 capabilities | Restrict unconverted routes before activation; capability checks complete in Phase 2 |
| D11 purchase payment race/status/atomicity | 5 accounting; 6 commercial | No affected payment cutover without allocation/rollback/concurrency proof |
| D12 unsafe numbering | 3 numbering | Replace and prove concurrency before numbering cutover |

The Partial/Pending purchase regression is a baseline correction, separate from D1–D12. Resolve it before dependent purchasing work. Preserve existing `fiscal_years` date ranges and migrate 1970 opening records separately.

SQLite service fixtures may prove isolated logic. They cannot substitute for MySQL DDL/recovery, concurrency, representative legacy-data rehearsal or original zoloERP Pro smoke flows. Completion requires evidence, not scaffolds.

## How to use this plan

1. Start every session with: *"Read `documents/ZOLO_ERP_EXECUTION_PLAN.md` §0 and Phase N. Then read the spec docs listed in Phase N."*
2. Before editing, grep every reader/writer of the tables the phase touches (doc 26 rule). State observed vs target behaviour.
3. Do not start Phase N+1 while Phase N verification fails.
4. Each phase ends with: changed-file list, test output, open risks appended to §0.8 *Phase log*.

---

## The product rule (why this plan exists)

zoloERP is **one ERP for many kinds of business** — a small FMCG maker, a timber yard, a solar installer, a textile wholesaler, a general trader. They all share **one** sales, purchase, stock, accounts, GST, printing and security core. What changes per business is:

| Changes per business | Never changes per business |
|---|---|
| Which features are switched on (capabilities) | Tables for sales, purchases, stock, journals |
| Words on screen ("Godown" vs "Warehouse", "Mill" vs "Job worker") | Posting rules, tax engine, numbering |
| Extra fields on items (GSM, species, wattage, MRP) | Reports' financial truth |
| Default print format, shortcuts, report presets | Permission/lock enforcement |

**Easy for business people** is a first-class requirement, delivered by: business-profile onboarding (pick "I run a timber yard"), capability-driven menus (only show what is on), per-profile vocabulary, plain-language forms with advanced fields collapsed, a per-profile "Getting started" checklist, and UAT with real non-technical users (Phase 12).

---

# §0 Repository discovery snapshot (2026-10-03)

The discovery tables below describe the pre-foundation source snapshot. Company schema/backfill and context primitives have since been delivered; see `zolo_erp_implementation_docs/IMPLEMENTATION_PROGRESS.md`. Re-trace current readers/writers before editing; historical line numbers drift.

### 0.1 Stack facts

- Laravel 10 / PHP 8.2, `nwidart/laravel-modules ^8.3`, Sanctum, `spatie/laravel-permission ^5.8`, dompdf, `mike42/escpos-php` (ESC/POS + usable for ESC/P dot-matrix), `maatwebsite/excel`, Twilio.
- MySQL only (`config/database.php:18`, strict mode). 264 MySQL-flavoured migrations (enum, `->after()`) SQLite is permitted for isolated service fixtures only. Full schema, backfill, concurrency and cutover parity must also be proven on MySQL.
- Frontend: Blade + jQuery 3 + Bootstrap 4 + bootstrap-select + DataTables, prebuilt assets in `public/vendor/*`. No working build (no `webpack.mix.js`, no Vite). Layout `resources/views/backend/layout/main.blade.php`, sidebar `resources/views/backend/layout/sidebar.blade.php` (633 lines), POS `resources/views/backend/sale/pos.blade.php` (5513 lines).
- Fresh install = `InstallController::installProcess` → `migrate --force` + `db:seed --force` (`app/Http/Controllers/InstallController.php:46-48`). Module migrations load via provider `loadMigrationsFrom`.

### 0.2 What exists (reuse)

| Asset | Location | State |
|---|---|---|
| `SaleService::createSale(array $data, ?int $userId): Sale`, `addPayment(Sale, array, ?int): Payment` | `app/Services/ERP/SaleService.php:46,186` | Real. API-only (`Api/V1/SaleApiController.php:97,125`). Web `SaleController` does **not** use it. |
| `PurchaseService::createPurchase(array, ?int): Purchase` | `app/Services/ERP/PurchaseService.php:28` | Real. API-only. |
| `InventoryService::getStockValuation(?int)`, `transferStock(array, ?int): Transfer` | `app/Services/ERP/InventoryService.php:28,86` | Real. No negative-stock check, no journal. |
| `AccountingService::postJournalEntry(array $header, array $items): JournalEntry` | `app/Services/Accounting/AccountingService.php:29` | Real, balanced check at :58, txn at :68. Items need `chart_of_account_id`. |
| `AccountingService::getAccount(string $codeOrSubType)`, `postSaleJournal`, `postPurchaseJournal`, `postPaymentJournal`, `postExpenseJournal`, `getTrialBalance`, `getProfitAndLoss`, `getBalanceSheet`, `getGeneralLedger` | same file :109,131,267,334,403,451,526,597,673 | Real; hard-coded codes (see 0.4). |
| Double-entry tables `chart_of_accounts`, `fiscal_years`, `journal_entries`, `journal_items` | `database/migrations/2026_09_19_000001_*.php:15-88` | Real. No company/FY keys. |
| `semantic_account_mappings`, `inventory_closes` | `2026_09_19_000004_*.php:11-43` | Tables exist; **no posting code reads mappings**. No models. |
| Batch / variant / IMEI | `products.is_batch,is_variant,is_imei`; `product_batches(batch_no, expired_date, qty)`; `product_warehouse(product_batch_id, variant_id, imei_number TEXT csv, qty)`; lines carry `product_batch_id, variant_id, imei_number` | Real but IMEI = comma-separated string; no movement history. |
| Units | `units(base_unit, operator '*'/'/', operation_value)`; conversion at `SaleController.php:957-963` | Real; qty columns are unbounded `double`. |
| Invoice numbering | `SaleController::generateInvoiceName` `:1368-1395` + `invoice_settings`/`invoice_schemas` | Global, no lock. |
| GST | Only a display split: `general_settings.invoice_format=='gst'` + `state` (1=IGST, 2=CGST+SGST) at `SaleController.php:3520-3524`, `PrinterService.php:99-104`, invoice views 58mm/80mm/a4 | **No HSN, GSTIN, state_code, tax-split columns anywhere.** |
| Manufacturing module | `Modules/Manufacturing` (Production, Recipe controllers, `productions`, `product_productions`) | Real but BOM stored as CSV on `products` (`RecipeController.php:134-147`). Routes live in both tenancy branches (`Routes/web.php:20-61`). |
| Module template | `Modules/Manufacturing/Providers/{ManufacturingServiceProvider,RouteServiceProvider}.php`, `module.json` | Copy for any new module. |
| Verticals (prototypes) | water (`2026_09_19_000005`), cafe/QR (`000006`), repair/projects/bookings (`000007`), damage/exchange (`000003`); controllers in `app/Http/Controllers/{WaterLogistics,CafeOperations,CatalogueQr,Repair,ProjectManagement,Booking,Exchange,DamageStock}Controller.php`; routes `routes/web.php:842-924`, `routes/api.php:89-108` | Prototypes; treat as optional pack prototypes (doc 01). |
| Feature switch | `general_settings.modules` (text CSV). Read via `in_array('x', explode(',', $general_setting->modules ?? ''))` e.g. `sidebar.blade.php:319`; API map `Api/V1/AddonApiController.php:16-31`; toggle `HomeController.php:187-209` | Compatibility input only (doc 04). |
| Permissions | Custom `permission` middleware (role_id based) `app/Http/Middleware/PermissionMiddleware.php:18-45`; controllers `Role::find(Auth::user()->role_id)->hasPermissionTo('products-index')` (`ProductController.php:49-50`); sidebar `Auth::user()->can('sidebar_*')`; Blade `@can` override `AppServiceProvider.php:81-104`; seeds `database/seeders/Tenant/TenantDatabaseSeeder.php:114+` | Works via `users.role_id`; **nothing calls `assignRole`** → Spatie `can()` path likely fails for non-admins. |
| Settings cache | `app/Http/Middleware/Common.php:22-120` — keys `general_setting`, `user_role` (shared across users — bug), `role_has_permissions_list{role_id}` | |
| API | `routes/api.php` `prefix('v1')`, `auth:sanctum`, controllers `app/Http/Controllers/Api/V1/*` with `BaseApiController::sendResponse` | Extend, don't fork (doc 21). |
| Tests | `tests/Feature/{AccountingServiceTest,AccountingWebTest,ApiV1Test}.php` need live seeded MySQL (`User::first()`, account codes 1010/3010); `phpunit.xml` sets `DB_CONNECTION=mysql`, no DB name; no `.env.testing`; factories: `UserFactory`, `CountryFactory` only | Must build harness in Phase 0. |

### 0.3 What does NOT exist (do not assume)

- No `company_id` / `branch_id` / `tenant_id` / `business_id` on any table. Only `users.biller_id`, `users.warehouse_id`.
- No companies, branches, company membership, FY context middleware, document series, stock movement ledger, open items/allocations, tax registrations/rates/HSN, print profiles, dispatch logs, idempotency store, attribute definitions, capability tables.
- SaaS tenancy (`stancl/tenancy`) **not installed**. `config('database.connections.zoloerp_landlord')` is always null → single-DB mode. `app/Traits/TenantInfo.php`, `AppServiceProvider.php:106-142`, `Common.php:32-42` are dead SaaS remnants. Keep them inert; don't build on them.
- `Modules/Optech*` (7 modules) are unmodified `module:make` scaffolds: no migrations, stub controllers, no auth on routes.
- `modules_statuses.json` lists Woocommerce/Ecommerce/Restaurant/Project/Middleware but those dirs are absent. Boot is safe; but `general_settings.modules` containing `restaurant` activates code paths querying missing tables (`SaleController` 15+ places, `ProductController.php:437,1221,1281`) and `DatabaseSeeder.php:20-22` would fatal.
- Model `$fillable` lists columns no migration creates (products: `slug, is_online, kitchen_id…`; sales: `billing_*, waiter_id…`). Run `SHOW COLUMNS` on any real DB before trusting.
- `sales.paying_method` does not exist, yet `AccountingService.php:161` reads it → sale receipts always debit cash.

### 0.4 Known defects and stabilization dependencies

| # | Defect | Evidence |
|---|---|---|
| D1 | 10 callers pass ONE array with `items[]` + `account_code` to `postJournalEntry(array $header, array $items)` → `ArgumentCountError` (not caught by `catch(\Exception)`) → HTTP 500 | `DamageStockController.php:73`, `ExchangeController.php:157`, `RepairController.php:92`, `WaterLogisticsController.php:92,164`, `Accounting/PeriodicInventoryCloseController.php:88`, `Api/V1/CafeApiController.php:72`, `Api/V1/RepairApiController.php:142`, `Api/V1/WaterLogisticsApiController.php:81,151`. Correct pattern: `Accounting/JournalEntryController.php:59-64` |
| D2 | Account codes 1030/1040 used by controllers + `ErpAddonsSeeder.php:14-23` don't exist in `ChartOfAccountsSeeder`; repair revenue posts to 4020 (= Sales Discounts) | |
| D3 | Route → missing method: `routes/web.php:864-865` → `PeriodicInventoryCloseController::store` (only `postClose` exists, :59); `web.php:896` `menu/{slug}` → `viewCatalogue` (actual `showPublicMenu`, view `backend.qr.public_menu` missing, and behind auth) | |
| D4 | `HomeController::addonToggle` (:204) never clears `general_setting` cache | |
| D5 | `SaleController::store` commits per line (`DB::beginTransaction` :921 / commit :1051) → multi-line sale not atomic. `ReturnPurchaseController`, `TransferController` store/update have no transaction. No `lockForUpdate` anywhere | |
| D6 | `CustomFieldController::store` builds raw `ALTER TABLE … DEFAULT '<user input>'` (:43-73) → **SQL injection**; store/update lack permission check | |
| D7 | `web.php:129` uses `role:Admin` middleware alias that is not registered | |
| D8 | `Common.php:99` caches `user_role` globally (not per user) | |
| D9 | `ProductionController::store` never writes `product_productions`, but `destroy` (:660-679) reverses from it → delete never restores stock; leftover `dd($e)` at :343 | |
| D10 | Vertical web routes (`web.php:842-920`) and API routes (`api.php:89-108`) have no module or permission check | |
| D11 | `PaymentService::payForPurchase` no txn, `Payment::latest()->first()` race (:83), inverted payment_status (:50) | |
| D12 | Time-based refs collide within a second: `SaleService.php:56` `'posr-'.date("Ymd").'-'.date("his")`, `PurchaseService.php:37`, `InventoryService.php:102`, `AccountingService.php:72-73` (`count()+1`), `DamageStockController.php:44` (`rand`), `ReturnController:333`, `ReturnPurchaseController:422`, `TransferController:313,988`, `AdjustmentController:262`, `ProductionController:226`, `PackingSlipController:247`, `ExchangeController:68` | |

### 0.5 Direct stock writers (inventory for Phase 4 cutover)

| File | Approx. lines | Context |
|---|---|---|
| `app/Services/ERP/SaleService.php` | 148,155 | sale |
| `app/Services/ERP/PurchaseService.php` | 115,119-124 | purchase (also overwrites cost :116) |
| `app/Services/ERP/InventoryService.php` | 150,155 | transfer |
| `app/Http/Controllers/SaleController.php` | 980-1043 store; 2794-2799 import; 3004-3175 update; 4618-4668 bulk delete; 4815-4866 destroy | ~36 writes |
| `app/Http/Controllers/PurchaseController.php` | 247-324; 576-596; 1234-1377; 1759-1789; 1871-1901; 2028-2159 | ~34 |
| `app/Http/Controllers/ReturnController.php` | 423-478; 924-1069; 1190-1234; 1281-1326 | 35 |
| `app/Http/Controllers/ReturnPurchaseController.php` | 502-558; 784-869; 963-980; 1011-1035 | 23 |
| `app/Http/Controllers/AdjustmentController.php` | 289-311; 379-449; 521-540; 565-584 | 28 |
| `app/Http/Controllers/TransferController.php` | 398-426; 1033-1060; 1191-1329; 1464-1467; 1497-1515; 1559-1607 | 22 |
| `Modules/Manufacturing/Http/Controllers/ProductionController.php` | 270-336; 439-472; 660-679 | 17 |
| `app/Http/Controllers/PackingSlipController.php` | 200-238; 316-323 | 9 |
| `ExchangeController.php` 128-139; `DamageStockController.php` 66-68; `CafeOperationsController.php` 57; `ProductController.php` 615,718,725 (+bulk insert 2058-2071); `WarehouseController.php` 41; `app/Console/Commands/AutoPurchase.php` 110,119 | | misc |

Regenerate before Phase 4: `grep -rnE "(increment|decrement)\('qty'|->qty\s*[+-]?=|\['qty'\]\s*[+-]" app Modules`.

Journal writers: only `AccountingService.php:75,90` create rows. Legacy web `SaleController`/`PurchaseController` post **no journals at all** → web-made sales/purchases are invisible to the new ledger today.

### 0.6 Spec conflicts — resolved

| Conflict | Resolution |
|---|---|
| HTML plan + `ENGINEERING_REFERENCE.md:38-58,218-236` say "zero core modification", separate `tex_*`/`optech_*` tables, `/express-pos`, `tex_vouchers` second ledger | **Superseded** by docs 02/10/26/29: additive core changes allowed; one ledger; one sales engine; no `tex_*` transaction tables. Keep the HTML's *operator UX* (F2/F12/F9, HUD, Xerox, AutoSave, 68-line DM). |
| HTML roadmap textile-first (Weeks 1-24) | Use doc 28 order: platform → sources of truth → commercial → operations → profiles → go-live. |
| `ENGINEERING_REFERENCE.md:239-401` designs `optech_companies` / `optech_financial_years` / `VerifyOptechCompanySession` | Use generic `companies`, extend existing `fiscal_years`, and use `ResolveCompanyContext` (doc 03). The engineering reference now documents the shared architecture. |
| Existing `fiscal_years` table (global) | Extend it (add `company_id`, `status`, `lock_date`, `closed_at/by`) rather than creating a second FY table. |
| Legacy `accounts` (payment accounts used by `payments.account_id`) vs `chart_of_accounts` | Keep `accounts` as "Cash/Bank payment account" UI; add `accounts.chart_of_account_id` link; postings always hit COA. |

### 0.7 Allowed-API cheat sheet (verified signatures)

```text
AccountingService::postJournalEntry(array $header, array $items): JournalEntry
  header: entry_date, reference_type, reference_id, reference_no, description, created_by
  item:   chart_of_account_id, debit, credit, memo, partner_type, partner_id
AccountingService::getAccount(string $codeOrSubType): ?ChartOfAccount   // matches code OR sub_type
SaleService::createSale(array $data, ?int $userId = null): Sale
SaleService::addPayment(Sale $sale, array $paymentData, ?int $userId = null): Payment
PurchaseService::createPurchase(array $data, ?int $userId = null): Purchase
InventoryService::transferStock(array $data, ?int $userId = null): Transfer
Product_Warehouse scopes: FindProductWithVariant($product_id, $variant_id, $warehouse_id), FindProductWithoutVariant(...)
Module helpers: module_path('Name', 'path'), view namespace 'name::'
```

### 0.8 Phase log

Append one line per finished phase: `Phase N — date — PR/commit — tests: X pass / Y baseline-fail — open risks`.

---

# Phase 0 — Baseline, test harness, stabilise known defects

**Spec:** doc 01, doc 24 §1, doc 26 step 1.

**Do**
1. Record baseline into `documents/baseline/`: `git status`, `php artisan about`, `php artisan route:list --json > routes.json`, `php artisan test` output.
2. Test harness:
   - Add `.env.testing` (gitignored): `DB_DATABASE=zolo_test`.
   - Add `tests/Concerns/SeedsErpBaseline.php` that runs `migrate:fresh` once per suite + `TenantDatabaseSeeder` + `ChartOfAccountsSeeder`; new tests use `DatabaseTransactions`.
   - Add factories for Product, Customer, Supplier, Warehouse, Unit, Tax (copy shape from `database/factories/UserFactory.php`).
   - Fix `ExampleTest`/`UserTest` to actually use their traits.
3. Follow the stabilization disposition above for D1–D12 (§0.4). Correct baseline route defects and purchase receipt regressions here. Company integration contains unsafe custom-field DDL and closes authorization/cache defects before activation. Accounting and operational phases repair their journal callers and mappings before affected cutover. Additive primitives do not authorize unsafe routes.
4. Guard `restaurant/ecommerce/woocommerce/project` module strings: helper `module_installed('restaurant')` = in modules string **and** `Module::find()` exists.

**Verify**
- `php artisan test` green for new tests; baseline failures listed separately.
- Feature test per D1 caller: POST returns 2xx and a balanced `journal_entries` row exists.
- `grep -rn "'items' =>" app | grep -i journal` → 0 hits on postJournalEntry calls.
- `php artisan route:list` → no route targets a missing method (write a test that reflects every route action).

**Don't**
- Don't refactor stock/accounting design yet. Don't delete vertical prototypes. Don't install stancl/tenancy.

---

# Phase 1 — Company, Branch, Financial Year

**Spec:** docs 03, 05, 22 (company isolation), the company backfill runbook, and the current engineering reference.

**Do**
1. Migrations (additive): `companies`, `company_branches`, `company_user`, `company_user_branches`; extend `fiscal_years` (company_id, status open|soft_closed|closed, lock_date, closed_at, closed_by). Column lists = doc 03 §Data model.
2. DEFAULT/MAIN initialization is integrated into `erp:backfill-company-context`; do not add a separate bootstrap command. It must: create `DEFAULT` company from `general_settings` (`company_name`, `vat_registration_number`, `timezone`, `currency`), branch `MAIN`, map all `warehouses` → MAIN (add `warehouses.branch_id`), attach all users.
3. Add nullable `company_id` (+ `branch_id` where meaningful) in small migrations to the table list in doc 05 §Data model. Index each.
4. `php artisan erp:backfill-company-context {--dry-run}`: batched, prints per-table row counts / nulls / orphan FKs.
5. Use implemented `App\Services\Platform\CompanyContext` and `CompanyContextResolver` with combined branch/FY resolution. `ResolveCompanyContext` uses session `company_id`, `branch_id`, `financial_year_id` and headers `X-Company-ID`, `X-Branch-ID`, `X-Financial-Year-ID`. Treat the tuple coherently on switching. Call `assertPostingDate` in write services. Attach `company.context` after authentication only when backfill, reader/writer isolation, FY setup, constraints, jobs/cache and HTTP IDOR proofs pass.
6. Trait `BelongsToCompany` (sets `company_id` on create from context; global scope **off by default**, enabled per model only after parity check).
7. Company/FY switcher in top bar (`resources/views/backend/layout/top-head.blade.php`).
8. Queue jobs: `CompanyAware` job middleware restoring context.

**Verify**
- Dry-run then real backfill → zero null `company_id` on scoped tables (SQL check script in `documents/baseline/`).
- Feature test: user of Company A gets 403/404 for Company B sale/journal by guessed id (web + API).
- Same document code allowed in two companies; FY close in A doesn't affect B.
- Existing smoke flows (POS sale, purchase, transfer) still pass with a single DEFAULT company.

**Don't**
- Don't trust `X-Company-ID` without membership check. Don't make columns NOT NULL until backfill verified. Don't reuse `TenantInfo`/landlord code.

---

# Phase 2 — Capability engine, business profiles, vocabulary, attributes

**Spec:** docs 04, 06 (attributes), 20 (navigation/labels), 05 (custom-field replacement). This is the phase that makes the ERP "understandable by different business persons".

**Do**
1. Tables `capabilities`, `business_profiles`, `business_profile_capabilities`, `company_capabilities` (doc 04). Seed capability keys from doc 04 + `sales.wholesale`, `inventory.lot_tracking`, `manufacturing.bom`.
2. Seed profiles: `general_trading`, `fmcg_distribution`, `fmcg_manufacturing`, `textile_wholesale`, `timber`, `solar_epc`, plus the existing prototypes as optional profiles (`water_supply`, `cafe_bakery`, `repair_service`) mapped from current module strings.
3. `App\Services\Platform\CapabilityService` with exactly: `enabled($key, $company=null)`, `config($key, $default=null)`, `enable($key, array $config=[])`, `disable($key)`, `assertEnabled($key)`, `dependencies($key)`, `forNavigation()`. Cache key `caps:{company_id}`, invalidate on change. Dependency validator refuses enabling `operations.installation` without `inventory.serial_tracking`, etc.
4. Middleware `capability:{key}` (alias in Kernel). Apply to vertical routes `web.php:842-920`, `api.php:89-108`, Manufacturing module routes.
5. Legacy adapter: `general_settings.modules` CSV → capabilities (one-time migration + read-through while legacy code still checks the CSV). Replace `in_array(..., explode(',', $general_setting->modules))` call sites progressively with `capability('key')` Blade/helper.
6. **Vocabulary layer:** table `profile_terms(profile_key, term_key, label)` + `company_term_overrides`. Helper `term('warehouse')` → "Godown" (textile), "Yard" (timber), "Store" (cafe), "Warehouse" (default). Seed term keys: warehouse, customer, supplier, job_worker, item, batch, lot, sale_invoice, delivery_challan, goods_receipt, project, site. Use in sidebar + headings first.
7. **Attributes:** `attribute_definitions` + `entity_attribute_values` (doc 05). `AttributeService` renders a dynamic "More details" panel on product/party forms. Seed per-profile attribute sets (doc 06 §Industry attributes). Migrate existing `custom_fields` definitions → attribute definitions (keep old columns readable; stop creating new ones).
8. Sidebar refactor: restructure `sidebar.blade.php` into doc 20 top menu (Home, Sales, Purchases, Inventory, Accounts, People, Operations, Reports, Settings). Each item: `capability` + permission check. "Operations" shows only enabled Manufacturing/Job Work/Projects/Routes/Repair/Water/Cafe.
9. Settings → "Features" page: grouped toggles with one-sentence plain explanations ("Batch & expiry — track which lot each sale came from; needed for food/medicine"), shows dependencies, records `enabled_by`. Permission `settings.capabilities`.

**Verify**
- Unit: dependency validator; cache invalidation; `term()` fallback chain (company override → profile → default).
- Feature: disabled capability → menu hidden **and** direct URL/API returns 403.
- FMCG profile enables batch/expiry + multi-UOM; textile enables job work + dot matrix; solar enables projects + serials + installation (doc 04 acceptance).
- `grep -rn "explode(',', \$general_setting->modules" resources app` count decreases; record remaining.
- Creating an attribute executes no DDL (assert via `DB::listen` no `ALTER`).

**Don't**
- No industry-named tables for core data. Disabling never deletes history. UI hiding is not authorization.

---

# Phase 3 — Atomic document series

**Spec:** doc 12 (series part), doc 27 row "Auto series".

**Do**
1. Tables `document_series`, `document_number_reservations` (doc 12).
2. `App\Services\Platform\DocumentNumberService::next(string $docType, ?int $seriesId=null): string` — `SELECT … FOR UPDATE` on series row inside caller's transaction; FY reset policy; prefix/suffix/padding.
3. Seed default series per company/FY for: sale, pos_sale, sale_return, purchase, purchase_return, transfer, adjustment, payment_in, payment_out, journal, contra, credit_note, debit_note, delivery_challan, goods_receipt, job_work_order, production, damage, exchange, quotation, sales_order, purchase_order.
4. Replace every D12 site (§0.4). For `SaleController::generateInvoiceName` keep `invoice_settings` format options but source the number from the series.
5. Series settings screen (Optech Ctrl+F9 equivalent): auto/manual, prefix, padding, reset yearly, separate Cash/Credit series.

**Verify**
- Concurrency test: 50 parallel `next('sale')` (two DB connections / `pcntl` or artisan loop in background) → 50 unique, gapless numbers.
- FY rollover resets once per company.
- `grep -rnE "date\(\"his\"\)|count\(\)\s*\+\s*1" app Modules` → only non-authoritative uses remain (document each).

**Don't**
- Never `count()+1`, timestamps, `rand()` for authoritative numbers.

---

# Phase 4 — Stock movement ledger (source of truth)

**Spec:** doc 09 (+ doc 06 UOM). Biggest-risk phase — split into 4a/4b/4c, separate sessions/PRs.

### 4a — Ledger + generic services
1. Tables `stock_movements`, `stock_movement_lines`, `stock_batches`(or extend `product_batches` — **prefer extend**: add company_id, mfg_date, mrp, status), `stock_serials` (normalised from `product_warehouse.imei_number` CSV), `stock_dimensions`, `stock_identities` (doc 09). Quantity columns `decimal(18,4)`; base-qty only.
2. `product_uom_conversions` (doc 06); `UomConversionService` reading it, falling back to `units.operator/operation_value`.
3. `App\Services\Inventory\InventoryMovementService`: `receive`, `issue`, `transfer`, `adjust`, `reverse` (command DTOs). In the same DB transaction: lock projection rows (`lockForUpdate`), enforce negative-stock policy (company setting Allow/Warn/Block — Optech FEATURES video), validate batch/serial/dimension identity, persist `unit_cost`/`value` (weighted average per company setting), update projections `products.qty`, `product_warehouse.qty`, `product_batches.qty`, `product_variants.qty`, serial status.
4. `InventoryAvailabilityService` (on-hand/reserved/available) and `InventoryReconciliationService` + command `erp:stock-reconcile {--rebuild}`.
5. Opening-balance command: one `opening` movement per product/warehouse/batch from current projections (so ledger == projections at cutover).
6. Convert `SaleService`, `PurchaseService`, `InventoryService` to the movement service.

### 4b — Legacy commercial controllers (shadow → cutover)
1. Shadow mode: in `SaleController`, `PurchaseController`, `ReturnController`, `ReturnPurchaseController` add movement recording **beside** the existing writes (flag `inventory.ledger_mode=shadow`), wrap each store/update/destroy in one transaction (fixes D5).
2. Run reconciliation daily in UAT; when clean, flip to `ledger_mode=authoritative`: remove the direct writes per controller method, one method per commit.

### 4c — Remaining writers
`AdjustmentController`, `TransferController`, `PackingSlipController`, `ProductionController`, `ExchangeController`, `DamageStockController`, `CafeOperationsController`, `ProductController` (opening + autoPurchase), `AutoPurchase` command, `WarehouseController`.

**Verify**
- Doc 09 acceptance list (receipt→sale reconciles; transfer nets; serial can't be in two places; expired batch blocked by policy; concurrent last-unit sale: exactly one succeeds; reversal restores).
- `erp:stock-reconcile` → zero diffs on seeded + UAT data.
- End of 5c: §0.5 regenerate-grep returns only `InventoryMovementService` projection updates.

**Don't**
- Don't drop `qty` columns (reports depend on them). Don't recompute historical COGS from current `products.cost`. No new direct qty mutation anywhere.

---

# Phase 5 — Accounting hardening, open items, vouchers

**Spec:** doc 10; Optech VOUCHER ENTRY / ACCOUNTS videos (HTML Screen 07, 08-11).

**Do**
1. Add to `chart_of_accounts`: company_id, financial_report_group, control_type (ar|ap|cash|bank|none), allow_manual_posting. Make `code` unique per company. Add to `journal_entries`: company_id, branch_id, financial_year_id, document_series_id, posting_key (unique per company), idempotency_key, reversal_of_id, posted_at, void_reason.
2. `semantic_account_mappings` → add company_id; model `SemanticAccountMapping`; `SemanticAccountResolver::resolve('ar'|'ap'|'cash'|'bank'|'inventory'|'sales'|'cogs'|'output_tax_cgst'|…)`. Seed per company from `ChartOfAccountsSeeder` codes.
3. Refactor `AccountingService` → `AccountingPostingService` (keep `postJournalEntry` signature as façade): replace every hard-coded code (`AccountingService.php:133-141,269-272,336-339,405-407,646`) with resolver roles. Posting keys `sale:{id}:v1`. Repost same key = return existing entry. Posted entries immutable; `reverse(entry, reason)`.
4. Entry numbers from `DocumentNumberService` (removes `count()+1` at :72).
5. `account_open_items`, `account_allocations` + `OpenItemService` (create on posting AR/AP; allocate Against Ref / New Ref / Advance / On Account; partial allocation; reversal).
6. Link legacy `accounts` → COA (`accounts.chart_of_account_id`); fix `paying_method` lookup (read from `payments.paying_method`).
7. Voucher Hub (Optech F9): Contra F4, Payment F5, Receipt F6, Journal F7 on one screen, bill-by-bill allocation modal, cheque fields, narration templates. Same `AccountingPostingService` + `OpenItemService`.
8. Reports: Day Book, Cash Book, Ledger with 12-month (Apr–Mar) breakup + drilldown, Trial Balance, P&L, Balance Sheet, receivable/payable ageing (0-30/31-60/60+) — company/FY scoped.
9. `PeriodCloseService` + lock-date enforcement (company/FY `lock_date`; admin unlock audited).
10. Commands: `erp:ledger-reconcile` (AR/AP control = open items; `current_balance` rebuild from journal_items).

**Verify**
- Doc 10 acceptance (balanced, idempotent repost, control accounts reconcile, partial allocation exact, reversal history, scoped reports).
- `grep -rnE "'(1010|1020|1100|1200|2010|2020|4010|4020|4030|5010|6090|9999)'" app/Services` → 0.

**Don't**
- Never a second ledger (`tex_vouchers`, `optech_*`). Never edit posted journals in place.

---

# Phase 6 — Shared sales & purchase application services + fast modes

**Spec:** docs 07, 08; HTML Screens 03/04 + Sub-sections 1.1–1.12, 2.1–2.7.

**Do**
1. `SalePostingService::post(Sale $sale)` — effects only: stock issue (Phase 4), journal (Phase 5), AR open item, tax snapshot hook (Phase 7). Idempotent by posting key.
2. `SaleApplicationService::create(SaleCommand)` — full doc 07 flow (context → party/credit → price → tax → number → stock reserve → write → `SalePostingService::post` → commit → after-commit events). Idempotency store table `idempotency_keys(company_id, key, request_hash, response_ref)`.
3. Legacy `SaleController::store/update/destroy` and POS: keep their line-building logic but call `SalePostingService` (and reversal on update/destroy) — this is how web sales finally reach the ledger. `Api/V1/SaleApiController` → `SaleApplicationService`.
4. Same for purchases: `PurchasePostingService` + `PurchaseApplicationService`; landed cost (by value/qty/weight/manual); service items = no stock; optional PO → GRN → bill linkage (not mandatory); "update item cost/HSN on save" checkbox.
5. Credit control: credit limit/days on party (attributes or columns), override permission `sales.override_credit` + audit.
6. **New Sales Bill (F2) / New Purchase Bill (F12)** — keyboard accelerators into the normal `/sales` and `/purchases` pages, not separate Fast Sales / Fast Purchase modules and not a second engine. The standalone entry screens, the `sales.fast_counter` / `purchases.fast_entry` capabilities and the empty `Modules/OptechSpeedBilling` scaffold were removed; see `documents/DOCUMENT_ENTRY_CONSOLIDATION.md`.
7. The same rule applies to every document: a shortcut opens the existing page in its new-document state.
8. Party search service (`PartyQueryService`) with city-prefix/alias search; product search (`ProductQueryService`) — p95 ≤300 ms on 50k items.

**Verify**
- Doc 07/08 acceptance. Web vs API equivalent sale → identical movement/journal/open-item rows (test compares).
- Duplicate Idempotency-Key → same sale id.
- Perf: 20-line post p95 ≤1 s; fast screen warm ≤1.5 s (doc 24 budgets) on seeded large dataset.
- Keyboard-only E2E (Playwright, `webapp-testing` skill): 10-line bill without mouse.

**Don't**
- No `/express-pos` writing to separate tables. No pricing/tax/credit decided in JS.

---

# Phase 7 — GST / India compliance

**Spec:** doc 11; HTML "Automated GST Engine". Repurpose `Modules/OptechGST` as `IndiaCompliance` (or core `app/Services/Compliance`).

**Do**
1. Tables `tax_registrations`, `tax_categories`, `tax_rates` (effective-dated), `hsn_sac_codes`; product `hsn_sac_code`, `tax_category_id`; party `gstin`, `state_code`, `registration_type`; company/branch registrations.
2. Line tax snapshot columns on `product_sales`, `product_purchases`, `product_returns`, `purchase_product_return`: taxable_value, rate, cgst, sgst, igst, cess, hsn_sac, place_of_supply, reverse_charge.
3. `TaxDeterminationService` (seller reg + buyer reg/address + POS + HSN + date + flags) — the only place deciding CGST/SGST vs IGST. Remove UI/controller decisions (`SaleController.php:3520-3524` becomes snapshot read).
4. `GstinLookupProvider` interface + one HTTP adapter + manual fallback; timeout, cache, audit; format check (checksum) ≠ verified.
5. `GstProjectionService` → GSTR-1 (B2B 4A, B2C small 7, CDNR 9B, HSN 12) and GSTR-3B summaries; `GstExportAdapter(version)` CSV/JSON. **Stop and confirm current GSTN offline-tool schema at implementation time.**
6. RCM on purchases; Input/Output tax roles via semantic mapping.

**Verify**
- Doc 11 acceptance: TN→TN split; TN→KA IGST; B2C; exempt; SAC service; credit note reverses projection; provider outage leaves data intact.

**Don't**
- Don't hard-code a GST provider vendor in commercial code. Don't recompute posted tax from current rates.

---

# Phase 8 — Returns/reversals, printing, dispatch

**Spec:** docs 12 (print/dispatch), 13; HTML SERIES/ENTRY videos.

**Do**
1. Returns via shared reversal: sales return / purchase return / credit-debit notes (qty vs rate-diff vs tax-correction; stock only for qty). Dispositions (doc 13). Exchange = return + new sale + settlement (keep `exchanges` JSON read-only for history). Damage/expiry = movement + loss journal. Approval for high-value/late returns.
2. `print_profiles` + `DocumentRenderingService` (read-only DTO → A4 HTML/PDF via dompdf, thermal via existing 58/80mm views + `PrinterService`, **dot-matrix** 68-line fixed-width ESC/P text via `mike42/escpos-php` or plain text). Repurpose `Modules/OptechPrinting`. Copies (Buyer/Transporter/Office), "rate with tax / rate + tax", bank details footer, transport block.
3. `document_dispatch_logs` + `CommunicationDispatchService` wrapping existing WhatsApp settings (`2025_10_20_*whatsapp_settings*`), `SmsService`, mail. After-commit, queued/retryable; failure never unposts.
4. Reprint vs Clone-from-prior are separate commands (Optech "Xerox" = clone).

**Verify**
- Doc 12/13 acceptance: totals identical across A4/thermal/DM; messaging failure retryable; returns reverse stock/AR/AP/tax; expired FMCG return → quarantine; serial return updates serial status.
- Golden-file test for 68-line DM output.

---

# Phase 9 — Manufacturing refactor + generic Job Work

**Spec:** docs 14, 15; HTML DC & GRN video / Screen 05-06 / Sub-sections 4.1-4.5.

**Do**
1. Manufacturing: `boms`, `bom_lines`, `production_orders` (doc 14); migrate CSV recipe fields (`products.product_list/qty_list/price_list/wastage_percent/combo_unit_id`) with a validating command; adapt `RecipeController`/`ProductionController` to BOM services; production consume/output/scrap through `InventoryMovementService`; multi-output (timber); production journal when perpetual. Fix D9 by design. Capability `manufacturing.production`.
2. Job Work: repurpose `Modules/OptechJobWork` → `Modules/JobWork` (generic). Tables `job_work_process_types`, `job_work_orders`, `job_work_dispatches(+lines)`, `job_work_receipts(+lines)` (doc 15). Party role `job_worker`. Virtual warehouse per job worker (`warehouses.type='job_worker'`). Send = transfer movement (no sale, no GST sale; DC series). Receive = transfer back with accepted/rejected/loss, shrinkage threshold per process type. Service bill = normal purchase (SAC 9988 default) linked to receipt, no duplicate material value.
3. Pending-material-outside report = movement balance of job-worker locations.

**Verify**
- Doc 14/15 acceptance: textile 500→480 m = 4 %; timber volume; FMCG outsourced repack; production cost = consumption + overhead policy; reversal by opposite movements.

---

# Phase 10 — Industry packs (one session each)

Packs = **seed data + capability config + vocabulary + attributes + report presets + small focused extensions**. Location: `database/seeders/Profiles/{Fmcg,Textile,Timber,Solar,GeneralTrading}ProfileSeeder.php`; extensions only where a capability needs code. No pack creates sales/purchase/stock/ledger tables.

Each pack ships a **plain-language "Getting started" checklist** (dashboard card) and a one-page help guide in the profile's vocabulary.

### 10a General Trading (do first — proves nothing textile leaks)
Profile: core only + multi-UOM optional. UAT: purchase → sale → payment → return (doc 24). Assert no textile/FMCG/timber/solar field visible.

### 11b FMCG — doc 16
Batch/expiry/MRP capture on purchase; FEFO suggestion in sale + expiry block policy; PCS/BOX/CASE conversions; schemes (10+1: free qty still issues stock, priced 0); route/van sales only with `sales.route_distribution`; manufacturer subtype uses BOM. UAT: two batches, FEFO picks earliest valid; expiry write-off posts loss.

### 11c Textile — doc 17 (Optech client)
3-decimal MTR, UQC; attributes fabric type/construction/width/GSM/design/colour/lot/rack; job-work process presets (bleaching, dyeing, sizing, printing, calendering); transport/bale fields; 68-line DM default; city-prefix party naming option; reports: job-work pending, shrinkage, stock by group/lot, previous rates, stock ageing. UAT: grey purchase → DC → GRN → service bill → credit sale → partial receipt (Agst Ref) → print. Walk the 27-row traceability matrix (doc 27) against the HTML plan.

### 11d Timber — doc 18
`DimensionCalculationService` (L×W×T, pieces → CFT/CBM; versioned formula persisted on movement line); purchase by piece/CFT/CBM; sale picker by species/grade/dimensions selecting actual identities; dimensional invoice lines; sawing = multi-output production with offcut/scrap. UAT: receive, compute, sell subset, reconcile remaining; formula change doesn't alter history.

### 11e Solar / EPC — doc 19
Extend existing `projects` (from `2026_09_19_000007`) with site address/contact/system kW/roof notes/survey attachments + status flow; system kit = BOM expanded into quotation/order lines; serial allocation/reservation to project; dispatch → install → commission status; installed-serial history; warranty/AMC records from installed serials; project profitability from movements + journals + expenses. UAT: 5 kW quote → project → shortage PO → serial allocation → dispatch → commission → invoice → payment.

### 11f Existing prototypes (water, cafe/bakery, repair)
Map to profiles/capabilities, gate routes, route their stock/journal effects through shared services (already partially fixed in Phase 0/4c). No new feature work unless a customer needs it.

**Verify (all packs)** — doc 24 UAT per profile on fixtures `database/seeders/Uat/*`; `erp:stock-reconcile` + `erp:ledger-reconcile` clean after each UAT run.

---

# Phase 11 — Onboarding, UX polish, API, security completion

**Spec:** docs 20, 21, 22.

**Do**
1. **Onboarding wizard** (doc 20): Company → GST/state/currency → FY → "What does your business do?" (profile cards with picture + 1-line description + examples) → branch/warehouse → opening data (Excel templates per profile) → users/roles → print preference → capability summary in plain words → activate.
2. Form standard (doc 20 form rules): common fields first, "More details" collapsed, totals/actions sticky, inline create preserves state, empty-state help text, visible focus, tablet layout. Apply to party, product, sale, purchase, payment first.
3. Plain-language review: replace jargon in labels/messages (e.g., "Sundry Debtors" → "Customers (money to receive)" with accounting term in tooltip).
4. API: company middleware on all `/api/v1`, API Resources/DTOs, `Idempotency-Key` on writes, endpoints for companies/branches/capabilities/search/availability/open items/allocations/vouchers/GSTIN; vertical endpoints call shared services; optional signed webhooks via outbox (`sale.posted`, `payment.received`, `stock.low`, …).
5. Security: access = auth + company membership + branch + capability + permission + period lock. Fix Spatie path (either `assignRole` sync from `users.role_id` or move sidebar to the role_id-based check consistently). Seed permissions from doc 22 list. `AuditService` for high-risk actions (reversal, overrides, stock adjust, manual journal, opening balances, capability change, tax master change, period unlock). Tax masters editable only by `settings.tax` (Optech "server-only tax master" rule).
6. Retire Optech scaffolds per doc 29 (OptechCompany, OptechMaster, OptechAccounting → remove; SpeedBilling/JobWork/GST/Printing already repurposed). Remove empty routes; no empty pages remain.

**Verify**
- Doc 20/21/22 acceptance; IDOR suite across web/API/import; backdated post beyond lock blocked everywhere.
- Usability: 3 non-technical users per profile (trader, FMCG, textile, timber, solar) complete party/product/sale/purchase/payment unaided; log friction, fix top issues.

---

# Phase 12 — Data migration, performance, deployment, go-live

**Spec:** docs 23, 24, 25, 28.

**Do**
1. Import framework `import_batches/import_rows/validation_errors`: upload → parse → normalise → validate → preview → commit → reconcile. Templates: parties, items (+attributes), opening stock (batches/serials/dimensions), open AR/AP items, opening trial balance, open job-work/projects.
2. Optech legacy ETL (textile client) + 2-week parallel run; freeze, delta import, sign-off.
3. Performance dataset (≥50k items, ≥20k parties, ≥200k lines) and budgets from doc 24.
4. `erp:health` (DB/migrations, storage, company/FY, capability integrity, semantic mappings, series, queue, backup freshness, reconciliation status).
5. Backup (DB + uploads, off-host) + **restore rehearsal**; deploy sequence install → migrate → health → cache → worker restart → smoke; structured logs with company + correlation id.

**Verify** — doc 23/25 acceptance; rollback runbook rehearsed.

---

## Final verification — Phase 12 acceptance

1. Every spec doc 03–25 acceptance list ticked with test name or UAT evidence → `documents/ACCEPTANCE_EVIDENCE.md`.
2. Anti-pattern greps (all must be 0 outside allowed files):
   - `grep -rnE "(increment|decrement)\('qty'|->qty\s*[+-]=" app Modules` → only InventoryMovementService.
   - `grep -rnE "date\(\"his\"\)|count\(\)\s*\+\s*1|rand\(" app Modules` on reference/number code → 0.
   - `grep -rnE "'(1010|1020|1100|1200|2010|2020|4010|5010)'" app/Services app/Http` → 0.
   - `grep -rn "ALTER TABLE" app` → 0.
   - `grep -rniE "tex_|optech_" database/migrations Modules/*/Database` → 0 new tables.
   - `grep -rn "explode(',', \$general_setting->modules" app resources` → 0 (all via CapabilityService).
3. `php artisan test` full green; `erp:stock-reconcile`, `erp:ledger-reconcile`, `erp:health` clean on UAT copy.
4. Five profile UATs (General, FMCG, Textile, Timber, Solar) signed off; General Trading shows zero industry-specific fields.

---

## Global guardrails (every phase)

- One sales engine, one purchase engine, one stock ledger, one accounting ledger, one tax engine, one numbering service.
- No new direct qty mutation; no new hard-coded account code; no timestamp/count numbering; no runtime DDL.
- Never fill Optech scaffolds just because they exist. Never build `tex_*` / `optech_*` transaction tables.
- Controllers validate/authorise and delegate; services own transactions; `lockForUpdate` on stock/series/allocations.
- Every transaction-changing PR states its effect on: company scope, capability, permission, stock, accounting, tax, documents, API, audit, tests, migration (doc 30) — N/A where not applicable.
- Stop and ask when schema/data contradict the spec or GST/legal behaviour needs current statutory confirmation.

## Open decisions (defaults chosen — change if you disagree)

| Decision | Default in this plan |
|---|---|
| Where new platform/core code lives | `app/Services/{Platform,Inventory,Accounting,Commercial,Compliance}` + `app/Support/Company`; nwidart Modules only for optional operations (JobWork, FastSales UI, IndiaCompliance, Printing) |
| SaaS multi-tenant (separate DB per customer) | Out of scope; single-DB, multi-company. Dead SaaS code left inert, removed in Phase 11 if unused. |
| Valuation method | Weighted average default; FIFO later per company setting |
| GSTIN provider | Interface + one adapter chosen at Phase 7 (needs API account) |
| Prototype verticals (water/cafe/repair) | Kept as optional profiles; no new investment |
| First pilot customer | Optech textile client — but General Trading UAT must pass first (doc 28) |
