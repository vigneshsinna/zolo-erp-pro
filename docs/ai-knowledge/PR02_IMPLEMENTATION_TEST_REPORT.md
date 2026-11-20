# PR 2 — Tenant-safe knowledge context retrieval

Stacked on PR 1 (feature/ai-knowledge-01-registry).

## Implemented
- Config feature switch defaults OFF (AI_BUSINESS_KNOWLEDGE_ENABLED).
- ScopedBusinessFactReader: explicit kind/id allowlist, ActionSecurity/CommercialPermission checks, company/branch/FY constraints, projected safe fields.
- Read-only knowledge context from reviewed action definitions; draft definitions excluded from action context.
- Facts include tenant provenance and as_of, without GSTIN, contact PII or all-company dumps.
- Missing/foreign scoped record returns the same not-found error; no free-form SQL or model invocation.

## Caveat
- A conservative write-permission gate is used for fact readers; dedicated read permission keys require stakeholder review.
- Purchase/GRN/job-work documents lacking financial_year_id fail closed, by design. Confirm schema migration coverage.
- No public endpoint is introduced, avoiding accidental API exposure.

## Tests added
- tests/Unit/Ai/ScopedBusinessFactReaderTest.php: allowlist, SQL-like inputs, invalid IDs and disabled flag.
- MySQL cross-company/branch/FY integration and read-permission fixtures remain release blockers; not claimed tested.

## Tests actually run
- Not run; repository dependencies and database service are not available through this connected GitHub environment.

## Rollout
- Keep AI_BUSINESS_KNOWLEDGE_ENABLED=false until tenant-isolation test matrix is green and read-permission policy is reviewed.
