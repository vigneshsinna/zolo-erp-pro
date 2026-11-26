# O01–O18 — Optical Lens Supplier & Rx Lab AI Implementation and Verification Report

- **Base SHA:** `e299b862c33f70cff8f86cfcbb36c1326da6dde5`
- **Branch:** `feature/ai-unified-input-clarification`
- **Specification Source:** `docs/zoloERP_Optical_Lens_Supplier_Implementation/` (`00_README_IMPLEMENTATION_ORDER.md` through `08_INCREMENTAL_PR_ROADMAP.md`)

---

## 1. Scope & Source Files Reused

Built strictly on top of existing authoritative zoloERP domain engines and U1/U2 capability layers without creating duplicate commercial/accounting/inventory engines or external approval paths:

- **Tenant & Industry Context:** `App\Services\Platform\CompanyContextResolver`, `App\Services\Industry\IndustryCatalog`, `App\Services\Industry\IndustryProfileService`
- **Commercial & Pricing:** `App\Services\Commercial\CommercialApplicationService`, `App\Services\Commercial\CommercialPricing`, `App\Services\Commercial\OrderLifecycleService`, `App\Services\Commercial\ProductQueryService`
- **Inventory & UOM:** `App\Services\Inventory\InventoryAvailabilityService`, `App\Services\Inventory\InventoryMovementService`, `App\Services\Inventory\UomConversionService`
- **AI Action & Capability Pipeline:** `App\Ai\Capabilities\CapabilityCatalog`, `App\Ai\Capabilities\CapabilitySchemaCompiler`, `App\Ai\Capabilities\CapabilityExposurePolicy`, `App\Ai\Capabilities\CapabilityDispatcher`, `App\Ai\Services\ActionPreparationService`, `App\Ai\Services\ActionApprovalService`, `App\Ai\Services\ApprovedActionExecutor`, `App\Ai\Services\InputCompletenessEvaluator`, `App\Ai\Services\BatchRecordResolutionService`, `App\Ai\Services\RecordResolutionService`

---

## 2. Work Package Coverage (`O01`–`O18`, `GAP-01`–`GAP-11`) & File Inventory

