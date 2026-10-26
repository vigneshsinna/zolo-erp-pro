# zoloERP Pro — Complete Textile Operations & Input/Output Manual

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Single combined manual covering Company Setup, masters, Purchase, GRN, Sales, DC, Returns, Job Work, GST and Accounting.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---



---

# Company Creation & Textile Setup


## 1. Navigation

Use the post-login company picker / company management screen.

Relevant routes on the verified branch:

- `GET /select-company`
- `POST /company-picker/companies`
- `GET /settings/company`
- `POST /settings/company`
- Financial-year setup: `/company/financial-years/setup`

## 2. Who can create a company?

A company can be created by an installation administrator. For a company inside a company group, the actor must be allowed to manage that group.

## 3. Company creation inputs

| Input | Required | Example | Rule / meaning |
|---|---:|---|---|
| Legal Name | Yes | `Sri Vignesh Textiles Pvt Ltd` | Maximum 255 characters |
| Code | Yes, or auto-generated | `SVT` | Uppercase letters/numbers/`_`/`-`; unique |
| Industry | Yes | `textile` | Must be one of the configured profiles |
| Business subtype | Yes in practice; defaults if blank | `manufacturing` | Must belong to Textile subtype list |
| Timezone | Yes | `Asia/Kolkata` | Must be a valid timezone |
| Financial Year Start | Yes | `2026-04-01` | Date |
| Financial Year End | Yes | `2027-03-31` | Must be after start |
| Trade Name | No | `Vignesh Textiles` | Display/trading name |
| Company Group | No | `Zolo Group` | Optional grouping |
| State Code | No | `33` | Tamil Nadu example |
| Base Currency | No | `INR` | INR default when available |
| Address | Conditional | `12, Textile Street` | Required when GSTIN is supplied |
| City | No | `Erode` | Company profile |
| PIN Code | No | `638001` | Six digits |
| PAN | No | `ABCDE1234F` | Must match GSTIN PAN section if both supplied |
| GSTIN | No | valid GSTIN | GST setup when configured |
| GST Registration Type | No | `regular` | regular/composition/unregistered |
| Logo | No | PNG/JPG/JPEG | Max 1 MB in company profile |

## 4. Textile subtypes

| Subtype | Intended use | Added capabilities |
|---|---|---|
| `wholesale_distributor` | Wholesale + distribution | Route distribution |
| `wholesale` | Wholesale textile trading | No Job Work/manufacturing by default |
| `distributor` | Distribution | Route distribution |
| `manufacturing` | Textile processing/manufacturing | Job Work + BOM + Production |

**Important:** If the company needs Job Work Material DC / Job Work GRN, choose Textile `manufacturing` or explicitly enable Job Work later.

## 5. What the system creates automatically

When a company is created successfully, zoloERP creates/configures:

1. Legal company.
2. `MAIN` branch named **Main branch**.
3. First open financial year.
4. User/company and branch access memberships.
5. Main warehouse, e.g. `Vignesh Textiles - Main`.
6. Chart of Accounts.
7. Selected industry profile/subtype.
8. Starter masters when empty: customer group, product category, biller, Cash/Bank settlement accounts, plus configured starter sale/purchase types, sundries and document series.
9. Address/PAN/GSTIN/logo when supplied.
10. GST defaults when GST/compliance is configured.
11. Company setup audit event.

## 6. Textile defaults

### Quantity and units

- Quantity scale: **3 decimals**
- Units: `MTR`, `KGS`, `DOZ`, `PCS`
- Default UQC: `MTR`
- Warehouse label: **Godown**

### Textile product attributes

- Fabric Type
- Construction
- Width
- GSM
- Design
- Color
- Brand
- Roll / Lot
- Rack / Godown

### Textile document fields

- Transporter
- LR No
- LR Date
- Bale Count
- Bundle Count

### Textile entry aids

- Previous rates
- Outstanding balance
- Clone invoice
- Inline party creation
- Inline item creation
- Tracking

### Textile reports

Base Textile:
- Stock by Lot
- Previous Rates

Textile manufacturing additionally:
- Job Work Pending
- Job Work Shrinkage
- Stock by Lot
- Previous Rates

## 7. Textile process presets

| Code | Process | Default max loss | Conversion? |
|---|---|---:|---:|
| `BLEACHING` | Bleaching | 0% | No |
| `GREY-DYE` | Dyeing - grey to dyed fabric | 5% | Yes |
| `DYEING` | Dyeing | 4% | No |
| `PRINTING` | Printing | 4% | No |
| `FINISHING` | Finishing | 4% | No |

