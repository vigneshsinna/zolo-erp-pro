# 32 - Optech Screen to Modern zoloERP UI Mapping

> **Update:** wherever this document says Fast Sales / Fast Purchase / Fast Entry, read it as the keyboard-first mode of the *normal* Sales and Purchase pages (F2 / F12 are accelerators, not separate screens). See [DOCUMENT_ENTRY_CONSOLIDATION.md](../DOCUMENT_ENTRY_CONSOLIDATION.md).

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** UI modernization implementation specification  
> **Source references:** `optech_screens/*`, `ENGINEERING_REFERENCE.md`, docs 07/08/10/12/15/20/27/29, `documents/ZOLO_ERP_EXECUTION_PLAN.md`  
> **Date:** 2026-10-03

## Purpose

Map the legacy Optech screenshot library into modern zoloERP screens without copying the old visual design.

The `optech_screens/` library contains **1,268 screenshots** across 11 workflow groups. These screenshots are a **functional and operator-workflow reference**. They are not the target visual design.

> **Mandatory rule:** Preserve the workflow, speed, shortcuts, useful information and business meaning. Do not reproduce the legacy Optech visual style.

The new UI must use the zoloERP design system and shared ERP services. Do not preserve old complexity merely because it appears in a reference screenshot. Consolidate, simplify and progressively disclose information while preserving required behavior.

Do not create separate Optech sales, purchase, stock, accounting or master-data engines.

---

# Source precedence

When the sources disagree, use this order:

1. `documents/zolo_erp_implementation_docs/*`
2. `documents/ZOLO_ERP_EXECUTION_PLAN.md`
3. `ENGINEERING_REFERENCE.md`
4. `optech_erp_modernization_project_plan.html`
5. `optech_screens/*` for workflow/field/operator evidence

The screenshots can show useful legacy behavior, but they do not override current architecture.

---

# Mapping method

For each Optech workflow group:

1. Identify the operator goal.
2. Preserve useful information, shortcuts and workflow sequence.
3. Map the workflow to the current shared zoloERP service/module.
4. Remove textile-only assumptions from the core screen.
5. Put optional profile fields behind capabilities/attributes.
6. Use modern reusable components.
7. Provide mouse and keyboard paths.
8. Define desktop and tablet behavior.
9. Validate the screen against General Trading first.
10. Then validate profile-specific variants such as FMCG, Textile, Timber and Solar.

Do not infer unverified business rules from a single screenshot. During implementation, inspect the relevant screenshot sequence and the matching functional spec before coding.

---

# Optech source inventory

| Optech source folder | Screens | Modern zoloERP destination |
|---|---:|---|
| `COMPANY_011` | 10 | Company/FY setup, active context switcher, branch selection |
| `MASTER_07` | 118 | Products, parties, units, categories, brands, attributes and master-data lists |
| `SERIES_04` | 51 | Document Series, print profiles, numbering and print preferences |
| `ENTRY_06` | 44 | Transaction registry, returns/reversals and common entry/list patterns |
| `DC_GRN_05` | 47 | Generic Job Work / Subcontracting dispatch and receipt |
| `MJ_PURCHASE_01` | 246 | Purchase workspace + Fast Purchase |
| `MJ_SALES_02` | 135 | Sales workspace + Fast Sales |
| `VOUCHER_ENTRY_03` | 159 | Voucher Hub: Contra, Payment, Receipt and Journal |
| `ACCOUNTS_08` | 198 | Accounting workspace and financial reports |
| `REPORT_09` | 205 | Reports hub and report filters/drill-down |
| `FEATURES_010` | 55 | Settings, capabilities, permissions, tax/printing/system controls |
| **Total** | **1,268** | |

---

# Global modern-screen rules

## Visual design

Use the current zoloERP design foundation in `public/css/zolo-erp-neo.css`.

Prefer clean surfaces, clear hierarchy, restrained borders, modern spacing, readable typography, consistent status badges, consistent primary/secondary/destructive actions, clear empty/loading/error states, and dark-mode compatibility where supported.

