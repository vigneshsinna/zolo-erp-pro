# 06 — Shared AI capabilities, optical Copilot, MCP and automation

## Baseline

U1 and U2 are implemented in the requested branch. `CapabilityCatalog`, `CapabilityDescriptor`, `CapabilitySchemaCompiler`, `CapabilityIntegrityValidator`, `CapabilityExposurePolicy`, `CapabilityDispatcher`, `GenericPrepareSdkTool`, `ActionPreparationService` and `ProposalStatusReader` exist. MCP is scoped via `McpClientGuard`, `McpClientBindingService`, `McpClientGrant`, `McpPreparationDeduplicator`, `McpController`; existing endpoint `/mcp/erp`. Runbook reports 45 derived descriptors (15 read/29 prepare/1 status), and external PO preparation plus status and two reads. Reuse and extend rather than repeating the earlier shared discovery/MCP PRs. Production activation is not established.

## 1. One capability declaration, multiple channels

| Capability / task | Existing anchor | Proposed delta |
|---|---|---|
| Read stock | `inventory.stock_summary.v1`, `ErpToolExecutor`, `AiToolManifest` | Scoped optical identity/power/store query; add typed read capability only if needed |
| Read dealer outstanding | `receivables.outstanding.v1` | Keep; scope to authorized dealer, avoid clinical data |
| Read lens candidates | `RecordResolutionService`, `ProductQueryService` | Exact family/specification search; reviewed read handler and schema |
| PO preparation | `purchase_order.create.v1` | Optical fragment, direct PHP / shared catalog / existing MCP alias parity |
| Purchase bill | `purchase_bill.create.v1` | Direct/linked route, receipt status, optical fields and no missing-source fallback |
| GRN | `grn.create.v1` | Exact stock dimensions, source and actual receipt |
| Service bill | `purchase_bill.service.create.v1` | Flag, reviewed nonstock path; no unsupported expense bypass |
| Sales order | `sales_order.create.v1` | Optical snapshot, exact reservation, credit and partial fulfillment |
| Invoice | `sales_invoice.create.v1` | Direct or source-based; optical fields and charges, domain parity |
| Quotation/DC | `quotation.create.v1`, `delivery_challan.create.v1` | Optical data round-trip and verified dispatch |
| Receipt | `payment_receipt.create.v1` | Actual settlement / appropriate advance if domain supports |
| Transfer | `stock_transfer.create.v1` | Exact lens and approved cross-store policy; separate dispatch/receipt if needed |
| Job work | Existing registered job/principal job keys | Profile gated, ownership and evidence checks |
| New product | `setup.product.create.v1` exists, excluded from shared discovery due to requirements gap | Implement typed `ActionRequirementsProviderInterface`; approved optical master only |
| New unit/warehouse/branch | Registered setup actions, requirements gap in runbook | Complete only needed providers; privileged administration, separate approval |
| Customer/supplier | Existing setup actions | Verify guided collection, duplicate/registration handling, no fake master IDs |
| Return / reversal / supplier payment / lab actions | Existing domain service where supported; verify AI registration | Add narrowly reviewed action descriptor, requirements/preview/command only where actual capability absent |

All suggested new action keys are candidates, not permissions. Recompute catalog coverage automatically. Keep `AiToolManifest` read-only and `ErpToolExecutor` read guard. All prepare paths use `ActionPreparationService` → proposal and review. No domain execution as a generic tool. No dynamic method dispatch, generic SQL or unrestricted JSON patch. Knowledge metadata not sufficient authorization. Validate alias uniqueness, channel permissions, schemas and source refs at boot and in CI.

## 2. Optical schema and deterministic routing

Existing `CreatePurchaseOrderInputSchema`, `CreatePurchaseBillInputSchema`, `CreateSalesOrderInputSchema`, `CommercialDocumentInputSchema` omit lens and optical header fields. Add shared conditional schema fragment as described in files 03–04, not copied rule sets. Compiler must faithfully expose nested arrays/object properties, closed schemas, limits, types, nullable, enums, required-if rules and documented constraints; authoritative Laravel validation remains mandatory. Test `CapabilitySchemaCompiler` output against actual requirements/provider and command contract. Never quietly drop a field during normalization; reject unsupported field/action/profile. Canonical decimal strings (validated server side) preserve powers, exact money and approved units. Prohibit actor/company/branch/FY authority anywhere in nested input, except legitimate business references resolved against scope; no caller-supplied principal. Capture raw source candidate and confirmed value with provenance; sensitive payload redacted in logs.

`ConversationTurnRouter` and existing deterministic interpreter first recognize exact commands and pending task corrections. Route only when verified. Supplier vs customer, stock sale vs lab job, PO vs direct bill, invoice vs payment, warehouse vs branch and missing source document are explicit distinctions. Use `ApprovedModelGateway` only for free-form ambiguity or unstructured OCR/audio/semantic extraction. Do not spend NVIDIA credits for stock counts, matching, tax math, unit conversion, totals, exact search, known document transitions or UI selection. Existing meter controls every actual provider attempt. No silent fallback to another model without approved gateway policy. If model fails/timeouts, show missing input or manual correction, not a fabricated action. Model-supplied IDs are candidates checked against live ERP. Bound model/tool rounds and output; never allow document text to invoke tools. Task revisions and line IDs preserve confirmed unrelated fields across corrections. New dependent master separate proposal; once posted, re-resolve parent and preview again.

## 3. User experience: multi-store lens supplier

Design within existing Copilot workspace, Action Review Center and ERP pages. No duplicate chatbot or independent industry agent. Reuse existing routes/auth/CSRF and design tokens. On company scope switch clear unsafe cached candidates and pending UI, resume only authorized original task. All errors actionable and sanitized.

### Main UI panels

