<?php

namespace App\Http\Middleware;

use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResolveCompanyContext
{
    public function __construct(private CompanyContextResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            throw new AuthorizationException('Active user required for company access.');
        }

        $companyId = $this->id($request, 'X-Company-ID', 'company_id');
        $sessionCompany = $request->hasSession() ? $request->session()->get('company_id') : null;
        // A company header selects a new tuple; old-company dependent session IDs are invalid.
        $useSessionDependents = !$request->headers->has('X-Company-ID')
            || ($sessionCompany !== null && filter_var($sessionCompany, FILTER_VALIDATE_INT) === $companyId);
        try {
            $context = $this->resolver->resolve(
                $user->id,
                $companyId,
                $this->id($request, 'X-Branch-ID', 'branch_id', $useSessionDependents),
                $this->id($request, 'X-Financial-Year-ID', 'financial_year_id', $useSessionDependents),
            );
        } catch (\App\Exceptions\CompanyLicenseBlockedException $error) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $error->getMessage(),
                    'license_status' => $error->licenseStatus,
                    'company_id' => $error->companyId,
                ], 403);
            }
            return response()->view('backend.developer.blocked', [
                'message' => $error->getMessage(),
                'status' => $error->licenseStatus,
                'company' => \App\Models\Company::find($error->companyId),
            ], 403);
        } catch (\App\Exceptions\CompanyFinancialYearSetupRequired $error) {
            $admin = $this->resolver->canManageFinancialYears($user->id, $error->companyId);
            if (!$request->expectsJson() && $admin) {
                return redirect()->route('company.financial-years.setup', ['company_id' => $error->companyId]);
            }

            return response()->json([
                'message' => $admin ? 'Company financial year requires setup or explicit historical selection.' : 'Ask a company administrator to configure a financial year.',
                'errors' => $error->errors(),
                'setup_url' => $admin ? route('api.v1.company.financial-years.setup', ['company_id' => $error->companyId]) : null,
            ], 409);
        }
        $request->attributes->set(CompanyContext::class, $context);
        $request->attributes->set('erp.company_id', $context->companyId);
        $request->attributes->set('erp.branch_id', $context->branchId);
        try {
            return $next($request);
        } finally {
            // Context belongs to this request, including under persistent workers.
            $request->attributes->remove(CompanyContext::class);
        }
    }

    private function id(Request $request, string $header, string $sessionKey, bool $useSession = true): ?int
    {
        $value = $request->header($header);
        if ($value === null && $useSession && $request->hasSession()) {
            $value = $request->session()->get($sessionKey);
        }
        if ($value === null) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw ValidationException::withMessages([$sessionKey => 'Context ID must be a positive integer.']);
        }

        return $id;
    }
}
