<?php

declare(strict_types=1);

namespace App\Services\Optical;

use App\Ai\Knowledge\IndustryContextResolver;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\OpticalRxJob;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Optional Optical Rx Lab Job lifecycle service.
 * Strictly gated by IndustryContextResolver::hasLabCapability($context) so stock-only
 * wholesale suppliers and non-optical companies never execute lab workflows.
 */
final class OpticalRxJobService
{
    public const ROUTES = [
        'internal_lab',
        'external_purchase',
        'external_job_work',
        'customer_frame_job',
    ];

    public const STATUSES = [
        'collecting',
        'spec_verified',
        'awaiting_material',
        'ready_for_work',
        'in_process',
        'awaiting_qc',
        'released',
        'packed',
        'dispatched',
        'delivered',
        'reconciled',
        'on_hold',
        'rework_required',
        'cancelled',
        'requires_reconciliation',
    ];

    private const ALLOWED_TRANSITIONS = [
        'collecting' => ['spec_verified', 'on_hold', 'cancelled'],
        'spec_verified' => ['awaiting_material', 'ready_for_work', 'on_hold', 'cancelled'],
        'awaiting_material' => ['ready_for_work', 'on_hold', 'cancelled'],
        'ready_for_work' => ['in_process', 'on_hold', 'cancelled'],
        'in_process' => ['awaiting_qc', 'on_hold', 'rework_required', 'cancelled'],
        'awaiting_qc' => ['released', 'rework_required', 'on_hold'],
        'rework_required' => ['in_process', 'cancelled', 'requires_reconciliation'],
        'released' => ['packed', 'rework_required', 'on_hold'],
        'packed' => ['dispatched', 'on_hold'],
        'dispatched' => ['delivered', 'requires_reconciliation'],
        'delivered' => ['reconciled', 'requires_reconciliation'],
        'on_hold' => ['spec_verified', 'ready_for_work', 'in_process', 'cancelled'],
        'requires_reconciliation' => ['reconciled', 'cancelled'],
    ];

    public function __construct(
        private readonly IndustryContextResolver $industry,
    ) {}

