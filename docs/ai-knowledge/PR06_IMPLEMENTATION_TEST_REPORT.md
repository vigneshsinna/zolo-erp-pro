# PR6 — Governed job-work order and billing capabilities

Stacked on PR5; AI_BUSINESS_JOB_WORK_ENABLED defaults false.

## Implemented
- JobWorkDirectionClassifier: distinguishes material owned by our company vs customer; ambiguous requests remain clarifications.
- ActionInputSchemas: job-work order, job-worker processing service bill, and customer-owned principal-job service invoice.
- JobWorkOrderActionAdapter -> `JobWorkService::order` (supplier/process preflight, domain-only external location creation, separate approval, source-state, operation_requests retry recovery).
- JobWorkServiceBillActionAdapter -> `JobWorkService::serviceBill` (requires posted unbilled current-FY receipt; linked active dispatch/order; active service-only item; supplier invoice; CommercialPricing tax preview; charge mode; source hash; idempotency receipt). `JobWorkCosting` remains the costing authority.
- PrincipalJobInvoiceActionAdapter -> `PrincipalJobService::bill`, with explicit FY-owned principal-job order, actual returned-unbilled quantity, CommercialPricing tax/total preview and idempotency recovery. No stock created for customer-owned material.
- Additive principal_job_orders FY migration; unambiguous historical orders backfilled and new orders stamped; ambiguous historical entries remain unusable by AI.
- BusinessWorkflowProgress links each step to actual completed AI execution receipts; physical return verification requires the posted GRN from that approved workflow, linked to the completed dispatch. No chat-only proof.
- Draft outward/inward workflow definitions updated. Inbound customer job order, receipt and return remain unsupported AI steps; process is not startable until reviewed contracts exist.

## Tests added
- JobWorkBusinessKnowledgeTest covers ownership ambiguity, default-off capabilities, explicit approvals, and strict billing schema.
- CI must run AI, manufacturing job-work, job-work return/rework, textile ledger and non-GST suites.

## Release blockers
- Domain owner signoff is required on all job-work accounting and tax proposals; definitions remain draft.
- Need full MySQL fixtures for receipt/reversal/partial quantities, duplicate invoice and concurrency, plus live supplier/customer service invoice acceptance.
- Direct principal-job invoice capability is safe default-off and requires reviewed FY migration; in-flight unreviewed orders cannot be inferred.
- This PR adds adapters, not a completed autonomous workflow or a permission to skip individual approvals.

## Rollback
- Turn off AI_BUSINESS_JOB_WORK_ENABLED and AI_BUSINESS_WORKFLOW_PLANNER_ENABLED. Preserve posted domain records and durable audit events.
