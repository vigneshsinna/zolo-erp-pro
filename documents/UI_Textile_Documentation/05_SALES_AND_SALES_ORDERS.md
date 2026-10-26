# 05 — Sales & Sales Orders

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Create Sales Bills and Sales Orders and understand stock, COGS, revenue, GST, AR, payments, Customer DC settlement and Textile fields.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
