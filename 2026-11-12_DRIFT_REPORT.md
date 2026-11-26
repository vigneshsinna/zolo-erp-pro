# 2026-11-12 EGM Drift Report: Autonomous AI Agent Features

## Resolution audit — 2026-11-17

The reported implementation gaps have been implemented in `feature/ai-unified-input-clarification`. The findings below this resolution audit are the original November 12 snapshot, retained for traceability; their “absent” and “not implemented” statements do not describe the current checkout. This audit closes the listed code gaps, not every release gate in the wider blueprint.

| Report sections | Resolution and source evidence |
| --- | --- |
| 11 — MCP | Authenticated, scope-bound read-only MCP server at `POST /mcp/erp`; fixed external read aliases with per-company/branch bearer bindings, closed schemas, public-address DNS pinning, destination allowlists and no remote tool discovery. See [infrastructure operations](docs/ai-infrastructure-operations.md), [MCP routes](routes/ai-infrastructure.php), and [outbound client](app/Ai/Infrastructure/OutboundMcpReadClient.php). |
| 13 — API and voice | Authenticated API SSE supports GET/POST; the session workspace sends CSRF-protected POST streams. UTF-8 deltas are displayed as unverified draft text, then replaced by the persisted final result. Interrupted streams never retry silently. Provider dispatch, quota and usage settlement cover deferred SDK generators. Voice recording and optional browser speech recognition require an explicit user action; dictation never submits automatically. See [chat controller](app/Http/Controllers/Api/V1/AiChatController.php), [gateway](app/Ai/Gateway/ApprovedModelGateway.php), [stream client](public/js/ai-workspace-stream.js), [dictation](public/js/ai-workspace-dictation.js), and [media controls](public/js/ai-media-input.js). |
| 14 — Queues | Approved execution and polling use reference-only jobs, fresh worker actor context and existing idempotent execution receipts. Optional Horizon configuration isolates execution, telemetry, schedules, documents and communications. Plain Laravel workers are supported; Horizon itself is not installed. See [worker context](app/Ai/Infrastructure/WorkerActorContext.php) and [worker pools](config/horizon.php). |
| 15 — RAG | Private encrypted document intake, reviewed SOP chunking, embedding and optional Qdrant search. Every citation rechecks SQL scope, access, version, expiry and plaintext checksum. Foreign or revoked vector hits cannot establish access. See [document operations](docs/ai-documents-operations.md) and [retrieval service](app/Ai/Documents/KnowledgeRetrievalService.php). |
| 16 — Observability | Optional queued Langfuse OTLP sink exports redacted trace/span, usage and latency metadata with explicitly configured cost estimates. Local audit persists independently of queue or sink failure. See [infrastructure operations](docs/ai-infrastructure-operations.md). |
| 20, 28, 30 — Purchasing | `grn.create.v1` and `purchase_bill.create.v1` have typed intake, source-linked previews, approval and authoritative execution. Partial receipts, combined over-receipt, decimal precision, duplicate supplier bills, tax and source changes are checked. Goods receipts use the actual `MaterialDocumentService` and `OrderLifecycleService` contracts; bills use `CommercialApplicationService`. See [GRN adapter](app/Ai/Adapters/GoodsReceivedNoteCommandAdapter.php) and [bill adapter](app/Ai/Adapters/PurchaseBillCommandAdapter.php). |
| 20, 28, 31 — Sales and collections | `quotation.create.v1`, `delivery_challan.create.v1`, `sales_invoice.create.v1` and `payment_receipt.create.v1` are registered, scoped and approval-bound. Dispatch-to-invoice reconciliation avoids a second stock issue. Quotations use the existing pricing/numbering/model transaction; receipts use the repository's authoritative receipt service rather than inventing the blueprint's illustrative service names. Internal `payment_reminder.prepare.v1` remains a draft. Separate `payment_reminder.send.v1` seals an email preview, requires communication permission and explicit approval, and queues an encrypted outbox. Unknown delivery outcomes never retry automatically. See [commercial catalog](app/Ai/Registries/CommercialActionCatalog.php), [commercial adapter](app/Ai/Adapters/CommercialDocumentAdapter.php), and [reminder operations](docs/ai-payment-reminders.md). |
| 20, 28, 32 — Inventory and finance | Transfers, physical stock adjustments (both reported aliases), and exact balanced journals pass through existing inventory and accounting owners. Preview is read-only; current source state and stock are checked under locks before approved execution. See [extended catalog](app/Ai/Registries/ExtendedActionCatalog.php) and [extended adapter](app/Ai/Adapters/ExtendedActionAdapter.php). |
| 20, 32 — Manufacturing | Production planning and completion plus job-work outward and inward use `ProductionService` and `JobWorkService`, with current permissions, capability switches, quantities, source bindings and durable idempotent receipts. See [extended adapter](app/Ai/Adapters/ExtendedActionAdapter.php). |
| 20, 33 — Masters and imports | Chart-of-account creation, reviewed old/new price changes and selected valid CSV/XLSX product rows use typed action definitions and approval. Product import stores per-row receipts so partial recovery cannot duplicate completed rows. See [master service](app/Ai/Services/ExtendedMasterService.php) and [import catalog](app/Ai/Imports/DocumentActionCatalog.php). |
| 20, 34 — Document intake | Authenticated private quarantine, scanner boundary, MIME/extension/size checks, bounded local PDF/image extraction and OCR, safe spreadsheet parsing and row-error export are implemented. Scanned PDFs use configured rasterization and OCR; blank physical page positions are preserved. Parsing requires a successful scan and executes on a dedicated asynchronous queue. See [local extractor](app/Ai/Documents/LocalTextExtractor.php) and [document operations](docs/ai-documents-operations.md). |
| 20, 25 — Scheduled work | Actor-owned, explicitly opted-in low-stock read alerts can prepare a purchase clarification or pending proposal. Revision, expiry, cancellation, membership, branch, company caps and duplicate delivery are rechecked by workers. Scheduling never approves, posts or sends. Governed invoice posting is available only after human approval; models never gain financial authorization. See [scheduled runner](app/Ai/Scheduling/ScheduledRuleRunner.php) and [schedule routes](routes/ai-schedules.php). |
| 39 — Opening setup | Opening stock and financial balance planners accept typed sources or scanned CSV/XLSX, explicit company-record mappings and expected totals. The existing `OpeningImportService` performs a rollback dry-run; exact rows, mappings, source checksum and access are bound to the later approved proposal. Existing posted accounting or stock prevents opening migration. See [opening planner](app/Ai/Imports/OpeningImportPlanner.php), [source access](app/Ai/Imports/OpeningImportSourceAccess.php), and [document workspace](resources/views/backend/ai/documents.blade.php). |
| 41 — Clarification | Remove/undo controls submit actual boolean `_destroy: true` with stable line IDs and expected revision. A conflict reloads current details and requires another review. Final-line removal cannot promote an incomplete proposal. See [clarification builder](app/Ai/Services/ClarificationResponseBuilder.php) and [workspace](resources/views/backend/ai/workspace.blade.php). |

