# 31 — Sales Quotation, Sales Order, Invoice and Collections

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Business objective
Let a permitted user prepare customer transactions and manage payment follow-up from language or documents while enforcing ERP pricing, inventory, credit, tax and accounting rules. Creating a quote/order/invoice are **different actions** with different consequences and approvals.

## Verified service candidates
`SaleApplicationService::create(SaleCommand)`, `CommercialApplicationService::create`, `CommercialDraftService`, `OrderLifecycleService::createSale`, `remaining`, `consume`, `SalePostingService::post`, `CommercialPricing`, `CreditControlService`, `TaxDeterminationService`, `FinancialReportService`, `ReadCustomerInvoices`, `ReadInvoiceAllocations`. Check exact command schemas and current refund/return policy before implementing.

## Workflow A — Customer quotation
**Example:** "Quote Kumar 25 blue shirts with 5% discount, valid one week."
Resolve customer and product with exact SKU/variant/UOM; verify accessible branch and product saleability. Use domain price list, customer group, tax policy, credit and discount restrictions to prepare a quote. Preview item and tax basis, rounding and validity. A quotation is **not** a posted invoice and should not imply stock/GL entries. Save draft or reviewed quotation through an allowed domain capability only.

## Workflow B — Sales Order and fulfillment
**Example:** "Turn approved quote QT-42 into an order for next Monday."
Confirm quote status, customer details, authorized warehouse, date and current price/availability. Check credit policy and actual order lifecycle. Prepare order and any configured reservation status through `OrderLifecycleService`; show whether stock is reserved or decremented according to ERP policy. On fulfillment/dispatched goods, use proper dispatch/stock service with batch/serial and quantity checks, not direct inventory table edits.

## Workflow C — Sales Invoice
**Example:** "Invoice Customer ABC for 10 units delivered on SO-63."
Resolve source order/dispatch; check remaining billable quantity and whether invoice already exists. Determine selling price, applicable discounts/GST, currency, customer registration/place of supply, cash/credit and due dates through existing services. Preview revenue, tax, AR and COGS/stock effects as applicable; if stock was already issued during dispatch, do not issue again. Require explicit posting approval; use `SalePostingService` / approved command, capture real invoice number and actual GL/stock receipts.

## Workflow D — Collections and payment allocation
**Example:** "Show invoices overdue for Kumar and prepare a reminder." Use AR ageing/open item tool for current authoritative dues, separate due vs overdue and partially paid balances. Prepare reminder content, review recipient/address and consent before any external send. "Record ₹25,000 received against INV-12" requires matching customer, invoice, payment source/method, currency, bank/cash destination and allocation rules. Save/post only through verified accounting receipt/settlement service with finance approval. Do not mark communication 'sent' before delivery receipt.

## Return/cancellation flows
Sales returns, credit notes, refunds and cancellations require their **own** approved workflows. Do not edit a posted invoice row or apply a negative quantity as generic correction. Use existing return/reversal services and preserve source invoice links, tax/stock consequences and ledger history. Automatic refunds/bank transfers remain restricted.

## Critical validation
- Customer duplicate/identity, tax ID, active status, credit control and outstanding balances.
- Stock on-hand vs available/reserved; batch/variant and order fulfillment rules.
- Price history/discount limits, inclusive/exclusive tax and exchange rounding.
- Date/FY posting lock, duplicate invoice/ref number, document ownership and recorded source version.
- Source links only to permitted sale, quote, order, invoice and AR pages.

## Acceptance
Exact quote→order→dispatch→invoice trace; no wrong customer or duplicate invoice; current stock effects per business lifecycle; correct CGST/SGST/IGST; no duplicate revenue, COGS or inventory; partial invoicing and payment allocation; credit-note/returns support; two-click retry idempotency; no false 'sent' reminders; non-sales users cannot execute sales commands.

## Rollout
After purchase PO foundation, build quote/order first, then invoice, then settlement/outbound. Each adds a new capability flag, domain service review, gold fixtures and explicit approval policy.
