# 07 - Sales, POS and Order-to-Cash

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** Implementation specification  
> **Basis:** reviewed `main` branch + `optech_erp_modernization_project_plan.html`  
> **Date:** 2026-10-03

> **Product rule:** zoloERP is a general ERP. Textile, FMCG, timber, solar and other verticals must be configurable capabilities or industry packs around one shared sales, purchase, inventory, accounting, tax, document, security and API foundation.


## Purpose

Use one sales engine for standard invoice entry, zoloERP Pro POS, fast wholesale billing, route sales and project-linked solar billing.

## Target rules

- Different UI modes create the same core sales document and effects.
- Posted invoice changes are reversed, not hard-deleted.
- Fast billing is a UX capability, not a separate sales database.
- Pricing, credit, tax, stock and accounting validation happen server-side.
- Write endpoints are idempotent.

## Data model / contracts

Recommended lifecycle:

```text
draft → confirmed/posted → partially_paid → paid
                       ↘ cancelled/reversed through controlled reversal
```

Optional chain:

```text
Quotation → Sales Order → Pick/Delivery/Challan → Tax Invoice → Collection
```

## Services and ownership

`SaleApplicationService` owns the atomic posting flow:

```text
resolve company/FY/branch
validate party + credit
price lines
determine tax
reserve document number
validate/reserve stock
write sale + lines
post stock issue
post accounting journal
create AR open item
commit
emit after-commit document events
```

## Implementation sequence

1. Wrap current SaleService in a company-aware application service.
2. Replace timestamp reference generation with DocumentNumberService.
3. Route stock changes through InventoryMovementService.
4. Route accounting through idempotent posting service and create AR open item.
5. Adapt existing POS and API to the same service.
6. Build fast-sales view without duplicating posting code.

## UI / operator behavior

Fast mode should preserve useful Optech behavior:

```text
F2        new sales bill (normal Sales page)
Space     contextual search
Enter     accept/advance
Alt+C     create party inline
Ctrl+B    pending bills
Ctrl+S    previous rates
Alt+Y     party statement
Ctrl+Enter save/confirm
Esc       close popup / restore focus
```

Show current outstanding, overdue, credit limit/available credit and current invoice separately.

## Acceptance and verification

- General trading cash and credit invoices reconcile.
- FMCG batch, textile decimals, timber dimensions and solar serials all post through same sale service.
- Duplicate idempotency key returns original transaction.
- Credit override is permissioned and audited.
- Invoice total = line/tax/charges and matches journal/open item.
