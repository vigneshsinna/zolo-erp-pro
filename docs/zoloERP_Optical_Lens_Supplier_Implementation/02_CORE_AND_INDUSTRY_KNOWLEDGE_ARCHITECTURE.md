# 02 — Common and industry-specific Business Knowledge Layer

## Outcome

Expand the current 43-definition, purchasing-oriented registry into a reusable, reviewed knowledge layer. First production scope is multi-store optical lens supply. Other industries inherit the common ERP modules; their specific modules remain opt-in and unsupported until implemented. Do not build 90 agents or parallel registries.

## 1. Knowledge versus execution

| Layer | Authority |
|---|---|
| Entity/relationship knowledge | Describes a lens, product, branch, dealer, order, invoice and valid links; references real model/services |
| Process knowledge | Enumerates legitimate optional paths and required evidence; cannot invent events |
| Policy knowledge | Points to executable code or approved company policy; does not implement tax/stock arithmetic |
| Action knowledge | Points to existing `ActionCapabilityRegistry` keys and requirements; not a grant |
| Live facts | Read-only, scoped, projected from current authorized data; never trusted from model output |
| Company settings | Select applicable reviewed pack and operating policy; cannot loosen system security or tax obligations |
| Execution | Existing `ActionPreparationService`, proposal/approval/executor, authoritative domain services |

## 2. Registry extension

Retain `KnowledgeDefinition`, `KnowledgeDefinitionValidator`, `BusinessKnowledgeRegistry`, `BusinessKnowledgeContextReader`, `ScopedBusinessFactReader` and existing `source_manifest.json`. Existing schema 1 expects one definitions file. Introduce a versioned, additive loader only after tests prove existing definitions still resolve and hashes are reproducible. Proposed resources:

```text
resources/ai/knowledge/
  definitions.json            # existing legacy catalog, no second authority
  source_manifest.json
  core/{entities,relationships,actions,policies,processes}/
  industries/opticals/{entities,relationships,actions,policies,processes}/
  profiles/{general_trading,opticals_lens_supplier,opticals_rx_lab}.json
  governance/{schema-v2,review-manifest}.json
```

Use one explicit build/compile pipeline. Never discover arbitrary PHP methods or accept arbitrary file paths from request/model. Canonical sorted serialization, schema/manifest integrity, unique stable IDs, source refs allowlisted in repository, strict version and effective dates, owner and named review record, dependencies acyclic, action refs present, process predecessor types valid, no duplicate aliases within channel. A definition can be `draft`, `reviewed`, `deprecated` or `withdrawn`; only reviewed/effective/in-scope definitions can drive a new workflow. Keep old versions for audit. No automatic promotion of draft data. Distinguish `unsupported_action` from reviewed supported step. Bind workflow snapshot to knowledge version hash + profile/policy revisions; stale or withdrawn rules pause and require new plan/approval.

Proposed v2 metadata (design only):

```json
{
  "id": "process.opticals.direct_stock_sale.v1",
  "kind": "process",
  "status": "draft",
  "owner": "optical-commercial",
  "profiles": ["opticals"],
  "subtypes": ["wholesale_distributor", "lens_manufacturing"],
  "requires_capabilities": ["sales_invoice.create.v1"],
  "source_refs": ["app/Services/Commercial/CommercialApplicationService.php"],
  "reviewed_by": null,
  "reviewed_at": null,
  "steps": [
    {"id":"resolve_lens_and_customer","kind":"read_or_clarify","depends_on":[]},
    {"id":"invoice","kind":"reviewed_action","action_key":"sales_invoice.create.v1","depends_on":["resolve_lens_and_customer"]}
  ]
}
```

This schema requires an actual compiler/validator upgrade; do not paste it into current schema-1 registry as-is.

## 3. Profile resolution and tenancy

Use `IndustryProfileService::settings(CompanyContext)` and `IndustryCatalog` for `opticals` and existing subtype. Do not use global general-settings or product-name heuristic to choose AI authority. Existing `currentProfileKey` has legacy fallback: reviewed AI context must resolve explicit authorized company; fail closed when missing, not cross-company inference. Existing company settings hold one subtype; if multi-role configuration is necessary, add explicit reviewed branch role overlay using additive storage, scope/index, permission and revision. Do not change profile preset in the background. Profile does not grant permissions. Role examples: central wholesaler, distribution store, manufacturing lab, retail counter (optional). Legal entity/GST registration, warehouse and FY remain independent dimensions.

Precedence: law/authoritative code + security > reviewed domain capability > stricter approved company policy > branch policy > user-supplied choices. No policy can bypass higher constraints. Disable unsupported modules at discovery and call time. Return only relevant definitions, minimal facts and evidence to the model. Cache immutable compiled knowledge by hash; cache scoped projections only with company/branch/FY/actor/permissions/profile version and safe expiry; re-read live sources at approval/execution. Invalidate on permission, profile and price changes. No global cache reuse across companies.

