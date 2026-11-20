# PR 3 — Semantic intent and guided supplier conversation

Stacked on PR 2 (feature/ai-knowledge-02-context). Both AI_BUSINESS_KNOWLEDGE_ENABLED and AI_BUSINESS_SEMANTIC_ENABLED default OFF.

## Implemented
- Structured bounded BusinessIntentParseAgent and BusinessIntentInterpreter via ApprovedModelGateway.
- Deterministic role disambiguation for supplier/customer based on trade direction, not the bare word buying.
- Durable 30-minute pending party-role clarification scoped to actor, company, branch, FY and thread.
- Reuse ConversationQuestionPlanner for standalone and dependent master supplier collection; optional skip groups.
- Model-suggested supplier fields are allowlisted, evidence-checked against literal input, passed through TaskCorrectionService and tagged model_extracted.
- Supplier GST status prompts distinguish unregistered from merely no GSTIN; guided preview cannot proceed if user says registered without GSTIN.
- Model-independent exact requests avoid new LLM calls; removed previously unnecessary deterministic conversation reservation.
- Post-preview supplier changes and tax-status corrections reject the linked proposal; user must preview/approve again.
- Feature-gated supplier preview no longer silently fills Tamil Nadu as supplier state.
- Preserved ActionSecurity, action allowlists, owned task revision, tenant and approval paths.

## Tests added
- tests/Unit/Ai/Conversation/BusinessIntentInterpreterTest.php: direction, zero-call disabled mode, question planner.
- tests/Feature/Ai/Conversation/BusinessKnowledgeSupplierWorkflowTest.php: ambiguous party -> supplier -> draft -> optional skips; no ERP supplier write or HTTP call.

## Not complete / release blockers
- Customer classification is understood, but full guided customer creation is not yet implemented; safe UX states this explicitly.
- Only supplier guided collection is wired; other entities still need profiles.
- Model-mediated multi-field extraction has not been benchmarked on a real NVIDIA model; production prompts need evaluation.
- Generic direct supplier proposal API still follows its existing schema (minimum name); consistent guided completeness policy requires business owner signoff.
- Pending clarification migration must be applied before enabling the semantic flag.
- No CI, database, PHPUnit or PHP lint was executed in this GitHub-only session.

## Rollout / rollback
- Keep AI_BUSINESS_KNOWLEDGE_ENABLED=false and AI_BUSINESS_SEMANTIC_ENABLED=false until MySQL, security, quota and UI suites pass.
- Rollback by switching both flags OFF; existing ERP records are unchanged. Keep the pending-intent table until in-flight clarifications expire.