Used process policy is protected from silent policy changes.

## 8. Company profile validation

If GSTIN is entered:
- Registered address is required.
- PAN must match GSTIN.
- State code follows GSTIN.
- GST registration is maintained for the active branch context.

If Registration Type is `unregistered`, GSTIN must be blank.

## 9. Expected outputs after setup

| Check | Expected output |
|---|---|
| Company picker | New company appears |
| Branch | `MAIN / Main branch` |
| Financial Year | Correct FY and status `open` |
| Warehouse | Main Godown/Warehouse exists |
| Currency | Correct base currency |
| Chart of Accounts | Installed |
| Textile units | MTR/KGS/DOZ/PCS |
| Textile attributes | Available according to subtype |
| Job Work | Available only when enabled |
| GST | Registration/default taxes when configured |
| Audit | Company creation/profile changes recorded |

## 10. Example

```text
Legal Name: Vignesh Textile Processors Pvt Ltd
Trade Name: Vignesh Textiles
Code: VTP
Industry: Textile
Subtype: Manufacturing
State: Tamil Nadu (33)
Currency: INR
Timezone: Asia/Kolkata
FY: 01-Apr-2026 to 31-Mar-2027
GST Type: Regular
```

Expected structure:

```text
Company VTP
  ├── MAIN branch
  ├── Open FY 2026-27
  ├── Main Godown
  ├── Chart of Accounts
  ├── Textile profile
  ├── MTR/KGS/DOZ/PCS
  ├── Job Work capability
  └── GST setup when configured
```

## Source map

- `app/Http/Controllers/CompanySelectorController.php`
- `app/Services/Platform/CompanyDirectoryService.php`
- `app/Services/Platform/CompanyProfileService.php`
- `app/Services/Industry/IndustryCatalog.php`


---

# Textile Master Data


## 1. Recommended sequence

```text
Company / Branch / FY
        ↓
Warehouse / Godown
        ↓
Units
        ↓
Categories / Brands
        ↓
Products
        ↓
Suppliers / Customers / Job Workers
        ↓
Sale/Purchase Types
        ↓
Document Series
        ↓
Agents / Areas / Remarks / Sundries
        ↓
Accounting settlement accounts
```

## 2. Textile stock product inputs

| Input | Example | Notes |
|---|---|---|
| Product Name | `Grey Cotton Fabric 60"` | Active catalog item |
| Product Code | `GREY60` | Searchable identity |
| Type | `standard` | DC/GRN carry stock items |
| Category | `Grey Fabric` | Company-owned |
| Brand | `Mill A` | Optional |
| Base Unit | `MTR` | Common Textile unit |
| Cost | `80.00` | Master/default cost |
| Sale Price | `100.00` | Default/assistive price |
| GST / Tax Category | configured category | Server tax authoritative |
| HSN | valid HSN | As required |
| Active | Yes | Posting requires active product |

Textile attributes include Fabric Type, Construction, Width, GSM, Design, Color, Brand, Roll/Lot and Rack/Godown.

## 3. Service products

Create `service` products for:
- Dyeing Charge
- Bleaching Charge
- Printing Charge
- Finishing Charge
- Other processing/service charges

Job Work Service Bill accepts service items only.

## 4. Parties

### Supplier
Used for Purchase/AP and can be used as Job Worker.

### Customer
Used for Sales/AR, Customer DC, credit control, outstanding, previous-rate history and returns.

## 5. Warehouses/Godowns

Examples:
- Grey Godown
- Finished Fabric Godown
- Rejected / Quarantine Godown

Job Work Orders create a dedicated external job-worker location automatically.

## 6. Commercial masters

As required:
- Sale Types
- Purchase Types
- Bill Sundry
- Standard Remarks
- Document Series
- Agents/Brokers
- Areas/Routes

## 7. Accounting prerequisites

Verify:
- Chart of Accounts
- Cash/Bank settlement accounts
- Semantic posting ledgers
- GST ledgers when GST enabled
- Opening balances reconcile if migrated

## 8. Minimum output checklist

```text
✓ Active supplier
✓ Active customer
✓ Active products
✓ Correct units
✓ Godown
✓ GST/HSN setup
✓ Document series
✓ Cash/Bank account
✓ Process/service items for Job Work
```

## Source map

- `app/Http/Controllers/ProductController.php`
- `app/Services/Industry/IndustryCatalog.php`
- `app/Services/ERP/CompanyWriteGuard.php`
- `app/Services/Commercial/CommercialApplicationService.php`
- `app/Services/JobWork/JobWorkService.php`


