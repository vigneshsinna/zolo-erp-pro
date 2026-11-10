# 07 — Laravel AI SDK adapter design

## SDK ownership

Use `laravel/ai` rather than building raw provider HTTP wrappers. Its official Laravel 13 docs define agent contracts, `Promptable`, `HasTools`, `Tool`, `Laravel\Ai\Tools\Request`, JSON schema, streamed prompts, test fakes and optional conversation memory. **All business authorisation belongs to zoloERP.**

## Initial `ErpCopilot` responsibilities

- Receives an immutable `AiPrincipal` created by the authenticated controller.
- Has a short, versioned system instruction: explain **only** data from authorised tool responses; cite ERP source and period; report no-data and reconciliation warnings; do not present drafts as posted transactions.
- Offers only a small list of permitted read tools after server-side policy checks. A forbidden tool is not registered, and calls are rechecked even when selected.
- Does not include unrestricted provider built-in web search, code execution, file-system delete or fetch tools.
- Produces the UI response plus structured evidence metadata and optional next-question suggestions. A user-visible financial number requires a matching returned fact, not generation.

## PHP integration sketch aligned with Laravel 13 SDK contracts

```php
<?php
namespace App\Ai\Agents;

use App\Ai\Tools\CustomerOutstanding;
use App\Ai\Security\AiPrincipal; // application-owned proposal
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

final class ErpCopilot implements Agent, HasTools
{
    use Promptable;

    public function __construct(private readonly AiPrincipal $principal) {}

    public function instructions(): Stringable|string
    {
        return 'Answer only from authorised tool results. Never invent ERP totals. '
            .'Do not perform or imply a business write. Include reporting date and source links.';
    }

    public function tools(): iterable
    {
        // Tool constructor receives an authenticated principal, not model-selected IDs.
        return [new CustomerOutstanding($this->principal)];
    }
}
```

**Before copying:** generate an SDK agent with `php artisan make:agent ErpCopilot` and reconcile namespaces, return types and tool conventions with the resolved package version. The `AiPrincipal` and `CustomerOutstanding` classes are **not implemented** in the repo today.

## Model gateway and configuration

Create `app/Ai/Providers/ApprovedModelGateway.php` to mediate model provider and model selection, request options, request limits, masking, failover policy and result audit. It should call the installed SDK, not recreate an SDK. Provider key and model are operator-defined; user chats cannot choose arbitrary models or endpoints.

- Configure one vetted provider and one vetted model for PH1; reject missing credentials early.
- Keep a provider allowlist; add failover only after model compatibility and privacy equivalence are evaluated.
- Introduce `max_steps`, model token/output budget, total tool count, wall-clock timeout and per-company concurrency.
- Disable freeform `tools()` containing `WebSearch`, `FileStorage::all()` or external MCP tools. Use typed internal read tools.
- Tool results should be JSON with `data`, `scope`, `as_of`, `is_reconciled`, `sources`, `warnings`, `row_count` and a version.

## Conversation persistence

Laravel's `RemembersConversations` trait can persist SDK conversations. For zoloERP, never load a conversation solely by user-supplied SDK ID. Resolve an authorised `ai_thread_scopes` record and check owner/company/branch/FY first. For PH1 either:

1. Use **stateless prompting** and a zoloERP-owned bounded conversation history, or
2. Use SDK persisted conversations **only** after enforcing the ownership mapping before every `continue(...)` call.

Do not implement both histories independently unless there's a clear source-of-truth mapping. Limit carry-over to recent non-sensitive messages; never treat old tool values as current facts.

## Streaming and background processing

- PH1: start synchronous `prompt()` and render completion; only later add streaming after examining SDK's actual response/event API and PHP-FPM timeout settings.
- PH2: `queue()` and job run lifecycle may be used if supported; worker must reconstruct actor/scopes from DB, not from an old Laravel session.
- Cancellation stops model work where supported; it must not undo a posted ERP business action.

## Structured responses

Use JSON schema for report cards and citations when necessary. The server checks every `source_ref` and numeric value against prior tool output before returning it to browser. Never trust a model-suggested report URL or business amount without validating the source.

## Provider/model tests

1. `ErpCopilot::fake()` (check syntax against installed SDK) and asserts read tool used for the intended prompt.
2. Invalid model tool name → denied and logged.
3. Tool result no rows → truthful empty-state response.
4. Tool result with reconciliation failure → warning displayed; no claim of confirmed ledger reconciliation.
5. Cross-company adversarial input → no unauthorized data/tools.
6. Provider timeout/429 → bounded retry or safe failure without creating duplicate business work.
