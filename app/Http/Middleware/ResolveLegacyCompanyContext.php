<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Company context for the original business screens. It is the same trusted resolution as the API
 * (membership, branch grant, financial year), but an installation that has never run the company
 * foundation gets an actionable message instead of silently running without isolation.
 */
class ResolveLegacyCompanyContext extends ResolveCompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        $schema = app(\App\Support\Database\SchemaCache::class);
        $connection = \Illuminate\Support\Facades\DB::connection();
        if (!$schema->hasTable($connection, 'companies') || !Company::query()->exists()) {
            // A fresh installation has no business documents yet: its first company is created from the company picker.
            // An upgraded database with history must be assigned to its company by the backfill instead.
            $fresh = $schema->hasTable($connection, 'companies') && collect(['sales', 'purchases', 'journal_entries'])
                ->every(fn ($table) => !$schema->hasTable($connection, $table) || !\Illuminate\Support\Facades\DB::table($table)->exists());
            if ($fresh && !$request->expectsJson() && $request->user() && \Illuminate\Support\Facades\Route::has('company.select')) {
                return redirect()->route('company.select', ['manage' => 1])->with('status', 'Create your first company to start working.');
            }
            $message = 'Company foundation is not initialised. An administrator must run "php artisan erp:backfill-company-context" (see the company backfill runbook) before business screens can be used.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : response($message, 409);
        }
        // Screens need one company/branch; when the session cannot resolve it unambiguously, send the user to choose.
        if (!$request->expectsJson() && !$request->headers->has('X-Company-ID') && $request->user() && $request->hasSession()
            && !$request->session()->has(['company_id', 'branch_id', 'financial_year_id'])) {
            try {
                $session = fn (string $key) => $request->session()->has($key) ? (int) $request->session()->get($key) : null;
                app(\App\Services\Platform\CompanyContextResolver::class)->resolve($request->user()->id, $session('company_id'), $session('branch_id'));
            } catch (\App\Exceptions\CompanyFinancialYearSetupRequired) {
                // The parent sends administrators to financial-year setup.
            } catch (ValidationException) {
                if (\App\Services\Platform\MenuSwitches::isDeveloper($request->user())) {
                    return redirect()->route('developer.index');
                }
                return redirect()->route('company.select');
            }
        }

        return parent::handle($request, $next);
    }
}
