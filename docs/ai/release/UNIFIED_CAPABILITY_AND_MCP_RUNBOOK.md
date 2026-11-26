# zoloERP Pro — Unified Shared Capability Discovery & External MCP Governed Proposals Runbook

**Baseline Branch:** `feature/ai-unified-input-clarification`  
**Base HEAD SHA:** `505c68039281ff93232d6fad520dbfcdfa46f130` (working tree contains U1, U2, and Doc 03/04 updates)  
**Target Stack:** Laravel 13.40.0 / PHP 8.5.12 / MySQL 8.4 (`zolo_test_ai_release` verified)  
**Release Decision:** `NO-GO / READY FOR STAGING PILOT` (all unit and MySQL feature gates implemented and green locally; live staging k6 write concurrency, live Nemotron corpus evaluation, and named stakeholder sign-offs remain required before production activation).

---

## 1. Derived Capability Inventory & Coverage Summary

`App\Ai\Capabilities\CapabilityCatalog` derives 45 immutable descriptors directly from `AiToolManifest`, `ActionCapabilityRegistry`, `ActionRequirementsRegistry`, and `config/ai_capabilities.php`:

- **Total Derived Descriptors:** 45 (`15` read, `29` prepare, `1` status)
- **External MCP Exposed Capabilities (Default-Off behind `AI_MCP_ENABLED` + `AI_MCP_GOVERNED_PROPOSALS_ENABLED` + per-company `mcp_proposals_enabled`):**
  - `receivables.outstanding.v1` (`read`, alias `CustomerOutstanding`, owner `finance-ar`, ref `REV-READ-AR-V1`)
  - `inventory.stock_summary.v1` (`read`, alias `InventoryStockSummary`, owner `inventory`, ref `REV-READ-INV-V1`)
  - `purchase_order.create.v1` (`prepare`, alias `erp_purchase_order_prepare`, owner `purchasing`, ref `REV-U2-PO-PREP-V1`)
  - `proposal.status.v1` (`status`, alias `erp_proposal_status`, owner `platform-security`, ref `REV-U2-STATUS-V1`)
- **Automation Builder Exposed Capabilities:**
  - `inventory.stock_summary.v1` (`read`)
  - `purchase_order.create.v1` (`prepare` — draft preparation only; zero auto-approval or posting authority)
- **Intentionally Hidden / Excluded Capabilities (no `ActionRequirementsProvider` registered):**
  - `setup.branch.create.v1`
  - `setup.product.create.v1`
  - `setup.unit.create.v1`
  - `setup.warehouse.create.v1`
- **Explicitly Unsupported Out-of-Scope Actions:**
  - Uncataloged expense-only AP posting (`is_expense_bill`) remains fail-closed.
  - External MCP `approve`, `execute`, `approve_and_execute`, `reverse`, `send`, or arbitrary SQL/HTTP actions do not exist in the catalog and are rejected by `CapabilityIntegrityValidator`.

---

## 2. Feature Flag Matrix (All Default-Off / Fail-Closed)

| Flag / Setting | Scope | Default | Purpose |
|---|---|---|---|
| `AI_ENABLED` | Global `.env` | `false` | Master AI operating layer killswitch |
| `AI_ACTION_FOUNDATION_ENABLED` | Global `.env` | `false` | Enables draft proposal & task preparation |
| `AI_ACTION_EXECUTION_ENABLED` | Global `.env` | `false` | Enables execution of human-approved proposals |
| `AI_SHARED_CAPABILITY_DISCOVERY_ENABLED` | Global `.env` | `false` | Switches SDK, REST `/api/v1/ai/capabilities`, and Automation Builder onto `CapabilityCatalog` |
| `AI_MCP_ENABLED` | Global `.env` | `false` | Enables `/mcp/erp` JSON-RPC endpoint |
| `AI_MCP_GOVERNED_PROPOSALS_ENABLED` | Global `.env` | `false` | Enables `prepare` and `status` operations over MCP |
| `ai_feature_settings.enabled` | Per-company DB | `false` | Company-level AI opt-in |
| `ai_feature_settings.mcp_proposals_enabled` | Per-company DB | `false` | Company-level opt-in for external MCP proposal preparation & status |

---

## 3. MCP Client Binding Lifecycle (Issuance & Emergency Revocation)