## 4. Common ERP module catalog (90 logical domains)

Status: proposed coverage inventory, not 90 implemented tools. P0 = first supplier pilot, P1 = next enabled profile, P2 = deferred unless required. For every module create a traceability row with entity, relationships, facts, actions/read permission, source, exception and tests. A knowledge-only module may point to existing services without a new command.

| ID / module | Required knowledge and source strategy | Priority |
|---|---|---|
| BK01 Company | Identity, tax and enabled capabilities; existing CompanyContext | P0 |
| BK02 Branch | Authorized business locations and registration | P0 |
| BK03 Fiscal year | Posting date, locked period and document ownership | P0 |
| BK04 Supplier | Active supplier, defaults, supplier aliases | P0 |
| BK05 Customer | Dealer, retail, credit party, bill-to/ship-to | P0 |
| BK06 Product | Stock vs service, active status, master identity | P0 |
| BK07 Variants | Exact attributes, SKU, stock identity | P0 |
| BK08 Warehouse | Receiving, dispatch, transit, branch rights | P0 |
| BK09 UOM | Base, purchase/sale unit, explicit factors | P0 |
| BK10 Pricing | Price lists, previous rates, discount, dealer terms | P0 |
| BK11 Tax profiles | Effective registration and item classification | P0 |
| BK12 Requisition | Reorder request, optional approval | P1 |
| BK13 Supplier quotation | Optional quote, vendor comparison | P1 |
| BK14 Purchase order | Commitment, remaining qty, amendment | P0 |
| BK15 GRN | Real receipt, shortages, rejection | P0 |
| BK16 Direct purchase | No PO/quote required; receipt policy | P0 |
| BK17 Purchase bill | Direct/PO/GRN variants, duplicate detection | P0 |
| BK18 Service purchase | Non-stock catalog service | P0 |
| BK19 Expense purchase | GL-only payable; currently unsupported AI | P1 |
| BK20 Purchase matching | UOM-normalized quantity, value, source | P0 |
| BK21 Purchase return | Original line/qty/tax, reversal | P0 |
| BK22 Supplier settlement | Advance, payable, allocation, debit note | P0 |
| BK23 Sales enquiry | Exact stock and quote availability | P0 |
| BK24 Sales quotation | Optional quote, validity and rate | P0 |
| BK25 Sales order | Allocation, partial fulfillment | P0 |
| BK26 Direct sale | No SO/quotation required | P0 |
| BK27 POS | Optional retail branch, tender and shift | P2 |
| BK28 Delivery challan | Physical dispatch, open billing | P0 |
| BK29 Sales invoice | Direct, SO/DC-linked, service | P0 |
| BK30 Service sale | Non-stock processing revenue | P0 |
| BK31 Sales return | Return/credit, quarantine, replacement | P0 |
| BK32 Customer settlement | Advance, receipts, allocation | P0 |
| BK33 Credit control | Limits, ageing, override authority | P0 |
| BK34 Promotions | Dealer scheme, discount and combos | P1 |
| BK35 Delivery | Packing, shipment and POD | P0 |
| BK36 Availability | Physical, reserved, free, damaged | P0 |
| BK37 Reservation | Order allocation and release | P0 |
| BK38 Transfer | Source/destination, transit, receipt | P0 |
| BK39 Adjustment | Count variance, approval, valuation | P0 |
| BK40 Batch | Lot, traceability, expiry if applicable | P1 |
| BK41 Serial | Serialized products if enabled | P2 |
| BK42 Valuation | Domain costing and inventory ledger | P0 |
| BK43 Reorder | Per-store reorder, lead time, stock suggestions | P0 |
| BK44 Stock ageing | Slow-moving power/SKU analysis | P0 |
| BK45 Stock audit | Cycle count and reconciliation | P0 |
| BK46 Ownership | Owned, customer-owned, consignment | P0 |
| BK47 Quality hold | Quarantine, released, rejected | P1 |
| BK48 BOM | Lens blanks, consumables if manufacturing | P1 |
| BK49 Planning | Capacity and materials | P1 |
| BK50 Production order | Existing production domain | P1 |
| BK51 Consumption | Actual materials and stock effects | P1 |
| BK52 WIP | Unfinished processing and costing | P1 |
| BK53 Finished goods | Actual output and QC | P1 |
| BK54 Routing | Approved sequence/process | P1 |
| BK55 Outward job work | Our material to external lab | P1 |
| BK56 Inward job work | Customer-owned frame/lens | P1 |
| BK57 Costing | Material, labour, processing, overhead | P1 |
| BK58 Loss | Scrap, process limits, reason | P1 |
| BK59 Rework | Defect, remake, loss and history | P1 |
| BK60 Quality control | Authorized inspections and evidence | P1 |
| BK61 Chart of accounts | Approved account classification | P0 |
| BK62 Double entry | Balanced domain posting | P0 |
| BK63 AP | Supplier open items | P0 |
| BK64 AR | Dealer open items | P0 |
| BK65 Cash/bank | Verified settlement | P0 |
| BK66 Journal | Approved account/period/amounts | P1 |
| BK67 Bank reconciliation | Statement matching | P1 |
| BK68 GST | Effective tax engine, place of supply | P0 |
| BK69 Non-GST | Explicit distinct legal tax treatments | P0 |
| BK70 RCM | Authoritative applicable reverse charge | P1 |
| BK71 TDS/TCS | Reviewed applicability and engine support | P1 |
| BK72 Statutory docs | Applicable e-invoice/e-way bill/challan | P0 if applicable |
| BK73 Reports | GL, P&L, balance sheet, reconciled results | P0 |
| BK74 Expenses | Cost centre and ledger | P1 |
| BK75 Reversal | Preserve financial history | P0 |
| BK76 Period close | Reconcile and lock | P0 |
| BK77 Assets | Depreciation/disposal | P2 |
| BK78 Budget | Planned vs actual | P2 |
| BK79 Customer history | Authorized dealer activity | P0 |
| BK80 Warranty | Lens defects and eligible remakes | P1 |
| BK81 Repair/service | Optional lab/customer frame | P1 |
| BK82 Employee ops | Assigned role and ownership | P2 |
| BK83 Commission | Agent incentive rule and approval | P1 |
| BK84 Documents | Source, attachment, voucher | P0 |
| BK85 Communications | Approved reminders/delivery status | P1 |
| BK86 Exceptions | Missing data, mismatch, stale state | P0 |
| BK87 Analytics | Branch sales, margin, stock ageing | P0 |
| BK88 Automation | Deterministic triggers and proposals | P1 |
| BK89 Approvals | Permission, risk and exact version | P0 |
| BK90 Audit | Provenance, receipt, retention | P0 |