Do not imitate legacy Windows-style panels, dense grey boxes, tiny text, old 3D buttons, arbitrary bright colors, cramped toolbar clutter or fixed desktop-only layouts.

## Information density

Use three density patterns:

- **Standard** — normal CRUD/settings.
- **Compact** — lists, report tables, accounting registers.
- **Fast Entry** — Sales/Purchase/Voucher transaction grids.

A modern ERP must not become slower merely to look spacious.

## Forms

- Common fields first.
- Advanced/profile fields under **More details**.
- Avoid unnecessary nested tabs.
- Avoid internal scrolling where a single responsive page can work.
- Keep totals and primary actions visible on transaction screens.
- Inline create must preserve current form state.
- Validation appears near the field and in an accessible summary for large forms.

## Keyboard support

Fast modes preserve documented behavior:

```text
F2          New Sales Bill (normal Sales page)
F12         New Purchase Bill (normal Purchase page)
F9          Voucher Hub
F4          Contra
F5          Payment
F6          Receipt
F7          Journal
Space       Contextual search
Enter       Accept / move forward
Alt+C       Create party inline
Ctrl+B      Pending bills
Ctrl+S      Previous rates
Alt+Y       Party statement
Ctrl+Enter  Save / confirm
Esc         Close dialog / restore focus
```

Keyboard shortcuts are accelerators, not the only navigation method.

---

# Detailed workflow mapping

## 1. `COMPANY_011` → Company, Branch and Financial Year UX

### Preserve

- explicit company/FY awareness;
- fast switching for authorized users;
- clear legal-entity separation.

### Modern screens

- Company Setup / Onboarding
- Financial Year Setup
- Active Context Switcher
- Company Settings
- Branch / Warehouse Assignment
- User Company/Branch Access

### Target context bar

```text
[ Company: ABC Traders ▼ ] [ Branch: Chennai ▼ ] [ FY: 2026-27 ▼ ]
```

Changing context must be explicit. Do not silently move an in-progress draft to another company.

### Improve

- searchable selectors;
- active-company badge in the header;
- permission-aware options;
- setup guidance when FY is missing;
- clear closed/locked indication;
- no old-company branch/FY leakage.

### Remove from shared UI

- textile-only company assumptions;
- Optech-specific session terminology;
- separate `optech_companies` / `optech_financial_years` authority.

### Authority

`CompanyContext`, `CompanyContextResolver`, existing `fiscal_years`.

### Acceptance

- unauthorized company never appears/selects;
- closed FY is readable but clearly marked;
- missing current FY gives admin setup guidance;
- branch-only users can operate without MAIN access;
- company switch never leaks old branch/FY.

---

## 2. `MASTER_07` → Shared Master Data Workspace

### Modern screens

- Products
- Customers
- Suppliers
- Units / UOM conversions
- Categories
- Brands
- Customer Groups
- Tax/HSN/SAC references where permitted
- Configurable Attributes
- Warehouses
- Party aliases/search terms

### Product form core

```text
Name
Code
Item kind
Category
Brand
Base UOM
Purchase UOM
Sale UOM
Purchase cost
Sale price
Tax / HSN-SAC
Stock tracking
```

Then use **More details** for optional/profile fields.

### Profile variants

**General Trading:** standard stock/pricing.  
**FMCG:** multi-UOM, batch/expiry/MRP when enabled.  
**Textile:** MTR/KG precision, fabric attributes, lot/roll/rack where enabled.  
**Timber:** species/grade, dimensional defaults.  
**Solar:** model/wattage, serial/warranty metadata.

### Preserve

- rapid lookup;
- inline master creation from transactions;
- business-friendly aliases/search;
- required UOM precision.

### Improve

- one shared product/party master;
- progressive disclosure;
- duplicate-warning UX;
- capability-aware fields.

### Remove

- separate textile master tables;
- runtime DDL custom fields;
- industry fields shown to all companies.

---

## 3. `SERIES_04` → Document Series and Print Profiles

### Modern screens

