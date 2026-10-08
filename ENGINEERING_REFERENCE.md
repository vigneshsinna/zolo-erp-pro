# zoloERP Master Engineering Reference

Shared ERP architecture and implementation guide for zoloERP Pro / Laravel 10.

- **Repository:** `vigneshsinna/zolo-erp-pro`
- **Product:** A general ERP with configurable General Trading, FMCG, Textile, Timber and Solar profiles.
- **Platform:** Existing Laravel application, MySQL, Blade/Bootstrap UI, and `nwidart/laravel-modules` where modular packaging fits.
- **Architecture:** One commercial, inventory, accounting, tax and document foundation; optional operational capabilities and industry presets.
- **Status:** Implementation specification with staged foundation packages delivered. Full company isolation and later phases remain pending.
- **Updated:** 2026-10-04.
- **Implementation snapshot:** Reviewed shared commercial writers and double-entry accounting paths; see [implementation progress](documents/zolo_erp_implementation_docs/IMPLEMENTATION_PROGRESS.md).

This reference consolidates the [implementation pack](documents/zolo_erp_implementation_docs/README.md) and its [master index](documents/zolo_erp_implementation_docs/00_IMPLEMENTATION_MASTER_INDEX.md). It supersedes the earlier textile-only, isolated Optech architecture in this file. Detailed implementation requirements remain in the linked documents. The confirmed financial-year policy below resolves the pack's conflicting table names.

Unless explicitly marked **implemented**, service names, schema additions, workflows and acceptance criteria below describe the target architecture. A specification or generated scaffold is not evidence of working behavior.

## Contents

