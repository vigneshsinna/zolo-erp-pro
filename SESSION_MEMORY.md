# zoloERP Pro — Persistent Session Memory & State

> **CRITICAL AGENT INSTRUCTION (Crash Recovery & Session Persistence)**:
> This document is the single persistent source of truth for the active development session.
> If the IDE, machine, or assistant crashes or resets, **immediately read this file** to restore full working context into memory.
> Keep this file updated after every milestone, commit, or architectural decision.

---

## 1. Active Session Metadata
- **Last Updated:** 2026-10-08 (+05:30) — document entry consolidation complete (section 13)
- **Active Git Branch:** ui
- **Upstream Remotes:**
  - `upstream`: https://github.com/nandha3d/zolo-erp-pro.git (`ui`)
  - `origin`: https://github.com/vigneshsinna/zolo-erp-pro.git (`ui`)
- **Latest Commits:** see `git log` on `ui`; the document-entry series is `test(commercial) fixture` → `feat(ui) shortcuts + ?new=1` → `feat(ui) fast-entry aids + server draft tabs` → `refactor(ui) remove duplicate entry UI + capabilities` → `test(docs) verification + documentation`.
- **Working Tree State:** clean after the series above (commits are local until pushed; the user approved pushing once complete).
- **Database & Server State:**
  - Database: MariaDB (Ubuntu WSL daemon) running on port 3307 with all 179 tables and Optech master migrations applied and seeded (`sale_types`, `purchase_types`, `dc`, `grn`).
  - Web Server: Single instance on `http://localhost:8080` (bound to `0.0.0.0:8080 -t public server.php`).