Integration checks exercise the real application registry and routes, rather than relying on test-only registration. Generic review cards now display the complete action preview, including journal lines and import mappings; completion labels use the actual ERP receipt rather than assuming every action placed a purchase order.

### Verification and operating limits

Current verification commands and results are recorded in the [implementation work log](docs/DRIFT_IMPLEMENTATION_2026-11-12.md). Automated database checks use only guarded disposable `zolo_test_*` schemas. Browser checks use rendered Blade fixtures and intercepted endpoints; provider streams, speech recognition, document services and mail are simulated. They do not establish real microphone, SMTP, Qdrant, Langfuse or production-worker readiness.

Final local verification passed **352 tests / 3,684 assertions**, with zero failures, errors or skips, plus **three browser checks**, PHP/JavaScript syntax checks, documentation links and `git diff --check`.

External services remain disabled until configured. Operators must deploy additive migrations, configure private storage and scanner/OCR binaries, provision reviewed external credentials and start the documented queues. No development/production migrations, real customer emails, paid model requests, package installation or deployment were performed in this completion pass. Production release gates, including the separate genuine-concurrency and live-model evaluations, are not approved by this drift audit.

---

## Original findings — historical snapshot, 2026-11-12

> **Report Date:** 2026-11-12  
> **Target Codebase:** `vigneshsinna/zolo-erp-pro`  
> **Working Branch:** `feature/ai-unified-input-clarification`  
> **Audit Framework:** Elephant-Goldfish Model (EGM) Drift Detection Specification  
> **Target Design Blueprints:** `docs/zoloerp-ai-agent-implementation/docs/ai/` (43 Architecture Documents)  
> **Automated Test Validation:** 132/132 Passed (906 assertions, 0 errors, 0 failures, 0 skipped) on `zolo_test_ai_release`

