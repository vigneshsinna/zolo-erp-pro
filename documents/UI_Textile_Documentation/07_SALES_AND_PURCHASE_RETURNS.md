# 07 — Sales Returns, Purchase Returns & Credit/Debit Notes

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Handle physical quantity returns and financial adjustments against posted Sales/Purchases while preserving source valuation, tax and open-item history.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
