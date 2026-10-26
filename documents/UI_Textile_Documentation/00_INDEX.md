# zoloERP UI Branch — Complete Textile Operations Documentation

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Index for the complete operator/UAT documentation set.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


## Verified source

Remote GitHub branch: `ui`  
Commit: `6aeab06888106127c4dabf058ede975f86e0eeaa`

## Documents

- [Company Creation & Textile Setup](01_COMPANY_AND_TEXTILE_SETUP.md)
- [Textile Master Data](02_MASTER_DATA_FOR_TEXTILE.md)
- [Purchases & Purchase Orders](03_PURCHASES_AND_PURCHASE_ORDERS.md)
- [Supplier GRN](04_SUPPLIER_GRN.md)
- [Sales & Sales Orders](05_SALES_AND_SALES_ORDERS.md)
- [Customer DC / Dispatch](06_CUSTOMER_DC_AND_DISPATCH.md)
- [Sales & Purchase Returns](07_SALES_AND_PURCHASE_RETURNS.md)
- [Textile Job Work](08_TEXTILE_JOB_WORK.md)
- [Accounting & Ledger](09_ACCOUNTING_AND_LEDGER.md)
- [GST & Document Outputs](10_GST_AND_DOCUMENT_OUTPUTS.md)
- [Complete Textile UAT Scenario](11_END_TO_END_TEXTILE_UAT_SCENARIO.md)
- [Input / Output Quick Reference](12_INPUT_OUTPUT_REFERENCE_MATRIX.md)

## Recommended training order

```text
1 Company Setup
2 Master Data
3 Supplier GRN / Purchase
4 Job Work
5 Customer DC / Sales
6 Returns
7 Accounting
8 GST
9 End-to-End UAT
```

## Important terminology

```text
Supplier GRN = supplier goods physically received before Purchase Bill
Customer DC = supported physical dispatch before Sales Invoice
Job Work Material DC/GRN = own material sent to/received from processor
Purchase Return = return against billed Purchase
Sales Return = return against billed Sale
```
