# zoloERP Pro — AI Agent Implementation Pack

**Revision:** 1.0 | **Prepared:** 2026-11-10 | **Baseline reviewed:** `vigneshsinna/zolo-erp-pro`, `main` @ `1b429a5b51d6d242e97347781938973c3e55015c` | **Status:** architecture and implementation specification, **not installed or executed**.

## Objective

Add a secure, company-aware AI operating layer to zoloERP. Users should be able to ask natural-language questions about sales, receivables, finance, inventory and industry-specific operations; receive **verifiable, authorised** answers; and eventually prepare transactions that are executed **only** after explicit approval via existing ERP services.

**Core integrations:** [Laravel AI SDK](https://github.com/laravel/ai), [Laravel MCP](https://github.com/laravel/mcp), [Laravel Horizon](https://github.com/laravel/horizon). **Optional integrations:** [Langfuse](https://github.com/langfuse/langfuse), [Qdrant](https://github.com/qdrant/qdrant), [Neuron AI](https://github.com/neuron-core/neuron-ai), [Ollama](https://github.com/ollama/ollama). Package integrations are specified, not claimed as implemented.

## How to use this pack

1. Create a **new branch from current `main`**: `feature/ai-agent-foundation`. Never develop against a stale upgrade branch.
2. Read `01_REPOSITORY_BASELINE.md`, `02_OPEN_SOURCE_STACK.md`, `03_ARCHITECTURE.md`, and `04_SECURITY_AND_TENANCY.md` before any package installation.
3. Implement **PH0 → PH1** from `20_DELIVERY_BACKLOG.md`. Get the read-only **Receivables Copilot** vertical slice passing before adding more agents.
4. Use the package installation and version gates in `05_INSTALLATION.md`. Copy ideas from the code recipes in `09_FIRST_VERTICAL_SLICE.md`, but adapt and test them against the **actual installed versions**.
5. Run the tests in `18_TESTING_AND_EVALUATIONS.md` and release gates in `19_CI_CD_AND_PRODUCTION.md` before merging.
6. Keep `22_DECISIONS_AND_ASSUMPTIONS.md` current. `23_CODING_AGENT_HANDOFF.md` is a ready task brief for an AI coding agent.

## Non-negotiable product rules

- Never allow an LLM, plugin, MCP client or autonomous agent to execute arbitrary SQL, shell commands, migrations or unrestricted database writes.
- Every read and write must be authorised against actual user membership, company, branch, financial year, capabilities and relevant ERP business permissions. **Never accept a company ID supplied by the model as authority.**
- Live financial and stock numbers must come from existing deterministic ERP services; do not depend on embeddings or remembered chat content for transaction truth.
- No financial posting, payment, customer-facing sending, stock adjustment, journal, purchase approval or deletion is performed directly by a model. The model can create proposals; the ERP executes only after human review and a fresh permission/business-state check.
- Separate the AI queue from transactional ERP workers. Preserve the shared VPS's other applications and maintain PHP 8.3 rollback until the ERP release policy allows retirement.
- Fail closed when a tool is not authorised, a request is ambiguous, or the authoritative data is unavailable. Make missing data visible rather than inventing figures.

## Document index

| File | Covers |
|---|---|
| `01_REPOSITORY_BASELINE.md` | Source-derived facts, gaps and touchpoints |
| `02_OPEN_SOURCE_STACK.md` | Github repos, licensing, selection and integration ownership |
| `03_ARCHITECTURE.md` | Components, data flows, trust boundaries |
| `04_SECURITY_AND_TENANCY.md` | Actor/company scoping, risk tiers, injection controls |
| `05_INSTALLATION.md` | Composer packages, configuration and environment setup |
| `06_DATABASE_SCHEMA.md` | MySQL tables, constraints, indexes, data retention |
| `07_LARAVEL_AI_SDK.md` | Agents, model gateway, prompts, streaming, SDK lifecycle |
| `08_ERP_TOOL_REGISTRY.md` | Typed tools, permission checks, domain adapters |
| `09_FIRST_VERTICAL_SLICE.md` | Detailed read-only receivables implementation recipe |
| `10_AGENT_CATALOG.md` | Domain agent definitions and milestones |
| `11_MCP_INTEGRATION.md` | Server/client bridge, authentication and testing |
| `12_APPROVAL_AND_ACTIONS.md` | Human approval and safe business commands |
| `13_API_AND_FRONTEND.md` | Endpoints, SSE, chat UI and compatibility |
| `14_HORIZON_AND_QUEUES.md` | Redis, jobs, quotas and workers |
| `15_RAG_AND_QDRANT.md` | Controlled knowledge ingestion and tenant filters |
| `16_LANGFUSE_OBSERVABILITY.md` | Tracing, evals, redaction and model-cost analysis |
| `17_OPTIONAL_NEURON_OLLAMA.md` | Multi-agent and self-hosted inference options |
| `18_TESTING_AND_EVALUATIONS.md` | Unit, integration, adversarial and acceptance tests |
| `19_CI_CD_AND_PRODUCTION.md` | CI, staging, VPS deployment and rollback |
| `20_DELIVERY_BACKLOG.md` | Implementation stories, dependencies and acceptance |
| `21_ROLLOUT_AND_OPERATIONS.md` | Feature flags, rollout, cost and operations |
| `22_DECISIONS_AND_ASSUMPTIONS.md` | Open design decisions and validation matrix |
| `23_CODING_AGENT_HANDOFF.md` | Agent-ready instructions to build Phase 0 and 1 |
| `24_SOURCE_REFERENCES.md` | Official package documentation and repository links |

## Success definition for Phase 1

A permitted employee opens the zoloERP AI workspace, asks for **customer outstanding amounts**, the assistant invokes a **server-authorised, branch-scoped** receivables service, returns a reconciled or clearly flagged result with source links, and the interaction is logged without leaking customer data to another company. Requests from an unauthorised actor and a user from a different company must be rejected. No write tools are exposed.

## Scope boundaries

This pack gives a complete **implementation blueprint**; it does not claim to contain working Laravel classes, executed migrations, verified provider credentials, deployed AI services or finished UX. The included PHP/SQL examples are design recipes and must be adapted to installed package APIs and tested. Optional components are deliberately gated to keep the MVP supportable.
