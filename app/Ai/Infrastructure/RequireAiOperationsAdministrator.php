<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Horizon exposes installation-wide worker payloads and requires an explicit operator allowlist. */
final class RequireAiOperationsAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(CompanyContext::class);
        $user = $request->user();
        abort_unless($user && $context instanceof CompanyContext && $user->is_active && !$user->is_deleted, 403);
        abort_unless(in_array((int) $user->id, config('ai_infrastructure.operations_admin_actor_ids', []), true), 403);
        $resolver = app(CompanyContextResolver::class);
        $resolver->forActor($context, (int) $user->id);
        abort_unless($resolver->canManageFinancialYears((int) $user->id, $context->companyId), 403);
        return $next($request);
    }
}
