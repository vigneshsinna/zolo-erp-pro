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

            if ($toStatus === 'dispatched') {
                $dcId = (int) ($evidence['delivery_challan_id'] ?? 0);
                $saleId = (int) ($evidence['sale_id'] ?? 0);
                $validDc = $dcId > 0 && DeliveryChallan::where('company_id', $context->companyId)
                    ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)
                    ->whereNotNull('posted_at')->whereKey($dcId)->exists();
                $validSale = $saleId > 0 && Sale::where('company_id', $context->companyId)
                    ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)
                    ->whereNotNull('posted_at')->whereNull('reversed_at')->whereKey($saleId)->exists();
                if (!$validDc && !$validSale) {
                    throw ValidationException::withMessages([
                        'evidence' => 'Dispatch transition requires a verified posted Delivery Challan or Sales Invoice.',
                    ]);
                }
            }

            if ($toStatus === 'cancelled' && ($job->production_order_id || $job->job_work_order_id || $job->sale_id)) {
                if (empty($evidence['reversal_reference'])) {
                    throw ValidationException::withMessages([
                        'reversal_reference' => 'Cancelling a posted/linked Rx job requires an authoritative reversal or return reference.',
                    ]);
                }
            }

            $fromStatus = (string) $job->status;
            $updates = [
                'status' => $toStatus,
                'revision' => $job->revision + 1,
            ];
            if ($toStatus === 'packed' && isset($evidence['qty_packed'])) {
                $qtyPacked = (float) $evidence['qty_packed'];
                if ($qtyPacked <= 0 || $qtyPacked > (float) $job->qty_accepted + 0.0001) {
                    throw ValidationException::withMessages(['qty_packed' => 'Packed quantity cannot exceed QC-accepted quantity.']);
                }
                $updates['qty_packed'] = $qtyPacked;
            }
            if ($toStatus === 'dispatched') {
                $updates['qty_dispatched'] = (float) ($evidence['qty_dispatched'] ?? $job->qty_packed ?: $job->qty_accepted);
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

            $job->forceFill([
                'qty_accepted' => $acceptedQty,
                'qty_failed' => $failedQty,
                'qty_completed' => $acceptedQty,
                'status' => $toStatus,
                'revision' => $job->revision + 1,
            ])->save();

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