    public function createJob(
        CompanyContext $context,
        int $actorId,
        array $input,
        string $idempotencyKey,
    ): OpticalRxJob {
        $this->assertLabEnabled($context);

        $existing = OpticalRxJob::where('company_id', $context->companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        $route = (string) ($input['fulfillment_route'] ?? 'internal_lab');
        if (!in_array($route, self::ROUTES, true)) {
            throw ValidationException::withMessages(['fulfillment_route' => 'Select a valid Rx job fulfillment route.']);
        }

        $customer = Customer::where('company_id', $context->companyId)->where('is_active', true)->find((int) ($input['customer_id'] ?? 0));
        if (!$customer) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active dealer/customer in the current company.']);
        }

        $productId = !empty($input['product_id']) ? (int) $input['product_id'] : null;
        if ($productId !== null && !Product::where('company_id', $context->companyId)->where('is_active', true)->whereKey($productId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Product not found in current company.']);
        }

        $warehouseId = !empty($input['source_warehouse_id']) ? (int) $input['source_warehouse_id'] : null;
        if ($warehouseId !== null && !Warehouse::where('company_id', $context->companyId)->where('branch_id', $context->branchId)->where('is_active', true)->whereKey($warehouseId)->exists()) {
            throw ValidationException::withMessages(['source_warehouse_id' => 'Source warehouse not found in active branch.']);
        }

        $qtyRequested = (float) ($input['qty_requested'] ?? 0);
        if ($qtyRequested <= 0) {
            throw ValidationException::withMessages(['qty_requested' => 'Requested quantity must be positive.']);
        }

        $specInput = (array) ($input['optical_spec'] ?? $input);
        $normalized = OpticalSpecNormalizer::normalizeSpec($specInput, requireClinicalAxis: true);

        return DB::transaction(function () use ($context, $actorId, $input, $idempotencyKey, $route, $customer, $productId, $warehouseId, $qtyRequested, $normalized): OpticalRxJob {
            $id = (string) Str::uuid();
            $jobNo = 'RXJ-' . now('UTC')->format('Ym') . '-' . strtoupper(substr(str_replace('-', '', $id), 0, 8));

            $job = OpticalRxJob::create([
                'id' => $id,
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId,
                'customer_id' => $customer->id,
                'sales_order_id' => !empty($input['sales_order_id']) ? (int) $input['sales_order_id'] : null,
                'sale_id' => !empty($input['sale_id']) ? (int) $input['sale_id'] : null,
                'production_order_id' => !empty($input['production_order_id']) ? (int) $input['production_order_id'] : null,
                'job_work_order_id' => !empty($input['job_work_order_id']) ? (int) $input['job_work_order_id'] : null,
                'principal_job_order_id' => !empty($input['principal_job_order_id']) ? (int) $input['principal_job_order_id'] : null,
                'job_no' => $jobNo,
                'customer_order_no' => isset($input['customer_order_no']) ? (string) $input['customer_order_no'] : null,
                'source_warehouse_id' => $warehouseId,
                'fulfillment_route' => $route,
                'status' => 'spec_verified',
                'revision' => 1,
                'product_id' => $productId,
                'optical_spec_json' => $normalized['canonical'] + ['spec_signature' => $normalized['signature']],
                'qty_requested' => $qtyRequested,
                'promised_date' => $input['promised_date'] ?? null,
                'parent_job_id' => $input['parent_job_id'] ?? null,
                'remake_reason' => $input['remake_reason'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
            ]);

            $this->recordEvent($job, $actorId, 'job_created', null, 'spec_verified', null, null, [
                'fulfillment_route' => $route,
                'spec_signature' => $normalized['signature'],
            ]);

            return $job->fresh();
        });
    }

    public function transition(
        CompanyContext $context,
        int $actorId,
        string $jobId,
        int $expectedRevision,
        string $toStatus,
        array $evidence = [],
    ): OpticalRxJob {
        $this->assertLabEnabled($context);
        if (!in_array($toStatus, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unsupported optical Rx job status.']);
        }

        return DB::transaction(function () use ($context, $actorId, $jobId, $expectedRevision, $toStatus, $evidence): OpticalRxJob {
            $job = OpticalRxJob::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->lockForUpdate()
                ->find($jobId);

            if (!$job) {
                throw new ModelNotFoundException('Optical Rx job not found in current scope.');
            }
            if ((int) $job->revision !== $expectedRevision) {
                throw new ConflictHttpException('Optical Rx job revision conflict. Reload current state.');
            }

            $allowedNext = self::ALLOWED_TRANSITIONS[$job->status] ?? [];
            if (!in_array($toStatus, $allowedNext, true)) {
                throw ValidationException::withMessages([
                    'status' => "Transition from '{$job->status}' to '{$toStatus}' is not permitted.",
                ]);
            }

            if (in_array($toStatus, ['released', 'packed'], true) && (float) $job->qty_accepted <= 0) {
                throw ValidationException::withMessages([
                    'qc' => 'Cannot release or pack an Rx job with zero QC-accepted quantity.',
                ]);
            }

            $fromStatus = (string) $job->status;
            $updates = [
                'status' => $toStatus,
                'revision' => $job->revision + 1,
            ];
            if ($toStatus === 'packed') {
                $qtyPacked = (float) ($evidence['qty_packed'] ?? $job->qty_accepted);
                if ($qtyPacked <= 0 || $qtyPacked > (float) $job->qty_accepted + 0.0001) {
                    throw ValidationException::withMessages(['qty_packed' => 'Packed quantity cannot exceed QC-accepted quantity.']);
                }
                $updates['qty_packed'] = $qtyPacked;
            }
            if ($toStatus === 'dispatched') {
                $allocation = $this->verifyAndAllocateDispatchSource($context, $actorId, $job, $evidence);
                $updates['qty_dispatched'] = $allocation['allocated_qty'];
                if ($allocation['sale_id'] !== null && $job->sale_id === null) {
                    $updates['sale_id'] = $allocation['sale_id'];
                }
            }
            if ($toStatus === 'cancelled') {
                $this->verifyLinkedDocumentReversal($context, $job, $evidence);
                if (Schema::hasTable('optical_rx_job_allocations')) {
                    DB::table('optical_rx_job_allocations')
                        ->where('company_id', $context->companyId)
                        ->where('job_id', $job->id)
                        ->whereNull('released_at')
                        ->update([
                            'released_at' => now('UTC'),
                            'updated_at' => now('UTC'),
                        ]);
                }
            }

            $job->forceFill($updates)->save();
            $this->recordEvent(
                $job,
                $actorId,
                'status_transition',
                $fromStatus,
                $toStatus,
                $evidence['evidence_type'] ?? null,
                isset($evidence['evidence_id']) ? (int) $evidence['evidence_id'] : null,
                $evidence
            );

            return $job->fresh();
        });
    }

    public function recordQcResult(
        CompanyContext $context,
        int $actorId,
        string $jobId,
        int $expectedRevision,
        float $acceptedQty,
        float $failedQty,
        ?string $failureReason = null,
    ): OpticalRxJob {
        $this->assertLabEnabled($context);
        if ($acceptedQty < 0 || $failedQty < 0 || ($acceptedQty + $failedQty) <= 0) {
            throw ValidationException::withMessages(['qc' => 'Provide non-negative accepted/failed QC quantities totaling > 0.']);
        }

        return DB::transaction(function () use ($context, $actorId, $jobId, $expectedRevision, $acceptedQty, $failedQty, $failureReason): OpticalRxJob {
            $job = OpticalRxJob::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->lockForUpdate()
                ->find($jobId);

            if (!$job) {
                throw new ModelNotFoundException('Optical Rx job not found.');
            }
            if ((int) $job->revision !== $expectedRevision) {
                throw new ConflictHttpException('Optical Rx job revision conflict.');
            }
            if (!in_array($job->status, ['in_process', 'awaiting_qc'], true)) {
                throw ValidationException::withMessages(['status' => 'QC result can only be recorded while job is in_process or awaiting_qc.']);
            }
            if (($acceptedQty + $failedQty) > (float) $job->qty_requested + 0.0001) {
                throw ValidationException::withMessages(['qc' => 'Total QC quantity cannot exceed requested job quantity.']);
            }

            $fromStatus = (string) $job->status;
            $toStatus = $failedQty > 0 && $acceptedQty <= 0 ? 'rework_required' : ($acceptedQty > 0 ? 'released' : 'rework_required');

            $qcUpdates = [
                'qty_accepted' => $acceptedQty,
                'qty_failed' => $failedQty,
                'qty_completed' => $acceptedQty,
                'status' => $toStatus,
                'revision' => $job->revision + 1,
            ];
            if (Schema::hasColumn('optical_rx_jobs', 'qc_inspector_id')) {
                $qcUpdates['qc_inspector_id'] = $actorId;
            }
            $job->forceFill($qcUpdates)->save();

            $this->recordEvent($job, $actorId, 'qc_recorded', $fromStatus, $toStatus, 'qc_inspection', null, [
                'accepted_qty' => $acceptedQty,
                'failed_qty' => $failedQty,
                'failure_reason' => $failureReason,
            ]);

            return $job->fresh();
        });
    }

    public function createRemake(
        CompanyContext $context,
        int $actorId,
        string $parentJobId,
        string $reason,
        string $idempotencyKey,
    ): OpticalRxJob {
        $this->assertLabEnabled($context);
        $parent = OpticalRxJob::where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)
            ->find($parentJobId);
        if (!$parent) {
            throw new ModelNotFoundException('Original Rx job not found in current scope.');
        }

        $remakeQty = (float) ($parent->qty_failed > 0 ? $parent->qty_failed : $parent->qty_requested);

        return $this->createJob($context, $actorId, [
            'customer_id' => $parent->customer_id,
            'sales_order_id' => $parent->sales_order_id,
            'product_id' => $parent->product_id,
            'source_warehouse_id' => $parent->source_warehouse_id,
            'customer_order_no' => $parent->customer_order_no,
            'fulfillment_route' => $parent->fulfillment_route,
            'qty_requested' => $remakeQty,
            'optical_spec' => $parent->optical_spec_json,
            'promised_date' => $parent->promised_date?->toDateString(),
            'parent_job_id' => $parent->id,
            'remake_reason' => trim($reason),
        ], $idempotencyKey);
    }

    /**
     * Validate a barcode/QR scan reference strictly within company/branch/FY scope.
     * Scanning itself never approves or posts an ERP mutation.
     *
     * @return array<string, mixed>
     */
    public function verifyScanReference(CompanyContext $context, string $reference): array
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw ValidationException::withMessages(['reference' => 'Scan reference cannot be empty.']);
        }

        $job = OpticalRxJob::where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)
            ->where(function ($q) use ($trimmed): void {
                $q->where('job_no', $trimmed)->orWhere('id', $trimmed);
            })
            ->first();

