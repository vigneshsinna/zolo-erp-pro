<?php

namespace App\Services\Commercial;

use App\Models\Unit;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Translate parallel legacy form arrays without calculating financial or stock effects. */
class LegacyCommercialCommand
{
    public function data(Request $request, bool $purchase, CompanyContext $context): array
    {
        $data = $request->all();
        if (isset($data['items'])) {
            return $data;
        }
        $items = [];
        foreach ($data['product_id'] ?? [] as $i => $id) {
            $unitName = $data[$purchase ? 'purchase_unit' : 'sale_unit'][$i] ?? null;
            $unitId = $unitName ? Unit::where('company_id', $context->companyId)
                ->where(fn ($query) => $query->where('unit_name', $unitName)->orWhere('unit_code', $unitName))->value('id') : null;
            if ($unitName && !$unitId) {
                throw ValidationException::withMessages(['unit' => 'Select a company-owned unit.']);
            }
            $line = ['product_id' => $id, 'qty' => $data['qty'][$i] ?? null,
                $purchase ? 'net_unit_cost' : 'net_unit_price' => $data[$purchase ? 'net_unit_cost' : 'net_unit_price'][$i] ?? null,
                $purchase ? 'purchase_unit_id' : 'sale_unit_id' => $unitId];
            foreach (['discount', 'tax_rate', 'tax', 'imei_number', 'product_batch_id', 'variant_id', 'stock_identity_id'] as $field) {
                if (isset($data[$field][$i])) {
                    $line[$field] = $data[$field][$i];
                }
            }
            if (isset($data['subtotal'][$i])) {
                $line['total'] = $data['subtotal'][$i];
            }
            if ($purchase && (int) ($data['status'] ?? 1) === 2) {
                $line['received_qty'] = $data['recieved'][$i] ?? null;
            }
            $lineBatchNo = !empty($data['batch_no'][$i]) ? $data['batch_no'][$i] : (!empty($data['lot_number']) ? $data['lot_number'] : null);
            if (!empty($lineBatchNo)) {
                $line['batch'] = ['batch_no' => $lineBatchNo, 'expired_date' => $data['expired_date'][$i] ?? null];
                $line['batch_no'] = $lineBatchNo;
            }
            $items[] = $line;
        }
        foreach (['product_id', 'qty', 'sale_unit', 'purchase_unit', 'net_unit_price', 'net_unit_cost', 'discount', 'tax_rate', 'tax',
            'subtotal', 'recieved', 'imei_number', 'product_batch_id', 'variant_id', 'product_code', 'batch_no', 'expired_date'] as $field) {
            unset($data[$field]);
        }
        foreach (['paid_amount', 'paying_method', 'account_id'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                if (count($data[$field]) !== 1) {
                    throw ValidationException::withMessages([$field => 'Split tender requires the reviewed settlement workflow.']);
                }
                $data[$field] = reset($data[$field]);
            }
        }
        if (!empty($data['paid_amount']) && empty($data['paying_method']) && isset($data['paid_by_id'])) {
            $method = is_array($data['paid_by_id']) && count($data['paid_by_id']) === 1 ? reset($data['paid_by_id']) : $data['paid_by_id'];
            $data['paying_method'] = match ((string) (is_scalar($method) ? $method : '')) {
                '1' => 'Cash', '3' => 'Credit Card', '4' => 'Cheque', 'bank', 'Bank' => 'Bank',
                default => throw ValidationException::withMessages(['paying_method' => 'This tender requires its reviewed settlement workflow.']),
            };
        }
        if (!empty($data['draft'])) {
            throw ValidationException::withMessages(['draft' => 'Use Save draft in shared entry.']);
        }
        if (isset($data['created_at'])) {
            $data['business_date'] ??= substr(normalize_to_sql_datetime($data['created_at']), 0, 10);
            unset($data['created_at']);
        }
        if (isset($data['transporter_name'])) {
            $data['transport_name'] = $data['transporter_name'];
            unset($data['transporter_name']);
        }
        if (!empty($data['series_id'])) {
            $data['series_code'] = \App\Models\DocumentSeries::forCompany($context)
                ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)
                ->where('document_type', $purchase ? 'purchase' : 'sale')->findOrFail($data['series_id'])->code;
        }
        // Under GST the server determines every line's tax; browser estimates must not block the post.
        if (app(\App\Services\Tax\GstSetupService::class)->enabled($context, $data['business_date'] ?? null)) {
            foreach ($items as &$item) unset($item['tax'], $item['total']);
            unset($item, $data['grand_total'], $data['order_tax'], $data['total_tax']);
        }
        $data['items'] = $items;
        return $data;
    }
}
