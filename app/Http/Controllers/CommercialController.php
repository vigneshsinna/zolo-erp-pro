<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Area;
use App\Models\BillSundry;
use App\Models\Customer;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseType;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\StandardRemark;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Commercial\CommercialDraftService;
use App\Services\Commercial\CommercialPermission;
use App\Services\Commercial\CommercialPricing;
use App\Services\Commercial\CommercialReversalService;
use App\Services\Commercial\CreditControlService;
use App\Services\Commercial\LegacyCommercialCommand;
use App\Services\Commercial\PartyQueryService;
use App\Services\Commercial\ProductQueryService;
use App\Services\Commercial\SaleApplicationService;
use App\Services\Commercial\SaleCommand;
use App\Services\Commercial\PurchaseApplicationService;
use App\Services\Commercial\PurchaseCommand;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\ERP\PaymentService;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialController extends Controller
{
    private function context(Request $request): CompanyContext
    {
        return $request->attributes->get(CompanyContext::class);
    }

    private function idempotencyKey(Request $request, int $max = 150): string
    {
        $key = $request->hasHeader('Idempotency-Key') ? $request->header('Idempotency-Key') : $request->input('idempotency_key', '');
        abort_unless(is_string($key) && trim($key) !== '' && strlen($key) <= $max,
            422, 'An idempotency key of at most '.$max.' characters is required.');
        return $key;
    }

    public function entry(Request $request, string $kind)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $request->user()->id);
        $industry = config('operations.enabled') && \Illuminate\Support\Facades\Schema::hasTable('company_industry_settings')
            ? app(\App\Services\Industry\IndustryProfileService::class)->settings($context) : ['profile' => 'general_trading', 'settings' => ['quantity_scale' => 4]];
        $project = null;
        if ($kind === 'sale' && $request->filled('project_id')) {
            $request->validate(['project_id' => 'integer|min:1']);
            app(\App\Services\Operations\OperationPosting::class)->authorize('operations.projects', 'projects.manage', $context, $request->user()->id);
            $project = \App\Models\Operations\Project::visibleIn($context)->findOrFail($request->project_id);
        }
        $exchangeReturn = null;
        if ($kind === 'sale' && $request->filled('exchange_return_id')) {
            abort_unless(config('compliance.enabled'), 503);
            $exchangeReturn = \App\Models\Returns::forCompany($context)->where('branch_id', $context->branchId)->whereNotNull('posted_at')
                ->where('note_type', 'credit')->where('adjustment_type', 'quantity')->findOrFail($request->integer('exchange_return_id'));
        }
        return view('backend.commercial.entry', [
            'kind' => $kind, 'context' => $context, 'exchangeReturn' => $exchangeReturn,
            'industry' => $industry, 'project' => $project,
            'dimensionsEnabled' => config('operations.enabled') && app(\App\Services\Platform\CapabilityService::class)->enabled('inventory.dimension_tracking', $context),
            'schemes' => $kind === 'sale' && $industry['profile'] === 'fmcg'
                ? DB::table('sales_quantity_schemes')->where('company_id', $context->companyId)->where('is_active', true)->get() : collect(),
            'warehouses' => Warehouse::forCompany($context)->where('branch_id', $context->branchId)
                ->when(config('compliance.enabled'), fn ($q) => $q->where('is_quarantine', false))
                ->when(config('operations.enabled'), fn ($q) => $q->whereNull('external_job_order_id'))->get(['id', 'name']),
            'units' => DB::table('units')->where('company_id', $context->companyId)->get(['id', 'unit_name']),
            'categories' => DB::table('categories')->where('company_id', $context->companyId)->get(['id', 'name']),
            'groups' => $kind === 'sale' ? DB::table('customer_groups')->where('company_id', $context->companyId)->get(['id', 'name']) : collect(),
            'accounts' => DB::table('accounts')->where('company_id', $context->companyId)->get(['id', 'name']),
            'saleTypes' => DB::table('sale_types')->where('company_id', $context->companyId)->where('is_active', true)->get(),
            'purchaseTypes' => DB::table('purchase_types')->where('company_id', $context->companyId)->where('is_active', true)->get(),
            'billSundries' => DB::table('bill_sundries')->where('company_id', $context->companyId)->where('is_active', true)
                ->where(fn($q) => $q->where('nature', $kind === 'sale' ? 'sales' : 'purchase')->orWhere('nature', 'both'))->get(),
            'agents' => DB::table('agents')->where('company_id', $context->companyId)->where('is_active', true)->get(),
            'areas' => DB::table('areas')->where('company_id', $context->companyId)->where('is_active', true)->get(),
            'remarks' => DB::table('standard_remarks')->where('company_id', $context->companyId)->where('is_active', true)
                ->where(fn($q) => $q->where('type', $kind)->orWhere('type', 'all'))->get(),
            'documentSeries' => DB::table('document_series')->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)
                ->where('document_type', $kind)->get(),
            'businessDate' => \Carbon\CarbonImmutable::now(\App\Models\Company::findOrFail($context->companyId)->timezone)->toDateString(),
            'company' => \App\Models\Company::find($context->companyId),
            'recentBills' => ($kind === 'sale' ? \App\Models\Sale::class : \App\Models\Purchase::class)::visibleIn($context)
                ->orderByDesc('id')->limit(25)->get(),
        ]);
    }

    public function store(Request $request, string $kind, bool $legacy = false)
    {
        $context = $this->context($request);
        $data = $legacy ? app(LegacyCommercialCommand::class)->data($request, $kind === 'purchase', $context) : $request->all();
        $key = $this->idempotencyKey($request);
        if ($request->filled('draft_id')) {
            app(CommercialDraftService::class)->assertPostable($kind, $request->integer('draft_id'), $context, $request->user()->id);
        }
        $document = DB::transaction(function () use ($request, $kind, $data, $key, $context) {
            $document = $kind === 'sale'
                ? app(SaleApplicationService::class)->create(new SaleCommand($data, $key, $request->user()->id, $context))
                : app(PurchaseApplicationService::class)->create(new PurchaseCommand($data, $key, $request->user()->id, $context));
            if ($request->filled('draft_id')) {
                app(CommercialDraftService::class)->query($kind, $context, $request->user()->id)->where('id', $request->draft_id)->delete();
            }
            return $document;
        });
        if ($legacy && $request->boolean('pos')) {
            return response()->json($document->id);
        }
        if ($legacy && !$request->expectsJson()) {
            return redirect($kind === 'sale' ? '/sales' : '/purchases')->with('message', 'Document posted successfully.');
        }
        return response()->json(['success' => true, 'data' => $document, 'message' => 'Document posted successfully.'], 201);
    }

    public function preview(Request $request, string $kind)
    {
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $this->context($request), $request->user()->id);
        $data = $request->has('items') ? $request->all()
            : app(LegacyCommercialCommand::class)->data($request, $kind === 'purchase', $this->context($request));
        return response()->json(['data' => app(CommercialPricing::class)->preview($data, $kind === 'purchase', $this->context($request))]);
    }

    public function reverse(Request $request, string $kind, int $id)
    {
        if (!$request->expectsJson() && !$request->filled('reason')) {
            return redirect('/commercial/'.$kind.'/'.$id.'/reverse');
        }
        $request->validate(['business_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:500']);
        $document = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($this->context($request))->findOrFail($id);
        $document = app(CommercialReversalService::class)->reverse($document, $request->business_date, $request->reason, $this->context($request));
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => $document])
            : redirect($kind === 'sale' ? '/sales' : '/purchases')->with('message', 'Document reversed; original history preserved.');
    }

    public function reversalForm(Request $request, string $kind, int $id)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-delete' : 'purchases-delete', $context, $request->user()->id);
        $document = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($id);
        return view('backend.commercial.reversal', compact('kind', 'document', 'context'));
    }

    public function reverseSelection(Request $request, string $kind)
    {
        $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer|min:1',
            'business_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:500']);
        DB::transaction(function () use ($request, $kind) {
            foreach ($request->ids as $id) {
                $this->reverse($request, $kind, $id);
            }
        });
        return response()->json(['success' => true]);
    }

    public function replace(Request $request, string $kind, int $id)
    {
        $request->validate(['business_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:500']);
        $context = $this->context($request);
        $data = app(LegacyCommercialCommand::class)->data($request, $kind === 'purchase', $context);
        $document = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($id);
        if ($request->filled('draft_id')) {
            app(CommercialDraftService::class)->assertPostable($kind, $request->integer('draft_id'), $context, $request->user()->id);
        }
        $replacement = DB::transaction(function () use ($document, $data, $request, $kind, $context) {
            $replacement = app(CommercialReversalService::class)->replace($document, $data,
                $this->idempotencyKey($request), $request->business_date, $request->reason, $context);
            if ($request->filled('draft_id')) {
                app(CommercialDraftService::class)->query($kind, $context, $request->user()->id)->where('id', $request->draft_id)->delete();
            }
            return $replacement;
        });
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => $replacement], 201)
            : redirect($kind === 'sale' ? '/sales' : '/purchases')->with('message', 'Replacement document posted successfully.');
    }

    public function search(Request $request, string $kind, string $resource)
    {
        $request->validate(['q' => 'nullable|string|max:100']);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $this->context($request), $request->user()->id);
        $data = $resource === 'parties' ? app(PartyQueryService::class)->search($kind, $request->input('q', ''), $this->context($request))
            : app(ProductQueryService::class)->search($request->input('q', ''), $this->context($request));
        return response()->json(['data' => $data]);
    }

    public function party(Request $request, string $kind, int $id)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-index' : 'purchases-index', $context, $request->user()->id);
        $party = ($kind === 'sale' ? Customer::class : Supplier::class)::forCompany($context)->findOrFail($id);
        $type = $kind === 'sale' ? 'customer' : 'supplier';
        $credit = $kind === 'sale' ? app(CreditControlService::class)->summary($party, $context,
            \Carbon\CarbonImmutable::now(\App\Models\Company::findOrFail($context->companyId)->timezone)->toDateString()) : [];
        $request->validate(['page' => 'nullable|integer|min:1|max:1000000', 'pending' => 'nullable|boolean']);
        $page = (int) $request->input('page', 1);
        $items = DB::table('account_open_items')->where('company_id', $context->companyId)
            ->where('party_type', $type)->where('party_id', $id)
            ->when($request->boolean('pending'), fn ($query) => $query->where('open_amount', '!=', 0))
            ->orderByDesc('document_date')->orderByDesc('id')->offset(($page - 1) * 100)->limit(101)->get();
        return response()->json(['data' => ['party' => $party, 'items' => $items->take(100)->values(),
            'next_page' => $items->count() > 100 ? $page + 1 : null, 'credit' => $credit, 'outstanding' => round((float) DB::table('account_open_items')
            ->where('company_id', $context->companyId)->where('party_type', $type)->where('party_id', $id)->sum('open_amount'), 4)]]);
    }

    public function previousRates(Request $request, string $kind)
    {
        $partyId = (int) ($request->input('party_id') ?: $request->input('customer_id') ?: $request->input('supplier_id'));
        $productId = (int) $request->input('product_id');
        $request->merge(['party_id' => $partyId, 'product_id' => $productId]);
        $request->validate(['party_id' => 'required|integer|min:1', 'product_id' => 'required|integer|min:1']);
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-index' : 'purchases-index', $context, $request->user()->id);
        app(CompanyWriteGuard::class)->owned($kind === 'sale' ? Customer::class : Supplier::class, $partyId, $context, 'party_id');
        app(CompanyWriteGuard::class)->owned(Product::class, $productId, $context, 'product_id');
        $documents = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)
            ->where($kind === 'sale' ? 'customer_id' : 'supplier_id', $partyId)->whereNotNull('posted_at')->whereNull('reversed_at')->select('id');
        $lines = DB::table($kind === 'sale' ? 'product_sales' : 'product_purchases')->where('company_id', $context->companyId)
            ->whereIn($kind.'_id', $documents)->where('product_id', $productId)->orderByDesc('id')->limit(10)->get();

        $lastSale = DB::table('product_sales')
            ->join('sales', 'product_sales.sale_id', '=', 'sales.id')
            ->where('sales.company_id', $context->companyId)
            ->where('product_sales.product_id', $productId)
            ->where('sales.customer_id', $partyId)
            ->whereNotNull('sales.posted_at')
            ->orderByDesc('sales.created_at')
            ->select('sales.reference_no', 'sales.created_at', 'product_sales.net_unit_price as rate', 'product_sales.total as amount', 'product_sales.qty', 'product_sales.tax_rate')
            ->first();

        $lastPurchase = DB::table('product_purchases')
            ->join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
            ->where('purchases.company_id', $context->companyId)
            ->where('product_purchases.product_id', $productId)
            ->where('purchases.supplier_id', $partyId)
            ->whereNotNull('purchases.posted_at')
            ->orderByDesc('purchases.created_at')
            ->select('purchases.reference_no', 'purchases.created_at', 'product_purchases.net_unit_cost as cost', 'product_purchases.total as amount', 'product_purchases.qty', 'product_purchases.tax_rate')
            ->first();

        return response()->json([
            'data' => $lines,
            'last_sale' => $lastSale,
            'last_purchase' => $lastPurchase,
        ]);
    }

    public function cloneDocument(Request $request, string $kind, int $id)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $request->user()->id);
        $document = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($id);
        $lines = ($kind === 'sale' ? $document->productSales() : $document->productPurchases())->forCompany($context)->with('product')->get();
        $items = $lines->map(function ($line) use ($kind, $context) {
            abort_unless((int) $line->product?->company_id === $context->companyId, 409, 'Corrupt document line requires review.');
            return ['product_id' => $line->product_id, 'name' => $line->product->name, 'code' => $line->product->code, 'qty' => $line->qty,
                $kind === 'sale' ? 'net_unit_price' : 'net_unit_cost' => $kind === 'sale' ? $line->net_unit_price : $line->net_unit_cost,
                $kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id' => $kind === 'sale' ? $line->sale_unit_id : $line->purchase_unit_id];
        });
        return response()->json(['data' => [$kind === 'sale' ? 'customer_id' : 'supplier_id' => $document->{$kind === 'sale' ? 'customer_id' : 'supplier_id'},
            'warehouse_id' => $document->warehouse_id, 'items' => $items]]);
    }

    public function draft(Request $request, string $kind)
    {
        $request->validate(['payload' => 'required|array', 'id' => 'nullable|integer|min:1', 'version' => 'required|integer|min:0',
            'party_id' => 'nullable|integer|min:0', 'party_name' => 'nullable|string|max:150']);
        $result = app(CommercialDraftService::class)->save($kind, $request->payload, $request->id, $request->version,
            $this->context($request), $request->user()->id, $request->only(['party_id', 'party_name']));
        return response()->json(['data' => $result['draft'], 'warnings' => $result['warnings']]);
    }

    /** Tab strip metadata only; the payload is fetched one draft at a time. */
    public function drafts(Request $request, string $kind)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $request->user()->id);
        $drafts = app(CommercialDraftService::class);
        $drafts->prune($kind, $context, $request->user()->id);
        return response()->json(['data' => $drafts->meta($drafts->query($kind, $context, $request->user()->id)),
            'limit' => CommercialDraftService::MAX_OPEN, 'open' => $drafts->owned($kind, $context, $request->user()->id)->count()]);
    }

    public function showDraft(Request $request, string $kind, int $id)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $request->user()->id);
        return response()->json(['data' => app(CommercialDraftService::class)->load($kind, $id, $context, $request->user()->id)]);
    }

    public function destroyDraft(Request $request, string $kind, int $id)
    {
        app(CommercialDraftService::class)->delete($kind, $id, $this->context($request), $request->user()->id);
        return response()->json(['success' => true]);
    }

    public function payment(Request $request, string $kind, int $id)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sale-payment-add' : 'purchase-payment-add', $context, $request->user()->id);
        $document = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($id);
        abort_if($document->reversed_at || !$document->posted_at, 409, 'Only an active posted document can receive payment.');
        $data = $request->all();
        $data['idempotency_key'] = $this->idempotencyKey($request, 100);
        $request->validate(['amount' => 'required|numeric|gt:0|max:1000000000',
            'paying_method' => 'required|in:Cash,Bank,Cheque,Credit Card']);
        try {
            $payment = app(PaymentService::class)->addPayment($document, $data, $request->user()->id, $context);
        } catch (\InvalidArgumentException $error) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payment' => $error->getMessage()]);
        }
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => $payment], 201)
            : redirect($kind === 'sale' ? '/sales' : '/purchases')->with('message', 'Payment recorded successfully.');
    }

    public function receive(Request $request, int $id)
    {
        $context = $this->context($request);
        $purchase = Purchase::visibleIn($context)->findOrFail($id);
        $data = $request->except(['_token', 'idempotency_key']);
        return response()->json(['data' => app(\App\Services\Commercial\PurchaseReceiptService::class)->receive($purchase, $data,
            $this->idempotencyKey($request), $context)]);
    }

    public function inlineMaster(Request $request, string $kind, string $resource)
    {
        $context = $this->context($request);
        $actor = $request->user()->id;

        $permission = match ($resource) {
            'products' => 'products-add',
            'parties' => $kind === 'sale' ? 'customers-add' : 'suppliers-add',
            'sale-types' => 'sales-add',
            'purchase-types' => 'purchases-add',
            default => $kind === 'sale' ? 'sales-add' : 'purchases-add',
        };
        app(CommercialPermission::class)->assert($permission, $context, $actor);

        $key = $this->idempotencyKey($request);
        $type = match ($resource) {
            'products' => 'product',
            'parties' => $kind === 'sale' ? 'customer' : 'supplier',
            'agents' => 'agent',
            'areas' => 'area',
            'bill-sundries' => 'bill_sundry',
            'sale-types' => 'sale_type',
            'purchase-types' => 'purchase_type',
            'remarks' => 'standard_remark',
            'series' => 'document_series',
            default => abort(404, 'Invalid master resource'),
        };

        $model = match ($type) {
            'product' => Product::class,
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'agent' => Agent::class,
            'area' => Area::class,
            'bill_sundry' => BillSundry::class,
            'sale_type' => SaleType::class,
            'purchase_type' => PurchaseType::class,
            'standard_remark' => StandardRemark::class,
            'document_series' => DocumentSeries::class,
        };

        $values = $request->except(['_token', 'idempotency_key', 'company_id']); ksort($values);
        $hash = hash('sha256', json_encode([$type, $values], JSON_THROW_ON_ERROR));

        $record = DB::transaction(function () use ($request, $kind, $resource, $context, $actor, $key, $type, $model, $hash) {
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $retry = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $key)->first();
            if ($retry) {
                abort_unless($retry->request_hash === $hash && $retry->response_type === $type, 409, 'Key already used for a different request.');
                return $model::forCompany($context)->findOrFail($retry->response_ref);
            }
            app(CompanyWriteGuard::class)->begin($context, null);

            if ($resource === 'products') {
                $request->validate(['name' => 'required|string|max:100', 'code' => 'required|string|max:100', 'category_id' => 'required|integer|min:1',
                    'unit_id' => 'required|integer|min:1', 'price' => 'required|numeric|min:0', 'cost' => 'required|numeric|min:0']);
                app(CompanyWriteGuard::class)->owned(\App\Models\Category::class, $request->category_id, $context, 'category_id');
                app(CompanyWriteGuard::class)->owned(\App\Models\Unit::class, $request->unit_id, $context, 'unit_id');
                abort_if(Product::forCompany($context)->where('code', $request->code)->exists(), 422, 'Product code already exists.');
                $record = Product::forceCreate(['company_id' => $context->companyId, 'name' => $request->name, 'code' => $request->code,
                    'category_id' => $request->category_id, 'unit_id' => $request->unit_id, 'sale_unit_id' => $request->unit_id,
                    'purchase_unit_id' => $request->unit_id, 'type' => 'standard', 'barcode_symbology' => 'C128',
                    'price' => app(CommercialPricing::class)->number($request->price, 'price'),
                    'cost' => app(CommercialPricing::class)->number($request->cost, 'cost'), 'qty' => 0, 'is_active' => true]);
            } elseif ($resource === 'parties') {
                $request->validate(['name' => 'required|string|max:100', 'city' => 'nullable|string|max:100',
                    'phone_number' => 'nullable|string|max:50', 'address' => 'nullable|string|max:255', 'search_alias' => 'nullable|string|max:100',
                    'credit_days' => 'nullable|integer|min:0|max:3650', 'credit_limit' => 'nullable|numeric|min:0|max:1000000000',
                    'area_id' => 'nullable|integer', 'agent_id' => 'nullable|integer']);
                $attributes = $request->only(['name', 'city', 'phone_number', 'address', 'search_alias', 'area_id', 'agent_id']);
                $attributes += ['phone_number' => '', 'address' => '', 'city' => '', 'company_name' => '', 'email' => ''];
                if ($kind === 'sale') {
                    app(CompanyWriteGuard::class)->owned(\App\Models\CustomerGroup::class, $request->customer_group_id, $context, 'customer_group_id');
                    $attributes['customer_group_id'] = $request->customer_group_id;
                    $attributes += $request->only(['credit_days', 'credit_limit']);
                }
                $record = $model::forceCreate($attributes + ['company_id' => $context->companyId, 'is_active' => true]);
            } elseif ($resource === 'agents') {
                $request->validate(['name' => 'required|string|max:150', 'code' => 'nullable|string|max:50',
                    'phone' => 'nullable|string|max:50', 'commission_rate' => 'nullable|numeric|min:0|max:100']);
                $record = Agent::forceCreate([
                    'company_id' => $context->companyId,
                    'name' => $request->name,
                    'code' => $request->code,
                    'phone' => $request->phone,
                    'commission_rate' => $request->input('commission_rate', 0),
                    'is_active' => true,
                ]);
            } elseif ($resource === 'areas') {
                $request->validate(['name' => 'required|string|max:100', 'code' => 'nullable|string|max:50',
                    'city' => 'nullable|string|max:100', 'pincode' => 'nullable|string|max:20']);
                $record = Area::forceCreate([
                    'company_id' => $context->companyId,
                    'name' => $request->name,
                    'code' => $request->code,
                    'city' => $request->city,
                    'pincode' => $request->pincode,
                    'is_active' => true,
                ]);
            } elseif ($resource === 'bill-sundries') {
                $request->validate(['name' => 'required|string|max:150', 'nature' => 'nullable|in:sales,purchase,both',
                    'calculation_type' => 'nullable|in:percentage,amount', 'default_value' => 'nullable|numeric',
                    'tax_rate' => 'nullable|numeric|min:0|max:100']);
                $record = BillSundry::forceCreate([
                    'company_id' => $context->companyId,
                    'name' => $request->name,
                    'nature' => $request->input('nature', $kind === 'sale' ? 'sales' : 'purchase'),
                    'calculation_type' => $request->input('calculation_type', 'percentage'),
                    'default_value' => $request->input('default_value', 0),
                    'tax_rate' => $request->input('tax_rate', 0),
                    'is_active' => true,
                ]);
            } elseif ($resource === 'sale-types') {
                $request->validate(['name' => 'required|string|max:150', 'code' => 'nullable|string|max:50',
                    'tax_nature' => 'nullable|in:local,interstate,export,sez,exempted', 'tax_rate' => 'nullable|numeric|min:0|max:100']);
                $record = SaleType::forceCreate([
                    'company_id' => $context->companyId,
                    'name' => $request->name,
                    'code' => $request->code,
                    'tax_nature' => $request->input('tax_nature', 'local'),
                    'tax_rate' => $request->input('tax_rate', 0),
                    'is_active' => true,
                ]);
            } elseif ($resource === 'purchase-types') {
                $request->validate(['name' => 'required|string|max:150', 'code' => 'nullable|string|max:50',
                    'tax_nature' => 'nullable|in:local,interstate,import,exempted', 'tax_rate' => 'nullable|numeric|min:0|max:100']);
                $record = PurchaseType::forceCreate([
                    'company_id' => $context->companyId,
                    'name' => $request->name,
                    'code' => $request->code,
                    'tax_nature' => $request->input('tax_nature', 'local'),
                    'tax_rate' => $request->input('tax_rate', 0),
                    'is_active' => true,
                ]);
            } elseif ($resource === 'remarks') {
                $title = $request->input('title') ?: $request->input('name') ?: 'Remark';
                $remark = $request->input('remark') ?: $request->input('name') ?: '';
                $record = StandardRemark::forceCreate([
                    'company_id' => $context->companyId,
                    'title' => $title,
                    'type' => $kind,
                    'remark' => $remark,
                    'is_active' => true,
                ]);
            } elseif ($resource === 'series') {
                $code = $request->input('code') ?: $request->input('name') ?: 'SERIES-1';
                $request->validate(['code' => 'nullable|string|max:50', 'name' => 'nullable|string|max:50']);
                $branchId = $context->branchId ?: (DB::table('company_branches')->where('company_id', $context->companyId)->value('id') ?? 1);
                $fyId = $context->financialYearId ?: (DB::table('fiscal_years')->where('company_id', $context->companyId)->value('id') ?? 1);
                $docType = $kind === 'sale' ? 'sale' : 'purchase';
                $record = DocumentSeries::forceCreate([
                    'company_id' => $context->companyId,
                    'branch_id' => $branchId,
                    'financial_year_id' => $fyId,
                    'document_type' => $docType,
                    'code' => $code,
                    'prefix' => $request->input('prefix', ''),
                    'suffix' => $request->input('suffix', ''),
                    'next_number' => (int) $request->input('next_number', 1),
                    'padding' => max(1, (int) $request->input('padding', 5)),
                    'reset_policy' => $request->input('reset_policy') ?: 'financial_year',
                    'is_default' => false,
                ]);
            }

            DB::table('idempotency_keys')->insert(['company_id' => $context->companyId, 'key' => $key, 'request_hash' => $hash,
                'response_type' => $type, 'response_ref' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            return $record;
        });
        return response()->json(['data' => $record], 201);
    }

    public function quickStoreProduct(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:100',
            'type' => 'nullable|string',
            'barcode_symbology' => 'nullable|string',
            'brand_id' => 'nullable',
            'category_id' => 'nullable',
            'unit_id' => 'nullable',
            'sale_unit_id' => 'nullable',
            'purchase_unit_id' => 'nullable',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'profit_margin_type' => 'nullable|string',
            'profit_margin' => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'daily_sale_objective' => 'nullable|numeric',
            'alert_quantity' => 'nullable|numeric',
            'tax_id' => 'nullable',
            'tax_method' => 'nullable',
            'warranty' => 'nullable',
            'warranty_type' => 'nullable|string',
            'guarantee' => 'nullable',
            'guarantee_type' => 'nullable|string',
            'is_batch' => 'nullable|boolean',
            'is_imei' => 'nullable|boolean',
            'featured' => 'nullable|boolean',
            'is_embeded' => 'nullable|boolean',
            'is_online' => 'nullable|boolean',
            'in_stock' => 'nullable|boolean',
            'product_details' => 'nullable|string',
        ]);

        $name = trim($validated['name']);
        $code = !empty($validated['code']) ? trim($validated['code']) : ('ITM-' . mt_rand(100000, 999999));
        $cost = isset($validated['cost']) ? (float)$validated['cost'] : 0;
        $price = isset($validated['price']) && $validated['price'] !== '' ? (float)$validated['price'] : ($cost > 0 ? $cost : 0);
        $type = !empty($validated['type']) ? strtolower(trim($validated['type'])) : 'standard';
        $barcodeSymbology = !empty($validated['barcode_symbology']) ? trim($validated['barcode_symbology']) : 'C128';

        $unit = null;
        if (!empty($validated['unit_id'])) {
            $unit = \App\Models\Unit::find($validated['unit_id'])
                ?? \App\Models\Unit::where('unit_code', $validated['unit_id'])->orWhere('unit_name', $validated['unit_id'])->first();
        }
        if (!$unit) {
            $unit = \App\Models\Unit::first();
        }
        $unitId = $unit ? $unit->id : 1;
        $saleUnitId = !empty($validated['sale_unit_id']) ? $validated['sale_unit_id'] : $unitId;
        $purchaseUnitId = !empty($validated['purchase_unit_id']) ? $validated['purchase_unit_id'] : $unitId;

        $categoryId = null;
        if (!empty($validated['category_id'])) {
            $categoryId = $validated['category_id'];
        } else {
            $cat = \App\Models\Category::where('is_active', true)->first();
            $categoryId = $cat ? $cat->id : 1;
        }

        $tax = null;
        if (!empty($validated['tax_id'])) {
            $tax = \App\Models\Tax::find($validated['tax_id']);
        }
        $taxRate = $tax ? (float)$tax->rate : 0;

        $product = \App\Models\Product::create([
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'barcode_symbology' => $barcodeSymbology,
            'brand_id' => !empty($validated['brand_id']) ? $validated['brand_id'] : null,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'purchase_unit_id' => $purchaseUnitId,
            'sale_unit_id' => $saleUnitId,
            'cost' => $cost,
            'price' => $price,
            'profit_margin_type' => $validated['profit_margin_type'] ?? 'percentage',
            'profit_margin' => $validated['profit_margin'] ?? null,
            'wholesale_price' => $validated['wholesale_price'] ?? null,
            'daily_sale_objective' => $validated['daily_sale_objective'] ?? null,
            'alert_quantity' => $validated['alert_quantity'] ?? null,
            'tax_id' => $tax ? $tax->id : null,
            'tax_method' => $validated['tax_method'] ?? 1,
            'warranty' => $validated['warranty'] ?? null,
            'warranty_type' => $validated['warranty_type'] ?? 'months',
            'guarantee' => $validated['guarantee'] ?? null,
            'guarantee_type' => $validated['guarantee_type'] ?? 'months',
            'is_batch' => !empty($validated['is_batch']) ? 1 : 0,
            'is_imei' => !empty($validated['is_imei']) ? 1 : 0,
            'featured' => !empty($validated['featured']) ? 1 : 0,
            'is_embeded' => !empty($validated['is_embeded']) ? 1 : 0,
            'product_details' => $validated['product_details'] ?? null,
            'qty' => 0,
            'is_active' => 1,
        ]);

        return response()->json([
            'success' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'code' => $product->code,
                'price' => (float)$product->price,
                'cost' => (float)$product->cost,
                'tax_rate' => $taxRate,
                'unit_id' => $unitId,
                'unit' => $unit ? ($unit->unit_code ?? $unit->unit_name) : 'Unit',
                'value' => $product->code . '|' . $product->name,
                'label' => $product->code . ' - ' . $product->name,
            ],
            'message' => 'Product created successfully',
        ]);
    }

    public function quickStoreCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer',
        ]);

        $name = trim($validated['name']);
        $category = \App\Models\Category::firstOrCreate(
            ['name' => $name],
            [
                'parent_id' => $validated['parent_id'] ?? null,
                'is_active' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'message' => 'Category created successfully',
        ]);
    }

    public function quickStoreBrand(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $title = trim($validated['title']);
        $brand = \App\Models\Brand::firstOrCreate(
            ['title' => $title],
            [
                'is_active' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'brand' => [
                'id' => $brand->id,
                'title' => $brand->title,
            ],
            'message' => 'Brand created successfully',
        ]);
    }

    public function quickStoreParty(Request $request)
    {
        $validated = $request->validate([
            'party_type' => 'nullable|in:supplier,customer,both',
            'both' => 'nullable',
            'customer_group_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
            'vat_number' => 'nullable|string|max:50',
            'tax_no' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric|min:0',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'required|string|max:50',
            'wa_number' => 'nullable|string|max:50',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'credit_days' => 'nullable|integer|min:0|max:3650',
        ]);

        $companyId = session('company_id') ?? 1;
        if (auth()->check() && auth()->user()->company_id) {
            $companyId = auth()->user()->company_id;
        }

        $partyType = $validated['party_type'] ?? 'supplier';
        if (!empty($request->both) || $request->both == '1') {
            $partyType = 'both';
        }

        $taxNo = trim($validated['vat_number'] ?? ($validated['tax_no'] ?? ''));
        $waNumber = !empty($validated['wa_number']) ? trim($validated['wa_number']) : trim($validated['phone_number']);
        $country = !empty($validated['country']) ? trim($validated['country']) : 'India';
        $creditDays = isset($validated['credit_days']) && $validated['credit_days'] !== '' ? (int)$validated['credit_days'] : 30;
        $openingBalance = isset($validated['opening_balance']) && $validated['opening_balance'] !== '' ? (float)$validated['opening_balance'] : 0;
        $customerGroupId = !empty($validated['customer_group_id']) ? (int)$validated['customer_group_id'] : 1;

        $supplier = null;
        $customer = null;

        DB::transaction(function () use (
            $partyType, $companyId, $validated, $taxNo, $waNumber, $country, $creditDays, $openingBalance, $customerGroupId, &$supplier, &$customer
        ) {
            if ($partyType === 'supplier' || $partyType === 'both') {
                $supplier = Supplier::create([
                    'company_id' => $companyId,
                    'name' => trim($validated['name']),
                    'company_name' => trim($validated['company_name']),
                    'vat_number' => $taxNo,
                    'tax_no' => $taxNo,
                    'email' => !empty($validated['email']) ? trim($validated['email']) : null,
                    'phone_number' => trim($validated['phone_number']),
                    'wa_number' => $waNumber,
                    'address' => trim($validated['address']),
                    'city' => trim($validated['city']),
                    'state' => !empty($validated['state']) ? trim($validated['state']) : null,
                    'postal_code' => !empty($validated['postal_code']) ? trim($validated['postal_code']) : null,
                    'country' => $country,
                    'opening_balance' => $openingBalance,
                    'credit_days' => $creditDays,
                    'is_active' => true,
                ]);
            }

            if ($partyType === 'customer' || $partyType === 'both') {
                $customer = Customer::create([
                    'company_id' => $companyId,
                    'customer_group_id' => $customerGroupId,
                    'name' => trim($validated['name']),
                    'company_name' => trim($validated['company_name']),
                    'tax_no' => $taxNo,
                    'email' => !empty($validated['email']) ? trim($validated['email']) : null,
                    'phone_number' => trim($validated['phone_number']),
                    'wa_number' => $waNumber,
                    'address' => trim($validated['address']),
                    'city' => trim($validated['city']),
                    'state' => !empty($validated['state']) ? trim($validated['state']) : null,
                    'postal_code' => !empty($validated['postal_code']) ? trim($validated['postal_code']) : null,
                    'country' => $country,
                    'opening_balance' => $openingBalance,
                    'credit_days' => $creditDays,
                    'bill_by_bill' => 1,
                    'is_active' => true,
                ]);
            }
        });

        $supplierArr = $supplier ? [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'company_name' => $supplier->company_name,
            'vat_number' => $supplier->vat_number ?? $supplier->tax_no ?? '',
            'tax_no' => $supplier->tax_no ?? $supplier->vat_number ?? '',
            'address' => $supplier->address . ($supplier->city ? (', ' . $supplier->city) : ''),
            'credit_days' => $supplier->credit_days ?? 30,
            'phone_number' => $supplier->phone_number,
        ] : null;

        $customerArr = $customer ? [
            'id' => $customer->id,
            'name' => $customer->name,
            'company_name' => $customer->company_name,
            'tax_no' => $customer->tax_no ?? '',
            'address' => $customer->address . ($customer->city ? (', ' . $customer->city) : ''),
            'credit_days' => $customer->credit_days ?? 30,
            'phone_number' => $customer->phone_number,
        ] : null;

        return response()->json([
            'success' => true,
            'message' => 'Party created successfully',
            'party_type' => $partyType,
            'supplier' => $supplierArr,
            'customer' => $customerArr,
        ]);
    }

    public function gstLookup(Request $request)
    {
        $gstin = strtoupper(trim($request->input('gstin', '')));
        if (!$gstin) {
            return response()->json(['success' => false, 'message' => 'Please enter a valid GSTIN.'], 422);
        }

        $parsed = \App\Services\Tax\Gstin::parse($gstin);

        // Fetch company state code to determine intra vs inter-state tax rule (default Tamil Nadu: 33)
        $companyStateCode = '33';
        $company = \App\Models\Company::first();
        if ($company && !empty($company->vat_number) && strlen($company->vat_number) >= 2) {
            $companyStateCode = substr($company->vat_number, 0, 2);
        }

        $isLocal = ($parsed['state_code'] === $companyStateCode);
        $taxRule = $isLocal ? 'Local Intra-State (CGST + SGST)' : 'Inter-State (IGST)';

        // 1. Search internal ERP database for matching supplier or customer (zero cost, instant recall)
        $partyData = null;
        $partyType = null;
        $existing = \App\Models\Supplier::where('vat_number', $gstin)->orWhere('tax_no', $gstin)->first();
        if ($existing) {
            $partyType = 'supplier';
        } else {
            $existing = \App\Models\Customer::where('tax_no', $gstin)->first();
            if ($existing) {
                $partyType = 'customer';
            }
        }

        if (!$existing && !empty($parsed['pan'])) {
            $pan = $parsed['pan'];
            $existing = \App\Models\Supplier::where('vat_number', 'like', "%{$pan}%")->orWhere('tax_no', 'like', "%{$pan}%")->first();
            if ($existing) {
                $partyType = 'supplier';
            } else {
                $existing = \App\Models\Customer::where('tax_no', 'like', "%{$pan}%")->first();
                if ($existing) {
                    $partyType = 'customer';
                }
            }
        }

        if ($existing) {
            $partyData = [
                'name' => $existing->name ?? '',
                'company_name' => $existing->company_name ?? ($existing->name ?? ''),
                'address' => $existing->address ?? '',
                'city' => $existing->city ?? '',
                'state' => $existing->state ?? $parsed['state_name'],
                'postal_code' => $existing->postal_code ?? '',
                'phone_number' => $existing->phone_number ?? '',
                'email' => $existing->email ?? '',
                'party_type' => $partyType,
                'source' => 'local_database',
            ];
        }

        // 2. If not found locally, query live external provider if configured in .env (e.g. gstin_lookup or Sandbox)
        if (!$partyData && $parsed['is_valid']) {
            $lookupUrl = config('compliance.gst_lookup_url') ?: env('GST_LOOKUP_URL');
            if ($lookupUrl) {
                try {
                    $url = rtrim($lookupUrl, '/') . '/' . $gstin;
                    $token = config('compliance.gst_lookup_token') ?: env('GST_LOOKUP_TOKEN');
                    $client = \Illuminate\Support\Facades\Http::timeout(5);
                    if ($token) {
                        $client = $client->withToken($token);
                    }
                    $response = $client->get($url);
                    if ($response->successful()) {
                        $ext = $response->json();
                        // Normalize across gstin_lookup, sandbox, or standard GSP schemas
                        $data = $ext['data'] ?? $ext;
                        $legalName = $data['legalName'] ?? ($data['legal_name'] ?? ($data['lgnm'] ?? ''));
                        $tradeName = $data['tradeName'] ?? ($data['trade_name'] ?? ($data['tradeNam'] ?? $legalName));
                        
                        $address = $data['principalAddress'] ?? ($data['address'] ?? '');
                        if (!$address && isset($data['pradr']['addr'])) {
                            $addr = $data['pradr']['addr'];
                            $parts = array_filter([$addr['bno'] ?? '', $addr['flno'] ?? '', $addr['st'] ?? '', $addr['loc'] ?? '', $addr['dst'] ?? '']);
                            $address = implode(', ', $parts);
                        }

                        $pincode = $data['pincode'] ?? ($data['postal_code'] ?? ($data['pradr']['addr']['pncd'] ?? ''));
                        $city = $data['city'] ?? ($data['pradr']['addr']['dst'] ?? '');
                        $state = $data['state'] ?? ($data['pradr']['addr']['stcd'] ?? $parsed['state_name']);

                        if ($legalName || $tradeName) {
                            $partyData = [
                                'name' => $legalName ?: $tradeName,
                                'company_name' => $tradeName ?: $legalName,
                                'address' => $address,
                                'city' => $city,
                                'state' => $state,
                                'postal_code' => $pincode,
                                'phone_number' => $data['phone_number'] ?? '',
                                'email' => $data['email'] ?? '',
                                'party_type' => 'registered_business',
                                'source' => 'live_api',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    // Gracefully fallback to statutory parser
                }
            }
        }

        return response()->json([
            'success' => true,
            'gstin' => $parsed['gstin'],
            'is_valid' => $parsed['is_valid'],
            'error' => $parsed['error'],
            'state_code' => $parsed['state_code'],
            'state_name' => $parsed['state_name'],
            'pan' => $parsed['pan'],
            'constitution' => $parsed['constitution'],
            'is_local' => $isLocal,
            'tax_rule' => $taxRule,
            'is_existing' => (bool)$partyData,
            'party' => $partyData,
        ]);
    }
}

