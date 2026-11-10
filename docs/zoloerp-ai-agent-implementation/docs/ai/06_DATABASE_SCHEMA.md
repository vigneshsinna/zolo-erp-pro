# 06 — AI database schema and migration design

## Integration with Laravel AI SDK storage

Laravel AI SDK's published migrations (current docs) provide `agent_conversations` and `agent_conversation_messages`. Treat these as vendor-owned and use an **application-owned ownership table**; do not add a parallel full transcript store by default. Inspect the published schema and actual ID types before adding foreign keys. UUID/ULID recommendations below are **proposals**, not claims about existing database columns.

## Tables — foundation (PH0/PH1)

| Table | Important fields | Constraints, indexes and purpose |
|---|---|---|
| `ai_thread_scopes` | `id` UUID, `sdk_conversation_id` (match vendor ID type), `user_id`, `company_id`, `branch_id`, `financial_year_id`, `title`, `status`, `expires_at`, timestamps | Unique SDK ID; index `(company_id,user_id,updated_at)`; enforce ownership on every history request |
| `ai_runs` | `id` UUID, `thread_id`, `actor_id`, `company_id`, `branch_id`, `financial_year_id`, `agent_key`, `model_provider`, `model_key`, `prompt_version`, `status`, `request_hash`, `started_at`, `completed_at`, `error_code`, `input_tokens`, `output_tokens`, `estimated_cost_minor`, `trace_id` | Index `(company_id,created_at)`, `(actor_id,created_at)`, `(status,created_at)`; no raw sensitive prompts by default |
| `ai_tool_invocations` | `id` UUID, `run_id`, `tool_key`, `schema_version`, `scope_hash`, `input_redacted`, `output_ref`, `authorization_decision`, `status`, `duration_ms`, `error_code`, timestamps | Index `(run_id,created_at)`; redact values and cap JSON sizes |
| `ai_usage_counters` | `company_id`, `user_id`, `date`, `request_count`, `input_tokens`, `output_tokens`, `estimated_cost_minor` | Unique `(company_id,user_id,date)` and aggregated per-company usage; atomic increments |
| `ai_feature_settings` | `company_id`, `enabled`, `read_only`, `provider_key`, `allowed_agent_keys` JSON, `daily_token_limit`, `monthly_budget_minor`, `retention_days`, `updated_by` | Unique company ID; model credentials never stored in plain JSON |
| `ai_feedback` | `id`, `run_id`, `actor_id`, `rating`, `reason_code`, `comments_redacted`, timestamps | Index run/actor; no auto-promotion of model output into business facts |

## Tables — approval phase (PH2)

| Table | Fields | Rules |
|---|---|---|
| `ai_action_proposals` | UUID, `company_id`,`branch_id`,`financial_year_id`,`created_by`,`agent_run_id`,`action_key`,`schema_version`,`payload_encrypted`,`payload_hash`,`business_snapshot_hash`,`status`,`expires_at`,`approved_by`,`executed_at`,`result_ref` | No editable proposal after approval; unique idempotency key per company+action; `status` transitions enforced atomically |
| `ai_action_approvals` | UUID, `proposal_id`, `approver_id`, decision, reason, timestamp, `context_version` | Auditable, immutable decisions; require separate explicit approve/reject request |
| `ai_action_receipts` | UUID, `proposal_id`, `erp_entity_type`,`erp_entity_id`,`idempotency_key`,`outcome`,`executed_at` | Prevent duplicate execution under retries; ERP service receipt is source of truth |

## Tables — knowledge phase (PH3)

| Table | Fields | Rules |
|---|---|---|
| `ai_knowledge_documents` | UUID, tenant/company owner, title, document type, storage key, checksum, status, ACL policy/version, created_by, retention/deleted_at | Only allow document types approved by privacy policy; storage must not be web-public |
| `ai_knowledge_chunks` | UUID, document UUID, chunk ordinal, vector ID, embedding model/version, checksum, ACL snapshot/version | Index `(document_id,ordinal)`; delete vectors on document revocation/deletion and verify eventual deletion |
| `ai_document_ingestions` | UUID, document UUID, run status, started/finished, error category, processed count | Idempotent ingestion, retries and quarantine |

## Constraints and migration rules

- Use the same integer types and foreign key conventions as actual `companies`, `company_branches`, `fiscal_years` and `users`. Existing SQL uses `company_user` membership and `company_user_branches`; design with their real key shapes after schema inspection.
- Every `ai_*` table that contains company data has a company column and appropriate composite indexes. Never depend solely on implicit Eloquent global scopes.
- Do not store model-provider API keys in database. Do not store full tool payloads by default; prefer hashes, record references and redacted summaries.
- Use FK/unique constraints for immutable receipts and proposal mapping; transactions plus row locks on approval/execution state changes.
- Add bounded JSON field sizes and application-side validation. Long conversation history retention is governed by data policy; use per-company TTL and export/delete operations.
- `ai_run` should distinguish `queued`, `running`, `completed`, `failed`, `cancelled`, `approval_required` and `expired` (adjust for actual workflow).
- `ai_tool_invocations` should capture `denied` distinctly from provider or domain failures.
- Do not use provider conversation ID as an unverified access token. Resolve `thread_id -> sdk_conversation_id` only after server-side user/company authorization.

## Example schema blueprint — intentionally illustrative

```php
Schema::create('ai_runs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('thread_id')->nullable();
    // Match actual user / company / branch / FY PK types after inspection.
    $table->unsignedBigInteger('actor_id');
    $table->unsignedBigInteger('company_id');
    $table->unsignedBigInteger('branch_id');
    $table->unsignedBigInteger('financial_year_id');
    $table->string('agent_key', 100);
    $table->string('model_provider', 80)->nullable();
    $table->string('model_key', 120)->nullable();
    $table->string('status', 32);
    $table->string('error_code', 80)->nullable();
    $table->timestamps();
    $table->index(['company_id', 'created_at']);
    $table->index(['actor_id', 'created_at']);
});
```

## Migration test requirements

1. Run application + module migrations on disposable MySQL 8.0 and 8.4; no pending migrations.
2. Repeat migration commands; no changes to existing ERP documents, ledger, stock or permission rows.
3. Race two approval attempts; exactly one action receipt.
4. Delete an AI thread; SDK history and application ownership cleanup follow retention policy without touching ERP business records.
5. Backfill/upgrade schema from previous AI schema version on a snapshot; preserve tenant filters and uniqueness.
