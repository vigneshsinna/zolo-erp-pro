{{-- One job work workspace: the order is the parent; Material DC goes out, Job Work GRN comes back, the service bill closes it.
     Returns, rework and reversals are actions on the document they belong to, not separate modules. --}}
@php
    $dcLabel = \App\Services\Industry\IndustryProfileService::label('dispatch', 'Material DC');
    $grnLabel = \App\Services\Industry\IndustryProfileService::label('receipt', 'Job Work GRN');
    $process = $record->details_json['process'];
    $conversion = (bool) $process['conversion'];
    $outputDefault = $route->output_product_id ?? null;
    $receiptRefs = $receipts->pluck('reference_no', 'id');
    $dcRefs = $dispatches->pluck('reference_no', 'id');
    $activeDispatches = $dispatches->whereNull('reversed_at');
    $activeReceipts = $receipts->whereNull('reversed_at');
    $lineOutside = fn ($line) => max(0, round((float) $line->qty_base - (float) ($settledQty[$line->id] ?? 0), 4));
    $outside = $activeDispatches->sum(fn ($d) => $dispatchLines->get($d->id, collect())->sum($lineOutside));
    $sent = $activeDispatches->sum(fn ($d) => (float) $dispatchLines->get($d->id, collect())->sum('qty_base'));
    $lost = $activeReceipts->sum(fn ($r) => (float) ($r->details_json['loss_qty'] ?? 0));
    $back = $activeReceipts->sum(fn ($r) => (float) ($r->details_json['received_qty'] ?? 0));
    $unbilled = $activeReceipts->filter(fn ($r) => !$r->service_purchase_id && ($r->receipt_kind ?? 'process') !== 'material_return')->count();
    $billed = $activeReceipts->whereNotNull('service_purchase_id')->count();
    $openPanel = old('form_id');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
@endphp

