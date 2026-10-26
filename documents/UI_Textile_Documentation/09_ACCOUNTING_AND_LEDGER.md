# 09 — Accounting, Vouchers, Open Items & Financial Outputs

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Understand automatic document postings and use Contra/Payment/Receipt/Journal vouchers and open-item settlement correctly.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
