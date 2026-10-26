# zoloERP Sidebar Information Architecture & Profile-Based Navigation Plan

**Project:** zoloERP Pro  
**Purpose:** Redesign the left sidebar so it stays compact, understandable, and scalable across General Trading, Textile, FMCG, Timber, Solar/EPC, and future business profiles.  
**Status:** Implementation plan  
**Principle:** One shared ERP navigation architecture, with profile/capability-based visibility. Do not create separate ERP menus for each industry.

---

## 1. Problem Statement

The current sidebar is functionally rich but too long. The main issues are:

- Too many first-level sections.
- Related features are split into separate menu groups.
- Some modules sit under the wrong owner.
- Settings expose too many low-level items directly in the sidebar.
- Sales contains GST, settings, promotions, logistics, and unrelated functions.
- Product mixes master data with inventory and Job Work.
- Inventory is fragmented across Stock, Transfers, Adjustments, Stock Count, and Inventory Reports.
- Optional modules are visible even when the company profile does not need them.
- Multiple accordion groups can remain expanded, making the sidebar excessively tall.
- Daily users need too much scrolling to reach common transactions.

Target:

> **Stable core navigation + capability/profile-driven visibility + contextual actions inside workspaces.**

---

## 2. Core Navigation Principles

### 2.1 One ERP, not one sidebar per industry

zoloERP remains one shared ERP. Industry profiles control enabled capabilities, fields, presets, labels, default columns, operational modules, reports, shortcuts, and workflows.

Do not create top-level modules called Textile ERP, FMCG ERP, Timber ERP, or Solar ERP.

Shared domains remain:

- Master Data
- Inventory
- Buying
- Selling
- Operations
- Finance
- Compliance
- Reports
- Organization
- Settings

### 2.2 Core vs optional modules

**Core modules:** Dashboard, Products, Parties, Buying, Selling, Inventory, Finance, GST/Compliance, Reports, Organization, Settings.

**Optional modules:** Job Work, Manufacturing, Projects/Sites, Route Distribution, Rental, Scheduling, Serial/Warranty, Batch/Expiry, Dimensional Stock, POS/Retail, Promotions, Courier/Logistics, HRM.

---

## 3. Recommended First-Level Sidebar

```text
Workspace
Dashboard

MASTER DATA
  Products
  Parties
  Commercial Masters

INVENTORY
  Inventory

TRANSACTIONS
  Buying
  Selling

OPERATIONS
  Job Work            [capability]
  Manufacturing       [capability]
  Projects / Sites    [capability]
  Distribution        [capability]
  Rental              [capability]
  Scheduling          [capability]

FINANCE
  Accounting
  Expenses
  Income

COMPLIANCE
  GST & Compliance

REPORTING
  Reports

ORGANIZATION
  People
  HRM                  [capability]

ADMINISTRATION
  Settings
```

Only enabled and permitted modules should be rendered.

---

## 4. Master Data

### Products

```text
Products
  Product List
  Categories
  Brands
  Units
  Print Barcode
```

Move **Stock By Lot, Stock Count, Stock Adjustments and Job Work** out of Product.

Profile-specific fields remain inside Product screens:

- Textile: fabric type, construction, width, GSM, design, colour, roll/lot, rack/godown, MTR/KGS/DOZ/PCS.
- FMCG: MRP, batch, expiry, PCS/BOX/CASE, schemes/free qty.
- Timber: species, grade, dimensions, CFT/CBM, piece identity.
- Solar: serial tracking, warranty, model, capacity, project applicability.

### Parties

```text
Parties
  Customers
  Suppliers
  Agents / Brokers
  Areas / Routes
```

Optional party roles can include Transporters and Job Workers without creating duplicate party engines.

### Commercial Masters

```text
Commercial Masters
  Sale Types
  Purchase Types
  Bill Sundry
  Standard Remarks
  Voucher Series
```

