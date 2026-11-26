<?php

namespace App\Services\Platform;

use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyContextResolver
{
    public function resolve(
        int $userId,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $financialYearId = null,
        ?string $businessDate = null,
    ): CompanyContext {
        $company = $this->authorizedCompany($userId, $companyId);
        $companyId = $company->id;

        $branches = $company->branches()->where('is_active', true)
            ->whereIn('id', DB::table('company_user_branches')->select('branch_id')
                ->where('company_id', $companyId)->where('user_id', $userId));
        if ($branchId === null) {
            $authorized = (clone $branches)->get();
            if ($authorized->count() !== 1) {
                throw ValidationException::withMessages(['branch_id' => 'Select an authorized branch.']);
            }
            $branchId = $authorized->sole()->id;
        } elseif (!(clone $branches)->whereKey($branchId)->exists()) {
            throw new AuthorizationException('Branch access denied.');
        }

        $years = $company->fiscalYears();
        if ($financialYearId !== null) {
            $year = $years->whereKey($financialYearId)->first();
            if (!$year) {
                throw new AuthorizationException('Financial year access denied.');
            }
            if ($businessDate !== null) {
                $this->assertWithinYear($year, $this->date($businessDate));
            }
        } else {
            $date = $businessDate === null ? CarbonImmutable::now($company->timezone)->toDateString() : $this->date($businessDate);
            $matches = $years->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->get();
            if ($matches->count() !== 1) {
                throw new \App\Exceptions\CompanyFinancialYearSetupRequired($companyId);
            }
            $year = $matches->sole();
        }

        // Historical reads are allowed even when the year is closed.
        return new CompanyContext($companyId, $branchId, $year->id);
    }

    /** Revalidate explicit or request context for every service caller, including non-HTTP jobs. */
    public function forActor(?CompanyContext $context = null, ?int $userId = null): CompanyContext
    {
        $authenticatedId = auth()->id();
        if ($authenticatedId && $userId && (int) $authenticatedId !== $userId) {
            throw new AuthorizationException('Posting actor must match the authenticated user.');
        }
        $userId = $userId ?: $authenticatedId;
        if (!$userId) {
            throw new AuthorizationException('An authorized posting actor is required.');
        }
        $context ??= request()->attributes->get(CompanyContext::class);
        if (!$context && (request()->hasHeader('X-Company-ID') || request()->hasHeader('X-Branch-ID')
            || request()->hasHeader('X-Financial-Year-ID'))) {
            throw new AuthorizationException('Request context must be authorized by company middleware.');
        }
        return $this->resolve($userId, $context?->companyId, $context?->branchId, $context?->financialYearId);
    }

    /** Company authorization is also available before a branch/FY is configured. */
    public function authorizedCompany(int $userId, ?int $companyId = null): Company
    {
        $user = User::find($userId);
        if (!$user || !$user->is_active || $user->is_deleted) {
            throw new AuthorizationException('Active user required for company access.');
        }
        $memberships = DB::table('company_user')->where('user_id', $userId);
        if ($companyId === null) {
            $defaults = (clone $memberships)->where('is_default', true)->pluck('company_id');
            if ($defaults->count() !== 1) {
                throw ValidationException::withMessages(['company_id' => 'Select a company with an authorized membership.']);
            }
            $companyId = (int) $defaults->sole();
        }
        if (!(clone $memberships)->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('Company access denied.');
        }
        $company = Company::whereKey($companyId)->where('status', 'active')->first();
        if (!$company) {
            throw new AuthorizationException('Company access denied.');
        }
        if (!MenuSwitches::isDeveloper($user)) {
            $license = app(LicenseService::class)->snapshot($company->id);
            if ($license['is_blocked']) {
                throw new \App\Exceptions\CompanyLicenseBlockedException(
                    $company->id,
                    (string) $license['status'],
                    (string) ($license['block_reason'] ?: 'Company license is not active.')
                );
            }
        }
        if (!in_array($company->timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['company_id' => 'Company timezone requires valid setup.']);
        }

        return $company;
    }

    public function canManageFinancialYears(int $userId, int $companyId): bool
    {
        $membership = DB::table('company_user')->where('company_id', $companyId)->where('user_id', $userId)->first();
        $user = User::find($userId);
        if (!$membership || !$user || !$user->is_active || $user->is_deleted) {
            return false;
        }
        $roleId = (int) ($membership->role_id_override ?? $user->role_id);
        // Existing RoleController/SettingController reserve administration for Admin/Owner (1/2).
        return in_array($roleId, [1, 2], true)
            && DB::table('roles')->where('id', $roleId)->where('is_active', true)->exists();
    }

    public function assertPostingDate(CompanyContext $context, string $businessDate): void
    {
        $year = FiscalYear::where('company_id', $context->companyId)->whereKey($context->financialYearId)->lockForUpdate()->first();
        if (!$year) {
            throw new AuthorizationException('Financial year access denied.');
        }
        $date = $this->date($businessDate);
        $this->assertWithinYear($year, $date);
        if ($year->is_closed || $year->status !== 'open' || ($year->lock_date && $date <= $year->lock_date->toDateString())) {
            throw ValidationException::withMessages(['business_date' => 'Financial year or business date is locked for posting.']);
        }
    }

    private function date(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || (int) $parts[1] < 1000
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ValidationException::withMessages(['business_date' => 'Business date must be a valid YYYY-MM-DD date.']);
        }

        return $value;
    }

    private function assertWithinYear(FiscalYear $year, string $date): void
    {
        if ($date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw ValidationException::withMessages(['business_date' => 'Business date is outside the selected financial year.']);
        }
    }
}
