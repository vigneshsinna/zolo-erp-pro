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
        $request->validate(['q' => 'nullable|string|max:150', 'party_id' => 'nullable|integer|min:1']);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $this->context($request), $request->user()->id);
        $data = $resource === 'parties' ? app(PartyQueryService::class)->search($kind, $request->input('q', ''), $this->context($request))
            : app(ProductQueryService::class)->search($request->input('q', ''), $this->context($request),
                $kind === 'sale' ? 'customer' : 'supplier', $request->integer('party_id') ?: null);
        if ($resource === 'products' && $kind === 'sale' && !app(CommercialPermission::class)->allows('purchases-index', $this->context($request), $request->user()->id)) {
            $data = array_map(function ($product) { unset($product['cost']); return $product; }, $data);
        }
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
        $request->validate(['page' => 'nullable|integer|min:1|max:1000000', 'pending' => 'nullable|boolean',
            'statement' => 'nullable|boolean', 'exclude_id' => 'nullable|integer|min:1']);
        $page = (int) ($request->input('page') ?: 1);
        $base = DB::table('account_open_items')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('party_type', $type)->where('party_id', $id);
        $outstanding = round((float) (clone $base)->sum('open_amount'), 4);
        $previous = $outstanding;
        if ($request->filled('exclude_id')) {
            $source = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)
                ->where($kind === 'sale' ? 'customer_id' : 'supplier_id', $id)->whereNull('reversed_at')->findOrFail($request->integer('exclude_id'));
            $previous -= (float) (clone $base)->where('source_type', $kind)->where('source_id', $source->id)->sum('open_amount');
        }
        $statement = $request->boolean('statement');
        $query = (clone $base)->when($request->boolean('pending'), fn ($q) => $q->where('open_amount', '!=', 0));
        $items = $statement ? $query->orderBy('document_date')->orderBy('id') : $query->orderByDesc('document_date')->orderByDesc('id');
        $opening = $statement && $page > 1 ? (float) DB::query()->fromSub((clone $items)->limit(($page - 1) * 100)->select('original_amount'), 'prior')->sum('original_amount') : 0;
        $items = $items->offset(($page - 1) * 100)->limit(101)->get();
        return response()->json(['data' => ['party' => $party, 'items' => $items->take(100)->values(),
            'next_page' => $items->count() > 100 ? $page + 1 : null, 'credit' => $credit, 'outstanding' => $outstanding,
            'previous' => round($previous, 4), 'opening_before_page' => $opening, 'balance_kind' => $kind === 'sale' ? 'Receivable' : 'Payable']] );
    }

    public function materialSources(Request $request, string $kind)
    {
        $context = $this->context($request);
        $purchase = $kind === 'purchase';
        app(CommercialPermission::class)->assert($purchase ? 'grn-index' : 'delivery-challans-index', $context, $request->user()->id);
        $request->validate(['party_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1|max:1000000']);
        app(CompanyWriteGuard::class)->warehouse($request->integer('warehouse_id'), $context, $request->user()->id);
        app(CompanyWriteGuard::class)->owned($purchase ? Supplier::class : Customer::class, $request->integer('party_id'), $context, 'party_id');
        $sources = ($purchase ? \App\Models\GoodsReceivedNote::class : \App\Models\DeliveryChallan::class)::forCompany($context)
            ->where('branch_id', $context->branchId)->where($purchase ? 'supplier_id' : 'customer_id', $request->integer('party_id'))
            ->where('warehouse_id', $request->integer('warehouse_id'))->whereNotNull('posted_at')
            ->whereIn('status', ['pending', 'partially_billed'])->when(!$purchase, fn ($q) => $q->where('purpose', '<>', 'not_supply'))
            ->with(['items.product', 'items.unit'])->orderBy('id')->paginate(50);
        $service = app(\App\Services\Commercial\MaterialDocumentService::class);
        return response()->json(['data' => collect($sources->items())->map(fn ($source) => [
            'id' => $source->id, 'reference_no' => $purchase ? $source->grn_no : $source->challan_no,
            'date' => ($purchase ? $source->grn_date : $source->challan_date)->toDateString(),
            'items' => $source->items->map(fn ($line) => ['id' => $line->id, 'product_id' => $line->product_id,
                  'variant_id' => $line->variant_id,
                'name' => $line->product?->name, 'code' => $line->product?->code, 'unit_id' => $line->unit_id, 'unit' => $line->unit?->unit_name,
                'batch_no' => $line->batch_no, 'product_batch_id' => $line->batch_no ? DB::table('product_batches')->where('product_id', $line->product_id)->where('batch_no', $line->batch_no)->value('id') : null,
                'qty' => $service->openQuantities($source)[$line->id], 'base_factor' => $line->qty_base / $line->qty,
                'rate' => $purchase ? $line->cost : $line->rate])->values()]), 'next_page' => $sources->hasMorePages() ? $sources->currentPage() + 1 : null]);
    }

    public function previousRates(Request $request, string $kind)
    {
        $request->merge(['party_id' => $request->input('party_id') ?: $request->input('customer_id') ?: $request->input('supplier_id')]);
        $request->validate(['party_id' => 'required|integer|min:1', 'product_id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1|max:1000000']);
        return response()->json(app(\App\Services\Commercial\RateHistory::class)->read($kind, $request->integer('party_id'),
            $request->integer('product_id'), (int) ($request->input('page') ?: 1), $this->context($request), $request->user()->id));
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
        app(CommercialPermission::class)->assert(CommercialDraftService::permission($kind), $context, $request->user()->id);
        $drafts = app(CommercialDraftService::class);
        $drafts->prune($kind, $context, $request->user()->id);
        return response()->json(['data' => $drafts->meta($drafts->query($kind, $context, $request->user()->id)),
            'limit' => CommercialDraftService::MAX_OPEN, 'open' => $drafts->owned($kind, $context, $request->user()->id)->count()]);
    }

    public function showDraft(Request $request, string $kind, int $id)
    {
        $context = $this->context($request);
        app(CommercialPermission::class)->assert(CommercialDraftService::permission($kind), $context, $request->user()->id);
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
        $warning = $this->negativeCashWarning($payment, $context);
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => $payment] + ($warning ? ['warning' => $warning] : []), 201)
            : redirect($kind === 'sale' ? '/sales' : '/purchases')->with('message', trim('Payment recorded successfully. '.$warning));
    }

    /** Cash cannot really go below zero: a payment that leaves the cash ledger negative is recorded but flagged (matrix B38). */
    private function negativeCashWarning($payment, CompanyContext $context): ?string
    {
        $ledger = DB::table('accounts')->where('company_id', $context->companyId)->where('id', $payment->account_id ?? 0)->value('chart_of_account_id');
        if (!$ledger || DB::table('chart_of_accounts')->where('id', $ledger)->value('control_type') !== 'cash') return null;
        $balance = (float) DB::table('journal_items as i')->join('journal_entries as e', 'e.id', '=', 'i.journal_entry_id')
            ->where('e.company_id', $context->companyId)->where('e.status', 'posted')->where('i.chart_of_account_id', $ledger)
            ->sum(DB::raw('i.debit - i.credit'));
        return $balance < -0.005 ? 'Warning: the cash ledger is now negative ('.number_format($balance, 2).'). Check that every cash receipt was entered.' : null;
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
            'tax_category_id' => 'nullable|integer|min:1',
            'hsn_code' => 'nullable|string|max:8',
            'negative_stock_policy' => 'nullable|in:allow,warn,block',
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

        $context = $request->attributes->get(CompanyContext::class) ?? abort(409, 'Choose a company first.');
        app(CommercialPermission::class)->assert('products-add', $context, $request->user()->id);
        if (!empty($validated['tax_category_id']) && !DB::table('tax_categories')->where('company_id', $context->companyId)->where('id', $validated['tax_category_id'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tax_category_id' => 'Select a GST rate of this company.']);
        }
        return DB::transaction(function () use ($request, $validated, $context) {
        $name = trim($validated['name']);
        $code = !empty($validated['code']) ? trim($validated['code']) : ('ITM-' . mt_rand(100000, 999999));
        $cost = isset($validated['cost']) ? (float)$validated['cost'] : 0;
        $price = isset($validated['price']) && $validated['price'] !== '' ? (float)$validated['price'] : ($cost > 0 ? $cost : 0);
        $type = !empty($validated['type']) ? strtolower(trim($validated['type'])) : 'standard';
        $barcodeSymbology = !empty($validated['barcode_symbology']) ? trim($validated['barcode_symbology']) : 'C128';
        if (!empty($validated['hsn_code'])) {
            $gst = app(\App\Services\Tax\GstSetupService::class);
            $gst->validateCode(preg_replace('/\s+/', '', $validated['hsn_code']), $gst->kindFor($type));
        }

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

        $resolvedHsn = app(\App\Services\Tax\GstSetupService::class)->productCode($validated['hsn_code'] ?? null,
            $categoryId ? (int) $categoryId : null, $type, $request->attributes->get(CompanyContext::class));
        $product = app(\App\Services\ERP\ProductMasterService::class)->create([
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
            // products.profit_margin is NOT NULL; the form leaves it blank when the margin field is hidden (services).
            'profit_margin' => $validated['profit_margin'] ?? (($validated['profit_margin_type'] ?? 'percentage') === 'percentage'
                ? ((float) $cost > 0 ? round(((float) $price - (float) $cost) / (float) $cost * 100, 2) : 0) : round((float) $price - (float) $cost, 4)),
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
        ], $validated, $context);
        if ($rate = app(\App\Services\Tax\GstSetupService::class)->rateFor($product)) $taxRate = $rate['rate'];

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
        });
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
            'vat_number' => \App\Services\Tax\Gstin::rule(),
            'tax_no' => \App\Services\Tax\Gstin::rule(),
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

        // The active company, never a guessed default: parties must land in the company the user is working in.
        $companyId = $request->attributes->get(\App\Services\Platform\CompanyContext::class)?->companyId ?? session('company_id');
        abort_unless($companyId, 409, 'Choose a company first.');

        $partyType = $validated['party_type'] ?? 'supplier';
        // With "both", the due entered belongs to the side the form was opened for; the other copy starts clear.
        $openingSide = $partyType === 'customer' ? 'customer' : 'supplier';
        if (!empty($request->both) || $request->both == '1') {
            $partyType = 'both';
        }

        $taxNo = trim($validated['vat_number'] ?? ($validated['tax_no'] ?? ''));
        $waNumber = !empty($validated['wa_number']) ? trim($validated['wa_number']) : trim($validated['phone_number']);
        $country = !empty($validated['country']) ? trim($validated['country']) : 'India';
        $creditDays = isset($validated['credit_days']) && $validated['credit_days'] !== '' ? (int)$validated['credit_days'] : 30;
        $openingBalance = isset($validated['opening_balance']) && $validated['opening_balance'] !== '' ? (float)$validated['opening_balance'] : 0;
        // Default to the company's own first active group (customer groups are company scoped).
        $customerGroupId = \App\Models\CustomerGroup::where('company_id', $companyId)->where('is_active', true)
            ->when(!empty($validated['customer_group_id']), fn ($q) => $q->whereKey((int) $validated['customer_group_id']))
            ->orderBy('id')->value('id');
        if (!$customerGroupId && in_array($partyType, ['customer', 'both'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['customer_group_id' => 'Select a customer group of this company.']);
        }

        $supplier = null;
        $customer = null;
        $openings = app(\App\Services\Accounting\PartyOpeningBalanceService::class);
        $context = app(\App\Services\Platform\CompanyContextResolver::class)->forActor();
        $openings->assertPostable($openingSide, $openingBalance, $context);

        DB::transaction(function () use (
            $partyType, $companyId, $validated, $taxNo, $waNumber, $country, $creditDays, $openingBalance, $customerGroupId, &$supplier, &$customer,
            $openingSide, $openings, $context
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
                    'opening_balance' => $openingSide === 'supplier' ? $openingBalance : 0,
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
                    'opening_balance' => $openingSide === 'customer' ? $openingBalance : 0,
                    'credit_days' => $creditDays,
                    'bill_by_bill' => 1,
                    'is_active' => true,
                ]);
            }
            $openingParty = $openingSide === 'customer' ? $customer : $supplier;
            if ($openingParty) {
                $openings->post($openingParty, $openingBalance, $context);
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

    /** Company name -> candidate GSTINs: parties already in the ERP first, then Masters India legal-name search. */
    public function gstSearchByName(Request $request)
    {
        $name = trim((string) $request->input('name', ''));
        if (mb_strlen($name) < 3) {
            return response()->json(['success' => false, 'message' => 'Enter at least 3 characters of the company name.'], 422);
        }

        $matches = [];
        foreach ([[Supplier::class, 'vat_number'], [Customer::class, 'tax_no']] as [$model, $column]) {
            $model::query()->whereNotNull($column)->where($column, '!=', '')
                ->where(fn ($q) => $q->where('company_name', 'like', "%{$name}%")->orWhere('name', 'like', "%{$name}%"))
                ->limit(5)->get()
                ->each(function ($party) use (&$matches, $column) {
                    $gstin = strtoupper($party->{$column});
                    $matches[$gstin] ??= ['gstin' => $gstin, 'legal_name' => $party->company_name ?: $party->name,
                        'trade_name' => '', 'status' => '', 'city' => $party->city ?? '', 'state' => $party->state ?? '', 'source' => 'local_database'];
                });
        }

        $mastersIndia = app(\App\Services\Tax\MastersIndiaGstClient::class);
        if ($mastersIndia->configured()) {
            foreach (array_slice($mastersIndia->searchLegalName($name), 0, 10) as $reg) {
                $matches[$reg['gstin']] ??= ['gstin' => $reg['gstin'], 'legal_name' => $reg['legal_name'], 'trade_name' => $reg['trade_name'],
                    'status' => $reg['status'], 'city' => $reg['city'], 'state' => $reg['state'], 'source' => 'live_api'];
            }
        }

        return response()->json(['success' => true, 'provider' => $mastersIndia->configured(), 'matches' => array_values($matches)]);
    }

    public function gstLookup(Request $request)
    {
        $gstin = strtoupper(trim($request->input('gstin', '')));
        if (!$gstin) {
            return response()->json(['success' => false, 'message' => 'Please enter a valid GSTIN.'], 422);
        }

        $parsed = \App\Services\Tax\Gstin::parse($gstin);

        // Intra vs inter-state follows the branch GST registration; without one the rule is unknown.
        $context = $request->attributes->get(\App\Services\Platform\CompanyContext::class);
        $registration = $context ? app(\App\Services\Tax\GstSetupService::class)->registration($context) : null;
        $companyStateCode = $registration->state_code
            ?? ($context ? \App\Models\Company::whereKey($context->companyId)->value('state_code') : null);

        $isLocal = $companyStateCode !== null && $parsed['state_code'] === $companyStateCode;
        $taxRule = $companyStateCode === null ? 'Set your GSTIN in Settings › GST to decide CGST/SGST or IGST'
            : ($isLocal ? 'Local Intra-State (CGST + SGST)' : 'Inter-State (IGST)');

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

        // A match without an address (e.g. the party being edited) still lets the provider fill the gaps.
        if ($existing && trim((string) $existing->address) !== '') {
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

        // 2. If not found locally, query Masters India when configured, else the generic provider URL below.
        $mastersIndia = app(\App\Services\Tax\MastersIndiaGstClient::class);
        if (!$partyData && $parsed['is_valid'] && $mastersIndia->configured()) {
            if ($reg = $mastersIndia->searchGstin($gstin)) {
                $partyData = [
                    'name' => $reg['legal_name'] ?: $reg['trade_name'],
                    'company_name' => $reg['trade_name'] ?: $reg['legal_name'],
                    'address' => $reg['address'],
                    'city' => $reg['city'],
                    'state' => $reg['state'] ?: $parsed['state_name'],
                    'postal_code' => $reg['postal_code'],
                    'phone_number' => '',
                    'email' => '',
                    'party_type' => 'registered_business',
                    'registration_status' => $reg['status'],
                    'source' => 'live_api',
                ];
            }
        } elseif (!$partyData && $parsed['is_valid']) {
            $lookupUrl = config('compliance.gst_lookup_url') ?: env('GST_LOOKUP_URL');
            if ($lookupUrl) {
                try {
                    $url = rtrim($lookupUrl, '/') . '/' . $gstin;
                    $token = config('compliance.gst_lookup_token') ?: env('GST_LOOKUP_TOKEN');
                    $header = config('compliance.gst_lookup_header');
                    // Providers charge per lookup, so a successful response is reused for 30 days.
                    $ext = \Illuminate\Support\Facades\Cache::get('gst-provider:' . $gstin);
                    $providerMessage = null;
                    if (!$ext) {
                        $client = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(5)->acceptJson();
                        if ($token) {
                            $client = $header ? $client->withHeaders([$header => $token]) : $client->withToken($token);
                        }
                        $response = $client->get($url);
                        if ($response->successful()) {
                            $ext = $response->json();
                            \Illuminate\Support\Facades\Cache::put('gst-provider:' . $gstin, $ext, now()->addDays(30));
                        } else {
                            $errJson = $response->json();
                            $providerMessage = is_array($errJson) ? ($errJson['error'] ?? ($errJson['message'] ?? null)) : null;
                        }
                    }
                    if ($ext) {
                        // Normalize across gstinapi.in, gstin_lookup, sandbox, or standard GSP schemas
                        $data = $ext['data'] ?? $ext;
                        $legalName = $data['legalName'] ?? ($data['legal_name'] ?? ($data['lgnm'] ?? ''));
                        $tradeName = $data['tradeName'] ?? ($data['trade_name'] ?? ($data['tradeNam'] ?? $legalName));
                        
                        $rawAddr = $data['principalAddress'] ?? ($data['address'] ?? null);
                        $address = '';
                        $pincode = '';
                        $city = '';
                        $state = '';

                        if (is_array($rawAddr)) {
                            // gstinapi.in object structure
                            $parts = array_filter([
                                $rawAddr['building_no'] ?? ($rawAddr['bno'] ?? ''),
                                $rawAddr['floor_no'] ?? ($rawAddr['flno'] ?? ''),
                                $rawAddr['street'] ?? ($rawAddr['st'] ?? ''),
                                $rawAddr['location'] ?? ($rawAddr['loc'] ?? ''),
                                $rawAddr['district'] ?? ($rawAddr['dst'] ?? ''),
                            ]);
                            $address = implode(', ', $parts);
                            $pincode = $rawAddr['pincode'] ?? ($rawAddr['pncd'] ?? ($rawAddr['postal_code'] ?? ''));
                            $city = $rawAddr['city'] ?? ($rawAddr['district'] ?? ($rawAddr['location'] ?? ''));
                            $state = $rawAddr['state'] ?? '';
                        } elseif (is_string($rawAddr) && trim($rawAddr) !== '') {
                            $address = $rawAddr;
                        } elseif (isset($data['pradr']['addr']) && is_array($data['pradr']['addr'])) {
                            // Sandbox / GSTN pradr structure
                            $addr = $data['pradr']['addr'];
                            $parts = array_filter([$addr['bno'] ?? '', $addr['flno'] ?? '', $addr['st'] ?? '', $addr['loc'] ?? '', $addr['dst'] ?? '']);
                            $address = implode(', ', $parts);
                            $pincode = $addr['pncd'] ?? '';
                            $city = $addr['dst'] ?? ($addr['loc'] ?? '');
                            $state = $addr['stcd'] ?? '';
                        }

                        $pincode = $pincode ?: ($data['pincode'] ?? ($data['postal_code'] ?? ($data['pradr']['addr']['pncd'] ?? '')));
                        $city = $city ?: ($data['city'] ?? ($data['pradr']['addr']['dst'] ?? ''));
                        $state = $state ?: ($data['state'] ?? ($data['pradr']['addr']['stcd'] ?? $parsed['state_name']));

                        $status = $data['status'] ?? ($data['sts'] ?? 'Active');
                        $taxpayerType = $data['taxpayer_type'] ?? ($data['dty'] ?? 'Regular');
                        $regDate = $data['registration_date'] ?? ($data['rgdt'] ?? null);
                        $cancellationDate = $data['cancellation_date'] ?? null;
                        $blockStatus = $data['block_status'] ?? null;
                        $natureOfBusiness = is_array($data['nature_of_business'] ?? null)
                            ? implode(', ', $data['nature_of_business'])
                            : ($data['nature_of_business'] ?? null);
                        $stateJurisdiction = $data['state_jurisdiction'] ?? null;
                        $filingFrequency = $data['filing_frequency'] ?? null;
                        $gstr3bFiledTill = $data['gstr3b_filed_till'] ?? null;
                        $gstr1FiledTill = $data['gstr1_filed_till'] ?? null;

                        if ($legalName || $tradeName) {
                            $partyData = [
                                'name' => $legalName ?: $tradeName,
                                'company_name' => $tradeName ?: $legalName,
                                'legal_name' => $legalName,
                                'trade_name' => $tradeName,
                                'address' => $address,
                                'city' => $city,
                                'state' => $state,
                                'postal_code' => $pincode,
                                'country' => 'India',
                                'phone_number' => $data['phone_number'] ?? '',
                                'email' => $data['email'] ?? '',
                                'party_type' => 'registered_business',
                                'registration_status' => $status,
                                'status' => $status,
                                'taxpayer_type' => $taxpayerType,
                                'registration_date' => $regDate,
                                'cancellation_date' => $cancellationDate,
                                'block_status' => $blockStatus,
                                'nature_of_business' => $natureOfBusiness,
                                'state_jurisdiction' => $stateJurisdiction,
                                'filing_frequency' => $filingFrequency,
                                'gstr3b_filed_till' => $gstr3bFiledTill,
                                'gstr1_filed_till' => $gstr1FiledTill,
                                'pan' => $parsed['pan'],
                                'constitution' => $parsed['constitution'],
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
            'gst_details' => $partyData,
            'provider_message' => $providerMessage ?? null,
        ]);
    }
}

