# 29 - Existing Optech Module Disposition

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** Implementation specification  
> **Basis:** reviewed `main` branch + `optech_erp_modernization_project_plan.html`  
> **Date:** 2026-10-03

> **Product rule:** zoloERP is a general ERP. Textile, FMCG, timber, solar and other verticals must be configurable capabilities or industry packs around one shared sales, purchase, inventory, accounting, tax, document, security and API foundation.


## Purpose

Prevent the generated Optech module scaffolds from becoming duplicated ERP cores.

## Target rules

- Historical routes/data are preserved until replacements are proven.
- Functional boundaries matter more than folder renaming.
- Cleanup and high-risk core migration should not be mixed in one PR.

## Data model / contracts

| Existing module | Disposition |
|---|---|
| `OptechCompany` | Do not build a textile company system; retire or repurpose only as generic platform context |
| `OptechMaster` | Do not duplicate zoloERP Pro products/parties; retire scaffold |
| `OptechSpeedBilling` | Retired: empty scaffold removed; entry is the normal Sales/Purchase pages over the shared application services |
| `OptechJobWork` | Repurpose as generic Subcontracting/Job Work |
| `OptechAccounting` | Do not create second ledger; retire or make thin UI over core accounting |
| `OptechGST` | Repurpose as IndiaCompliance adapter/module |
| `OptechPrinting` | Repurpose as generic document/print engine |
| `Manufacturing` | Keep and refactor onto shared inventory/accounting services |

## Implementation sequence

1. Search every module alias/route/config/menu reference.
2. Confirm whether any production data or external integration uses each scaffold.
3. Introduce replacement core/capability service.
4. Disable empty scaffold navigation/routes if safe.
5. Retire module only after tests and redirect/compatibility needs are handled.

## Acceptance and verification

- No user-facing empty generated Optech pages remain after cutover.
- No duplicate authoritative table/service exists for shared concepts.
- Module removal does not break route cache or historical document rendering.
