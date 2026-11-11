# 07 — Laravel AI SDK Usage and Copilot Compatibility Contract

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Current implementation, not a blank SDK tutorial
`ErpCopilot` and `DeepSeekAgent` use `laravel/ai`; `AiToolManifest::sdkTools()` returns `GenericSdkTool` instances, each bound to an immutable `AiPrincipal`, explicit `AiToolRegistration`, and `ErpToolExecutor`. Do not recreate removed `Tools/SalesSummary.php`-style wrappers. The SDK selects approved provider function aliases; internal ERP keys remain separate versioned identifiers.

## SDK responsibilities
LLM provider invocation; messages/instructions; supported tool interface (`name`, `description`, schema, `handle`); response streaming where deployed; test fakes; optional SDK conversation persistence. **Not** responsible for company authorization, ERP number calculations, business mutations or approval verification.

## Proposed minimal agent contract
- Constructor receives authenticated `AiPrincipal` / approved capability set.
- `instructions()` versions financial-number, source, uncertainty and read-only policies; never trusts prompt instructions as security.
- `tools()` calls the manifest with that principal; no arbitrary tool creation or broad built-in WebSearch/SQL.
- Model decides among available tools; tool executor may still deny based on fresh permissions or business scope.
- Financial narrative only follows deterministic evidence. Display structured fact cards from tool results; no fabricated chart figures.

## Tool-name and argument compatibility
SDK-facing function names are provider-compatible aliases (`sales_summary`), internal ERP keys are stable (`sales.summary.v1`). Preserve alias/key mapping, default values, max length, nullable rules, enums and exact error envelopes. Validate unmodified SDK request arguments in executor. Strict closed schemas reject unknown ownership fields; the model cannot set actor/company/branch/FY. Capture tool version in audit and golden tests.

## Model gateway integration
Continue using `ApprovedModelGateway` as one policy point: provider allowlist, model selection, per-company usage, token reservation and actual HTTP attempts including retry, timeouts, sanitised errors, concurrency. Preserve separate counts for chat requests, model steps, HTTP attempts and ERP tool executions. Test NVIDIA-compatible tool call JSON with real provider in a bounded staging contract suite; routine CI must use SDK HTTP fakes.

## Streaming and history
Synchronous read-only chat is the stable baseline. For UI streaming, define explicit events `run.started`, `content.delta`, `tool.started`, `tool.completed`, `run.completed`, `run.failed`, with trusted facts released after server validation. Do not stream raw tool/secret fields. When continuing a thread, confirm owned thread ID + company/branch/FY and recheck capabilities against permission revocations. Model history is not fresh ERP truth.

## Specialist agents later
`SalesIntelligenceAgent` can have a sales-focused prompt and tool subset but must reuse the manifest/executor. It should never have to call `ErpCopilot` internally just to retrieve sales. Future action planners may use the same provider gateway, but require a separate allowlisted action planner boundary and PHP-driven execution path.

## Upgrade tests
- Provider aliases and advertised schemas unchanged or deliberately versioned.
- `GenericSdkTool` passes validated args and returns exact `ErpToolResult` shape.
- Tool permission revoked between SDK selection and actual execution is denied.
- One user message with multiple model steps/retries accounts usage correctly.
- Provider failure results in safe error rather than invented zero.
- SDK conversation and app thread mapping remain stable across current branch.
