<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Request metadata only: never log URL query strings, input, credentials or exception messages. */
class RequestCorrelation
{
    public function handle(Request $request, Closure $next)
    {
        $provided = $request->header('X-Request-ID') ?? $request->header('X-Correlation-ID');
        $id = is_string($provided) && preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D', $provided) ? $provided : (string) Str::uuid();
        $request->attributes->set('erp.request_id', $id);
        $request->attributes->set('erp.correlation_id', $id);
        $start = hrtime(true);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('X-Correlation-ID', $id);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($request->user()) $response->headers->set('Cache-Control', 'private, no-store');
        Log::info('erp.http', ['request_id' => $id, 'correlation_id' => $id, 'route' => $request->route()?->getName(),
            'method' => $request->method(), 'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $start) / 1000000, 2), 'actor_id' => $request->user()?->id,
            'company_id' => $request->attributes->get('erp.company_id'), 'branch_id' => $request->attributes->get('erp.branch_id')]);
        return $response;
    }
}
