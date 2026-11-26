<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

/**
 * Deterministic sales & optical order route classifier.
 * Distinguishes direct dealer invoices from SO/DC/quotation/settlement/return/Rx-lab routes.
 * Zero LLM calls and zero writes.
 */
final class SalesDocumentClassifier
{
    public function classify(string $utterance, array $confirmedFacts = []): array
    {
        if (!empty($confirmedFacts['route_type'])) {
            $explicit = (string) $confirmedFacts['route_type'];
            return $this->result($explicit, $confirmedFacts);
        }

        $text = mb_strtolower(trim($utterance));
        $kind = match (true) {
            !empty($confirmedFacts['delivery_challan_id']) => 'so_dc_invoice',
            !empty($confirmedFacts['sales_order_id']) && (bool) preg_match('/\b(?:challan|dispatch|dc)\b/u', $text) => 'delivery_challan',
            !empty($confirmedFacts['sales_order_id']) => 'so_to_invoice',
            (bool) preg_match('/\b(?:custom rx|rx lab|grinding|surfacing|edging|remake|lab job)\b/u', $text) => 'rx_lab_job',
            (bool) preg_match('/\b(?:credit note|sales return|return lens|wrong power returned|damaged lens return)\b/u', $text) => 'sales_return',
            (bool) preg_match('/\b(?:payment receipt|receive payment|settlement|advance receipt|collect from)\b/u', $text) => 'payment_receipt',
            (bool) preg_match('/\b(?:quotation|quote|estimate)\b/u', $text) => 'quotation',
            (bool) preg_match('/\b(?:delivery challan|dispatch challan|create dc|raise dc)\b/u', $text) => 'delivery_challan',
            (bool) preg_match('/\b(?:sales order|create so|book order|dealer order)\b/u', $text) => 'sales_order',
            (bool) preg_match('/\b(?:service invoice|fitting charge invoice|processing invoice)\b/u', $text) => 'service_sale',
            (bool) preg_match('/\b(?:direct invoice|direct sale|sales invoice|bill dealer|dealer invoice|sell)\b/u', $text) => 'direct_sales_invoice',
            default => 'ambiguous',
        };

        return $this->result($kind, $confirmedFacts);
    }

    private function result(string $kind, array $confirmedFacts): array
    {
        $action = match ($kind) {
            'direct_sales_invoice', 'so_to_invoice', 'so_dc_invoice', 'service_sale' => 'sales_invoice.create.v1',
            'sales_order' => 'sales_order.create.v1',
            'delivery_challan' => 'delivery_challan.create.v1',
            'quotation' => 'quotation.create.v1',
            'payment_receipt' => 'payment_receipt.create.v1',
            default => null,
        };

        $reason = match ($kind) {
            'direct_sales_invoice' => 'Direct dealer sales invoice; no sales order or quotation is required.',
            'sales_order' => 'Sales order reserves stock allocation without posting revenue or AR.',
            'so_to_invoice' => 'Sales order linked directly to invoice; consumes open order balance.',
            'so_dc_invoice' => 'Invoice settles posted delivery challan without double-issuing stock.',
            'delivery_challan' => 'Physical dispatch challan without revenue or AR posting.',
            'quotation' => 'Commercial quotation with zero ledger or stock effect.',
            'payment_receipt' => 'Separate approved customer payment receipt against open item or advance.',
            'service_sale' => 'Non-stock service invoice; no owned stock is issued.',
            'rx_lab_job' => 'Custom Rx lab workflow gated by Opticals Rx Lab profile.',
            'sales_return' => 'Requires verified original invoice line and exact power/disposition check.',
            default => 'Clarify direct invoice, sales order, delivery challan, quotation, or payment receipt.',
        };

        return [
            'classification' => $kind,
            'candidate_action' => $action,
            'so_required' => in_array($kind, ['so_to_invoice', 'so_dc_invoice'], true),
            'supported_without_clarification' => $action !== null,
            'needs_payment_evidence' => $kind === 'payment_receipt' && empty($confirmedFacts['payment_reference']),
            'source' => 'deterministic_explicit_classification',
            'reason' => $reason,
            'writes_executed' => false,
        ];
    }
}
