<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Purchase;
use App\Models\SalesOrder;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative read facade for Open Orders, pending fulfilment, and unbilled purchase/sale orders.
 * Strictly scoped by CompanyContext and branch isolation.
 */
class ReadOpenOrders
{
    public function execute(
        AiPrincipal $principal,
        string $type = 'all', // 'all', 'sale', 'purchase'
        int $limit = 20
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        $allowed = app(CommercialPermission::class)->allowed(['sales-index', 'purchases-index'], $context, $principal->actorId);
        $included = array_values(array_filter(['sale', 'purchase'], fn ($kind) =>
            in_array($kind === 'sale' ? 'sales-index' : 'purchases-index', $allowed, true)
            && in_array($type, ['all', $kind], true)));
        if ($included === []) {
            return ErpToolResult::denied('orders.open.v1', 'Access denied to the requested order type.', 'AI_TOOL_FORBIDDEN');
        }

        $company = Company::findOrFail($principal->companyId);
        $currency = Currency::find($company->base_currency_id)?->code ?? 'INR';
        $orders = $sources = $warnings = [];
        $summary = ['total_open_orders' => 0, 'total_sales_value' => null, 'total_purchase_value' => null];
        $money = static fn ($value): string => (string) BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);

        foreach ($included as $kind) {
            $sale = $kind === 'sale';
            $table = $sale ? 'sales_orders' : 'purchases';
            $party = $sale ? 'customer' : 'supplier';
            $dateColumn = $sale ? 'business_date' : 'created_at';
            $query = $sale ? SalesOrder::visibleIn($context)->whereIn('sales_orders.status', ['open', 'partial'])
                : Purchase::visibleIn($context)->where('purchases.status', 4)->whereNull('purchases.reversed_at')
                    ->whereNull('purchases.attributes_json->fulfilled_at');
            $query->where("$table.branch_id", $context->branchId)->where("$table.financial_year_id", $context->financialYearId);

            // Match OrderLifecycleService's remaining billing quantities for all authorized orders.
            $billing = DB::table('commercial_order_allocations')->where('company_id', $context->companyId)
                ->where('stage', 'billing')->whereNull('released_at')->groupBy('order_item_id')
                ->selectRaw('order_item_id, SUM(qty_base - returned_qty_base) AS billed');
            $items = DB::table('commercial_order_items as item')->where('item.company_id', $context->companyId)
                ->where('item.order_kind', $kind)->whereIn('item.order_id', (clone $query)->select("$table.id"))
                ->leftJoinSub($billing, 'billing', 'billing.order_item_id', '=', 'item.id')->groupBy('item.order_id')
                ->selectRaw('item.order_id, COUNT(*) AS line_count, SUM(item.qty * item.unit_rate) AS ordered_value,
                    SUM(GREATEST(0, item.qty_base - item.cancelled_qty_base - COALESCE(billing.billed, 0))
                        / NULLIF(item.qty_base, 0) * item.qty * item.unit_rate) AS remaining_value');
            $query->leftJoinSub($items, 'lines', 'lines.order_id', '=', "$table.id");
            $rate = $sale ? '1' : 'COALESCE(NULLIF(purchases.exchange_rate, 0), 1)';
            $totals = (clone $query)->toBase()->selectRaw("COUNT(*) AS total, SUM(lines.remaining_value / ($rate)) AS remaining,
                SUM(CASE WHEN lines.remaining_value IS NULL THEN 1 ELSE 0 END) AS incomplete")->first();

            $summary['total_open_orders'] += (int) $totals->total;
            $summary[$sale ? 'total_sales_value' : 'total_purchase_value'] = $totals->incomplete ? null : $money($totals->remaining);
            if ($totals->incomplete) {
                $warnings[] = 'Some open orders have no lifecycle lines. Their remaining value is unavailable.';
            }
            $sources[] = route('commercial.orders.index', ['kind' => $kind], absolute: false);
            $rows = $query->select("$table.*", 'lines.line_count')
                ->selectRaw("lines.ordered_value / ($rate) AS ordered_value, lines.remaining_value / ($rate) AS remaining_value")
                ->with([$party => fn ($q) => $q->forCompany($context)])
                ->orderByDesc("$table.$dateColumn")->orderByDesc("$table.id")->limit($limit)->get();

            foreach ($rows as $order) {
                $orders[] = [
                    'order_type' => $kind,
                    'order_id' => (int) $order->id,
                    'reference_no' => $order->reference_no,
                    'date' => substr((string) $order->{$dateColumn}, 0, 10),
                    'party' => ['type' => $party, 'id' => (int) $order->{$party . '_id'}, 'name' => $order->{$party}?->name],
                    'items_count' => (int) $order->line_count,
                    'grand_total' => $order->ordered_value === null ? null : $money($order->ordered_value),
                    'open_balance' => $order->remaining_value === null ? null : $money($order->remaining_value),
                    'status' => $sale ? $order->status : ($order->attributes_json['order_status'] ?? 'open'),
                    'source_link' => route('commercial.orders.show', ['kind' => $kind, 'id' => $order->id], absolute: false),
                ];
            }
        }

        usort($orders, static fn ($a, $b) => ($b['date'] <=> $a['date']) ?: ($b['order_id'] <=> $a['order_id']));

        return ErpToolResult::ok(
            toolKey: 'orders.open.v1',
            scope: ['company_id' => $context->companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId],
            data: ['currency' => $currency, 'included_types' => $included, 'summary' => $summary, 'orders' => array_slice($orders, 0, $limit)],
            sourceRefs: $sources,
            warnings: $warnings,
            asOf: now($company->timezone)->toDateString(),
            reconciled: $warnings === [],
            rowCount: min(count($orders), $limit),
        );
    }
}
