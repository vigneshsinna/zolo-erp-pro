# PR5 — Purchasing and billing vertical slices

Based on PR4 (#43); `AI_BUSINESS_SERVICE_BILL_ENABLED` defaults false.

## Implemented
- PurchaseDocumentClassifier: explicit purchase-order/goods/service/expense/job-work categories. Ambiguous or non-GST wording is not silently converted to a tax classification.
- PurchaseBillMatchAudit: advisory read-only PO/GRN/supplier/warehouse identity and remaining quantity checks. Distinguishes direct bills where PO/GRN evidence is absent.
- Existing PurchaseBillPreviewAdapter now includes match audit in the preview; existing CommercialPricing/tax and posting logic remains authoritative.
- New guarded service-only purchase-bill capability uses service products, no linked stock PO/GRN, and the authoritative PurchaseBillCommandAdapter / PurchasePostingService (which does not issue service stock).
- New service variant retains warehouse as accounting/branch context because the current domain contract requires it; no synthetic stock product.
- Draft process definitions for direct goods and service bills; expense-only posting remains explicitly unsupported until a dedicated authoritative accounting adapter is approved.

## Safety boundaries
- Action requires normal explicit proposal approval, ERP permissions, source-state hash, idempotency and post-approval domain service.
- All knowledge entries remain `draft`; no blanket permissions or automatic posting.
- This PR does not create dependent masters or synthetic GRNs and does not change tax rates.

## Tests
- PurchaseDocumentClassifierTest (explicit request categorization, GST ambiguity), ServicePurchaseBillActionTest (default-off, reviewed action registration and draft knowledge).
- CI expected to run existing AI / MySQL commercial and bill suites; live Bill 100-corpus and three-way match reconciliation remain release gates.

## Limitations
- PO vs GRN unit reconciliation and price differences remain authoritative-domain validation; match audit is clearly advisory.
- Expense-only AP classification intentionally does not post: domain/UI contract requires additional analysis.
- No model inference is used by the classifier; complex language requires the existing bounded conversational model.