---

## 01_REPOSITORY_BASELINE.md
No drift detected.

## 02_OPEN_SOURCE_STACK.md
No drift detected.

## 03_ARCHITECTURE.md
No drift detected.

## 04_SECURITY_AND_TENANCY.md
No drift detected.

## 05_INSTALLATION.md
No drift detected.

## 06_DATABASE_SCHEMA.md
No drift detected.

## 07_LARAVEL_AI_SDK.md
No drift detected.

## 08_ERP_TOOL_REGISTRY.md
No drift detected.

## 09_FIRST_VERTICAL_SLICE.md
No drift detected.

## 10_AGENT_CATALOG.md
No drift detected.

## 11_MCP_INTEGRATION.md
- Model Context Protocol (MCP) server endpoints (`/mcp/*`) are absent from routing.
- External MCP client token authentication and allowlist filtering described in specification are not implemented.
- Note: This was planned as an intentional deferral in Section 7 ("MCP is a future interoperability surface, not a prerequisite for chat-driven purchases and sales inside Laravel").

## 12_APPROVAL_AND_ACTIONS.md
No drift detected.

## 13_API_AND_FRONTEND.md
- Server-Sent Events (SSE) streaming endpoint (`GET /api/v1/ai/chat/stream`) specified for real-time token delivery is absent; chat communication currently operates over standard synchronous JSON endpoints (`POST /api/v1/ai/chat`).
- Audio/voice input capture controls specified in interface requirements are not implemented in `workspace.blade.php`.

## 14_HORIZON_AND_QUEUES.md
- Dedicated AI queue worker pools (`ai-execution`, `ai-telemetry`) are not defined in `config/horizon.php`.
- Asynchronous proposal execution via background queue workers is not configured; action executions run synchronously within HTTP request cycles.

## 15_RAG_AND_QDRANT.md
- Qdrant vector database integration and document chunking pipeline are absent from `app/Ai`.
- System relies strictly on relational schema lookups via `ReadCompanyKnowledge` and `CompanyKnowledgeHandler`. (Explicitly marked optional/deferred in specification).

## 16_LANGFUSE_OBSERVABILITY.md
- Langfuse external telemetry sink adapter is absent.
- Distributed tracing and external LLM cost monitoring are not integrated; telemetry is recorded locally in MySQL (`ai_model_invocations`, `ai_tool_invocations`).

## 17_OPTIONAL_NEURON_OLLAMA.md
No drift detected.

## 18_TESTING_AND_EVALUATIONS.md
No drift detected.

## 19_CI_CD_AND_PRODUCTION.md
No drift detected.

## 20_DELIVERY_BACKLOG.md
- Deliverable **PB-001** (Goods Receipt Notes via `OrderLifecycleService::receivePurchase`): Not implemented.
- Deliverable **PB-002** (Supplier Bill Draft and GST Posting via `CommercialApplicationService::create('purchase')`): Not implemented.
- Deliverable **SL-001** (Customer Quotation Intake & Execution via `QuotationApplicationService`): Not implemented (`sales_order.create.v1` is implemented; `quotation.create.v1` is pending).
- Deliverable **SL-002** (Sales Tax Invoice Proposal & Post via `SalePostingService::post`): Not implemented.
- Deliverable **SL-003** (Payment Reminder Drafts and Outbound Communication): Not implemented.
- Deliverable **IV-001** (Warehouse Stock Transfers & Physical Stock Adjustments): Not implemented.
- Deliverable **FI-001** (Journal Entry Proposals & General Ledger Adjustments): Not implemented.
- Deliverable **MF-001** (Production Orders & Job Work Outward/Inward Processing): Not implemented.
- Deliverable **DC-001** (Document Intake, Quarantine Storage, and OCR Slot Extraction): Not implemented.
- Deliverable **BK-001** (Bulk Spreadsheet Import Planner for Products and Balances): Not implemented.
- Deliverable **AU-001** (Scheduled Low-Stock Read Alerts and Proactive Purchase Order Preparation): Not implemented.

## 21_ROLLOUT_AND_OPERATIONS.md
No drift detected.

## 22_DECISIONS_AND_ASSUMPTIONS.md
No drift detected.

## 23_CODING_AGENT_HANDOFF.md
No drift detected.

## 24_SOURCE_REFERENCES.md
No drift detected.

