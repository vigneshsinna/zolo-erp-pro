# 17 — Advanced multi-agent workflows (Neuron AI) and local models (Ollama)

## Neuron AI integration evaluation

Project: https://github.com/neuron-core/neuron-ai, documentation: https://neuron-ai.dev. Neuron's PHP framework supports workflow orchestration, checkpointing, human-in-the-loop and RAG. It is an **optional alternative orchestrator**, not a required MVP dependency.

### Proposed isolated proof of concept

- A separate `spike/neuron-workflows` branch/project after PH1 passes. Install a compatible released package in that project and review its official Laravel integration guidelines.
- Build one bounded read-only workflow: `sales.summary → inventory.availability → merge deterministic facts → narrative response`.
- Compare with the same flow using a custom Laravel orchestrator and Laravel AI SDK: compatibility, testability, state recovery, tool isolation, cost, latency, security, observability and operator burden.
- Do not run the same business tool through both frameworks with divergent auth checks. The **single `ErpToolExecutor`** remains the source of tool authority regardless of orchestrator.
- If chosen, document exact package version, ownership of memory/state, checkpoint recovery, approval handoff and how to avoid duplicate SDK calls or nested agents.

### Decision criteria

Adopt only if ≥3 production workflow needs require checkpoints, branching or human pause/resume, and the package measurably improves maintenance over our own bounded state machine. Remain Laravel-native for simple read/draft flows.

## Ollama and open-weight models

Project: https://github.com/ollama/ollama. Optional separate model-serving runtime for controlled environments; **not** a substitute for ERP authorisation or privacy controls. Keep it off the shared ERP VPS unless capacity/performance review explicitly approves.

- Verify GPU/CPU/RAM, operating-system service, queue throughput, TLS/private network and model-specific usage license.
- Select models by structured tool-calling reliability, multilingual accuracy, data minimisation, latency, memory footprint and cost.
- Put provider behind `ApprovedModelGateway`; confirm Laravel AI SDK supports the target backend/provider shape (for example via approved compatible endpoint) in the **actual installed SDK**. Do not assume provider-specific configuration keys.
- Never expose model HTTP endpoint publicly without authentication; rate limits, sandbox and egress controls apply.
- Perform separate adversarial evaluation for model hallucinations, tool schema selection and confidential data leakage.

## Go/no-go gates

If multi-agent orchestration adds more complexity than saved effort, defer Neuron. If local inference costs more operational effort or performs below accuracy thresholds, keep the managed provider. Neither option blocks PH1.