## 5. Missing definitions to implement first

Use existing reviewed action keys where found. Add entities `sales_order`, `sales_invoice`, `quotation`, `delivery_challan`, `payment_receipt`, `supplier_payment`, `stock_transfer`, `stock_reservation`, `unit`, `tax_profile`, `credit_note`, `debit_note`, `return`, `optical_lens_spec`, `optical_job`, `packing_slip`, `dealer`, `store`, `lens_stock_identity` as applicable. `dealer` is customer role, `store` resolves existing branch/warehouse; don't duplicate masters. Relationships: SO→customer/items/warehouse; DC→SO/dispatch movements; invoice→optional SO/DC; receipt→open item/allocation; purchase bill→optional GRN/PO; return→original posted line; lens identity→catalog/stock dimensions; optical job→customer/order/external processing; transfer→source/destination. Add action descriptors for `sales_order.create.v1`, `sales_invoice.create.v1`, `quotation.create.v1`, `delivery_challan.create.v1`, `payment_receipt.create.v1`, `stock_transfer.create.v1` and actual registered keys after verification. Supplier settlement, return, advance or lab-specific commands must not be described as available until actual registration/domain proof exists.

Process paths: direct goods purchase, GRN-first purchase, PO-to-bill with receipt policy, formal procurement, service purchase, expense payable (unsupported until domain implemented), direct stock sale, SO→dispatch→invoice, order-to-invoice, direct service invoice, settlement, return/reversal, cross-store transfer, replenishment, optical stock lens order and optional Rx job. A process may start from existing verified ERP evidence. Do not create a dummy predecessor. Use bounded DAG, no unbounded loop. Each action approval individually sealed and linked to same workflow step/relationship; independently verified physical event, never model text.

## 6. Fact readers and runtime context

Extend `ScopedBusinessFactReader` with allowlisted projections for sales/dispatch, stock identities, transfers, rate lists, invoice balances, optical jobs. Query via authoritative service and server-generated scope. Include as_of, source identifier, document status, relevant revision, unit, ownership and permissions. Do not return raw model rows, patient identity, full statements, secrets or arbitrary columns. For cross-store summary, separately authorize source and destination; return only permitted stores, no foreign count leakage. Use safe bounded search and pagination. Missing FY or ownership => denied until real migration. Prove same not-found semantics for unknown and foreign records.

### Acceptance

- Existing schema-1 fixture still loads; modular registry produces deterministic version hash, duplicate/cycle/missing source fails.
- Draft/withdrawn or wrong-profile knowledge never starts or progresses a workflow.
- Same action discovered under SDK/REST/MCP policy uses existing registry and identical domain checks; knowledge not permission.
- Optical profile request returns only relevant optical facts; non-optical tenant receives no Rx or hidden product attributes.
- Workflow with changed profile, policy or source state cannot execute stale proposal.
- Direct stock sale and direct purchase start without a quotation/order. Existing source references are verified rather than manufactured.
- Enable flags only in explicit test tenant; no writes on context load, no model request on deterministic path.
