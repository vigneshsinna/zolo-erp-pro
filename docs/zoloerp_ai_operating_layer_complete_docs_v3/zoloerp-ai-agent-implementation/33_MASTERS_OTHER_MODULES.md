# 33 — Guided Master Data and Full ERP Module Onboarding

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Goal
A user should be able to maintain business masters via natural language without navigating many forms, while preventing duplicates and cross-company pollution. Reuse existing master CRUD/validation services. **Do not assume that every menu has a safe write command**; discover and certify each module first.

## First-wave masters
| Master | Example user instruction | Validations / UI |
|---|---|---|
| Customer | "Add Kumar Textiles in Chennai with 30-day credit." | Customer name/company duplicate, address/tax ID, credit policy, authorized create permission, consent/privacy |
| Supplier | "Add ABC Traders with GSTIN and bank details." | Supplier category, tax ID, duplicate check, bank details high sensitivity, independent approval |
| Product | "Create cotton fabric blue, SKU FAB-BLUE, sold in metres." | SKU uniqueness, product type, UOM and conversion, tax category, stock tracking, variants |
| Warehouse | "Add another finished-goods warehouse." | Branch authorization, location/status, stock policy; higher-impact admin control |
| Chart of accounts | "Add a freight expense account." | Accounting structure, group classification, duplicate/name and high-risk admin permission |
| Price list | "Increase price of selected SKUs by 5%." | Scope/discount caps, effective date, full old/new diff, bulk review |

## Guided master workflow
`parse intent → query existing masters → show exact/fuzzy duplicates → ask missing required field(s) → domain validation → editable preview of old/new fields → authorise and save through master service → return actual ID/route`. For updates use optimistic record version and show diff. Never overwrite immutable IDs/tax flags or change company owner from model text. New customer/supplier bank details require secure field access and separate masking.

## Product imports
A user may say "Create these 200 products from Excel". The AI shows a mapping preview with accepted field names, sample rows, duplicate candidates, UOM warnings and a downloadable row error report. Simulate/validate first; approved bulk create is chunked, idempotent and reports partial success per row. Do not inject all 200 rows into one LLM prompt or let the LLM invent missing SKUs.

## Other ERP modules and onboarding checklist
| Module | First AI opportunity | Action needs confirmation |
|---|---|---|
| POS/counter sales | Product lookup, guided bill preparation | Offline inventory/settlement and register accounting |
| HR/Payroll | Attendance and payroll analysis | Restricted compensation and pay run controls |
| Fixed assets | Asset lookup, maintenance task | Depreciation and disposal journal ownership |
| Expenses | Extract expense receipt, draft claim | Tax/approval/ledger posting rules |
| Projects/services | Summarise progress, prepare task | Assignment and billing permission |
| Job work/manufacturing | BOM and production plan | Lot traceability/stock consumption |
| Tax compliance | Explain posted GST reports | No statutory filing without distinct review |
| Administration | Navigate policies/settings | Never broad role/grant change without privileged approval |

## Capability onboarding method
1. Inventory menu/API/domain services, existing permissions and true business status lifecycle.
2. Identify safe read query, prepare schema, domain validator, exact create/update/post command and cancellation/reversal.
3. Define ownership fields, sensitive columns, tax/stock/GL effects and approval tier.
4. Build deterministic fixture + denied cross-company example + duplicate/retry case.
5. Add action capability to code registry only after signed business review and staging proof.
6. Roll out by company and exact action, not a blanket "all ERP write" toggle.

## Scope discipline
Do not force every module into the first launch. An unsupported operation receives a helpful explanation and a safe link to its existing ERP form. The AI can still assist filling a guided form without bypassing service validation. Prioritize frequent workflows based on real user time spent and error reduction.


---

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.

## V3 exact initial setup ownership
Initial group/company creation is already implemented in non-AI `CompanyDirectoryService` (with MAIN branch, FY, warehouse, COA and starter masters). Use `CompanySetupService` for authorized additional branches/warehouses, `IndustryProfileService`/`IndustryCatalog` for vertical and preset units, `ProductMasterService`/`ReviewedProductImport` for product creation/CSV, and `GstSetupService`/`TaxSetupService` for tax configurations. Legacy `UnitController`, `CustomerController`, `SupplierController` and `WarehouseController` are not automatically safe AI adapters; create audited company-scoped business services or wrappers after reviewing their side effects. All setup modifications on already-used masters obey `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md`. See `37`–`39`.