---

# Purchases & Purchase Orders


## 1. Purchase Bill inputs

| Input | Required | Example |
|---|---:|---|
| Supplier | Yes | ABC Mills |
| Warehouse/Godown | Yes | Main Godown |
| Business Date | Yes | 2026-10-26 |
| Item | Yes | Grey Fabric |
| Quantity | Yes | 500.000 |
| Purchase Unit | Yes | MTR |
| Net Unit Cost | Yes | 80.00 |

Common optional inputs:
- Purchase Type
- Document Series
- Supplier invoice number/date
- Agent/Area
- Transporter
- LR No/LR Date
- Vehicle Number
- Order No
- Credit Days/Due Date
- Sundries/discount/shipping
- Remarks
- Payment method/account
- Purchase Order reference
- Supplier GRN source(s)
- Job Work Receipt reference
- Job Work charge mode: `capitalise` or `expense`

Per-line optional data can include discount, batch/lot, expiry, manufacturing date, variant, serial/IMEI/stock identity, dimensions and attributes.

## 2. Validation

- Active company-owned supplier/product/unit
- Authorized warehouse
- 1–500 lines
- Positive quantity
- Nonnegative price
- Server tax determination
- Payment cannot exceed total
- Due date cannot precede document date
- Batch manufacture date cannot exceed expiry
- Linked Purchase Order cannot be over-received

## 3. Purchase Order output

A Purchase Order is a commitment. It does **not** receive stock or post the normal Purchase accounting until later receipt/billing.

## 4. Direct Purchase stock output

For stock items:

```text
Purchase Bill
  ↓
Stock receipt into selected Godown
```

Partial receiving can split value between:
- Inventory
- Goods In Transit

Service/digital items do not create stock movement.

## 5. Typical accounting output

```text
Dr Inventory
Dr Goods In Transit
Dr Operating Expense
Dr Input GST
Dr GRNI clearing where source GRN exists
Cr Cash/Bank for immediate settlement
Cr Accounts Payable for unpaid amount
Cr TDS Payable where applicable
```

## 6. Purchase from Supplier GRN

```text
Supplier GRN
  ↓ stock + GRNI
Purchase Bill
  ↓ AP + GST + GRNI settlement
```

**Critical:** the bill does not receive the GRN quantity again.

## 7. Textile example

```text
Supplier: ABC Mills
Godown: Grey Godown
Date: 26-Oct-2026
Product: Grey Cotton Fabric
Qty: 500.000 MTR
Net Unit Cost: ₹80.00
Transporter: XYZ Transport
LR No: LR-889
Credit Days: 30
```

Before GST:

```text
Dr Inventory          ₹40,000
Cr Accounts Payable   ₹40,000
```

## 8. Reversal

Use reviewed reversal with:
- Business date
- Reason

History is preserved; do not hard-delete posted commercial history.

## 9. UAT outputs

```text
✓ Purchase visible
✓ Reference assigned
✓ Stock increases once
✓ GST correct
✓ AP/open item correct
✓ Cash/Bank correct if paid
✓ GRN-backed bill does not double stock
✓ Trial Balance balances
```

## Source map

- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Middleware/CommercialRouteAdapter.php`
- `app/Services/Commercial/LegacyCommercialCommand.php`
- `app/Services/Commercial/CommercialApplicationService.php`
- `app/Services/Commercial/PurchasePostingService.php`


---

# Supplier GRN


## 1. Meaning

Supplier GRN means goods have physically arrived but the commercial Purchase Bill has not yet been posted.

This is not Job Work GRN.

## 2. Required inputs

| Input | Required |
|---|---:|
| Supplier | Yes |
| Warehouse/Godown | Yes |
| GRN Date | Yes |
| Products | Yes, 1–500 |
| Quantity | Yes |
| Cost | Yes |
| Unit | Product unit or supplied valid unit |

Optional:
- GRN No
- Purchase Type
- Agent
- Transporter
- LR No/LR Date
- Order No
- Tax rate
- Sundries
- Remarks
- Batch/Lot
- Variant

## 3. Output on save

### Stock
Selected Godown stock increases immediately.

### Accounting
At actual GRN stock value:

```text
Dr Inventory
Cr Goods Received Not Invoiced (GRNI)
```

### Record output
- GRN number
- Source lines/base quantities
- Stock Movement ID
- Journal Entry ID
- Posted timestamp
- Open quantity
- Settlement history/status

## 4. Example

```text
Supplier: ABC Mills
Grey Fabric: 500.000 MTR
Cost: ₹80
```

Output:

```text
Stock +500 MTR
Inventory +₹40,000
GRNI ₹40,000
AP not yet created by this GRN
```

## 5. Later Purchase Bill

Multiple eligible GRNs may feed one Purchase Bill.

The bill:
- Does not receive stock again
- Clears GRNI for billed allocation
- Creates AP
- Posts GST/input tax
- Handles value variance if needed

## 6. GRN Return

For unbilled quantity only.

Inputs:
- Return date
- Reason
- Quantity/lines

Outputs:
- Stock leaves Godown
- GRNI reduced
- Variance where current cost differs
- Settlement history

Already billed quantity must use Purchase Return/Debit Note.

## 7. UAT checks

```text
✓ GRN increases stock
✓ GRNI balances
✓ Bill does not duplicate stock
✓ Partial billing leaves open quantity
✓ Multi-GRN settlement works
✓ GRN Return reduces stock/accrual
✓ Billed quantity cannot use GRN Return
```

## Source map

- `app/Http/Controllers/GoodsReceivedNoteController.php`
- `app/Http/Controllers/Concerns/ValidatesMaterialDocuments.php`
- `app/Services/Commercial/MaterialDocumentService.php`
- `app/Services/Commercial/PurchasePostingService.php`


---

# Sales & Sales Orders


## 1. Sales Bill inputs

| Input | Required | Example |
|---|---:|---|
| Customer | Yes | XYZ Textiles |
| Warehouse/Godown | Yes | Finished Fabric Godown |
| Business Date | Yes | 2026-10-26 |
| Item | Yes | Dyed Fabric |
| Quantity | Yes | 100.000 |
| Sale Unit | Yes | MTR |
| Net Unit Price | Yes | 120.00 |

Optional:
- Sale Type
- Document Series
- Agent/Area
- Transporter
- LR No/LR Date
- Vehicle
- Bale No
- No. of Bales / Bundle Count
- Station To
- Order No
- Credit Days/Due Date
- Cash Discount
- Order Discount
- Shipping
- Sundries
- Payment
- Quotation/Sales Order/Customer DC source

For Textile, Bale No can default to the final document reference when left blank.

## 2. Textile entry aids

- Previous rates
- Outstanding balance
- Clone invoice
- Inline party/item
- Tracking

## 3. Direct Sales output

### Stock
Selected Godown stock decreases.

### Accounting

Typical:

```text
Dr Cash/Bank
Dr Accounts Receivable
Dr Sales Discount
Dr Cost of Goods Sold

Cr Sales Revenue
Cr Output GST
Cr Shipping Income
Cr Inventory
```

Rounding is posted where required.

## 4. Customer DC-backed Sale

```text
Customer DC
  ↓ stock issue + GDNI
Sales Invoice
  ↓ revenue/GST/AR + GDNI settlement
