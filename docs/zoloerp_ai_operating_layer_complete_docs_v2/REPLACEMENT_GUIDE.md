# zoloERP AI Operating Layer v2 — How to Replace the Existing Docs

This package is **documentation only**. The `docs/zoloerp-ai-agent-implementation/docs/ai` subfolder is a **complete replacement** for the same path in the repository. It preserves the original README and `01`–`24` names, then adds `25`–`36`. Do not paste the package over the repository root without reviewing the file diff.

## Copy procedure
1. Open an authorized clone of `vigneshsinna/zolo-erp-pro`.
2. Fetch and select the latest approved AI base; never assume the pinned SHA is still current. Suggest documentation branch `docs/ai-operating-layer-v2` off `refactor/erp-copilot-stabilization` if still the correct base.
3. Copy the package's `docs/zoloerp-ai-agent-implementation/docs/ai` **contents** over the repository path of the same name. Existing old files are overwritten; new numbered docs are added.
4. Run `git status --short`, `git diff --check` and review all changed `.md` files. The PR must have **no app, config, migration, lockfile or workflow code edits**.
5. Open a documentation-only PR with explicit product, security, finance and engineering reviewers.
6. **Do not run** commands in design documents against production; they are task instructions, not automatic migrations.

## Recommended reading
Start with `README.md`, then `01`, `03`, `04`, `08`, `12`, `20`, `25`–`29`, `30`–`34`, `35`, `36`. The first coding implementation should still be closing the current Copilot CI gate; the next code PR after approval is the Action Foundation.

## Provenance
GitHub source reviewed at `refactor/erp-copilot-stabilization` SHA `6ded3715275e7ad8bd60eb7410428af03e4fc304` on 2026-11-10. Actual project code wins if the source has changed, and business owners approve transaction semantics.
