# 17 - Industry Pack: Textile Wholesale / Processing

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** Implementation specification  
> **Basis:** reviewed `main` branch + `optech_erp_modernization_project_plan.html`  
> **Date:** 2026-10-03

> **Product rule:** zoloERP is a general ERP. Textile, FMCG, timber, solar and other verticals must be configurable capabilities or industry packs around one shared sales, purchase, inventory, accounting, tax, document, security and API foundation.


## Purpose

Preserve the valuable Optech textile behavior while keeping shared code generic.

## Target rules

- 3-decimal quantities and fast wholesale UX are capabilities/defaults.
- Job work is shared subcontracting with textile process presets.
- Bill-by-bill accounting stays core.
- Dot-matrix printing is a generic print capability.

## Data model / contracts

Default profile:

```text
core.sales / core.purchases / core.inventory / core.accounting / core.gst
inventory.multi_uom
sales.wholesale
operations.job_work
printing.dot_matrix
communications.whatsapp
```

Attribute presets: fabric type, construction, width, GSM, design, color, brand, roll/lot, rack/godown.

## Implementation sequence

1. Seed textile profile/labels/attributes.
2. Configure MTR precision and UQC.
3. Configure textile job-work process types.
4. Configure transport/bale/bundle document extensions.
5. Configure 68-line print profile.
6. Add textile report presets for job-work pending, shrinkage, stock group/lot and previous rates.

## UI / operator behavior

Fast-sales preset should expose previous rates, pending bills, live outstanding, inline party/item creation, transport/LR data, copy-from-previous invoice and A4/dot-matrix outputs without forcing these elements into other profiles.

## Acceptance and verification

- Grey fabric purchase → job-work send/receive → service bill → credit sale → partial receipt → print all reconcile.
- No textile-only field appears on General Trading unless capability/attribute is enabled.
