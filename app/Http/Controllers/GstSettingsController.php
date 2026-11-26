<?php

namespace App\Http\Controllers;

use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\{CompanyContext, CompanyContextResolver};
use App\Services\Tax\{Gstin, GstSetupService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings › GST: registration, rate slabs, HSN/SAC, product and party tax in one place. */
class GstSettingsController extends Controller
{
    private function context(Request $request): CompanyContext { return $request->attributes->get(CompanyContext::class); }

    public function index(Request $request, GstSetupService $gst)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert('gst-index', $context, $request->user()->id);
        $scope = ['company_id' => $context->companyId];
        $today = now()->toDateString();
        $rates = DB::table('tax_rates')->where($scope)->orderBy('effective_from')->get()->groupBy('tax_category_id');
        $usage = DB::table('products')->where($scope)->whereNotNull('tax_category_id')->groupBy('tax_category_id')
            ->selectRaw('tax_category_id, count(*) as total')->pluck('total', 'tax_category_id');
        $categories = DB::table('tax_categories')->where($scope)->get()->map(function ($category) use ($rates, $usage, $today) {
            $history = $rates[$category->id] ?? collect();
            $category->current = $history->first(fn ($r) => $r->effective_from <= $today && (!$r->effective_to || $r->effective_to >= $today));
            $category->history = $history;
            $category->products = (int) ($usage[$category->id] ?? 0);
            return $category;
        })->sortBy(fn ($c) => [$c->supply_type === 'taxable' ? 1 : 0, (float) ($c->current->rate ?? 0), $c->name])->values();
        // All products by default; ones missing a GST rate or HSN/SAC come first.
        $filter = $request->query('products') === 'missing' ? 'missing' : 'all';
        $missingSql = "(tax_category_id IS NULL OR hsn_code IS NULL OR hsn_code = '')";
        $products = DB::table('products')->where($scope)->where('is_active', true)
            ->when($filter === 'missing', fn ($q) => $q->whereRaw($missingSql))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')->orWhere('code', 'like', '%'.$request->q.'%')))
            ->orderByRaw("CASE WHEN {$missingSql} THEN 0 ELSE 1 END")->orderBy('name')->limit(300)->get(['id', 'name', 'code', 'type', 'hsn_code', 'tax_category_id']);
        $codeUsage = DB::table('products')->where($scope)->whereNotNull('hsn_code')->groupBy('hsn_code')
            ->selectRaw('hsn_code, count(*) as total')->pluck('total', 'hsn_code');
        $profiles = DB::table('party_tax_profiles')->where($scope)->get()->keyBy(fn ($p) => $p->party_type.':'.$p->party_id);
        $parties = collect(['supplier' => 'suppliers', 'customer' => 'customers'])->flatMap(fn ($table, $type) => DB::table($table)->where($scope)
            ->where('is_active', true)->orderBy('name')->limit(300)->get()->map(fn ($p) => (object) ['type' => $type, 'id' => $p->id, 'name' => $p->name,
                'gstin' => $type === 'supplier' ? ($p->vat_number ?? null) : ($p->tax_no ?? null), 'state' => $p->state ?? null,
                'profile' => $profiles[$type.':'.$p->id] ?? null]));
        $ledgers = collect(GstSetupService::LEDGERS)->map(fn ($ledger, $role) => (object) ['role' => $role, 'name' => $ledger[1],
            'account' => DB::table('semantic_account_mappings as m')->join('chart_of_accounts as a', 'a.id', '=', 'm.account_id')
                ->where('m.company_id', $context->companyId)->where('m.semantic_role', $role)->first(['a.code', 'a.name'])]);
        return view('backend.gst.settings', [
            'registration' => $gst->registration($context),
            'registrations' => DB::table('tax_registrations')->where($scope)->where('branch_id', $context->branchId)->orderByDesc('effective_from')->get(),
            'categories' => $categories, 'codes' => DB::table('hsn_sac_codes')->where($scope)->orderBy('code')->get(),
            'codeUsage' => $codeUsage, 'products' => $products, 'productFilter' => $filter, 'parties' => $parties, 'ledgers' => $ledgers,
            'invoicePrint' => ($company = \App\Models\Company::findOrFail($context->companyId))->settings_json['invoice_print'] ?? [],
            'company' => $company, 'companyAddress' => app(\App\Services\Platform\CompanyProfileService::class)->fullAddress($company),
            'missing' => DB::table('products')->where($scope)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('tax_category_id')->orWhereNull('hsn_code')->orWhere('hsn_code', ''))->count(),
            'states' => array_diff_key(Gstin::$states, ['97' => 1]), 'gstMode' => $gst->enabled($context),
            'canManage' => app(CompanyContextResolver::class)->canManageFinancialYears($request->user()->id, $context->companyId),
            'legacyTaxes' => \Illuminate\Support\Facades\Schema::hasTable('taxes') ? DB::table('taxes')->where('is_active', true)->count() : 0,
        ]);
    }

    public function save(Request $request, string $action, GstSetupService $gst)
    {
        $context = $this->context($request); $actor = $request->user()->id;
        $message = match ($action) {
            'registration' => tap('GST registration saved.', fn () => $gst->registerCompany($request->only(['gstin', 'legal_name', 'trade_name', 'address', 'registration_type', 'state_code', 'effective_from']), $context, $actor)),
            'install' => (function () use ($gst, $context, $actor) {
                $result = $gst->installDefaults($context, $actor);
                return "Standard GST set up: {$result['categories']} new rates, {$result['products']} products mapped, {$result['parties']} parties synced.";
            })(),
            'category' => tap('GST rate added.', fn () => $gst->createCategory($request->only(['name', 'supply_type', 'rate', 'cess_rate', 'input_credit_allowed', 'effective_from']), $context, $actor)),
            'rename' => tap('GST rate updated.', fn () => $gst->updateCategory($request->integer('id'), $request->only(['name', 'input_credit_allowed']), $context, $actor)),
            'rate' => tap('New rate period saved. Bills already posted keep their old rate.', fn () => $gst->changeRate($request->integer('id'),
                (float) $request->input('rate'), (float) $request->input('cess_rate', 0), (string) $request->input('effective_from'), $context, $actor)),
            'category-delete' => tap('GST rate deleted.', fn () => $gst->deleteCategory($request->integer('id'), $context, $actor)),
            'code' => tap('HSN/SAC code saved.', fn () => $gst->saveCode($request->only(['code', 'kind', 'description']), $context, $actor)),
            'code-delete' => tap('HSN/SAC code deleted.', fn () => $gst->deleteCode((string) $request->input('code'), $context, $actor)),
            'products' => (function () use ($request, $gst, $context, $actor) {
                $rows = $request->validate(['products' => 'required|array|max:500', 'products.*.tax_category_id' => 'nullable|integer|min:1',
                    'products.*.hsn_code' => 'nullable|string|max:8'])['products'];
                DB::transaction(function () use ($rows, $gst, $context, $actor) {
                    foreach ($rows as $id => $row) {
                        $gst->assignProduct((int) $id, isset($row['tax_category_id']) ? (int) $row['tax_category_id'] : null, $row['hsn_code'] ?? null, $context, $actor);
                    }
                });
                return count($rows).' products saved.';
            })(),
            'parties' => (function () use ($gst, $context, $actor) {
                $gst->authorize($context, $actor);
                return $gst->syncAllParties($context->companyId).' party GST profiles synced from party masters.';
            })(),
            'print' => tap('Invoice print details saved. New bills carry them; posted bills keep what they printed with.', function () use ($request, $gst, $context, $actor) {
                $gst->authorize($context, $actor);
                $fields = $request->validate(['licences' => 'nullable|string|max:300', 'dispatch_from' => 'nullable|string|max:300', 'bank_name' => 'nullable|string|max:100',
                    'bank_account_name' => 'nullable|string|max:150', 'bank_account_no' => 'nullable|string|max:40', 'bank_ifsc' => 'nullable|string|max:20',
                    'bank_branch' => 'nullable|string|max:100', 'upi_id' => 'nullable|string|max:100', 'terms' => 'nullable|string|max:2000',
                    'declaration' => 'nullable|string|max:500', 'signatory' => 'nullable|string|max:100']);
                DB::transaction(function () use ($fields, $context) {
                    $company = \App\Models\Company::lockForUpdate()->findOrFail($context->companyId);
                    // Contact now lives on Company profile; contact saved here earlier stays as its fallback.
                    $legacy = array_intersect_key($company->settings_json['invoice_print'] ?? [], array_flip(['phone', 'email', 'website']));
                    $company->settings_json = ['invoice_print' => array_map(fn ($value) => is_string($value) ? trim($value) : $value, $fields) + $legacy] + ($company->settings_json ?? []);
                    $company->save();
                });
            }),
            'garment-band' => tap('Garment rate by sale value added: up to ₹2,500 a piece 5%, above 18%. Assign it to garments and made-ups.',
                fn () => $gst->installGarmentBands($context, $actor)),
            'trade' => tap('Export and TDS settings saved.', fn () => $gst->saveTradeSettings($request->only(['lut_number', 'lut_valid_from', 'lut_valid_to', 'tds_194q']), $context, $actor)),
            'party' => tap('Party GST profile saved.', fn () => app(\App\Services\Tax\TaxSetupService::class)->save('party',
                $request->only(['party_type', 'party_id', 'registration_type', 'gstin', 'state_code']), $context, $actor)),
            default => abort(404),
        };
        return $request->expectsJson() ? response()->json(['success' => true, 'message' => $message])
            : redirect()->to(url('/settings/gst').'#'.$request->input('tab', 'rates'))->with('message', $message);
    }
}