```

No second issue of DC-backed quantity.

## 5. Sales Order

A Sales Order is a commitment, not an invoice.

It should not create:
- Stock issue
- Revenue
- GST invoice
- AR
- Payment

Later invoice/dispatch fulfils it.

## 6. Example

```text
Customer: XYZ Textiles
Godown: Finished Fabric
Product: Dyed Cotton Fabric
Qty: 100 MTR
Rate: ₹120/MTR
Transporter: Fast Lorry
LR No: FL-501
Credit Days: 30
```

Before GST:

```text
Revenue ₹12,000
Stock -100 MTR
COGS = actual stock cost
AR = unpaid invoice amount
```

## 7. UAT checks

```text
✓ Invoice reference
✓ Bale/transport metadata
✓ Stock decreases once
✓ COGS matches stock
✓ Revenue/GST correct
✓ AR/open item correct
✓ DC-backed invoice does not double issue
✓ Reversal restores expected effects
```

## Source map

- `app/Http/Controllers/SaleController.php`
- `app/Http/Middleware/CommercialRouteAdapter.php`
- `app/Services/Commercial/CommercialApplicationService.php`
- `app/Services/Commercial/SalePostingService.php`
- `routes/orders.php`


---

# Customer DC / Dispatch


## 1. Supported purposes

| Code | Meaning |
|---|---|
| `approval` | On approval / sale or return |
| `quantity_unknown` | Quantity known only at delivery |
| `not_supply` | Not a supply: exhibition, repair, own use |

Use a normal Sales Invoice for a normal sale.

## 2. Required inputs

| Input | Required |
|---|---:|
| Customer | Yes |
| Warehouse/Godown | Yes |
| Challan Date | Yes |
| Purpose | Yes |
| Products | Yes |
| Quantity | Yes |
| Rate | Yes |

Optional:
- Challan No
- Sale Type
- Agent/Area
- Transport
- LR No/LR Date
- Bale No/No. of Bales
- Station
- Unit
- Tax
- Batch/Variant
- Sundries/Remarks

## 3. Output

### Stock
Goods leave selected Godown.

### Accounting

```text
Dr Goods Delivered Not Invoiced (GDNI)
Cr Inventory
```

No Sales revenue/AR yet.

## 4. Later invoice

Eligible DCs can feed a Sales Bill.

The invoice:
- Does not issue stock again
- Posts Revenue
- Posts GST
- Creates AR/payment effects
- Clears GDNI for allocated quantity

## 5. Customer DC Return

For unbilled quantity only.

Output:

```text
Stock returns
GDNI reduced
Variance where applicable
Settlement history recorded
```

Already billed quantity must use Sales Return/Credit Note.

## 6. UAT checks

```text
✓ DC purpose validation
✓ Stock issue
✓ GDNI accrual
✓ Multi-DC invoicing
✓ Partial settlement
✓ Unbilled return only
✓ No double stock issue at invoice
```

## Source map

- `app/Http/Controllers/DeliveryChallanController.php`
- `app/Http/Controllers/Concerns/ValidatesMaterialDocuments.php`
- `app/Services/Commercial/MaterialDocumentService.php`
- `app/Services/Commercial/SalePostingService.php`


---

# Sales & Purchase Returns


## 1. Choose the correct flow

| Situation | Flow |
|---|---|
| Unbilled Supplier GRN goods returned | GRN Return |
| Billed purchased goods returned | Purchase Return / Debit Note |
| Unbilled Customer DC goods returned | Customer DC Return |
| Billed sold goods returned | Sales Return / Credit Note |
| Job Worker returns unprocessed material | Job Work Return Material |
| Processed Job Work sent again | Job Work Rework |

## 2. Inputs

Header:
- Business Date
- Reason, 3–500 chars
- Note Type: `credit` or `debit`
- Adjustment Type:
  - `quantity`
  - `rate_difference`
  - `discount`
  - `tax_correction`
- 1–500 source lines

Per-line:
- Source line ID
- Quantity for quantity return
- Amount for financial-only note
- Disposition
- Warehouse
- Stock Line IDs when exact identities are required

Disposition values:
- restock
- quarantine
- damaged
- expired
- quality_reject

## 3. Source requirements

Return must reference an active, posted, unreversed source Sale/Purchase in the current company/branch.

## 4. Sales Return output

Typical physical return:

```text
Stock received
Dr Sales Returns / reverse Output GST
Dr Inventory
Cr Accounts Receivable
Cr COGS
```

Actual values use source valuation/tax snapshots.

## 5. Purchase Return output

Typical:

```text
Stock issued back
Dr Accounts Payable
Cr Inventory
Cr/reverse Input GST
Variance/expense as required
```

## 6. Financial-only notes

Rate difference, discount and tax corrections can create accounting/tax adjustments without physical stock movement.

## 7. Approval

Configured return policy can require approval based on age/amount.

Status may be `awaiting_approval` before posting.

## 8. UAT checks

```text
✓ Source/date validation
✓ Quantity limits
✓ Identity preservation
✓ Correct stock direction
✓ AR/AP adjustment
✓ GST uses source snapshot
✓ Approval path
✓ Partial/full reconciliation
```

## Source map

- `app/Http/Controllers/ReturnController.php`
- `app/Http/Controllers/ReturnPurchaseController.php`
- `app/Services/Commercial/ReturnService.php`


---

# Textile Job Work


## 1. Capability

Textile `manufacturing` enables Job Work capability by default.

## 2. End-to-end flow

```text
Own Fabric Stock
    ↓
Job Work Order
    ↓
Material DC
    ↓
External Job Worker Location
    ↓
Job Work GRN
    ├── Accepted
    ├── Rejected → Quarantine
    ├── Loss → Damage/Loss
    └── Conversion Output
    ↓
