<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Platform\CompanyDirectoryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBootstrapAdminContext
{
    public function __construct(
        private readonly CompanyDirectoryService $directory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$this->directory->isInstallationAdmin((int) $user->id)) {
            return response()->json([
                'message' => 'Installation administrator required for bootstrap setup.',
                'error_code' => 'BOOTSTRAP_ADMIN_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}

