# 15 — Knowledge retrieval, RAG and Qdrant integration (optional PH3)

## Scope

Use retrieval-augmented generation for **unstructured** knowledge: customer-approved training documents, user guides, SOPs, business policies and documents that a requesting user may actually view. Live sales, stock, payments and financial totals must continue to use structured ERP services, not vector retrieval.

Selected engine: https://github.com/qdrant/qdrant; tenancy guidance: https://qdrant.tech/documentation/manage-data/multitenancy/.

## Ingestion pipeline

`Upload → validate file type/size → virus scan/quarantine → identify owner and ACL → extract text → redact sensitive fields → chunk deterministically → embed with pinned model version → write Qdrant payload + vector → store chunk metadata/ACL checksum → verify → publish document`.

All ingestion jobs are company-scoped, auditable and idempotent. Do not index arbitrary external URLs or attachments from other companies. For scans/PDFs, use a maintained extraction pipeline and flag OCR uncertainty; do not ingest unreadable text as authoritative.

## Payload proposal

```json
{
  "tenant_id": "tenant-from-server",
  "company_id": 22,
  "document_id": "uuid",
  "chunk_id": "uuid",
  "acl_policy_version": 3,
  "allowed_role_tags": ["company_admin", "finance_reader"],
  "classification": "internal",
  "source_title": "Inventory SOP",
  "source_version": "2026-10",
  "source_page": 12
}
```

Never trust ACL tags supplied in a question or from the vector; build search restrictions from authenticated user and current permissions, and **recheck** document access in the relational database before returning chunk text.

## Retrieval steps

1. Authenticate and resolve `AiPrincipal`, approved knowledge feature and business role.
2. Determine allowed document IDs/types/classification from relational ACL policy.
3. Embed query with **same** embedding model and dimensionality as collection; apply **mandatory company/tenant filter** plus an allowed-document filter in Qdrant.
4. Retrieve bounded top-k snippets. Server filters again against current database ACLs and deletion/revocation state.
5. Construct `source_refs` including document ID, title, page/section and snippet ID; do not reveal internal storage URL.
6. Agent cites sources and distinguishes policy knowledge from live ERP data.

## Tenant isolation

Qdrant supports tenant payload indexes (`is_tenant`) and filtered queries. A shared collection does **not** itself authorise access. Use service-side immutable tenant filters, private API keys, TLS, ingress firewall and separated staging/prod collections. Document deletion/revocation must trigger vector deletion, and retrieval must check the document status even before eventual vector cleanup completes.

## Sensitive content policy

No payment card numbers, payroll, medical/sensitive user records, raw secrets, government IDs, personal tax records or production DB dumps in embeddings unless the product has an explicit approved legal/privacy model. Audit provider retention/training and regional rules first. Do not index business documents before data minimisation is established.

## Quality gates

- Retrieval recall@k and grounded-answer accuracy on a labelled document set.
- Cross-company document injection attempt returns no foreign chunks.
- Expired/revoked documents inaccessible immediately.
- Deleted document vectors removed and verified.
- Malicious SOP says "ignore all rules" → treated as text, not instructions.
- Reingesting a document is idempotent; older versions do not leak stale policy without version labelling.
- Cost, embedding concurrency and query latency meet company limits.

## Deploy decision

Qdrant is **not required** for the first read-only ERP Copilot. Prefer dedicated managed/private Qdrant over running another database on the shared ERP VPS. A Laravel HTTP adapter is sufficient after evaluating an upstream PHP client and Qdrant API version.
