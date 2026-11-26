<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Services\Tax\{Gstin, GstSetupService};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company master details: names, address, contact, PAN, currency and logo. Documents print them, and a GSTIN given here
 * registers (or updates) the branch's GST registration so the invoice seller name and address always follow the company.
 */
class CompanyProfileService
{
    public const LOGO_DIR = 'images/company';
    public const FIELDS = ['legal_name', 'trade_name', 'address', 'city', 'state_code', 'postal_code', 'phone', 'email', 'website', 'pan', 'base_currency_id'];

    public function rules(): array
    {
        return [
            'legal_name' => 'required|string|max:191', 'trade_name' => 'nullable|string|max:191',
            'address' => 'nullable|string|max:1000', 'city' => 'nullable|string|max:100',
            'state_code' => ['nullable', Rule::in(array_map('strval', array_keys(Gstin::$states)))],
            'postal_code' => 'nullable|regex:/^[0-9]{6}$/', 'phone' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191', 'website' => 'nullable|string|max:191',
            'pan' => 'nullable|regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/',
            'base_currency_id' => 'nullable|integer|exists:currencies,id',
            'gstin' => 'nullable|string|max:15', 'registration_type' => 'nullable|in:regular,composition,unregistered',
            'gst_effective_from' => 'nullable|date_format:Y-m-d',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:1024',
            'allow_reverse_edited_orders' => 'nullable|boolean',
        ];
    }

    /** Validates and saves; $context is the branch whose GST registration a GSTIN updates. */
    public function save(Company $company, array $input, ?UploadedFile $logo, CompanyContext $context, int $actor): Company
    {
        if ($context->companyId !== $company->id || !app(CompanyContextResolver::class)->canManageFinancialYears($actor, $company->id)) {
            throw new AuthorizationException('Company administrator required.');
        }
        $data = validator($input + ['logo' => $logo], $this->rules())->validate();
        $gstin = trim((string) ($data['gstin'] ?? '')) !== '' ? Gstin::normalize($data['gstin']) : null;
        $pan = isset($data['pan']) ? strtoupper($data['pan']) : null;
        $unregistered = ($data['registration_type'] ?? null) === 'unregistered';
        if ($unregistered && $gstin) throw ValidationException::withMessages(['gstin' => 'Leave GSTIN empty for an unregistered business, or choose Regular / Composition.']);
        if ($gstin) {
            if ($pan && $pan !== substr($gstin, 2, 10)) throw ValidationException::withMessages(['pan' => 'PAN must match characters 3–12 of the GSTIN.']);
            if (trim((string) ($data['address'] ?? '')) === '') throw ValidationException::withMessages(['address' => 'Enter the registered address for the GSTIN.']);
            $pan ??= substr($gstin, 2, 10);
            $data['state_code'] = substr($gstin, 0, 2);
        }

        return DB::transaction(function () use ($company, $data, $gstin, $unregistered, $pan, $logo, $context, $actor, $input) {
            $company = Company::lockForUpdate()->findOrFail($company->id);
            $before = $company->only([...self::FIELDS, 'logo']);
            $values = array_intersect_key($data, array_flip(self::FIELDS));
            if (array_key_exists('pan', $data) || $gstin) $values['pan'] = $pan;
            $values = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $values);
            if ($logo) $values['logo'] = $this->storeLogo($company, $logo);
            if (array_key_exists('allow_reverse_edited_orders', $data) || array_key_exists('allow_reverse_edited_orders', $input)) {
                $settings = $company->settings_json ?? [];
                $settings['allow_reverse_edited_orders'] = !empty($data['allow_reverse_edited_orders'] ?? $input['allow_reverse_edited_orders'] ?? false);
                $values['settings_json'] = $settings;
            }
            $company->forceFill($values)->save();
            // A GSTIN, or the Unregistered type (no GSTIN; state from the company), keeps the branch's GST registration in step.
            if ($gstin || $unregistered) {
                $gst = app(GstSetupService::class);
                $open = $gst->registration($context);
                $type = $unregistered ? 'unregistered' : ($data['registration_type'] ?? (($open->registration_type ?? null) === 'composition' ? 'composition' : 'regular'));
                $effectiveFrom = $data['gst_effective_from'] ?? null;
                if (!$effectiveFrom) {
                    if ($open && $open->gstin === $gstin) {
                        $effectiveFrom = substr((string) $open->effective_from, 0, 10);
                    } elseif (!$open && $unregistered && ($fyStart = DB::table('fiscal_years')->where('id', $context->financialYearId)->value('start_date'))) {
                        $effectiveFrom = substr((string) $fyStart, 0, 10);
                    } else {
                        $effectiveFrom = now()->toDateString();
                    }
                }
                $gst->registerCompany(['gstin' => $gstin, 'legal_name' => $company->legal_name, 'trade_name' => $company->trade_name,
                    'address' => $this->fullAddress($company), 'registration_type' => $type, 'state_code' => $company->state_code,
                    'effective_from' => $effectiveFrom],
                    $context, $actor);
            }
            if (Schema::hasTable('company_setup_audits')) {
                DB::table('company_setup_audits')->insert(['company_id' => $company->id, 'actor_id' => $actor, 'action' => 'company_profile',
                    'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_json' => json_encode($company->only([...self::FIELDS, 'logo']) + ['gstin' => $gstin], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
            return $company->refresh();
        });
    }

    /** One line for invoices and the GST registration: street, city, state and PIN. */
    public function fullAddress(Company $company): string
    {
        $state = $company->state_code ? (Gstin::$states[$company->state_code] ?? null) : null;
        return implode(', ', array_filter([$company->address, $company->city, trim(($state ?? '').' '.($company->postal_code ?? ''))]));
    }

    /** Each upload gets a new file so documents posted with an older logo keep printing it. */
    private function storeLogo(Company $company, UploadedFile $logo): string
    {
        $name = 'company-'.$company->id.'-'.now()->format('YmdHis').'-'.substr(bin2hex(random_bytes(4)), 0, 8).'.'.$logo->guessExtension();
        $logo->move(public_path(self::LOGO_DIR), $name);
        return self::LOGO_DIR.'/'.$name;
    }
}
