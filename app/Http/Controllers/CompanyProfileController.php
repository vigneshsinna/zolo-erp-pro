<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Industry\{IndustryCatalog, IndustryProfileService};
use App\Services\Platform\{CapabilityCatalog, CapabilityService, CompanyContext, CompanyContextResolver, CompanyProfileService};
use App\Services\Tax\{Gstin, GstSetupService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\Rule;

/** Settings › Company profile: the current company's master details, logo, currency and industry. */
class CompanyProfileController extends Controller
{
    private function context(Request $request): CompanyContext
    {
        $context = $request->attributes->get(CompanyContext::class);
        abort_unless(app(CompanyContextResolver::class)->canManageFinancialYears($request->user()->id, $context->companyId), 403, 'Company administrator required.');
        return $context;
    }

    public function edit(Request $request)
    {
        $context = $this->context($request);
        $industry = Schema::hasTable('company_industry_settings') ? app(IndustryProfileService::class)->settings($context) : null;
        return view('backend.company.profile', [
            'company' => Company::findOrFail($context->companyId),
            'registration' => GstSetupService::available() ? app(GstSetupService::class)->registration($context) : null,
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'appCurrencyId' => Schema::hasTable('general_settings') ? (int) DB::table('general_settings')->latest()->value('currency') : 0,
            'states' => array_diff_key(Gstin::$states, ['97' => 1]),
            'industry' => $industry, 'industries' => collect(CapabilityCatalog::PROFILES)->map(fn ($profile) => $profile[0])->all(),
            'subtypes' => IndustryCatalog::SUBTYPES,
            'features' => collect(CapabilityCatalog::SWITCHABLE)->mapWithKeys(fn ($key) => [$key => [
                'label' => CapabilityCatalog::DEFINITIONS[$key][0], 'enabled' => app(CapabilityService::class)->enabled($key, $context),
            ]])->all(),
        ]);
    }

    public function update(Request $request, CompanyProfileService $profiles)
    {
        $context = $this->context($request); $actor = $request->user()->id;
        $input = $request->only([...CompanyProfileService::FIELDS, 'gstin', 'registration_type', 'gst_effective_from']);
        $company = $profiles->save(Company::findOrFail($context->companyId), $input, $request->file('logo'), $context, $actor);
        if ($request->boolean('app_currency') && $company->base_currency_id && Schema::hasTable('general_settings')) {
            DB::table('general_settings')->where('id', DB::table('general_settings')->latest()->value('id'))->update(['currency' => $company->base_currency_id]);
            cache()->forget('general_setting');
        }
        $industryChanged = false;
        if ($request->filled('industry') && Schema::hasTable('company_industry_settings')) {
            $request->validate(['industry' => ['required', Rule::in(array_keys(IndustryCatalog::SUBTYPES))], 'subtype' => 'nullable|string|max:50']);
            $current = app(IndustryProfileService::class)->settings($context);
            if ([$current['profile'], $current['subtype']] !== [$request->input('industry'), $request->input('subtype')]) {
                app(IndustryProfileService::class)->apply($request->input('industry'), $request->input('subtype'), $context, $actor);
                $industryChanged = true;
            }
        }
        // A new industry applies its own preset; otherwise the Features checkboxes switch existing features on or off.
        if ($request->boolean('features_submitted') && !$industryChanged) {
            $request->validate(['features' => 'array', 'features.*' => ['string', Rule::in(CapabilityCatalog::SWITCHABLE)]]);
            $capabilities = app(CapabilityService::class);
            foreach (CapabilityCatalog::SWITCHABLE as $key) {
                $wanted = in_array($key, $request->input('features', []), true);
                if ($wanted !== $capabilities->enabled($key, $context)) {
                    $wanted ? $capabilities->enable($key, [], $context, $actor) : $capabilities->disable($key, $context, $actor);
                }
            }
        }
        return redirect()->route('settings.company')->with('message', 'Company profile saved. New bills print these details; posted bills keep the details they were posted with.');
    }
}
