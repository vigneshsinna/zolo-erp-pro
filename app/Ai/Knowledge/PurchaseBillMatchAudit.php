<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Models\GoodsReceivedNote;
use App\Models\Purchase;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;

/**
 * Advisory read-only source reconciliation; no tax or price calculation.
 * The proposal adapter/domain command remain the posting authority.
 */
final class PurchaseBillMatchAudit
{
    public function inspect(CompanyContext $context, array $bill): array
    {
        $poId = (int) ($bill['purchase_order_id'] ?? 0);
        $grnId = (int) ($bill['grn_id'] ?? 0);
        $result = [
            'type' => $poId && $grnId ? 'po_grn_bill' : ($poId ? 'po_bill' : ($grnId ? 'grn_bill' : 'direct_bill')),
            'status' => 'not_applicable', 'checks' => [], 'exceptions' => [],
            'source' => 'read_only_erp_records',
            'can_post_from_this_result' => false,
        ];
        if ($poId === 0 && $grnId === 0) {
            $result['checks'][] = 'No PO or GRN evidence was supplied; treat as a direct bill under its own approved policy.';
            return $result;
        }
        $result['status'] = 'requires_review';
        $supplierId = (int) ($bill['supplier_id'] ?? 0);
        $warehouseId = (int) ($bill['warehouse_id'] ?? 0);
        $po = $poId ? Purchase::visibleIn($context)->where('financial_year_id', $context->financialYearId)
            ->where('status', 4)->find($poId) : null;
        $grn = $grnId ? GoodsReceivedNote::where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)
            ->whereNotNull('posted_at')->find($grnId) : null;

        if ($poId && !$po) $result['exceptions'][] = 'PO is missing, not open, or outside the authorised scope.';
        if ($grnId && !$grn) $result['exceptions'][] = 'GRN is missing, unposted or outside the authorised scope.';
        foreach ([$po, $grn] as $source) {
            if ($source && (int) $source->supplier_id !== $supplierId) {
                $result['exceptions'][] = 'Supplier does not match linked purchase evidence.';
            }
            if ($source && (int) $source->warehouse_id !== $warehouseId) {
                $result['exceptions'][] = 'Receiving warehouse differs from linked source.';
            }
        }
        if ($po && $grn && (int) ($grn->purchase_id ?? 0) !== $poId) {
            $result['exceptions'][] = 'The GRN is not linked to the selected purchase order.';
        }
        if ($po) {
            $remaining = app(OrderLifecycleService::class)->remaining('purchase', $poId, $context);
            $openByProduct = [];
            foreach ($remaining['items'] as $line) {
                $pid = (int) $line['product_id'];
                $openByProduct[$pid] = ($openByProduct[$pid] ?? 0) + (float) $line['remaining_qty'];
            }
            $requestedByProduct = [];
            foreach ($bill['items'] ?? [] as $line) {
                $pid = (int) ($line['product_id'] ?? 0);
                $requestedByProduct[$pid] = ($requestedByProduct[$pid] ?? 0) + (float) ($line['qty'] ?? 0);
            }
            foreach ($requestedByProduct as $pid => $qty) {
                if (!array_key_exists($pid, $openByProduct)) {
                    $result['exceptions'][] = 'Bill contains a product not present in the linked PO: product ID '.$pid.'.';
                } elseif ($qty > $openByProduct[$pid] + 0.00005) {
                    $result['exceptions'][] = 'Billed quantity exceeds PO open quantity for product ID '.$pid.'.';
                }
            }
            $result['checks'][] = 'PO open quantities checked; UOM conversions and rates require authoritative bill preview.';
        }
        if ($grn) {
            $receivedProducts = $grn->items()->pluck('product_id')->map(static fn ($v): int => (int) $v)->all();
            foreach ($bill['items'] ?? [] as $line) {
                $pid = (int) ($line['product_id'] ?? 0);
                if (!in_array($pid, $receivedProducts, true)) {
                    $result['exceptions'][] = 'Bill product is not in the linked GRN: product ID '.$pid.'.';
                }
            }
            $result['checks'][] = 'GRN product identities checked; unbilled base quantities/UOM must be validated by posting service.';
        }
        $result['status'] = $result['exceptions'] === [] ? 'identity_verified_amounts_pending' : 'mismatch';
        return $result;
    }
}
