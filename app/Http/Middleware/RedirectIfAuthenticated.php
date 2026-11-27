<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        if (Auth::guard($guard)->check()) {
            if (\App\Services\Platform\MenuSwitches::isDeveloper(Auth::guard($guard)->user())) {
                return redirect()->route('developer.index');
            }
            return redirect('/dashboard');
        }

        return $next($request);
    }
}
