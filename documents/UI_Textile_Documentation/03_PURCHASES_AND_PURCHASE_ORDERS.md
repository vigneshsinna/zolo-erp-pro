# 03 — Purchases & Purchase Orders

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Enter a normal Purchase or Purchase Order and understand stock, GST, AP, GRN settlement, payments and Textile-specific outputs.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
