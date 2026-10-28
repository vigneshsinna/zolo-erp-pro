<?php

namespace App\Http\Controllers;

use App\Models\{Sale, Purchase, Returns, ReturnPurchase, Warehouse};
use App\Services\Commercial\{CommercialPermission, ReturnService, StockLossService, ExchangeService};
use App\Services\Documents\{DocumentRenderingService, CommunicationDispatchService};
use App\Services\Platform\CompanyContext;
use App\Services\Tax\{TaxSetupService, GstinLookupService, GstProjectionService, GstExportAdapter};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComplianceController extends Controller
{
    private function context(Request $request): CompanyContext { return $request->attributes->get(CompanyContext::class); }
    private function permit(Request $request, string $permission): CompanyContext
    {
        $context = $this->context($request); app(CommercialPermission::class)->assert($permission, $context, $request->user()->id); return $context;
    }

    public function setup(Request $request)
    {
        $context = $this->permit($request, 'gst-index');
        $scope = ['company_id' => $context->companyId];
        return view('backend.compliance.setup', ['context' => $context,
            'registrations' => DB::table('tax_registrations')->where($scope)->where('branch_id', $context->branchId)->get(),
            'categories' => DB::table('tax_categories')->where($scope)->get(),
            'rates' => DB::table('tax_rates')->where($scope)->orderBy('effective_from')->get(),
            'profiles' => DB::table('print_profiles')->where($scope)->get(),
            'channels' => DB::table('document_channels')->where($scope)->get(['channel', 'is_active']),
            'warehouses' => Warehouse::forCompany($context)->where('branch_id', $context->branchId)->whereNull('external_job_order_id')->get(),
            'products' => \App\Models\Product::forCompany($context)->where('type', 'standard')->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code', 'is_batch']),
            'policy' => \App\Models\Company::findOrFail($context->companyId)->settings_json['return_policy'] ?? null,
            'canManage' => app(\App\Services\Platform\CompanyContextResolver::class)->canManageFinancialYears($request->user()->id, $context->companyId)]);
    }

    public function saveSetup(Request $request, string $resource)
    {
        app(TaxSetupService::class)->save($resource, $request->except('_token'), $this->context($request), $request->user()->id);
        return $request->expectsJson() ? response()->json(['success' => true]) : back()->with('message', 'Settings saved.');
    }

    public function lookup(Request $request)
    {
        $context = $this->permit($request, 'gst-index'); $request->validate(['gstin' => 'required|string|max:15']);
        return response()->json(['data' => app(GstinLookupService::class)->lookup($request->gstin, $context, $request->user()->id)]);
    }

    public function report(Request $request)
    {
        $context = $this->permit($request, 'gst-index');
        $year = \App\Models\Accounting\FiscalYear::where('company_id', $context->companyId)->findOrFail($context->financialYearId);
        $from = $request->input('from', $year->start_date->toDateString()); $to = $request->input('to', $year->end_date->toDateString());
        $rows = app(GstProjectionService::class)->report($context, $from, $to);
        return $request->expectsJson() ? response()->json(['data' => $rows])
            : view('backend.compliance.report', compact('context', 'rows', 'from', 'to') + $this->reviewSummary($rows));
    }

    /** Signed per-document, per-section and per-HSN totals of the posted snapshots, for the review screen only. */
    private function reviewSummary(array $rows): array
    {
        $taxes = ['taxable_value', 'cgst', 'sgst', 'igst', 'cess'];
        $documents = collect($rows)->map(function ($row) use ($taxes) {
            $sign = ($row['snapshot']['sign'] ?? 1) < 0 ? -1 : 1;
            $lines = collect($row['snapshot']['lines'] ?? []);
            $inward = $row['section'] === 'inward';
            return $row + array_combine($taxes, array_map(fn ($tax) => $sign * (float) $lines->sum($tax), $taxes)) + [
                'sign' => $sign, 'total' => $sign * (float) ($row['snapshot']['grand_total'] ?? 0),
                // The other party: the supplier on inward documents, the buyer on outward ones.
                'party' => $inward ? ($row['snapshot']['seller_name'] ?? null) : ($row['snapshot']['buyer_name'] ?? null),
                'party_gstin' => $inward ? ($row['snapshot']['seller_gstin'] ?? null) : ($row['snapshot']['buyer_gstin'] ?? null),
                'place' => $row['snapshot']['place_of_supply'] ?? null];
        });
        $sum = fn ($set) => array_combine($taxes, array_map(fn ($tax) => round($set->sum($tax), 2), $taxes)) + ['count' => $set->count(), 'total' => round($set->sum('total'), 2)];
        $hsn = $documents->where('section', '!=', 'inward')->flatMap(fn ($row) => collect($row['snapshot']['lines'] ?? [])
            ->map(fn ($line) => ['key' => ($line['hsn_sac'] ?? '—').'|'.($line['rate'] ?? 0), 'hsn' => $line['hsn_sac'] ?? '—', 'rate' => (float) ($line['rate'] ?? 0)]
                + array_combine($taxes, array_map(fn ($tax) => $row['sign'] * (float) ($line[$tax] ?? 0), $taxes))))
            ->groupBy('key')->map(fn ($set) => ['hsn' => $set->first()['hsn'], 'rate' => $set->first()['rate']] + $sum($set))->sortBy('hsn')->values();
        $outward = $documents->where('section', '!=', 'inward'); $inward = $documents->where('section', 'inward');
        $tax = fn ($set) => $set->sum('cgst') + $set->sum('sgst') + $set->sum('igst') + $set->sum('cess');

        return ['documents' => $documents->values(), 'sections' => $documents->groupBy('section')->map($sum), 'hsn' => $hsn,
            'kpi' => ['outward_taxable' => $outward->sum('taxable_value'), 'output_tax' => $tax($outward),
                'inward_taxable' => $inward->sum('taxable_value'), 'input_tax' => $tax($inward), 'net' => $tax($outward) - $tax($inward)]];
    }

    /** GSTR-3B, Form ITC-04 and the 194Q TDS register for a period, on one screen, with CSV / JSON downloads. */
    public function gstReturns(Request $request)
    {
        $context = $this->permit($request, 'gst-index');
        $year = \App\Models\Accounting\FiscalYear::where('company_id', $context->companyId)->findOrFail($context->financialYearId);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'download' => 'nullable|in:gstr3b,itc04_4,itc04_5a,itc04_5c,tds_194q', 'format' => 'nullable|in:json,csv']);
        $from = $request->input('from', now()->startOfMonth()->max($year->start_date)->toDateString());
        $to = $request->input('to', now()->endOfMonth()->min($year->end_date)->toDateString());
        $returns = app(\App\Services\Tax\GstReturnsService::class);
        $data = ['gstr3b' => $returns->gstr3b($context, $from, $to), 'itc04' => $returns->itc04($context, $from, $to),
            'tds' => app(\App\Services\Tax\Tds194qService::class)->register($context, $from, $to)];
        if ($download = $request->input('download')) {
            $rows = match ($download) { 'gstr3b' => $data['gstr3b'], 'itc04_4' => $data['itc04']['table_4_sent'],
                'itc04_5a' => $data['itc04']['table_5a_received_back'], 'itc04_5c' => $data['itc04']['table_5c_supplied_from_job_worker'], 'tds_194q' => $data['tds'] };
            $name = $download.'-'.$from.'-'.$to;
            return $request->input('format') === 'csv' && $download !== 'gstr3b'
                ? response($returns->csv($rows), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"'])
                : response()->json($rows)->header('Content-Disposition', 'attachment; filename="'.$name.'.json"');
        }
        return $request->expectsJson() ? response()->json($data) : view('backend.compliance.gst_returns', compact('context', 'from', 'to') + $data);
    }

    /** E-invoice JSON (IRP schema 1.1) for an invoice, or e-way bill JSON for an invoice or Material DC, to upload on the portal. */
    public function eDocument(Request $request, string $format, string $source, int $id)
    {
        $context = $this->permit($request, 'gst-index');
        $service = app(\App\Services\Tax\EDocumentService::class);
        $json = $format === 'einvoice' ? $service->einvoice($id, $context)
            : $service->ewayBill($source, $id, $context, $request->only(['transporter_id', 'distance_km', 'vehicle_number']));
        return response()->json($json, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ->header('Content-Disposition', 'attachment; filename="'.$format.'-'.$source.'-'.$id.'.json"');
    }

    public function export(Request $request)
    {
        $context = $this->permit($request, 'gst-index');
        $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d', 'version' => 'required|string']);
        return response()->json(app(GstExportAdapter::class)->export($request->version, $context, $request->from, $request->to))
            ->header('Content-Disposition', 'attachment; filename="gst-review.json"');
    }

    /** Returns register. $side 'purchase' / 'sale' (from the Purchase or Sales command center) lists that side only. */
    public function notes(Request $request, ?string $side = null)
    {
        $context = $this->context($request); $permissions = app(CommercialPermission::class);
        $side ??= in_array($request->query('side'), ['sale', 'purchase'], true) ? $request->query('side') : null;
        $saleAccess = $side !== 'purchase' && $permissions->allows('returns-index', $context, $request->user()->id);
        $purchaseAccess = $side !== 'sale' && $permissions->allows('purchase-return-index', $context, $request->user()->id);
        abort_unless($saleAccess || $purchaseAccess, 403);
        $query = fn ($model) => $model::forCompany($context)->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)->latest()->limit(100)->get();
        $sales = $saleAccess ? $query(Returns::class) : collect(); $purchases = $purchaseAccess ? $query(ReturnPurchase::class) : collect();
        if (!$request->expectsJson()) return redirect()->route($side === 'purchase' ? 'purchases.index' : 'sales.index', ['returns' => 1]);
        return response()->json(['data' => compact('sales', 'purchases')]);
    }

    /** Posted, unreversed bills of this branch that a return tab can pick, newest first. */
    public function returnable(Request $request, string $kind)
    {
        $context = $this->permit($request, $kind === 'sale' ? 'returns-add' : 'purchase-return-add');
        $party = $kind === 'sale' ? 'customer' : 'supplier';
        $search = trim((string) $request->query('q', ''));
        $bills = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->with($party)
            ->where('branch_id', $context->branchId)->whereNotNull('posted_at')->whereNull('reversed_at')
            ->when($request->integer('party_id'), fn ($q, $id) => $q->where($party.'_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference_no', 'like', "%{$search}%")
                ->orWhereHas($party, fn ($p) => $p->where('name', 'like', "%{$search}%"))))
            ->latest('created_at')->limit(30)->get();
        return response()->json(['data' => $bills->map(fn ($bill) => [
            'id' => $bill->id, 'reference_no' => $bill->reference_no, 'date' => $bill->created_at->toDateString(),
            'party' => $bill->{$party}?->name, 'grand_total' => (float) $bill->grand_total,
        ])]);
    }

    public function returnForm(Request $request, string $kind, int $id)
    {
        $context = $this->permit($request, $kind === 'sale' ? 'returns-add' : 'purchase-return-add');
        $source = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($id);
        abort_unless($source->posted_at && !$source->reversed_at, 409, 'Return requires an active posted source.');
        $lines = ($kind === 'sale' ? $source->productSales() : $source->productPurchases())->forCompany($context)->with('product')->get();
        $warehouses = Warehouse::forCompany($context)->where('branch_id', $context->branchId)->get();
        $stock = \App\Models\Inventory\StockMovementLine::forCompany($context)->whereHas('movement', fn ($q) => $q->where('source_type', $kind)->where('source_id', $id)->where('projection_mode', 'applied')->whereNull('reversal_of_id'))->with('identity')->get();
        if (!$request->expectsJson()) return redirect()->route($kind === 'sale' ? 'sales.index' : 'purchases.index', ['returns' => 1, 'bill' => $id]);
        return response()->json(['data' => $this->returnSource($kind, $source, $lines, $warehouses, $stock, $context)]);
    }

    /** The bill, its lines with quantity already returned by posted notes, and the warehouses a return may use. */
    private function returnSource(string $kind, Sale|Purchase $source, $lines, $warehouses, $stock, CompanyContext $context): array
    {
        $headerTable = $kind === 'sale' ? 'returns' : 'return_purchases';
        $lineTable = $kind === 'sale' ? 'product_returns' : 'purchase_product_return';
        $returned = DB::table($lineTable)->where('company_id', $context->companyId)->whereIn('source_line_id', $lines->pluck('id'))
            ->whereIn('return_id', DB::table($headerTable)->where('company_id', $context->companyId)->whereNotNull('posted_at')
                ->where('note_type', $kind === 'sale' ? 'credit' : 'debit')->select('id'))
            ->groupBy('source_line_id')->selectRaw('source_line_id, SUM(qty) as qty')->pluck('qty', 'source_line_id');
        $units = DB::table('units')->pluck('unit_code', 'id');
        $party = $kind === 'sale' ? 'customer' : 'supplier';
        return [
            'source' => ['id' => $source->id, 'reference_no' => $source->reference_no, 'date' => $source->created_at->toDateString(),
                'party' => $source->{$party}?->name, 'warehouse_id' => $source->warehouse_id, 'grand_total' => (float) $source->grand_total],
            'lines' => $lines->map(fn ($line) => [
                'id' => $line->id, 'product' => $line->product?->name, 'code' => $line->product?->code,
                'unit' => $units[$line->{$kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id'}] ?? '',
                'qty' => (float) $line->qty, 'returned' => (float) ($returned[$line->id] ?? 0),
                'rate' => (float) ($kind === 'sale' ? $line->net_unit_price : $line->net_unit_cost),
                'tax_rate' => (float) $line->tax_rate, 'tax' => (float) $line->tax, 'total' => (float) $line->total,
                'identities' => $stock->filter(fn ($m) => ($m->attributes_json['commercial_line_id'] ?? 0) == $line->id && $m->stock_identity_id)
                    ->map(fn ($m) => ['id' => $m->id, 'label' => $m->identity->identity_no.' · '.$m->identity->identity_type])->values(),
            ])->values(),
            'warehouses' => $warehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'quarantine' => (bool) $w->is_quarantine])->values(),
        ];
    }

    public function createNote(Request $request, string $kind, int $id)
    {
        $data = $request->except('_token', 'idempotency_key', 'draft_id');
        if (!$request->isJson()) $data['items'] = array_values(array_filter($data['items'] ?? [], fn ($line) => !empty($line['selected'])));
        $note = app(ReturnService::class)->create($kind, $id, $data, $request->header('Idempotency-Key', $request->input('idempotency_key', '')), $this->context($request), $request->user()->id);
        app(\App\Services\Commercial\CommercialDraftService::class)->forgetPosted($kind === 'sale' ? 'sreturn' : 'preturn', $request->integer('draft_id'), $request->user()->id);
        return $request->expectsJson() ? response()->json(['data' => $note], 201) : redirect()->route($kind === 'sale' ? 'sales.index' : 'purchases.index', ['returns' => 1])->with('message', 'Note '.$note->reference_no.' is '.$note->status.'.');
    }

    public function approve(Request $request, string $kind, int $id)
    {
        $note = app(ReturnService::class)->approve($kind, $id, $this->context($request), $request->user()->id);
        return $request->expectsJson() ? response()->json(['data' => $note]) : back()->with('message', 'Note posted.');
    }

    public function document(Request $request, string $kind, int $id)
    {
        $context = $this->permit($request, $kind === 'sale_note' ? 'returns-index' : ($kind === 'purchase_note' ? 'purchase-return-index' : (in_array($kind, ['sale', 'sales_order'], true) ? 'sales-index' : 'purchases-index')));
        $document = app(DocumentRenderingService::class)->dto($kind, $id, $context);
        $profiles = DB::table('print_profiles')->where('company_id', $context->companyId)->where('document_type', $kind)->get();
        $dispatches = DB::table('document_dispatch_logs')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)->where('document_type', $kind)->where('document_id', $id)->latest()->get();
        return $request->expectsJson() ? response()->json(['data' => compact('document', 'profiles', 'dispatches')])
            : view('backend.compliance.document', compact('context', 'document', 'kind', 'id', 'profiles', 'dispatches'));
    }

    public function printDocument(Request $request, string $kind, int $id)
    {
        $context = $this->permit($request, $kind === 'sale_note' ? 'returns-index' : ($kind === 'purchase_note' ? 'purchase-return-index' : (in_array($kind, ['sale', 'sales_order'], true) ? 'sales-index' : 'purchases-index')));
        $request->validate(['format' => 'nullable|in:a4,thermal,dot_matrix', 'profile_id' => 'nullable|integer|min:1', 'output' => 'nullable|in:html,pdf,escp']);
        $renderer = app(DocumentRenderingService::class); $document = $renderer->dto($kind, $id, $context);
        $profile = $renderer->profile($kind, $context, $request->integer('profile_id') ?: null, $request->input('format', 'a4'));
        if ($profile['format'] === 'dot_matrix') {
            $text = $renderer->fixedWidth($document, $profile);
            return response($request->input('output') === 'escp' ? "\x1B@".$text."\x0C" : $text)->header('Content-Type', 'text/plain; charset=us-ascii');
        }
        if ($request->input('output') === 'pdf') {
            $paper = $profile['format'] === 'thermal' ? [0, 0, $profile['width'] * 72 / 25.4, max(300, count($document['lines']) * 80 + 400)] : 'a4';
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($renderer->html($document, $profile))->setPaper($paper)->stream('document.pdf');
        }
        return response($renderer->html($document, $profile));
    }

    public function dispatch(Request $request, string $kind, int $id)
    {
        $request->validate(['channel' => 'required|string', 'recipient' => 'required|string']);
        $log = app(CommunicationDispatchService::class)->request($kind, $id, $request->channel, $request->recipient,
            $request->header('Idempotency-Key', $request->input('idempotency_key', '')), $this->context($request), $request->user()->id);
        return $request->expectsJson() ? response()->json(['data' => $log], 202) : back()->with('message', 'Dispatch requested. See current status in delivery history.');
    }

    public function retry(Request $request, int $id)
    {
        $log = app(CommunicationDispatchService::class)->retry($id, $this->context($request), $request->user()->id);
        return $request->expectsJson() ? response()->json(['data' => $log]) : back()->with('message', 'Delivery queued again.');
    }

    public function loss(Request $request)
    {
        $loss = app(StockLossService::class)->create($request->except('_token', 'idempotency_key'),
            $request->header('Idempotency-Key', $request->input('idempotency_key', '')), $this->context($request), $request->user()->id);
        return $request->expectsJson() ? response()->json(['data' => $loss], 201) : back()->with('message', 'Stock loss posted: '.$loss->reference_no);
    }

    public function exchange(Request $request, int $id)
    {
        $exchange = DB::transaction(function () use ($request, $id) {
            $exchange = app(ExchangeService::class)->create($id, $request->except('_token', 'idempotency_key', 'return_id'),
                $request->header('Idempotency-Key', $request->input('idempotency_key', '')), $this->context($request), $request->user()->id);
            if ($request->filled('draft_id')) app(\App\Services\Commercial\CommercialDraftService::class)
                ->query('sale', $this->context($request), $request->user()->id)->where('id', $request->draft_id)->delete();
            return $exchange;
        });
        return response()->json(['data' => $exchange], 201);
    }
}
