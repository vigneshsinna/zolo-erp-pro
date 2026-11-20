<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\DB;

/** Deterministic, read-only GST intent: no manual zero-tax classification. */
final class PurchaseBillGstIntentService
{
    public function detect(string $text): ?string
    {
        if (preg_match('/\b(?:non[\s-]?gst|without\s+gst|no\s+gst|gst[\s-]?free)\b/ui', $text)) return 'non_gst';
        if (preg_match('/\b(?:gst\s+bill|with\s+gst|include\s+gst|registered\s+gst)\b/ui', $text)) return 'gst';
        return null;
    }

    public function describe(AiPrincipal $principal, array $proposal, string $intent): array
    {
        if ($proposal['action_key'] !== 'purchase_bill.create.v1') {
            return ['message' => 'GST classification is available for purchase bills only.'];
        }
        $payload = json_decode((string) $proposal['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $preview = json_decode((string) $proposal['preview_json'], true, flags: JSON_THROW_ON_ERROR);
        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $profile = DB::table('party_tax_profiles')->where('company_id', $principal->companyId)
            ->where('party_type', 'supplier')->where('party_id', $supplierId)->first();
        $amount = (float) ($preview['totals']['tax_total'] ?? 0);
        $tax = (array) ($preview['totals']['tax_breakdown'] ?? []);
        $type = (string) ($profile->registration_type ?? 'not configured');
        $gstinState = !empty($profile?->gstin) ? 'on file' : 'not on file';
        $message = 'Supplier GST registration: '.$type.' (GSTIN '.$gstinState.'). '
            .'Original ERP preview tax: ₹'.number_format($amount, 2)
            .' (CGST ₹'.number_format((float) ($tax['cgst'] ?? 0), 2)
            .', SGST ₹'.number_format((float) ($tax['sgst'] ?? 0), 2)
            .', IGST ₹'.number_format((float) ($tax['igst'] ?? 0), 2)
            .', cess ₹'.number_format((float) ($tax['cess'] ?? 0), 2).'). ';
        if (!$profile) {
            $message .= 'The supplier GST profile must be completed and reviewed before any GST-compliant bill is posted.';
        } elseif ($intent === 'non_gst' && $type !== 'unregistered' && $amount > 0) {
            $message .= 'The current bill is not a non-GST invoice. Chat cannot erase applicable tax. Select the correct supplier or have an authorized operator correct product/supplier tax classifications.';
        } elseif ($intent === 'gst' && $type === 'unregistered') {
            $message .= 'An unregistered supplier cannot issue a registered GST tax invoice. Choose a registered supplier or create one separately with a valid verified GSTIN and approval.';
        } elseif ($intent === 'non_gst' && $type === 'unregistered') {
            $message .= 'Supplier is unregistered, but product classification or reverse charge may still apply. No tax override was made.';
        } else {
            $message .= 'GST and reverse charge are calculated by ERP from registration, tax category and place of supply. Neither a zero-tax override nor new tax registration was applied.';
        }
        return ['message' => $message, 'gst_status' => $type,
            'gst_total' => number_format($amount, 2, '.', ''), 'intent' => $intent];
    }
}
