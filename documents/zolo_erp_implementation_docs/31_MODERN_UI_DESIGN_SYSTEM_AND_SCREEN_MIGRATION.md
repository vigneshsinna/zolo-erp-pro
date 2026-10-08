# 31 - Modern zoloERP UI Design System and Screen Migration Rules

> **Update:** wherever this document says Fast Sales / Fast Purchase / Fast Entry, read it as the keyboard-first mode of the *normal* Sales and Purchase pages (F2 / F12 are accelerators, not separate screens). See [DOCUMENT_ENTRY_CONSOLIDATION.md](../DOCUMENT_ENTRY_CONSOLIDATION.md).

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** UI modernization implementation specification  
> **Existing UI foundation:** Blade/Bootstrap + `public/css/zolo-erp-neo.css` loaded by `resources/views/backend/layout/main.blade.php`  
> **Date:** 2026-10-03

## Purpose

Define the visual, interaction and migration rules for modernizing the existing zoloERP Pro-derived UI into zoloERP without sacrificing ERP speed, information density or compatibility.

This is **not** a full frontend-framework rewrite. The current application remains Blade/Bootstrap/jQuery unless a later approved architecture decision changes that. The first objective is consistency and usability, not replacing working server-rendered screens with a new JavaScript stack.

---

# Design doctrine

## Preserve behavior, modernize presentation

Legacy zoloERP Pro and Optech interfaces provide workflow evidence.

Preserve:

- speed;
- useful density;
- familiar business flows;
- keyboard shortcuts;
- field sequence where it materially improves operation;
- reports and balances users depend on.

Modernize:

- hierarchy;
- spacing;
- typography;
- navigation;
- forms;
- tables;
- status language;
- responsive behavior;
- empty/error/loading states;
- accessibility;
- visual consistency.

## Do not optimize only for appearance

A screen that looks good but takes more clicks or hides critical information is not an improvement.

The ERP must remain efficient for counter billing, purchase inward, voucher entry, warehouse work, accounting review and report filtering.

## General ERP first

Core screens use general business terminology. Industry-specific fields and vocabulary appear only through capability, business profile, configurable attributes or vocabulary overrides.

---

# Existing design foundation

The repository already has a global zoloERP stylesheet:

`public/css/zolo-erp-neo.css`

It is loaded from:

`resources/views/backend/layout/main.blade.php`.

Current design tokens include:

- modern font stack;
- slate neutrals;
- primary/success/warning/danger/info roles;
- modern sidebar;
- radius tokens;
- shadow tokens;
- dark-mode variables.

Do not create a second unrelated CSS theme. Extend/refactor the existing design foundation into maintainable shared component styles.

---

# Design tokens

## Color roles

Use semantic roles rather than per-screen arbitrary colors:

```text
background
surface
surface-raised
border
border-subtle
text-primary
text-secondary
text-muted
primary
success
warning
danger
info
sidebar-background
sidebar-surface
sidebar-text
sidebar-active
```

Never communicate business meaning through color alone.

## Typography

Suggested hierarchy using the current design foundation:

```text
Page title       22-26px / 700
Section title    16-18px / 600-700
Body             13-15px / 400-500
Table            12.5-14px / 400-500
Helper text      12-13px
Badge            11-12px / 600
Monetary/code    mono optional where useful
```

Do not use tiny fonts merely to increase density.

## Spacing

Use a consistent rhythm:

```text
4, 8, 12, 16, 20, 24, 32
```

Transaction grids may use compact vertical padding while preserving readability.

## Radius

Use the existing radius token scale consistently. Avoid each module inventing its own shape language.

---

# Density modes

## Standard

For settings, setup and master forms.

## Compact

For tables, ledgers, reports and registries.

## Fast Entry

For F2 Fast Sales, F12 Fast Purchase and Voucher Hub.

Fast Entry can be visually denser, but must retain clear focus, visible totals, sufficient hit targets and predictable keyboard navigation.

---

# Application shell

## Desktop

