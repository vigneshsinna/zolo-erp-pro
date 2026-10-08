# Phase 6: shared commercial posting and fast entry

> **Superseded for entry UI:** the standalone Fast Entry screens were removed. F2/F12 are keyboard accelerators into the normal `/sales` and `/purchases` pages. See [DOCUMENT_ENTRY_CONSOLIDATION.md](../DOCUMENT_ENTRY_CONSOLIDATION.md).

Implementation branch: `codex/phase6-shared-commercial`, based on Phase 4c commit `3e4a112`. Phase 5 remains a separate dependency. The commercial cutover and optional capabilities remain inactive. No production migration or deployment has been performed.

## Delivered behavior

Sales and purchases use shared application and posting services. Authorized company, branch, fiscal year, actor, ownership, stock rules, numbering and accounting effects come from existing authoritative services. Gated legacy web forms, POS and API creation converge on the same application command. Browser preview is advisory; posting recalculates totals and validates prices, quantities and configured tax rates on the server.

Company-scoped idempotency keys retain the request hash and document identity. Identical retries return the original document; changed requests cannot reuse a key. After-commit posting events fire once. Posted documents and lines reject destructive edits. Replacement atomically reverses the original and creates a new numbered document. Reversal preserves documents, payments, journals and movements while appending accounting and stock reversals. Documents without reviewed branch/fiscal metadata require migration before posting or reversal.

Purchases allocate landed cost by value, quantity, weight or manual amounts. Service and digital items never create stock movements. Partial and pending bills recognize the full payable; unreceived physical value goes to goods in transit. Later receipt releases transit value into inventory without duplicating AP. Optional PO/receipt references, transport/LR details, and explicitly selected cost/HSN updates are retained. Product updates require `products-edit`.

Credit control reads company-wide customer outstanding and overdue open items, credit days and credit limits. Zero or absent limits mean unlimited credit. Overrides require `sales.override_credit`, an audited reason and the effective company role. The existing role permission screen exposes this permission after cutover. Initial and later payments use owned settlement accounts; unsupported legacy tenders require their reviewed settlement workflow.

Fast Sales F2 and Fast Purchase F12 are core Blade views using the shared engine. They provide party/product prefix search, keyboard focus, previous/current/total HUD, credit details, previous rates, paginated pending bills and statements, server autosave with version conflict detection, draft restore, clone-from-prior, inline masters, stock tracking, freight/discount/transport details and a save-to-print dialog. Cloning clears stock identities and uses a new idempotency key. Invoice save and deletion of its owned draft share a transaction.

Shortcuts: Space party search, Enter advance/select, Alt+C new party, F6 new item, Ctrl+S previous rates, Ctrl+B pending bills, Alt+Y party statement, Ctrl+Enter save, Esc close. F2/F12 switch entry modes. Purchase tracking exposes typed serial, batch/expiry, weight, manual freight and dimensioned-piece inputs.

## Integration and activation

`ERP_SHARED_COMMERCIAL_ENABLED` defaults to false. Shared routes require Phase 5 accounting classes and schema. Sales and purchase entry use the normal pages and are gated only by `core.sales` / `core.purchases` plus the add permission (the former `sales.fast_counter` / `purchases.fast_entry` capabilities were retired); this branch does not lift the existing Phase 1/optional-capability activation gates.

Integrate the final Phase 5 package before applying `2026_10_05_000001_create_shared_commercial_contracts.php` to a reviewed UAT database. Retain both packages' route and sidebar changes. In `app/Services/ERP/PaymentService.php`, retain Phase 5 exact amounts, settlement idempotency and open-item allocations together with Phase 6's active-posted-source guard. The current branch intentionally contains only the Phase 6 guard in that file; Phase 5 supplies the accounting settlement implementation.

Re-run the combined commercial suite after integration. Review ownership/backfill, account mappings, settlement-account links, period locks and retained commercial history before enabling cutover. Seed the capability catalog through the existing platform seeder and explicitly grant credit override only to authorized roles. Existing retained tax rows need reviewed company ownership before taxed commercial posting.