## 25_PRODUCT_VISION_AND_AUTONOMY.md
- Autonomous Level A5 (Scheduled Tasks & Proactive Background Agents) is not implemented. There are zero scheduled cron jobs or queue workers initiating autonomous ERP draft preparations without immediate user prompt triggers.
- Financial Posting (Autonomy Level A4) remains intentionally blocked; sales and purchase workflows stop at unposted orders (`purchase_order.create.v1`, `sales_order.create.v1`), with direct ledger-posting invoices deferred.

## 26_COMMAND_CENTER_UX.md
No drift detected.

## 27_INTENT_AND_RECORD_RESOLUTION.md
No drift detected.

## 28_ACTION_CAPABILITY_CATALOG.md
- The following action capabilities specified in the catalog remain unregistered in `ActionCapabilityRegistry`:
  - `quotation.create.v1`
  - `grn.create.v1`
  - `purchase_bill.create.v1`
  - `sales_invoice.create.v1`
  - `stock_transfer.create.v1`
  - `stock_adjust.create.v1`
  - `payment_receipt.create.v1`
  - `journal_entry.create.v1`

## 29_WORKFLOW_ORCHESTRATION.md
No drift detected.

## 30_PURCHASE_WORKFLOWS.md
- Workflow B (`grn.create.v1` - Goods Receipt Notes via `OrderLifecycleService::receivePurchase`): Not implemented. No adapter, schema, or tool exists for receiving goods against purchase orders.
- Workflow C (`purchase_bill.create.v1` - Supplier Bill Posting via `CommercialApplicationService::create('purchase')`): Not implemented. No adapter, schema, or tool exists for booking vendor invoices with AP ledger effects.

## 31_SALES_AND_COLLECTION_WORKFLOWS.md
- Workflow A (`quotation.create.v1` - Customer Quotation via `QuotationApplicationService`): Not implemented.
- Workflow B2 (Delivery Challan / Dispatch Movement): Not implemented.
- Workflow C (`sales_invoice.create.v1` - Sales Tax Invoice via `SalePostingService::post`): Not implemented.
- Workflow D (`payment_receipt.create.v1` - Customer Payment Settlement via `AccountingReceiptService`): Not implemented.
- Outbound customer payment reminder generation and dispatch: Not implemented.

## 32_INVENTORY_FINANCE_MANUFACTURING.md
- Inventory Stock Transfer action adapter (`stock_transfer.create.v1`) via `InventoryMovementService`: Not implemented.
- Physical Stock Adjustment action adapter (`stock_adjustment.create.v1`): Not implemented.
- Financial Journal Entry proposal adapter (`journal_entry.create.v1`): Not implemented.
- Production Order planning and completion adapter (`production_order.create.v1`): Not implemented.
- Job Work outward/inward processing adapter (`job_work.outward.v1`): Not implemented.

## 33_MASTERS_OTHER_MODULES.md
- Chart of Accounts AI creation adapter (`setup.account.create.v1`): Not implemented.
- Price List bulk adjustment adapter (`setup.pricelist.update.v1`): Not implemented.
- Bulk product import adapter from Excel/CSV (`ReviewedProductImport` AI adapter): Not implemented.

## 34_DOCUMENT_AND_BULK_ENTRY.md
- Authenticated file upload, quarantine storage, and virus scanning pipeline: Not implemented.
- PDF text extraction and OCR service for vendor invoices: Not implemented.
- Bulk spreadsheet dry-run validation engine and partial failure recovery: Not implemented.

## 35_SCENARIO_EVALUATION_CATALOG.md
No drift detected.

## 36_REPLACEMENT_MIGRATION_AND_RELEASE_GATES.md
No drift detected.

## 37_GUIDED_INITIAL_SETUP_AGENT.md
No drift detected.

## 38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md
No drift detected.

## 39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md
- Opening Stock Entry AI setup action: Not implemented (only manual ERP processes exist).
- Opening Financial Balance AI setup action: Not implemented.

## 40_PHASE1_ACTION_FOUNDATION_IMPLEMENTATION.md
No drift detected.

## 41_UNIFIED_INPUT_COLLECTION_AND_CLARIFICATION.md
- The interactive clarification card in `workspace.blade.php` does not yet render explicit line deletion controls (`_destroy: true`), although the backend contracts and schema support it.

## 42_DEPENDENT_MASTER_DATA_CREATION_AND_WORKFLOW_RESUMPTION.md
No drift detected.