- Document Series
- Print Profiles
- Printer Setup
- Communication Defaults
- Reprint / Clone

### Series edit

```text
Document type
Series code
Prefix
Suffix
Padding
Reset policy
Company/FY/branch scope
Default?
Manual-number permission
```

### Preserve

- configurable sequences;
- separate series where needed;
- fast print workflow;
- 68-line dot-matrix option.

### Improve

- number preview;
- duplicate-safe numbering;
- plain descriptions;
- print preview;
- consistent A4/thermal/dot-matrix configuration.

### Important distinction

```text
Reprint = same document + same number
Clone / “Xerox” = new draft copied from prior document; new number when posted
```

### Authority

`DocumentNumberService`, `DocumentRenderingService`, `CommunicationDispatchService`.

---

## 4. `ENTRY_06` → Transaction Registry / Returns / Reversals

The current zoloERP specs use the Optech ENTRY reference for common transaction entry, registry, return/reversal and document-operation behavior. Do not create a separate “Entry engine”.

### Modern screens

- Transaction Registry
- Sale Return
- Purchase Return
- Credit/Debit Note
- Exchange
- Damage/Expiry adjustment
- Draft/Posted/Reversed document views

### Registry columns

```text
Document No. | Date | Type | Party | Branch | Total | Payment | Status
```

### Improve

- one consistent status language;
- clear posted vs draft vs reversed state;
- explicit reason for high-risk actions;
- quick detail drawer;
- links to stock/accounting/tax effects where authorized.

### Remove

- hard deletion as normal correction;
- separate return logic per industry.

---

## 5. `DC_GRN_05` → Generic Job Work / Subcontracting

### Workflow

```text
Job Work Order
    ↓
Send Material / DC
    ↓
Material at Job Worker
    ↓
Receive / GRN
    ↓
Accepted / Rejected / Loss
    ↓
Service Purchase
```

### Modern screens

- Job Work Orders
- Send Material / Delivery Challan
- Receive Material / GRN
- Job Worker Material Balance
- Job Work Service Bill Link
- Shrinkage/Loss Review

### Preserve

- DC/GRN workflow;
- sent vs received quantity;
- process-specific loss;
- pending material outside;
- textile terminology only in Textile profile.

### Improve

- generic labels by default;
- virtual job-worker stock location;
- clear reconciliation;
- linked service bill without double-counting material;
- shrinkage exception banner.

### Profile vocabulary

```text
General/FMCG: Job Worker / Processor
Textile: Mill / Dyeing / Sizing / Printing
Timber: External Processor / Saw Mill
Solar: Fabricator / Subcontractor
```

---

## 6. `MJ_PURCHASE_01` → Purchase Workspace + Fast Purchase

### Modern screens

- Purchase List
- Purchase Create/Edit
- Purchase Detail
- Fast Purchase (F12)
- Goods Receipt / Partial Receipt where separated
- Supplier Outstanding / Previous Rates

### Fast Purchase target

```text
FAST PURCHASE                                           F12

Supplier [ search................................... ]  Outstanding ₹...
Bill No. [        ]  Date [      ]  Warehouse [      ]

-----------------------------------------------------------------------
Item / Code        Qty   Received   UOM   Rate   Tax   Amount
-----------------------------------------------------------------------
...
-----------------------------------------------------------------------

[ More details: transport / notes / landed cost / reference ]

                                    Subtotal
                                    Tax
                                    Charges
                                    Grand Total

[Save Draft]                            [Ctrl+Enter Save]
```

### Preserve

- F12;
- rapid supplier/item search;
- keyboard line entry;
- prior rates;
- pending supplier context;
- direct print after save where configured.

### Improve

- one grid instead of fragmented dialogs;
- visible ordered vs received quantity;
- status: Ordered / Pending / Partial / Received;
- supplier balance separate from current bill;
- batch/serial/dimension capture only when enabled;
- service items hide stock-only fields.

### Authority

`PurchaseApplicationService` / shared Purchase posting stack.

---

