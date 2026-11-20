<?php

namespace App\Services\Tax;

use App\Services\Platform\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GstProjectionService
{
    /** Review totals use saved snapshots only. This is not a statutory filing schema. */
    public function summary(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $outward = $row['section'] !== 'inward'; $snapshot = $row['snapshot']; $sign = $snapshot['sign'] ?? 1;
            foreach ($snapshot['lines'] as $line) {
                $bucket = $outward ? 'outward_'.$line['supply_type'] : ($line['reverse_charge'] ? 'inward_reverse_charge' : 'inward');
                foreach (['taxable_value', 'cgst', 'sgst', 'igst', 'cess'] as $field) {
                    $totals[$bucket][$field] = ($totals[$bucket][$field] ?? 0) + $sign * \App\Support\LedgerAmount::units($line[$field]);
                }
                if (!$outward) foreach (['cgst', 'sgst', 'igst', 'cess'] as $field) {
                    $credit = $line['input_credit_allowed'] ? 'eligible_input_credit' : 'blocked_input_credit';
                    $totals[$credit][$field] = ($totals[$credit][$field] ?? 0) + $sign * \App\Support\LedgerAmount::units($line[$field]);
                }
            }
        }
        return array_map(fn ($fields) => array_map(fn ($units) => \App\Support\LedgerAmount::decimal($units), $fields), $totals);
    }

    public function record(Model $document, string $kind, array $lines, CompanyContext $context): void
    {
        if (!$document->tax_snapshot_json) return;
        $snapshot = $document->tax_snapshot_json;
        if (!empty($snapshot['is_non_gst']) || ($snapshot['bill_type'] ?? '') === 'non_gst') return;
        DB::table('gst_transaction_projections')->insert([
            'company_id' => $context->companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
            'source_type' => $kind, 'source_id' => $document->id, 'document_no' => $document->reference_no,
            'document_date' => $document->created_at->toDateString(), 'registration_gstin' => $snapshot['registration_gstin'] ?? '',
            'version' => 'gst-domestic-v1', 'snapshot_json' => json_encode($snapshot + ['lines' => $lines, 'grand_total' => (float) $document->grand_total], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function reverse(Model $document, string $kind, string $date, CompanyContext $context): void
    {
        if (!$document->tax_snapshot_json) return;
        $original = DB::table('gst_transaction_projections')->where('company_id', $context->companyId)
            ->where('source_type', $kind)->where('source_id', $document->id)->first();
        if (!$original) return;
        $snapshot = json_decode($original->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
        $snapshot['reversal_of'] = [$kind, $document->id]; $snapshot['sign'] = -1;
        DB::table('gst_transaction_projections')->insert([
            'company_id' => $context->companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
            'source_type' => $kind.'_reversal', 'source_id' => $document->id, 'document_no' => $document->reference_no,
            'document_date' => $date, 'registration_gstin' => $original->registration_gstin,
            'version' => $original->version, 'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function report(CompanyContext $context, string $from, string $to): array
    {
        validator(compact('from', 'to'), ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $rows = DB::table('gst_transaction_projections')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)->whereBetween('document_date', [$from, $to])
            ->orderBy('document_date')->orderBy('id')->get();
        return $rows->map(function ($row) {
            $snapshot = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
            $outward = in_array($row->source_type, ['sale', 'sale_credit_note', 'sale_debit_note', 'sale_reversal'], true);
            $zero = $snapshot['supply_type'] ?? 'domestic';
            // GSTR-1: exports go to EXP, SEZ supplies to B2B with the SEZ flag; other sales by buyer registration.
            $section = !$outward ? 'inward' : (str_contains($row->source_type, 'note') ? ($snapshot['buyer_gstin'] ? 'cdnr' : 'cdnur')
                : (str_starts_with($zero, 'export') ? 'exp' : (str_starts_with($zero, 'sez') ? 'sez' : ($snapshot['buyer_gstin'] ? 'b2b' : 'b2c'))));
            return ['document_no' => $row->document_no, 'document_date' => $row->document_date, 'source_type' => $row->source_type,
                'source_id' => $row->source_id, 'section' => $section, 'registration_gstin' => $row->registration_gstin, 'snapshot' => $snapshot];
        })->all();
    }
}
