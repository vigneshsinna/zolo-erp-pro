<?php

namespace App\Http\Controllers;

use App\Exceptions\CompanyFinancialYearSetupRequired;
use App\Models\Company;
use App\Services\Industry\IndustryCatalog;
use App\Services\Platform\CapabilityCatalog;
use App\Services\Platform\CompanyContextResolver;
use App\Services\Platform\CompanyDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Post-login group/company picker. Runs before company context exists, so it never reads scoped business data. */
class CompanySelectorController extends Controller
{
    private const SESSION_KEYS = ['company_id', 'branch_id', 'financial_year_id'];

    public function __construct(private CompanyDirectoryService $directory, private CompanyContextResolver $resolver)
    {
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $directory = $this->directory->directory($userId);
        $only = collect($directory['groups'])->flatMap(fn ($group) => $group['companies'])->merge($directory['ungrouped']);
        // A single company with a single branch needs no choice; send the user straight in.
        if (!$request->boolean('manage') && $only->count() === 1 && count($only->first()['branches']) === 1) {
            return $this->enter($request, $only->first()['id']);
        }
        $isAdmin = $this->directory->isInstallationAdmin($userId);
        $today = CarbonImmutable::now(config('app.timezone'));
        $fyStart = $today->month >= 4 ? $today->setDate($today->year, 4, 1) : $today->setDate($today->year - 1, 4, 1);

        return view('backend.company.select', [
            'user' => $request->user(),
            'directory' => $directory,
            'currentCompanyId' => (int) $request->session()->get('company_id'),
            'isAdmin' => $isAdmin,
            'manageableGroups' => collect($directory['groups'])->where('manageable', true)->values(),
            'ownCompanies' => $isAdmin ? Company::whereNull('company_group_id')->where('status', 'active')
                ->whereIn('id', DB::table('company_user')->where('user_id', $userId)->select('company_id'))
                ->orderBy('legal_name')->get(['id', 'code', 'legal_name']) : collect(),
            'industries' => collect(CapabilityCatalog::PROFILES)->map(fn ($profile) => $profile[0])->all(),
            'subtypes' => IndustryCatalog::SUBTYPES,
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCurrencyId' => DB::table('currencies')->where('code', 'INR')->value('id') ?? DB::table('general_settings')->latest()->value('currency'),
            'states' => array_diff_key(\App\Services\Tax\Gstin::$states, ['97' => 1]),
            'defaults' => ['timezone' => config('app.timezone'), 'fy_start' => $fyStart->toDateString(),
                'fy_end' => $fyStart->addYear()->subDay()->toDateString()],
            'theme' => $_COOKIE['theme'] ?? 'light',
            'deletedCompanies' => $this->directory->deletedCompanies($userId),
        ]);
    }

    public function choose(Request $request)
    {
        $data = $request->validate([
            'company_id' => 'required|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'financial_year_id' => 'nullable|integer|min:1',
        ]);

        return $this->enter($request, $data['company_id'], $data['branch_id'] ?? null, $data['financial_year_id'] ?? null);
    }

    public function storeGroup(Request $request)
    {
        $group = $this->directory->createGroup($request->user()->id, $request->only(['name', 'code', 'company_ids']));

        return redirect()->route('company.select', ['manage' => 1])->with('status', "Group {$group->name} created.");
    }

    public function storeCompany(Request $request)
    {
        if (!$request->has('registration_type') && $request->filled('gstin')) {
            $request->merge(['registration_type' => 'regular']);
        }
        $request->validate([
            'registration_type' => 'required|in:regular,composition,unregistered',
        ]);

        $company = $this->directory->createCompany($request->user()->id, $request->only([
            'company_group_id', 'legal_name', 'trade_name', 'code', 'industry', 'subtype',
            'state_code', 'base_currency_id', 'timezone', 'fy_start', 'fy_end',
            'address', 'city', 'postal_code', 'phone', 'email', 'website', 'pan', 'gstin', 'registration_type',
        ]), $request->file('logo'));

        return redirect()->route('company.select', ['manage' => 1])
            ->with('status', ($company->trade_name ?: $company->legal_name).' is ready. Open it to start working.');
    }

    public function updateCompany(Request $request, int $company)
    {
        if (!$request->has('registration_type') && $request->filled('gstin')) {
            $request->merge(['registration_type' => 'regular']);
        }
        $request->validate([
            'registration_type' => 'required|in:regular,composition,unregistered',
        ]);

        $updated = $this->directory->updateCompany($request->user()->id, $company, $request->only([
            'company_group_id', 'legal_name', 'trade_name', 'code', 'state_code', 'base_currency_id',
            'address', 'city', 'postal_code', 'phone', 'email', 'website', 'pan', 'gstin', 'registration_type',
        ]), $request->file('logo'));

        return redirect()->route('company.select', ['manage' => 1])->with('status', ($updated->trade_name ?: $updated->legal_name).' saved.');
    }

    public function deleteCompany(Request $request, int $company)
    {
        $deleted = $this->directory->deleteCompany($request->user()->id, $company, (string) $request->input('confirm_code', ''));
        if ((int) $request->session()->get('company_id') === $deleted->id) {
            $request->session()->forget(self::SESSION_KEYS);
        }

        return redirect()->route('company.select', ['manage' => 1])
            ->with('status', ($deleted->trade_name ?: $deleted->legal_name).' deleted. Its books are kept; restore it from Deleted companies.');
    }

    public function restoreCompany(Request $request, int $company)
    {
        $restored = $this->directory->restoreCompany($request->user()->id, $company);

        return redirect()->route('company.select', ['manage' => 1])->with('status', ($restored->trade_name ?: $restored->legal_name).' restored.');
    }

    public function updateGroup(Request $request, int $group)
    {
        $updated = $this->directory->updateGroup($request->user()->id, $group, $request->only(['name', 'code']));

        return redirect()->route('company.select', ['manage' => 1])->with('status', "Group {$updated->name} saved.");
    }

    public function deleteGroup(Request $request, int $group)
    {
        $this->directory->deleteGroup($request->user()->id, $group);

        return redirect()->route('company.select', ['manage' => 1])->with('status', 'Group deleted. Its companies are now independent.');
    }

    private function enter(Request $request, int $companyId, ?int $branchId = null, ?int $yearId = null)
    {
        try {
            $context = $this->resolver->resolve($request->user()->id, $companyId, $branchId, $yearId);
        } catch (\App\Exceptions\CompanyLicenseBlockedException $error) {
            return redirect()->route('company.select', ['manage' => 1])
                ->withErrors(['company_id' => $error->getMessage()]);
        } catch (CompanyFinancialYearSetupRequired $error) {
            if (!$this->resolver->canManageFinancialYears($request->user()->id, $error->companyId)) {
                return redirect()->route('company.select', ['manage' => 1])
                    ->withErrors(['company_id' => 'This company has no open financial year for today. Ask its administrator to set one up.']);
            }
            $request->session()->forget(self::SESSION_KEYS);
            $request->session()->put('company_id', $error->companyId);

            return redirect()->route('company.financial-years.setup', ['company_id' => $error->companyId]);
        } catch (ValidationException $error) {
            return redirect()->route('company.select', ['manage' => 1])->withErrors($error->errors());
        }
        $request->session()->forget(self::SESSION_KEYS);
        $request->session()->put(['company_id' => $context->companyId, 'branch_id' => $context->branchId,
            'financial_year_id' => $context->financialYearId]);

        // A switch started from the workspace lands on the new company's workspace.
        return $request->input('return') === 'workspace' ? redirect('/workspace') : redirect()->intended('/dashboard');
    }
}
