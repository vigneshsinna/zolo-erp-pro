<?php

namespace App\Http\Middleware;

use App\Services\Platform\MenuSwitches;
use Closure;
use Illuminate\Http\Request;

/** Refuses screens (and their AJAX calls) under a section the developer switched off for the active company. */
class EnforceMenuSwitches
{
    public function handle(Request $request, Closure $next)
    {
        $switches = app(MenuSwitches::class);
        if ($switches->allowsRequest($request)) return $next($request);
        $message = ($switches->blockedBy($request) ?? 'This screen').' is switched off for this company. Ask your developer to switch it on.';
        if ($request->expectsJson() || $request->ajax()) return response()->json(['message' => $message], 403);
        return redirect('/dashboard')->with('not_permitted', $message);
    }
}