| Work Package | Scope Delivered | Key Modified / Created Artifacts |
|---|---|---|
| **O01 / O02** | Modular schema_version 2 knowledge registry, profile/subtype/effective-date validator, deterministic content hash, optical packs (`status: draft`) | `app/Ai/Knowledge/{KnowledgeDefinition,KnowledgeDefinitionValidator,BusinessKnowledgeRegistry,BusinessKnowledgeContextReader,BusinessWorkflowPlanner}.php`, `resources/ai/knowledge/{governance,profiles,core,industries/opticals}/*`, `source_manifest.json` |
| **O03** | Fail-closed `IndustryContextResolver` (`opticals` + `wholesale_distributor`/`lens_manufacturing` + `company_branch_industry_roles`) and profile-gated `ScopedBusinessFactReader` (`sales_order`, `sales_invoice`, `quotation`, `delivery_challan`, `optical_lens_identity`, `optical_rx_job` with PII redaction) | `app/Ai/Knowledge/IndustryContextResolver.php`, `app/Ai/Knowledge/ScopedBusinessFactReader.php`, `database/migrations/2026_11_25_000003_create_optical_ai_foundation_tables.php` |
| **O04 / O05** | Signed optical identity normalizer (`+/-` SPH/CYL, `0..180` axis, R/L/Pair eye, DIA, SHA-256 signature), exact/candidate resolver, power-matrix availability reconciled 1:1 to `InventoryAvailabilityService`, read-only SDK tools (`optical.lens_candidates.v1`, `optical.power_matrix.v1`), optical columns in `ProductQueryService` | `app/Services/Optical/{OpticalSpecNormalizer,OpticalCatalogService,OpticalPowerMatrixService}.php`, `app/Ai/Handlers/{OpticalLensCandidatesHandler,OpticalPowerMatrixHandler}.php`, `app/Ai/Services/{RecordResolutionService,BatchRecordResolutionService}.php`, `app/Services/Commercial/ProductQueryService.php`, `app/Providers/AiServiceProvider.php` |
| **O06–O09** | Shared `OpticalSchemaFragment`, `PurchaseDocumentClassifier` (P1 direct no-PO, P2 GRN, P3 PO, P5 `invoice_before_goods`), `SalesDocumentClassifier`, `OpticalReturnValidator` (blocks wrong-power returns and invalid QC dispositions), optical schema/adapter round-trip across PO, GRN, Purchase Bill, SO, Quotation, DC, Sales Invoice | `app/Ai/Schemas/{OpticalSchemaFragment,CreatePurchaseOrderInputSchema,CreatePurchaseBillInputSchema,CreateGoodsReceivedNoteInputSchema,CreateSalesOrderInputSchema}.php`, `app/Ai/Requirements/*RequirementsProvider.php`, `app/Ai/Adapters/{PurchaseOrderPreviewAdapter,PurchaseBillPreviewAdapter,GoodsReceivedNotePreviewAdapter,GoodsReceivedNoteCommandAdapter,SalesOrderPreviewAdapter,SalesOrderCommandAdapter,CommercialDocumentAdapter}.php`, `app/Ai/Services/InputCompletenessEvaluator.php`, `app/Models/{GoodsReceivedNoteItem,DeliveryChallanItem}.php`, `app/Services/Optical/OpticalReturnValidator.php`, `app/Services/Commercial/CommercialApplicationService.php` |
| **O10** | Same-GSTIN multi-store transfer policy & branch authorization (`OpticalTransferPolicyService`) wired into `stock_transfer.create.v1` + `tax_registrations` in `sourceHash()` | `app/Services/Optical/OpticalTransferPolicyService.php`, `app/Ai/Adapters/ExtendedActionAdapter.php` |
| **O11 / O12** | Optional Rx Lab job model & state machine (`OpticalRxJob`, `OpticalRxJobService`) strictly gated to `opticals` + (`lens_manufacturing` or `rx_lab` branch role) + `AI_OPTICALS_LAB_ENABLED=true`, idempotent creation, optimistic revision locks, QC & remake lineage, PII redaction | `app/Models/OpticalRxJob.php`, `app/Services/Optical/OpticalRxJobService.php` |
| **O13** | Setup requirements providers (`setup.product.create.v1`, `setup.unit.create.v1`, `setup.warehouse.create.v1`, `setup.branch.create.v1`), closed capability schemas (`CapabilitySchemaCompiler`, `CapabilityCatalog`), MCP & profile exposure policy guards | `app/Ai/Requirements/{Product,Unit,Warehouse,Branch}SetupRequirementsProvider.php`, `app/Ai/Registries/ActionRequirementsRegistry.php`, `app/Ai/Schemas/CreateProductInputSchema.php`, `app/Ai/Adapters/{ProductSetupPreviewAdapter,ProductSetupCommandAdapter}.php`, `app/Ai/Capabilities/{CapabilityCatalog,CapabilityExposurePolicy,CapabilitySchemaCompiler}.php`, `config/ai_capabilities.php` |
| **O14–O16** | Optical workspace desk panel (canonical tokens, bulk signed power paste, scope clear), deterministic zero-LLM triggers (`OpticalDeterministicTriggers`), optical workflow metrics (`BusinessWorkflowMetrics::opticalSummary()`), staging `optical_mixed` k6 profile | `public/css/pages/ai-optical.css`, `resources/views/backend/ai/partials/optical_lens_panel.blade.php`, `public/js/ai-optical-workspace.js`, `app/Http/Controllers/Ai/AiWorkspaceController.php`, `resources/views/backend/ai/workspace.blade.php`, `app/Ai/Automation/OpticalDeterministicTriggers.php`, `app/Ai/Knowledge/BusinessWorkflowMetrics.php`, `tests/k6/ai_business_workflow_concurrency.js` |

---

## 3. Feature Flags & Default-Off Guarantees

All new flags in `config/ai_business_knowledge.php` and `.env.example` default to **`false`**:

- `AI_OPTICALS_ENABLED=false` (`ai_business_knowledge.opticals_enabled`)
- `AI_OPTICALS_LAB_ENABLED=false` (`ai_business_knowledge.opticals_lab_enabled`)
- `AI_OPTICALS_CROSS_STORE_ENABLED=false` (`ai_business_knowledge.opticals_cross_store_enabled`)
- `AI_OPTICALS_MCP_ENABLED=false` (`ai_business_knowledge.opticals_mcp_enabled`)

When flags are off or the company does not have an active `opticals` profile in `company_industry_settings`:
- `CapabilityExposurePolicy` hides `optical.*` tools on all channels (`sdk`, `rest`, `mcp`, `automation`).
- `OpticalSchemaFragment::assertAllowedForProfile()` rejects optical prescription/lens slots on commercial proposals.
- `ProductQueryService` omits optical attributes (`base`, `addition`, `lr`, `dia`, `product_type_id`).
- `OpticalRxJobService` rejects any lab operation with `AuthorizationException`.
- `OpticalDeterministicTriggers` returns `enabled: false` with zero DB/model side effects.
- `AiWorkspaceController` and `workspace.blade.php` do not mount the Optical Lens Desk panel, CSS, or JS.

---

## 4. Security & Finance Invariants

1. **Zero Autonomous Writes:** Optical read tools (`optical.lens_candidates.v1`, `optical.power_matrix.v1`), deterministic triggers, and setup/commercial prepare flows emit zero ledger or stock mutations; all ERP writes remain gated by `ActionPreparationService` -> immutable `ActionApprovalService` -> `ApprovedActionExecutor`.
2. **Signed Power Precision:** `OpticalSpecNormalizer` preserves explicit `+/-` signs (`-2.00` vs `+2.00`), keeps `0.00`/`PL` distinct from missing `null`, rejects out-of-range powers and invalid axes (`0..180`), and `OpticalCatalogService` never substitutes different signed powers.
3. **Cross-GSTIN Internal Transfer Block:** `OpticalTransferPolicyService` fails closed (`cross_gst_registration_requires_tax_route`) when source and destination branches hold distinct active GSTINs in `tax_registrations`.
4. **Invoice-Before-Receipt Block:** Both `PurchaseBillRequirementsProvider::validate()` and `PurchaseBillPreviewAdapter::preview()` fail closed when `receipt_status=not_received` without a linked GRN, while preserving direct no-PO bills when goods are physically received.
5. **Wearer PII Redaction & Cross-Tenant Isolation:** `ScopedBusinessFactReader` and `OpticalRxJobService::toSafeSummary()` omit end-wearer PII; non-optical tenants are blocked from optical fact reads and Rx lab endpoints.

---

## 5. Observed Verification Results (`zolo_test_ai_release`)

1. **Syntax & Migration Checks:**
   - `php -l` across all 59 created/modified PHP files + `node --check public/js/ai-optical-workspace.js`: **0 errors**.
   - `php artisan migrate --force --path=database/migrations/2026_11_25_000003_create_optical_ai_foundation_tables.php` on `zolo_test_ai_release`: **DONE** (`893.95ms`).
2. **Optical Unit & Feature Suites (`php vendor/phpunit/phpunit/phpunit -c phpunit.ai.xml tests/Unit/Ai/Optical tests/Feature/Ai/Optical`):**
   - `ModularKnowledgeRegistryTest` (3 tests)
   - `OpticalLensIdentityAndClassifierTest` (3 tests)
   - `OpticalCatalogPowerMatrixAndTransferTest` (3 tests)
   - `OpticalCommercialWorkflowParityTest` (4 tests)
   - `OpticalLabCapabilityMcpAndSecurityTest` (2 tests)
   - **Total:** **15 / 15 passed (100 assertions)**, 0 failures, 0 skipped.
3. **UI Token Guard (`php vendor/phpunit/phpunit/phpunit tests/Unit/DesignTokensTest.php`):**
   - **4 / 4 passed (4 assertions)**, 0 inline/hex token violations.
