# 02 — Laravel-Native Open Source Stack and Selection Record

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Stack principles
Use upstream libraries for model interaction, queues, MCP and retrieval only where necessary. Keep ERP-specific authorization, transaction state, tool catalog, business validation and approvals owned by zoloERP. Avoid adding a second AI framework before there is a proven gap. Exact versions are controlled by `composer.lock` and audited for the deployment environment.

| Project | Link | Decision | Deployment timing |
|---|---|---|---|
| Laravel AI SDK `laravel/ai` | https://github.com/laravel/ai | **Primary provider/agent SDK**; installed API must be verified | Already integrated on AI branch |
| Laravel Horizon | https://github.com/laravel/horizon | Redis job supervision for nonblocking workflows | Only after secure isolated Redis/worker validation |
| Laravel MCP | https://github.com/laravel/mcp | Optional external authenticated interoperability | Later; not required for in-process AI actions |
| Langfuse | https://github.com/langfuse/langfuse | Optional redacted trace/quality evaluation | Separate deployment, after privacy review |
| Qdrant | https://github.com/qdrant/qdrant | Optional vector retrieval for approved documents | Knowledge phase only |
| Neuron AI | https://github.com/neuron-core/neuron-ai | Compare for complex checkpoint orchestration | Isolated proof of concept only |
| Ollama | https://github.com/ollama/ollama | Optional model serving for private environments | Capacity and model license review first |
| Prism PHP | https://github.com/prism-php/prism | Alternative SDK, not installed alongside primary by default | Only with measured justification |

## Ownership table
- **Laravel AI SDK**: messages to approved provider, SDK-specific tool interface, model response, testing fakes.
- **`ApprovedModelGateway`**: approved provider/model, request/token quotas, retry admission and usage telemetry.
- **`AiToolManifest` + `GenericSdkTool` + `ErpToolExecutor`**: current read-tool contract and backend policy.
- **Future action-capability registry**: explicit business command descriptors, intent mapping, risk level, preview, permission, approved executor. No arbitrary reflection-discovered service methods.
- **Domain services**: business record creation, pricing, GST, accounting/stock posting, validation and authoritative receipts.
- **Horizon/Redis (optional)**: durable workflow work execution; never source of financial truth.

## Packages to avoid for MVP
Do not make n8n, Dify or a general external multi-agent SaaS the ERP transaction authority. They add separate trust boundaries and may carry different licensing/operations implications. External automations can call tightly scoped zoloERP APIs later. Don't install MCP merely to communicate between Laravel classes in the same application.

## Compatibility and license gates
For every dependency: pin package release and lock hash, run `composer validate --strict`, `composer check-platform-reqs`, `composer audit --locked`, review maintainer/license, and test PHP 8.5/Laravel 13 on disposable MySQL. Upstream docs can evolve; verify actual installed SDK tool interface, schema builder, streaming and conversation contract rather than copying older snippets.

## Deployment footprint
Shared ERP VPS should not be burdened with an LLM GPU, full Langfuse stack, embeddings DB and new brokers during the first action pilot. Start with existing Laravel/MySQL and managed model, add dedicated AI workers only when long-running tasks require them. Document budget, outbound data policy and region for every approved provider.