```text
┌──────────────────────────────────────────────────────────────┐
│ Top bar: context / search / notifications / user            │
├───────────────┬──────────────────────────────────────────────┤
│ Sidebar       │ Page Header                                  │
│               │                                              │
│ Home          │ Page content                                 │
│ Sales         │                                              │
│ Purchases     │                                              │
│ Inventory     │                                              │
│ Accounts      │                                              │
│ People        │                                              │
│ Operations    │                                              │
│ Reports       │                                              │
│ Settings      │                                              │
└───────────────┴──────────────────────────────────────────────┘
```

## Tablet

- sidebar collapses to a drawer;
- primary page action stays visible;
- transaction grid uses horizontal containment only when unavoidable;
- totals remain visible;
- complex dialogs can become full-height sheet/page patterns.

## Mobile

Mobile is not the primary full-ERP workstation, but common approvals, lookups and lightweight actions should remain usable. Avoid desktop-only fixed widths.

---

# Navigation rules

Top level:

```text
Home
Sales
Purchases
Inventory
Accounts
People
Operations
Reports
Settings
```

Rules:

- show only enabled capabilities;
- permission and capability checks are both required;
- Operations contains optional capabilities;
- active location is visually obvious;
- submenu depth should normally not exceed two levels;
- avoid duplicate links to the same business function.

---

# Page anatomy

Every main page should follow a predictable structure:

```text
Breadcrumb (optional)
Page title + concise description
Primary action
Context/status

Filter/search toolbar
Optional summary cards
Main content
Pagination / totals
```

Transaction pages may replace summary cards with a customer/supplier HUD.

---

# Forms

## Field order

Use:

```text
Identity / essential fields
Operational fields
Financial/tax fields
More details
Profile/capability extensions
```

## Labels

Use plain business wording. Accounting terminology can appear in helper text or tooltip where needed.

## More details

Use for low-frequency fields. Do not hide fields required to understand or post the current transaction.

## Inline creation

When creating a customer/product/etc. from a transaction:

- preserve all transaction lines;
- open a focused modal/drawer;
- return the new record to the originating field;
- restore focus;
- do not reload and lose data.

---

# Transaction screens

Transaction screens have five zones:

```text
1. Context/header
2. Party/customer/supplier HUD
3. Line-entry grid
4. Optional details
5. Totals + actions
```

Totals/actions should remain visible when practical. Do not scatter totals across several panels.

---

# Lists and tables

Common behaviors:

- search;
- filter;
- sort;
- pagination;
- status;
- column visibility;
- export when authorized;
- bulk selection only where business-safe;
- responsive fallback;
- empty/loading states.

Avoid putting many brightly colored action buttons on every row. Prefer row click/detail plus a compact action menu.

Financial values align right. Codes/dates/statuses use predictable widths.

---

# Filters

Standard filter order:

```text
Search
Date range
Status
Main entity filter(s)
More filters
```

Actions:

```text
Apply
Reset
```

Report filters should remain when drilling into a record and returning.

---

# Status language

Use stable semantic states such as:

```text
Draft
Posted
Partially Paid
Paid
Pending
Partial
Received
Ordered
Reversed
Closed
Locked
Inactive
```

Do not use different words for the same state across modules.

---

# Dialogs, drawers and sheets

Use dialogs for short focused tasks such as confirmation, inline create, payment, allocation and quick detail.

Use a full page or large responsive drawer for complex workflows.

Avoid deep nested modals, multi-tab modal forms and long internal scroll areas when a full page is more appropriate.

Every dialog should:

- trap focus correctly;
- support Esc when safe;
- restore focus to the opener;
- have clear primary/secondary actions.

---

# Keyboard and focus system

Create one shared focus/shortcut manager for fast modes.

Rules:

- shortcuts are scoped to the active screen;
- do not fire while the user is typing into an unrelated input unless intended;
- show shortcut hints beside actions;
- Esc closes the top-most safe overlay;
- Enter behavior is predictable;
- Ctrl+Enter is the strong save/post accelerator;
- after inline create, return focus to the originating field.

Keyboard behavior must have automated E2E coverage.

---

# Feedback and system states

## Save/post

Show in-progress, success and failure clearly. Separate retryable external-message failures from transaction failures.

Do not show success before the server transaction commits.

## Validation

- field-level message;
- summary for large forms;
- focus first invalid field;
- preserve all entered data.

## Locked period

Prefer a clear business message:

```text
This financial period is locked for posting.
You can view this document, but you cannot post changes.
```