<div class="jw-workspace jw-zero-scroll" @if($openPanel) data-open-form="{{ $openPanel }}" @endif>
    {{-- Compact Unified Header & Stepper Card (Zero Page Scroll) --}}
    <section class="jw-header-card">
        <div class="jw-header-top">
            <div class="jw-title-group">
                <a href="{{ url('/operations/job-work') }}" class="jw-back-link" title="Back to Job Work Hub">
                    <i class="fa fa-arrow-left"></i>
                </a>
                <div class="jw-title-text">
                    <div class="jw-eyebrow-row">
                        <span class="jw-eyebrow">JOB WORK ORDER</span>
                        <span class="jw-status-pill jw-status-{{ \Illuminate\Support\Str::slug($record->status) }}">
                            <span class="jw-status-dot"></span> {{ ucfirst($record->status) }}
                        </span>
                    </div>
                    <h1 class="jw-title-heading">{{ $record->reference_no }}</h1>
                </div>
            </div>

            <div class="jw-metrics-strip">
                <div class="jw-metric-chip" title="Job Worker">
                    <i class="fa fa-user-circle jw-chip-icon"></i>
                    <div class="jw-chip-body">
                        <span class="jw-chip-label">Job Worker</span>
                        <span class="jw-chip-val">{{ $jobWorker->name ?? '—' }}</span>
                    </div>
                </div>
                <div class="jw-metric-chip" title="Process">
                    <i class="fa fa-cogs jw-chip-icon"></i>
                    <div class="jw-chip-body">
                        <span class="jw-chip-label">Process</span>
                        <span class="jw-chip-val">{{ $process['name'] }}</span>
                    </div>
                </div>
                <div class="jw-metric-chip" title="Comes back as">
                    <i class="fa fa-refresh jw-chip-icon"></i>
                    <div class="jw-chip-body">
                        <span class="jw-chip-label">Comes Back As</span>
                        <span class="jw-chip-val">{{ $conversion ? (optional($products->firstWhere('id', $outputDefault))->name ?? 'a new item') : 'the same item' }}</span>
                    </div>
                </div>
                <div class="jw-metric-chip" title="Maximum Loss">
                    <i class="fa fa-percent jw-chip-icon"></i>
                    <div class="jw-chip-body">
                        <span class="jw-chip-label">Max Loss</span>
                        <span class="jw-chip-val text-warning">{{ (float) $process['max_loss_percent'] }}%</span>
                    </div>
                </div>
                <div class="jw-metric-chip" title="Expected Return Date">
                    <i class="fa fa-calendar jw-chip-icon"></i>
                    <div class="jw-chip-body">
                        <span class="jw-chip-label">Expected Return</span>
                        <span class="jw-chip-val">{{ $record->expected_return_date ?? 'Not set' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="jw-stepper-row">
            <ol class="jw-pipeline-stepper" aria-label="Job work progress">
                <li class="done" title="Job Work Order">
                    <div class="jw-step-icon">✓</div>
                    <div class="jw-step-content">
                        <strong>Job Work Order</strong>
                        <span>{{ $record->reference_no }}</span>
                    </div>
                </li>
                <li class="{{ $activeDispatches->count() ? 'done' : 'current' }}" data-target-tab="tab-dc" title="Click to view Material DC">
                    <div class="jw-step-icon">{{ $activeDispatches->count() ? '✓' : '2' }}</div>
                    <div class="jw-step-content">
                        <strong>{{ $dcLabel }}</strong>
                        <span>{{ $activeDispatches->count() }} sent · {{ $qty($sent) }} qty</span>
                    </div>
                </li>
                <li class="{{ $activeReceipts->count() ? ($outside > 0 ? 'current' : 'done') : '' }}" data-target-tab="tab-grn" title="Click to view Job Work GRN">
                    <div class="jw-step-icon">{{ $activeReceipts->count() && $outside <= 0 ? '✓' : '3' }}</div>
                    <div class="jw-step-content">
                        <strong>{{ $grnLabel }}</strong>
                        <span>{{ $qty($back) }} back · {{ $qty($lost) }} loss · <b class="{{ $outside > 0 ? 'text-amber font-weight-bold' : '' }}">{{ $qty($outside) }} outside</b></span>
                    </div>
                </li>
                <li class="{{ $billed && !$unbilled ? 'done' : ($unbilled ? 'current' : '') }}" data-target-tab="tab-grn" title="Click to view Service Bills">
                    <div class="jw-step-icon">{{ $billed && !$unbilled ? '✓' : '4' }}</div>
                    <div class="jw-step-content">
                        <strong>Service Bill</strong>
                        <span>{{ $billed }} billed · {{ $unbilled }} to bill</span>
                    </div>
                </li>
            </ol>
            <div class="jw-stepper-legend">
                <i class="fa fa-shield mr-1 text-primary"></i>
                <span>Material stays company stock at the job worker until it is received back or booked as loss. The job worker's bill is added to the cost of what comes back.</span>
            </div>
        </div>
    </section>

    {{-- Operations Toolbar (Tabs + Primary Action) --}}
    <div class="jw-toolbar">
        <div class="jw-segmented-control" role="tablist">
            <button type="button" class="jw-seg-btn active" data-jw-tab="tab-dc">
                <i class="fa fa-truck mr-1"></i> {{ $dcLabel }}
                <span class="jw-seg-count">{{ $dispatches->count() }}</span>
            </button>
            <button type="button" class="jw-seg-btn" data-jw-tab="tab-grn">
                <i class="fa fa-inbox mr-1"></i> {{ $grnLabel }}
                <span class="jw-seg-count">{{ $receipts->count() }}</span>
                @if($outside > 0)
                    <span class="jw-outside-badge">{{ $qty($outside) }} outside</span>
                @endif
            </button>
            <button type="button" class="jw-seg-btn" data-jw-tab="tab-split" title="View Material DC and GRN side by side">
                <i class="fa fa-columns mr-1"></i> Split View
            </button>
        </div>

        <div class="jw-toolbar-actions">
            <button type="button" class="btn btn-sm btn-primary jw-action-btn jw-toggle" data-jw-toggle="new-dc" aria-expanded="false">+ New {{ $dcLabel }}</button>
        </div>
    </div>

    {{-- Main Work Area: Dual Pane Architecture with Instant Mode Switching (DC / GRN / Split) --}}
    <div class="jw-content-grid mode-dc" id="jw-content-grid">
        {{-- ===================================================================== Pane 1: Material DC --}}
        <div class="jw-card-section" id="pane-dc">
            {{-- Collapsible New Material DC Form --}}
            <div class="jw-form-card jw-panel" id="new-dc" hidden>
                <div class="jw-form-header">
                    <div class="jw-form-title">
                        <i class="fa fa-paper-plane mr-2 text-primary"></i>
                        <span>Send Material &bull; New {{ $dcLabel }}</span>
                    </div>
                    <button type="button" class="jw-form-close" data-jw-close="new-dc" title="Close Form">&times;</button>
                </div>

                @include('backend.operations.form_start',['formId'=>'dispatch-form','action'=>'job-dispatch/'.$record->id])
                <div class="jw-form-fields-grid">
                    @include('backend.operations.warehouse')
                    @include('backend.operations.date')
                    <label>
                        <span>Vehicle no.</span>
                        <input name="transport[vehicle_number]" maxlength="30" placeholder="TN39 AB 1234">
                    </label>
                    <label>
                        <span>Transporter</span>
                        <input name="transport[transporter_name]" maxlength="100" placeholder="e.g. VRL Logistics">
                    </label>
                    <label>
                        <span>E-way bill no.</span>
                        <input name="transport[eway_bill_no]" maxlength="30" placeholder="E-way bill no.">
                    </label>
                    <label>
                        <span>Challan note</span>
                        <input name="transport[notes]" maxlength="500" placeholder="Shade, lot or process instructions">
                    </label>
                </div>

                <div class="jw-form-table-wrap">
                    <table class="jw-form-lines-table" data-lines>
                        <thead>
                            <tr>
                                <th class="u-minw-220px">Product</th>
                                <th class="u-minw-140px">Quantity</th>
                                <th class="u-minw-110px">Unit</th>
                                <th class="u-minw-140px">Tracking</th>
                                <th class="u-w-40px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @include('backend.operations.stock_line',['prefix'=>'lines[0]'])
                        </tbody>
                    </table>
                </div>

                <div class="jw-form-actions-footer">
                    <div class="jw-form-left-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-add-line>
                            <i class="fa fa-plus mr-1"></i> Add material
                        </button>
                    </div>
                    <div class="jw-form-right-actions">
                        <button type="button" class="btn btn-sm btn-light border mr-2" data-jw-close="new-dc">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-sm btn-primary ops-primary font-weight-bold">
                            <i class="fa fa-paper-plane mr-1"></i> Send material
                        </button>
                    </div>
                </div>
                </form>
            </div>

            {{-- Material DC Register Table --}}
            <div class="jw-table-card">
                <div class="jw-table-scroll">
                    <table class="jw-register ops-table">
                        <thead>
                            <tr>
                                <th>{{ $dcLabel }}</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th class="num">Sent</th>
                                <th class="num">Outside</th>
                                <th>Status</th>
                                <th class="jw-actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($dispatches as $dispatch)
                            @php
                                $lines = $dispatchLines->get($dispatch->id, collect());
                                $dcOutside = $lines->sum($lineOutside);
                                $dcReceipts = $receipts->where('dispatch_id', $dispatch->id);
                                $hasActiveReceipts = $dcReceipts->whereNull('reversed_at')->isNotEmpty();
                                $isRework = ($dispatch->purpose ?? 'process') === 'rework';
                                $status = $dispatch->reversed_at ? 'Reversed' : ($dcOutside <= 0 ? 'Settled' : ($hasActiveReceipts ? 'Part received' : 'At job worker'));
                            @endphp
                            <tr class="{{ $dispatch->reversed_at ? 'jw-muted' : '' }}">
                                <td><strong class="jw-ref-code">{{ $dispatch->reference_no }}</strong></td>
                                <td>{{ $dispatch->business_date }}</td>
                                <td><span class="badge badge-light border">{{ $isRework ? 'Rework of '.($receiptRefs[$dispatch->rework_of_receipt_id] ?? 'GRN') : 'Process' }}</span></td>
                                <td class="num font-weight-bold">{{ $qty($lines->sum('qty_base')) }}</td>
                                <td class="num font-weight-bold {{ $dcOutside > 0 ? 'text-amber' : '' }}">{{ $dispatch->reversed_at ? '—' : $qty($dcOutside) }}</td>
                                <td><span class="jw-status-pill jw-status-{{ \Illuminate\Support\Str::slug($status) }}"><span class="jw-status-dot"></span> {{ $status }}</span></td>
                                <td class="jw-actions">
                                    <button type="button" class="btn btn-xs btn-light border jw-toggle" data-jw-toggle="dc-{{ $dispatch->id }}-view">View</button>
                                    <a class="btn btn-xs btn-light border jw-action-link" href="{{ url('/operations/dispatch/'.$dispatch->id.'/print') }}" target="_blank" rel="noopener" title="Print job work challan (GST)"><i class="fa fa-print mr-1"></i> Print</a>
                                    @if(!$dispatch->reversed_at && $dcOutside > 0)
                                        <button type="button" class="btn btn-xs btn-success jw-toggle jw-main" data-jw-toggle="dc-{{ $dispatch->id }}-receive">Receive {{ $grnLabel }}</button>
                                        <button type="button" class="btn btn-xs btn-light border jw-toggle" data-jw-toggle="dc-{{ $dispatch->id }}-return">Return material</button>
                                    @endif
                                    @if(!$dispatch->reversed_at && !$hasActiveReceipts)
                                        <button type="button" class="btn btn-xs btn-outline-danger jw-toggle jw-danger" data-jw-toggle="dc-{{ $dispatch->id }}-reverse">Reverse</button>
                                    @endif
                                </td>
                            </tr>
                            <tr class="jw-panel-row"><td colspan="7">
                                <div class="jw-panel" id="dc-{{ $dispatch->id }}-view" hidden>
                                    <div class="jw-panel-head">
                                        <h4>Challan Details &bull; {{ $dispatch->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="dc-{{ $dispatch->id }}-view">&times;</button>
                                    </div>
                                    <p>{{ $dispatch->business_date }} · <a href="{{ url('/operations/dispatch/'.$dispatch->id.'/print') }}" target="_blank" rel="noopener">Print job work challan (GST)</a> · <a href="{{ url('/compliance/ewaybill/dispatch/'.$dispatch->id) }}" title="E-way bill JSON (sub-type Job Work); needed for inter-state job work whatever the value">E-way bill JSON</a>@if(in_array('printing.dot_matrix',$navigation)) · <a href="{{ url('/operations/dispatch/'.$dispatch->id.'/print?format=dot_matrix') }}" target="_blank" rel="noopener">68-line output</a>@endif
                                    @if(!empty($dispatch->details_json['transport'])) · {{ implode(' · ', array_filter([$dispatch->details_json['transport']['vehicle_number'] ?? null, $dispatch->details_json['transport']['transporter_name'] ?? null, isset($dispatch->details_json['transport']['eway_bill_no']) ? 'E-way '.$dispatch->details_json['transport']['eway_bill_no'] : null])) }}@endif</p>
                                    <table><thead><tr><th>Material</th><th class="num">Sent</th><th class="num">Settled</th><th class="num">Outside</th></tr></thead><tbody>
                                    @foreach($lines as $line)<tr><td>{{ $line->name }}</td><td class="num">{{ $qty($line->qty_base) }}</td><td class="num">{{ $qty($settledQty[$line->id] ?? 0) }}</td><td class="num">{{ $qty($lineOutside($line)) }}</td></tr>@endforeach
                                    </tbody></table>
                                    @if($dcReceipts->isNotEmpty())<p class="mt-2">{{ $grnLabel }}: {{ $dcReceipts->map(fn ($r) => $r->reference_no.($r->reversed_at ? ' (reversed)' : ''))->implode(', ') }}</p>@endif
                                    @if($dispatch->reversed_at)<p class="text-danger mt-1">Dispatch reversed: {{ $dispatch->reversal_reason }}</p>@endif
                                </div>

                                @if(!$dispatch->reversed_at && $dcOutside > 0)
                                <div class="jw-panel" id="dc-{{ $dispatch->id }}-receive" hidden>
                                    <div class="jw-panel-head">
                                        <h4>{{ $grnLabel }} against {{ $dispatch->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="dc-{{ $dispatch->id }}-receive">&times;</button>
                                    </div>
                                    @include('backend.operations.form_start',['formId'=>'receive-'.$dispatch->id,'action'=>'job-receive/'.$dispatch->id])
                                    <div class="ops-fields">@include('backend.operations.warehouse',['warehouseLabel'=>'Receiving warehouse','selectedWarehouse'=>$dispatch->warehouse_id])@include('backend.operations.date')<label>Quarantine warehouse<select name="rejected_warehouse_id"><option value="">Required for rejected material</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select>@if($warehouses->count() < 2)<small class="text-muted">Rejected material needs a second warehouse apart from the receiving one. Add one (e.g. Quarantine) under Settings › Branch / Warehouse.</small>@endif</label></div>
                                    <div class="ops-table-wrap"><table><thead><tr><th>Material / outside</th><th>Accepted</th><th>Rejected at receipt</th><th>Loss</th></tr></thead><tbody>
                                    @foreach($lines as $i=>$line)<tr><td>{{ $line->name }} · {{ $qty($lineOutside($line)) }}<input type="hidden" name="lines[{{ $i }}][dispatch_line_id]" value="{{ $line->id }}"></td><td><label>Accepted · {{ $line->name }}<input name="lines[{{ $i }}][accepted_qty]" type="number" min="0" max="{{ $lineOutside($line) }}" step="0.0001" value="{{ $lineOutside($line) }}" required></label></td><td><label>Rejected · {{ $line->name }}<input name="lines[{{ $i }}][rejected_qty]" type="number" min="0" step="0.0001" value="0" required></label></td><td><label>Loss · {{ $line->name }}<input name="lines[{{ $i }}][loss_qty]" type="number" min="0" step="0.0001" value="0" required></label></td></tr>@endforeach
                                    </tbody></table></div>
                                    @if($conversion && !$isRework)<label class="small d-block mt-2" title="Sec 143(1)(a): the processed goods stay at the job worker (still your stock) and you invoice the customer from that location, choosing the job worker's godown on the sales invoice."><input type="checkbox" name="direct_dispatch" value="1"> Goods go straight from the job worker to the customer</label><h3>Converted outputs</h3><div class="ops-table-wrap"><table data-lines><thead><tr><th>Product</th><th>Quantity / cost weight</th><th>Unit</th><th>Tracking</th><th></th></tr></thead><tbody>@include('backend.operations.stock_line',['prefix'=>'outputs[0]','withWeight'=>true,'defaultProduct'=>$outputDefault ? (int) $outputDefault : null])</tbody></table></div><button type="button" data-add-line>Add output</button>
                                    @elseif($conversion && $isRework)<section><p>Reworked material comes back as the same item, unless it was sent unprocessed and now comes back converted.</p><label><input type="checkbox" data-optional-lines> Comes back converted (e.g. rejected grey now dyed)</label><fieldset data-optional-group hidden disabled><h3>Converted outputs</h3><div class="ops-table-wrap"><table data-lines><thead><tr><th>Product</th><th>Quantity / cost weight</th><th>Unit</th><th>Tracking</th><th></th></tr></thead><tbody>@include('backend.operations.stock_line',['prefix'=>'outputs[0]','withWeight'=>true,'defaultProduct'=>$outputDefault ? (int) $outputDefault : null])</tbody></table></div><button type="button" data-add-line>Add output</button></fieldset></section>
                                    @elseif($isRework)<p>Rework comes back as the same item that was sent.</p>@endif
                                    <div class="mt-3"><button class="btn btn-sm btn-primary ops-primary font-weight-bold">Receive and reconcile</button> <button type="button" class="btn btn-sm btn-light border ml-1" data-jw-close="dc-{{ $dispatch->id }}-receive">Cancel</button></div></form>
                                </div>

                                <div class="jw-panel" id="dc-{{ $dispatch->id }}-return" hidden>
                                    <div class="jw-panel-head">
                                        <h4>Return material from {{ $dispatch->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="dc-{{ $dispatch->id }}-return">&times;</button>
                                    </div>
                                    @include('backend.operations.form_start',['formId'=>'return-'.$dispatch->id,'action'=>'job-return/'.$dispatch->id])
                                    <p class="text-muted small">The job worker sends material back unprocessed, as the same item. No loss check and no processing charge.</p>
                                    <div class="ops-fields">@include('backend.operations.warehouse',['warehouseLabel'=>'Return into warehouse','selectedWarehouse'=>$dispatch->warehouse_id])@include('backend.operations.date')</div>
                                    <div class="ops-table-wrap"><table><thead><tr><th>Material / outside</th><th>Returned unprocessed</th></tr></thead><tbody>
                                    @foreach($lines as $i=>$line)<tr><td>{{ $line->name }} · {{ $qty($lineOutside($line)) }}<input type="hidden" name="lines[{{ $i }}][dispatch_line_id]" value="{{ $line->id }}"></td><td><label>Returned · {{ $line->name }}<input name="lines[{{ $i }}][returned_qty]" type="number" min="0" max="{{ $lineOutside($line) }}" step="0.0001" value="0" required></label></td></tr>@endforeach
                                    </tbody></table></div>
                                    <div class="mt-3"><button class="btn btn-sm btn-primary ops-primary font-weight-bold">Return material</button> <button type="button" class="btn btn-sm btn-light border ml-1" data-jw-close="dc-{{ $dispatch->id }}-return">Cancel</button></div></form>
                                </div>
                                @endif

                                @if(!$dispatch->reversed_at && !$hasActiveReceipts)
                                <div class="jw-panel" id="dc-{{ $dispatch->id }}-reverse" hidden>
                                    <div class="jw-panel-head">
                                        <h4>Reverse {{ $dispatch->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="dc-{{ $dispatch->id }}-reverse">&times;</button>
                                    </div>
                                    @include('backend.operations.form_start',['formId'=>'dispatch-reverse-'.$dispatch->id,'action'=>'job-reverse-dispatch/'.$dispatch->id])
                                    <p class="text-muted small">Brings the material back into the warehouse it was sent from. Use it only when the DC was wrong.</p>
                                    <div class="ops-fields">@include('backend.operations.date')<label>Reason<input name="reason" required maxlength="500"></label></div>
                                    <div class="mt-2"><button type="submit" class="btn btn-sm btn-danger font-weight-bold">Post reversal</button> <button type="button" class="btn btn-sm btn-light border ml-1" data-jw-close="dc-{{ $dispatch->id }}-reverse">Cancel</button></div></form>
                                </div>
                                @endif
                            </td></tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="jw-empty-state">
                                        <div class="jw-empty-icon"><i class="fa fa-paper-plane-o"></i></div>
                                        <div class="jw-empty-title">No {{ $dcLabel }} yet</div>
                                        <p class="jw-empty-desc">Send raw material or grey goods to <strong>{{ $jobWorker->name ?? 'the job worker' }}</strong> under this order.</p>
                                        <button type="button" class="btn btn-sm btn-primary jw-toggle" data-jw-toggle="new-dc">
                                            <i class="fa fa-plus mr-1"></i> + New {{ $dcLabel }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ===================================================================== Pane 2: Job Work GRN --}}
        <div class="jw-card-section" id="pane-grn">
            {{-- Job Work GRN Register Table --}}
            <div class="jw-table-card">
                <div class="jw-table-scroll">
                    <table class="jw-register ops-table">
                        <thead>
                            <tr>
                                <th>{{ $grnLabel }}</th>
                                <th>Date</th>
                                <th>Against</th>
                                <th>Type</th>
                                <th class="num">Accepted</th>
                                <th class="num">Rejected</th>
                                <th class="num">Loss</th>
                                <th>Service bill</th>
                                <th class="jw-actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($receipts as $receipt)
                            @php
                                $rLines = $receiptLines->get($receipt->id, collect());
                                $kind = $receipt->receipt_kind ?? 'process';
                                $fromRework = ($dispatches->firstWhere('id', $receipt->dispatch_id)->purpose ?? 'process') === 'rework';
                                $kindLabel = $kind === 'material_return' ? 'Returned unprocessed' : ($fromRework ? 'Rework received' : 'Processed');
                                $reworkDcs = $dispatches->where('rework_of_receipt_id', $receipt->id);
                                $billable = !$receipt->reversed_at && !$receipt->service_purchase_id && $kind !== 'material_return';
                                // A booked bill is corrected by reversing it and booking again (job-rebill), never edited in place.
                                $billRow = $receipt->service_purchase_id ? ($billTotals ?? collect())->get($receipt->service_purchase_id) : null;
                                $editBill = $billRow && !$receipt->reversed_at;
                                $billLine = $billRow ? ($billLines ?? collect())->get($billRow->id) : null;
                                $billMode = $billRow ? (json_decode($billRow->attributes_json ?? '{}', true)['job_work_charge_mode'] ?? 'capitalise') : 'capitalise';
                                $rejectedLines = $rLines->where('rejected_qty', '>', 0)->values();
                                $cost = $receipt->details_json['processing_cost'] ?? null;
                            @endphp
                            <tr class="{{ $receipt->reversed_at ? 'jw-muted' : '' }}">
                                <td><strong class="jw-ref-code">{{ $receipt->reference_no }}</strong></td>
                                <td>{{ $receipt->business_date }}</td>
                                <td><span class="badge badge-light border">{{ $dcRefs[$receipt->dispatch_id] ?? '—' }}</span></td>
                                <td>{{ $kindLabel }}@if($receipt->reversed_at) · <em class="text-danger">reversed</em>@endif</td>
                                <td class="num font-weight-bold text-success">{{ $qty($rLines->sum('accepted_qty')) }}</td>
                                <td class="num font-weight-bold {{ $rLines->sum('rejected_qty') > 0 ? 'text-danger' : '' }}">{{ $qty($rLines->sum('rejected_qty')) }}</td>
                                <td class="num font-weight-bold {{ $rLines->sum('loss_qty') > 0 ? 'text-warning' : '' }}">{{ $qty($rLines->sum('loss_qty')) }}@if((float) ($receipt->details_json['loss_qty'] ?? 0) > 0) ({{ round((float) $receipt->details_json['loss_percent'], 2) }}%)@endif</td>
                                <td>
                                    @if($receipt->service_purchase_id)
                                        <span class="badge badge-success"><i class="fa fa-check mr-1"></i> {{ $billNumbers[$receipt->service_purchase_id] ?? ('#'.$receipt->service_purchase_id) }}</span>
                                    @elseif($kind === 'material_return')
                                        <span class="text-muted">—</span>
                                    @else
                                        <span class="jw-status-pill jw-status-at-job-worker"><span class="jw-status-dot"></span> To bill</span>
                                    @endif
                                </td>
                                <td class="jw-actions">
                                    <button type="button" class="btn btn-xs btn-light border jw-toggle" data-jw-toggle="grn-{{ $receipt->id }}-view">View</button>
                                    <a class="btn btn-xs btn-light border jw-action-link" href="{{ url('/operations/receipt/'.$receipt->id.'/print') }}" target="_blank" rel="noopener" title="Print the goods received note (quantities, no GST)"><i class="fa fa-print mr-1"></i> Print GRN</a>
                                    @if($billable)
                                        <button type="button" class="btn btn-xs btn-primary jw-toggle jw-main" data-jw-toggle="grn-{{ $receipt->id }}-bill">Service bill</button>
                                    @elseif($editBill)
                                        @if(config('compliance.enabled'))<a class="btn btn-xs btn-light border" href="{{ url('/compliance/documents/purchase/'.$billRow->id.'/print?format=a4') }}" target="_blank" rel="noopener" title="Print {{ $billRow->reference_no }} (A4)"><i class="dripicons-print"></i> Print bill</a>@endif
                                        <button type="button" class="btn btn-xs btn-light border jw-toggle" data-jw-toggle="grn-{{ $receipt->id }}-bill" title="Correct {{ $billRow->reference_no }}: it is reversed and booked again with your changes">Edit bill</button>
                                    @endif
                                    @if(!$receipt->reversed_at && $kind !== 'material_return')
                                        <button type="button" class="btn btn-xs btn-light border jw-toggle" data-jw-toggle="grn-{{ $receipt->id }}-rework">Return / Send for rework</button>
                                    @endif
                                    @if(!$receipt->reversed_at)
                                        <button type="button" class="btn btn-xs btn-outline-danger jw-toggle jw-danger" data-jw-toggle="grn-{{ $receipt->id }}-reverse">Reverse</button>
                                    @endif
                                </td>
                            </tr>
                            <tr class="jw-panel-row"><td colspan="9">
                                <div class="jw-panel" id="grn-{{ $receipt->id }}-view" hidden>
                                    <div class="jw-panel-head">
                                        <h4>Receipt Details &bull; {{ $receipt->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="grn-{{ $receipt->id }}-view">&times;</button>
                                    </div>
                                    <p>Received {{ $receipt->details_json['received_qty'] }} · Loss {{ $receipt->details_json['loss_qty'] }} ({{ $receipt->details_json['loss_percent'] }}%)@if(!empty($receipt->details_json['lots']['input']) || !empty($receipt->details_json['lots']['output'])) · Lots {{ implode(', ', $receipt->details_json['lots']['input'] ?? []) ?: '—' }} → {{ implode(', ', $receipt->details_json['lots']['output'] ?? []) ?: '—' }}@endif</p>
                                    <table><thead><tr><th>Material</th><th class="num">Accepted</th><th class="num">Rejected</th><th class="num">Loss</th></tr></thead><tbody>
                                    @foreach($rLines as $line)<tr><td>{{ $line->name }}</td><td class="num">{{ $qty($line->accepted_qty) }}</td><td class="num">{{ $qty($line->rejected_qty) }}</td><td class="num">{{ $qty($line->loss_qty) }}</td></tr>@endforeach
                                    </tbody></table>
                                    @if($receipt->output_movement_id && $receiptOutputs->has($receipt->output_movement_id))<p class="mt-2">Came back as: {{ $receiptOutputs[$receipt->output_movement_id]->map(fn ($o) => $o->name.' '.$qty($o->qty_base))->implode(', ') }}</p>@endif
                                    @if($cost)<p class="mt-1">Processing charge {{ $cost['purchase_no'] }}: ₹{{ number_format($cost['amount'], 2) }} added to cost (₹{{ number_format($cost['to_stock'], 2) }} to stock{{ '' }}@if($cost['to_cogs'] > 0), ₹{{ number_format($cost['to_cogs'], 2) }} to cost of goods already sold{{ '' }}@endif)@if($cost['per_unit']) · material ₹{{ number_format($cost['material_per_unit'], 2) }} + processing ₹{{ number_format($cost['per_unit'], 2) }} = ₹{{ number_format($cost['material_per_unit'] + $cost['per_unit'], 2) }} per unit{{ '' }}@endif</p>@endif
                                    @if($receipt->service_purchase_id)@php $bill = ($billTotals ?? collect())->get($receipt->service_purchase_id); @endphp<p>Job worker bill: {{ $cost['purchase_no'] ?? ($billNumbers[$receipt->service_purchase_id] ?? ('purchase #'.$receipt->service_purchase_id)) }}@if($bill) · ₹{{ number_format($bill->grand_total - $bill->total_tax, 2) }} + GST ₹{{ number_format($bill->total_tax, 2) }} = ₹{{ number_format($bill->grand_total, 2) }}@endif</p>@endif
                                    @if($reworkDcs->isNotEmpty())<p>Sent for rework: {{ $reworkDcs->map(fn ($d) => $d->reference_no.($d->reversed_at ? ' (reversed)' : ''))->implode(', ') }}</p>@endif
                                    @if($receipt->reversed_at)<p class="text-danger mt-1">Receipt reversed: {{ $receipt->reversal_reason }}</p>@endif
                                </div>

                                @if($billable || $editBill)
                                <div class="jw-panel" id="grn-{{ $receipt->id }}-bill" hidden>
                                    <div class="jw-panel-head">
                                        <h4>{{ $editBill ? 'Edit service bill '.$billRow->reference_no.' for ' : 'Service bill for ' }}{{ $receipt->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="grn-{{ $receipt->id }}-bill">&times;</button>
                                    </div>
                                    @include('backend.operations.form_start',['formId'=>'bill-'.$receipt->id,'action'=>($editBill ? 'job-rebill/' : 'job-bill/').$receipt->id])
                                    @if($editBill)<input type="hidden" name="bill_id" value="{{ $billRow->id }}"><p class="small mb-2">Saving reverses {{ $billRow->reference_no }} (kept in history with your reason) and books the corrected bill against this GRN.</p>@endif
                                    <div class="ops-fields">@include('backend.operations.date')<label>Processing charge item<select name="items[0][product_id]" required><option value="">Select service</option>@foreach(($serviceProducts ?? $products->where('type','service')) as $product)<option value="{{ $product->id }}" @selected((int) ($billLine->product_id ?? $route->service_product_id ?? $chargeDefault['product_id'] ?? 0) === $product->id)>{{ $product->name }}</option>@endforeach</select>@if(($serviceProducts ?? collect())->isEmpty())<small class="text-muted">No service item yet. <a href="{{ url('/products/create') }}">Create one</a> (type Service, with its GST rate and SAC code).</small>@endif</label><label>Quantity charged<input name="items[0][qty]" type="number" min="0.0001" step="0.0001" value="{{ $billLine ? (float) $billLine->qty : ((float) ($acceptedQty[$receipt->id] ?? 1) ?: 1) }}" required></label><label>Rate per unit (before GST)<input name="items[0][net_unit_cost]" type="number" min="0" step="0.0001" value="{{ $billLine ? (float) $billLine->net_unit_cost : (isset($route->service_rate) ? (float) $route->service_rate : ($chargeDefault['rate'] ?? '')) }}" required></label><label title="GST comes from the processing charge item's GST rate. Change it on the item to change it here.">GST<input data-bill-gst readonly tabindex="-1" placeholder="Pick item and rate"></label><label>Bill total (with GST)<input data-bill-total readonly tabindex="-1"></label><label title="Add to fabric cost: the charge raises the processed stock's value (default, as Ind AS 2 expects). Period expense: it goes straight to profit and loss.">Book the charge as<select name="charge_mode"><option value="capitalise">Part of fabric cost</option><option value="expense" @selected($billMode === 'expense')>Period expense</option></select></label>@foreach($rLines->unique('product_id') as $line)<label title="The name the job worker writes for this product on their bill. Saved as their alternate name and printed in the bill note.">{{ $line->name }} on their bill<input name="their_names[{{ $line->product_id }}]" maxlength="150" value="{{ ($workerAliases ?? collect())->get($line->product_id) }}" placeholder="Name used by {{ $jobWorker->name ?? 'the job worker' }}" autocomplete="off"></label>@endforeach</div>
                                    <p class="small mt-1 mb-0 text-muted">GST rate comes from the processing charge item. To change it, open the item in <a href="{{ url('/products') }}" target="_blank" rel="noopener">Products</a> and set its GST rate.</p>
                                    <p class="small mt-2 mb-0" data-bill-preview data-url="{{ url('/commercial/purchase/preview') }}" data-supplier="{{ $jobWorker->id ?? '' }}" aria-live="polite"></p>
                                    @if($editBill)<div class="ops-fields mt-2"><label>Reason for the correction<input name="reason" required minlength="3" maxlength="500" placeholder="e.g. Wrong rate: agreed ₹22 per metre"></label></div>@endif
                                    <div class="mt-3"><button class="btn btn-sm btn-primary ops-primary font-weight-bold">{{ $editBill ? 'Save corrected bill' : 'Book job worker bill' }}</button> <button type="button" class="btn btn-sm btn-light border ml-1" data-jw-close="grn-{{ $receipt->id }}-bill">Cancel</button></div></form>
                                </div>
                                @endif

                                @if(!$receipt->reversed_at && $kind !== 'material_return')
                                <div class="jw-panel" id="grn-{{ $receipt->id }}-rework" hidden>
                                    <div class="jw-panel-head">
                                        <h4>Return {{ $receipt->reference_no }} to {{ $jobWorker->name ?? 'the job worker' }} for rework</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="grn-{{ $receipt->id }}-rework">&times;</button>
                                    </div>
                                    @include('backend.operations.form_start',['formId'=>'rework-'.$receipt->id,'action'=>'job-rework/'.$receipt->id])
                                    <p class="text-muted small">Creates a new {{ $dcLabel }} under this order, referencing {{ $receipt->reference_no }}. When it comes back, receive it on that DC.</p>
                                    <div class="ops-fields">@include('backend.operations.warehouse',['warehouseLabel'=>'Send from warehouse','selectedWarehouse'=>$receipt->details_json['rejected_warehouse_id'] ?? $receipt->warehouse_id])@include('backend.operations.date')<label>Vehicle no.<input name="transport[vehicle_number]" maxlength="30"></label><label>Rework note<input name="transport[notes]" maxlength="500" placeholder="What to correct: shade, stains, finish"></label></div>
                                    <div class="ops-table-wrap"><table data-lines><thead><tr><th>Product</th><th>Quantity</th><th>Unit</th><th>Tracking</th><th></th></tr></thead><tbody>
                                    @forelse($rejectedLines as $i=>$line)
                                        @include('backend.operations.stock_line',['prefix'=>'lines['.$i.']','defaultProduct'=>(int) $line->product_id,'defaultQty'=>(float) $line->rejected_qty,'defaultUnit'=>(int) optional($products->firstWhere('id', (int) $line->product_id))->unit_id])
                                    @empty
                                        @php $first = $rLines->first(); @endphp
                                        @include('backend.operations.stock_line',['prefix'=>'lines[0]','defaultProduct'=>$first ? (int) $first->product_id : null,'defaultUnit'=>$first ? (int) optional($products->firstWhere('id', (int) $first->product_id))->unit_id : null])
                                    @endforelse
                                    </tbody></table></div>
                                    <div class="mt-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line><i class="fa fa-plus mr-1"></i> Add material</button> <button class="btn btn-sm btn-primary ops-primary font-weight-bold ml-2">Send for rework</button> <button type="button" class="btn btn-sm btn-light border ml-1" data-jw-close="grn-{{ $receipt->id }}-rework">Cancel</button></div></form>
                                </div>
                                @endif

                                @if(!$receipt->reversed_at)
                                <div class="jw-panel" id="grn-{{ $receipt->id }}-reverse" hidden>
                                    <div class="jw-panel-head">
                                        <h4>Reverse {{ $receipt->reference_no }}</h4>
                                        <button type="button" class="jw-form-close" data-jw-close="grn-{{ $receipt->id }}-reverse">&times;</button>
                                    </div>
                                    @include('backend.operations.form_start',['formId'=>'receipt-reverse-'.$receipt->id,'action'=>'job-reverse-receipt/'.$receipt->id])
                                    <p class="text-muted small">Puts the material back outside with the job worker. Reverse its service bill and any rework DC first.</p>
                                    <div class="ops-fields">@include('backend.operations.date')<label>Reason<input name="reason" required maxlength="500"></label></div>
                                    <div class="mt-2"><button type="submit" class="btn btn-sm btn-danger font-weight-bold">Post reversal</button> <button type="button" class="btn btn-sm btn-light border ml-1" data-jw-close="grn-{{ $receipt->id }}-reverse">Cancel</button></div></form>
                                </div>
                                @endif
                            </td></tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <div class="jw-empty-state">
                                        <div class="jw-empty-icon"><i class="fa fa-inbox"></i></div>
                                        <div class="jw-empty-title">Nothing received yet</div>
                                        <p class="jw-empty-desc">Use <strong>Receive {{ $grnLabel }}</strong> on a {{ $dcLabel }} when material comes back from <strong>{{ $jobWorker->name ?? 'the job worker' }}</strong>.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