Keep configuration/master records here, not transaction screens.

---

## 5. Inventory

Create one Inventory group:

```text
Inventory
  Stock
  Stock By Lot
  Stock Count
  Stock Adjustments
  Transfers
  Inventory Reports
```

### Stock

Suggested internal views:

```text
Summary
Warehouse
Product
Lot / Batch
Serial
Piece / Dimension
```

Only applicable views appear.

### Stock By Lot

Show when Textile lot tracking or batch/lot identity is enabled. Textile lot reporting must not depend on FMCG expiry capability.

### Stock Count

Physical stock counting across profiles.

### Stock Adjustments

Use for corrections, damage, shrinkage, write-offs and approved adjustments.

### Transfers

```text
Transfers
  Transfer List
  Import Transfer by CSV
```

Transfer is an Inventory function, not a separate major domain.

### Inventory Reports

Long term, reports should be centralized under Reports. A temporary shortcut is acceptable during migration.

---

## 6. Buying / Purchase Command Center

Final structure:

```text
Buying
  Purchase Bills
  Purchase Orders
  Purchase Returns & Debit Notes
  Import Purchases
```

### Purchase Bills

Canonical purchase-entry workspace.

Register:

```text
All | Bills | GRN
```

Actions:

```text
+ New Purchase Bill
+ Receive Now, Bill Later
```

### Supplier GRN

Keep the GRN document but remove its standalone sidebar entry.

Purpose:

```text
Supplier GRN
  ↓
Physical stock receipt
  ↓
GRNI / provisional accounting
  ↓
Purchase Bill
  ↓
Supplier payable / GST settlement
```

The later bill must not receive the stock a second time.

### Purchase Orders

Support ordered, partial, fulfilled and cancelled quantities. GRN or direct purchase receipt must not fulfil the same order quantity twice.

### Purchase Returns & Debit Notes

This must be visible under Buying.

Difference:

```text
GRN Return
= return unbilled goods received through Supplier GRN

Purchase Return
= return goods already purchased/billed
```

Do not create a separate GRN Return sidebar item.

### Import Purchases

Rename `Import Purchase By CSV` to `Import Purchases`.

---

## 7. Selling / Sales Command Center

Final structure:

```text
Selling
  Sales Bills
  Sales Orders
  Sales Returns & Credit Notes
  Quotations
  Dispatch Register
  POS Terminal          [capability]
  Import Sales
```

### Sales Bills

Canonical sales-entry workspace.

Register:

```text
All | Bills | Dispatches
```

Actions:

```text
+ New Sales Bill
+ Dispatch Now, Invoice Later
```

### Sales Orders

Keep a real Sales Order lifecycle separate from Pending Sale:

```text
Order
  ↓
Pending quantity
  ↓
Partial billing
  ↓
Remaining quantity
  ↓
Completed / Cancelled
```

Do not relabel Pending Sale as Sales Order unless the lifecycle is proven.

### Sales Returns & Credit Notes

Rename generic `Returns & Notes` to `Sales Returns & Credit Notes`.

### Quotations

Keep under Selling.

### Dispatch Register

Merge:

```text
Challan List
Customer DC
Delivery List
```

into one:

```text
Dispatch Register
```

Suggested filters:

```text
All
Customer DC
Delivery Challan
On Approval
Sale or Return
Other Than Supply
Billed
Unbilled
Returned
```

Keep internal document subtype distinctions and print formats.

### Customer DC

Keep the document, but not a separate sidebar entry. Require an appropriate purpose such as:

- On Approval
- Sale or Return
- Other Than Supply

Regular sales should use Sales Invoice directly. Final statutory wording must be confirmed in accountant/GST UAT.

### POS Terminal

Capability-gated. Common for retail/FMCG; usually hidden for Solar EPC and pure wholesale profiles unless enabled.

### Import Sales

Rename `Import Sale By CSV` to `Import Sales`.

---

