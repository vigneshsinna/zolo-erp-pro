# AI documents and reviewed imports

These features are disabled until `AI_DOCUMENTS_ENABLED=true`. Enable them only after configuring the private storage, scanner, local extractors and a document worker. This change does not start services, modify `.env`, run production migrations, call a paid provider or post ERP records.

## Storage and extraction

Uploads require an authenticated, active company member with `ai.use`. Documents are bound to their uploader, company, branch and financial year. Bytes and extracted content are encrypted. Access expires after the configured retention period and revoked documents immediately become unavailable. Store backups and the application encryption key under the existing ERP privacy policy.

Configure absolute executable paths with:

- `AI_CLAMAV_BINARY`: the ClamAV scanner. A missing scanner, timeout or nonzero scan result leaves the file quarantined.
- `AI_PDFINFO_BINARY` and `AI_PDFTOTEXT_BINARY`: PDF page inspection and text extraction.
- `AI_PDFTOPPM_BINARY`: Poppler page rasterization for scanned PDF pages.
- `AI_TESSERACT_BINARY`: local OCR for images and scanned PDF pages.

Only operator configuration chooses executable paths. No request or model argument can choose commands. The extractor caps PDF pages, raster dimensions, image pixels, process duration and text output. Temporary plaintext and page images use private files and are removed after processing. Blank PDF pages retain their physical positions so later page citations remain correct. OCR output always requires review.

Use the `ai-documents` queue on the connection selected by `AI_QUEUE_CONNECTION` for scanning and extraction. The processing endpoint rejects synchronous or unsupported connections rather than running OCR inside the HTTP request. The worker must use the same application key and private storage as the web application. A supplier invoice remains untrusted evidence. Extracted hints cannot authorize posting, create a supplier, or determine an authoritative tax or ledger balance.

Run `php artisan queue:work redis --queue=ai-documents --tries=1 --timeout=60`. The job has one attempt and a 60-second timeout, below the default 90-second queue visibility timeout. Oversized or slow documents require review and a fresh processing request; they are not retried automatically.

## Approved document knowledge

Set `AI_QDRANT_ENABLED=true`, `AI_QDRANT_URL`, `AI_QDRANT_API_KEY`, `AI_EMBEDDING_PROVIDER` and `AI_EMBEDDING_MODEL` only after approving the external data-processing policy. Provision the configured `zolo_approved_knowledge_v1` collection with the configured embedding dimensions. Keep the embedding model and vector collection consistent. The adapter uses HTTPS without redirects and does not accept arbitrary document URLs.

Only scanned SOP documents can be explicitly approved for indexing. Invoice and spreadsheet data cannot enter this index. Embedding attempts have a per-company daily cap. Every search applies server-owned company, actor, branch and year filters, then checks the current SQL ownership, document version, ACL version and plaintext checksum. Expired, revoked, stale or altered chunks do not become citations. Retrieved instructions remain inert text and never override ERP permissions or financial services.

## Product spreadsheets

Open `/ai/documents-workspace`, choose Product spreadsheet, upload CSV or XLSX, and wait for scanning. Validate the rows and review duplicate codes, formula rejections, company-owned master mappings and prices. Select only valid rows and prepare an action proposal. Product creation requires the normal approval and execution gates.

The dry-run uses the existing `ReviewedProductImport` service. An approval binds the source checksum, exact schema, scope and accepted row set. Execution stores individual row receipts. A partial failure preserves completed rows and retries only unfinished rows. Download the row error CSV for corrections. Corrected source data requires a new preview rather than modifying an approved selection.

## Opening stock and financial balances

Opening imports require a company administrator and a clean stock and accounting target. They cannot alter a company that already has posted stock or accounting activity. Existing account opening balances remain immutable; the authoritative `OpeningImportService` creates reconciled stock movements and journal entries only after action approval.

For CSV or XLSX, choose Opening stock or balances spreadsheet. Use exactly these columns:

```text
source_key,kind,payload
```

`source_key` is a stable, unique row reference. `kind` is `stock`, `receivable`, `payable` or `journal`. `payload` contains a JSON object. Supported payload examples:

```json
{"product_key":"P1","warehouse_key":"W1","qty":"10.0000","unit_cost":"5.0000","offset_account_key":"OFFSET"}
```

```json
{"party_key":"CUSTOMER1","amount":"100.1234","offset_account_key":"OFFSET","document_date":"2026-11-12","due_date":"2026-12-12"}
```

```json
{"items":[{"account_key":"ASSET","debit":"100.1234"},{"account_key":"EQUITY","credit":"100.1234"}]}
```

Map each referenced source key to an existing company-owned product, warehouse, customer, supplier or account. Confirm the source stock quantity, stock value, customer balance and supplier balance. Do not infer ambiguous record mappings. CSV JSON values must be quoted using normal CSV escaping; XLSX payload cells are plain JSON text, never formulas.

The document workspace runs an authoritative rollback dry-run and prepares a proposal with the exact rows, mappings, totals and source hash. The document checksum and current ACL are checked again before execution. A revoked or changed source cannot post. Both opening action types commit the whole reviewed batch; the preview explicitly shows every included stock and financial row.

Chat also supports `prepare_opening_import`, accepting the same source structure as `source_json`. Supply a scanned `document_id` to read rows from CSV/XLSX, or provide typed rows directly. This tool stages a draft and runs validation. It never approves or commits the opening batch.
