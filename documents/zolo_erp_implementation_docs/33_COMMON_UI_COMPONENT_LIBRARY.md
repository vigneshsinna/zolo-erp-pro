# 33 - zoloERP Common UI Component Library

> **Update:** wherever this document says Fast Sales / Fast Purchase / Fast Entry, read it as the keyboard-first mode of the *normal* Sales and Purchase pages (F2 / F12 are accelerators, not separate screens). See [DOCUMENT_ENTRY_CONSOLIDATION.md](../DOCUMENT_ENTRY_CONSOLIDATION.md).

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target stack:** Laravel Blade + Bootstrap foundation + current zoloERP CSS/JS  
> **Status:** Target component contract  
> **Date:** 2026-10-03

## Purpose

Define reusable UI building blocks so zoloERP does not modernize each zoloERP Pro/Optech screen independently.

These are target contracts. They do not imply every component already exists.

A shared component should be used wherever the interaction pattern is the same across Sales, Purchases, Inventory, Accounts, People, Operations, Reports or Settings.

---

# Component principles

1. Business behavior stays in services/controllers; components render and collect input.
2. Components do not calculate authoritative tax, accounting or stock.
3. Components accept semantic data/status, not industry-specific assumptions.
4. Capability/profile extensions use slots/configuration instead of duplicate forms.
5. Interactive components support keyboard and mouse.
6. State/error/loading patterns are consistent.
7. Components use zoloERP design tokens.
8. Server-side authorization remains mandatory.

---

# Application shell components

## `zolo-app-shell`

Responsibility:

- sidebar;
- top bar;
- company/branch/FY context;
- page body.

Contains:

```text
zolo-sidebar
zolo-topbar
zolo-company-context
zolo-user-menu
zolo-flash-region
```

## `zolo-sidebar`

Inputs:

- authorized navigation tree;
- active route;
- capability state;
- permission state.

Rules:

- no direct business queries in the view;
- hide unavailable capability;
- historical-read routes remain only where policy permits.

## `zolo-company-context`

Shows:

```text
Company
Branch
Financial Year
```

States:

- ready;
- no FY;
- closed FY;
- locked period;
- multiple branches require selection.

---

# Page structure components

## `zolo-page-header`

Slots:

```text
title
description
context/status
primary-action
secondary-actions
```

## `zolo-breadcrumbs`

Use only where hierarchy helps. Avoid redundant breadcrumbs on every simple page.

## `zolo-section`

Standard titled content section.

## `zolo-sticky-actions`

Used for long forms/transactions.

Typical contents:

```text
Save Draft
Save/Post
Cancel
optional contextual action
```

---

# Button system

Semantic variants:

```text
primary
secondary
success
warning
danger
ghost
link
```

Rules:

- one dominant primary action per context;
- destructive action never looks like ordinary navigation;
- include loading/disabled states;
- shortcut hint optional.

Example:

```text
[Ctrl+Enter  Save & Post]
```

---

# Status components

## `zolo-status-badge`

Semantic statuses:

```text
draft
posted
pending
partial
received
ordered
paid
partially_paid
overdue
reversed
cancelled
locked
closed
active
inactive
```

Map domain statuses to visual variants in one helper/config, not independently in every Blade file.

---

# Form components

## `zolo-field`

Supports:

- label;
- required;
- helper;
- error;
- prefix/suffix;
- read-only;
- permission-disabled.

## Input variants

```text
zolo-input
zolo-number
zolo-money
zolo-date
zolo-select
zolo-search-select
zolo-textarea
zolo-checkbox
zolo-radio-group
zolo-toggle
```

## `zolo-more-details`

Progressive disclosure region. Do not place essential posting fields inside it.

## `zolo-form-errors`

Summary for large forms. Focuses/links to invalid fields.

---

# Search and lookup components

## `zolo-entity-search`

Generic lookup for:

- product;
- customer;
- supplier;
- account;
- warehouse;
- project;
- job worker.

Contract:

```text
entity_type
query
company context
branch context where applicable
capability context where applicable
display template
selected ID
```

Features:

- debounce;
- keyboard arrows;
- Enter select;
- Esc close;
- loading;
- no results;
- optional inline-create action.

Never trust a returned ID without server-side company validation.

## `zolo-inline-create`

Reusable modal/drawer wrapper.

Must:

- preserve parent form;
- return new entity;
- restore focus;
- surface validation without closing unnecessarily.

---

# Filter components

## `zolo-filter-bar`

Default slots:

```text
search
date-range
status
primary domain filters
more-filters
apply/reset
```

## `zolo-active-filters`

Shows removable filter chips for complex reports/lists.