## 8. Job Work / Subcontracting

Job Work is an Operations module, not a Product menu.

Show only when the Job Work capability is enabled.

```text
Job Work
  Job Work Orders
  Material DC
  Job Work GRN
  Pending With Job Workers
  Returns / Rework
  Service Bills
```

### Textile flow

```text
Grey Fabric Purchase
  ↓
Job Work Order
  ↓
Material DC
  ↓
Material Outside
  ↓
Job Work GRN
  ↓
Accepted / Rejected / Loss
  ↓
Service Bill
  ↓
Processed Fabric
```

### Material DC

Company-owned material moves to the job worker. Ownership stays with the company. No sale/revenue is created.

Actions:

```text
Print
Return Unprocessed Material
Reverse
```

### Job Work GRN

Handles:

- Accepted quantity
- Rejected quantity
- Process loss
- Converted output
- Shrinkage
- Rework
- Service-bill linkage

### Returns / Rework

Keep contextual:

```text
Material DC
  → Return Unprocessed Material

Job Work GRN
  → Return / Send For Rework
```

Do not create separate generic sidebar modules called DC Return or GRN Return.

---

## 9. Operations Modules

### Manufacturing

Show only when enabled:

```text
Manufacturing
  BOM / Recipe
  Production
  Production History
```

Use for FMCG production/repacking, Timber conversion, optional Textile in-house production, and optional Solar fabrication.

### Projects / Sites

Primarily Solar/EPC:

```text
Projects / Sites
  Projects
  Site Allocation
  Site Dispatch
  Installation
  Commissioning
  Service History
  Warranty / AMC
```

Quotation remains in Selling; procurement remains in Buying.

### Distribution

Optional FMCG capability:

```text
Distribution
  Routes
  Van Stock
  Route Orders
  Route Collections
```

### Rental

Capability-gated:

```text
Rental
  Rental Orders
  Rental Returns
  Rental Assets
```

### Scheduling

```text
Scheduling
  Booking Calendar
```

Show only when the business needs appointments/reservations/service scheduling.

---

## 10. Sales Support Cleanup

Do not keep Sales Support as a prominent section for every profile.

### Agents / Brokerage

Move master identity to:

```text
Parties
  Agents / Brokers
```

Brokerage reports can live under Reports.

### Gift Cards / Coupons

Show only for retail/POS/promotion-enabled businesses:

```text
Selling
  Sales Tools
    Gift Cards
    Coupons
```

### Courier / Logistics

Place under:

```text
Selling
  Sales Tools
    Courier / Logistics
```

or Operations if it later becomes a larger logistics module.

---

## 11. GST & Compliance

Move GST out of Sales.

```text
GST & Compliance
  GST Review
  GST Returns
```

Expanded:

```text
GST Returns
  GSTR-3B
  ITC-04
  TDS / TCS where applicable
```

Future capabilities may add GSTR-1, reconciliation, E-Invoice or E-Way Bill.

---

## 12. Finance & Ledger

```text
Finance
  Accounting
  Expenses
  Income
```

### Accounting

```text
Accounting
  Voucher Entry
  Chart of Accounts
  Journal Entries
  General Ledger
  Trial Balance
  Profit & Loss
  Balance Sheet
  Cash Flow
  Statement
  Periodic Inventory Close
  Semantic Account Mapping
  Account List (Cash)
  Money Transfer
```

### Expenses

```text
Expenses
  Expense Category
  Expense List
```

### Income

```text
Income
  Income Category
  Income List
```

---

## 13. Organization

```text
Organization
  People
  HRM
```

HRM is capability-gated. People can remain a core organizational area.

---

## 14. Reports

Use one central Reports entry:

```text
Reports
  Sales
  Purchases
  Inventory
  Accounting
  GST
  Job Work
  Manufacturing
  Projects
  Distribution
```

Only enabled categories appear.

### Textile Reports

