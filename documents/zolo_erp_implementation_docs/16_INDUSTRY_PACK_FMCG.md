# 16 - Industry Pack: FMCG Distribution / Small Manufacturing

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** Implementation specification  
> **Basis:** reviewed `main` branch + `optech_erp_modernization_project_plan.html`  
> **Date:** 2026-10-03

> **Product rule:** zoloERP is a general ERP. Textile, FMCG, timber, solar and other verticals must be configurable capabilities or industry packs around one shared sales, purchase, inventory, accounting, tax, document, security and API foundation.


## Purpose

Configure the common ERP for FMCG businesses with batch, expiry, MRP, pack conversions, schemes and optional route distribution/production.

## Target rules

- FMCG is a profile + capabilities, not a new sales/purchase database.
- FEFO picking works from actual batch balances.
- Expired stock is governed by policy and auditable write-off.
- Case/box/piece conversion uses normalized UOM factors.

## Data model / contracts

Default profile:

```text
core.sales
core.purchases
core.inventory
core.accounting
core.gst
inventory.multi_uom
inventory.batch_expiry
sales.wholesale
communications.whatsapp
sales.route_distribution        optional by subtype
manufacturing.production        manufacturer subtype
```

Master defaults: barcode, brand, HSN, GST, PCS/BOX/CASE, MRP, shelf life, reorder level.

## Implementation sequence

1. Seed FMCG profile and attribute definitions.
2. Add batch/expiry/MRP capture to purchase/stock identity.
3. Add FEFO suggestion and expiry validation.
4. Add scheme/pricing rules such as 10+1 without hiding free stock movement.
5. Enable route/van workflow only when configured.
6. Use Manufacturing BOM for manufacturer subtype.

## Acceptance and verification

- Two batches received with different expiries and FEFO sale selects valid earliest batch.
- PCS/BOX/CASE conversion reconciles base quantity.
- Expired batch write-off posts stock and loss journal.
- Scheme free quantity still reduces stock correctly.
