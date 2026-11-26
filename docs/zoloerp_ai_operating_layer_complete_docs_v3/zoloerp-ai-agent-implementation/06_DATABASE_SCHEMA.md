# 06 — Canonical AI, Task, Proposal and Audit Data Model

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Schema principles
Do not recreate Laravel AI SDK-owned `agent_conversation*` tables. Keep application-owned ownership mapping and audit/usage tables additive. Match actual `users/companies/branches/fiscal_years` key types; use real migrations and data contracts, not illustrative IDs. Define relationships, retention and indexes before migration. Every data-bearing row contains tenant scope; authorization remains at query and API boundaries.

## Existing baseline tables (inspect actual migrations; don't recreate)
| Existing logical table | Purpose | Security requirement |
|---|---|---|
| `ai_thread_scopes` | User-owned conversation, active company/branch/FY | Scoped retrieval and replay revocation |
| `ai_runs` | Run lifecycle, model, usage, outcome | Correlation and recoverable states |
| `ai_tool_invocations` | Tool name, decision, error, timing | Redacted inputs and bounded metadata |
| `ai_usage_counters`, model-invocation log | RPM, daily/user/company quotas and provider attempts | Atomic accounting, no raw content |
| `ai_feature_settings` | Company enablement and budgets | Fail-closed and operator-controlled |
| `ai_thread_entities` | Confirmed entity context for follow-up | Actor/FY/branch verification and expiry |
| `ai_action_proposals`, approvals/executions | Historical/quarantined data from earlier branch | Preserve history; do not revive legacy write logic |

## New logical tables for *future* action workflow
| Table | Essential fields | Constraints |
|---|---|---|
| `ai_tasks` | id UUID, actor, company, branch, FY, intent, workflow version, state, priority, expires_at | Scoped owner index; unique correlation ID; terminal states immutable |
| `ai_task_slots` | task_id, field key, typed value encrypted if sensitive, source, validation status, version | No unrestricted payload; explicit whitelist/size; provenance |
| `ai_task_events` | id, task_id, sequence, state_from/to, actor/system source, event, occurred_at | Append-only, unique task+sequence, no full PII |
| `ai_entity_resolutions` | task_id, entity kind, candidate IDs, selected ID, source and verification | Tenant ownership and stale-candidate checks |
| `ai_action_definitions` | **Prefer code registry, not arbitrary DB-executable classes** | Database may hold enable/disable config only |
| `ai_action_proposals_v2` | id, task_id, action key/version, scope, encrypted immutable command payload, payload hash, source-state hash, expiry, status | Unique task/action/version, sealed approved content |
| `ai_action_approvals_v2` | proposal_id, approver, decision, exact payload hash, approved_at, reason, policy version | Immutable decision, explicit approval gesture |
| `ai_action_executions_v2` | proposal_id, command key, idempotency key, state, attempt count, started/completed, error category | Unique company+action+idempotency, atomic claim |
| `ai_action_receipts_v2` | execution_id, ERP resource type/id, operation, posted_state, committed_at, service reference | Immutable business receipt; exact one per successful side effect |
| `ai_document_ingestions` | task_id, storage reference, safe MIME, checksum, quarantined status, extraction version | ACL, virus scan, retention and reprocess idempotency |

**Migration note:** the `_v2` names above indicate logical separation from the unsafe legacy proposal implementation, not a mandatory physical suffix. Before implementation, choose names based on real current schema and plan a safe migration preserving all old audit records. Never overwrite existing approval meanings.

## Task state model
`created → interpreting → awaiting_clarification → ready_for_preview → awaiting_approval → executing → completed`; errors transition to `failed`, cancellation to `cancelled`, expiry to `expired`, changed data to `requires_revalidation`. `executing` is never entered by a model alone. Event logging uses monotonic sequence and idempotent consumers.

## Referential and concurrency requirements
- Scope: composite indexes by `(company_id, branch_id, actor_id, status, updated_at)` where appropriate; foreign ownership checks.
- Optimistic `version` for task edits; optimistic or row lock for proposal approve/execute state transition.
- Proposal payload encrypted and hashed; approval binds exact hash and source record version, not transient UI state.
- Unique business idempotency on command invocation; successful ERP receipt authoritative for retries.
- No long DB transaction during LLM calls, uploads, OCR, external supplier communication or model retries.
- Conversation cleanup must not destroy mandatory business audit/financial records.

## Migration tests
Clean MySQL 8.0/8.4; schema upgrade from snapshot; rollback of additive AI migrations on disposable DB; row-level ownership rejection; races (two approvals, retry after success); failed/out-of-order job recovery; retention purge with ledger records intact; secrets absent from logs and dump.


---

## V3: onboarding task storage and setup readiness
Add a dedicated task kind `erp.initial_setup.v1` with an explicit **bootstrap principal** that has actor, allowed group ID (when applicable), installation-admin eligibility, task version, validated setup slots and step receipts. Company/branch/FY IDs are **nullable only before creation**; once creation succeeds, task must store verified returned IDs and enter the normal scoped context. Never allow model-provided IDs to become ownership authority.

Proposed additional logical persistence (name after inspecting existing AI tables): `ai_setup_tasks` (actor, group selection, pre-create task version/status), `ai_setup_steps` (scope, key, state, input version/hash, domain receipt, errors, dependencies), `ai_setup_readiness_checks` (required/optional blockers and last checked time), `ai_setup_import_batches` (company-scoped checked rows with per-row status, no raw secrets). Keep existing `company_setup_audits` as authoritative business audit and reference these records; **do not duplicate** completed company creation by replaying a partially failed task. Exact company code and installation-issued idempotency key must be unique at server policy level.

For existing document edits, persist immutable proposal snapshot, expected document status/version, source-linked dependencies, prior approval hash, transition operation and committed receipt. The executor rejects any status/version mismatch and routes corrections through domain-specific approved reversal/amendment commands.