Do not expose a generic technical error.

---

# Empty states

Examples:

```text
No suppliers yet.
Create your first supplier to record purchases.
[Add Supplier]
```

or:

```text
No Job Work records.
Enable Job Work and create an order when you send your material to an outside processor.
```

Do not show blank tables without explanation.

---

# Loading and performance

Use server-rendered content by default where the current architecture supports it.

For interactive lookups:

- debounce search;
- cancel stale requests;
- show loading state;
- return limited result sets;
- preserve keyboard navigation.

Performance budgets from doc 24 remain applicable:

```text
Fast screen warm interactive       <= 1.5 s
Autocomplete p95                   <= 300 ms
20-line invoice post p95           <= 1.0 s excluding external messaging
Common list first page p95         <= 800 ms
Heavy financial summary            <= 3 s or async/export
```

Do not hide slow operations behind animation.

---

# Accessibility baseline

At minimum:

- visible focus;
- logical tab order;
- keyboard-accessible controls;
- form labels associated with inputs;
- semantic button/link usage;
- sufficient contrast;
- status not communicated only by color;
- dialog focus management;
- errors readable by assistive technology;
- responsive zoom without broken layout.

---

# Light/dark mode

The design foundation already contains dark-mode tokens. New components must use tokens rather than hard-coded white/black values.

Dark mode must not block or alter business functionality.

---

# Industry profile UI rules

The shared component should not branch into separate industry screens unless the workflow is genuinely different.

Prefer:

```text
Shared Product Form
+ capability sections
+ profile vocabulary
+ attribute panels
```

instead of separate full product forms per industry.

Likewise, Fast Sales remains one shared sales UI with capability-driven identity extensions.

---

# Migration strategy

Do not redesign the whole frontend at once.

## Stage A — foundation

- tokens;
- app shell;
- navigation;
- common page header;
- forms;
- buttons;
- status badges;
- tables;
- dialogs;
- filter toolbar.

## Stage B — high-traffic core

- Product;
- Customer;
- Supplier;
- Sale;
- Purchase;
- Payment.

## Stage C — fast workspaces

- Fast Sales;
- Fast Purchase;
- Voucher Hub.

## Stage D — operations/reports/settings

- Job Work;
- Accounting;
- Reports;
- Series/Printing;
- Features/Settings.

## Stage E — profile extensions

- FMCG;
- Textile;
- Timber;
- Solar.

---

# Legacy CSS migration

Rules:

- do not add new screen-specific overrides when a shared component can solve the requirement;
- prefer token/component classes;
- isolate unavoidable legacy selectors;
- delete obsolete rules only after regression checks;
- do not use `!important` as the normal new component strategy;
- avoid inline styles in new work;
- keep customer branding separate from structural CSS.

---

# UI code ownership

Recommended pattern within the current stack:

```text
resources/views/backend/components/
resources/views/backend/partials/
resources/views/backend/<domain>/
public/css/zolo-erp-neo.css
public/css/zolo-components.css          optional companion
public/js/zolo/
```

Do not generate a second frontend application just for fast screens.

Optional modules may own optional-operation views, but shared UI components stay in the shared application layer.

---

# Screen migration checklist

For every migrated page:

- [ ] Current zoloERP Pro screen inspected.
- [ ] Relevant Optech sequence inspected where applicable.
- [ ] Functional spec identified.
- [ ] Shared source-of-truth service identified.
- [ ] Common component inventory used.
- [ ] No duplicate business logic in JS.
- [ ] Capability/permission rules identified.
- [ ] Standard/Compact/Fast density selected.
- [ ] Desktop checked.
- [ ] Tablet checked.
- [ ] Keyboard checked.
- [ ] Empty/loading/error states checked.
- [ ] Dark mode checked where globally supported.
- [ ] Accessibility basics checked.
- [ ] Performance measured on realistic data.
- [ ] Existing workflow smoke-tested.

---

# Visual acceptance rule

A page is not accepted merely because it is modern.

Acceptance requires:

```text
Modern visual consistency
+ correct business workflow
+ no loss of useful legacy behavior
+ same authoritative backend effects
+ faster/equal operator effort
+ capability/profile correctness
+ responsive usability
```
