# 10 — GST, Tax & Document Outputs

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Explain the tax/compliance output of Sales, Purchase, GRN/DC, Returns and Job Work.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


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