1. [00 — Architecture and ownership](#00-architecture-and-ownership)
2. [01 — Company, branch and financial year](#01-company-branch-and-financial-year)
3. [02 — Capabilities, profiles and shared masters](#02-capabilities-profiles-and-shared-masters)
4. [03 — Document numbering, printing and communication](#03-document-numbering-printing-and-communication)
5. [04 — Sales, POS and order to cash](#04-sales-pos-and-order-to-cash)
6. [05 — Purchases and procure to pay](#05-purchases-and-procure-to-pay)
7. [06 — Inventory, manufacturing and job work](#06-inventory-manufacturing-and-job-work)
8. [07 — Registry, orders, returns and reversals](#07-registry-orders-returns-and-reversals)
9. [08 — Accounting, vouchers and open items](#08-accounting-vouchers-and-open-items)
10. [09 — Financial and operational reporting](#09-financial-and-operational-reporting)
11. [10 — GST and tax compliance](#10-gst-and-tax-compliance)
12. [11 — UI, API, permissions and operational controls](#11-ui-api-permissions-and-operational-controls)
13. [12 — Schema ownership and migration](#12-schema-ownership-and-migration)
14. [13 — Delivery, validation and deployment](#13-delivery-validation-and-deployment)

## 00 Architecture and ownership

Sources: [repository audit](documents/zolo_erp_implementation_docs/01_REPOSITORY_AUDIT_AND_GAP_ANALYSIS.md), [core boundaries](documents/zolo_erp_implementation_docs/02_ARCHITECTURE_PRINCIPLES_AND_CORE_BOUNDARIES.md).

### Product structure

An optional SaaS tenant may isolate a customer's database. A legal company is a separate concept inside that deployment. Branches, warehouses, financial years and company memberships sit beneath the legal company.

The shared ERP core owns masters, sales, purchases, payments, inventory, accounting, tax, documents, APIs, audit and reports. Optional capabilities add manufacturing, job work, batch/expiry, serial/warranty, dimensions, projects/sites, route distribution and repair. Industry profiles select capabilities, attributes, labels and defaults.

Core behavior must work with no industry pack enabled. An industry pack depends on shared services; shared services must not depend on an industry pack. Disabling an optional capability preserves readable historical documents.

| Boundary | Responsibility | Existing foundation and direction |
|---|---|---|
| PlatformCore | Company context, capabilities, authorization, audit, period controls, document series | Extend platform services and existing access controls |
| CommercialCore | Sales, purchases, payments, returns, pricing, credit | Converge existing `SaleService` and `PurchaseService` with web writers |
| InventoryCore | Movements, availability, valuation, transfers, stock identities | Extend existing inventory behavior into one movement authority |
| AccountingCore | Chart of accounts, journals, semantic mappings, open items, close | Harden existing `AccountingService`; retain one ledger |
| IndiaCompliance | GST registrations, tax determination, lookup and statutory projections | Shared compliance services and versioned provider/export adapters |
| Operations | Manufacturing, job work, projects, distribution and repair | Reuse shared stock and financial posting |
| Industry packs | Presets, terminology, attributes, reports and focused extensions | Company-configurable additions around the core |

These are responsibility boundaries, not a requirement to generate new modules, folders or interfaces for every service.

### Integration rules

1. Reuse the existing responsible service, model or helper where it fits. Add a service or adapter only when it establishes clear ownership.
2. Allow tested changes to `app/`, routes, views and additive migrations. Preserve compatibility and avoid destructive vendor-core rewrites. Do not edit `vendor/`.
3. Controllers authenticate, authorize, validate and delegate. Application services own transaction boundaries and business invariants.
4. A posted transaction commits its document, number, stock movement/projections, journal, open items/allocations and required audit records atomically. Failure in a required effect rolls back the operation.
5. Run PDF generation, communication and nonessential analytics after commit. Use a durable outbox only when reliable asynchronous delivery requires it.
6. Web, API, import, background and industry entry points use the same business rules. Avoid direct quantity mutations and financial posting hidden in unrelated listeners.
7. Preserve existing document routes, identifiers and external integrations until replacements pass compatibility checks.
8. Use stable semantic keys. Labels such as "fabric", "job worker" or "system kit" must not create parallel commercial or accounting tables.

### Existing module disposition

Follow [document 29](documents/zolo_erp_implementation_docs/29_EXISTING_OPTECH_MODULE_DISPOSITION.md) before changing module activation or removing scaffolds.

| Module | Target disposition |
|---|---|
| `OptechCompany` | Retire or repurpose as generic platform context; no separate textile company authority |
| `OptechMaster` | Retire duplicate master scaffolding; extend existing products and parties |
| `OptechSpeedBilling` | Retired (empty scaffold). Sales/purchase entry is the normal `/sales` and `/purchases` pages; see `documents/DOCUMENT_ENTRY_CONSOLIDATION.md` |
| `OptechJobWork` | Generic subcontracting/job work capability |
| `OptechAccounting` | Retire or retain a thin UI over existing accounting |
| `OptechGST` | Shared IndiaCompliance adapter/module |
| `OptechPrinting` | Generic document rendering/print capability |
| `Manufacturing` | Keep and refactor onto shared inventory/accounting services |

Trace aliases, routes, menus, data and integrations before retirement. Keep scaffold cleanup separate from high-risk transaction migration.

## 01 Company, branch and financial year

Sources: [company/FY specification](documents/zolo_erp_implementation_docs/03_MULTI_COMPANY_BRANCH_AND_FINANCIAL_YEAR.md), [scoping strategy](documents/zolo_erp_implementation_docs/05_DATABASE_SCOPING_AND_MIGRATION_STRATEGY.md), [backfill runbook](documents/zolo_erp_implementation_docs/COMPANY_BACKFILL_RUNBOOK.md).

### Ownership and context

Every company-owned master and financial/operational transaction has exactly one legal company. A company may have several branches and warehouses. Users access companies through membership and branches through explicit assignments. Company selection is an authorization decision, not a trusted request field.

The target request flow is authentication, authorized company/branch/FY resolution, capability and permission checks, then business services. All selected parties, products, warehouses, accounts and source documents must belong to the resolved company. Company scoping must cover Eloquent queries, raw queries, joins, exports, file access, caches, imports and jobs.

A company/FY switcher displays the active context prominently. Switching cannot silently reassign an in-progress document to a different company. Jobs carry explicit context, validate it before querying, and clear it before a worker handles another job.

### Confirmed financial-year policy

**Extend the existing `fiscal_years` table, preserve existing date ranges, and migrate opening balances separately.**

Document 03's conceptual `financial_years` name maps to the existing physical `fiscal_years` authority. Do not create a parallel table. API/context terminology such as `financial_year_id` does not establish another ledger or FY source.

- Preserve existing calendar-year and other valid ranges. April–March is an available company configuration, not a forced historical conversion.
- Select and validate FYs against their stored inclusive start/end dates and the company's timezone.
- Support open, soft-closed and closed states, lock date and close metadata while retaining legacy closure compatibility.
- Historical reads may select a closed FY. Posting requires an explicit business-date/period check.
- Locks apply to server-side writes across web, API, imports and jobs. A closed or locked FY in one company must not affect another company.
- Artificial opening sales/purchases dated `1970-01-01` receive company ownership during backfill only. Their later opening-journal/open-item migration is separate; do not shift their dates or balances automatically.
- Opening and carry-forward balances must reconcile before year-end close is activated.

### Implemented foundations and activation limits

Company packages A/B have delivered:

- `companies`, `company_branches`, `company_user` and `company_user_branches`, including membership/branch ownership constraints.
- Nullable indexed company keys on 32 audited core tables when present, a warehouse branch key, and status/lock/close metadata on `fiscal_years`.
- `erp:backfill-company-context` with a zero-write dry run, validation, maintenance-mode guard and transactional DEFAULT/MAIN assignment.
- Immutable `CompanyContext` with company, branch and financial-year IDs.
- `CompanyContextResolver` for active user/company membership, branch access and FY selection; explicit `assertPostingDate` for date/period validation.
- `ResolveCompanyContext`, registered as `company.context`, exposing context through request attributes and clearing it after success or failure. Sole authorized branch selection and coherent header/session switching are implemented. Ten authenticated API catalog/partner/valuation and sales/purchase list/detail reads consume this context; explicit root/nested scopes, branch stock checks and nested payment/journal ownership are covered by HTTP isolation tests.
- Authorized web/API financial-year setup works before a branch/FY tuple exists. Active Admin/Owner membership and company role overrides are enforced; creation locks the company and rejects overlapping dates. Missing current FY directs administrators to setup.

Middleware reads positive integer `X-Company-ID`, `X-Branch-ID` and `X-Financial-Year-ID` headers, with `company_id`, `branch_id` and `financial_year_id` session fallbacks. Request-body company IDs are not authoritative. Defaults require unambiguous authorized selection.

**Implemented:** shared sales/purchase creation, customer/supplier payments, transfers, accounting API readers/manual posting and double-entry accounting web paths consume authorized context and period guards. Referenced parents, stock identities, nested journals and report aggregates are scoped. All required effects and atomic number reservations roll back together. Inventory-close posting remains blocked until reviewed inventory-ledger valuation and reconciliation. Raw legacy/operational routes, jobs, files and final constraints remain incomplete; second-company activation is still unsafe. Production migration/backfill has not been executed.

Before activation, rehearse on representative disposable MySQL data; validate ownership and uniqueness; convert all affected readers/writers and jobs; enforce necessary constraints; prove cross-company rejection and legacy parity. Do not activate a second company before these gates pass.

## 02 Capabilities, profiles and shared masters

Sources: [capability engine](documents/zolo_erp_implementation_docs/04_BUSINESS_PROFILE_AND_CAPABILITY_ENGINE.md), [master data](documents/zolo_erp_implementation_docs/06_MASTER_DATA_PRODUCTS_PARTIES_UOM_ATTRIBUTES.md).

### Capability engine

Business profiles are presets. Companies can enable supported capabilities independently, subject to dependencies. Capability availability and user permission are separate checks.

**Implemented as gated foundations:** `capabilities`, `business_profiles`, `business_profile_capabilities` and `company_capabilities`. Store stable keys, provider ownership, dependencies, configuration schema, company configuration and the actor/time of changes.

Examples include `core.sales`, `core.purchases`, `core.inventory`, `core.accounting`, `core.gst`, `inventory.multi_uom`, `inventory.batch_expiry`, `inventory.serial_tracking`, `inventory.dimension_tracking`, `manufacturing.production`, `operations.job_work`, `operations.projects`, `printing.dot_matrix` and `communications.whatsapp`.

A shared capability service resolves availability/configuration, validates dependencies and supplies navigation. Cache by company and invalidate on changes. Enforce enabled capabilities at routes and business services, not only in menus. Disabling a capability prevents new operations while preserving history.

The implemented capability service validates configuration/dependencies and effective company administration. Optional capabilities remain inactive behind the fixed Phase 1 acceptance gate. Legacy imports preserve metadata without enabling operations. See [capability/numbering evidence](documents/zolo_erp_implementation_docs/PHASE_2_3_IMPLEMENTATION.md).

Existing `general_settings.modules` is a migration compatibility input. It must not remain the authoritative company capability engine.

### Shared product and party model

Extend existing products, units, categories, brands, customers and suppliers. Introduce shared product/party query services and a party resolver over current customer/supplier records before considering any physical consolidation.

Stable product concepts include:

- Item kind: goods, service, consumable, asset or non-stock.
- Stock tracking: none, quantity, batch, serial, batch/serial or dimension.
- HSN/SAC, tax category, base/purchase/sale UOM, valuation method, negative-stock policy and shelf life.

Normalize UOM conversions in `product_uom_conversions`, including conversion factor, rounding scale and purchase/sale defaults. Quantity precision follows UOM requirements; preserve `125.375 MTR` without truncation. Monetary precision and rounding belong to the shared currency/posting policy. Do not force a blanket precision change on existing accounting columns.

Keep product attributes separate from stock identities. A product may define standard dimensions, but an actual timber piece, batch or serial is tracked in inventory. Use `attribute_definitions` and `entity_attribute_values` for configurable attributes. Add indexed capability sidecars only when calculation or querying requires them. Ordinary requests must not run schema DDL.

Party search supports legal/trade names, city, aliases, contact and tax identifiers. City-prefixed textile search is an option, not a mandatory naming convention. Inline creation returns the new record to the active document while preserving entered lines and focus.

### Industry presets

| Profile | Default focus | Shared behavior |
|---|---|---|
| General Trading | Standard commercial, inventory, accounting and tax workflows | Purchase, sale, payment and return |
| FMCG | Multi-UOM, batch/expiry, FEFO, fast/wholesale sales, communication | PCS/BOX/CASE conversion, MRP and schemes; free units still affect stock |
| Textile | Multi-UOM, fast/wholesale sales, job work, dot-matrix and communication | Decimal MTR/KG, fabric attributes, process labels, transport/bale notes |
| Timber | Dimensional stock, lot tracking, multi-UOM and wholesale sales | Piece selection, unit-aware CFT/CBM calculation with persisted formula version |
| Solar | Serial tracking, projects/sites, installation, BOM kits, warranty/AMC and communication | Shared quotation/order/procurement/dispatch/invoice links and project costing |

Manufacturing, route distribution and other subtype needs remain selectable capabilities. Profiles must not create their own products, sales, purchases or journals.

Profile sources: [FMCG](documents/zolo_erp_implementation_docs/16_INDUSTRY_PACK_FMCG.md), [Textile](documents/zolo_erp_implementation_docs/17_INDUSTRY_PACK_TEXTILE.md), [Timber](documents/zolo_erp_implementation_docs/18_INDUSTRY_PACK_TIMBER.md), [Solar](documents/zolo_erp_implementation_docs/19_INDUSTRY_PACK_SOLAR.md).

## 03 Document numbering, printing and communication

Source: [document engine](documents/zolo_erp_implementation_docs/12_DOCUMENT_SERIES_PRINTING_AND_COMMUNICATION.md).

### Numbering

**Implemented for reviewed shared writers:** one `DocumentNumberService` backed by `document_series` and `document_number_reservations`. Series support company, branch where applicable, FY, document type, code, prefix/suffix, padding, next number, reset policy and default selection.

Reserve/increment under database row locking inside the posting transaction. Database uniqueness and idempotency protect concurrent posting and retries. Authoritative numbers must not use `count()+1` or second-level timestamps. Preserve historical numbers; replacing the numbering mechanism does not renumber posted documents.

Committed MySQL table creation resumes missing validated indexes/FKs. Used series cannot be reset or reformatted; retained source references, including soft-deleted documents, cannot be reused. Source/linked journal reference lengths are enforced. Raw legacy writers still retain their existing numbering until conversion.

FY reset follows actual configured date ranges and occurs once per applicable series/company. Failed or retried posting must leave explainable reservation state without duplicate numbers.

### Rendering and dispatch

Rendering consumes a read-only document DTO containing persisted business totals and tax snapshots. Templates format data; they do not recalculate tax, stock value or journal amounts.

Support existing A4 and thermal layouts plus optional fixed-width dot-matrix profiles. Company/document print profiles configure copies, paper dimensions, template, printer and channel settings. Original/Duplicate/Triplicate copies must show identical financial totals.

Preserve the legacy 68-line continuous-paper option as a configurable printer profile. Support the selected 80/132-column printer through an appropriate raw ESC/P adapter. Verify line advance, overflow and page alignment on real hardware; a browser/PDF preview cannot prove tractor-feed alignment. Include a repeated-form test for paper creep.

Optional document fields include transport, LR/reference, bundles/bales, delivery details and bank/IFSC footer. These are profile attributes, not mandatory fields for every business.

Reprint reproduces an existing document and number. Clone-from-prior initializes a new draft and later obtains a new number; revalidate current prices, tax and stock before posting.

Email, WhatsApp and SMS dispatch runs after commit. Record attempts, provider IDs and failures in `document_dispatch_logs`; support retries. Communication failure must not unpost an invoice. Consent, recipients and templates follow company configuration and permissions.

## 04 Sales, POS and order to cash

Source: [sales specification](documents/zolo_erp_implementation_docs/07_SALES_POS_AND_ORDER_TO_CASH.md).

Converge existing web/API sale writers through a shared `SaleApplicationService` around fitting existing `SaleService` behavior. The fast counter UI is one entry point to this service.

The target posting flow:

1. Resolve company, branch, FY and authorized party/product/warehouse references.
2. Check capability, permission, period, credit and pricing rules.
3. Determine server-side tax and validate availability/reservations and stock identities.
4. Atomically allocate a number, persist sale/lines, issue stock, post balanced accounting, create receivable open items, apply immediate payment/allocations and record required audit.
5. Commit, then render/dispatch and emit after-commit notifications.

Keep draft/posting state distinct from payment settlement. Model partial payment, paid status and reversal explicitly. Idempotent retries must not duplicate documents or effects. Service/non-stock lines create commercial/accounting effects without physical stock movements.

Live party information displays prior outstanding, current bill and projected total separately. Show overdue amounts and credit limits distinctly. Price history and prior bill loading must remain company/party scoped.

### Fast-entry behavior

Preserve keyboard workflows as an optional interaction mode over the existing UI. Mouse, touch and accessible controls remain usable.

| Shortcut | Context and behavior |
|---|---|
| F2 / F12 | New Sales Bill / New Purchase Bill on the normal pages (accelerators, not separate modules; see `documents/DOCUMENT_ENTRY_CONSOLIDATION.md`) |
| Space | Open lookup in the active party/item field |
| Enter | Advance through the document's entry sequence |
| Alt+C / Alt+A | Inline party creation / editing, subject to permission |
| F6 or Alt+F6 | Optional inline item creation in the sales shortcut profile |
| Ctrl+S | Optional prior customer/item rate history |
| Ctrl+B | Pending bills and overdue information |
| Alt+Y | Party statement |
| Alt+X | Clone a prior bill into a new draft |
| Ctrl+Enter; optional F10/End | Save/post, with print behavior configured separately |
| Esc | Close the current dialog and restore focus |
| F9 | Open accounting voucher hub |

Use contextual maps to avoid conflicts, including F6 for accounting receipts and F7 for accounting journals. Do not capture ordinary typing or browser shortcuts globally. A new-document command must protect unsaved work. Display shortcuts and validation feedback near the relevant controls.

## 05 Purchases and procure to pay

Source: [purchase specification](documents/zolo_erp_implementation_docs/08_PURCHASE_AND_PROCURE_TO_PAY.md).

Converge purchase writers through a shared `PurchaseApplicationService` around existing `PurchaseService`. Validate company/FY/branch, supplier, item/UOM, receipt state, tax, costs and permissions before posting.

A posted purchase atomically writes its document/lines, number, applicable receipt movement, inventory valuation, journal, payable open items, payment allocations and audit. Service purchases do not receive physical stock.

- Separate order, receipt and supplier invoice states. A pending purchase must not increase on-hand stock.
- Load authorized PO/receipt lines with provenance and remaining-quantity validation.
- Link ordinary purchase receipt and job-work receipt deliberately; they are different operations.
- Support freight, discount, rounding and landed-cost allocation by supported policy: value, quantity, weight or explicit allocation.
- Supplier outstanding and allocation UI use shared party/open-item queries.
- Reverse charge and other tax treatments require server-side eligibility, not an unrestricted checkbox.
- Preserve supplier document references and distinguish cloning from reprinting.

The audit correction preserves `product_purchases.recieved` while accepting API `received_qty`: Received records full quantity, Partial records validated line receipts, Pending records zero, and Ordered remains an unbilled PO. The confirmed supplier-bill policy recognizes full payment/AP and splits received inventory from goods-in-transit. Configure an active asset account with sub_type `goods_in_transit`; missing required accounts roll back creation. Subsequent receipt release and legacy web update parity remain shared-engine integration gates.

## 06 Inventory, manufacturing and job work

Sources: [inventory](documents/zolo_erp_implementation_docs/09_INVENTORY_STOCK_LEDGER_BATCH_SERIAL_DIMENSIONS.md), [manufacturing](documents/zolo_erp_implementation_docs/14_MANUFACTURING_BOM_RECIPE_AND_PRODUCTION.md), [job work](documents/zolo_erp_implementation_docs/15_SUBCONTRACTING_JOB_WORK_DC_GRN.md).

### Inventory authority

Every posted physical change produces an auditable stock movement. `stock_movements` and `stock_movement_lines` become the historical authority. Existing `products.qty` and `product_warehouse.qty` remain rebuildable compatibility projections during migration.

Movement records identify company, branch, FY, date/type, source, warehouses/locations, posting status, reversal and idempotency. Lines record product/variant, base UOM quantity, posting cost/value, batch and stock identity. Persist cost at posting; historical COGS must not change when current product cost changes.

A shared movement service owns receipt, issue, transfer, adjustment and reversal. Availability separates on-hand, reserved and available stock. Central policies validate negative stock, batches/expiry, serial lifecycle, selected pieces and dimensions. A serial cannot be present in two locations simultaneously.

Projection updates occur in the same transaction as movement posting. Lock the necessary availability/identity records so two concurrent last-stock sales cannot both succeed when negative stock is blocked. Transfers reconcile source and destination quantities/value. Corrections append linked reversal movements.

Convert generic ERP services first, legacy controllers next, then manufacturing/industry writers. Use the [writer audit](documents/zolo_erp_implementation_docs/STOCK_AND_TRANSACTION_WRITER_AUDIT.md) and a fresh source search before cutover. Add reconciliation/rebuild tools before treating the ledger as authoritative.

### Manufacturing

Retain the existing Manufacturing boundary. Normalize BOM/recipe structures into versioned `boms`, `bom_lines` and `production_orders` with production consumption/output records as needed.

Production supports multiple outputs, scrap/waste and numeric yield. Consumption, finished output, loss and related journals use shared inventory/accounting services. Freeze the applicable BOM version on production records. Validate migration from legacy comma-separated recipes before replacement.

Timber conversion and FMCG repacking use these shared capabilities. Neither creates an independent stock ledger.

### Job work and external processing

Own material sent to a job worker retains company ownership and moves to an external inventory location. Dispatch is an operational stock movement, not a sale. The compliance layer determines required challan treatment and statutory rules.

Target records are `job_work_orders`, dispatches/lines and receipts/lines, linked to document and stock movement records. Process configuration controls accepted/rejected quantities, loss/shrinkage, outputs and thresholds.

A receipt reconciles sent, returned and outstanding material. For example, 500.000 MTR sent and 480.000 MTR received represents 20.000 MTR loss, or 4%, when the order is fully reconciled. A service purchase linked to that receipt posts processing charges; it must not receive or value the same fabric a second time.

Pending outside-material reports derive from movement balances. Support partial receipts and traceability between dispatch, receipt and service bill. Textile terms such as bleaching/dyeing are process labels, not core entity names.

### Projects and serialized operations

Solar projects/sites link shared quotations, orders, procurement, reservations, dispatches, installations and invoices. Serial history follows purchase, allocation, dispatch, installation and replacement. Warranty/AMC records follow installed serials. Project margin aggregates shared material, service, expense and revenue data.

## 07 Registry, orders, returns and reversals

Source: [returns and notes](documents/zolo_erp_implementation_docs/13_RETURNS_DAMAGE_EXCHANGE_AND_CREDIT_DEBIT_NOTES.md).

Provide one company-scoped document registry with filters, source links and drilldown. Pending orders expose fulfillment/receipt balances without falsely changing physical stock.

Drafts may be edited under permission. Posted commercial and financial history is corrected by traceable reversal and corrected posting. Reprinting never mutates a document. Period locks apply to correction and reversal commands.

Sales/purchase returns reference the original document and validate remaining returnable quantities, identities, rates, tax snapshots and settlement effects.

- Physical returns post stock movement according to disposition: restock, quarantine, damaged/expired or other supported treatment.
- A financial credit/debit note without returned goods does not change stock.
- An exchange consists of a return, a new sale and the settlement difference.
- Reverse relevant tax, journals, open items and allocations consistently; preserve the original records.
- Store actor, reason, source and reversal links. Restrict destructive deletion of posted or audit history.

## 08 Accounting, vouchers and open items

Source: [accounting specification](documents/zolo_erp_implementation_docs/10_ACCOUNTING_DOUBLE_ENTRY_OPEN_ITEMS_AND_VOUCHERS.md).

### One financial ledger

Extend existing `chart_of_accounts`, `journal_entries`, `journal_items` and `semantic_account_mappings`. Harden existing `AccountingService` rather than building an Optech ledger.

The target accounting authority owns company-scoped semantic account resolution, balanced posting, idempotency, reversal, open items, allocations, reports and period close. The current shared posting service validates owned active accounts and source documents, enforces business dates and writes owned journals/items atomically. API and double-entry web readers are scoped. Reversals, open items, dated openings and close remain deferred. Service boundaries may be extracted when ownership requires them.

The current mapping editor updates company-owned metadata atomically and requires company administration. It does not silently activate legacy hard-coded mapping IDs. New chart accounts start at zero; historical openings remain unchanged.

Target posting rules request semantic roles such as AR, AP, cash, bank, inventory, sales, COGS, tax input/output, discounts, returns, rounding, freight, damage and variance. Do not add hard-coded account IDs or silently skip required posting when mappings are absent.

Posted journals balance exactly at the defined currency precision. Use consistent decimal arithmetic and documented rounding. Persist posting keys and enforce uniqueness so source retries produce one posting. Posted journal entries/lines are immutable; correction creates linked reversal and replacement.

Account balances are rebuildable from journal lines. Operational cash/bank records must reconcile with the ledger during migration. Financial periods and company context are enforced inside posting services.

### Open items and allocation

Use `account_open_items` for receivable/payable balances by source and `account_allocations` for payment settlement. Support Against Reference, Advance and On Account as behaviors over this shared service.

Partial allocation preserves the exact remaining amount. Validate party, company, currency, eligible source and remaining balance. Concurrent allocations cannot settle the same amount twice. Reversal retains allocation history and restores the applicable balance.

AR/AP control accounts reconcile to open items. Initial opening balances enter through the separately reviewed opening-data migration.

### Voucher behavior

Preserve F4 Contra, F5 Payment, F6 Receipt, F7 Journal and F9 Voucher Hub as a contextual accounting shortcut profile. Contra moves between cash/bank accounts; payments/receipts settle appropriate sources; journals record authorized adjustments. All use the shared posting engine and permissions.

## 09 Financial and operational reporting

Sources: [accounting](documents/zolo_erp_implementation_docs/10_ACCOUNTING_DOUBLE_ENTRY_OPEN_ITEMS_AND_VOUCHERS.md), [inventory](documents/zolo_erp_implementation_docs/09_INVENTORY_STOCK_LEDGER_BATCH_SERIAL_DIMENSIONS.md), [traceability](documents/zolo_erp_implementation_docs/27_REQUIREMENT_TRACEABILITY_MATRIX.md).

Financial statements derive from the shared journal ledger. Stock quantities/value derive from posted movements and reconciled projections. Open-item ageing derives from open items and allocations. Reports must not introduce a second authority.

Preserve:

- General ledger with source-document/voucher drilldown, day book and cash book.
- Trial balance, P&L, balance sheet and AR/AP control reconciliation.
- Twelve-month movement matrix and month drilldown, based on the selected FY's actual dates. April–March applies only to a matching configured FY.
- Stock availability, valuation, ageing, batch/expiry, serial and dimension filters.
- Negative stock, shrinkage/yield and material pending at job workers.
- Profile-specific filters and project profitability using shared records.

Apply company scope to report queries, exports and cache keys. Apply branch/FY filters according to report semantics; opening/as-of balances may need prior-period history. Do not hide that history with an indiscriminate FY query scope.

Weighted average and FIFO are company valuation choices requiring supported posting/reconciliation behavior. Changing policy must not recalculate historical posted costs silently. Heavy summaries may use derived projections or asynchronous exports, with a defined reconciliation source.

## 10 GST and tax compliance

Source: [India compliance specification](documents/zolo_erp_implementation_docs/11_INDIA_GST_AND_TAX_COMPLIANCE.md).

GST is a shared compliance layer. Product/profile labels do not decide statutory treatment.

Tax determination receives seller registration, buyer registration/address, ship-to/place of supply, HSN/SAC, document date and applicable supply flags. Resolve effective-dated categories/rates server-side, including supported intra/inter-state, exempt, non-GST, service and reverse-charge scenarios.

Persist line tax snapshots: taxable value, rate, CGST, SGST, IGST, supported cess, HSN/SAC, place of supply and reverse-charge treatment. Rate changes must not rewrite posted documents. Returns/notes use the relevant original snapshot and required correction rules.

GSTIN format validation is distinct from verification of active registration. A lookup provider uses timeout, caching, audit and a manual fallback. Provider failure must not corrupt party data or invoices.

GSTR-1/GSTR-3B and HSN/SAC reporting use posted documents/notes and shared tax projections. Keep provider and government export formats behind versioned adapters. Reverify current statutory rules and schemas during compliance implementation and before production filing. This reference specifies architecture; it does not certify rates, eligibility, filing formats or job-work legal deadlines.

## 11 UI, API, permissions and operational controls

Sources: [UI and setup](documents/zolo_erp_implementation_docs/20_UI_UX_NAVIGATION_KEYBOARD_AND_SETUP.md), [API](documents/zolo_erp_implementation_docs/21_API_INTEGRATION_AND_WEBHOOKS.md), [security](documents/zolo_erp_implementation_docs/22_SECURITY_PERMISSIONS_AUDIT_AND_DATA_LOCKS.md).

### UI and setup

UI modernization follows [design system and migration rules](documents/zolo_erp_implementation_docs/31_MODERN_UI_DESIGN_SYSTEM_AND_SCREEN_MIGRATION.md), [Optech screen mapping](documents/zolo_erp_implementation_docs/32_OPTECH_SCREEN_TO_ZOLOERP_UI_MAPPING.md), and [common component contracts](documents/zolo_erp_implementation_docs/33_COMMON_UI_COMPONENT_LIBRARY.md). Retain Blade/Bootstrap/jQuery and extend the existing `zolo-erp-neo.css` foundation. Preserve operator speed/shortcuts; use profile extensions and shared services. These specifications do not imply implemented components or completed company/engine gates.

Extend the existing Blade/Bootstrap application progressively. Establish shared business behavior before any frontend framework rewrite. Integrate scripts/views through explicit application hooks, not response-string HTML injection.

Use business navigation: Home, Sales, Purchases, Inventory, Accounts, People, Operations, Reports and Settings. Show entries based on company capabilities and permissions. Historical access remains available when supported.

Setup guides company details, authorized branch/FY selection, profile/capabilities, tax registration, account mappings, document series and opening-data validation. Technical module names must not dominate operator workflows.

Fast-entry UI preserves line state, focus, accessible labels, visible validation and responsive behavior. Client-side totals are previews; the server authoritatively validates price, tax, stock and posting.

### API and integration

Extend existing `/api/v1` with stable resources/DTOs. Web and API use the same application services. Authenticate, resolve company/branch/FY, authorize capability/permission and validate every referenced object.

Use the context headers described in section 01. A context ID alone never grants access. Posting supports `Idempotency-Key`, with company/operation scope and a defined response for retries and conflicting payloads.

Jobs/imports carry explicit authorized company context and obey periods/capabilities. Reliable integrations may use signed webhooks and a durable outbox where required. Avoid external HTTP calls inside critical posting transactions.

### Security and policy

Use existing authentication and Spatie permission foundations, with company membership/branch constraints. Access requires all applicable checks: authentication, membership, branch, capability, permission and period policy.

An IP address or "server terminal" label is not sufficient authority to edit tax, series, company or period settings. If terminal restrictions remain useful, apply them as additional policy.

Audit company/capability changes, protected master/settings changes, posting, reversal, allocation and override actions. Authorized overrides require reason and actor; do not bypass invariant or audit checks. Restrict uploaded documents and exports by ownership and permission. Keep secrets out of code, responses and logs.

Negative-stock policy belongs to inventory posting. Period locks belong to posting services. Menu hiding, disabled controls and request-only validation cannot replace these checks.

## 12 Schema ownership and migration

Sources: [database strategy](documents/zolo_erp_implementation_docs/05_DATABASE_SCOPING_AND_MIGRATION_STRATEGY.md), [migration/cutover](documents/zolo_erp_implementation_docs/23_DATA_MIGRATION_PARALLEL_RUN_AND_CUTOVER.md).

### Authoritative schema map

Existing tables remain authoritative until a tested, reconciled cutover. Target additions are planned unless listed as implemented in section 01.

| Area | Existing authority to extend | Target additions or normalized concepts |
|---|---|---|
| Company/branch | Implemented platform tables | Profile and tax-registration integration; complete scoping |
| Financial year | `fiscal_years` | Already added status/lock/close metadata; service enforcement pending |
| Capabilities | Legacy module setting during transition | `capabilities`, `business_profiles`, profile/company capability tables |
| Masters | `products`, `units`, `categories`, `brands`, `customers`, `suppliers` | UOM conversions, attribute definitions/values, capability sidecars |
| Commercial | `sales`, `product_sales`, `purchases`, `product_purchases`, `payments`, existing returns/orders | Posting/idempotency/source links and shared application services |
| Inventory | Existing stock/warehouse/transfer/adjustment records | `stock_movements`, `stock_movement_lines`, batches, serials, dimensions/identities |
| Accounting | `chart_of_accounts`, `journal_entries`, `journal_items`, `semantic_account_mappings`, `inventory_closes` | Posting/reversal metadata, `account_open_items`, `account_allocations` |
| Tax | Existing tax data during transition | Registrations, effective-dated rates/categories, HSN/SAC and statutory projections |
| Documents | Existing numbers/templates during transition | `document_series`, reservations, `print_profiles`, dispatch logs |
| Manufacturing | Existing Manufacturing records | Versioned BOMs, normalized lines/orders, shared consume/output movements |
| Job work | Existing operational data after audit | Orders, dispatch/receipt lines, process configuration and shared document links |
| Projects/service | Existing relevant domain records | Site/installation/serial and warranty links where required |

New tables use names matching their shared responsibility. There is no blanket `optech_*` requirement. Do not create duplicate company, FY, product, commercial or voucher authorities. Confirm legacy foreign-key types and names before migrations; conceptual field names are not permission to replace physical columns blindly.

### Current company-key coverage

The additive package covers these 32 tables when present:

```text
categories, brands, units, customer_groups, products, customers, suppliers,
warehouses, billers, sales, product_sales, purchases, product_purchases,
payments, product_warehouse, transfers, product_transfer, returns,
product_returns, return_purchases, purchase_product_return, adjustments,
product_adjustments, stock_counts, expenses, accounts, chart_of_accounts,
fiscal_years, journal_entries, journal_items, semantic_account_mappings,
inventory_closes
```

This is staged coverage, not the complete company-owned table inventory. Manufacturing, projects, payroll, other operational tables, settings, files and future additions require separate reader/writer audits and migrations. Nullable company keys do not enforce isolation.

### Safe migration sequence

1. Inventory affected schema, readers, writers, reports, jobs and external consumers. Record types, constraints, legacy sentinels and source totals.
2. Add nullable columns and new tables through migrations. Replace runtime custom-field DDL with attribute storage.
3. Preview mappings and run zero-write dry runs. Reject orphan references, invalid ownership, incompatible dates and duplicate company business keys.
4. Backfill validated records into DEFAULT/MAIN under the runbook's maintenance and paused-worker/scheduler conditions. Preserve posted numbers, dates, quantities, values and legitimate legacy sentinels.
5. Reconcile documents, stock, journals, open items and tax; rehearse rollback/restore on a representative copy.
6. Convert readers/writers and company-aware uniqueness in deployable stages. Add required FKs/non-null constraints only after validation and compatibility.
7. Activate context/scopes and prove cross-company isolation. Remove compatibility paths only after all dependent behavior passes.

Opening imports are staged separately: masters, stock, receivables/payables and opening journals. Preview/validate before posting and reconcile inventory, AR/AP and trial balance. Use parallel-run review, a controlled delta cutover and a rehearsed rollback. Migration rollback must not delete posted production history.

Use the [company backfill runbook](documents/zolo_erp_implementation_docs/COMPANY_BACKFILL_RUNBOOK.md) for actual command sequencing and guards. Do not substitute blanket module-migration or activation commands.

## 13 Delivery, validation and deployment

Sources: [execution sequence](documents/zolo_erp_implementation_docs/26_CODEX_EXECUTION_SEQUENCE_AND_CHECKLIST.md), [testing/UAT](documents/zolo_erp_implementation_docs/24_TESTING_PERFORMANCE_AND_UAT.md), [deployment](documents/zolo_erp_implementation_docs/25_DEPLOYMENT_BACKUP_AND_OBSERVABILITY.md), [roadmap](documents/zolo_erp_implementation_docs/28_PHASED_DELIVERY_ROADMAP.md), [backlog](documents/zolo_erp_implementation_docs/30_IMPLEMENTATION_BACKLOG_AND_ACCEPTANCE_CRITERIA.md).

### Dependency order

Document numbers group specifications; document 26 defines delivery order.

| Phase | Deliverable and gate |
|---|---|
| 0 | Baseline, reader/writer audit and transaction regressions |
| 1 | Company/branch/FY schema, backfill rehearsal, integrated context and isolation |
| 2 | Capability/profile engine and legacy module adapter |
| 3 | Atomic numbering with concurrent-post proof |
| 4 | Stock movement authority, writer conversion and reconciliation |
| 5 | Accounting hardening, semantic mappings, idempotency and open items |
| 6 | Shared sales/purchase application services and web/API convergence |
| 7 | Shared GST/tax determination and snapshots |
| 8 | Returns/reversals, rendering/printing and dispatch |
| 9 | Manufacturing refactor and generic job work |
| 10 | General/FMCG/Textile/Timber/Solar profile defaults and extensions |
| 11 | UI/API/security completion |
| 12 | Opening-data migration, parallel run, UAT, performance and deployment |

Implement required master-data changes within their dependent phases. Security and authorization are required in every new behavior; phase 11 completes integration rather than postponing protection. The 24-week roadmap is a planning reference, not a delivery guarantee.

For each package, state observed/target behavior, responsible source of truth and affected readers/writers before editing. Record company scope, capability, permission, stock, accounting, tax, document, API, audit, tests and migration impact; use N/A when applicable. Validate, review, commit and push each completed package before dependent work. Do not proceed with failed new tests, unexplained reconciliation differences or unresolved schema/data contradictions.

### Recorded implementation evidence

| Package | Commit | Recorded proof |
|---|---|---|
| Phase 0 transaction regressions | `d6b69d2` | 7 SQLite tests, 50 assertions; sale/purchase/transfer relationships and purchase receipt repair |
| Phase 1 A: schema/backfill | `2535838` | 16 SQLite tests, 83 assertions; dry-run, guards, rollback, ownership and date preservation |
| Phase 1 B: context primitives | `b2df894` | 27 SQLite tests, 34 assertions; membership/branch/FY resolution, spoofing rejection and period guards |

The recorded targeted suite totals **50 tests and 167 assertions**. See [implementation progress](documents/zolo_erp_implementation_docs/IMPLEMENTATION_PROGRESS.md) for fixtures, effects and limitations. These results are package evidence, not a claim that this documentation update reran tests.

The application boot and route baseline succeeded. The original feature suite recorded 2 passed and 16 failed because seeded MySQL was unavailable. The frontend build baseline failed with absent dependencies/build configuration. SQLite proof does not establish MySQL migration compatibility, concurrent safety, full HTTP isolation or production-data parity.

MySQL 8.4 local and GitHub Actions proof covers full source migrations, committed-DDL recovery, backfill, commercial regressions, context/setup and ten bounded API reads. The current combined CI suite passes 102 tests, 459 assertions; targeted local context/ERP proof passes 75 tests, 335 assertions. Original seeded web/API/accounting smoke passes 16 tests, 54 assertions after fixture-only missing-parent preparation. Phase 1 remains pending full isolation integration and representative retained-data cutover. All dependent phases remain pending. No production migration/backfill or deployment has been performed by these packages.

### Validation and UAT

Run the smallest relevant proof for each change: targeted behavior tests, applicable lint/type checks, affected builds, and browser verification for browser-visible changes. Capture broad environment baselines once; repeat only when changes or failures justify it. Documentation-only changes require document/link/diff checks.

Transaction-engine tests compare document, movement, projection, journal, open item, tax snapshot and audit effects. Include rollback, idempotency, company/branch isolation, period locks and concurrent numbering/last-stock/allocation tests. Full phase acceptance includes relevant dependency and reconciliation checks.

Required industry UAT:

| Profile | End-to-end scenario |
|---|---|
| General | Purchase, sale, payment and return |
| FMCG | Batch receipt, FEFO sale, expiry/damage and UOM conversion |
| Textile | Job-work send/receive, service bill and wholesale sale |
| Timber | Dimensional receipt, selected-piece sale and conversion |
| Solar | Project, serialized procurement, allocation, installation, invoice and payment |

Preserve legacy zoloERP Pro critical flows and review original operator behavior through the [traceability matrix](documents/zolo_erp_implementation_docs/27_REQUIREMENT_TRACEABILITY_MATRIX.md).

Initial performance budgets from document 24, to measure with representative data:

- Fast POS warm interactive: at most 1.5 seconds.
- Master autocomplete p95: at most 300 milliseconds.
- Twenty-line invoice post p95: at most 1 second, excluding external messaging.
- Common list first page p95: at most 800 milliseconds.
- Heavy financial summary: at most 3 seconds or an asynchronous export.

These are targets, not current measured guarantees. Optional local caching must respect company isolation and freshness; it does not replace server validation.

### Deployment and operations

Use the existing PHP/Laravel/MySQL stack. Add Redis/queues only when selected behavior needs them. Define secret ownership, storage permissions and structured logs with company/correlation context.

The planned `erp:health` command checks database/migrations, company/FY, capability dependencies, semantic mappings, series, enabled workers/scheduler, backup freshness and reconciliation. It is not delivered by the current foundation packages.

Before production activation:

1. Test off-host database/upload backups by restoring them.
2. Rehearse migrations/backfills and compatible code rollback on representative data.
3. Install the release, run applicable migrations, validate health/reconciliation, then refresh caches and restart required workers.
4. Run focused smoke tests and inspect posting/dispatch failures.
5. Apply controlled cutover only after opening balances and parallel-run differences are approved.

A failed print/message task is observable and retryable without corrupting posted transactions. Schema rollback and code rollback must respect retained business history and the release's documented compatibility.

Update this reference and implementation progress when a source-of-truth decision, policy or delivered gate changes. Completion means proven behavior and reconciliation, not generated files or visible menus.

