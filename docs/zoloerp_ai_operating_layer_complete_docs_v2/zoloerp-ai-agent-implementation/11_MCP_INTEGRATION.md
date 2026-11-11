# 11 — Optional Laravel MCP Integration with Shared ERP Guard

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Decision
MCP is a **future interoperability surface**, not a prerequisite for chat-driven purchases and sales inside Laravel. In-process SDK read tools call `ErpToolExecutor` directly; don't call your own MCP endpoint over HTTP. External MCP clients must pass the same authorization and command policies as the native AI UI.

## Use cases
Approved trusted external AI client asks for a limited stock or customer dues report, or zoloERP consumes a strictly scoped third-party service. Initial MCP server should expose only a small read-only capability set. Keep business writes disabled until a separate security and action-approval review.

## MCP pipeline
`External client + user-linked token → auth/scope → AiPrincipal → allowlisted MCP alias → schema validation → ErpToolExecutor → authoritative read facade → scoped redacted result`. Tool listing itself is filtered by actual capabilities. Never expose all company records or broad raw SQL.

## Controls
- Authenticate via reviewed Sanctum/OAuth flow and map external token to a real actor with scopes.
- Prevent MCP from supplying arbitrary company or branch authority; resource IDs are revalidated.
- Limit returned row count, size and sensitive fields. Reconcile tool version aliases with internal manifest.
- Restrict outbound MCP server addresses against SSRF, redirects to internal metadata endpoints, credentials or localhost.
- No general arbitrary web fetcher, code runner, file search outside approved ACLs or superuser server token.
- Log tool ID, actor hash, company hash, decision and outcome with redaction; protect logs and retention.

## Tests / gate
Missing scope, expired token, cross-tenant parameter, mismatched alias, malicious tool descriptions and excessive request size all fail closed. Same request in native SDK and external MCP returns the same authoritative facts. Disable MCP routes entirely until these tests pass; introduce no MCP dependencies in core action workflow if not needed.
