@extends('backend.operations.layout')
@section('title', ['manufacturing'=>'BOM & Production','job-work'=>'Job Work','profiles'=>'Business Profile','projects'=>'Sites & Projects','stock'=>'Stock Tools'][$area])

@section('operations_content')

@if($area === 'job-work')
    @php
        $jwDc = \App\Services\Industry\IndustryProfileService::label('dispatch', 'Material DC');
        $jwGrn = \App\Services\Industry\IndustryProfileService::label('receipt', 'Job Work GRN');
    @endphp
    <div class="ops-hub-view jw-hub-view">
    @include('backend.partials.job-work-direction', ['direction' => 'out'])
    <!-- KPI Strip -->
    <div class="ops-kpi-strip">
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Recent Orders</span>
                <span class="ops-kpi-value">{{ count($records) }}</span>
            </div>
            <div class="ops-kpi-icon kpi-indigo"><i class="dripicons-clipboard"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Material Outside</span>
                <span class="ops-kpi-value">{{ count($pending) }} items</span>
            </div>
            <div class="ops-kpi-icon kpi-amber"><i class="dripicons-export"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Shrinkage Receipts</span>
                <span class="ops-kpi-value">{{ count($shrinkage) }}</span>
            </div>
            <div class="ops-kpi-icon kpi-rose"><i class="dripicons-graph-bar"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Active Processes</span>
                <span class="ops-kpi-value">{{ count($processes) }}</span>
            </div>
            <div class="ops-kpi-icon kpi-cyan"><i class="dripicons-gear"></i></div>
        </div>
    </div>

    <!-- Single-Screen Two-Column Command Grid -->
    <div class="ops-grid-2col">
        <!-- Left: Tabbed Operational Registers -->
        <div class="ops-subcard">
            <div class="ops-subcard-header">
                <ul class="nav nav-tabs ops-tab-strip" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#tab-orders" role="tab">
                            <i class="dripicons-list mr-1"></i> Orders ({{ count($records) }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tab-dc" role="tab">
                            <i class="dripicons-export mr-1"></i> {{ $jwDc }} ({{ count($dispatchRegister) }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tab-grn" role="tab">
                            <i class="dripicons-download mr-1"></i> {{ $jwGrn }} ({{ count($receiptRegister) }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tab-outside" role="tab">
                            <i class="dripicons-export mr-1"></i> Material outside ({{ count($pending) }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tab-shrinkage" role="tab">
                            <i class="dripicons-warning mr-1"></i> Shrinkage Log ({{ count($shrinkage) }})
                        </a>
                    </li>
                </ul>
                {{-- Sidebar entries open a register directly: ?tab=dc|grn|outside|shrinkage --}}
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var tab = new URLSearchParams(window.location.search).get('tab');
                    if (tab && window.jQuery) jQuery('.ops-tab-strip a[href="#tab-' + tab + '"]').tab('show');
                });
                </script>
            </div>
            <div class="tab-content flex-fill d-flex flex-column min-h-0 u-ov-hidden">
                <!-- Tab: Orders -->
                <div class="tab-pane fade show active h-100" id="tab-orders" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Job Work Order</th>
                                    <th>Job worker</th>
                                    <th>Process</th>
                                    <th>Date</th>
                                    <th>{{ $jwDc }} / GRN</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($records as $record)
                                    <tr>
                                        <td><strong>{{ $record->reference_no ?? $record->title }}</strong></td>
                                        <td>{{ $orderInfo[$record->id]->worker ?? '' }}</td>
                                        <td><span class="badge badge-light border text-muted px-2 py-1">{{ $orderInfo[$record->id]->process ?? ($record->details_json['process']['name'] ?? '') }}</span></td>
                                        <td>{{ $record->business_date ?? $record->start_date }}</td>
                                        <td><span class="badge badge-light border font-weight-bold px-2 py-1">{{ $orderCounts['dc'][$record->id] ?? 0 }} / {{ $orderCounts['grn'][$record->id] ?? 0 }}</span></td>
                                        <td class="text-right">
                                            <a class="btn btn-outline-primary btn-sm py-0 px-2 btn-jw-open" href="{{ url('/operations/job-work/'.$record->id) }}">
                                                Open &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="dripicons-document-edit d-block mb-1 font-24"></i>
                                            No job work orders yet. Create your first order on the right.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Material DC register -->
                <div class="tab-pane fade h-100" id="tab-dc" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead><tr><th>{{ $jwDc }}</th><th>Date</th><th>Order</th><th>Job worker</th><th>Type</th><th>Sent</th><th class="text-right">Action</th></tr></thead>
                            <tbody>
                                @forelse($dispatchRegister as $dc)
                                    <tr class="{{ $dc->reversed_at ? 'text-muted' : '' }}">
                                        <td><strong>{{ $dc->reference_no }}</strong>@if($dc->reversed_at) <span class="badge badge-light border">reversed</span>@endif</td>
                                        <td>{{ $dc->business_date }}</td>
                                        <td>{{ $orderInfo[$dc->job_work_order_id]->reference_no ?? '' }}</td>
                                        <td>{{ $orderInfo[$dc->job_work_order_id]->worker ?? '' }}</td>
                                        <td><span class="badge badge-light border">{{ ($dc->purpose ?? 'process') === 'rework' ? 'Rework' : 'Process' }}</span></td>
                                        <td><strong>{{ (float) ($dispatchSent[$dc->id] ?? 0) }}</strong></td>
                                        <td class="text-right"><a class="btn btn-outline-primary btn-sm py-0 px-2 btn-jw-open" href="{{ url('/operations/job-work/'.$dc->job_work_order_id) }}">Open order &rarr;</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">No {{ $jwDc }} yet. Open an order to send material.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Job Work GRN register -->
                <div class="tab-pane fade h-100" id="tab-grn" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead><tr><th>{{ $jwGrn }}</th><th>Date</th><th>Against</th><th>Type</th><th>Received</th><th>Loss</th><th class="text-right">Action</th></tr></thead>
                            <tbody>
                                @forelse($receiptRegister as $grn)
                                    @php $grnDc = $receiptDc[$grn->dispatch_id] ?? null; @endphp
                                    <tr class="{{ $grn->reversed_at ? 'text-muted' : '' }}">
                                        <td><strong>{{ $grn->reference_no }}</strong>@if($grn->reversed_at) <span class="badge badge-light border">reversed</span>@endif</td>
                                        <td>{{ $grn->business_date }}</td>
                                        <td>{{ $grnDc->reference_no ?? '' }}</td>
                                        <td><span class="badge badge-light border">{{ ($grn->receipt_kind ?? 'process') === 'material_return' ? 'Returned unprocessed' : 'Processed' }}</span></td>
                                        <td><strong class="text-success">{{ $grn->details_json['received_qty'] ?? 0 }}</strong></td>
                                        <td>@if(($grn->details_json['loss_qty'] ?? 0) > 0)<span class="text-danger font-weight-bold">{{ $grn->details_json['loss_qty'] }}</span>@else<span class="text-muted">0</span>@endif</td>
                                        <td class="text-right">@if($grnDc)<a class="btn btn-outline-primary btn-sm py-0 px-2 btn-jw-open" href="{{ url('/operations/job-work/'.$grnDc->job_work_order_id) }}">Open order &rarr;</a>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">Nothing received from job workers yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Material Outside -->
                <div class="tab-pane fade h-100" id="tab-outside" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Product</th>
                                    <th>Pending Qty</th>
                                    <th>Inventory Value</th>
                                    <th title="Section 143: inputs must come back within 1 year (capital goods 3 years) of the Material DC, or the DC becomes a deemed supply with GST and interest.">Sec 143 return by</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($pending as $row)
                                    <tr>
                                        <td><strong>{{ $row['reference_no'] }}</strong></td>
                                        <td>{{ $products->firstWhere('id', $row['product_id'])?->name }}</td>
                                        <td><span class="badge badge-warning px-2 py-1 font-weight-bold">{{ $row['pending_qty'] }}</span></td>
                                        <td><strong>{{ $row['pending_value'] }}</strong></td>
                                        <td>@isset($row['return_by'])<span class="badge {{ ['overdue' => 'badge-danger', 'due_soon' => 'badge-warning', 'ok' => 'badge-light'][$row['status']] }}">{{ \Carbon\Carbon::parse($row['return_by'])->format('d-m-Y') }} · {{ $row['status'] === 'overdue' ? abs($row['days_left']).' days overdue' : $row['days_left'].' days left' }}</span>@endisset</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="dripicons-checkmark text-success d-block mb-1 font-24"></i>
                                            No material pending outside with job workers.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Shrinkage -->
                <div class="tab-pane fade h-100" id="tab-shrinkage" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ \App\Services\Industry\IndustryProfileService::label('receipt', 'Receipt') }}</th>
                                    <th>Date</th>
                                    <th>Received</th>
                                    <th>Loss</th>
                                    <th>Loss %</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($shrinkage as $receipt)
                                    <tr>
                                        <td><strong>{{ $receipt->reference_no }}</strong></td>
                                        <td>{{ $receipt->business_date }}</td>
                                        <td><strong>{{ $receipt->details_json['received_qty'] ?? 0 }}</strong></td>
                                        <td><span class="text-danger font-weight-bold">{{ $receipt->details_json['loss_qty'] ?? 0 }}</span></td>
                                        <td><span class="badge badge-danger px-2 py-1 font-weight-bold">{{ $receipt->details_json['loss_percent'] ?? 0 }}%</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="dripicons-info text-info d-block mb-1 font-24"></i>
                                            No active shrinkage records yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Action & Creation Drawer -->
        <div class="ops-subcard ops-action-subcard">
            <div class="ops-subcard-header">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <span class="font-weight-bold text-dark font-13"><i class="fa fa-pencil-square-o text-primary mr-1"></i> Quick Action</span>
                    <ul class="nav nav-pills ops-quick-pills" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="pill" href="#act-order">New Order</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="pill" href="#act-process">Process Policy</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="ops-subcard-body">
                <div class="tab-content">
                    <!-- Form: Create Order -->
                    <div class="tab-pane fade show active" id="act-order">
                        @include('backend.operations.form_start', ['formId'=>'order-form', 'action'=>'job-order'])
                        <div class="ops-form-compact">
                            <div class="form-group">
                                <label>Job Worker (Supplier) *</label>
                                <select name="job_worker_party_id" required>
                                    <option value="">Select job worker</option>
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Process Policy *</label>
                                <select name="process_type_id" required>
                                    <option value="">Select process</option>
                                    @foreach($processes as $process)
                                        <option value="{{ $process->id }}">{{ $process->name }} &bull; max loss {{ $process->max_loss_percent }}%</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>Business Date *</label>
                                    <input type="date" name="business_date" value="{{ $date }}" required>
                                </div>
                                <div class="col-6 form-group">
                                    <label>Expected Return</label>
                                    <input type="date" name="expected_return_date">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" placeholder="Order instructions or specifications..." maxlength="5000" rows="3"></textarea>
                            </div>
                            <button type="submit" class="btn-ops-submit mt-2">
                                <i class="fa fa-plus-circle mr-1"></i> Create Job Work Order
                            </button>
                        </div>
                        </form>
                    </div>

                    <!-- Form: Configure Process -->
                    <div class="tab-pane fade" id="act-process">
                        @include('backend.operations.form_start', ['formId'=>'process-form', 'action'=>'configure/process'])
                        <div class="ops-form-compact">
                            <div class="form-group">
                                <label for="process-load">Edit an existing process</label>
                                <select id="process-load">
                                    <option value="">— New process —</option>
                                    @foreach($processes as $process)
                                        <option value="{{ $process->code }}" data-process='@json($process)'>{{ $process->name }} ({{ $process->code }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Process Code *</label>
                                <input name="code" pattern="[A-Z0-9_-]+" maxlength="60" required placeholder="e.g. DYEING-01">
                            </div>
                            <div class="form-group">
                                <label>Process Name *</label>
                                <input name="name" maxlength="100" required placeholder="e.g. Fabric Dyeing & Softening">
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>Max Loss (%) *</label>
                                    <input name="max_loss_percent" type="number" step="0.0001" min="0" max="100" value="0" required>
                                </div>
                                <div class="col-6 form-group">
                                    <label>Yield Basis *</label>
                                    <select name="quantity_basis">
                                        <option value="base_qty">Base quantity</option>
                                        <option value="volume_cbm">Volume (CBM)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Output Behavior *</label>
                                <select name="conversion">
                                    <option value="0">Return same material (Shrinkage tracking)</option>
                                    <option value="1">Convert to other products (Recipe outputs)</option>
                                </select>
                            </div>
                            <p class="text-muted mb-1 u-fs-11_5px">Defaults pre-fill the receipt and the job worker's bill; they can be changed on each document and updated any time.</p>
                            <div class="form-group">
                                <label>Default output item</label>
                                <select name="output_product_id">
                                    <option value="">— Same as sent / choose on receipt —</option>
                                    @foreach($products->where('type', 'standard') as $product)<option value="{{ $product->id }}">{{ $product->name }} · {{ $product->code }}</option>@endforeach
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-7 form-group">
                                    <label>Processing charge item</label>
                                    <select name="service_product_id">
                                        <option value="">— Choose on the bill —</option>
                                        @foreach($products->where('type', 'service') as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-5 form-group">
                                    <label>Rate per unit (₹)</label>
                                    <input name="service_rate" type="number" step="0.0001" min="0" placeholder="e.g. 15">
                                </div>
                            </div>
                            <button type="submit" class="btn-ops-submit mt-2">
                                <i class="fa fa-save mr-1"></i> Save Process Policy
                            </button>
                            <script>
                            document.getElementById('process-load').addEventListener('change', function () {
                                var option = this.selectedOptions[0], data = option && option.dataset.process ? JSON.parse(option.dataset.process) : {};
                                var form = document.getElementById('process-form');
                                ['code', 'name', 'max_loss_percent', 'quantity_basis', 'output_product_id', 'service_product_id', 'service_rate'].forEach(function (field) {
                                    var input = form.querySelector('[name="' + field + '"]'); if (input) input.value = data[field] == null ? '' : data[field];
                                });
                                form.querySelector('[name="conversion"]').value = data.conversion ? '1' : '0';
                            });
                            </script>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

@elseif($area === 'manufacturing')
    <!-- Manufacturing Command Center -->
    <div class="ops-kpi-strip">
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Production Orders</span>
                <span class="ops-kpi-value">{{ count($records) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-primary"><i class="fa fa-industry"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Published BOMs</span>
                <span class="ops-kpi-value">{{ count($boms) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-success"><i class="dripicons-view-thumb"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Legacy Records</span>
                <span class="ops-kpi-value">{{ count($legacyProductions) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-secondary"><i class="dripicons-archive"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Standard Items</span>
                <span class="ops-kpi-value">{{ $products->where('type','standard')->count() }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-info"><i class="dripicons-box"></i></div>
        </div>
    </div>

    <div class="ops-grid-2col">
        <!-- Left: Production & BOM Registers -->
        <div class="ops-subcard">
            <div class="ops-subcard-header">
                <ul class="nav nav-tabs ops-tab-strip" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#tab-prod-orders" role="tab">
                            <i class="fa fa-cogs mr-1"></i> Productions ({{ count($records) }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tab-bom-versions" role="tab">
                            <i class="fa fa-cubes mr-1"></i> BOM Versions ({{ count($boms) }})
                        </a>
                    </li>
                    @if($legacyProductions->isNotEmpty())
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tab-legacy-prod" role="tab">
                            <i class="fa fa-history mr-1"></i> Legacy ({{ count($legacyProductions) }})
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
            <div class="tab-content flex-fill d-flex flex-column min-h-0 u-ov-hidden">
                <!-- Tab: Production Orders -->
                <div class="tab-pane fade show active h-100" id="tab-prod-orders" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th>Planned / Done</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($records as $record)
                                    <tr>
                                        <td><strong>{{ $record->reference_no ?? $record->title }}</strong></td>
                                        <td><span class="badge badge-secondary px-2 py-1">{{ $record->status }}</span></td>
                                        <td>{{ $record->planned_qty }} / {{ $record->completed_qty }}</td>
                                        <td class="text-right">
                                            <a class="btn btn-outline-primary btn-sm py-0 px-2" href="{{ url('/operations/production/'.$record->id) }}">
                                                Open &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No production orders yet. Plan one on the right.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: BOM Versions -->
                <div class="tab-pane fade h-100" id="tab-bom-versions" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Ver</th>
                                    <th>Product &bull; Output</th>
                                    <th>Validity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($boms as $bom)
                                    <tr>
                                        <td><strong>{{ $bom->code }}</strong></td>
                                        <td>v{{ $bom->version }}</td>
                                        <td>{{ $products->firstWhere('id', $bom->product_id)?->name }} &bull; {{ $bom->output_qty }}</td>
                                        <td>{{ $bom->effective_from }} &ndash; {{ $bom->effective_to ?? 'Open' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Create a BOM version on the right to start.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($legacyProductions->isNotEmpty())
                <div class="tab-pane fade h-100" id="tab-legacy-prod" role="tabpanel">
                    <div class="ops-table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($legacyProductions as $legacy)
                                    <tr>
                                        <td>{{ $legacy->reference_no }}</td>
                                        <td>{{ $legacy->created_at }}</td>
                                        <td><span class="badge badge-info px-2 py-1">{{ $legacy->status }}</span></td>
                                        <td>{{ $legacy->total_cost }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Right: Actions (Plan Production / Create BOM) -->
        <div class="ops-subcard ops-action-subcard">
            <div class="ops-subcard-header">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <span class="font-weight-bold text-dark font-13"><i class="fa fa-cogs text-primary mr-1"></i> Production Actions</span>
                    <ul class="nav nav-pills ops-quick-pills" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="pill" href="#act-plan">Plan Order</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="pill" href="#act-bom">+ New BOM</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="ops-subcard-body">
                <div class="tab-content">
                    <!-- Tab: Plan Production -->
                    <div class="tab-pane fade show active" id="act-plan">
                        @include('backend.operations.form_start', ['formId'=>'plan-form', 'action'=>'production-plan'])
                        <div class="ops-form-compact">
                            <div class="form-group">
                                <label>BOM Version *</label>
                                <select name="bom_id" required>
                                    <option value="">Select BOM</option>
                                    @foreach($boms as $bom)
                                        <option value="{{ $bom->id }}">{{ $bom->code }} &bull; v{{ $bom->version }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>{{ \App\Services\Industry\IndustryProfileService::label('warehouse', 'Warehouse') }} *</label>
                                <select name="warehouse_id" required>
                                    <option value="">Select warehouse</option>
                                    @foreach($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>Planned Qty *</label>
                                    <input name="planned_qty" type="number" step="0.0001" min="0.0001" required placeholder="0.00">
                                </div>
                                <div class="col-6 form-group">
                                    <label>Business Date *</label>
                                    <input type="date" name="business_date" value="{{ $date }}" required>
                                </div>
                            </div>
                            <button type="submit" class="btn-ops-submit mt-2">
                                <i class="fa fa-play mr-1"></i> Plan Production Run
                            </button>
                        </div>
                        </form>
                    </div>

                    <!-- Tab: Create BOM Version -->
                    <div class="tab-pane fade" id="act-bom">
                        @include('backend.operations.form_start', ['formId'=>'bom-form', 'action'=>'bom'])
                        <div class="ops-form-compact">
                            <div class="form-group">
                                <label>Output Product *</label>
                                <select name="product_id" required>
                                    <option value="">Select product</option>
                                    @foreach($products->where('type','standard') as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>BOM Code *</label>
                                    <input name="code" maxlength="60" pattern="[A-Z0-9_-]+" required placeholder="e.g. RECIPE-01">
                                </div>
                                <div class="col-6 form-group">
                                    <label>Output Qty *</label>
                                    <input name="output_qty" type="number" min="0.0001" step="0.0001" value="1" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>Output Unit *</label>
                                    <select name="output_uom_id" required>
                                        @foreach($units as $unit)
                                            <option value="{{ $unit->id }}">{{ $unit->unit_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6 form-group">
                                    <label>Effective Date *</label>
                                    <input name="effective_from" type="date" value="{{ $date }}" required>
                                </div>
                            </div>

                            <label class="mt-2 font-weight-bold">Components &bull; Ingredients</label>
                            <div class="border rounded p-2 mb-2 bg-light">
                                <table data-lines class="w-100 font-12">
                                    <tbody>
                                        <tr data-line>
                                            <td class="pr-1 pb-1">
                                                <select name="lines[0][component_product_id]" required class="form-control form-control-sm">
                                                    <option value="">Select ingredient</option>
                                                    @foreach($products->where('type','standard') as $product)
                                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="pr-1 pb-1 u-w-70px">
                                                <input name="lines[0][qty]" type="number" step="0.0001" min="0.0001" required placeholder="Qty" class="form-control form-control-sm">
                                            </td>
                                            <td class="pr-1 pb-1 u-w-80px">
                                                <select name="lines[0][uom_id]" required class="form-control form-control-sm">
                                                    @foreach($units as $unit)
                                                        <option value="{{ $unit->id }}">{{ $unit->unit_name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="pb-1 u-w-45px">
                                                <input name="lines[0][scrap_percent]" type="number" step="0.0001" min="0" max="100" value="0" placeholder="Scrap %" class="form-control form-control-sm">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <button type="button" data-add-line class="btn btn-outline-secondary btn-sm py-0 px-2 mt-1 font-11">+ Add Line</button>
                            </div>
                            <input type="hidden" name="business_date" value="{{ $date }}">
                            <button type="submit" class="btn-ops-submit">
                                <i class="fa fa-check mr-1"></i> Publish BOM Version
                            </button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

@elseif($area === 'projects')
    <!-- Projects Single-Screen Command Grid -->
    <div class="ops-kpi-strip">
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Total Projects</span>
                <span class="ops-kpi-value">{{ count($records) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-primary"><i class="dripicons-briefcase"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Customers</span>
                <span class="ops-kpi-value">{{ count($customers) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-success"><i class="dripicons-user"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">System Templates</span>
                <span class="ops-kpi-value">{{ count($templates ?? []) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-warning"><i class="dripicons-stack"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Current Profile</span>
                <span class="ops-kpi-value">{{ strtoupper($profile['profile'] ?? 'SOLAR') }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-info"><i class="dripicons-broadcast"></i></div>
        </div>
    </div>

    <div class="ops-grid-2col">
        <!-- Left: Projects Register -->
        <div class="ops-subcard">
            <div class="ops-subcard-header">
                <span class="font-weight-bold font-13 text-dark"><i class="dripicons-list mr-1"></i> Sites &amp; Projects Register</span>
            </div>
            <div class="ops-table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Project / Site</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($records as $record)
                            <tr>
                                <td><strong>{{ $record->title }}</strong></td>
                                <td><span class="badge badge-secondary px-2 py-1">{{ $record->status }}</span></td>
                                <td>{{ $record->start_date }}</td>
                                <td class="text-right">
                                    <a class="btn btn-outline-primary btn-sm py-0 px-2" href="{{ url('/operations/project/'.$record->id) }}">
                                        Open &rarr;
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No projects registered yet. Create one on the right.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Create Project Form -->
        <div class="ops-subcard ops-action-subcard">
            <div class="ops-subcard-header">
                <span class="font-weight-bold text-dark font-13"><i class="fa fa-plus-circle text-primary mr-1"></i> Create Site / Project</span>
            </div>
            <div class="ops-subcard-body">
                @include('backend.operations.form_start', ['formId'=>'project-form', 'action'=>'project'])
                <div class="ops-form-compact">
                    <div class="form-group">
                        <label>Project Name *</label>
                        <input name="title" maxlength="191" required placeholder="e.g. 50kW Commercial Rooftop Solar">
                    </div>
                    <div class="form-group">
                        <label>Customer *</label>
                        <select name="client_id" required>
                            <option value="">Select customer</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Site Address *</label>
                        <input name="site_address" maxlength="2000" required placeholder="Full physical installation location">
                    </div>
                    <div class="row">
                        <div class="col-6 form-group">
                            <label>System Size (kW) *</label>
                            <input name="system_kw" type="number" min="0.001" step="0.001" required placeholder="0.00">
                        </div>
                        <div class="col-6 form-group">
                            <label>Budget (USD)</label>
                            <input name="budget" type="number" min="0" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Business Date *</label>
                        <input type="date" name="business_date" value="{{ $date }}" required>
                    </div>
                    <button type="submit" class="btn-ops-submit mt-2">
                        <i class="fa fa-check mr-1"></i> Register Project Site
                    </button>
                </div>
                </form>
            </div>
        </div>
    </div>

@elseif($area === 'stock')
    <!-- Stock Tools Single-Screen Grid -->
    <div class="ops-kpi-strip">
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Batch Balances</span>
                <span class="ops-kpi-value">{{ count($batchBalances) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-primary"><i class="dripicons-box"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Tracked Warehouses</span>
                <span class="ops-kpi-value">{{ count($warehouses) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-success"><i class="dripicons-home"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Units of Measure</span>
                <span class="ops-kpi-value">{{ count($units) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-info"><i class="dripicons-scale"></i></div>
        </div>
        <div class="ops-kpi-box">
            <div class="ops-kpi-info">
                <span class="ops-kpi-title">Standard Products</span>
                <span class="ops-kpi-value">{{ count($products) }}</span>
            </div>
            <div class="ops-kpi-icon bg-light text-warning"><i class="dripicons-tags"></i></div>
        </div>
    </div>

    <div class="ops-grid-2col">
        <!-- Left: Batch Expiry & MRP List -->
        <div class="ops-subcard">
            <div class="ops-subcard-header">
                <span class="font-weight-bold font-13 text-dark"><i class="dripicons-view-list mr-1"></i> Batch Expiry &amp; MRP Inventory</span>
            </div>
            <div class="ops-table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Godown</th>
                            <th>Batch</th>
                            <th>Expiry</th>
                            <th>MRP</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($batchBalances as $balance)
                            <tr>
                                <td><strong>{{ $balance->product }}</strong></td>
                                <td>{{ $balance->warehouse }}</td>
                                <td>{{ $balance->batch_no }}</td>
                                <td>{{ $balance->expired_date }}</td>
                                <td>{{ $balance->mrp }}</td>
                                <td><span class="badge badge-primary px-2 py-1">{{ $balance->qty }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No batch balances recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Disposal & Schemes -->
        <div class="ops-subcard ops-action-subcard">
            <div class="ops-subcard-header">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <span class="font-weight-bold text-dark font-13"><i class="fa fa-wrench text-primary mr-1"></i> Stock Tools</span>
                    <ul class="nav nav-pills ops-quick-pills" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="pill" href="#act-writeoff">Expired Disposal</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="pill" href="#act-scheme">Qty Scheme</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="ops-subcard-body">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="act-writeoff">
                        @include('backend.operations.form_start', ['formId'=>'expiry-form', 'action'=>'expiry-writeoff'])
                        <div class="ops-form-compact">
                            <div class="form-group">
                                <label>{{ \App\Services\Industry\IndustryProfileService::label('warehouse', 'Warehouse') }} *</label>
                                <select name="warehouse_id" required>
                                    @foreach($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Business Date *</label>
                                <input type="date" name="business_date" value="{{ $date }}" required>
                            </div>
                            <div class="form-group">
                                <label>Disposal Reason *</label>
                                <input name="reason" maxlength="500" required placeholder="e.g. Expired batch write-off">
                            </div>
                            <label class="font-weight-bold">Batch Item</label>
                            <div class="border rounded p-2 mb-2 bg-light">
                                @include('backend.operations.stock_line', ['prefix'=>'lines[0]'])
                            </div>
                            <button type="submit" class="btn-ops-submit">
                                <i class="fa fa-trash mr-1"></i> Post Batch Disposal
                            </button>
                        </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="act-scheme">
                        @include('backend.operations.form_start', ['formId'=>'scheme-form', 'action'=>'configure/scheme'])
                        <div class="ops-form-compact">
                            <div class="form-group">
                                <label>Product *</label>
                                <select name="product_id" required>
                                    @foreach($products->where('type','standard') as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Scheme Name *</label>
                                <input name="name" required maxlength="100" placeholder="e.g. 10 + 1 Free">
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>Buy Qty *</label>
                                    <input name="buy_qty" type="number" min="0.0001" step="0.0001" value="10" required>
                                </div>
                                <div class="col-6 form-group">
                                    <label>Free Qty *</label>
                                    <input name="free_qty" type="number" min="0.0001" step="0.0001" value="1" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 form-group">
                                    <label>Valid From *</label>
                                    <input name="valid_from" type="date" value="{{ $date }}" required>
                                </div>
                                <div class="col-6 form-group">
                                    <label>Valid To *</label>
                                    <input name="valid_to" type="date" required>
                                </div>
                            </div>
                            <button type="submit" class="btn-ops-submit mt-2">
                                <i class="fa fa-save mr-1"></i> Save Scheme
                            </button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

@elseif($area === 'profiles')
    <!-- Business Profiles Single-Screen Grid -->
    <div class="ops-grid-2col">
        <!-- Left: Profile Selector Grid -->
        <div class="ops-subcard">
            <div class="ops-subcard-header">
                <span class="font-weight-bold font-13 text-dark"><i class="dripicons-gear mr-1"></i> Industry Business Profiles</span>
                <span class="badge badge-primary px-2 py-1 font-11">Current: {{ \App\Services\Platform\CapabilityCatalog::PROFILES[$profile['profile']][0] ?? $profile['profile'] }}@if(!empty($profile['subtype'])) &bull; {{ \App\Services\Industry\IndustryCatalog::subtypeLabel($profile['subtype']) }}@endif</span>
            </div>
            <div class="ops-subcard-body">
                <div class="row u-gap-12px u-m-0">
                    @foreach(\App\Services\Industry\IndustryCatalog::SUBTYPES as $key=>$subtypes)
                        @php $isCurrent = ($profile['profile'] ?? null) === $key; @endphp
                        <div class="col-12 p-0">
                            <div class="card ops-profile-card border rounded-lg p-3 {{ $isCurrent ? 'is-current' : '' }}">
                                <div class="d-flex align-items-center justify-content-between u-gap-12px">
                                    <div>
                                        <h5 class="mb-1 font-14 font-weight-bold">
                                            {{ \App\Services\Platform\CapabilityCatalog::PROFILES[$key][0] }}
                                            @if($isCurrent)
                                                <span class="badge badge-success ml-2 font-11"><i class="fa fa-check"></i> Active</span>
                                            @endif
                                        </h5>
                                        <p class="text-muted small mb-1">Units: {!! implode(' &bull; ', array_map('e', \App\Services\Industry\IndustryCatalog::SETTINGS[$key]['units'])) !!}</p>
                                        @foreach($subtypes as $subtype=>$features)
                                            @if($features)
                                                <p class="text-muted small mb-0">{{ \App\Services\Industry\IndustryCatalog::subtypeLabel($subtype) }} adds: {{ collect($features)->map(fn ($f) => \App\Services\Platform\CapabilityCatalog::DEFINITIONS[$f][0] ?? $f)->implode(', ') }}</p>
                                            @endif
                                        @endforeach
                                    </div>
                                    <div>
                                        @include('backend.operations.form_start', ['formId'=>'profile-'.$key, 'action'=>'configure/profile'])
                                        <input type="hidden" name="profile" value="{{ $key }}">
                                        <div class="d-flex align-items-center u-gap-6px">
                                            <select name="subtype" aria-label="Business subtype for {{ \App\Services\Platform\CapabilityCatalog::PROFILES[$key][0] }}" class="form-control form-control-sm u-w-150px u-h-32px u-fs-12px">
                                                @foreach($subtypes as $subtype=>$features)
                                                    <option value="{{ $subtype }}" @selected($isCurrent && ($profile['subtype'] ?? null) === $subtype)>{{ \App\Services\Industry\IndustryCatalog::subtypeLabel($subtype) }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm {{ $isCurrent ? 'btn-primary' : 'btn-outline-secondary' }} u-fs-12px u-h-32px u-fw-600">
                                                Apply
                                            </button>
                                        </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Right: Product Attributes Configurator -->
        <div class="ops-subcard ops-action-subcard">
            <div class="ops-subcard-header">
                <span class="font-weight-bold text-dark font-13"><i class="dripicons-tags text-primary mr-1"></i> Product Industry Attributes</span>
            </div>
            <div class="ops-subcard-body">
                <div class="ops-form-compact">
                    <div class="form-group">
                        <label>Select Product to Edit Attributes</label>
                        <select id="attribute-product">
                            <option value="">Select product...</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <form id="attribute-form">
                        <div id="attribute-fields" class="ops-attribute-box border rounded p-3 mb-2 min-h-120"></div>
                        <p id="attribute-status" role="status" aria-live="polite" class="text-muted small mb-3">Select a product to view enabled profile attributes.</p>
                        <button type="submit" class="btn-ops-submit" disabled>
                            <i class="fa fa-save mr-1"></i> Save Attributes
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif

@endsection