- Stock By Lot
- Job Work Pending
- Shrinkage
- Previous Rates
- Party Outstanding
- Bale / Transport
- Sales
- Purchases

### FMCG Reports

- Batch Stock
- Expiry
- Near Expiry
- FEFO
- Scheme / Free Quantity
- Route Stock
- Production

### Timber Reports

- Piece Stock
- Dimension Stock
- CFT / CBM
- Offcuts
- Waste
- Conversion

### Solar Reports

- Project Margin
- Site Stock
- Serial Allocation
- Installation
- Warranty
- AMC
- Outstanding

---

## 15. Settings

The current Settings sidebar is too large. Replace dozens of entries with one Settings workspace.

```text
Settings
```

Inside the Settings page:

```text
Company & Organization
  Company
  Branch / Warehouse
  Financial Year

Security & Access
  Roles & Permissions
  Users
  Data Locks

Documents
  Document Series
  Tax & Document Settings
  Printing

Sales & Purchase
  Sales Settings
  Purchase Settings
  Pricing
  Discounts

Inventory
  Stock Settings
  Negative Stock Policy
  UOM
  Batch / Serial

POS & Retail
  POS Settings
  Receipt Printers
  Gift Cards
  Coupons
  Rewards

Communications
  SMS
  Email
  WhatsApp
  Templates
  Notifications

Payments
  Payment Gateway
  Payment Methods

Customization
  Custom Fields
  Tables
  Languages

Business
  Business Profile
  Capabilities

System
  General Settings
  Backup Database
  Mail Settings
  Barcode Settings
```

`Business Profile / Capabilities` belongs inside Settings, not as a major daily operational menu.

---

## 16. Profile-Based Visibility

Sidebar rendering must be based on:

```text
User Permissions
+
Company Capabilities
+
Business Profile
+
Company / Branch Context
```

Profiles provide defaults. Permissions and capability checks remain authoritative.

### General Trading

```text
Workspace
Dashboard
Master Data
Inventory
Buying
Selling
Finance
GST & Compliance
Reports
Organization
Settings
```

Hide optional Operations modules unless explicitly enabled.

### Textile

```text
Workspace
Dashboard
Master Data
Inventory
Buying
Selling

Operations
  Job Work

Finance
GST & Compliance
Reports
Organization
Settings
```

Textile behaviors live inside shared screens:

- MTR / KGS / DOZ / PCS
- Fabric attributes
- Bale No
- Transport / LR
- Previous Rates
- Party Outstanding
- Lot Stock
- Dyeing
- Printing
- Finishing
- Bleaching
- Job Work loss/shrinkage
- Dot-matrix / long print

### FMCG

```text
Workspace
Dashboard
Master Data
Inventory
Buying
Selling

Operations
  Manufacturing
  Distribution    [optional]

Finance
GST & Compliance
Reports
Organization
Settings
```

FMCG Inventory may expose Batch Stock, Expiry Stock and Near Expiry. POS, Gift Cards, Coupons and Distribution are capability-driven.

### Timber

```text
Workspace
Dashboard
Master Data
Inventory
Buying
Selling

Operations
  Manufacturing / Conversion
  Job Work        [optional]

Finance
GST & Compliance
Reports
Organization
Settings
```

Inventory may expose Piece Stock, Dimension Stock, CFT/CBM, Offcuts and Waste.

### Solar / EPC

```text
Workspace
Dashboard
Master Data
Inventory
Buying
Selling

Operations
  Projects / Sites

Finance
GST & Compliance
Reports
Organization
Settings
```

Solar-specific behavior includes Serial Stock, Project Procurement, Site Allocation, Site Dispatch, Installation, Commissioning, Warranty, AMC and Project Margin.

---

## 17. Capability Visibility Matrix