Job Work Service Purchase
```

## 3. Configure Process

Inputs:
- Code
- Name
- Max Loss %
- Conversion true/false
- Quantity Basis
- Optional Output Product
- Optional Service Product
- Optional Service Rate

## 4. Job Work Order inputs

| Input | Required |
|---|---:|
| Job Worker Supplier | Yes |
| Process | Yes |
| Expected Return Date | Optional |
| Notes | Optional |

Outputs:
- Job Work Order No
- Dedicated external warehouse
- Process snapshot

## 5. Material DC / Dispatch

Inputs:
- Job Work Order
- Internal warehouse
- Material lines
- Transport/vehicle/e-way-bill details as needed

Output:

```text
Internal Godown → External Job Worker warehouse
```

Material remains company-owned. No sales/revenue/AR.

## 6. Return unprocessed material

Use Return Material.

Rules:
- Same material returns
- No loss
- No conversion outputs
- No processing Service Bill for that material-return receipt

## 7. Job Work GRN

Inputs:
- Dispatch
- Receiving warehouse unless direct dispatch
- Accepted Qty
- Rejected Qty
- Loss Qty
- Rejected warehouse
- Outputs for conversion

Rules:
- Settled quantity cannot exceed remaining dispatch
- Loss must obey process threshold
- Rejected material requires separate internal quarantine warehouse

## 8. Non-conversion example

```text
Sent       500 MTR
Accepted   480 MTR
Rejected     0 MTR
Loss        20 MTR
```

Output:

```text
480 MTR returns internally
20 MTR process loss
Pending external balance = 0
```

Loss accounting:

```text
Dr Damage / Loss
Cr Inventory
```

## 9. Conversion process

Example:

```text
Grey Fabric → Dyed Fabric
```

Accepted raw material value is transferred into conversion output(s), allocated by cost weight. Loss remains separate.

## 10. Rework

A processed Job Work GRN can create a new rework Material DC under the same Job Work Order and reference the GRN being reworked.

## 11. Service Bill

Inputs:
- Active processed Job Work GRN
- Service item(s) only
- Charge Mode:
  - `capitalise`
  - `expense`

Output:
- Normal Purchase document
- Supplier = Job Worker
- AP/GST through normal Purchase engine
- Receipt linked to Service Purchase

`capitalise` can move processing cost into processed inventory/COGS; `expense` leaves it as operating expense.

## 12. Pending report

Reads actual external warehouse stock ledger and returns:
- Job Work Order
- Job Worker
- Product
- Pending quantity
- Pending value

## 13. Reversal dependency

To reverse Material DC:
- Reverse active receipts first.

To reverse Job Work GRN:
- Reverse rework DC first.
- Reverse linked Service Purchase first.

## 14. UAT checks

```text
✓ Ownership retained externally
✓ Internal→external transfer correct
✓ Pending report matches external stock
✓ Return Material has no processing charge
✓ Accepted/rejected/loss reconcile
✓ Loss threshold enforced
✓ Quarantine used
✓ Conversion output keeps actual material value
✓ Service bill AP/GST once
✓ Capitalise/expense works
```

## Source map

- `routes/operations.php`
- `app/Services/Industry/IndustryCatalog.php`
- `app/Services/JobWork/JobWorkService.php`


---

# Accounting & Ledger


## 1. Automatic accounting

Operational documents already post accounting. Do not manually duplicate their effects.

### Purchase
Can affect Inventory, GIT, GRNI, Expense, Input GST, AP, Cash/Bank and TDS.

### Sale
Can affect AR, Cash/Bank, Revenue, Discount, Output GST, Shipping, Inventory, COGS and GDNI.

### Supplier GRN

```text
Dr Inventory
Cr GRNI
```

### Customer DC

```text
Dr GDNI
Cr Inventory
```

### Job Work Loss

```text
Dr Damage/Loss
Cr Inventory
```

## 2. Manual voucher types

- `contra`
- `payment`
- `receipt`
- `journal`

## 3. Voucher inputs

| Input | Required |
|---|---:|
| Voucher Type | Yes |
| Entry Date | Yes |
| Description | Yes |
| Idempotency Key | Yes |
| Reference Mode | Yes |
| Lines | Yes, 2–100 |
| Account | Each line |
| Debit/Credit | Each line as applicable |
| Party | When AR/AP line needs party |
| Due Date | Optional |
| Cheque No/Date | Optional/conditional |
| Allocations | Against Reference |

Reference modes:
- `new_reference`
- `against_reference`
- `advance`
- `on_account`

## 4. Voucher rules

### Contra
Only cash/bank; requires two distinct settlement accounts.

### Payment
Cash/Bank must be credited.

### Receipt
Cash/Bank must be debited.

### Journal
General balanced posting subject to account permissions.

## 5. Open items

Sales/Purchase create AR/AP open items where applicable.

Against Reference allocation settles existing open items.

## 6. Period control

Supported actions:
- lock
- unlock
- close

## 7. Reports

- Trial Balance
- General Ledger
- Profit & Loss
- Balance Sheet
- Cash Flow
- Day Book
- Cash Book
- Ageing

## 8. Textile reconciliation

```text
GRNI = unbilled Supplier GRN accrual
GDNI = unbilled Customer DC accrual
AR = unpaid Sales open items
AP = unpaid Purchase open items
Job Work pending = external stock ledger balance
Trial Balance Debits = Credits
```

## 9. UAT checks

```text
✓ Automatic journals balanced
✓ AR/AP matches source docs
✓ Cash/Bank matches settlement
✓ GRNI/GDNI reconcile
✓ Returns adjust open items
✓ Job Work loss correct
✓ Trial Balance balances
✓ Reversals preserve audit history
```

## Source map

- `app/Http/Controllers/Accounting/VoucherController.php`
- `app/Services/Accounting/VoucherService.php`
- `app/Services/Accounting/OpenItemService.php`
- `app/Services/Commercial/PurchasePostingService.php`
- `app/Services/Commercial/SalePostingService.php`


---

# GST & Document Outputs


## 1. GST ownership

When GST is enabled, server tax determination is authoritative.

Company GST setup uses:
- Legal/trade name
- Registered address
- GSTIN
- Registration type
- State
- Effective date

## 2. Sales

Outputs can include:
- Tax snapshot
- Output CGST/SGST/IGST/Cess
- GST projection
- Rendered document snapshot

## 3. Purchase

Outputs can include:
- Input CGST/SGST/IGST/Cess
- Reverse-charge liability
- Blocked tax treatment
- GST projection
- TDS 194Q where applicable

## 4. Supplier GRN

Supplier GRN posts physical stock/accrual separately from the later Purchase Bill's tax/AP settlement.

## 5. Customer DC

Customer DC is a physical movement document for supported purposes; it is not the normal Sales Invoice path.

## 6. Returns

Credit/Debit notes use source tax snapshots proportionally rather than silently using unrelated current tax values.

## 7. Job Work

Material DC/GRN are operational documents. Job Work processing charge is billed through a normal Purchase Service Bill.

## 8. UAT checks

```text
✓ Company GST/state correct
✓ Product HSN/tax category
✓ Intra/interstate split
✓ Purchase ITC/RCM
✓ Return tax uses source snapshot
✓ Job Work charge taxed through Purchase
```

## Source map

- `app/Services/Platform/CompanyProfileService.php`
- `app/Services/Tax/TaxDeterminationService.php`
- `app/Services/Tax/GstProjectionService.php`
- `app/Services/Commercial/ReturnService.php`


---

# Complete Textile UAT Scenario


## Stage 1 — Company

Create:

```text
Industry: Textile
Subtype: Manufacturing
FY: 01-Apr-2026 to 31-Mar-2027
Currency: INR
```

Expected:
- MAIN branch
- Open FY
- Main Godown
- COA
- Textile units
- Job Work capability

## Stage 2 — Masters

```text
Supplier: ABC Mills
Job Worker: Color Dyeing Works
Customer: XYZ Textiles