- Chat input: text, invoice PDF/image, voice, bulk lens matrix CSV/Excel where supported. Show source type, extraction status, model name only for real calls, credit used. Attachments remain untrusted; preview before preparation. Validate size/type, scan and store privately.
- Request classification card: direct purchase / PO / GRN-linked bill / direct sales invoice / SO / DC-linked invoice / lab job. Show chosen route and reason, let user change type. Never insist on PO/quotation when direct route applicable.
- Inline fields: dealer/supplier, branch/warehouse, receipt/dispatch status, customer order number, rate and unit, bill date/number, GST treatment and source document only if relevant. Consolidate missing questions, show optional fields, no repeated questions for confirmed facts. Explicitly clarify if "10 lenses" means pieces or pairs.
- Optical line grid: stable row ID, selected SKU, family, power fields and eye/diameter/coating where applicable, stock, UOM, rate basis, qty, discount/charge, tax/total; show candidate differences in a dropdown. Bulk paste multiple powers, inline validation, preserve signs. Hide Rx fields for non-optical profile or ordinary stock family where not applicable.
- Stock finder panel: authorized stores, available/reserved/hold/in-transit and as-of; explicit transfer draft vs procure vs backorder. No background unauthorized branch switch. Show pending physical step separately from financial status.
- Proposal card: exact source, preview totals, tax breakdown, expected stock and ledger effects, warnings, versions, changed fields, expiry. Edit/reject causes new preview and approval. Approval and execute buttons remain existing protected mechanism; no chat phrase interpreted as approval.
- Workflow timeline: current action, dependencies, next missing event, partial quantities, source records, actual receipt/reconciliation, pause/resume/cancel/replan. A verified receipt cannot be reused for unrelated workflow; reversed receipt invalidates dependent evidence. Do not label draft as completed. Official lab slip/packing/invoice only from persisted authorized records.

## 4. External MCP extensions — staged, default off

Keep U2 guard and dedup unchanged in spirit. Add capabilities only via reviewed `config/ai_capabilities.php` and explicit per-client bindings; no broad wildcard. Proposed exposure order: existing PO/status/read, then optical lookup and scoped stock, then purchase bill, sales order and invoice prepare only after each passes tests, then other eligible operations. User must review proposals inside authenticated zoloERP; MCP must never approve, execute, reverse, post, send messages, or change settings. No clinical/wearer data in external output by default. Sensitive optical job specs require separately reviewed business purpose, minimized fields and explicit permissions; do not grant simply because ordinary stock lookup allowed.

`tools/list` and `tools/call` share `CapabilityExposurePolicy` and live authorization. Token binding exact company/branch/FY/actor and client id, operations and action-specific grants, TTL, origin and request limits. Revocation effective for subsequent calls; human approval/execution authorization rechecked independently. Dedup uses binding/scope/action/client_request_id and canonical payload hash, task revision where relevant. Same key+same payload returns prior draft/status; different payload 409, no duplicate draft or posting. Protocol errors sanitized; do not reveal foreign existence. MCP response `proposal_id`, version, `status=awaiting_approval` or needs_input, safe review URL and no official document number until verified execution. Use existing proposal status reader and receipt scoping, not arbitrary record access. Audit source channel/binding and trace id; no token/Rx/contact dumps. Reverify external no-approval/no-execute test with flags on and malicious nested input. Global kill switch and company switch govern all exposure including already queued tasks.

## 5. Automation Builder and performance monitoring

Define deterministic triggers such as low exact-power stock, unfilled dealer orders, overdue PO, GRN awaiting bill, dispatched unbilled lenses, approved lab job past due and overdue receivables. Scheduled checks use existing scheduling rules, permission and bounded query, per-company timezone, idempotency window. Rule result is a suggestion or draft proposal, no unconditional execution. Never auto-transfer, create purchase, invoice, settle, send message or order custom Rx merely due to trigger. Show source event and rule version; stop/revoke prevents future preparation, not retroactive deletion. Scope automation to authorized stores; no per-row model calls. Any outbound notice needs separate reviewed send action, recipient, permission and consent.

Metrics: separate total user requests, deterministic successes, actual model invocations/attempts, tool calls, credit debits/refunds, provider RPM, p50/p95/p99 latency, workflow completion, genuine execution receipts, exceptions, stale approvals, duplicate prevention, optical exact-match accuracy, cost per completed workflow and branch stockout/fill metrics. Count real receipts, not “completed” model text. Source channel and workflow ID correlation, not entire actor's unrelated actions. Do not invent accuracy or savings; use labeled evaluation and measured baseline. No PII in aggregate telemetry or provider trace. Admin/finance permission for financial metrics.

## 6. Acceptance

- One descriptor/validation contract for SDK/REST/automation/MCP allowed interfaces; same canonical payload and domain preview for equivalent inputs. Legacy direct PHP path remains controlled; if feature off, no bypass into newly gated optical behavior.
- Discovery excludes wrong company/role/profile/flag; calls independently revalidate. All new schema fields round-trip for PO/bill/SO/invoice and source lines, or explicit unsupported. Non-optical legacy outputs unchanged.
- 20-line mixed-power purchase or sale uses bounded batch lookups; exact command zero model invocations/credits. Ambiguous or image prompt only uses metered model when necessary.
- Changed power, eye, unit, price, tax, warehouse or source requires new proposal version. Missing product creates independent approved master, no hidden records.
- MCP preparation replays, expired/revoked tokens, stale revision, malicious nested authority, foreign task, oversized body, unknown aliases all safe; no financial or stock posting from MCP. No clinical/PII leakage in tools/list, result, audit or status.
- Feature enabled browser UAT for keyboard, mobile, power grid, corrections, branch switch, errors, previews and approved real document retrieval. Use existing UI not a separate platform.
