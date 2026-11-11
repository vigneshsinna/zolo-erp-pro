# zoloERP AI Operating Layer v3 — Repository Document Replacement Guide

This ZIP is **documentation only**. It preserves README + numbered documents `01`–`36` and adds `37`, `38`, `39` under the existing repository path: `docs/zoloerp-ai-agent-implementation/docs/ai`.

## Safe replacement workflow
1. In a trusted working copy of `vigneshsinna/zolo-erp-pro`, `git fetch origin` and inspect current HEAD. The source reference in this pack is `refactor/erp-copilot-stabilization` @ `14195d8` (2026-11-11), but the branch may advance.
2. Create a **documentation-only branch** such as `docs/ai-operating-layer-v3` from the latest approved base. Never edit the actual read-only AI Copilot to install documents.
3. Back up or commit any uncommitted existing documentation. Copy the package's `docs/zoloerp-ai-agent-implementation/docs/ai/` contents over the same repository folder. Original README + `01`–`36` names stay stable, new `37`–`39` are added.
4. Review `git diff --check`, `git status --short` and all changed docs. No production config, migrations, Composer lock, source PHP or actual action enablement belongs in this PR.
5. Obtain product, finance, platform/security and engineering approval for the proposed Setup Agent and lifecycle design before coding.
6. After docs approval, implement the Action Foundation in a **separate branch**, followed by the admin-only Setup Agent. Do not put setup write calls in current `AiToolManifest`, which remains read-only.

## Key changes from v2
- Now describes **pre-company bootstrap authorization**, with installation/group-admin rules derived from existing `CompanyDirectoryService`.
- Makes the **Initial Setup Agent** the first administrative business-action vertical slice, prior to purchase orders.
- Includes source-confirmed automatic initial branch/FY/warehouse/COA and industry starter masters; avoids duplicate provisioning.
- Adds a mandatory **ERP transaction lifecycle policy**: after submitted/posted, AI cannot generic-edit; only supported amendment/cancellation/return/reversal/credit/debit/adjustment flows.
- Requires **new preview and human approval after each change** and final live state revalidation before execution.
- Updates CI evidence: latest reviewed run [42870009218](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42870009218) fully passes 11 jobs; earlier failure references marked historical.
- Replaces previous code baseline SHA `6ded371` with latest reviewed `14195d8`, while retaining historical prior-run evidence when useful.

## Not implemented by adding documents
No AI Setup Agent, new company-create tool, business action engine, ability to edit submitted/posted ERP records, purchase/sales posting automation or AI finance posting is currently live as a result of this package. Application code and production configuration are unchanged.