        if ($job) {
            if ($job->status === 'cancelled') {
                throw ValidationException::withMessages(['reference' => 'Scanned Rx job is cancelled and ineligible for processing.']);
            }
            return [
                'document_kind' => 'optical_rx_job',
                'approval_granted' => false,
                'job' => $this->toSafeSummary($job, includeWearerPii: false),
            ];
        }

        $sale = Sale::visibleIn($context)
            ->where('financial_year_id', $context->financialYearId)
            ->whereNull('reversed_at')
            ->where('reference_no', $trimmed)
            ->first();

        if ($sale) {
            return [
                'document_kind' => 'sale_voucher',
                'approval_granted' => false,
                'sale_id' => (int) $sale->id,
                'reference_no' => (string) $sale->reference_no,
                'customer_order_no' => $sale->customer_order_no,
                'customer_id' => (int) $sale->customer_id,
                'posted_at' => $sale->posted_at?->toDateTimeString(),
            ];
        }

        throw new ModelNotFoundException('Scanned document or Rx job does not exist in your authorized company/branch/FY.');
    }

    /** @return array<string, mixed> */
    public function toSafeSummary(OpticalRxJob $job, bool $includeWearerPii = false): array
    {
        $spec = is_array($job->optical_spec_json) ? $job->optical_spec_json : [];
        if (!$includeWearerPii) {
            unset($spec['end_user_name'], $spec['end_user_phone'], $spec['end_user_email'], $spec['end_user_address'], $spec['patient_name']);
        }

        return [
            'id' => (string) $job->id,
            'job_no' => (string) $job->job_no,
            'customer_id' => (int) $job->customer_id,
            'customer_order_no' => $job->customer_order_no,
            'fulfillment_route' => (string) $job->fulfillment_route,
            'status' => (string) $job->status,
            'revision' => (int) $job->revision,
            'qty_requested' => (float) $job->qty_requested,
            'qty_accepted' => (float) $job->qty_accepted,
            'qty_failed' => (float) $job->qty_failed,
            'qty_packed' => (float) $job->qty_packed,
            'qty_dispatched' => (float) $job->qty_dispatched,
            'parent_job_id' => $job->parent_job_id,
            'optical_spec' => $spec,
        ];
    }

    private function assertLabEnabled(CompanyContext $context): void
    {
        if (!$this->industry->hasLabCapability($context)) {
            throw new AuthorizationException('Optical Rx lab workflows are disabled or not enabled for this store profile.');
        }
    }

    /**
     * Verify a posted physical Delivery Challan or physical Sales Invoice for this customer and optical spec,
     * prevent DC-to-Sale double counting via material_settlements/sales linkage, and record immutable line allocations.
     *
     * @return array{allocated_qty: float, sale_id: ?int}
     */
    private function verifyAndAllocateDispatchSource(
        CompanyContext $context,
        int $actorId,
        OpticalRxJob $job,
        array $evidence,
    ): array {
        $dcId = (int) ($evidence['delivery_challan_id'] ?? 0);
        $saleId = (int) ($evidence['sale_id'] ?? 0);
        if ($dcId <= 0 && $saleId <= 0) {
            throw ValidationException::withMessages([
                'evidence' => 'Dispatch transition requires a verified posted Delivery Challan or Sales Invoice.',
            ]);
        }

        $maxDispatchQty = (float) ($job->qty_packed > 0 ? $job->qty_packed : $job->qty_accepted);
        $requestedQty = (float) ($evidence['qty_dispatched'] ?? $maxDispatchQty);
        if ($requestedQty <= 0 || $requestedQty > $maxDispatchQty + 0.0001) {
            throw ValidationException::withMessages([
                'qty_dispatched' => 'Dispatched quantity cannot exceed packed or QC-accepted quantity.',
            ]);
        }

        $sourceType = $dcId > 0 ? 'delivery_challan' : 'sale';
        $sourceId = $dcId > 0 ? $dcId : $saleId;
        $resolvedSaleId = $saleId > 0 ? $saleId : null;
        $allocatableLines = [];

        if ($sourceType === 'delivery_challan') {
            $dc = DeliveryChallan::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->whereNotNull('posted_at')
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->find($dcId);
            if (!$dc) {
                throw ValidationException::withMessages(['delivery_challan_id' => 'Posted Delivery Challan not found in current scope.']);
            }
            if ((int) $dc->customer_id !== (int) $job->customer_id) {
                throw ValidationException::withMessages(['customer_id' => 'Delivery Challan customer does not match the Rx job customer.']);
            }
            $hasMovement = ($dc->stock_movement_id && DB::table('stock_movements')
                ->where('company_id', $context->companyId)
                ->where('id', $dc->stock_movement_id)
                ->where('status', 'posted')
                ->exists())
                || DB::table('stock_movements')
                    ->where('company_id', $context->companyId)
                    ->where('source_type', 'delivery_challan')
                    ->where('source_id', $dc->id)
                    ->where('status', 'posted')
                    ->exists();
            if (!$hasMovement) {
                throw ValidationException::withMessages(['delivery_challan_id' => 'Delivery Challan requires a posted physical stock movement.']);
            }
            $resolvedSaleId = $resolvedSaleId ?? ($dc->sale_id ? (int) $dc->sale_id : null);

            $dcLines = DB::table('delivery_challan_items')
                ->where('delivery_challan_id', $dc->id)
                ->where(function ($q) use ($context): void {
                    $q->where('company_id', $context->companyId)->orWhereNull('company_id');
                })
                ->lockForUpdate()
                ->get();

            foreach ($dcLines as $line) {
                if ($job->product_id && (int) $line->product_id !== (int) $job->product_id) {
                    continue;
                }
                if (!$this->lineOpticalMatches($job, $line->optical_spec_json ?? null, (int) $line->product_id)) {
                    continue;
                }
                $lineQty = max(0.0, (float) ($line->qty_base ?? $line->qty) - (float) ($line->returned_qty_base ?? 0));
                $usedHere = $this->sumActiveAllocations($context->companyId, 'delivery_challan', $dc->id, (int) $line->id);
                $settledSaleAlloc = 0.0;
                if (Schema::hasTable('material_settlements')) {
                    $settlements = DB::table('material_settlements')
                        ->where('company_id', $context->companyId)
                        ->where('document_type', 'dc')
                        ->where('document_id', $dc->id)
                        ->where('document_item_id', $line->id)
                        ->where('settled_by_type', 'sale')
                        ->whereNull('released_at')
                        ->get();
                    foreach ($settlements as $settlement) {
                        $settledSaleAlloc += $this->sumActiveAllocations(
                            $context->companyId,
                            'sale',
                            (int) $settlement->settled_by_id,
                            (int) ($settlement->settled_by_line_id ?? 0),
                        );
                    }
                }
                if ($settledSaleAlloc === 0.0 && $dc->sale_id) {
                    $settledSaleAlloc += $this->sumActiveAllocationsForProductInSale($context->companyId, (int) $dc->sale_id, (int) $line->product_id);
                }
                $free = max(0.0, $lineQty - $usedHere - $settledSaleAlloc);
                if ($free > 0.0001) {
                    $allocatableLines[] = ['line_id' => (int) $line->id, 'free' => $free];
                }
            }
        } else {
            $sale = Sale::visibleIn($context)
                ->where('financial_year_id', $context->financialYearId)
                ->whereNotNull('posted_at')
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->find($saleId);
            if (!$sale) {
                throw ValidationException::withMessages(['sale_id' => 'Posted non-reversed Sales Invoice not found in current scope.']);
            }
            if ((int) $sale->customer_id !== (int) $job->customer_id) {
                throw ValidationException::withMessages(['customer_id' => 'Sales Invoice customer does not match the Rx job customer.']);
            }
            $hasPhysicalSaleMovement = DB::table('stock_movements')
                ->where('company_id', $context->companyId)
                ->where('source_type', 'sale')
                ->where('source_id', $sale->id)
                ->where('status', 'posted')
                ->exists();
            $hasSettledPhysicalDc = DB::table('delivery_challans')
                ->where('company_id', $context->companyId)
                ->where('sale_id', $sale->id)
                ->whereNotNull('posted_at')
                ->exists()
                || (Schema::hasTable('material_settlements') && DB::table('material_settlements')
                    ->where('company_id', $context->companyId)
                    ->where('document_type', 'dc')
                    ->where('settled_by_type', 'sale')
                    ->where('settled_by_id', $sale->id)
                    ->whereNull('released_at')
                    ->exists());
            if (!$hasPhysicalSaleMovement && !$hasSettledPhysicalDc) {
                throw ValidationException::withMessages(['sale_id' => 'Service-only or unposted invoices without physical stock movement cannot verify Rx dispatch.']);
            }

            $saleLines = DB::table('product_sales as ps')
                ->join('products as p', 'p.id', '=', 'ps.product_id')
                ->where('ps.sale_id', $sale->id)
                ->where(function ($q) use ($context): void {
                    $q->where('ps.company_id', $context->companyId)->orWhereNull('ps.company_id');
                })
                ->where('p.company_id', $context->companyId)
                ->select('ps.*', 'p.type as product_type')
                ->lockForUpdate()
                ->get();

            foreach ($saleLines as $line) {
                if (($line->product_type ?? '') === 'service') {
                    continue;
                }
                if ($job->product_id && (int) $line->product_id !== (int) $job->product_id) {
                    continue;
                }
                $lineSpec = $line->prescription_json ?: [
                    'sph' => $line->sph ?? null,
                    'cyl' => $line->cyl ?? null,
                    'axis' => $line->axis ?? null,
                    'addition' => $line->addition ?? null,
                    'base_curve' => $line->base ?? null,
                    'dia' => $line->dia ?? null,
                    'lr' => $line->lr ?? null,
                ];
                if (!$this->lineOpticalMatches($job, $lineSpec, (int) $line->product_id)) {
                    continue;
                }
                $lineQty = max(0.0, (float) $line->qty - (float) ($line->return_qty ?? 0));
                $usedHere = $this->sumActiveAllocations($context->companyId, 'sale', $sale->id, (int) $line->id);
                $linkedDcAlloc = 0.0;
                if (Schema::hasTable('material_settlements')) {
                    $settlements = DB::table('material_settlements')
                        ->where('company_id', $context->companyId)
                        ->where('document_type', 'dc')
                        ->where('settled_by_type', 'sale')
                        ->where('settled_by_id', $sale->id)
                        ->whereNull('released_at')
                        ->where(function ($q) use ($line): void {
                            $q->where('settled_by_line_id', $line->id)->orWhereNull('settled_by_line_id');
                        })
                        ->get();
                    foreach ($settlements as $settlement) {
                        $linkedDcAlloc += $this->sumActiveAllocations(
                            $context->companyId,
                            'delivery_challan',
                            (int) $settlement->document_id,
                            (int) $settlement->document_item_id,
                        );
                    }
                }
                $linkedDcs = DB::table('delivery_challans')
                    ->where('company_id', $context->companyId)
                    ->where('sale_id', $sale->id)
                    ->pluck('id');
                if ($linkedDcAlloc === 0.0 && $linkedDcs->isNotEmpty()) {
                    foreach ($linkedDcs as $linkedDcId) {
                        $linkedDcAlloc += $this->sumActiveAllocationsForSource($context->companyId, 'delivery_challan', (int) $linkedDcId);
                    }
                }
                $free = max(0.0, $lineQty - $usedHere - $linkedDcAlloc);
                if ($free > 0.0001) {
                    $allocatableLines[] = ['line_id' => (int) $line->id, 'free' => $free];
                }
            }
        }

        $totalFree = array_sum(array_column($allocatableLines, 'free'));
        if ($totalFree + 0.0001 < $requestedQty) {
            throw ValidationException::withMessages([
                'evidence' => 'Dispatch document has insufficient unallocated physical optical quantity matching this Rx job.',
            ]);
        }

        $remaining = $requestedQty;
        $now = now('UTC');
        foreach ($allocatableLines as $candidate) {
            if ($remaining <= 0.0001) {
                break;
            }
            $take = min($remaining, $candidate['free']);
            if ($take <= 0.0001) {
                continue;
            }
            if (Schema::hasTable('optical_rx_job_allocations')) {
                DB::table('optical_rx_job_allocations')->insert([
                    'company_id' => $context->companyId,
                    'job_id' => $job->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_line_id' => $candidate['line_id'],
                    'qty_allocated' => $take,
                    'created_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $remaining -= $take;
        }

        return [
            'allocated_qty' => $requestedQty,
            'sale_id' => $resolvedSaleId,
        ];
    }

    private function sumActiveAllocations(int $companyId, string $sourceType, int $sourceId, int $sourceLineId): float
    {
        if (!Schema::hasTable('optical_rx_job_allocations') || $sourceLineId <= 0) {
            return 0.0;
        }

        return (float) DB::table('optical_rx_job_allocations')
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_line_id', $sourceLineId)
            ->whereNull('released_at')
            ->sum('qty_allocated');
    }

    private function sumActiveAllocationsForSource(int $companyId, string $sourceType, int $sourceId): float
    {
        if (!Schema::hasTable('optical_rx_job_allocations')) {
            return 0.0;
        }

        return (float) DB::table('optical_rx_job_allocations')
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('released_at')
            ->sum('qty_allocated');
    }

    private function sumActiveAllocationsForProductInSale(int $companyId, int $saleId, int $productId): float
    {
        if (!Schema::hasTable('optical_rx_job_allocations')) {
            return 0.0;
        }
        $lineIds = DB::table('product_sales')
            ->where('company_id', $companyId)
            ->where('sale_id', $saleId)
            ->where('product_id', $productId)
            ->pluck('id');
        if ($lineIds->isEmpty()) {
            return 0.0;
        }

        return (float) DB::table('optical_rx_job_allocations')
            ->where('company_id', $companyId)
            ->where('source_type', 'sale')
            ->where('source_id', $saleId)
            ->whereIn('source_line_id', $lineIds)
            ->whereNull('released_at')
            ->sum('qty_allocated');
    }

    private function lineOpticalMatches(OpticalRxJob $job, mixed $lineSpec, int $lineProductId): bool
    {
        $jobSpec = is_array($job->optical_spec_json) ? $job->optical_spec_json : [];
        if ($jobSpec === []) {
            return true;
        }

        $parsed = null;
        if (is_string($lineSpec) && trim($lineSpec) !== '') {
            $decoded = json_decode($lineSpec, true);
            $parsed = is_array($decoded) ? $decoded : null;
        } elseif (is_array($lineSpec)) {
            $parsed = $lineSpec;
        }

        $hasLinePowers = is_array($parsed) && array_filter([
            $parsed['sph'] ?? null,
            $parsed['cyl'] ?? null,
            $parsed['axis'] ?? null,
            $parsed['addition'] ?? null,
            $parsed['lr'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== '') !== [];

        if ($hasLinePowers) {
            foreach (['sph', 'cyl', 'axis', 'addition', 'lr'] as $key) {
                $expected = $jobSpec[$key] ?? null;
                $actual = $parsed[$key] ?? null;
                if ($expected !== null && $expected !== '' && $actual !== null && $actual !== '') {
                    $normExpected = OpticalSpecNormalizer::normalizeSpec([$key => $expected])['canonical'][$key] ?? (string) $expected;
                    $normActual = OpticalSpecNormalizer::normalizeSpec([$key => $actual])['canonical'][$key] ?? (string) $actual;
                    if ($normExpected !== $normActual) {
                        return false;
                    }
                }
            }

            return true;
        }

        $identity = DB::table('optical_lens_identities')
            ->where('company_id', $job->company_id)
            ->where('product_id', $lineProductId)
            ->first();
        if (!$identity) {
            return (int) ($job->product_id ?? 0) === $lineProductId;
        }

        foreach (['sph', 'cyl', 'axis', 'addition', 'lr'] as $key) {
            $expected = $jobSpec[$key] ?? null;
            $actual = $identity->{$key} ?? null;
            if ($expected !== null && $expected !== '' && $actual !== null && $actual !== '' && (string) $expected !== (string) $actual) {
                return false;
            }
        }

        return true;
    }

    private function verifyLinkedDocumentReversal(CompanyContext $context, OpticalRxJob $job, array $evidence): void
    {
        $hasActiveAllocations = Schema::hasTable('optical_rx_job_allocations')
            && DB::table('optical_rx_job_allocations')
                ->where('company_id', $context->companyId)
                ->where('job_id', $job->id)
                ->whereNull('released_at')
                ->exists();

        if (!$job->production_order_id && !$job->job_work_order_id && !$job->sale_id && !$hasActiveAllocations) {
            return;
        }

        $revRef = trim((string) ($evidence['reversal_reference'] ?? ''));
        $returnId = (int) ($evidence['return_id'] ?? 0);
        if ($revRef === '' && $returnId <= 0) {
            throw ValidationException::withMessages([
                'reversal_reference' => 'Cancelling a posted/linked Rx job requires an authoritative DB-verified reversal or return record.',
            ]);
        }

        if ($job->sale_id) {
            $saleReversed = DB::table('sales')
                ->where('company_id', $context->companyId)
                ->where('id', $job->sale_id)
                ->whereNotNull('reversed_at')
                ->exists();
            $returnQuery = DB::table('returns')
                ->where('company_id', $context->companyId)
                ->where('customer_id', $job->customer_id);
            if ($returnId > 0) {
                $returnQuery->where('id', $returnId);
            } else {
                $returnQuery->where('reference_no', $revRef);
            }
            if (Schema::hasColumn('returns', 'sale_id')) {
                $returnQuery->where(function ($q) use ($job): void {
                    $q->where('sale_id', $job->sale_id)->orWhereNull('sale_id');
                });
            }
            if (!$saleReversed && !$returnQuery->exists()) {
                throw ValidationException::withMessages([
                    'reversal_reference' => 'Linked Sales Invoice is not reversed and no matching customer return record exists.',
                ]);
            }
        }

        if ($job->production_order_id) {
            $poReversed = DB::table('production_orders')
                ->where('company_id', $context->companyId)
                ->where('id', $job->production_order_id)
                ->where(function ($q): void {
                    $q->whereNotNull('reversed_at')->orWhere('status', 'cancelled');
                })
                ->exists();
            if (!$poReversed) {
                throw ValidationException::withMessages([
                    'reversal_reference' => 'Linked Production Order must be reversed or cancelled before cancelling Rx job.',
                ]);
            }
        }

        if ($job->job_work_order_id) {
            $jwCancelled = DB::table('job_work_orders')
                ->where('company_id', $context->companyId)
                ->where('id', $job->job_work_order_id)
                ->where('status', 'cancelled')
                ->exists();
            $jwDispatchReversed = DB::table('job_work_dispatches')
                ->where('company_id', $context->companyId)
                ->where('job_work_order_id', $job->job_work_order_id)
                ->whereNotNull('reversed_at')
                ->exists();
            if (!$jwCancelled && !$jwDispatchReversed) {
                throw ValidationException::withMessages([
                    'reversal_reference' => 'Linked Job Work Order/Dispatch must be reversed or cancelled before cancelling Rx job.',
                ]);
            }
        }
    }

    private function recordEvent(
        OpticalRxJob $job,
        int $actorId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?string $evidenceType,
        ?int $evidenceId,
        array $metadata,
    ): void {
        DB::table('optical_rx_job_events')->insert([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'company_id' => $job->company_id,
            'actor_id' => $actorId,
            'revision' => $job->revision,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'evidence_type' => $evidenceType,
            'evidence_id' => $evidenceId,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'),
        ]);
    }
}
