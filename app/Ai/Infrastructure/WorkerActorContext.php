<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\User;
use App\Services\Platform\BranchAccess;
use App\Services\Platform\CompanyContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/** Install an explicitly revalidated actor only for the duration of one queued ERP operation. */
final class WorkerActorContext
{
    public function run(AiPrincipal $reference, Closure $callback): mixed
    {
        $actor = User::find($reference->actorId);
        if (!$actor || !$actor->is_active || $actor->is_deleted) {
            throw new AuthorizationException('The queued actor is no longer active.');
        }
        $defaultGuard = Auth::getDefaultDriver();
        $guard = Auth::guard('web');
        $previousActor = $guard->user();
        $request = request();
        $previousResolver = $request->getUserResolver();
        $hadContext = $request->attributes->has(CompanyContext::class);
        $previousContext = $request->attributes->get(CompanyContext::class);

        try {
            // No login/session or permission grant is serialized. Existing services still require auth()->id().
            Auth::shouldUse('web');
            $guard->setUser($actor);
            $request->setUserResolver(static fn () => $actor);
            $request->attributes->remove(CompanyContext::class);
            app(BranchAccess::class)->forget();
            $principal = app(AiPrincipalFactory::class)->createForUser(
                $actor->id, $reference->companyId, $reference->branchId, $reference->financialYearId, $reference->requestId,
            );
            $request->attributes->set(CompanyContext::class,
                new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId));
            return $callback($principal);
        } finally {
            $previousActor ? $guard->setUser($previousActor) : $guard->forgetUser();
            Auth::shouldUse($defaultGuard);
            $request->setUserResolver($previousResolver);
            if ($hadContext) $request->attributes->set(CompanyContext::class, $previousContext);
            else $request->attributes->remove(CompanyContext::class);
            app(BranchAccess::class)->forget();
        }
    }
}