## 7. `MJ_SALES_02` → Sales Workspace + Fast Sales

### Modern screens

- Sales List
- Sales Create/Edit
- Sale Detail
- Fast Sales (F2)
- Payment / Collection
- Pending Bills
- Previous Rates
- Party Statement
- Print / Dispatch

### Fast Sales target

```text
FAST SALES                                                F2

Customer [ search................................. ] [ + ]
Previous ₹...      Current ₹...      Total Due ₹...
Credit Limit ₹...  Available ₹...

-----------------------------------------------------------------------
Item / Code         Qty   UOM   Rate   Discount   Tax   Amount
-----------------------------------------------------------------------
...
-----------------------------------------------------------------------

[ More details: transport / LR / notes / profile fields ]

                                       Subtotal
                                       Tax
                                       Charges
                                       Grand Total

[Save Draft]                           [Ctrl+Enter Save]
```

### Preserve

- F2;
- keyboard entry;
- Space search;
- previous rates;
- pending bills;
- party statement;
- customer outstanding;
- clone/Xerox behavior;
- fast save/print.

### Improve

- separate previous outstanding from current invoice;
- show credit limit/available credit;
- inline availability/identity warnings;
- non-blocking draft autosave;
- clear focus after validation failure;
- full mouse path remains available.

### Authority

`SaleApplicationService` / shared stock/accounting/tax stack.

---

## 8. `VOUCHER_ENTRY_03` → Voucher Hub

### One modern hub

```text
F4 Contra
F5 Payment
F6 Receipt
F7 Journal
F9 Voucher Hub
```

### Target layout

```text
VOUCHER HUB

[Contra] [Payment] [Receipt] [Journal]

Date      [          ]
Account   [ search...]
Party     [ search...]  (when relevant)
Amount    [          ]
Method    [ Cash / Bank / Cheque ... ]
Reference [          ]

Allocation
------------------------------------------------
Invoice       Date       Due        Allocate
------------------------------------------------

Narration [...................................]

[Save Draft]                     [Post Voucher]
```

### Preserve

- familiar F-key flow;
- bill-by-bill allocation;
- Against Reference;
- Advance;
- On Account;
- cheque/reference details;
- narration.

### Improve

- one consistent screen;
- allocation totals always visible;
- prevent over-allocation;
- live remaining amount;
- plain-language helper text;
- documented keyboard focus sequence.

### Authority

`AccountingPostingService` + `OpenItemService`.

---

## 9. `ACCOUNTS_08` → Accounting Workspace

### Navigation

```text
Accounts
├── Voucher Hub
├── Chart of Accounts
├── Receivables
├── Payables
├── Day Book
├── Cash / Bank Book
├── General Ledger
├── Trial Balance
├── Profit & Loss
├── Balance Sheet
└── Period Close
```

### Ledger columns

```text
Date | Voucher | Reference | Narration | Debit | Credit | Balance
```

### Preserve

- Day Book;
- Cash Book;
- General Ledger;
- month-wise views;
- party balances;
- voucher drill-down.

### Improve

- drill-down without losing filters;
- plain-language labels + accounting tooltip;
- sticky totals;
- company/FY scope always visible;
- consistent export controls;
- ageing buckets displayed clearly.

### Authority

One shared accounting ledger only.

---

## 10. `REPORT_09` → Reports Hub

### Categories

```text
Sales
Purchases
Inventory
Accounting
Tax
Operations
Management
```

### Common report shell

```text
Report Title

Date From [ ] To [ ]
Branch [ ]
Party [ ]
Product [ ]
Other relevant filters

[Run] [Reset]                              [Export]

Summary cards
Result table / chart
```

### Preserve

- useful legacy reports;
- stock ageing;
- accounting books;
- detailed filters;
- export/print.

### Improve

- consistent filters;
- no unrelated filters;
- one-line report explanations;
- async/export for heavy reports;
- visible data scope;
- filter persistence through drill-down.

---

## 11. `FEATURES_010` → Settings and Capability Management

### Modern Settings navigation

