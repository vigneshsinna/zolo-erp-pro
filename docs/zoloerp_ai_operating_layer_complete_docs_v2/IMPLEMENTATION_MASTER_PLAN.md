# zoloERP AI Operating Layer — Implementation Master Plan

## Goal
One AI Command Center lets authorised users ask questions and prepare/complete governed ERP tasks from natural language, editable forms, files and later voice. Every business action is validated and executed by existing Laravel domain services. AI cannot bypass company/branch/FY, finance/stock rules or explicit approval.

## Starting status (2026-11-10)
`refactor/erp-copilot-stabilization` @ `6ded371` has 14 read-only tools migrated through a generic SDK adapter. AI MySQL suite passes (59/408), but aggregate CI fails (ProductAlias and two DesignTokens tests). Action proposals are quarantined. No new approved action execution is delivered with this documentation.

## Phase sequence
1. **PH0 Stabilize Copilot:** fix CI, reconcile real ERP figures, provider staging contract, release sign-off.
2. **PH1 Action Foundation:** action allowlist, intent/slot resolution, typed preview, immutable proposal, approval, idempotent receipt, robust workflow state. Flag OFF.
3. **PH2 Purchase Order:** first real business create from natural language, reviewed approval and actual ERP receipt.
4. **PH3 Purchase Goods Receipt / Bill:** link order → accepted goods → invoice, preventing double stock/accounting.
5. **PH4 Sales and Collections:** quote, order, dispatch, invoice, approved collection actions.
6. **PH5 Remaining ERP:** masters, stock transfers, journals (restricted), production and module onboarding.
7. **PH6 Document/Bulk:** secure OCR/Excel extraction, matching, editable review, per-row idempotent imports.
8. **PH7 Controlled Autonomy:** bounded scheduled reads/proposals, task inbox, monitoring and optional MCP/RAG/trace.

## First engineering decisions
- Keep `GenericSdkTool + AiToolManifest + ErpToolExecutor` for read-only tools.
- Add **separate** `ActionCapabilityRegistry` for future business commands.
- Use a durable task state machine; no unrestricted agent-to-agent or LLM-chosen PHP method execution.
- Human approves exact proposal hash; server revalidates and domain service executes idempotently.
- Existing purchase/sales/receipt/accounting/stock services remain owners of business effects.
- Start with purchase order; do not combine it with receipt/bill posting in first action milestone.

## Launch acceptance
Each released capability requires zero cross-tenant data leaks, authoritative fact parity, no double posting, source-linked receipts, reviewed business permission and exact approval, complete green ERP CI, production-appropriate model/privacy policy, and feature-flagged staged UAT.

## Document navigation
Read `docs/zoloerp-ai-agent-implementation/docs/ai/README.md` for full numbered index and all technical specifications. See `REPLACEMENT_GUIDE.md` before copying into the repository.