4. **Core AI, Knowledge, Capability, MCP & Commercial Regressions (`php vendor/phpunit/phpunit/phpunit -c phpunit.ai.xml`):**
   - Ran `BusinessKnowledgeRegistryTest`, `PurchaseDocumentClassifierTest`, `CapabilityCatalogAndSchemaTest`, `BusinessKnowledgeGovernanceTest`, `SharedCapabilityDiscoveryTest`, `McpGovernedProposalsTest`, `PurchaseBillActionTest`, `CommercialDocumentActionTest`, `BusinessKnowledgeSupplierWorkflowTest`:
   - **44 / 44 passed (305 assertions)**, 0 failures, 0 skipped.
5. **Existing Optical Profile Feature Suite (`php vendor/phpunit/phpunit/phpunit -c phpunit.ai.xml tests/Feature/OpticalsProfileAndVouchersTest.php`):**
   - **5 / 7 passed (54 assertions)** on `zolo_test_ai_release`; 2 tests failed due to `zolo_test_ai_release` fixture differences (`LenzBreeze` seeded product count 15 < expected 100 from full dev seeder, and HTTP 503 on non-AI optical route check). Not run against dev DB `zoloerp` per DB safety Law 6.

---

## 6. Gate Status (`G0`–`G8`) & Pending Releases

| Gate | Status | Notes |
|---|---|---|
| **G0** Baseline, migration path & regression safety | **PASS** | Additive migration applied cleanly on `zolo_test_ai_release`; dev DB untouched |
| **G1** Modular knowledge & closed capability contracts | **PASS** | `ModularKnowledgeRegistryTest` + `CapabilityCatalogAndSchemaTest` green |
| **G2** Read-only optical identity & matrix reconciliation | **PASS** | Reconciled to `InventoryAvailabilityService` with `model_calls = 0` |
| **G3** Commercial optical workflow & return parity | **PASS** | PO, GRN, direct Purchase Bill, SO, return validator & P5 block verified |
| **G4** Security, tenancy, lab gating & UI token compliance | **PASS** | `DesignTokensTest`, `McpGovernedProposalsTest`, cross-GSTIN & lab gates green |
| **G5** Live model evaluation (Nemotron) & credit metering | **PENDING** | Requires live staging API credentials & corpus run (`O17`) |
| **G6** Staging k6 concurrency soak (`optical_mixed`) | **PENDING** | k6 script extended; requires live staging server run (`O17`) |
| **G7 / G8** Owner knowledge review promotion & pilot UAT signoff | **PENDING** | New optical definitions remain `status: "draft"` until domain owners approve (`O18`) |

---

## 7. Explicit Unsupported / Fail-Closed Capabilities

- Cross-GSTIN internal stock transfers (`stock_transfer.create.v1`) remain blocked (`cross_gst_registration_requires_tax_route`).
- Invoice-before-goods purchase billing (`receipt_status=not_received` without GRN) fails closed at both preparation and preview.
- Stock-only wholesale optical companies are blocked from `OpticalRxJobService` lifecycle operations.
- General-ledger expense-only AP bills remain unsupported outside service/goods catalog items.
- External MCP clients cannot approve, post, or execute transactions (`opticals_mcp_enabled` defaults to `false`).

---

## 8. Rollback & Audit Preservation

- **Instant Kill Switch:** Set `AI_OPTICALS_ENABLED=false` (and optionally `AI_OPTICALS_LAB_ENABLED=false`, `AI_OPTICALS_CROSS_STORE_ENABLED=false`, `AI_OPTICALS_MCP_ENABLED=false`) in `.env` and clear config cache (`php artisan config:clear`).
- **Audit Integrity:** Migration `down()` intentionally retains additive tables (`optical_lens_identities`, `optical_rx_jobs`, `optical_rx_job_events`, `company_branch_industry_roles`) so historical vouchers, stock movements, and audit logs are never orphaned or destroyed.
- **Merge Readiness vs. Release Readiness:** Mergeable to feature integration after peer review with all flags default-off (`false`); production pilot activation awaits `G5`–`G8` staging soak, live model evaluation, and domain-owner review promotion.
