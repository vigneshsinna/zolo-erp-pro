# Document entry consolidation

Decision record for removing the standalone "Fast Entry" screens. **F2 and F12 are keyboard accelerators into the normal
`/sales` and `/purchases` pages, not separate modules.** Every other document follows the same rule: a shortcut opens
the existing document page in its new-document state and never owns a second implementation of that document.

## Architecture

```
Sales page /sales ─────────┐
Purchase page /purchases ──┤
Sales / Purchase API ──────┼── Legacy-compatible form payload ── LegacyCommercialCommand (legacy:true adapter)
POS ───────────────────────┘                                          │
                                                       Shared Commercial Application Services
                                                                        │
                                                       stock · accounting · numbering · tax
```

* The normal pages keep posting through their existing form contract. Nothing was rewritten to the JSON payload of the
  retired entry screen.
* `App\Services\Commercial` and the internal `/commercial/{sale|purchase}/…` endpoints (search, party, previous rates,
  clone, drafts, preview, posting, payment, reversal, receipts) remain. Only the entry **UI** routes were removed.
* Removed: `backend/commercial/entry.blade.php`, `public/js/commercial-entry.js`, the `?entry=fast` adapter hijack, the
  `commercial/{sale,purchase}/entry` routes, `CommercialController::entry`, the empty `Modules/OptechSpeedBilling`
  scaffold, and the `sales.fast_counter` / `purchases.fast_entry` capabilities. `public/css/commercial-entry.css` stays
  because the normal pages and the compliance screens still use it.

## Shortcuts (one registry: `App\Services\Platform\DocumentShortcutRegistry` → `public/js/document-shortcuts.js`)

| Key | Opens | Notes |
|---|---|---|
| F2 | `/sales?new=1` | blank bill, focus on the customer |
| Shift+F2 | Sales Returns, new state | the Returns register opens its own "Add return" step |
| Ctrl+F2 | — | **unassigned**: Sales Order has no proven lifecycle (`sale_status = 2` means Pending) |
| Alt+F2 | `/delivery-challans?new=1` | |
| F12 | `/purchases?new=1` | |
| Shift+F12 | Purchase Returns, new state | |
| Ctrl+F12 | — | **unassigned**, see "Purchase Order" below |
| Alt+F12 | `/goods-received-notes?new=1` | |
| Alt+F10 | `/quotations/create` | |
| F6 | new item | form-local only (never global); accounting F4–F9 are untouched |
| ? | shortcut help | never while typing |

Rules: one listener in the authenticated layout; permission-filtered for UX only (the target page authorises on the
server); skipped while a modal/dialog owns the keyboard; POS uses a different layout and is unaffected; F-keys work from
form fields; `preventDefault()` is called for recognised F-keys but **every action also has a visible button** because
browsers/OSes can reserve F12. Pressing F2/F12 on the same module calls `window.zoloDocumentWorkspace.newDocument()`; if
the bill is dirty a dialog offers *Save draft / Discard / Cancel*.

## Normal-page workspace (`public/js/document-workspace.js`, `document-aids.js`)

* **Draft tabs**: each tab owns a complete snapshot of the form (header fields, charges drawer, every line with its
  tracking, payment mode and amount, links, editing state, references). Switching serialises the current form, then
  replaces the whole form from the target snapshot; snapshots are never merged.
* **Server drafts** (`CommercialDraftService`, table `sale_drafts` — a historical name, it stores both kinds): scoped by
  company, branch, financial year, user and kind; optimistic `version` (409 on a stale save); 10 open drafts per
  user + company + kind (branch not part of the quota; never auto-evicted); 2 MB payload cap (rejected whole, never
  truncated); pruned 30 days after the **last edit** (lazy on list + `commercial:prune-drafts` daily). Navigation
  metadata (`title`, `party_id`, `party_name_snapshot`) is stored beside the payload and never drives selection.
* A draft never reserves stock, posts accounting, opens items, takes payment, or consumes a document number. The number
  shown on a bill is a preview; `DocumentNumberService` allocates it inside the posting transaction.
* `?new=1` opens a client-only blank tab (no server row until the first meaningful edit) and then removes only `new=1`
  from the URL. A plain `/sales` or `/purchases` shows the register plus the restored tab strip and never auto-opens a bill.
* Autosave 2 s after the last meaningful change, on tab switch and on *Save draft*; unload uses `fetch(keepalive)` and is
  best-effort only (the UI never claims it saved). A 409 offers *Load latest* or *Keep mine as a new tab*.
* **References** `project_id`, `exchange_return_id`, `purchase_order_id` are references, not trusted state. They are
  validated against the trusted company/branch context when `?new=1` is processed, when a draft is saved, when it is
  loaded and again when posting (`DocumentContextResolver`). An optional stale reference is removed with a notice; a
  required one blocks the draft (and posting with it) until corrected.
* **Entry aids** (config `entry_aids`, replaces the old `fast_entry` list): previous rates, party outstanding and
  pending bills, copy bill, inline party/item, extended stock tracking (variant, existing batch, HSN, weight, freight,
  batch MRP/mfg date, dimensioned pieces, quantity schemes where the capability applies). These are UI assistance
  appropriate to the profile, never a gate on the page.

## Purchase Order (Ctrl+F12) — verified, not enabled

Proven (see `PurchaseOrderLifecycleTest`): the normal form can save `status = 4`; no stock movement, no journal entry,
no open item, no payment (a paid order is rejected); a receipt links through `purchase_order_id` only to an active
order of the same supplier and warehouse; a reversed or foreign order cannot be linked.

Open gaps that keep `documents.purchase_order_shortcut` **off**:

1. Orders draw their number from the purchase **bill** series (there is no `purchase_order` numbering type), so orders
   leave gaps in the bill series.
2. An order has no fulfilled/closed state: it stays status 4 after receipts and can be linked by any number of receipts.
3. The orders list is a status filter on the purchase register; there is no dedicated order edit/convert lifecycle.

Close those, then set `DOCUMENTS_PURCHASE_ORDER_SHORTCUT=true` (or the config default) and add the browser proof.
Sales Order stays unassigned until conversion, fulfilment, numbering, stock and payment behaviour are proven.

## Verification

* PHP: `DocumentEntryTest`, `WorkspaceDraftTest`, `PurchaseOrderLifecycleTest` (fixture company),
  `DocumentEntryWebTest` (real pages against the seeded database), `CapabilityRetirementTest`.
* Browser: `tests/Browser/*.spec.mjs` drive the real `/sales` and `/purchases` pages (see its README).
