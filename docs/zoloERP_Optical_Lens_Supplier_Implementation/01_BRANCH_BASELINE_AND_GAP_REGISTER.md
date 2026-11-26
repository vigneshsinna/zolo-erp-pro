# 01 — Repository baseline and missing functionality

**Source snapshot:** [`e299b862c33f70cff8f86cfcbb36c1326da6dde5`](https://github.com/vigneshsinna/zolo-erp-pro/commit/e299b862c33f70cff8f86cfcbb36c1326da6dde5), branch `feature/ai-unified-input-clarification`. Source observations below refer to this commit, not the earlier knowledge release branch. Status labels mean source present, partial, not found in inspected contract, or unverified. None means deployed/production tested.

## 1. Source findings

| Area | Source-backed observation | Gap / disposition |
|---|---|---|
| Industry profile | `app/Services/Industry/IndustryCatalog.php` defines `opticals`, quantity scale 2, PRS/PCS/BOX, Lab / Branch labels, attributes, lab processes, reports and `lens_manufacturing`, `optical_retail`, `wholesale_distributor` subtypes. | Reuse. Select wholesale distribution for lens supplier unless manufacturing/Rx lab actually operates; optional branch capabilities need explicit approved design. |
| Company setup | `IndustryProfileService::apply/settings` and `company_industry_settings` store profile/subtype/settings; one row per company. `CreateOpticalsCompanyCommand` defaults to lens_manufacturing. | Do not assume every store is a factory. Existing service switches preset and enables subtype capabilities. Avoid destructive profile resets for live tenants; support effective branch overlays only if needed and tested. |
| Optical masters | `OpticalsMasterSeeder` seeds units, brands/categories, lens types, sale types and LAB/PS/INV series. `ProductTypeAndOpticalSpecsTest` covers product type, quick creation and forms. | Audit seeder scoping; no default seed across unrelated tenants. Seed tax labels are not proof of current tax rates. Verify effective tax configuration and UOM factors. |
| Product attributes | `Product` has `base`, `addition`, `lr`, `dia`, `product_type_id`; profile attributes include lens material/type/coating/index/diameter/base curve. | Existing product identity does not by itself prove stock is separated by every power. Inspect real catalog, variants and stock identities. Define one canonical optical identity; do not duplicate product catalog. |
| Optical sales data | Migration `2026_11_11_000001_add_optical_prescription_columns_to_product_sales_table.php` adds `sph,cyl,axis,base,addition,lr,dia,prescription_json`; `Product_Sale` allows these. Migration `2026_11_11_000002_add_optical_columns_to_products_and_sales_tables.php` adds product and sales header fields. | Preserve exact values through AI schemas, task corrections, preview hashes and command adapters. These fields are document metadata, not stock dimensions by themselves. |
| Optical purchase data | `ProductPurchase` getters read optical attributes from native columns or `stock_details_json`. Tests verify purchase accessors. | Validate complete persistence, import, PO/GRN/bill/return propagation and stock identity. Avoid adding parallel columns unless justified. |
| Billing metadata | `CommercialApplicationService` accepts end-user name/phone/email/address, fitting_charge, tinting_cost, customer_order_no; attributes validation present. | AI paths do not automatically pass these fields; reconcile charges with pricing, taxes and journal. End-wearer personal data optional and restricted. |
| Optical vouchers | `OpticalsProfileAndVouchersTest` asserts Rx lab slip with QR and power grid, A6 packing slip with barcode, sale header/line persistence; `OpticalProfileIsolationAndProductBoxesTest` tests Rx invoice rendering and non-optical hiding. | Printing tests do not prove an independent lab-order state machine or optical stock flow. Preserve views, generate official prints only from verified persisted records. |
| Stock availability | `InventoryAvailabilityService::forProduct` supports product/warehouse/variant/batch; `forCatalogBatch` scoped to company and branch warehouse IDs. | No reviewed optical power-matrix fact reader found. Test scope of all callers, power identity, reservation, in-transit and QC stock. |
| Units | `UomConversionService::toBase` validates company and uses conversion records / existing unit relations. | PRS/PCS/BOX not interchangeable. Explicit per-SKU factor and integral piece policy; no assumed factor for boxes. |
| Commercial engine | `PurchaseApplicationService`, `CommercialApplicationService`, `CommercialPricing`, `OrderLifecycleService`, `MaterialDocumentService`, `CreditControlService`, `ReturnService`, `CommercialReversalService` exist. | Map actual supported operations; never replace finance logic with knowledge JSON. Verify direct purchase and direct sale, source matching, tax, returns, and credit controls. |
| AI four-document contracts | `CreatePurchaseOrderInputSchema`, `CreatePurchaseBillInputSchema`, `CreateSalesOrderInputSchema`, `CommercialDocumentInputSchema` contain product_id, qty, rates, UOM and document links. | **Confirmed contract gap:** inspected schemas omit optical line fields and header fields; sales commercial adapter builds a restricted payload without those optical headers. Correct end-to-end instead of adding prompt text only. |
| Purchase bill expense | `CreatePurchaseBillInputSchema` includes `is_expense_bill` and expense_account_id; runbook says uncataloged expense-only posting remains fail-closed. | Field presence is not capability. Implement approved non-stock AP domain path or leave unavailable; no dummy lens. |
| Core knowledge | `resources/ai/knowledge/definitions.json` schema 1 contains 43 definitions: 11 entities, 9 relationships, 14 actions, 6 processes, 3 policies. **All draft.** | Missing sales and optical entities/actions/processes and scoped optical facts. Extend registry, keep review gates. |
| Direct buying | Draft `process.direct_goods_bill.v1`, `process.service_payable.v1` already exist. Purchase-to-pay includes PO/GRN. Expense process unsupported. | Integrate route selection, explicit physical receipt status and evidence, direct/GRN/PO parity. Do not force quotation/PO. |
| Inward job work | Latest definitions now include `principal_job.order.create.v1`, `.receive.create.v1`, `.return.create.v1`, `.service_invoice.create.v1`. | Earlier gap list is stale: adapters have progressed. Verify scoped ownership, quantity and ledger end-to-end; reuse for customer-owned frames only after suitability confirmed. |
| Fact reader | `ScopedBusinessFactReader` supports supplier/customer/product/warehouse, purchasing, GRN, job work. Latest permission map uses read and/or create permission alternatives. | Missing sales order, sales invoice, delivery challan and optical lens stock facts in inspected list. Prove exact permission semantics, do not broaden reads. |
| Knowledge registry/planner | `BusinessKnowledgeRegistry`, `KnowledgeDefinitionValidator`, `BusinessWorkflowPlanner`, `BusinessWorkflowProgress` present. Planner requires reviewed action/process. | Extend modular schema and verify completion, evidence and profile drift; do not infer completion from proposal IDs. |
| U1 shared discovery | `app/Ai/Capabilities/CapabilityCatalog.php`, dispatcher, descriptors, compiler and exposure policy exist. Runbook reports 45 descriptors (15 read, 29 prepare, 1 status). | Reuse existing system. Recompute counts after additions; don't hard-code counts. |
| U2 MCP | `/mcp/erp` governed proposals, scoped token bindings, deduplication, status, review handoff implemented per runbook. Only PO preparation exposed, plus status and 2 read tools. | Add other optical/commercial capabilities only after review; keep approval and execution internal. |
| Setup discovery gaps | Runbook explicitly excludes branch, product, unit and warehouse setup due to missing requirements providers. | Implement reviewed requirements providers for optical master creation as needed; product creation separate approval. |
| Release | Latest commit message reports 47 tests / 311 assertions, zero failures and focused textile regression pass; runbook says G4/G5/G6 pending. | Independent full CI and actual staging/model/user evaluation required. No PR-triggered workflow runs returned for pinned SHA. |

## 2. Priority gap register

| ID | Severity | Gap | Acceptance / file |
|---|---|---|---|
| GAP-01 | P0 | All 43 definitions draft; no sales/optical coverage | Reviewed versioned core and optical knowledge; 02 |
| GAP-02 | P0 | Rx and charge metadata omitted by AI contracts | Exact typed round-trip, hash integrity and finance parity; 03–06 |
| GAP-03 | P0 | Power-specific inventory identity not established in inspected AI contracts | Prove SKU/variant/stock identity or add reviewed domain extension; no wrong-power allocation; 03 |
| GAP-04 | P0 | Direct, order-linked, GRN-linked and invoice-before-receipt variants not fully orchestrated | No forced predecessor; no imaginary stock; verified fiscal/stock results; 04 |
| GAP-05 | P0 | Sales facts/Order-to-Cash knowledge absent | Direct sale, SO, dispatch, invoice, settlements and returns; 02,04 |
| GAP-06 | P0 | Multi-store permission, stock transfer, GST registration distinction | Scoped cross-store lookup and approved send/receive; 03–04 |
| GAP-07 | P0 | Profile-specific privacy and optical input ambiguity | No unauthorized end-wearer details, no inferred power, no model stock math; 03,05,06 |
| GAP-08 | P1 | Lab slips exist but Rx job lifecycle coverage unverified | Verify existing operations; implement missing job references/evidence only; 05 |
| GAP-09 | P1 | Missing master requirements providers and optical actions | Shared catalog, no duplicate engine; 06 |
| GAP-10 | P1 | Customer/dealer rate contracts, warranty/remake, dispatch handling | Verified pricing, route, service and correction flows; 04–05 |
| GAP-11 | P0 | Full enabled-feature regression and live eval pending | G0–G8 evidence and go/no-go; 07 |

## 3. Decisions for owners (proposed defaults, not inferred facts)

1. Is each store a company branch, a warehouse, or a separate legal entity? Map GST registrations and authorized stock locations. Default: same-company branches only until reviewed.
2. Is this a finished-lens wholesaler, distributor with Rx lab, or manufacturer with retail? Default: B2B supplier profile, optional lab enabled only for actual operation.
3. Are stock lenses identified by unique SKU per specification, variants, lot or another existing stock identity? Default: use existing authoritative identity; do not change stock schema until a reconciliation proves need.
4. Are lens quantities pieces, pairs or boxes? What is each product's conversion factor? Default: explicitly confirmed unit and catalog mapping; no universal BOX factor.
5. Is credit allowed, which rate list applies, who may override? Default: enforce existing credit control and review overrides.
6. When do invoices post stock? Is invoice-before-receipt supported? Default: only existing safe routes; defer unsupported receipt timing.
7. Which Rx fields and patient/end-user info are necessary for fulfillment? Default: customer/dealer and job reference, minimal wearer data; restricted medical information.
8. Which lab processes are internal vs outsourced, and how are defects/remakes billed? Default: no new workflow activated until domain owner signs off.
9. Which branches share inventory visibility and cross-branch fulfillment authority? Default deny. Separate stock ownership and tax status from visibility.

## 4. Source links to reopen during implementation

- [IndustryCatalog](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/app/Services/Industry/IndustryCatalog.php), [IndustryProfileService](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/app/Services/Industry/IndustryProfileService.php)
- [Knowledge definitions](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/resources/ai/knowledge/definitions.json)
- [Optical vouchers tests](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/tests/Feature/OpticalsProfileAndVouchersTest.php), [Optical isolation tests](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/tests/Feature/OpticalProfileIsolationAndProductBoxesTest.php)
- [Unified MCP runbook](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/docs/ai/release/UNIFIED_CAPABILITY_AND_MCP_RUNBOOK.md)
- [AI capability catalog](https://github.com/vigneshsinna/zolo-erp-pro/blob/e299b862c33f70cff8f86cfcbb36c1326da6dde5/app/Ai/Capabilities/CapabilityCatalog.php)

Review scope is selected files and tree inventory, not exhaustive code audit or a new CI execution. Re-open implementation files at current HEAD before edits. Do not treat listed candidate class names as guaranteed existing interfaces.
