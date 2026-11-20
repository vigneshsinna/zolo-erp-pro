# PR 09 — Ask Zolo PO / Purchase Bill Copilot Editing

## Scope

- Create purchase orders and purchase-bill conversational drafts, or open an existing, owned proposal from Action Review Center → Edit with Ask Zolo.
- Edit supplier, warehouse, quantities, purchase rates, discounts/freight, invoice number, bill date, due date and optional PO/GRN references. Existing ERP pricing, stock, accounting and matching checks remain authoritative.
- Existing supplier: resolve by confirmed company-scoped name/ID. New supplier: use the existing separate, approved supplier-master creation workflow. For an unregistered supplier created in chat, explicitly supply its Indian state; a GSTIN is NOT invented.
- GST/non-GST chat text is read-only guidance based on actual supplier GST registration, item tax category and validated totals. This is NOT a bypass or arbitrary zero-tax switch. Exempt supply and reverse-charge status remain ERP tax-engine decisions.
- Suspended proposal status prevents use of previous approval while edits are in progress. After validation, a new version requires a new human approval.
- Edits to one proposed PO do NOT edit the future rule in the Automation Builder.

## Not included

No autonomous financial posting; no manual replacement of product HSN/GST without authorized master editing; no arbitrary model-selected tax percentage; no automatic creation of a supplier from a name; no unapproved PO, bill, stock or journal transactions; no editing posted bills.

## Local staging configuration

Set AI_ENABLED=true, AI_CONVERSATION_ENABLED=true, AI_ACTION_FOUNDATION_ENABLED=true. Enable company AI and correct purchase permissions. Keep AI_ACTION_EXECUTION_ENABLED=false when testing preview-only flows.

Use only a disposable zolo_test_* MySQL database for PHPUnit, never live company data.

## Regression commands

~~~bash
php vendor/bin/phpunit -c phpunit.ai.xml --filter 'PurchaseDocumentCorrectionInterpreterTest|PurchaseBillActionTest|PurchaseOrderActionTest|ActiveTaskRoutingTest|DependentMasterResolutionTest|IndependentWorkflowRevisionTest'
php -l app/Ai/Services/AiPurchaseProposalEditService.php
php -l app/Ai/Services/PurchaseBillGstIntentService.php
php -l app/Ai/Services/TaskCorrectionService.php
php -l app/Ai/Conversation/ConversationalSlotInterpreter.php
php -l app/Ai/Conversation/ConversationTurnRouter.php
php -l app/Http/Controllers/Ai/AiPurchaseCopilotEditorController.php
node --check public/js/ai-conversation-workflow.js
node --check public/js/ai-conversation-po-workspace.js
~~~

Run full project MySQL 8.0 / 8.4 acceptance, AI credit evaluation, security and accounting suites before merging.

## Manual testing checklist

1. Ask: "Create a purchase order for 10 pieces of Cotton Shirt Blue from ABC Traders at ₹250 per piece, warehouse lenz-main." If names are ambiguous, choose the exact existing records. A validated draft, not a posted purchase, should result.
2. Ask: "Prepare a purchase bill for 10 pieces of Cotton Shirt Blue at ₹250, supplier ABC Traders, warehouse lenz-main, invoice number ABC/123." Confirm required dates and invoice references.
3. Trigger a low-stock automation set to prepare a PO draft. Open Action Review Center → Edit with Ask Zolo. Say: "Change supplier to ABC Traders and warehouse to lenz-main and quantity to 25." Confirm a new preview and separate approval.
4. Open a pending bill and change quantity 10 → 25, purchase rate ₹250 → ₹300, supplier invoice number and due date. Confirm full totals/tax recalculation.
5. With several lines, say "item 2 quantity to 12"; only line 2 changes. "quantity to 12" without identifying a line must not silently change another item.
6. Check two similarly named existing suppliers. Only the user-selected eligible supplier is used, and supplier changes discard old GST assumptions.
7. Ask: "create new supplier Kaveri Fabrics". Supply name, address, state and contacts plus verified GSTIN if registered. Approve the supplier master independently before resuming the purchase document.
8. For an unregistered supplier without GSTIN, require a real Indian state, e.g. Tamil Nadu (33). Verify the scoped party tax profile is unregistered. Never invent a GSTIN or assume that all unregistered purchases are RCM-free.
9. Choose a registered GST supplier and check authoritative CGST/SGST or IGST, HSN/SAC and grand total against the normal ERP bill screen.
10. Say "This is a non-GST bill" when a registered taxable invoice is selected. The chat should explain the conflict and must NOT force GST to zero. Say "GST bill" for an unregistered supplier; it must explain why a registered tax invoice is not valid.
11. Test valid and invalid supplier GSTIN checksums, different state codes, changes to profile after preview, product tax classifications and reverse-charge eligibility.
12. Change a linked bill's PO or GRN. Mismatched supplier, warehouse, FY, already-billed quantities and duplicate physical receipts must be blocked by ERP.
13. Try duplicate supplier invoice number for an existing posted bill. A second bill must be refused.
14. Choose a warehouse in another branch/company; both preview and posting must reject it without exposing other tenants' records.
15. Open an approved proposal. It enters editing status. Original approval must be unusable while editing; after a validated change, version increases and new human approval is required.
16. In two tabs, create a newer version in tab A; using stale tab B must fail with a conflict and preserve the newer version.
17. Try to open another actor's proposal, wrong company, wrong branch or FY. Authorization must fail. Cancelled, completed, executing and expired proposals cannot be edited.
18. Test keyboard, mobile, dark theme, CSRF and the existing voice/dictation confirmation flow. Verify unresolved input errors remain visible and the user can resume.
19. Ensure normal stock checks, supplier lookup and explicit quantity changes use deterministic PHP/SQL without requiring Nemotron. Tax calculation and permissions must never be delegated to an LLM.
20. Check pre-/post-test purchases, ledger, stock movements and payable balances. Creating/editing a proposal or asking about GST must not post a business transaction.

## Expected example

For a regular taxable intra-state registered supplier, 20 units × ₹200 at a configured 5% GST rate gives subtotal ₹4,000, CGST ₹100, SGST ₹100, total ₹4,200, before other discounts or charges. Actual ERP source values always take precedence.

For an unregistered supplier, an ordinary forward-tax supply may show ₹0 supplier-charged GST. That does not mean the whole purchase is legally exempt or that reverse charge can be ignored.

Proposal lifecycle: pending or approved → editing → pending with new version → fresh approved signature → authorized execution.

## Release gate

Do not merge until focused tests and full AI/ERP acceptance pass, a browser test covers both PO and bill edit links, MySQL 8.0/8.4 clean migrations pass, and isolated staging confirms versioned approvals, no cross-tenant access and no duplicate stock/ledger posting. This test guide does not replace runtime staging evidence.