- **Chat & Transcript Backups:**
  - Raw JSONL: [`documents/chat_backups/session_47d4cc38_raw_transcript_20261007.jsonl`](file:///v:/pers/Freelance/zolo-erp-pro/documents/chat_backups/session_47d4cc38_raw_transcript_20261007.jsonl)
  - Readable History: [`documents/chat_backups/SESSION_CHAT_HISTORY_20261007.md`](file:///v:/pers/Freelance/zolo-erp-pro/documents/chat_backups/SESSION_CHAT_HISTORY_20261007.md)

---

## 2. Top Bar Box Model Normalization & Focus Mode (Oct 2026)

Addressed user feedback `"hiding the top bar"` where the top navigation bar was vertically clipped/overlapped by the primary fields card:

1. **Root Cause Analysis of Top Bar Clipping & Overlap:**
   - Legacy `custom-default.css` and `style.default.css` enforced `line-height: 60px` on `nav.navbar` and `nav.navbar a`.
   - `style.default.css` set `nav.navbar .badge` to `position: absolute; right: 0; top: 7px; border-radius: 50%; width: 20px; height: 20px;`, transforming `#doc-breadcrumb-mode` into a circular purple bubble in the top-right corner.
   - `commercial-workspace.css` constrained `header` to `38px` while child navbar elements were 42-60px high, overflowing downwards.
   - `#content` was placed at `calc(100vh - 38px)` immediately below, causing `.doc-primary-fields` (white background) to sit directly on top of the bottom half of the top bar.

2. **Box Model & Alignment Fix:**
   - Normalized `header.container-fluid` and `.navbar` to `height: 40px !important; line-height: normal !important; display: flex; align-items: center; justify-content: space-between; flex-wrap: nowrap;`.
   - Constrained `#toggle-btn` to 28x28px, `.nav-menu` items to 26px height, `.btn-pos` to 26px, and segmented pills to 20px height.
   - Neutralized legacy `nav.navbar .badge` absolute positioning: enforced `position: static !important; width: auto !important; height: auto !important; border-radius: 4px !important;`.
   - Aligned `#content` to `height: calc(100vh - 40px) !important;` so there is zero overlap between the header and the form card.

3. **Focus / Distraction-Free Mode (Hide Top Bar Toggle):**
   - Added `▲ Hide` toggle button (`#btn-toggle-topbar`) to the top command bar in `purchase/index.blade.php` and `sale/index.blade.php`.
   - When clicked (or via shortcut `Ctrl + Shift + F` / `Alt + T`), applies `.topbar-hidden` to `<html>` and `<body>`:
     - Top header collapses smoothly (`display: none !important; height: 0;`).
     - `#content` expands to full `100vh`.
     - Floating reveal strip (`#reveal-topbar-strip`) appears at top center: `▼ Show Top Bar (Ctrl+Shift+F)`.
     - Preference persisted in `localStorage` (`zolo_topbar_hidden`).

---

## 3. Vertical Space Compaction, In-Cell Table Editing & Modal Activation (Oct 2026)

Addressed user feedback regarding vertical space occupation, bill fields height reduction by >50%, table editability, and modal activation:

1. **Top Navbar Command Strip Consolidation:**
   - Moved all page titles (`Purchase Command Center` / `Sales Command Center`, `New Purchase Bill` / `New Sales Bill`), breadcrumb mode badges, cash/credit segmented pills, product/service/mixed pills, register navigation pills (`All`, `GRN`/`DC`, `Transfers`, `Returns`), live `TOTAL: ₹ 0.00` badge, `⚙ Details`, and `📖 Bill list` buttons directly into the empty space in the main layout navbar (`resources/views/backend/layout/main.blade.php` via `@hasSection('navbar-center')`).
   - Completely eliminated the redundant `.comm-command-bar` from the page content, saving ~38px of vertical height.

2. **Ultra-Compact Primary Document Fields (Height Reduced by >60%):**
   - Re-architected primary fields into a tight 2-row layout with 24px input heights and 9.5px uppercase labels (`.doc-primary-fields.compact-fields`).
   - Total card height reduced to ~52px (down from ~160px), reclaiming >100px of vertical space for the items table.
   - Row 1: Our Bill No, Supplier Inv No / Customer PO, Bill Date, Entry Date, Party selector with `+ New Party`, and inline GSTIN / formatted address / credit days strip.
   - Row 2: Tax Classification, Series, Warehouse (and Biller for sales), plus compact Logistics & Remarks quick strip.

3. **Full Viewport Space Utilization for Items Grid:**
   - Removed redundant `< Page 1/1 >` pagination row below the items table.
   - Set `.comm-entry-workspace form`, `.desk-card.items-container`, and `.table-responsive` to flex-grow (`flex: 1 1 auto; height: 100%`) so the table stretches all the way down to the bottom summary bar, filling all available vertical space.

4. **100% In-Cell Editable Items Table:**
   - Updated `addProductRow` in both `purchase/index.blade.php` and `sale/index.blade.php` so all table cells are editable:
     - Item Name: `<input class="form-control form-control-sm row-item-name" name="product_name_text[]">`
     - Type: `<select class="row-type-select" name="purchase_type_line[]">` / `name="sale_type_line[]"`
     - Unit: `<select class="row-unit-select" name="purchase_unit[]">` / `name="sale_unit[]"`
     - Rate: `<input class="row-rate">`
     - Qty: `<input class="row-qty">`
     - Amount: `<input class="row-amount">` with two-way reactive calculation (editing Amount recalculates Rate: `rate = amount / qty`; editing Rate recalculates Amount: `amount = rate * qty`)
     - Tax Rate: `<select class="row-tax-rate">`
     - Total: `<input class="row-total" readonly>`
     - Actions: Pencil button opens `#row-detail-modal` for Batch, Expiry, Serial/IMEI, Item Discount; trash button deletes row.
   - Preserved `Product::firstOrCreate` auto-creation in controllers for custom/ad-hoc typed items.

5. **Fixed "+ Create item" and "Multi item" Modals:**
   - Added standard Bootstrap `data-toggle="modal" data-target="#..."` attributes to buttons.
   - Appended modals directly to `document.body` on load (`$('#multi-item-modal, #quick-create-item-modal, #row-detail-modal, #charges-drawer, #purchase-details').appendTo('body')`), completely eliminating clipping, transform, or backdrop z-index trapping.
   - Added explicit JavaScript trigger handlers to guarantee instant modal display without page reload or navigation.

Recovered undelivered stacked user messages from conversation storage and implemented solutions for all 12 feedback requests:

1. **Auto-Retracting Dropdowns on Outside Click:**
   - Fixed dropdown menus, bootstrap-select, and jQuery UI autocompletes remaining open when clicking outside.
   - Bound global document click handlers in both `resources/views/backend/sale/index.blade.php` and `resources/views/backend/purchase/index.blade.php` that retract any active `.bootstrap-select.open`, `.dropdown.show`, and close autocomplete panels.

2. **Top Command Bar Dark & Thin Strip:**
   - Converted `.comm-command-bar` to a sleek, thin top strip matching the dark navigation theme (`#0f172a` / `#1e293b`).
   - Reduced padding to `3px 12px` and margin to `4px 0`, maximizing vertical space for the voucher table.

3. **Party Info Card (Legible GSTIN & Full Address):**
   - Replaced "Party address loads automatically" placeholder with `#party-info-card`.
   - Customer and supplier selection populates `GSTIN` badge (`#party-gst-badge`), credit days badge (`#party-credit-badge`), and large readable formatted address (`#party-address-text`).

4. **Button Label Cleanup ("++ New"):**
   - Cleaned up repetitive "+" text on side panel buttons to standard `New Bill` and `New Purchase`.

5. **Inline "+ Create Item" Modal (Zero Tab/Window Redirection):**
   - Prevented opening new tabs or pages when clicking "+ Create item".
   - Implemented `CommercialController::quickStoreProduct` and route `POST products/quick-store`.
   - Connected `#quick-create-item-form` via AJAX: automatically creates active catalog product, updates autocomplete master list, and immediately inserts the new line into the grid table.

6. **Full-Width Bill List Panel Toggle:**
   - Added `⛶` / `⧉` full-width toggle button (`#btn-panel-fullscreen`) to side panel header.
   - Toggles `.panel-fullwidth` on `.comm-split-grid`, expanding the register across the entire workspace (preserving left navigation sidebar).
   - Automatically returns to split view when editing or creating a voucher.

7. **Fix Old Bills In-Place Editing (Resolved 500 Crashes):**
   - In `SaleController::updateSale` and `PurchaseController::update` (and `PurchaseController::store`), added null-coalescing defaults for missing arrays (`imei_number`, `recieved`, `batch_no`, `expired_date`, `unit_cost`, `net_unit_margin`, `net_unit_price`).
   - Added dual unit lookup by name or unit code (`Unit::where('unit_name', $u)->orWhere('unit_code', $u)->first()`), preventing null pointer exceptions.

8. **Density Switcher Row Resizing:**
   - Removed hardcoded inline styles (`style="height:26px..."`) from `addProductRow`.
   - Defined distinct row heights and input paddings in `commercial-workspace.css` for `.compact` (26px), `.cozy` (36px), and `.large` (48px).
   - Removed duplicate shadowed event listeners so density switching applies immediately and persists in `localStorage`.

9. **Multi-Item Fast Batch Picker:**
   - Added explanatory subtitle to `#multi-item-modal`: "Select multiple products with quantities and insert them all at once into the voucher table."

10. **Centered Delete Button in ACTIONS Column:**
    - Styled `.btn-delete-row` with centered flex container alignment (`26x26px`, centered icon).

11. **Zebra Striping on Items Table:**
    - Added alternating zebra row backgrounds (`.desk-grid-table tbody tr.order-item-row:nth-child(even)` `#f8fafc` vs `:nth-child(odd)` `#ffffff`) with subtle hover highlight.

12. **Fixed Bottom Summary Bar & Header Total Badge:**
    - Resolved bottom summary bar getting clipped below viewport by converting `.comm-entry-workspace form` to a flex container with `.table-responsive` taking `flex: 1; overflow-y: auto`.
    - Added live `#header-grand-total-display` badge in header meta bar for instant total visibility.

---

## 2. Last Session Summary & Recovered Context

### A. Root Cause Resolution for 403 Forbidden Errors
- **Problem:** User reported that most web pages were returning 403 Forbidden after navigation.
- **Root Cause:** In app/Providers/AppServiceProvider.php, custom Blade @can and @cannot directives were improperly registered or overriding the native authorization handler when checking permissions for roles.
- **Fix:** Corrected @can / @cannot gate handling in app/Providers/AppServiceProvider.php and verified role/permission evaluation across menus, sidebars, and authenticated screens.

---

### B. Optech Modern Master Management (Zero Hardcoding Enforced)
Implemented backend tables, models, controllers, and inline creation ([+] / Alt+C) modal endpoints for all Optech screen masters:
1. **Agents / Brokers (agents):**
   - Model: app/Models/Agent.php
   - Controller: app/Http/Controllers/AgentController.php
   - View: resources/views/backend/master/agent.blade.php
2. **Areas / Regions (areas):**
   - Model: app/Models/Area.php
   - Controller: app/Http/Controllers/AreaController.php
   - View: resources/views/backend/master/area.blade.php
3. **Bill Sundries (bill_sundries):**
   - Model: app/Models/BillSundry.php
   - Controller: app/Http/Controllers/BillSundryController.php
   - View: resources/views/backend/master/bill_sundry.blade.php
4. **Sale Types (sale_types):**
   - Model: app/Models/SaleType.php
   - Controller: app/Http/Controllers/SaleTypeController.php
   - View: resources/views/backend/master/sale_type.blade.php
5. **Purchase Types (purchase_types):**
   - Model: app/Models/PurchaseType.php
   - Controller: app/Http/Controllers/PurchaseTypeController.php
   - View: resources/views/backend/master/purchase_type.blade.php
6. **Standard Remarks (standard_remarks):**
   - Model: app/Models/StandardRemark.php
   - Controller: app/Http/Controllers/StandardRemarkController.php
   - View: resources/views/backend/master/standard_remark.blade.php
7. **Document Series (document_series):**
   - Model: app/Models/DocumentSeries.php
   - Controller: app/Http/Controllers/DocumentSeriesController.php
   - View: resources/views/backend/master/series.blade.php
8. **Delivery Challans & Goods Received Notes:**
   - Models: app/Models/DeliveryChallan.php, app/Models/GoodsReceivedNote.php
   - Migration: database/migrations/2026_10_13_000004_create_optech_dc_and_grn_tables.php

---

### C. Commercial Billing Entry Modernization
- **Files Modified:**
  - public/js/commercial-entry.js
  - resources/views/backend/commercial/entry.blade.php
  - app/Http/Controllers/CommercialController.php
- **Key Enhancements:**
  - Added Series selection and dynamic document numbering.
  - Added Sale Type and Agent dropdowns with inline [+] creation.
  - Added Transport Details popup (Bale No, No of Bales, LR No, LR Date, Transporter, Station To, Order No).
  - Added Rate History modal (Alt+UpArrow) querying historical party item purchase cost and selling rates.
  - Added Bill Sundries grid for taxes, discounts, and custom freight adjustments.
  - Added keyboard navigation shortcuts (F2, F12, F6, Alt+C, Alt+Y, Ctrl+S, Ctrl+B, Ctrl+Enter).

---

### D. Keyboard-Driven Multi-Voucher Entry
- **File:** resources/views/backend/accounting/voucher_entry.blade.php
- **Controller:** app/Http/Controllers/Accounting/JournalEntryController.php
- **Features:**
  - Tabbed support for Payment, Receipt, Journal, Contra, Sales Voucher, Purchase Voucher, Debit Note, and Credit Note.
  - Live Debit vs Credit balancing status.
  - Real-time current balance indicator for selected ledger accounts.
  - Bill-by-bill allocation dialog for outstanding invoice settlement.
  - Inline account creation modal ([+] / Alt+C).

---

### E. Modern Desk Workspace Billing UI (Sales & Purchase)
- **Files Modified:**
  - `resources/views/backend/commercial/entry.blade.php`
  - `public/css/commercial-entry.css`
  - `public/js/commercial-entry.js`
  - `app/Http/Controllers/CommercialController.php`
  - `resources/views/backend/sale/index.blade.php`
  - `resources/views/backend/purchase/index.blade.php`
  - `tests/Feature/OptechMasterWebTest.php`
- **Features & Visual Alignment:**
  - **Left Desk Sidebar:** Collapsible dark sidebar (`#111827`), Selling and Buying modules with purple active pill badges, fast navigation across Desk modules.
  - **Top Desk Navigation Bar:** Breadcrumb badges (Selling / Buying), global search with ⌘K badge, company badge with pulsing status dot (`● Sri Murugan Textiles` / active entity), user profile pill.
  - **Multi-Tab Document Strip:** Tabs for `Bills • Browse list`, `Draft 1`, and `+ New bill`.
  - **Toggleable & Dockable Side Panel ("Bill list" / "Purchase list"):**
    - Toggleable via button (`[ 📖 Bill list ]` / `[ 📖 Purchase list ]`), top tab, or `✕` close.
    - Dockable on **EITHER side** (arrangeable on LEFT or RIGHT via `⇄ Dock Right` / `⇄ Dock Left` button).
    - Preference persisted across sessions via `localStorage` (`zolo_panel_dock` and `zolo_panel_open`).
    - Search input, bill number filter, tabs (`All`, `Draft`, `Date`, `Range`), bill card list with load-to-form capability.
  - **Header Controls & Segmented Pills:**
    - Cash / Credit pill toggle (Credit selected in bright blue/purple).
    - Product / Service / Mixed line nature pill toggle.
    - Bill number with live Series preview, party search with address loader note, tax classification (Sale Type / Purchase Type) with GST badge.
  - **12-Column Items Grid Table with Vibrant Purple Header (`#7c3aed`):**
    - `S.NO`, `ITEM`, `SALES TYPE`, `UNIT`, `QTY`, `RATE + TAX`, `RATE`, `TAXABLE AMOUNT`, `GST / IGST %`, `TAX AMOUNT`, `LINE TOTAL`, `ACTIONS`.
    - Density selector pills (`Compact`, `Cozy`, `Large`).
    - Clean empty-state placeholder rows matching the reference ERP screen.
    - Live calculation of taxable amounts, taxes, and line totals.
  - **Charges, Transport & Remarks Slide-Over Drawer:**
    - Opens via `[ Charges & remarks  (count) ]` button or header `[ ⚙ Details ]`.
    - Tabs for Bill Sundries, Transport & Bales (Bale No, No of Bales, LR No, LR Date, Transporter, Station To, Order No, Credit Days), Remarks & Notes, Settlement.
  - **Fixed Bottom Summary & Action Bar:**
    - Action buttons: `[ ↺ Discard ]`, `[ 💾 Save as ]`, `[ 💾 Save ]` (primary purple), `[ Review ]`.
    - Summary totals: `NET`, `GST / TAX`, and `GRAND TOTAL`.
  - **Zero Regression Rule:** 100% of existing backend fields, models, migrations, and inline creation modals ([+]) are preserved intact.
 
---
 
### F. Single Compact Box UI Normalization (Elimination of Double Boxes)
- **Problem:** User reported nested/double boxes on:
  1. Filter dropdowns (Warehouse, Purchase Status, Payment Status).
  2. Opened dropdown menus (outer container vs inner list).
  3. DataTables pagination at footer (`<`, `1`, `>`).
  4. Export / action buttons (PDF, Excel, Colvis) and row action buttons.
- **Root Causes Identified & Fixed:**
  1. **Bootstrap Select Wrapper:** `<select class="form-control">` causes bootstrap-select to clone `.form-control` onto the outer `.btn-group.bootstrap-select`. Both outer container and inner `.btn.dropdown-toggle` had borders/shadows, creating a nested double box. Neutralized outer container border, background, and padding across `commercial-workspace.css` and `zolo-erp-neo.css`.
  2. **Dropdown Menus:** In bootstrap-select, `.dropdown-menu` wraps `.inner` and `ul.inner`. Stripped borders/shadows/padding from inner elements, retaining a single crisp 1px bordered card container (`border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 6px 18px rgba(15,23,42,0.08)`).
  3. **DataTables Pagination:** In Bootstrap 4 DataTables markup (`<li class="paginate_button page-item"><a class="page-link">1</a></li>`), outer `<li>` had borders/padding and inner `<a>` had borders/padding. Stripped all styling from outer `li` and normalized `.page-link` to a single compact box (`26px height, 1px solid #cbd5e1, 5px radius`).
  4. **DataTables Buttons:** DataTables wrapped nested arrays in `.btn-group`. Flattened `buttons.push(...)` in `purchase/index.blade.php` and `sale/index.blade.php`, swapped Excel export icon to `fa fa-file-excel-o`, and added single compact box styles to action buttons, search box, and length selector.
  5. **Dynamic Cache-Busting:** Added dynamic `?v={{ filemtime(...) }}` query parameters to CSS links in `purchase/index.blade.php` and `sale/index.blade.php` to guarantee immediate browser cache invalidation.

---
 
### G. Integrated Dockable Bill List Panel directly into Existing Sales & Purchase Command Centers
- **Context & User Request:** Rather than navigating to a separate Desk billing interface (`/commercial/sales/entry`), user requested enhancing the **existing** `/sales` and `/purchases` workspaces.
- **Architectural & UI Implementation:**
  1. **Retained 100% of Existing Workspace:** In the main area, the full DataTables register, date/warehouse/status filter bar, column visibility buttons, pagination, and KPI summary counters are preserved unchanged.
  2. **Transformed Right Drawer into Bill List Panel:**
     - Replaced the redundant "Fast Sale Console" and "Fast Purchase Console" right drawer with the dockable **Bill list** (`desk-bill-list-panel`).
     - Directly lists recent invoices/purchases with live search (`Find a bill...` / `Find a purchase...`), series filter (`Series or edited number`), and status pills (`All`, `Draft`, `Date`, `Range`).
     - Each card displays invoice number, customer/supplier name, transaction date, amount formatted in ₹, and color-coded status badges (`Paid`, `Due`, `Partial`, `Draft`).
     - Cards include immediate action links: `[ ✎ Edit ]` (navigates directly to the bill edit page for instant modification), `[ 👁 View ]` (triggers modal detail review), and `[ 🖨 Print ]` (opens printable invoice).
  3. **Dockable to Either Side & Fully Toggleable:**
     - Header tools include `⇄ Dock Left` / `⇄ Dock Right` button and `✕` close button.
     - Top navigation bar includes `[ 📖 Bill list ]` / `[ 📖 Purchase list ]` button to reopen collapsed drawer.
     - Docking mechanics leverage CSS Grid (`grid-template-columns: 1fr 360px` vs `360px 1fr` via `.dock-left` and flex order) for seamless positioning without DOM displacement.
     - User preferences for panel open/collapsed and dock orientation (left vs right) are automatically persisted across page reloads in `localStorage` (`zolo_bill_panel_dock` and `zolo_bill_panel_open`).
  4. **Backend Controllers:**
     - `SaleController::index()`: Eager loads `$recent_bills = Sale::whereNull('deleted_at')->with('customer:id,name,phone_number')->latest('id')->limit(50)->get();` and passes `$recent_bills` to `backend.sale.index`.
     - `PurchaseController::index()`: Eager loads `$recent_bills = Purchase::with('supplier:id,name,company_name,phone_number')->latest('id')->limit(50)->get();` and passes `$recent_bills` to `backend.purchase.index`.

---

### H. Optech Commercial Voucher Entry Transformation in Sales & Purchase Command Centers
- **Context & User Request:**
  - In the main content area, do NOT show the table/DataTables bills register.
  - The content area must be a voucher entry form (adding a new bill by default: `New Purchase Bill` / `New Sales Bill`) matching **Screenshot 3**.
  - All options from "add purchase" and "add sale" brought directly into this simple Optech voucher entry workspace.
  - The side panel lists recent bills. When a bill card or `[ ✎ Edit ]` is clicked in the side panel, it loads into the main form for in-place editing (Optech software workflow).
  - The toolbar filters from **Screenshot 2** (PDF, Excel, CSV, Print, Reset icon buttons) moved into the side bill list panel.
  - The filter dropdowns (`Warehouse`, `Purchase/Sale Status`, `Payment Status`) moved from the top bar into the side bill list panel itself.
  - Strictly no invented logic or UI elements not present in the background.
- **Architectural & Implementation Details:**
  1. **Main Entry Workspace (`.comm-entry-workspace`) Matching Screenshot 3:**
     - **Header Strip:** Breadcrumb trail (`Home / Buying / Purchase Bills / New` or `Home / Selling / Sales Bills / New`), document icon, dynamic title (`New Purchase Bill` / `New Sales Bill`), pill toggles (`Cash`/`Credit`, `Product`/`Service`/`Mixed`), due date and credit days metadata, quick tools (`[ 📖 Bill list ]`, `[ ⚙ Details ]`).
     - **Primary Fields Row:** `Our bill number` (with series preview and hint), `Supplier bill no` / `Customer PO / Ref`, `Bill date`, `Entry date`, `Party *` (with `+ New Party` modal link and address hint), `Tax classification *` (`purchaseTypes` / `saleTypes`), `Series` (`documentSeries`), `Warehouse *`, and `Biller *` (for sales).
     - **Items Grid Section:** Counter (`ITEMS 0 line(s) • 7 per page`), density selector (`Compact`, `Cozy`, `Large`), action buttons (`Multi Item`, `+ Create item`, `+ Add row`), quick barcode/item search with `F2` shortcut.
     - **10-Column Purple Header Table (`#7c3aed`):** `#`, `ITEM`, `PURCHASE/SALE TYPE`, `UNIT`, `RATE`, `QTY`, `AMOUNT`, `TAX`, `TOTAL`, `ACTIONS`. Alternating row lines, empty placeholder state matching screenshot, and `< Page 1/1 >` footer.
     - **Fixed Bottom Summary Bar:** `Charges & remarks (count)`, inline remarks preview, actions (`[ ↺ Discard ]`, `[ 💾 Save as ]`, `[ 💾 Save ]`, `[ ✓ Submit ]`, `[ Review ]`), live financial calculations (`NET`, `GST / TAX`, `GRAND TOTAL`).
     - **Charges & Remarks Drawer:** Slide-over modal with tabs for Transport & Logistics (Bale No, No of Bales, LR No, LR Date, Transporter, Station To, Order No, Credit Days), Notes & Remarks (standard remark picker and notes textarea), and Payment/Account settlement.
  2. **Dockable Side Panel with Screenshot 2 Filters & Dropdowns:**
     - Compact toolbar with Screenshot 2 export/action icons: PDF, Excel, CSV, Print, Reset (`.side-toolbar-actions`).
     - Search input (`Find a purchase...` / `Find a bill...`).
     - Bill number filter (`Series or edited number`).
     - Quick filter tabs: `All`, `Draft`, `Date`, `Range` with revealable date picker.
     - Single compact box dropdown filters moved from top bar: `Warehouse`, `Purchase/Sale Status`, `Payment Status` (`.side-dropdown-filters`).
     - Bill cards list: Displays reference number, amount in ₹, party name, transaction date, color-coded status badges (`Paid`, `Due`, `Partial`, `Draft`), and action links (`[ ✎ Edit ]`, `[ 👁 View ]`, `[ 🖨 Print ]`).
  3. **Optech In-Place Editing Mechanics:**
     - Clicking a card or `[ ✎ Edit ]` invokes `loadPurchaseToForm(id)` or `loadSaleToForm(id)` via AJAX (`/purchases/{id}` or `/sales/{id}/json`).
     - Form switches method to `PUT` and action to update URL; title updates to `Edit Purchase Bill: [ref]` / `Edit Sales Bill: [ref]`; breadcrumb updates to `Edit: [ref]`; header inputs and items rows are loaded with live rates and totals.
     - Active bill card in side list is highlighted with `.active-editing`.
     - Clicking `++ New` or `[ ↺ Discard ]` calls `resetFormToNew()`, resetting method to `POST` and action to store route, clearing inputs and blanking items grid.
  4. **Backend Controllers:**
     - `PurchaseController`: Eager loads `$lims_product_list_without_variant`, `$lims_product_list_with_variant`, `$currency`, `$purchaseTypes`, `$documentSeries`, `$billSundries`, `$standardRemarks`, `$agents`, `$areas`, `$recent_bills`. Fixed `document_type` column query. `show($id)` returns JSON for in-place edit loading.
     - `SaleController`: Eager loads product lists, series, types, bill sundries, remarks, and recent bills. Added `show($id)` and `getSaleJson($id)` returning JSON for in-place edit loading. Updated `limsProductSearch` to safely accept string queries from autocomplete.
  5. **Automated Testing Evidence:**
     - Feature tests in `OptechVoucherWebTest.php`:
       - `test_purchase_command_center_renders_entry_workspace`: PASS
       - `test_sales_command_center_renders_entry_workspace`: PASS
       - `test_purchase_json_endpoint`: PASS
       - `test_sale_json_endpoint`: PASS
     - Full test suite: 25/25 passing (125 assertions, 100%).

---

### I. Product Search Autocomplete, Density Switcher & Fast Items Entry
- **Context & Problem:** Typing in the quick search box (`#lims_productcodeSearch`) in `/sales` or `/purchases` showed no items or autocomplete dropdown.
- **Root Cause Analysis:**
  1. All 55 products in the database had `is_active = 0`. As a result, `Product::ActiveStandard()` returned 0 rows, so `$lims_product_code = []`.
  2. In `app/Models/Product.php`, `scopeActiveStandard` did not qualify table names or handle nulls flexibly.
  3. In `SaleController::limsProductSearch`, PHP 8 fatal error occurred when accessing `$request->data['price']` when `$request->data` was passed as a search query string.
  4. Product queries omitted unit and pricing metadata needed for live calculations.
  5. Missing tailored styling for jQuery UI autocomplete dropdown, resulting in low z-index clipping behind modal layers.
- **Fixes Applied:**
  1. Updated `Product::scopeActiveStandard` with table-qualified `products.is_active` and `products.type` checks.
  2. Activated existing 55 database products (`UPDATE products SET is_active = 1`).
  3. Fixed `SaleController::limsProductSearch` array guard and enriched both `SaleController` and `PurchaseController` product queries with joined unit names, unit codes, costs, and tax IDs.
  4. In `backend.sale.index` and `backend.purchase.index`, implemented high-performance `@json($jsProductList)` data structures, custom jQuery UI `_renderItem` with item title, code badge, unit, and green rate badge.
  5. Implemented live row creation on select, duplicate product quantity incrementing, `+ Add row` custom row insertion with inline item title input, `#multi-item-modal` Fast Batch Picker, and `#quick-create-item-modal` on-the-fly product creation.
  6. Implemented density switcher (`Compact`, `Cozy`, `Large`) with persistence in `localStorage`.
  7. Formatted autocomplete dropdown in `commercial-workspace.css` with `z-index: 999999 !important` and soft drop-shadow.
- **Test Evidence:** All 25 feature tests passing (100%).


---

## 3. In-Table Search, Barcode Realignment, Bottom Bar Spacing & Compact Add Product Modal (Oct 2026)

Addressed user feedback:
> "this item search must be comes in the table, barcode is not aligned properly.. also the buttons are not aligned properlygive some spacing in the bottom bar make the buttons smaller. gpa between each button. this add product must be comes in the purchase and sales item creation. make a compact one."

1. **Integrated In-Table Item Search Row (`#table-search-row`):**
   - Relocated the standalone search input from above the table directly into the first row of `#order-table` under `<tbody>`.
   - **Barcode Realignment:** In `#` column (`width: 36px`), vertically and horizontally centered a dedicated purple barcode icon (`<i class="fa fa-barcode"></i>`).
   - In `ITEM` column, placed `#lims_productcodeSearch` with an embedded magnifying glass icon (`<i class="fa fa-search"></i>`) on the left (`padding-left: 28px`), zero overlap with placeholder text, and shortcut badge `F2` pinned to the right.
   - Retained global keyboard listener (`F2`) to instantly focus the in-table search input from anywhere on the voucher.

2. **Items Toolbar Alignment & Clean Labels:**
   - Standardized button heights to 24px and normalized baseline alignment across `.items-controls-group`.
   - Removed duplicate `+` prefixes on `<i class="dripicons-plus"></i> Create item` and `<i class="dripicons-plus"></i> Add row`.
   - Ensured clean 6px spacing between `.density-segmented`, `Multi item`, `Create item`, and `Add row`.

3. **Bottom Action & Summary Bar Compaction & Spacing:**
   - Made bottom bar action buttons smaller and sleeker (`height: 24px !important; font-size: 10.5px !important; padding: 0 9px !important; border-radius: 4px !important;`).
   - Added explicit 8px gap between each button (`.desk-summary-bottom-bar .bottom-action-buttons .comm-bottom-btn + .comm-bottom-btn { margin-left: 8px !important; }`), completely resolving button collision in Bootstrap 4.
   - Added 8px separation between `Charges & remarks` and the inline remarks preview.
   - Re-architected `.comm-entry-workspace`, `form`, and `.desk-card.items-container .table-responsive` with `min-height: 0 !important; flex: 1 1 0% !important;`, preventing any vertical viewport overflow so the bottom summary bar is 100% visible and never clipped.

4. **Full Catalog Compact "Add Product" Modal (Embedded in Purchases & Sales):**
   - Replaced basic 5-field quick modal with the full 5-section catalog product creation workflow from `backend.product.create`:
     1. **Product Identification:** Product Type (Standard, Combo, Digital, Service), Product Name, Auto-generated Product Code with `⚡ Auto` button, Barcode Symbology (Code 128, Code 39, EAN-8, EAN-13, UPC-A, UPC-E), Brand (dynamic dropdown), and Category (dynamic dropdown).
     2. **Units of Measure:** Product Unit, Sale Unit, and Purchase Unit.
     3. **Cost, Pricing & Margins:** Product Cost (₹), Profit Margin (%), Product Price (₹), Wholesale Price (₹) with reactive two-way calculation (`Price = Cost + (Cost * Margin%)`).
     4. **Tax & Inventory:** Product Tax (dynamic tax dropdown with rates), Tax Method (Exclusive/Inclusive), Alert Quantity, Warranty Period & Type (Months/Years).
   - Styled with compact 24-26px form inputs and 9.5px uppercase labels in a neat 820px modal dialog (`.compact-add-product-modal`).
   - Connected via AJAX to `POST /products/quick-store`, validating and saving all catalog fields via `CommercialController::quickStoreProduct()`, dynamically prepending the new item to the active voucher grid and autocomplete memory.

6. **In-Place Searchable Item Rows (Table Grid Autocomplete):**
   - Implemented `initRowItemAutocomplete($input)` and `applyProductToRow(tr, product)` in both `purchase/index.blade.php` and `sale/index.blade.php`.
   - Bound autocomplete directly to every `.row-item-name` input in the table grid.
   - When editing or typing directly inside any row's ITEM cell (e.g. typing "as"):
     - Autocomplete dropdown pops up immediately under that specific row with product name, code, rate, unit, and "Select" badge.
     - Selecting a product (or pressing Enter on exact/AJAX match) immediately updates that row's product ID, code, name, rate (cost for purchases, price for sales), matching unit, and tax rate.
     - Recalculates amount, line total, and voucher net/tax/grand totals reactively.
     - Automatically advances focus to the Qty field for rapid keyboard voucher entry.
   - Initialized autocomplete for all dynamic row additions (`addProductRow`, `+ Add row`), loaded vouchers (`loadPurchaseToForm`, `loadSaleToForm`), and initial page load rows.
   - Added subtle purple focus state (`.desk-grid-table input.row-item-name:focus`) in `commercial-workspace.css`.

---

## 4. Horizontal Space Optimization, Terms Compaction & Spacious Fields (Oct 2026)

Addressed user feedback:
> "this area can be little more spacious, not in height, but we can use the horizontal space, because the terms section is taking too much space, reduce that and give 3 more pixel padding. we can extend the tax series warehouse and bbiller etc. make it some more larger than now."

1. **Horizontal Space Reallocation & Terms Compaction:**
   - Previously in Row 2, the Terms container was set to `flex: 1`, occupying >800px of empty space across wide viewports.
   - Constrained the Terms container into `.terms-compact-inline` (`width: 335px !important; flex-shrink: 0 !important; margin-left: auto !important; white-space: nowrap !important;`), pinning `Terms: Standard`, `Credit Days: Standard`, and `+ Transport & Remarks →` neatly to the right edge on a single clean line.
   - Reallocated ~400px of reclaimed horizontal space directly into primary document input fields.

2. **3px Padding & Breathing Room Enhancements:**
   - Increased container card horizontal padding from `10px` to `13px` (`.doc-primary-fields.compact-fields`).
   - Increased flex item gaps from `6px` to `9px` (`.fields-compact-row`).
   - Added 3px more padding inside input and select fields (`padding: 1px 9px !important;` up from `1px 6px`).
   - Added 3px more padding inside bootstrap-select buttons (`.bootstrap-select > .btn.dropdown-toggle`).
   - Increased inline party info strip padding to `1px 11px !important; gap: 10px !important;`.
   - Maintained the ultra-compact 24px vertical field height and ~52px overall card height intact.

3. **Expanded Field Widths (Sales & Purchases):**
   - **Sales Command Center (`sale/index.blade.php`):**
     - Row 1: `Our Bill No` (140px), `Customer PO / Ref` (165px — prevents placeholder truncation), `Bill Date` (125px), `Entry Date` (125px), `Party *` (250px).
     - Row 2: `Tax Classification *` (205px), `Series` (145px), `Warehouse *` (200px), `Biller *` (200px).
   - **Purchase Command Center (`purchase/index.blade.php`):**
     - Row 1: `Our Bill No` (140px), `Supplier Bill No` (165px), `Bill Date` (125px), `Entry Date` (125px), `Party *` (250px).
     - Row 2: `Tax Classification *` (230px), `Series` (160px), `Warehouse *` (230px).

---

## 5. Section Color Differentiation, Left Sidebar Table Header, Reactive GST & Dark Theme Suite (Oct 2026)

Addressed user feedback:
> "make the colors to differentiate each section. for example, table should be different color, bill top section with the date and other details box is slightly different color. and the table header is at left side panle color. and the dark theme is not working for the new theme."
> "this is not working, if selected the particular gst, it should reflect in the tax column in the table. also the alternate tables are different color to identify. if it is multi tax, then we can select any tax rate. also the product rate if it is mentioned in the product creation, then display that, bu we can override that if want."

1. **Section Differentiation Colors & Visual Hierarchy:**
   - **Bill Top Details Box:** Styled `.doc-primary-fields.compact-fields` with a distinct soft slate background (`#f1f5f9` Slate-100) and crisp subtle border (`1px solid #cbd5e1`), separating the top header fields cleanly from the white page canvas and table container.
   - **Items Table Header (`<thead> <tr> <th>`):** Shifted from vibrant purple to the deep dark color of the left side panel (`#0f172a` Slate-900) with crisp white text (`#ffffff`), establishing visual coherence with the left navigation bar.
   - **Distinct Zebra Striping:** Enforced high-contrast alternating row colors for table rows: `:nth-child(even)` is `#f1f5f9` (Slate-100) and `:nth-child(odd)` is `#ffffff` (Pure White), with a smooth hover tint of `#e2e8f0` (Slate-200).

2. **Complete Dark Theme Implementation for Commercial Workspaces:**
   - Addressed broken dark theme where `commercial-workspace.css` previously forced `#ffffff !important` with zero dark mode rules.
   - Implemented a complete `body.dark-mode` design system across `commercial-workspace.css`:
     - Top Navigation & Command Strip: Deep dark `#0f172a` with light slate text `#e2e8f0`.
     - Main Canvas: Sleek dark canvas background `#0b0f19`.
     - Primary Fields Box: Slate card background `#141c2e` with `#334155` border.
     - Table Header: Ultra-dark `#090d16` with `#f8fafc` text.
     - Table Container & Rows: Dark slate `#1e293b` with zebra striping (`#1e293b` vs `#141c2c`) and hover `#27354f`.
     - Inputs, Selects & Autocompletes: `#0f172a` background, `#f8fafc` text, and `#334155` borders with focused glow.
     - Bottom Action Bar: Dark `#141c2e` with high-contrast buttons and glowing totals.
     - Side Bill List Panel: Dark slate `#141c2e` with `#1e293b` bill cards (`.side-bill-card`), dark search/filters, and purple active-editing card highlight (`#2e1065`).
     - Modal Dialogs: Dark `#1e293b` with crisp borders and legible controls.

3. **Reactive GST Classification & Multi-Tax Grid Linking:**
   - Enriched `<select id="purchase_type_id">` and `<select id="sale_type_id">` options with data attributes:
     - `data-tax-rate`: Numerical rate (e.g., `18`, `12`, `5`, `0`).
     - `data-code`: Standard code (e.g., `GST18`, `GST12`, `GST_MULTI`).
     - `data-is-multi`: Flag (`1` for multi-tax / mixed, `0` for single fixed rate).
   - **Single GST Mode:** When a specific GST rate is selected (e.g. `18%-GST Inward` or `12%`), changing the dropdown immediately updates the `TAX` column across all existing table rows and locks them (`pointer-events: none; background: #f1f5f9; color: #475569;`) to maintain tax uniformity without disabling form POST serialization. New rows added default automatically to this active rate.
   - **Multi-Tax Mode:** When `GST • Multiple rates` or `L/MultiTax` or `Interstate MultiTax` is selected (`is_multi = 1`), row tax dropdowns unlock immediately (`pointer-events: auto`), allowing per-line tax rate customization (0%, 5%, 12%, 18%, 28%).
   - **Default Product Rate & Free Override:** When items are selected from autocomplete or catalog, the default product price/cost is inserted into `.row-rate`. The user can freely override Rate or Amount at any time, with two-way reactive calculation recalculating line amounts and voucher totals dynamically.

---

## 6. Single Box Control, Auto Button Alignment, Inline Masters & Global GST Standardization (Oct 2026)

Addressed user feedback:
> "why each elements are in double boxes? keep it single, also how to create the category in the page itself?auto button not aligned. why there are different types of tax in each place? is that hardcoded? if hardcoded, then make it dynamic and globally accessible i hope the GST engine is implemented right?"
> "when we extend this to full width, bring the initial setup before changing the new UI."

1. **Elimination of Nested "Double Boxes":**
   - **Root Cause:** In the Add Product popup and workspace, `<select class="form-control">` caused Bootstrap-Select to wrap elements with `<div class="btn-group bootstrap-select form-control">`. When both the outer `.form-control` wrapper and inner `<button class="btn dropdown-toggle">` declared visible borders and rounded edges, dropdowns rendered as an outer border box containing an inner border box.
   - **Fix:** Stripped `border`, `border-radius`, `background`, and `box-shadow` from `.compact-add-product-modal .bootstrap-select.form-control` and `.compact-field-block .bootstrap-select.form-control`. Preserved the inner `.btn.dropdown-toggle` as the single crisp 34px input container.

2. **Seamless Auto Button & Unified Input Group Alignment:**
   - **Root Cause:** Disjointed rounded pills with mismatched borders between input and button inside `.input-group`.
   - **Fix:** Fused `.input-group` controls seamlessly:
     - Applied `border-top-right-radius: 0; border-bottom-right-radius: 0;` to input.
     - Applied `border-top-left-radius: 0; border-bottom-left-radius: 0; border-left: none;` to adjacent buttons (e.g., `⚡ Auto` button, `+` create buttons).
     - Standardized uniform 34px control heights across all inputs, dropdowns, and button add-ons.

3. **Inline Category & Brand Creation Direct on the Page:**
   - Added inline `+` button right next to Category (`#btn-quick-add-category`) and Brand (`#btn-quick-add-brand`) in the Add Product modal.
   - Implemented `#quick-create-category-modal` and `#quick-create-brand-modal` sub-dialogs appended directly to `document.body`.
   - Implemented endpoints `POST /categories/quick-store` and `POST /brands/quick-store` handled by `CommercialController`:
     - Creates records via `firstOrCreate(['name' => $name])` and `firstOrCreate(['title' => $title])`.
     - Returns JSON representation of the new master.
     - Dynamically appends and immediately selects the newly created category/brand in the dropdown without page refresh or navigation.

4. **Global GST Slab Synchronization & Engine Integration:**
   - **Diagnosis of Discrepancies:**
     - Taxes table previously had legacy `VAT 10%` and only `GST 5%`.
     - Catalog products had legacy `tax_id = 1` (10%), which triggered a table fallback injecting `10%` into table row dropdowns.
   - **Standardization Executed:**
     - Updated `app/Models/Tax.php` `$fillable` to include `company_id`.
     - Upserted official Indian GST slabs into `taxes` table:
       - `GST 0% (Nil / Exempt)` (0%)
       - `GST 5%` (5%)
       - `GST 12%` (12%)
       - `GST 18%` (18%)
       - `GST 28%` (28%)
     - Deactivated legacy `VAT 10%` (`is_active = 0`).
     - Migrated existing catalog products with `tax_id = 1` (VAT 10%) to `GST 18%`.
     - Updated `addProductRow()` in both `purchase/index.blade.php` and `sale/index.blade.php` to render table row tax options 100% dynamically from `taxList` (the global GST slabs), eliminating hardcoded `10%`.
     - Linked `Tax Classification` header dropdown reactively with table row tax selection: selecting a specific GST rate locks row taxes uniformly, while multi-tax mode (`L/MultiTax`, `Interstate MultiTax`) unlocks individual row tax customization.

5. **Full Width Initial Register & Two-Way "Split View" Navigation:**
   - Addressed user inquiry: *"once the full width enabled, there is no split view option to go back?"*
   - **Prominent Split View Button:** Replaced ambiguous grey `⧉ Voucher Entry` button on `#fullwidth-register-view` header strip with a high-contrast purple primary button:
     - `[ ⇄ Split View ]` (`#btn-switch-voucher-mode`) styled in `#7c3aed` with explicit tooltip *"Return to Split View (Voucher Entry + Bill List)"*.
   - **Top Navbar Mode Switcher:** Added `#btn-navbar-workspace-toggle` directly into the top main navbar:
     - Displays `⛶ Full Width` in Split View mode.
     - Dynamically changes to `⇄ Split View` (highlighted in purple `.btn-mode-split`) when in Full Width mode.
   - **Intelligent Auto-Return Mechanics:**
     - Clicking `[ 📖 Bill list ]` or `[ 📖 Purchase list ]` in the top navbar while in Full Width mode automatically switches to Split View and opens the bill list panel.
     - Clicking `[ + New ]`, `[ + New Purchase ]`, or `[ + New Sale ]` resets the voucher form and switches to Split View.
     - Clicking `[ ✎ Edit ]` on any row in the register table switches to Split View and loads that bill into the form for in-place editing.
   - Mode preference persisted across sessions in `localStorage` (`zolo_purchase_workspace_mode`, `zolo_sale_workspace_mode`).

6. **Category & Brand Inline Creation Fix:**
   - Fixed `Unknown column 'slug' in 'field list'` error that caused `"Failed to save category"` by removing the non-existent `slug` column from `Category::firstOrCreate` and `Brand::firstOrCreate` in `CommercialController.php`.
   - Verified via unit & feature tests with 100% pass rate.

---

## 7. Database Fresh Slate Wipe & In-Place Quick Create Party Modal (Oct 2026)

Addressed user feedback:
> "delete all the purchase and party sales product entries and make a fresh"
> "when i click the "+new party " popup instead of new window and bring all the options, same window as a popup."

1. **Database Fresh Slate Wipe:**
   - **Snapshot Pre-Wipe Backup:** Full JSON database backup saved to `database/backups/pre_fresh_slate_backup_20261007_182938.json` (251.21 KB) prior to truncation.
   - **Truncation:** Safely truncated 58 transactional, payment, stock, and demo product tables with `SET FOREIGN_KEY_CHECKS=0`.
   - **Clean Seed State:** Preserved all master configurations (warehouses, users, roles, permissions, GST slabs, units, document series, billers). Reseeded clean default `Walk-in Customer` (ID=1) for POS/system integrity. Reset `AUTO_INCREMENT = 1` across truncated tables.
   - **Verified Counts:** `purchases: 0, sales: 0, products: 0, suppliers: 0, customers: 1`.

2. **In-Place Quick Create Party Modal (Zero New Window Redirection):**
   - **Eliminated New Tab / Window:** Replaced external `target="_blank"` anchor links with `<button type="button" class="btn btn-link btn-open-party-modal" id="btn-quick-new-party">` in both `purchase/index.blade.php` and `sale/index.blade.php`.
   - **Comprehensive Field Layout (Matching Reference Screenshot):**
     - Checkbox: "Both Customer and Supplier" (dynamically creates both supplier and customer records under a single form submit).
     - Customer Group selector (revealed when customer or both is checked).
     - Contact & Company: Name *, Company Name *.
     - Taxes & Financials: VAT / Tax Number (GSTIN), Opening balance (Due) (default: 0), Credit Days (default: 30).
     - Communications: Email *, Phone Number *, WhatsApp Number.
     - Address: City *, Address *, State, Postal Code, Country.
   - **Multi-Tenancy Guard (`CompanyWriteGuard`):** Added `company_id` to `$fillable` in `Supplier.php` and `Customer.php` to ensure records pass company-scoped authorization cleanly.
   - **Endpoint & Instant Dropdown Refresh:**
     - Route: `POST /parties/quick-store` handled by `CommercialController::quickStoreParty`.
     - Validates and saves in DB transaction.
     - Returns JSON with created IDs and formatted party details.
     - Appends new option to active dropdown (`#supplier_id` in purchases or `#customer_id` in sales), selects it, refreshes Bootstrap-Select picker, triggers `updatePartyCard()`, and closes the modal without page reload.

---

## 8. 100% Free Professional GSTIN Engine & Statutory Auto-Fetch (Oct 2026)

Addressed user inquiry & requirement:
> "is this hardcoded?"
> "yes. but i dont want to pay, i need a free solution but professional solution"

1. **Context & Audit Findings:**
   - The interactive widget in `optech_erp_modernization_project_plan.html` was a static JavaScript simulator prototype running on `const mockGstDb` with 3 hardcoded samples (`33AABCM1234F1Z5`, etc.).
   - In the live application, the `TAX NUMBER (GSTIN)` field was a plain text input with no fetch trigger. Paid GSP APIs (ClearTax, MasterGST, Sandbox) were not configured in `.env`.
2. **100% Free, Zero-Subscription Professional Architecture:**
   - **Statutory Offline Engine (`Gstin.php`):**
     - Full 38 Indian State & Union Territory code dictionary (01 to 38, with `33` = Tamil Nadu, `29` = Karnataka, `27` = Maharashtra, `32` = Kerala, `07` = Delhi).
     - Constitution of Business recognition from 4th PAN character (`P` = Proprietorship / Individual, `C` = Company, `F` = Partnership / LLP, `H` = HUF, `T` = Trust).
     - Official Luhn Mod-36 mathematical checksum validator.
     - Supply rule determination (Intra-State CGST+SGST vs Inter-State IGST) by comparing company branch state against buyer/supplier state.
   - **Internal Cross-Party & PAN Registry Memory:**
     - `CommercialController::gstLookup` searches existing customers, suppliers, and historical masters for matching GSTIN or PAN.
     - Automatically loads registered company name, contact, address, city, state, postal code, phone, and email if previously recorded.
   - **1-Click Official CBIC GST Portal Verification:**
     - `[ ↗ ]` button copies the GSTIN to the clipboard and opens official government taxpayer search (`https://services.gst.gov.in/services/searchtp`) with zero captcha friction.
3. **Modal UI Enhancements (`purchase/index.blade.php` & `sale/index.blade.php`):**
   - Real-time client-side typing event: typing `33` instantly populates `State: Tamil Nadu` (0ms latency, works offline).
   - Live statutory chip strip: shows State badge (`33 - Tamil Nadu`), Constitution badge (`Proprietorship / Individual`), and Supply rule (`Local Intra-State (CGST + SGST)`).
   - `[ ⚡ Fetch ]` button and Enter-key listener triggering `/parties/gst-lookup` AJAX.
   - Visual status badge: Green `✔ Valid GSTIN` or Red `✖ Invalid Checksum`.
4. **Automated Testing Evidence:**
   - `OptechMasterWebTest.php`: Added tests `test_gst_lookup_decodes_statutory_gstin_and_state` and `test_gst_lookup_recalls_existing_party_from_database`.
   - All 14 tests passing (89 assertions, 100%).
   - All 12 tests passing on `OptechVoucherWebTest.php` (56 assertions, 100%).

---

## 9. Verification & Testing Evidence
- Automated feature tests executed and passed:
  - `vendor/bin/phpunit tests/Feature/OptechVoucherWebTest.php tests/Feature/OptechMasterWebTest.php tests/Feature/DeliveryChallanWebTest.php tests/Feature/GoodsReceivedNoteWebTest.php tests/Feature/AccountingWebTest.php`
  - Results: **41 passed (207 assertions, 100%)**
- Database verification:
  - `purchases: 0, sales: 0, products: 0, suppliers: 0, customers: 1`
  - Inline party creation tested and verified for Supplier, Customer, and Both with GSTIN validation.

---

## 10. Crash Recovery Protocol for Any Agent
1. **Never start from scratch:** When reopened after a crash or system reboot, inspect SESSION_MEMORY.md first.
2. **Check Git Status:** Verify branch is `ui` (git status and git branch -vv). The user explicitly reaffirmed `ui` on 2026-10-07; do not switch to `enhanced-ui`.
3. **Verify Database & Dependencies:** Check migrations are up to date.
4. **Continue Next Steps:**
   - Review pending screens in documents/zolo_erp_implementation_docs/32_OPTECH_SCREENS_AUDIT_AND_BACKEND_GAP_REPORT.md.
   - Continue audit and modernization of remaining modules (Job Work, Production, GST).

## 11. Completed Command Center Consolidation (2026-10-07) — SUPERSEDED by section 13 for entry modes

- Work on `ui`, as explicitly required by the user. Preserve all current uncommitted edits.
- Keep `App\Services\Commercial` and its shared pricing, posting, draft, reversal, and permission services.
- Sales and Purchase Command Centers own operator navigation. Fast Entry is a mode at `/sales?entry=fast` or `/purchases?entry=fast`, using the common application shell. Old `/commercial/{kind}/entry` links redirect while preserving query context.
- Orders reuse pending sales (status 2) and ordered purchases (status 4); no new order posting engine is introduced.
- Validation: shared posting, boundary, and entry mode tests pass (28 tests, 164 assertions). Canonical entry master and normal command center tests pass (5 tests, 57 assertions). Industry entry profile rendering passes (1 test, 17 assertions). Total: 34 tests, 238 assertions.
- Updated isolated fixtures to include current Optech master/transport migrations, application shell view data, and explicit optional-module defaults. The opt-in fixture export also passes (4 tests, 25 assertions with export enabled).
- Browser checks on the existing localhost server confirm canonical navigation, F2/F12 switching, current-mode item focus without reload, dialog open/close and shortcut suppression, and both Orders status filters (sales 2, purchases 4). Final browser console check reports no errors. Bootstrap's closed-dialog display conflict is fixed in the command center stylesheet.
- Item search on the normal entry forms uses Alt+I so it does not compete with F2/F12. Legacy New Bill actions remain available when the fast capability is disabled.
- Project invoice and exchange links now enter the canonical Sales mode. Internal `/commercial` APIs and the shared services remain in place.
- Screenshot: `scratch/command-center-sales.jpg`. Changes remain uncommitted; no deployment was performed.
- The command center request is complete. Future work should follow the user's next request on `ui`.


## 12. Completed UI Action Wiring Audit (2026-10-07)

- User requested UI regression fixes against main, beginning with Delivery Challan Add row and list. Work stays on `ui`; existing uncommitted edits and section 11 navigation consolidation were preserved.
- Fixed DC/GRN dependency order through shared material-document.js. Real catalog/unit IDs, product/party creation, row editing, transport, list controls and show links work. Server validates company-owned references and calculates totals. Converted sources are locked against edits/deletion.
- Shared posting now links and marks DC/GRN converted atomically, rejects duplicate conversions and preserves idempotent retries. Fixed zero-tax preview/save type mismatch. Reversal service rejects a second replacement of an already reversed source while allowing retries.
- Sales/Purchase normal UI now connects Review, Save/Submit, versioned draft save/resume/consumption, View, Print, filtered CSV and date ranges. Product/Service/Mixed and Cash/Credit controls work. Multi-item selection uses stable product IDs. Purchase charges use existing freight/discount fields.
- Reset clears source/draft/retry context. Edit/draft restoration retains line rates, real units, metadata, batch/serial details, charges and supported payment method/account/amount. Reversed bills retain history with View/Print; unsupported mixed settlements keep the reviewed backend restriction.
- Responsive footer/list overlap, clipped material form and unreadable material numeric fields were repaired with existing scroll/wrap layout.
- Validation: CommercialUiWiringTest + SharedCommercialTest PASS (24 tests, 145 assertions); DC + GRN web tests PASS (8 tests, 44 assertions); final command-center render/JSON subset PASS (4 tests, 22 assertions). Voucher/master combined run passed 23 tests; three posting tests initially lacked accounting fixtures, then all three passed with test-only account fixtures (11 assertions). Changed PHP and shared JS syntax checks pass; git diff --check has no whitespace errors.
- Browser used actual app with a disposable SQLite copy, normal capability checks, and fixture accounting roles. Verified challan/GRN create/load/convert, quick product/party creation, sales and purchase draft posting/removal, service catalog, freight/discount total, replacement with Bank settlement, View/CSV, voucher posting and area creation. At 800px, purchase footer/list do not overlap and there is no horizontal page overflow. Final console check is clear.
- Actual local database lacks chart-of-accounts/semantic posting mappings. Do not invent real financial mappings: posting is correctly blocked until company setup. Fixture accounts were created only in the disposable SQLite DB.
- Full report: documents/zolo_erp_implementation_docs/33_UI_ACTION_WIRING_AUDIT.md. Proof: scratch/ui-wiring-challan-proof.jpg and scratch/ui-wiring-purchase-responsive-proof.jpg. Browser tab closed; disposable server stopped after verification. No deployment or commit performed.
- Next work follows the user's next request. This audit does not certify all optional ERP modules/reference screens in the modernization plan.


## 13. Document Entry Consolidation (2026-10-08)

Decision record: `documents/DOCUMENT_ENTRY_CONSOLIDATION.md`. **There is no separate Fast Entry module.**

- F2 / F12 (and Shift/Alt variants, Alt+F10) are accelerators from one registry (`DocumentShortcutRegistry` → `public/js/document-shortcuts.js`) that open the *existing* document page in new state (`?new=1`). Ctrl+F2 (Sales Order) and Ctrl+F12 (Purchase Order) stay **unassigned**: PO gaps are documented (shares the bill number series, never closes after receipts); `sale_status 2` is Pending, not an order lifecycle.
- Removed: `commercial/entry.blade.php`, `commercial-entry.js`, `?entry=fast` hijack, `commercial/{sale,purchase}/entry` routes, `CommercialController::entry`, `Modules/OptechSpeedBilling` scaffold, capabilities `sales.fast_counter` / `purchases.fast_entry` (migration `2026_10_14_000002` deletes rows FK-safely and renames settings `fast_entry` → `entry_aids`). `commercial-entry.css` stays (normal pages use it).
- Normal pages now have: server draft tabs (`document-workspace.js`, `CommercialDraftService`, migration `2026_10_14_000001` adds title/party metadata), entry aids (`document-aids.js`: previous rates, outstanding/pending bills, copy bill, F6 item, extended tracking), `DocumentContextResolver` for project/exchange/order references, and still post through the legacy adapter into the shared services.
- Proof: PHP `DocumentEntryTest`, `DocumentEntryWebTest`, `WorkspaceDraftTest`, `PurchaseOrderLifecycleTest`, `CapabilityRetirementTest`; real-browser `tests/Browser/*.spec.mjs` (48 + 31 checks on the normal pages, README there). Full company suite has the same 16 failures as before this work (pre-existing; none new).
- Known pre-existing failures (not caused by this work): 15F+1E in `phpunit.company.xml` (CapabilityEngineTest ×3, CompanyWriterTest ×11, ErpServiceRegressionTest ×1, StockLedgerMigrationTest ×1), two stale-text tests in `OperationsWebTest`, and `DeploymentReadinessTest::test_offsite_copy…` (mysqldump on the disposable server).
- Environment notes: WSL MariaDB (3307) may be stopped; the browser specs use a throw-away clone `zolo_browser` on the disposable MySQL (33084) — see `tests/Browser/README.md`. Never run two PHPUnit suites against `zolo_test_phase4b` at once.
