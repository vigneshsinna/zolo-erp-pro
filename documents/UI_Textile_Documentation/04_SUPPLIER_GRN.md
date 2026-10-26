# 04 — Supplier GRN: Receive Now, Bill Later

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Receive supplier goods before the Purchase Bill, post stock/GRNI, return unbilled goods and later settle GRNs into a Purchase.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
