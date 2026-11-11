# 17 — Evaluation of Advanced Orchestration and Local Models

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Default decision
Stay Laravel-native until actual business workflow complexity justifies another runtime. The future orchestration state machine can be built with Laravel services, DB, queues and existing domain commands. Neuron AI is not required for the first purchase order or sales invoice action.

## When to evaluate Neuron AI
Require at least three production workflows with complex branching, pause/resume and checkpoint recovery that a Laravel-built workflow handles poorly. Build a separate PoC with sales + inventory read synthesis, then one synthetic approval pause. Measure authorization boundary, state persistence, developer burden, latency, token cost, testability and recovery. If adopted, `ErpToolExecutor` and business command boundary remain authoritative; never create duplicated permission logic.

## Ollama / self-hosted inference
Evaluate on isolated, appropriately sized hardware with private network, TLS and model-specific licensing. Test multilingual input (English and Tamil if product needs it), Indian business nomenclature, structured tool calling, invoice extraction, GST terminology, hallucination, function call JSON, retries and privacy. Keep approved provider gateway; a local model is not inherently more accurate or more secure without isolation and policy.

## Decision criteria
A component is added only if its measured benefit exceeds operational and security cost. No speculative packages in MVP. Record ADR including alternative, benchmark results, deployment cost, rollback and responsible owner.


---
