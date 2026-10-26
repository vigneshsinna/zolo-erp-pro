# 02 — Textile Master Data Before Transactions

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Prepare products, parties, warehouses, units, commercial masters and accounting masters required by Purchase, Sales, DC, GRN and Job Work.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
