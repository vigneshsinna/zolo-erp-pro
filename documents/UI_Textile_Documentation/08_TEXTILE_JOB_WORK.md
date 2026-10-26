# 08 — Textile Job Work: Order, Material DC, Job Work GRN, Rework & Service Bill

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Operate outsourced Textile processing while the company retains ownership of material and tracks external stock, loss, rejected material, conversion output and processing cost.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
