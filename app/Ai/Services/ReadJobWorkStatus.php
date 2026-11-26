<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\JobWork\JobWorkService;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Route;

/**
 * Authoritative read facade for Job Work & External Processing.
 * Inspects pending material lying with processors, return deadlines, and GST Section 143 compliance.
 */
class ReadJobWorkStatus
{
    public function __construct(
        protected JobWorkService $jobWorkService
    ) {}

    public function execute(
        AiPrincipal $principal,
        ?int $jobWorkerId = null,
        ?string $status = null,
        int $limit = 20
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        $limit = max(1, min(50, $limit));

        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        $deadlines = $this->jobWorkService->deadlines($context, $principal->actorId);

        $orders = [];
        $orderIds = [];
        $totalPendingValue = BigDecimal::zero();
        $overdueCount = 0;

        foreach ($deadlines as $row) {
            if ($jobWorkerId !== null && (int) ($row['job_worker_party_id'] ?? 0) !== $jobWorkerId) {
                continue;
            }

            $rowStatus = $row['status'] ?? 'ok';
            if ($status !== null && $status !== 'all' && $rowStatus !== $status) {
                continue;
            }

            if ($rowStatus === 'overdue') {
                $overdueCount++;
            }

            $orderIds[$row['order_id']] = true;
            $totalPendingValue = $totalPendingValue->plus((string) $row['pending_value']);
            if (count($orders) >= $limit) {
                continue;
            }

            $product = Product::where('company_id', $principal->companyId)->find($row['product_id'] ?? null);
            $worker = Supplier::where('company_id', $principal->companyId)->find($row['job_worker_party_id'] ?? null);

            $pendingQty = BigDecimal::of((string) ($row['pending_qty'] ?? 0))->toScale(4, RoundingMode::HalfUp);
            $pendingVal = BigDecimal::of((string) ($row['pending_value'] ?? 0))->toScale(2, RoundingMode::HalfUp);

            $orders[] = [
                'order_id' => (int) ($row['order_id'] ?? 0),
                'reference_no' => $row['reference_no'] ?? '',
                'processor' => [
                    'id' => (int) ($row['job_worker_party_id'] ?? 0),
                    'name' => $worker?->name ?? 'Unknown Processor',
                ],
                'product' => [
                    'id' => (int) ($row['product_id'] ?? 0),
                    'name' => $product?->name ?? 'Unknown Product',
                    'code' => $product?->code ?? '',
                ],
                'pending_qty' => (string) $pendingQty,
                'pending_value' => (string) $pendingVal,
                'sent_on' => $row['sent_on'] ?? null,
                'return_by' => $row['return_by'] ?? null,
                'days_left' => (int) ($row['days_left'] ?? 0),
                'status' => $rowStatus,
            ];

        }

        $sourceRoute = Route::has('operations.job_work') ? route('operations.job_work', absolute: false) : '/operations/job-work';

        return ErpToolResult::ok(
            toolKey: 'jobwork.status.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'currency' => $currencyCode,
                'summary' => [
                    'total_orders_pending' => count($orderIds),
                    'total_pending_qty' => null,
                    'total_pending_value' => (string) $totalPendingValue->toScale(2, RoundingMode::HalfUp),
                    'overdue_count' => $overdueCount,
                ],
                'orders' => $orders,
            ],
            sourceRefs: [$sourceRoute],
            warnings: [],
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: count($orders)
        );
    }
}

