# 15 — RAG and Document Knowledge Retrieval (Optional)

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Hard distinction
Use relational ERP services for exact invoices, balances, orders, stock, cost, taxes and user permissions. **Do not use RAG embeddings as a financial database.** RAG answers natural-language questions about approved SOPs, user guides, product usage notes and policy manuals. Supplier invoices are processed as structured documents, not casually embedded with broad retrieval.

## Retrieval architecture
`ACL-approved upload → quarantine/scan → classify/redact → text extraction → chunk + version → embedding → private Qdrant payload with tenant/company/document ACL → search with server-owned filters → relational permission recheck → short cited answer`. A document instruction saying “ignore permissions” must be treated as inert text. Only approved MIME/size, retention and classification are allowed.

## Index metadata
`company_id`, `document_id`, `source_version`, `acl_version`, `classification`, `allowed roles/users`, `created_by`, `page_or_section`, `chunk_id`, `checksum`, `embedding_model`. Do not let the model supply tenant filters or arbitrary document URLs. Recheck access after vector search because ACLs may be revoked faster than index deletion.

## Product workflows
AI can cite an SOP when guiding a user through a return or purchasing process, but the authoritative allowed operation and fields still come from Laravel capabilities and domain services. Retrieved documents may inform explanations and form help, never override statutory GST or actual stock postings.

## Deployment
Qdrant is not required for Copilot stabilization or first purchase-order action. Prefer private/dedicated hosting, TLS, backups and per-company deletion. Check embedding provider privacy/region and model consistency. Do not place private vectors on a shared VPS without a capacity/security review.

## Tests
Cross-tenant filter bypass, revoked/delete document, injected SOP, OCR uncertainty, ambiguous document versions, pagination, recall/precision benchmark and correct page citations. Feature remains disabled until all pass.