```text
Settings
├── Company
├── Branches / Warehouses
├── Financial Years
├── Users / Roles
├── Features
├── Numbering
├── Tax
├── Printing
├── Communication
├── Inventory Policy
├── Accounting
├── Integrations
└── Audit / Security
```

### Feature card example

```text
Batch & Expiry
Track which batch each receipt/sale belongs to and prevent expired stock use.

[ Enabled ]

Requires: Inventory
```

### Preserve

- configurable operational behavior;
- restricted administrative settings;
- server/admin-only sensitive controls where applicable.

### Improve

- dependency visibility;
- plain language;
- audited changes;
- disabled features hidden from normal navigation while history stays readable;
- dangerous settings require reason/confirmation.

---

# Shared navigation mapping

Target top level:

```text
Home
Sales
Purchases
Inventory
Accounts
People
Operations
Reports
Settings
```

`Operations` contains Manufacturing, Job Work, Projects, Route Distribution, Repair, etc. only when enabled.

The sidebar must not expose modules merely because their routes exist.

---

# Profile-aware UI rules

## General Trading

Default core UI only. Do not show textile/FMCG/timber/solar fields unless capabilities are enabled.

## FMCG

Add multi-UOM, batch, expiry, MRP, schemes/free quantity and FEFO suggestion where enabled.

## Textile

Add decimal MTR/KG, fabric attributes, lot/roll, job-work vocabulary, transport/bale fields and dot-matrix defaults where enabled.

## Timber

Add species/grade, dimensions, pieces, CFT/CBM and actual identity selection where enabled.

## Solar / EPC

Add project/site, serial allocation, system-kit/BOM context, installation/commissioning and warranty/AMC where enabled.

---

# Required states for every modernized page

A screen is not complete if only the happy path is styled.

Support:

- loading;
- empty;
- populated;
- validation error;
- permission denied;
- capability disabled;
- locked financial period;
- external-service failure where applicable;
- no search results;
- read-only historical document;
- reversed/cancelled document;
- tablet constrained layout.

---

# Implementation order

Do not modernize all 1,268 screenshot equivalents at once.

1. App shell/navigation.
2. Company/FY context.
3. Product/Party masters.
4. Standard list/form components.
5. Fast Sales.
6. Fast Purchase.
7. Voucher Hub.
8. Accounting lists/reports.
9. Document Series / Printing.
10. Job Work DC/GRN.
11. Reports Hub.
12. Settings/Features.
13. Profile-specific UI extensions.

The UI must follow the business-engine phases. Do not build a polished screen on an unfinalized transaction engine and then duplicate logic in JavaScript.

---

# Acceptance checklist per mapped workflow

- [ ] Relevant Optech screenshot sequence reviewed.
- [ ] Relevant zoloERP functional spec reviewed.
- [ ] Modern screen uses shared zoloERP services.
- [ ] No parallel Optech transaction/master table introduced.
- [ ] General Trading variant tested.
- [ ] Capability/profile fields hidden when not enabled.
- [ ] Keyboard and mouse workflows both function.
- [ ] Tablet layout checked.
- [ ] Empty/loading/error/locked states implemented.
- [ ] Permission/capability checks enforced server-side.
- [ ] Focus returns correctly after modal/inline create.
- [ ] Filters/search match other modules.
- [ ] Performance budget checked on realistic data.
- [ ] UAT includes non-technical business users.

---

# Codex instruction

```text
Read:
- documents/zolo_erp_implementation_docs/32_OPTECH_SCREEN_TO_ZOLOERP_UI_MAPPING.md
- the relevant functional spec
- document 20
- document 33

Inspect the complete relevant optech_screens/<folder> sequence before editing.

Treat Optech images as workflow/function references, not visual-design references.
State:
1. legacy behavior observed,
2. behavior to preserve,
3. behavior to simplify/remove,
4. modern screen structure,
5. shared backend service used,
6. capability/profile differences.

Do not modify business posting rules in a UI-only task.
Do not create a separate transaction engine.
```