### Issue Scoped Client Binding
Use `App\Ai\Infrastructure\McpClientBindingService::issue()`:
- Never issue wildcard (`*`) Sanctum token abilities; `McpClientGuard` unconditionally rejects `*`.
- Bind exact `actor_id`, `company_id`, `branch_id`, `financial_year_id`, `allowed_operations` (subset of `['read', 'prepare', 'status']`), `allowed_tools`, `allowed_actions`, and finite TTL.
- Legacy bindings without `allowed_operations` default strictly to `['read']`.

### Emergency Revocation & Rollback
1. **Immediate Global Stop:** Set `AI_MCP_GOVERNED_PROPOSALS_ENABLED=false` (and `AI_MCP_ENABLED=false` if stopping read-only MCP as well), or `AI_SHARED_CAPABILITY_DISCOVERY_ENABLED=false` to revert SDK/REST/Automation to legacy adapters.
2. **Per-Company Stop:** Set `ai_feature_settings.mcp_proposals_enabled = 0` for the affected `company_id`.
3. **Per-Client Revocation:** Call `McpClientBindingService::revoke($bindingId, $companyId, 'reason')` — marks `is_active = false`, sets `revoked_at`, and deletes the associated `personal_access_tokens` row inside a transaction.
4. **Data Preservation:** Never delete `ai_mcp_preparation_requests`, `ai_action_proposals`, `ai_action_approvals`, `ai_action_executions`, or posted ERP records.

---

## 4. Additive Database Migrations

Run additive migrations safely before enabling any new flags:
- `database/migrations/2026_11_25_000001_extend_ai_mcp_client_bindings_for_governed_proposals.php`
  - Extends `ai_mcp_client_bindings` (`client_identifier`, `allowed_operations`, `allowed_actions`, `revoked_at`, `revoked_reason`), `ai_feature_settings` (`mcp_proposals_enabled`), and `ai_action_proposals` (`source_channel`, `source_client_binding_id`).
- `database/migrations/2026_11_25_000002_create_ai_mcp_preparation_requests_table.php`
  - Creates `ai_mcp_preparation_requests` with unique deduplication scope index `ai_mcp_prep_req_scope_unique`.

---

## 5. Gate G0–G6 Verification Status & Evidence

| Gate | Scope | Local Evidence & Exact Command | Status |
|---|---|---|---|
| **G0** | Baseline, FY textile fix, DesignTokens | `php vendor/phpunit/phpunit/phpunit -c phpunit.operations.xml --filter=test_dyeing_unit_processes_customer_material_without_owning_it_and_bills_only_the_charge` (1/1, 14 assertions); `php vendor/phpunit/phpunit/phpunit tests/Unit/DesignTokensTest.php` (4/4, 4 assertions) | **Passed locally** (full CI matrix pending remote runner) |
| **G1** | U1 (D01–D16) & U2 (M01–M20) discovery, MCP prepare/status | `CapabilityCatalogAndSchemaTest` (5/5, 34 assertions); `SharedCapabilityDiscoveryTest` (3/3, 29 assertions); `McpGovernedProposalsTest` (3/3, 89 assertions); `McpAndAsyncInfrastructureTest` (12/12, 52 assertions) | **Passed on MySQL 8.4** (`zolo_test_ai_release`) |
| **G2** | Business correctness, PO parity, workflow evidence (A22) | `SharedCapabilityDiscoveryTest` (legacy vs shared hash/preview/posting parity); `WorkflowEvidenceHardeningTest` (2/2, 3 assertions); `BusinessWorkflowStateMachineTest` (6/6, 21 assertions) | **Passed on MySQL 8.4** (Finance/Ops owner sign-off pending) |
| **G3** | Multi-tenant, branch/FY/actor isolation, replay, security | `SecurityAndConcurrencyHardeningTest` (6/6, 31 assertions); `McpGovernedProposalsTest` (M03–M07, M11–M16, M19–M20) | **Passed on MySQL 8.4** (Security sign-off pending) |
| **G4** | Live model corpus (100 bills / 100 tasks / 50 edge) & metering | Deterministic zero-credit invariants verified in `SharedCapabilityDiscoveryTest` (D14) and `McpGovernedProposalsTest` (M20) | **Pending live staging Nemotron run** |
| **G5** | Staging 5/10/20-VU write concurrency & SLOs | `tests/k6/ai_business_workflow_concurrency.js` exists; requires live staging target | **Pending staging k6 execution** |
| **G6** | UI/UAT, feature flags, runbooks & rollout | `ProductionReadinessSignoffTest` (5/5, 34 assertions); Action Review Center MCP Draft badge verified against `DesignTokensTest` | **Flags verified default-off; stakeholder sign-offs pending** |
