# 11 — Complete Textile End-to-End UAT Scenario

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Run one controlled scenario from Company Setup through Purchase, GRN, Job Work, Sales, Returns, payments and accounting reconciliation.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