## `zolo-date-range`

Consistent date range and quick-range behavior.

---

# Data table components

## `zolo-data-table`

Responsibilities:

- header;
- row rendering;
- sorting state;
- pagination;
- responsive wrapper;
- empty state;
- loading state.

Optional:

- column visibility;
- bulk selection;
- export;
- sticky header.

## Alignment rules

```text
Text             left
Code             left/mono
Date             consistent
Quantity         right
Money            right
Percentage       right
Status           compact
Actions          right
```

## `zolo-row-actions`

Compact menu for secondary row operations. Do not put six colored buttons on each row.

---

# Empty, loading and error components

## `zolo-empty-state`

Inputs:

```text
icon optional
title
description
primary action optional
secondary help optional
```

## `zolo-loading`

Use compact spinner for small async regions and skeletons where useful for larger list/detail content.

## `zolo-error-state`

Distinguish:

- validation;
- permission;
- capability disabled;
- locked period;
- external provider failure;
- network/retryable failure;
- unexpected failure.

---

# Dialog and drawer components

## `zolo-confirm-dialog`

For reversal, cancellation, unlock and destructive settings changes. Supports required reason where policy demands it.

## `zolo-quick-dialog`

For payment, inline create, simple allocation and quick detail.

## `zolo-side-drawer`

For substantial quick-view/edit where leaving the current list would create unnecessary friction.

Rules:

- avoid dialog-inside-dialog;
- focus trap;
- Esc;
- restore focus;
- tablet fallback.

---

# Transaction UI components

## `zolo-transaction-header`

Common data:

- document type;
- document number/status;
- date;
- company;
- branch;
- FY;
- warehouse;
- party.

## `zolo-party-hud`

For Sales/Purchases/Vouchers.

Sales example:

```text
Previous Outstanding
Current Invoice
Total Due
Credit Limit
Available Credit
Overdue
```

Purchase example:

```text
Supplier Balance
Current Bill
Pending Receipts
```

Only show values supported by the relevant service.

## `zolo-transaction-grid`

Base columns:

```text
Item
Qty
UOM
Rate/Cost
Discount
Tax
Amount
```

Extension slots:

```text
batch
expiry
serial
dimension
lot/roll
project
received_qty
```

The server recomputes/validates authoritative values. Do not make JavaScript the only tax/price/stock authority.

## `zolo-line-identity-editor`

Capability-driven editor for batch/expiry, serial, dimension and lot/roll identity.

## `zolo-totals-panel`

Standard ordering:

```text
Subtotal
Discount
Tax
Charges
Rounding
Grand Total
Paid
Balance
```

Hide irrelevant rows while preserving ordering.

## `zolo-transaction-actions`

Typical:

```text
Save Draft
Post / Save & Print
Cancel
```

---

# Fast-entry components

## `zolo-fast-workspace`

Used by Fast Sales, Fast Purchase and Voucher Hub.

Contains:

- compact header;
- shortcut legend;
- focus manager;
- transaction grid;
- fixed totals/actions.

## `zolo-shortcut-hint`

Examples:

```text
F2
F12
Ctrl+Enter
Esc
```

Use visible hints but do not require memorization.

## `zolo-command-help`

Optional overlay listing available shortcuts for the active screen.

---

# Accounting components

## `zolo-open-item-allocation`

Used for Against Reference / Advance / On Account.

Table:

```text
Document
Date
Due Date
Original
Open
Allocate
Remaining
```

Must prevent over-allocation.

## `zolo-debit-credit-grid`

Manual journal lines:

```text
Account
Party optional
Narration
Debit
Credit
```

Always show totals and difference. Post stays disabled while difference is non-zero.

## `zolo-ledger-table`

Columns:

```text
Date
Voucher
Reference
Narration
Debit
Credit
Running Balance
```

## `zolo-aging-summary`

Buckets come from the accounting service, not independent view calculations.

---

# Inventory components

## `zolo-stock-badge`

Shows contextual stock without confusing branch stock with the legacy aggregate stock field.

## `zolo-availability-panel`

May show:

```text
On Hand
Reserved
Available
Batch/Serial/Dimension identities
```

## `zolo-stock-identity-picker`

Used only when selecting actual stock identity is required. Do not render for ordinary quantity-only products.

---

# Job Work components

## `zolo-job-work-progress`

Stages:

```text
Order
Sent
At Processor
Received
Service Bill
Complete
```

## `zolo-quantity-reconciliation`

Displays:

```text
Sent
Received
Rejected
Loss
Pending
Shrinkage %
```

Highlights policy threshold breach.

---

# Report components