| Feature | General Trading | Textile | FMCG | Timber | Solar |
|---|---:|---:|---:|---:|---:|
| Products | Yes | Yes | Yes | Yes | Yes |
| Parties | Yes | Yes | Yes | Yes | Yes |
| Buying | Yes | Yes | Yes | Yes | Yes |
| Selling | Yes | Yes | Yes | Yes | Yes |
| Inventory | Yes | Yes | Yes | Yes | Yes |
| Stock By Lot | Optional | Yes | Yes | Optional | No |
| Job Work | Optional | Yes | Optional | Optional | No |
| Manufacturing | Optional | Optional | Yes | Yes | Optional |
| Distribution | Optional | No | Optional/Yes | No | No |
| Projects/Sites | Optional | No | No | Optional | Yes |
| Batch/Expiry | Optional | No | Yes | No | No |
| Serial/Warranty | Optional | No | Optional | Optional | Yes |
| Dimensional Stock | Optional | No | No | Yes | No |
| POS | Optional | Optional | Often Yes | Optional | Usually No |
| Gift Cards/Coupons | Optional | Usually No | Retail Optional | Usually No | No |
| Rental | Optional | No | No | No | No |
| Scheduling | Optional | Optional | Optional | Optional | Optional |

Actual capability configuration remains authoritative.

---

## 18. Navigation Rules for GRN, DC and Returns

### Commercial GRN/DC

Keep the documents but remove duplicate standalone menu entries.

#### Buying

```text
Purchase Bills
  All | Bills | GRN
```

Action:

```text
Receive Now, Bill Later
```

#### Selling

```text
Sales Bills
  All | Bills | Dispatches
```

Action:

```text
Dispatch Now, Invoice Later
```

### Job Work GRN/DC

Keep separately under Job Work because ownership remains with the company:

```text
Job Work
  Material DC
  Job Work GRN
```

### Returns

Core menus:

```text
Buying
  Purchase Returns & Debit Notes

Selling
  Sales Returns & Credit Notes
```

Context actions:

```text
Supplier GRN
  → Return Unbilled Goods

Customer DC
  → Return Unbilled Dispatch

Job Work Material DC
  → Return Unprocessed Material

Job Work GRN
  → Return / Send For Rework
```

---

## 19. Sidebar Interaction UX

### One major accordion open at a time

```text
Open Buying
→ collapse Selling / Inventory / Finance

Open Selling
→ collapse Buying
```

Remember the last open section where practical.

### Sidebar Search

Add:

```text
Search menu...
```

Examples:

```text
purchase return
job work
stock lot
trial balance
quotation
settings
```

Search results must respect permissions and capabilities.

### Shortcut hints

Show shortcut hints as actions, not separate modules:

```text
+ New Sales Bill · F2
+ New Purchase Bill · F12
```

Do not create `Fast Sales` or `Fast Purchase` menu concepts.

### Mobile

- Sidebar becomes an overlay/drawer.
- Keep search available.
- Only one major accordion open.
- Avoid horizontal scrolling.
- Keep nesting to a practical maximum of 3 levels.

---

## 20. Current → Target Mapping

| Current | Target |
|---|---|
| Product → Job Work | Operations → Job Work |
| Product → Stock By Lot | Inventory → Stock By Lot |
| Product → Adjustment List | Inventory → Stock Adjustments |
| Product → Stock Count | Inventory → Stock Count |
| Master Registry → Agents & Brokers | Parties → Agents / Brokers |
| Master Registry → Areas | Parties → Areas / Routes |
| Purchase → Bills | Buying → Purchase Bills |
| Missing Purchase Return | Buying → Purchase Returns & Debit Notes |
| Sales → Returns & Notes | Selling → Sales Returns & Credit Notes |
| Sales → GST Review | GST & Compliance → GST Review |
| Sales → GST Returns | GST & Compliance → GST Returns |
| Sales → Tax & Document Settings | Settings → Tax & Document Settings |
| Challan List / Customer DC / Delivery List | Selling → Dispatch Register |
| Gift Card List | Selling → Sales Tools → Gift Cards |
| Coupon List | Selling → Sales Tools → Coupons |
| Courier List | Selling → Sales Tools → Courier / Logistics |
| Manufacturing - BOM & Production | Operations → Manufacturing |
| Booking Calendar | Operations → Scheduling |
| Many Other Settings entries | Settings workspace |

