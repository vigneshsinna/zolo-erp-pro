# D10 — Interactive Conversational ERP Record Choices

## Scope and baseline

Based on the merged conversational implementation in \`feature/ai-unified-input-clarification\` at \`1cfc8e833466f7d6356d4ee48f66982fe3b6885d\`.

This is a narrowly scoped UX and task-continuation follow-up. Do not repeat T00–T11 or D01–D08, replace the Laravel ERP services, or weaken signed action approvals.

## User problem

A user asks, “Can you tell me what products are available?” in an active purchase-order conversation. The ERP returns product names and SKUs in a read-only list, but clicking a product is not possible if the task has no line item. The user then has to manually reenter the product through Detail controls.

## Implemented delta

1. **Scoped, selectable directories:** Product browsing produces a server-side versioned choice set even when no line exists. The choice carries a reserved stable line ID. Browsing itself never writes a line; selecting the choice is the explicit mutation. If one draft line has an unresolved product, selection fills it. Otherwise it adds a new line without replacing an existing product.
2. **Supplier and warehouse choices:** These reuse the same live, company-scoped entity-options service, existing permission checks, record fingerprints, task revisions, and 10-minute choice expiry.
3. **Inline conversation controls:** Product/supplier/warehouse directory cards show selectable buttons, a text search field, result counts, and an optional Create New flow when permission allows. No hidden ERP IDs are exposed. Detail controls remain available but are optional.
4. **Natural-language and voice replies:** “Select Cotton Shirt Blue; 100 pairs at 480”, “Select the second product”, and the existing button/voice flows can update the same task. Product quantity and cost remain subject to ERP validation, not model inference.
5. **Missing master creation:** Creating a new product uses the governed dependent-master workflow and can add a new draft line without silently overwriting a different product. Separate explicit approval is still required for new masters and for the purchase order.
6. **Contextual suggestions:** When starting a PO or selecting an existing record, the agent can show a bounded initial set of eligible remaining records. Searching the directory does not alter the draft.

## Safety properties to preserve

- Scope record selection to the principal's company, warehouse branch, active status, authorized thread/task and current financial year.
- Reject expired/stale choice sets and inactive or modified records with a conflict. A clicked choice must include the server-issued choice set and choice ID.
- Never accept a model-invented ERP primary key; names and SKUs resolve only against the current eligible visible choices.
- Do not prefill an invented quantity, purchase rate, GST, HSN, or credit term.
- Never create a master, post stock or journal entries, create an order, or charge a payment in response to a directory lookup or click.
- Preserve task revision optimistic concurrency, signed action proposals, idempotency and audit lineage.

## Acceptance scenarios

- Start a PO with missing supplier and items. Eligible supplier/product/warehouse cards appear without creating an item.
- Ask a natural-language product question. Product choices are clickable; the same task revision remains unchanged until selection.
- Click Cotton Shirt Blue. One draft item is added with the correct company-scoped product ID. Quantity is requested. No purchase order or ledger entry is created.
- Reply “100 pairs at 480”. The same line receives a validated quantity, unit and proposed cost.
- Say “Select Cotton Shirt Blue; 100 pairs at 480” after a visible product directory. Exactly one line is created.
- Click supplier/warehouse choices or search by name. Selections use the server-issued choice ID. Typed exact names must be unique.
- Request a new supplier/product/warehouse. The system checks existing records and moves to the existing governed master proposal, not an automatic database insert.
- Refresh or change company/branch: old choice IDs must fail and cannot mutate a different task.
- Verify keyboard, mobile layout, pagination/search affordances, and the optional Detail controls fallback.

## Test commands

Use the repository's guarded disposable MySQL test database (never a real tenant schema):

\`\`\`bash
php vendor/bin/phpunit -c phpunit.ai.xml tests/Feature/Ai/Conversation/ActiveTaskRoutingTest.php
php vendor/bin/phpunit -c phpunit.ai.xml tests/Feature/Ai/Conversation/IndependentWorkflowRevisionTest.php
# Browser fixture uses mocked APIs; it does not replace live provider or stage acceptance.
node tests/Browser/Ai/conversation-workspace.spec.mjs
php vendor/bin/phpunit -c phpunit.ai.xml
\`\`\`

Run the ERP acceptance matrix and clean migrations before release. Record exact results in the PR; do not claim a pass without executable evidence.

## Rollback and rollout

This change introduces no database schema changes. Revert the PR as a unit if search, selection, or conversation rendering regresses. Previously created ERP and audit records must not be deleted by rollback. Retain the conversational feature gate default-off until authorized staging voice, real-provider, permissions, and concurrent-session tests pass.

Deploy the UI asset revision with versioned asset URLs and clear app caches when necessary. Verify the deployed feature-branch SHA, effective company feature gate, and a live manual click/voice journey separately from the mocked browser test.
