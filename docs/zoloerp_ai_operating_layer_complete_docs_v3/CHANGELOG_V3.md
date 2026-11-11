# V3 Change Log and Design Decisions

**11 November 2026**. Recreated v2 37-doc suite from the prior compiled Markdown artifact. This release changes the design documents only; the user did not request code changes to GitHub.

## What changed
1. Added `37_GUIDED_INITIAL_SETUP_AGENT.md` for installation/group-admin bootstrap, company/group creation, industry vertical, FY/branch/warehouse/UOM/product/party/tax/COA and setup readiness.
2. Added `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` to require existing ERP state transitions for edits after submit/post, including correction documents and fresh version/approval checks.
3. Added `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md` with source-grounded service mappings and 30 setup scenarios.
4. Cross-referenced v3 rules across read, action, workflow, UX, security, schema, CI, backlog, acceptance, handoff and business module documents.
5. Updated baseline to latest reviewed refactor commit `14195d8` and passing GitHub Actions 42870009218; replaced outdated active CI-blocker claims.
6. Moved first administrative action vertical slice to **Initial Setup Agent** following secure Action Foundation, ahead of Purchase Order.

## Not yet done
No implementation, tests or production installation of the proposed new Action Foundation/Setup Agent; no source control changes. Domain-state transitions and financial/stock rules require source and business-owner signoff before using new write capabilities.