---

## 21. Permission Rules

Menu visibility must require:

```text
Capability enabled
AND
User permission
AND
Valid company context
AND
Valid branch context where required
```

Example:

```text
Job Work visible
only if:
Job Work capability enabled
AND user has job_work.read/manage permission
```

Never rely only on profile name or client-side hiding.

---

## 22. Recommended Implementation Sequence

### Phase 1 — Restructure domains

1. Create the top-level groups:
   - Master Data
   - Inventory
   - Buying
   - Selling
   - Operations
   - Finance
   - Compliance
   - Reports
   - Organization
   - Settings
2. Move existing route links without deleting functionality.
3. Preserve authorization and active-route highlighting.

### Phase 2 — Remove duplicates and fix ownership

1. Remove standalone Supplier GRN sidebar item.
2. Remove standalone Customer DC sidebar item.
3. Merge Challan List, Customer DC and Delivery List into Dispatch Register.
4. Add Purchase Returns & Debit Notes.
5. Rename Sales Returns & Credit Notes.
6. Move GST out of Selling.
7. Move Tax & Document Settings out of Selling.
8. Move Job Work out of Product.
9. Move Stock Count, Adjustments and Stock By Lot into Inventory.

### Phase 3 — Settings workspace

1. Keep one Settings sidebar entry.
2. Build settings-category cards/search.
3. Move low-level setting links into the Settings workspace.
4. Keep Business Profile / Capabilities under Settings.

### Phase 4 — Capability/profile visibility

Implement clean visibility for:
- General Trading
- Textile
- FMCG
- Timber
- Solar/EPC
- optional future profiles

### Phase 5 — Navigation UX

1. One-open-section accordion.
2. Sidebar search.
3. Remembered open section.
4. Responsive mobile drawer.
5. Keyboard accessibility.

---

## 23. Acceptance Criteria

The redesign is complete when:

- General Trading does not see Textile/FMCG/Solar-specific Operations unless enabled.
- Textile clearly sees Job Work and relevant lot/transport behavior.
- FMCG clearly sees Manufacturing/Batch/Expiry when enabled.
- Timber clearly sees dimensional/conversion capabilities when enabled.
- Solar clearly sees Projects/Sites/Serial workflows when enabled.
- Purchase Returns & Debit Notes is visible under Buying.
- Sales Returns & Credit Notes is visible under Selling.
- Supplier GRN is accessible from Purchase Bills without a duplicate sidebar item.
- Customer DC/Dispatch is accessible from Sales without duplicate Challan/DC/Delivery menu entries.
- Job Work DC/GRN remains separate from commercial GRN/DC.
- GST does not sit under Selling.
- Settings does not expose dozens of low-level entries directly.
- Inventory functions are grouped together.
- Reports are centrally discoverable.
- Only authorized routes are shown.
- Company/branch/profile changes refresh the permitted navigation correctly.
- Active-route highlighting remains correct.
- Desktop/tablet/mobile navigation is usable without excessive scrolling.
- Keyboard navigation works.
- Sidebar search returns only authorized destinations.

---

## 24. Final Architecture Rule

The sidebar represents **business domains**, not every controller, table, screen, report or capability as a separate menu item.

Use:

```text
Shared ERP Core
  +
Capability-Based Operations
  +
Profile-Specific Defaults
  +
Permission-Based Visibility
```

Do not use:

```text
Every Feature
  =
Another Sidebar Section
```

This architecture keeps zoloERP simple for General Trading while scaling cleanly to Textile, FMCG, Timber, Solar/EPC and future profiles.
