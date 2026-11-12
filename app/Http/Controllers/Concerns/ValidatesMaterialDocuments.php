<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ValidatesMaterialDocuments
{
    private function validateMaterialDocument(Request $request, bool $purchase): void
    {
        $context = $request->attributes->get(\App\Services\Platform\CompanyContext::class) ?? abort(409, 'Choose a company first.');
        app(\App\Services\Commercial\CommercialPermission::class)->assert($purchase ? 'grn-index' : 'delivery-challans-index', $context, $request->user()->id);
        $rateField = $purchase ? 'cost' : 'rate';
        $partyField = $purchase ? 'supplier_id' : 'customer_id';
        $request->validate([
            $partyField => 'required|integer', 'warehouse_id' => 'required|integer',
            $purchase ? 'grn_date' : 'challan_date' => 'required|date',
            'product_id' => 'required|array|min:1|max:500', 'product_id.*' => 'required|integer|min:1',
            'qty' => 'required|array', 'qty.*' => 'required|numeric|gt:0|max:1000000000',
            $rateField => 'required|array', $rateField.'.*' => 'required|numeric|min:0|max:1000000000',
            'tax_rate' => 'nullable|array', 'tax_rate.*' => 'nullable|numeric|between:0,100',
            'unit_id' => 'nullable|array', 'unit_id.*' => 'nullable|integer|min:1',
            'batch_no' => 'nullable|array', 'batch_no.*' => 'nullable|string|max:100',
            'variant_id' => 'nullable|array', 'variant_id.*' => 'nullable|integer|min:1',
        ] + ($purchase ? [] : ['purpose' => 'required|in:'.implode(',', array_keys(\App\Services\Commercial\MaterialDocumentService::DC_PURPOSES))]));
        $party = $purchase ? \App\Models\Supplier::class : \App\Models\Customer::class;
        if (!$party::where('is_active', true)->find($request->input($partyField))
            || !\App\Models\Warehouse::where('is_active', true)->find($request->warehouse_id)) {
            throw ValidationException::withMessages([$partyField => 'Select an active party and warehouse belonging to this company.']);
        }
        $products = Product::whereIn('id', $request->product_id)->where('is_active', true)->get()->keyBy('id');
        $units = Unit::pluck('id')->all();
        $amounts = $taxAmounts = $totals = $unitIds = [];
        $identityLines = [];
        foreach ($request->product_id as $index => $id) {
            $product = $products->get($id);
            if (!$product || !isset($request->qty[$index], $request->input($rateField)[$index])) {
                throw ValidationException::withMessages(['product_id.'.$index => 'Select an active catalog product and enter its quantity and rate.']);
            }
            $unitId = $request->input('unit_id.'.$index) ?: $product->unit_id;
            if (!in_array((int) $unitId, $units)) {
                throw ValidationException::withMessages(['unit_id.'.$index => 'Select a valid product unit.']);
            }
            $unitIds[$index] = $unitId;
            $itemTable = $purchase ? 'goods_received_note_items' : 'delivery_challan_items';
            if ($request->filled('batch_no.'.$index) && !app(\App\Support\Database\SchemaCache::class)->hasColumn(\Illuminate\Support\Facades\DB::connection(), $itemTable, 'batch_no')) {
                throw ValidationException::withMessages(['batch_no.'.$index => 'Apply the physical material migration before entering lots.']);
            }
            $variantId = $request->input('variant_id.'.$index) ?: null;
            if ($product->is_variant && !$variantId) {
                throw ValidationException::withMessages(['variant_id.'.$index => 'Select a variant for this item.']);
            }
            if ($variantId && !app(\App\Support\Database\SchemaCache::class)->hasColumn(\Illuminate\Support\Facades\DB::connection(), $purchase ? 'goods_received_note_items' : 'delivery_challan_items', 'variant_id')) {
                throw ValidationException::withMessages(['variant_id.'.$index => 'Apply the material variant migration before entering variants.']);
            }
            $identityLines[] = ['product_id' => $id, 'unit_id' => $unitId, 'variant_id' => $variantId];
            $amounts[$index] = round((float) $request->qty[$index] * (float) $request->input($rateField)[$index], 4);
            $taxAmounts[$index] = round($amounts[$index] * (float) $request->input('tax_rate.'.$index, 0) / 100, 4);
            $totals[$index] = $amounts[$index] + $taxAmounts[$index];
        }
        // Reuse the shared owner/product/unit/variant guard even when physical posting is disabled.
        app(\App\Services\ERP\CompanyWriteGuard::class)->products($identityLines, $context, 'unit_id');
        $request->merge(['unit_id' => $unitIds, 'amount' => $amounts, 'tax_amount' => $taxAmounts, 'total' => $totals,
            'total_qty' => array_sum($request->qty), $purchase ? 'total_cost' : 'total_amount' => array_sum($amounts),
            'total_tax' => array_sum($taxAmounts), 'grand_total' => array_sum($totals)]);
    }

    private function materialIdentityAttributes(Request $request, int $index, bool $purchase): array
    {
        $cache = app(\App\Support\Database\SchemaCache::class);
        $table = $purchase ? 'goods_received_note_items' : 'delivery_challan_items';
        $attributes = $cache->hasColumn(\Illuminate\Support\Facades\DB::connection(), $table, 'variant_id')
            ? ['variant_id' => $request->input('variant_id.'.$index) ? (int) $request->input('variant_id.'.$index) : null] : [];
        if ($cache->hasColumn(\Illuminate\Support\Facades\DB::connection(), $table, 'batch_no')) {
            $batch = trim((string) $request->input('batch_no.'.$index, ''));
            if ($batch === '' && $request->filled('lot_number')) {
                $batch = trim((string) $request->input('lot_number'));
            }
            $attributes['batch_no'] = $batch ?: null;
        }
        return $attributes;
    }
}
