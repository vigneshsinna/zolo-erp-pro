# 06 — Customer DC / Dispatch Now, Invoice Later

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Dispatch stock before invoicing for supported DC purposes, post GDNI, later invoice one/multiple DCs and return unbilled dispatches.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
