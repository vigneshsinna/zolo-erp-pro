# 12 — Textile Input / Output Quick Reference

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Provide one compact matrix for training and UAT.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


| Section | Main inputs | Main outputs |
|---|---|---|
| Company | Legal name, code, Textile profile/subtype, timezone, FY, currency, state/GST | Company, MAIN branch, FY, warehouse, COA, Textile presets |
| Product | Name/code, type, category, unit, HSN/GST, price/cost, Textile attributes | Active item |
| Supplier | Identity, address, GST/state | Purchase/AP party; Job Worker candidate |
| Customer | Identity, address, GST/state/credit terms | Sales/AR party |
| Purchase Order | Supplier, warehouse, items, qty/cost | Order/open quantities; no stock/accounting |
| Supplier GRN | Supplier, godown, date, stock, qty, cost | Stock receipt + Inventory/GRNI |
| Purchase Bill | Supplier, warehouse, items, cost, tax, payment, source GRNs/orders | Inventory/GIT/expense, GST, AP; GRNI settlement |
| Customer DC | Customer, warehouse, purpose, date, stock, qty/rate | Stock issue + GDNI; no normal revenue/AR |
| Sales Order | Customer, warehouse, ordered lines | Order/open quantities |
| Sales Bill | Customer, warehouse, items, rate, tax, payment, source DCs/orders | Stock issue if direct, revenue, GST, AR, COGS; GDNI settlement |
| GRN Return | Unbilled GRN line/qty/date/reason | Stock issue + GRNI reduction |
| Customer DC Return | Unbilled DC line/qty/date/reason | Stock receipt + GDNI reduction |
| Purchase Return | Posted Purchase source, line, qty/amount, reason | Debit Note, stock/AP/GST adjustment |
| Sales Return | Posted Sale source, line, qty/amount, disposition, reason | Credit Note, stock/AR/GST/COGS adjustment |
| Job Work Order | Job Worker, process, date | Order + external warehouse |
| Material DC | Order, source godown, material lines, transport | Stock transfer to external location |
| Return Unprocessed | Dispatch line, returned qty, warehouse | Same material back |
| Job Work GRN | Accepted/rejected/loss, outputs | Return/output stock, quarantine, loss journal |
| Rework | Job Work GRN + material lines | Rework DC under same order |
| Job Work Service Bill | GRN, service item, charge mode | Purchase/AP/GST; capitalise or expense |
| Receipt Voucher | Date, cash/bank, customer/other, reference mode | Journal + open-item settlement |
| Payment Voucher | Date, cash/bank, supplier/other, reference mode | Journal + open-item settlement |
| Contra | Two cash/bank accounts | Cash/bank transfer |
| Journal | Accounts, debit/credit, description | Balanced manual journal |
| Reports | Filters | TB, GL, P&L, BS, cash flow, ageing, stock/job-work reports |
