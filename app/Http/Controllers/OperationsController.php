<?php

namespace App\Http\Controllers;

use App\Models\Operations\Bom;
use App\Models\Operations\JobWorkDispatch;
use App\Models\Operations\JobWorkOrder;
use App\Models\Operations\JobWorkReceipt;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\Project;
use App\Models\Product;
use App\Services\Industry\FmcgInventoryService;
use App\Services\Industry\IndustryProfileService;
use App\Services\Industry\ProjectService;
use App\Services\JobWork\JobWorkService;
use App\Services\Manufacturing\BomService;
use App\Services\Manufacturing\ProductionService;
use App\Services\Operations\OperationPosting;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    private function context(Request $request): CompanyContext { return $request->attributes->get(CompanyContext::class); }
    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key', '');
        validator(['key' => $key], ['key' => 'nullable|string|max:150'])->validate();
        return $key ?? '';
    }

    public function hub(Request $request, string $area)
    {
        $context = $this->context($request); $actor = $request->user()->id;
        [$capability, $permission] = match ($area) {
            'manufacturing' => ['manufacturing.bom', 'manufacturing.read'], 'job-work' => ['operations.job_work', 'job_work.read'],
            'profiles' => ['core.inventory', 'profiles.manage'], 'projects' => ['operations.projects', 'projects.read'],
            'stock' => ['core.inventory', 'products-index'], default => abort(404),
        };
        app(OperationPosting::class)->authorize($capability, $permission, $context, $actor);
        $data = $this->lookups($context) + ['area' => $area, 'context' => $context];
        $data['records'] = match ($area) {
            'manufacturing' => ProductionOrder::visibleIn($context)->orderByDesc('id')->limit(100)->get(),
            'job-work' => JobWorkOrder::visibleIn($context)->orderByDesc('id')->limit(100)->get(),
            'projects' => Project::visibleIn($context)->orderByDesc('id')->limit(100)->get(), default => collect(),
        };
        $data['boms'] = Bom::forCompany($context)->orderByDesc('id')->limit(100)->get();
        $data['profile'] = app(IndustryProfileService::class)->settings($context);
        $data['processes'] = DB::table('process_types')->where('company_id', $context->companyId)->where('is_active', true)->get();
        $data['pending'] = $area === 'job-work' ? app(JobWorkService::class)->deadlines($context, $actor) : [];
        $data['batchBalances'] = $area === 'stock' && in_array('inventory.batch_expiry', $data['navigation'])
            ? app(\App\Services\Industry\IndustryStockQueries::class)->batchBalances($context, $actor) : [];
        $data['shrinkage'] = $area === 'job-work' ? JobWorkReceipt::visibleIn($context)->whereNull('reversed_at')
            ->when(JobWorkService::supports('job_work_receipts', 'receipt_kind'), fn ($q) => $q->where('receipt_kind', '!=', 'material_return'))
            ->orderByDesc('id')->limit(100)->get() : collect();
        if ($area === 'job-work') $data += $this->jobWorkRegisters($context);
        $data['legacyProductions'] = $area === 'manufacturing' && \Illuminate\Support\Facades\Schema::hasTable('productions')
            ? DB::table('productions')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)->orderByDesc('id')->limit(100)->get() : collect();
        return $request->expectsJson() ? response()->json(['data' => $data]) : view('backend.operations.hub', $data);
    }

    /** Material DC and Job Work GRN registers across orders, so an operator can find a document by its own number. */
    private function jobWorkRegisters(CompanyContext $context): array
    {
        $orders = DB::table('job_work_orders as o')->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->leftJoin('process_types as p', 'p.id', '=', 'o.process_type_id')
            ->where('o.company_id', $context->companyId)->where('o.branch_id', $context->branchId)
            ->select('o.id', 'o.reference_no', 's.name as worker', 'p.name as process')->get()->keyBy('id');
        $dispatches = JobWorkDispatch::visibleIn($context)->orderByDesc('id')->limit(100)->get();
        $receipts = JobWorkReceipt::visibleIn($context)->orderByDesc('id')->limit(100)->get();
        $dcOf = JobWorkDispatch::visibleIn($context)->whereIn('id', $receipts->pluck('dispatch_id'))->get(['id', 'reference_no', 'job_work_order_id'])->keyBy('id');
        $sent = DB::table('job_work_dispatch_lines')->whereIn('dispatch_id', $dispatches->pluck('id'))->groupBy('dispatch_id')
            ->selectRaw('dispatch_id, SUM(qty_base) AS qty')->pluck('qty', 'dispatch_id');
        return ['orderInfo' => $orders, 'dispatchRegister' => $dispatches, 'receiptRegister' => $receipts, 'receiptDc' => $dcOf, 'dispatchSent' => $sent,
            'orderCounts' => [
                'dc' => DB::table('job_work_dispatches')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
                    ->whereNull('reversed_at')->groupBy('job_work_order_id')->selectRaw('job_work_order_id, COUNT(*) AS n')->pluck('n', 'job_work_order_id'),
                'grn' => DB::table('job_work_receipts as r')->join('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
                    ->where('r.company_id', $context->companyId)->where('r.branch_id', $context->branchId)->whereNull('r.reversed_at')
                    ->groupBy('d.job_work_order_id')->selectRaw('d.job_work_order_id, COUNT(*) AS n')->pluck('n', 'job_work_order_id'),
            ]];
    }

    private function lookups(CompanyContext $context): array
    {
        return ['productAliasLabels' => app(\App\Services\Commercial\ProductAliasService::class)->commonLabels($context),
            'navigation' => app(CapabilityService::class)->forNavigation($context),
            'products' => Product::forCompany($context)->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name', 'code', 'type', 'unit_id']),
            'units' => DB::table('units')->where('company_id', $context->companyId)->get(['id', 'unit_name']),
            'warehouses' => DB::table('warehouses')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
                ->whereNull('external_job_order_id')->where('is_active', true)->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->where('company_id', $context->companyId)->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name']),
            'customers' => DB::table('customers')->where('company_id', $context->companyId)->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name']),
            'date' => \Carbon\CarbonImmutable::now(\App\Models\Company::findOrFail($context->companyId)->timezone)->toDateString()];
    }

    public function detail(Request $request, string $kind, int $id)
    {
        $context = $this->context($request); $actor = $request->user()->id;
        [$model, $capability, $permission] = match ($kind) {
            'production' => [ProductionOrder::class, 'manufacturing.production', 'manufacturing.read'],
            'job-work' => [JobWorkOrder::class, 'operations.job_work', 'job_work.read'],
            'project' => [Project::class, 'operations.projects', 'projects.read'], default => abort(404),
        };
        app(OperationPosting::class)->authorize($capability, $permission, $context, $actor);
        $record = $model::visibleIn($context)->findOrFail($id);
        $data = $this->lookups($context) + compact('kind', 'record', 'context');
        if ($kind === 'production') {
            $data['bom'] = Bom::forCompany($context)->findOrFail($record->bom_id);
            $data['components'] = DB::table('bom_lines as l')->join('products as p', 'p.id', '=', 'l.component_product_id')
                ->where('p.company_id', $context->companyId)->where('l.bom_id', $record->bom_id)->select('l.*', 'p.name')->get();
            $data['outputs'] = DB::table('production_outputs as l')->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('p.company_id', $context->companyId)->where('l.production_order_id', $id)->select('l.*', 'p.name')->get();
        } elseif ($kind === 'job-work') {
            $data['dispatches'] = JobWorkDispatch::visibleIn($context)->where('job_work_order_id', $id)->get();
            $data['dispatchLines'] = DB::table('job_work_dispatch_lines as l')->join('job_work_dispatches as d', 'd.id', '=', 'l.dispatch_id')
                ->join('products as p', 'p.id', '=', 'l.product_id')->where('d.company_id', $context->companyId)->where('d.branch_id', $context->branchId)
                ->where('p.company_id', $context->companyId)->where('d.job_work_order_id', $id)->select('l.*', 'p.name')->get()->groupBy('dispatch_id');
            $data['receipts'] = JobWorkReceipt::visibleIn($context)->whereIn('dispatch_id', $data['dispatches']->pluck('id'))->get();
            // Route defaults (output item, processing-charge item and rate) pre-fill the forms; every value stays editable.
            $data['route'] = DB::table('process_types')->where('company_id', $context->companyId)->find($record->process_type_id);
            $data['jobWorker'] = DB::table('suppliers')->where('company_id', $context->companyId)->find($record->job_worker_party_id);
            // Every active service item (not cut by the 200-product list) for the processing-charge select.
            $data['serviceProducts'] = Product::forCompany($context)->where('is_active', true)->where('type', 'service')->orderBy('name')->get(['id', 'name']);
            // When the process has no default charge, start from the last bill of this process, else this job worker, else the company.
            $lastCharge = fn ($orders) => DB::table('job_work_receipts as r')->join('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
                ->join('product_purchases as l', 'l.purchase_id', '=', 'r.service_purchase_id')
                ->where('r.company_id', $context->companyId)->whereIn('d.job_work_order_id', $orders)->orderByDesc('r.id')->first(['l.product_id', 'l.net_unit_cost']);
            $sameProcess = DB::table('job_work_orders')->where('company_id', $context->companyId)->where('process_type_id', $record->process_type_id)->pluck('id');
            $sameWorker = DB::table('job_work_orders')->where('company_id', $context->companyId)->where('job_worker_party_id', $record->job_worker_party_id)->pluck('id');
            $last = $lastCharge($sameProcess) ?? $lastCharge($sameWorker)
                ?? $lastCharge(DB::table('job_work_orders')->where('company_id', $context->companyId)->pluck('id'));
            $data['chargeDefault'] = ['product_id' => $last->product_id ?? ($data['serviceProducts']->count() === 1 ? $data['serviceProducts']->first()->id : null),
                'rate' => isset($last->net_unit_cost) ? (float) $last->net_unit_cost : null];
            // The job worker's own name for a product (their bill wording), kept as a supplier-scoped alternate name.
            $data['workerAliases'] = \Illuminate\Support\Facades\Schema::hasTable('product_aliases')
                ? DB::table('product_aliases')->where('company_id', $context->companyId)->where('party_type', 'supplier')
                    ->where('party_id', $record->job_worker_party_id)->orderByDesc('id')->get()->unique('product_id')->pluck('name', 'product_id')
                : collect();
            $data['acceptedQty'] = DB::table('job_work_receipt_lines')->whereIn('receipt_id', $data['receipts']->pluck('id'))
                ->groupBy('receipt_id')->selectRaw('receipt_id, SUM(accepted_qty) AS qty')->pluck('qty', 'receipt_id');
            // What each DC line still has outside: sent minus every active GRN / return settled against it.
            $data['settledQty'] = DB::table('job_work_receipt_lines as l')->join('job_work_receipts as r', 'r.id', '=', 'l.receipt_id')
                ->whereIn('r.id', $data['receipts']->whereNull('reversed_at')->pluck('id'))->groupBy('l.dispatch_line_id')
                ->selectRaw('l.dispatch_line_id, SUM(l.received_qty + l.loss_qty) AS qty')->pluck('qty', 'dispatch_line_id');
            $data['receiptLines'] = DB::table('job_work_receipt_lines as l')->join('job_work_dispatch_lines as d', 'd.id', '=', 'l.dispatch_line_id')
                ->join('products as p', 'p.id', '=', 'd.product_id')->where('p.company_id', $context->companyId)
                ->whereIn('l.receipt_id', $data['receipts']->pluck('id'))->select('l.*', 'd.product_id', 'p.name')->get()->groupBy('receipt_id');
            $data['receiptOutputs'] = DB::table('stock_movement_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('l.company_id', $context->companyId)->where('l.qty_base', '>', 0)
                ->whereIn('l.stock_movement_id', $data['receipts']->pluck('output_movement_id')->filter())
                ->select('l.stock_movement_id', 'l.qty_base', 'p.name')->get()->groupBy('stock_movement_id');
            $bills = DB::table('purchases')->where('company_id', $context->companyId)
                ->whereIn('id', $data['receipts']->pluck('service_purchase_id')->filter())->get(['id', 'reference_no', 'total_tax', 'grand_total', 'attributes_json'])->keyBy('id');
            $data['billNumbers'] = $bills->pluck('reference_no', 'id');
            $data['billTotals'] = $bills;
            // Edit bill pre-fills from the booked charge line.
            $data['billLines'] = DB::table('product_purchases')->where('company_id', $context->companyId)->whereIn('purchase_id', $bills->keys())
                ->orderBy('id')->get(['purchase_id', 'product_id', 'qty', 'net_unit_cost'])->unique('purchase_id')->keyBy('purchase_id');
        } else {
            $data['serials'] = DB::table('stock_identities as i')->join('products as p', 'p.id', '=', 'i.product_id')
                ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')->where('i.company_id', $context->companyId)
                ->where('p.company_id', $context->companyId)->where('w.company_id', $context->companyId)->where('w.branch_id', $context->branchId)
                ->where('i.identity_type', 'serial')->where('i.status', 'in_stock')
                ->whereNotIn('i.id', DB::table('project_serial_reservations')->whereNotNull('active_key')->select('stock_identity_id'))
                ->select('i.id', 'i.identity_no', 'p.name')->limit(200)->get();
            $data['reservations'] = \App\Models\Operations\SerialReservation::visibleIn($context)->where('project_id', $id)->get();
            $data['installations'] = \App\Models\Operations\ProjectInstallation::visibleIn($context)->where('project_id', $id)->get();
            $data['reservedIdentities'] = \App\Models\Inventory\StockIdentity::forCompany($context)
                ->whereIn('id', $data['reservations']->pluck('stock_identity_id'))->pluck('identity_no', 'id');
            $data['contracts'] = DB::table('warranty_contracts')->where('company_id', $context->companyId)
                ->whereIn('installation_id', $data['installations']->pluck('id'))->orderBy('ends_on')->get()->groupBy('installation_id');
            $data['serviceEvents'] = DB::table('serial_service_events')->where('company_id', $context->companyId)
                ->whereIn('installation_id', $data['installations']->pluck('id'))->orderBy('business_date')->orderBy('id')->get()->groupBy('installation_id');
            $data['documentLinks'] = DB::table('project_document_links')->where('company_id', $context->companyId)->where('project_id', $id)->get();
            $data['margin'] = app(ProjectService::class)->margin($id, $context, $actor);
            $data['templates'] = Bom::forCompany($context)->where('status', 'published')->get();
        }
        return $request->expectsJson() ? response()->json(['data' => $data]) : view('backend.operations.detail', $data);
    }

    public function action(Request $request, string $action, ?int $id = null)
    {
        $context = $this->context($request); $actor = $request->user()->id; $data = $request->all(); $key = $this->key($request);
        try {
            $record = $this->perform($action, $id, $request, $data, $key, $context, $actor);
        } catch (\InvalidArgumentException $error) {
            // Setup gaps (e.g. an unmapped posting account) come back to the form as a message, not an error page.
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => $error->getMessage()]);
        }
        $url = match (true) {
            $record instanceof ProductionOrder => url('/operations/production/'.$record->id),
            $record instanceof JobWorkOrder => url('/operations/job-work/'.$record->id),
            $record instanceof JobWorkDispatch => url('/operations/job-work/'.$record->job_work_order_id),
            $record instanceof JobWorkReceipt => url('/operations/job-work/'.JobWorkDispatch::visibleIn($context)->findOrFail($record->dispatch_id)->job_work_order_id),
            $record instanceof Project => url('/operations/project/'.$record->id),
            $record instanceof \App\Models\Operations\SerialReservation || $record instanceof \App\Models\Operations\ProjectInstallation => url('/operations/project/'.$record->project_id),
            $record instanceof Bom => url('/operations/manufacturing'), default => url('/operations/stock'),
        };
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => $record, 'redirect' => $url], 201)
            : redirect($url)->with('message', 'Operation saved.');
    }

    private function perform(string $action, ?int $id, Request $request, array $data, string $key, CompanyContext $context, int $actor)
    {
        return match ($action) {
            'bom' => app(BomService::class)->create($data, $key, $context, $actor),
            'production-plan' => app(ProductionService::class)->plan($data, $key, $context, $actor),
            'production-complete' => app(ProductionService::class)->complete($id, $data, $key, $context, $actor),
            'production-reverse' => app(ProductionService::class)->reverse($id, $request->string('business_date')->toString(), $request->string('reason')->toString(), $context, $actor),
            'job-order' => app(JobWorkService::class)->order($data, $key, $context, $actor),
            'job-dispatch' => app(JobWorkService::class)->dispatch($id, $data, $key, $context, $actor),
            'job-receive' => app(JobWorkService::class)->receive($id, $data, $key, $context, $actor),
            'job-return' => app(JobWorkService::class)->returnMaterial($id, $data, $key, $context, $actor),
            'job-rework' => app(JobWorkService::class)->rework($id, $data, $key, $context, $actor),
            'job-bill' => app(JobWorkService::class)->serviceBill($id, $data, $key, $context, $actor),
            'job-rebill' => app(JobWorkService::class)->rebill($id, $data, $key, $context, $actor),
            'job-reverse-dispatch' => app(JobWorkService::class)->reverse('dispatch', $id, $request->string('business_date')->toString(), $request->string('reason')->toString(), $context, $actor),
            'job-reverse-receipt' => app(JobWorkService::class)->reverse('receipt', $id, $request->string('business_date')->toString(), $request->string('reason')->toString(), $context, $actor),
            'project' => app(ProjectService::class)->create($data, $key, $context, $actor),
            'project-quotation' => app(ProjectService::class)->quotation($id, $data, $key, $context, $actor),
            'project-allocate' => app(ProjectService::class)->allocate($id, $data, $key, $context, $actor),
            'project-dispatch' => app(ProjectService::class)->dispatch($id, $data, $key, $context, $actor),
            'project-install' => app(ProjectService::class)->install($id, $data, $key, $context, $actor),
            'project-commission' => app(ProjectService::class)->commission($id, $data, $key, $context, $actor),
            'project-service' => app(ProjectService::class)->recordService($id, $data, $key, $context, $actor),
            'project-link' => $this->linkProject($id, $request, $context, $actor),
            'expiry-writeoff' => app(FmcgInventoryService::class)->writeOff($data, $key, $context, $actor),
            default => abort(404),
        };
    }

    public function configure(Request $request, string $action)
    {
        $context = $this->context($request); $actor = $request->user()->id;
        $data = match ($action) {
            'profile' => app(IndustryProfileService::class)->apply($request->string('profile')->toString(), $request->input('subtype'), $context, $actor),
            'process' => ['id' => app(JobWorkService::class)->configureProcess($request->all(), $context, $actor)],
            'scheme' => ['id' => app(FmcgInventoryService::class)->configureScheme($request->all(), $context, $actor)],
            default => abort(404),
        };
        return $request->expectsJson() ? response()->json(['data' => $data]) : back()->with('message', 'Configuration saved.');
    }

    private function linkProject(int $id, Request $request, CompanyContext $context, int $actor): Project
    {
        $data = $request->validate(['source_type' => 'required|in:sale,purchase,expense', 'source_id' => 'required|integer|min:1']);
        app(ProjectService::class)->link($id, $data['source_type'], $data['source_id'], $context, $actor);
        return Project::visibleIn($context)->findOrFail($id);
    }

    public function attributes(Request $request, int $id)
    {
        $values = $request->isMethod('POST') ? $request->validate(['attributes' => 'required|array'])['attributes'] : null;
        return response()->json(['data' => app(IndustryProfileService::class)->attributes($id, $this->context($request), $request->user()->id, $values)]);
    }

    public function fefo(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1',
            'qty' => 'required|numeric|gt:0', 'date' => 'required|date_format:Y-m-d']);
        return response()->json(['data' => app(FmcgInventoryService::class)->suggest($data['product_id'], $data['warehouse_id'], $data['qty'], $data['date'], $this->context($request), $request->user()->id)]);
    }

    public function pieces(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1', 'q' => 'nullable|string|max:100']);
        return response()->json(['data' => app(\App\Services\Industry\IndustryStockQueries::class)->pieces($data['product_id'], $data['warehouse_id'],
            $data['q'] ?? '', $this->context($request), $request->user()->id)]);
    }

    public function lots(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1']);
        return response()->json(['data' => app(\App\Services\Industry\IndustryStockQueries::class)->lots($data['product_id'], $data['warehouse_id'],
            $this->context($request), $request->user()->id)]);
    }

    public function materialDocument(Request $request, int $id)
    {
        $context = $this->context($request);
        app(OperationPosting::class)->authorize('operations.job_work', 'job_work.read', $context, $request->user()->id);
        $dispatch = JobWorkDispatch::visibleIn($context)->findOrFail($id);
        $order = JobWorkOrder::visibleIn($context)->findOrFail($dispatch->job_work_order_id);
        $challan = $this->jobWorkChallan($dispatch, $order, $context);
        if ($request->input('format') === 'dot_matrix') {
            app(CapabilityService::class)->assertEnabled('printing.dot_matrix', $context);
            $c = $challan; $rows = [$c['company']['name'], $c['company']['address'], 'GSTIN: '.($c['company']['gstin'] ?: 'Unregistered'),
                'DELIVERY CHALLAN - GOODS SENT FOR JOB WORK (Rule 55(1)(c))', 'Challan No. '.$dispatch->reference_no.'   Date: '.$c['date'].'   Order: '.$order->reference_no,
                'To: '.$c['worker']['name'].'  GSTIN: '.($c['worker']['gstin'] ?: 'Unregistered'), '    '.$c['worker']['address'],
                'Place of supply: '.$c['place_of_supply'], 'Purpose: '.$c['purpose'], str_repeat('-', 80),
                sprintf('%-30s %-8s %14s %10s %14s', 'Description', 'HSN', 'Quantity', 'Rate', 'Value')];
            foreach ($c['lines'] as $line) $rows[] = sprintf('%-30s %-8s %14s %10s %14s', mb_strimwidth($line['name'].($line['lot'] ? ' Lot '.$line['lot'] : ''), 0, 30),
                $line['hsn'], number_format($line['qty'], 3).' '.$line['unit'], number_format($line['rate'], 2), number_format($line['value'], 2));
            array_push($rows, str_repeat('-', 80), sprintf('%-64s %15s', 'Total value', number_format($c['total'], 2)), $c['value_words'], 'No GST charged: goods sent for job work under Section 143 of the CGST Act.',
                'Vehicle: '.($c['transport']['vehicle_number'] ?? '-').'  Transporter: '.($c['transport']['transporter_name'] ?? '-').'  E-way bill: '.($c['transport']['eway_bill_no'] ?? '-'),
                '', 'Receiver signature: ______________          For '.$c['company']['name'].' : Authorised Signatory');
            $pages = [];
            foreach (array_chunk($rows, 68) as $page) $pages[] = implode("\r\n", array_map(fn ($row) => mb_strimwidth($row, 0, 80), array_pad($page, 68, '')));
            return response(implode("\f", $pages), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        abort_unless(in_array($request->input('format', 'a4'), ['a4'], true), 422);
        return view('backend.operations.job_work_challan', ['dispatch' => $dispatch, 'order' => $order, 'c' => $challan]);
    }

    /** Job Work GRN print: material received back from the job worker (accepted / rejected / loss), no prices or GST. */
    public function receiptDocument(Request $request, int $id)
    {
        $context = $this->context($request);
        app(OperationPosting::class)->authorize('operations.job_work', 'job_work.read', $context, $request->user()->id);
        $receipt = JobWorkReceipt::visibleIn($context)->findOrFail($id);
        $dispatch = JobWorkDispatch::visibleIn($context)->findOrFail($receipt->dispatch_id);
        $order = JobWorkOrder::visibleIn($context)->findOrFail($dispatch->job_work_order_id);
        $c = $this->jobWorkChallan($dispatch, $order, $context); // company and job worker letterhead, same as the DC
        $unitCode = \Illuminate\Support\Facades\Schema::hasColumn('units', 'unit_code') ? 'u.unit_code' : DB::raw('NULL AS unit_code');
        $hsn = \Illuminate\Support\Facades\Schema::hasColumn('products', 'hsn_code') ? 'p.hsn_code' : DB::raw('NULL AS hsn_code');
        $lines = DB::table('job_work_receipt_lines as l')->join('job_work_dispatch_lines as d', 'd.id', '=', 'l.dispatch_line_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('l.receipt_id', $receipt->id)->where('p.company_id', $context->companyId)->orderBy('d.line_no')
            ->get(['p.name', 'p.code', $hsn, $unitCode, 'd.qty_base as sent', 'l.accepted_qty', 'l.rejected_qty', 'l.loss_qty']);
        $outputs = $receipt->output_movement_id ? DB::table('stock_movement_lines as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')->where('m.company_id', $context->companyId)
            ->where('m.stock_movement_id', $receipt->output_movement_id)->where('m.qty_base', '>', 0)->get(['p.name', 'p.code', $unitCode, 'm.qty_base']) : collect();
        $warehouse = fn ($wid) => $wid ? DB::table('warehouses')->where('company_id', $context->companyId)->where('id', $wid)->value('name') : null;
        $bill = $receipt->service_purchase_id ? DB::table('purchases')->where('company_id', $context->companyId)->where('id', $receipt->service_purchase_id)->value('reference_no') : null;
        return view('backend.operations.job_work_grn', ['receipt' => $receipt, 'dispatch' => $dispatch, 'order' => $order, 'c' => $c,
            'lines' => $lines, 'outputs' => $outputs, 'receivedInto' => $warehouse($receipt->warehouse_id),
            'quarantine' => $warehouse($receipt->details_json['rejected_warehouse_id'] ?? null), 'bill' => $bill]);
    }

    /** Everything Rule 55 asks of a delivery challan for goods sent to a job worker, from the dispatch and its masters. */
    private function jobWorkChallan(JobWorkDispatch $dispatch, JobWorkOrder $order, CompanyContext $context): array
    {
        $states = \App\Services\Tax\Gstin::$states;
        $company = \App\Models\Company::findOrFail($context->companyId);
        $registration = app(\App\Services\Tax\GstSetupService::class)->registration($context, (string) $dispatch->business_date);
        $profiles = app(\App\Services\Platform\CompanyProfileService::class);
        $worker = DB::table('suppliers')->where('company_id', $context->companyId)->find($order->job_worker_party_id);
        // Without GST tables (non-GST setups) the challan still prints, just without those details.
        $profile = \Illuminate\Support\Facades\Schema::hasTable('party_tax_profiles') ? DB::table('party_tax_profiles')->where('company_id', $context->companyId)
            ->where('party_type', 'supplier')->where('party_id', $order->job_worker_party_id)->first(['gstin', 'state_code']) : null;
        $workerGstin = trim((string) ($worker->vat_number ?? '')) ?: ($profile->gstin ?? '');
        $workerState = $workerGstin ? substr($workerGstin, 0, 2) : ($profile->state_code ?? app(\App\Services\Tax\GstSetupService::class)->stateCode($worker->state ?? null));
        $companyState = $registration ? substr($registration->gstin, 0, 2) : $company->state_code;
        $gst = app(\App\Services\Tax\GstSetupService::class);
        $hasRates = \App\Services\Tax\GstSetupService::available();
        $columns = ['l.*', 'p.name', 'p.code'];
        foreach (['hsn_code', 'tax_category_id'] as $column) $columns[] = \Illuminate\Support\Facades\Schema::hasColumn('products', $column) ? 'p.'.$column : DB::raw('NULL AS '.$column);
        $columns[] = \Illuminate\Support\Facades\Schema::hasColumn('units', 'unit_code') ? 'u.unit_code' : DB::raw('NULL AS unit_code');
        $lines = DB::table('job_work_dispatch_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')->where('p.company_id', $context->companyId)->where('l.dispatch_id', $dispatch->id)
            ->orderBy('l.line_no')->get($columns)->map(function ($line) use ($gst, $dispatch, $hasRates) {
                $details = json_decode($line->stock_details_json ?? '[]', true) ?: [];
                $product = $hasRates ? \App\Models\Product::find($line->product_id) : null;
                $rate = $product ? ($gst->rateFor($product, (string) $dispatch->business_date)['rate'] ?? null) : null;
                return ['name' => $line->name, 'code' => $line->code, 'hsn' => $line->hsn_code ?: '—', 'unit' => $line->unit_code ?: '',
                    'qty' => (float) $line->qty_base, 'value' => round((float) $line->value, 2),
                    'rate' => (float) $line->qty_base > 0 ? round((float) $line->value / (float) $line->qty_base, 2) : 0, 'gst_rate' => $rate,
                    'lot' => !empty($details['product_batch_id']) ? DB::table('product_batches')->where('id', $details['product_batch_id'])->value('batch_no') : null];
            });
        $total = round($lines->sum('value'), 2);
        $process = $order->details_json['process'] ?? [];
        return [
            'date' => \Carbon\Carbon::parse($dispatch->business_date)->format('d-m-Y'),
            'company' => ['name' => $company->trade_name ?: $company->legal_name, 'legal' => $registration->legal_name ?? $company->legal_name,
                'address' => $registration->address ?? $profiles->fullAddress($company), 'gstin' => $registration->gstin ?? '',
                'state' => $companyState ? (($states[$companyState] ?? $companyState).' ('.$companyState.')') : '', 'phone' => $company->phone, 'email' => $company->email,
                'logo' => \App\Services\Documents\DocumentRenderingService::logo($company->logo)],
            'worker' => ['name' => $worker->company_name ?? null ?: ($worker->name ?? 'Job worker'), 'contact' => $worker->name ?? null,
                'address' => implode(', ', array_filter([$worker->address ?? null, $worker->city ?? null, trim(($worker->state ?? '').' '.($worker->postal_code ?? ''))])),
                'gstin' => $workerGstin, 'state' => $workerState ? (($states[$workerState] ?? $workerState).' ('.$workerState.')') : '', 'phone' => $worker->phone_number ?? null],
            'place_of_supply' => $workerState ? (($states[$workerState] ?? $workerState).' ('.$workerState.')') : '—',
            'inter_state' => $workerState && $companyState && $workerState !== $companyState,
            'purpose' => 'Sent for job work'.(($process['name'] ?? null) ? ' - '.$process['name'] : ''),
            'process' => $process, 'expected_return' => $order->expected_return_date ? \Carbon\Carbon::parse($order->expected_return_date)->format('d-m-Y') : null,
            'notes' => $order->notes, 'transport' => $dispatch->details_json['transport'] ?? [],
            'lines' => $lines->all(), 'total' => $total,
            'value_words' => \App\Services\Documents\DocumentRenderingService::rupeesInWords($total),
            'tax_note' => 'Goods sent for job work without payment of tax under Section 143 of the CGST Act; not a supply.',
        ];
    }
}
