# 27 - Requirement Traceability Matrix

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** Implementation specification  
> **Basis:** reviewed `main` branch + `optech_erp_modernization_project_plan.html`  
> **Date:** 2026-10-03

> **Product rule:** zoloERP is a general ERP. Textile, FMCG, timber, solar and other verticals must be configurable capabilities or industry packs around one shared sales, purchase, inventory, accounting, tax, document, security and API foundation.


## Purpose

Map every useful Optech requirement into shared core or an optional capability so none is lost while removing textile-only architecture.

## Target rules

- Preserve behavior, not necessarily old table/module names.
- Any source requirement not mapped must be explicitly marked deferred or rejected with reason.

## Data model / contracts

| Optech requirement | General ERP destination | Profile-specific behavior |
|---|---|---|
| Company / FY selection | CompanyContext | none |
| Restricted tax settings | Permissions + audit | none |
| Goods + services | Product master | attributes by profile |
| 3-decimal MTR/KG | UOM precision | Textile default |
| City-prefixed search | Party search aliases/city | Textile naming option |
| Auto series | DocumentNumberService | prefix presets |
| 68-line dot matrix | Print profile capability | Textile default |
| Returns | Core reversal engine | none |
| DC/GRN job work | Generic Subcontracting | Textile process labels |
| F12 purchase | New Purchase Bill accelerator on the normal page | Global shortcut registry |
| F2 sales | New Sales Bill accelerator on the normal page | Global shortcut registry |
| Live customer balance | Open-item query | none |
| Xerox bill | Reprint or clone command | familiar label |
| F4/F5/F6/F7 vouchers | Core accounting | shortcut preset |
| Against Reference | Open-item allocation | none |
| Day/Cash Book | Accounting reports | none |
| 12-month matrix | Accounting report | none |
| GSTIN/GSTR | IndiaCompliance | none |
| Stock ageing | Inventory analytics | profile filters |
| Data lock | FY/company lock service | none |
| Negative stock policy | Inventory policy | profile default |
| WhatsApp/SMS | Dispatch service | template defaults |
| Weighted average | Inventory valuation | company choice |
| Multi-branch | Company/branch | none |

## Acceptance and verification

- Traceability table is reviewed during UAT against the original plan.
- Textile UAT confirms Optech-critical behavior is still available.
- General Trading confirms textile-specific concepts are not mandatory.