## `zolo-report-shell`

Contains report title/help, filter bar, run/reset, summary, result and export.

## `zolo-summary-card`

Use sparingly for important totals such as Sales, Gross Profit, Receivable or Stock Value.

## `zolo-report-drilldown`

Preserves parent filters and a clear return path.

---

# Settings components

## `zolo-setting-card`

Contains setting title, plain-language description, current state, dependencies and action.

## `zolo-capability-toggle`

Shows:

```text
Feature name
One-line business explanation
Enabled/Disabled
Dependencies
Historical-data note
```

Server enforcement remains mandatory.

## `zolo-danger-zone`

For rare high-risk administration. Requires explicit wording, permission, reason and audit where applicable.

---

# Notification components

## `zolo-toast`

Use for non-blocking confirmation. Do not use a toast as the only evidence of a serious posting failure.

## `zolo-banner`

For locked FY, capability setup required, provider outage or important administrative warning.

---

# Print and dispatch components

## `zolo-print-action`

Options based on profile/settings:

```text
A4
Thermal
Dot Matrix
```

## `zolo-dispatch-action`

Channels:

```text
Email
WhatsApp
SMS
```

Dispatch state stays separate from posting state.

---

# Profile extension contract

Shared forms expose named extension regions.

Example Product form:

```text
core
pricing
tax
inventory
profile_attributes
more_details
```

Example Sale line:

```text
core_line
stock_identity
profile_line_fields
```

A profile may add fields, labels and defaults through these regions. It should not fork the whole form unless the workflow is genuinely different and approved.

---

# Suggested Blade organization

Illustrative target:

```text
resources/views/backend/components/
  app-shell.blade.php
  page-header.blade.php
  status-badge.blade.php
  filter-bar.blade.php
  data-table.blade.php
  empty-state.blade.php
  field.blade.php
  more-details.blade.php
  sticky-actions.blade.php
  confirm-dialog.blade.php

resources/views/backend/components/transaction/
  header.blade.php
  party-hud.blade.php
  grid.blade.php
  totals.blade.php
  actions.blade.php

resources/views/backend/components/accounting/
  allocation.blade.php
  debit-credit-grid.blade.php
  ledger-table.blade.php
```

Exact Blade components vs partials should follow the existing Laravel codebase rather than introducing a framework for naming's sake.

---

# JavaScript organization

Shared behavior should move out of giant page-specific scripts where practical.

Illustrative:

```text
public/js/zolo/
  focus-manager.js
  entity-search.js
  inline-create.js
  transaction-grid.js
  filters.js
  dialogs.js
```

Rules:

- progressive enhancement;
- server remains authoritative;
- no duplicated tax/pricing/accounting rules;
- namespace events;
- clean up listeners on dynamic screens;
- avoid global variables where practical.

---

# Component test requirements

## Rendering/unit

- correct state class;
- correct error;
- capability field absent/present;
- status mapping.

## Feature

- authorization remains server-side;
- cross-company ID rejected;
- locked period blocked server-side;
- invalid entity ID rejected.

## E2E

- keyboard navigation;
- inline create returns focus;
- dialog focus/escape;
- fast sale/purchase without mouse;
- tablet layout;
- filter/drill-down retention.

## Visual regression

Capture representative light, dark, empty, populated, validation-error, locked/read-only, desktop and tablet states.

---

# Definition of done for a common component

- [ ] Semantic responsibility is clear.
- [ ] No domain posting logic lives inside.
- [ ] Company/profile data is passed safely.
- [ ] Keyboard path exists.
- [ ] Loading/empty/error states exist where relevant.
- [ ] Responsive behavior is defined.
- [ ] Design tokens are used.
- [ ] Reuse is demonstrated or clearly imminent.
- [ ] Tests cover important interaction.
- [ ] Legacy duplicate pattern can be retired after migration.

---

# Anti-patterns

Do not create one-off files such as:

```text
sales-special-button.css
purchase-special-button.css
textile-modal-v2.css
another_table_filter.js
```

when a shared component should solve the pattern.

Do not:

- put pricing/tax logic only in table JS;
- query company-unsafe endpoints from shared components;
- hide unauthorized actions only with CSS;
- use a modal for a complete multi-step workflow;
- create separate full product/sale components per industry by default;
- expose every optional field merely because the database contains it.

---

# Codex instruction

Before creating a new UI component:

```text
Search the existing Blade/CSS/JS for the same interaction.
Reuse or refactor first.
Read docs 20, 31, 32 and 33.
Do not modify posting/business logic unless the work package explicitly includes it.
Do not create an industry-specific shared component when capability slots can solve it.
```