The additive migration preflights existing tables, resumes committed DDL and preserves data. Destructive rollback is deliberately refused; use a reviewed forward migration or a verified backup. Existing posted values and numbers are not rewritten.

Unsupported gateway callbacks, split tender and unconverted legacy mutations fail closed after cutover. Bulk reversal requires an explicit date and reason. GST determination and statutory snapshots remain Phase 7. Line-level returns and new printing profiles remain Phase 8; the print dialog uses existing rendering routes. Optional capabilities and second-company activation remain subject to their recorded acceptance gates.

Stock unit-cost precision can reject an allocation when the rounded movement value differs from the accounting amount. The transaction rolls back; no balancing amount is invented. UAT must include the business's largest quantities and valuation allocations.

## Validation

Local integration used an ignored snapshot of Phase 5 accounting services/models and its migration. For settlement tests the snapshot combined Phase 5 `PaymentService` with the Phase 6 source guard. These files and disposable databases are not part of the delivered branch. Integration proof is not production cutover acceptance.

The suite covers web/API/POS equivalence, duplicate and changed keys, locked-period retries, rollback, credit overrides, partial receipt, service lines, landed cost, immutable replacement, draft versions, malformed inputs, decimal tax/charges, serials, batch/expiry, dimensioned pieces, inline master retries, later settlement reversal, statement pagination, explicit cost/HSN updates and additive migration resumption.

Run on the combined Phase 5/6 code:

```text
php vendor/phpunit/phpunit/phpunit -c phpunit.commercial.xml
```

Without Phase 5, integration tests explicitly skip; pricing tests still run. Set `ERP_COMMERCIAL_PERF=1` to run the 50,000-item/50,000-party timing fixture. Use the repository's `ERP_TEST_MYSQL` opt-in and an explicitly disposable database name for MySQL proof. Fixture setup time is excluded from timing samples. Warm-screen measurement covers the Laravel HTML response and does not claim production browser paint/network latency.

For browser proof, export the disposable SQLite fixture with `ERP_EXPORT_COMMERCIAL_BROWSER=1` while running `CommercialBrowserFixtureTest`, then use:

```text
php -S 127.0.0.1:8766 -t public tests/Support/commercial_browser_server.php
```

The test router permits only loopback requests and a fixture file beneath `scratch`. It enables capabilities and authenticates a fixture operator only for this test process. Never use it as a production entry point.

Final local proof on October 4, 2026:

- Commercial SQLite suite: 28 tests, 147 assertions, with the opt-in performance test skipped.
- MySQL commercial behavior suite: 23 tests, 137 assertions, all passed.
- MySQL performance fixture: 30 measured samples with 50,000 products and 50,000 customers; product lookup p95 7.51 ms, party lookup p95 8.28 ms, twenty-line posting p95 812.16 ms, warm HTML response p95 72.46 ms. All measured budgets passed.
- Ungated Phase 4 regression suite on this branch: 40 tests, 325 assertions, with one opt-in concurrency fixture skipped. This regression run uses the branch's existing accounting implementation; the commercial suites use the Phase 5 dependency snapshot.
- PHP syntax, JavaScript syntax and Git whitespace checks passed.
- Browser smoke checks posted a ten-line keyboard-only sale and a purchase, exercised pending bills, tracking controls, inline customer creation and draft save/reload/restore, and checked the 390-pixel mobile layout. Production browser paint/network performance remains a UAT measurement.

Posting writes stock metadata with the original document lines and validates product/unit ownership with ordered batch locks. It retains transaction-local ownership validation and uses no cross-request authorization cache. Master lookup uses separate indexed prefix ranges combined by UNION, preserving company filtering and literal wildcard escaping.

Proof images: [keyboard-only ten-line sale](../phase6-proof/keyboard-sales-posted.jpg), [purchase post](../phase6-proof/purchase-posted.jpg), [mobile sales](../phase6-proof/mobile-sales.jpg), [restored draft](../phase6-proof/draft-restored.jpg).