Products:
  Grey Fabric   standard / MTR
  Dyed Fabric   standard / MTR
  Dyeing Charge service

Warehouses:
  Grey Godown
  Finished Godown
  Rejected / Quarantine
```

Configure GREY-DYE:
- Max Loss 5%
- Conversion Yes
- Output Dyed Fabric
- Service Dyeing Charge

## Stage 3 — Supplier GRN

```text
ABC Mills
Grey Fabric
500 MTR
₹80/MTR
```

Expected:

```text
Stock +500
Inventory +₹40,000
GRNI ₹40,000
```

## Stage 4 — Purchase Bill from GRN

Bill the same 500 MTR.

Expected:

```text
No second stock receipt
GRNI cleared
AP created
GST posted
```

## Stage 5 — Job Work Order

```text
Job Worker: Color Dyeing Works
Process: GREY-DYE
```

Expected external location.

## Stage 6 — Material DC

Dispatch 500 MTR from Grey Godown.

Expected:

```text
Grey Godown 0
External Job Worker 500
No revenue/AR
```

## Stage 7 — Job Work GRN

```text
Accepted/converted 480 MTR
Rejected 0
Loss 20 MTR
Output 480 MTR Dyed Fabric
```

Expected:

```text
External pending 0
Finished stock 480
Loss 20
Loss % 4
```

## Stage 8 — Service Bill

```text
480 × ₹15 = ₹7,200
Charge mode: capitalise
```

Expected Purchase Service Bill + AP/GST + costing link.

## Stage 9 — Customer DC

Dispatch 100 MTR on approval.

Expected:

```text
Finished stock -100
GDNI at cost
No revenue/AR
```

## Stage 10 — Sales Invoice from DC

Expected:

```text
No second stock issue
GDNI cleared
Revenue/GST/AR/COGS posted
```

## Stage 11 — Direct Sale

Sell another 50 MTR.

Expected stock -50 and normal Sale effects.

## Stage 12 — Sales Return

Return 10 MTR, restock.

Expected:
- Stock +10
- Credit Note
- AR reduced
- COGS reversed proportionally
- GST reversed from source snapshot

## Stage 13 — Payments

Receive customer payment and pay supplier/job worker through reviewed payment/open-item allocation.

## Stage 14 — Quantity reconciliation

```text
Dyed output       480
Customer DC/Sale -100
Direct Sale       -50
Sales Return      +10
---------------------
Expected stock     340 MTR
```

## Stage 15 — Accounting reconciliation

```text
Trial Balance Debit = Credit
GRNI = 0 for fully billed GRN
GDNI = 0 for fully invoiced DC
AR = unpaid customer balance
AP = unpaid supplier/job-worker balance
External Job Work stock = 0
GST projection matches transactions
```

## Acceptance

```text
✓ No double stock on GRN→Purchase
✓ No double issue on DC→Sale
✓ Job Work pending equals external stock
✓ Loss reconciles
✓ Service-cost treatment correct
✓ Returns preserve source valuation/tax
✓ AR/AP/open items reconcile
✓ GST reconciles
✓ Trial Balance balances
```


---

# Input / Output Quick Reference


| Section | Main inputs | Main outputs |
|---|---|---|
| Company | Legal name, code, Textile profile/subtype, timezone, FY, currency, state/GST | Company, MAIN branch, FY, warehouse, COA, Textile presets |
| Product | Name/code, type, category, unit, HSN/GST, price/cost, Textile attributes | Active item |
| Supplier | Identity, address, GST/state | Purchase/AP party; Job Worker candidate |
| Customer | Identity, address, GST/state/credit terms | Sales/AR party |
| Purchase Order | Supplier, warehouse, items, qty/cost | Order/open quantities; no stock/accounting |
| Supplier GRN | Supplier, godown, date, stock, qty, cost | Stock receipt + Inventory/GRNI |
| Purchase Bill | Supplier, warehouse, items, cost, tax, payment, source GRNs/orders | Inventory/GIT/expense, GST, AP; GRNI settlement |
| Customer DC | Customer, warehouse, purpose, date, stock, qty/rate | Stock issue + GDNI; no normal revenue/AR |
| Sales Order | Customer, warehouse, ordered lines | Order/open quantities |
| Sales Bill | Customer, warehouse, items, rate, tax, payment, source DCs/orders | Stock issue if direct, revenue, GST, AR, COGS; GDNI settlement |
| GRN Return | Unbilled GRN line/qty/date/reason | Stock issue + GRNI reduction |
| Customer DC Return | Unbilled DC line/qty/date/reason | Stock receipt + GDNI reduction |
| Purchase Return | Posted Purchase source, line, qty/amount, reason | Debit Note, stock/AP/GST adjustment |
| Sales Return | Posted Sale source, line, qty/amount, disposition, reason | Credit Note, stock/AR/GST/COGS adjustment |
| Job Work Order | Job Worker, process, date | Order + external warehouse |
| Material DC | Order, source godown, material lines, transport | Stock transfer to external location |
| Return Unprocessed | Dispatch line, returned qty, warehouse | Same material back |
| Job Work GRN | Accepted/rejected/loss, outputs | Return/output stock, quarantine, loss journal |
| Rework | Job Work GRN + material lines | Rework DC under same order |
| Job Work Service Bill | GRN, service item, charge mode | Purchase/AP/GST; capitalise or expense |
| Receipt Voucher | Date, cash/bank, customer/other, reference mode | Journal + open-item settlement |
| Payment Voucher | Date, cash/bank, supplier/other, reference mode | Journal + open-item settlement |
| Contra | Two cash/bank accounts | Cash/bank transfer |
| Journal | Accounts, debit/credit, description | Balanced manual journal |
| Reports | Filters | TB, GL, P&L, BS, cash flow, ageing, stock/job-work reports |
