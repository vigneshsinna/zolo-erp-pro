<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\DTOs\NormalizedInputV1;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Models\AiMediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds canonical `normalized.input.v1` payloads from verified extractions,
 * optional user text, and optional cross-modal attachments without touching ledgers.
 */
final class NormalizedInputBuilder
{
    public function __construct(
        protected ActionCapabilityRegistry $capabilityRegistry,
    ) {}

    /**
     * @param array<string, mixed> $extracted
     * @param array<string, mixed>|null $companionExtracted
     */
    public function build(
        AiPrincipal $principal,
        AiMediaAsset $asset,
        array $extracted,
        string $extractionId,
        int $extractionRevision,
        string $modelKey,
        ?string $companionText = null,
        ?array $companionExtracted = null,
        ?string $overrideIntentKey = null,
    ): NormalizedInputV1 {
        $warnings = array_values((array) ($extracted['warnings'] ?? []));
        $conflicts = [];

        $intentKey = $this->resolveIntentKey($asset, $extracted, $companionText, $overrideIntentKey);
        $slots = $this->mapSlots($asset, $intentKey, $extracted, $companionText);

        $fieldSources = $this->buildFieldSources($asset, $extracted, $slots, $companionText);

        if ($companionExtracted !== null) {
            $this->detectCrossModalConflicts($extracted, $companionExtracted, $conflicts, $warnings);
        }

        $this->detectPostedImmutableConflict($principal, $extracted, $companionText, $conflicts, $warnings);

        $capabilityReason = null;
        $isActionable = false;

        if ($intentKey !== null && $intentKey !== 'query_readonly') {
            if (! $this->capabilityRegistry->has($intentKey)) {
                $capabilityReason = $this->unimplementedCapabilityMessage($intentKey);
                $warnings[] = $capabilityReason;
            } elseif ($conflicts === []) {
                $isActionable = true;
            }
        }

        return new NormalizedInputV1(
            schemaVersion: (string) config('ai_multimodal.schema_versions.normalized_input', 'normalized.input.v1'),
            intentKey: $intentKey,
            isActionable: $isActionable,
            isVerifiedByErp: false,
            slots: $slots,
            sourceProvenance: [
                'media_id' => $asset->id,
                'extraction_id' => $extractionId,
                'extraction_revision' => $extractionRevision,
                'source_kind' => $asset->source_kind,
                'model_key' => $modelKey,
                'companion_text_present' => $companionText !== null && trim($companionText) !== '',
                'field_sources' => $fieldSources,
            ],
            conflicts: $conflicts,
            warnings: array_values(array_unique($warnings)),
            capabilityUnavailableReason: $capabilityReason,
        );
    }

    /**
     * @param array<string, mixed> $extracted
     */
    private function resolveIntentKey(
        AiMediaAsset $asset,
        array $extracted,
        ?string $companionText,
        ?string $overrideIntentKey,
    ): ?string {
        if ($overrideIntentKey !== null && trim($overrideIntentKey) !== '') {
            return trim($overrideIntentKey);
        }

        $text = strtolower((string) $companionText);
        if ($text !== '') {
            if (preg_match('/\b(create|make|prepare|draft|raise|convert)\b.*\b(purchase\s*order|p\.?\s*o\.?)\b/', $text)
                || preg_match('/\b(purchase\s*order)\b/', $text)) {
                return 'purchase_order.create.v1';
            }
            if (preg_match('/\b(create|make|prepare|draft|raise|convert)\b.*\b(sales\s*order|s\.?\s*o\.?)\b/', $text)
                || preg_match('/\b(sales\s*order)\b/', $text)) {
                return 'sales_order.create.v1';
            }
            if (preg_match('/\b(post|create|enter|book)\b.*\b(purchase\s*bill|vendor\s*bill)\b/', $text)) {
                return 'purchase_bill.create.v1';
            }
            if (preg_match('/\b(post|create|enter|book)\b.*\b(sales\s*invoice|tax\s*invoice)\b/', $text)) {
                return 'sales_invoice.create.v1';
            }
        }

        if ($asset->source_kind === 'audio') {
            $guess = (string) ($extracted['intent_guess'] ?? 'unknown');

            return in_array($guess, ['purchase_order.create.v1', 'sales_order.create.v1', 'query_readonly'], true)
                ? $guess
                : null;
        }

        $docType = (string) ($extracted['document_type'] ?? 'unknown');

        return match ($docType) {
            'purchase_order' => 'purchase_order.create.v1',
            'sales_order' => 'sales_order.create.v1',
            'purchase_bill' => 'purchase_bill.create.v1',
            'sales_invoice' => 'sales_invoice.create.v1',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $extracted
     * @return array<string, mixed>
     */
    private function mapSlots(
        AiMediaAsset $asset,
        ?string $intentKey,
        array $extracted,
        ?string $companionText,
    ): array {
        $isSales = $intentKey === 'sales_order.create.v1' || $intentKey === 'sales_invoice.create.v1';

        if ($asset->source_kind === 'audio') {
            $party = $extracted['party_name'] ?? null;
            $slots = [
                $isSales ? 'customer_query' : 'supplier_query' => $party,
                'warehouse_query' => $extracted['warehouse_name'] ?? $this->extractWarehouseFromText($companionText),
                'date' => $extracted['expected_date'] ?? null,
                'note' => $extracted['notes'] ?? null,
                'items' => [],
            ];

            foreach ((array) ($extracted['items'] ?? []) as $idx => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = [
                    'client_line_id' => 'line-' . $idx,
                    'product_query' => $item['product_name'] ?? null,
                    'qty' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null,
                    'uom' => $item['uom'] ?? null,
                ];
                if (isset($item['unit_price']) && is_numeric($item['unit_price'])) {
                    $row[$isSales ? 'net_unit_price' : 'net_unit_cost'] = (float) $item['unit_price'];
                }
                $slots['items'][] = $row;
            }

            return array_filter($slots, static fn ($v) => $v !== null);
        }

        // Image / PDF extracted commercial fields -> draft workflow slots
        $party = $isSales
            ? ($extracted['buyer_name'] ?? $extracted['supplier_name'] ?? null)
            : ($extracted['supplier_name'] ?? null);

        $warehouseQuery = $this->extractWarehouseFromText($companionText);

        $slots = [
            $isSales ? 'customer_query' : 'supplier_query' => $party,
            'warehouse_query' => $warehouseQuery,
            'date' => $extracted['invoice_date'] ?? null,
            'note' => isset($extracted['invoice_number']) ? 'Source Ref: ' . $extracted['invoice_number'] : null,
            'items' => [],
        ];

        foreach ((array) ($extracted['lines'] ?? []) as $idx => $line) {
            if (! is_array($line)) {
                continue;
            }
            $row = [
                'client_line_id' => 'line-' . $idx,
                'product_query' => $line['description'] ?? null,
                'qty' => is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : null,
                'uom' => $line['uom'] ?? null,
            ];
            if (isset($line['unit_price']) && is_numeric($line['unit_price'])) {
                $row[$isSales ? 'net_unit_price' : 'net_unit_cost'] = (float) $line['unit_price'];
            }
            if (isset($line['tax_rate']) && is_numeric($line['tax_rate'])) {
                $row['tax_rate'] = (float) $line['tax_rate'];
            }
            if (! empty($line['sku_or_hsn'])) {
                $row['note'] = 'Ref/HSN: ' . $line['sku_or_hsn'];
            }
            $slots['items'][] = $row;
        }

        return array_filter($slots, static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $extracted
     * @param array<string, mixed> $slots
     * @return array<string, string>
     */
    private function buildFieldSources(
        AiMediaAsset $asset,
        array $extracted,
        array $slots,
        ?string $companionText,
    ): array {
        $sources = [];
        $baseSource = $asset->source_kind === 'audio' ? 'audio_transcript_unverified' : 'document_ocr_unverified';

        foreach ($slots as $key => $val) {
            if ($key === 'warehouse_query' && $companionText !== null && $this->extractWarehouseFromText($companionText) !== null) {
                $sources[$key] = 'user_companion_text';
                continue;
            }
            if ($key === 'items' && is_array($val)) {
                $rows = $asset->source_kind === 'audio' ? ($extracted['items'] ?? []) : ($extracted['lines'] ?? []);
                $anyConfirmed = false;
                foreach ($rows as $r) {
                    if (is_array($r) && ! empty($r['user_confirmed'])) {
                        $anyConfirmed = true;
                        break;
                    }
                }
                $sources[$key] = $anyConfirmed ? 'user_confirmed_extraction' : $baseSource;
                continue;
            }
            $sources[$key] = $baseSource;
        }

        return $sources;
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     * @param array<int, array<string, mixed>> $conflicts
     * @param array<int, string> $warnings
     */
    private function detectCrossModalConflicts(
        array $primary,
        array $secondary,
        array &$conflicts,
        array &$warnings,
    ): void {
        $primaryParty = strtolower(trim((string) ($primary['supplier_name'] ?? $primary['party_name'] ?? '')));
        $secondaryParty = strtolower(trim((string) ($secondary['party_name'] ?? $secondary['supplier_name'] ?? '')));

        if ($primaryParty !== '' && $secondaryParty !== '' && $primaryParty !== $secondaryParty && ! str_contains($primaryParty, $secondaryParty) && ! str_contains($secondaryParty, $primaryParty)) {
            $msg = "Cross-modal mismatch on party name: document says '{$primaryParty}' while voice recording says '{$secondaryParty}'.";
            $conflicts[] = ['code' => 'cross_modal_party_conflict', 'message' => $msg];
            $warnings[] = $msg;
        }
    }

    /**
     * @param array<string, mixed> $extracted
     * @param array<int, array<string, mixed>> $conflicts
     * @param array<int, string> $warnings
     */
    private function detectPostedImmutableConflict(
        AiPrincipal $principal,
        array $extracted,
        ?string $companionText,
        array &$conflicts,
        array &$warnings,
    ): void {
        $text = strtolower((string) $companionText);
        if ($text === '' || ! preg_match('/\b(edit|modify|update|alter|overwrite|delete|change)\b/', $text)) {
            return;
        }

        $invoiceNo = $extracted['invoice_number'] ?? null;
        if (! is_string($invoiceNo) || trim($invoiceNo) === '') {
            return;
        }

        try {
            if (Schema::hasTable('purchases')) {
                $postedPurchase = DB::table('purchases')
                    ->where('company_id', $principal->companyId)
                    ->whereNull('deleted_at')
                    ->whereNotNull('posted_at')
                    ->where(function ($q) use ($invoiceNo): void {
                        $q->where('supplier_invoice_no', $invoiceNo)
                            ->orWhere('reference_no', $invoiceNo);
                    })
                    ->first(['id', 'reference_no']);

                if ($postedPurchase !== null) {
                    $msg = "Posted ERP voucher [{$postedPurchase->reference_no}] is immutable. Direct edits are blocked; create a Credit Note, Debit Note, or Reversal entry instead.";
                    $conflicts[] = ['code' => 'posted_document_immutable', 'message' => $msg];
                    $warnings[] = $msg;
                }
            }

            if (Schema::hasTable('sales')) {
                $postedSale = DB::table('sales')
                    ->where('company_id', $principal->companyId)
                    ->whereNull('deleted_at')
                    ->whereNotNull('posted_at')
                    ->where('reference_no', $invoiceNo)
                    ->first(['id', 'reference_no']);

                if ($postedSale !== null) {
                    $msg = "Posted ERP sales invoice [{$postedSale->reference_no}] is immutable. Direct edits are blocked; use Sales Return or Credit Note instead.";
                    $conflicts[] = ['code' => 'posted_document_immutable', 'message' => $msg];
                    $warnings[] = $msg;
                }
            }
        } catch (\Throwable) {
            // Safe when running without DB in isolated unit tests.
        }
    }

    private function extractWarehouseFromText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (preg_match('/\b(?:for|into|to|at|warehouse|branch)\s+([A-Za-z0-9][A-Za-z0-9\s\-]{1,40}?\bwarehouse\b|[A-Za-z0-9][A-Za-z0-9\s\-]{1,40}\b(?:hub|store|depot|godown|lab)\b)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function unimplementedCapabilityMessage(string $intentKey): string
    {
        return match ($intentKey) {
            'purchase_bill.create.v1' => 'Direct Purchase Bill posting from AI is not enabled yet. Review the extracted bill above, or explicitly ask to draft a Purchase Order (`purchase_order.create.v1`) instead.',
            'sales_invoice.create.v1' => 'Direct Sales Invoice posting from AI is not enabled yet. Review the extracted invoice above, or explicitly ask to draft a Sales Order (`sales_order.create.v1`) instead.',
            default => "Action capability [{$intentKey}] is not registered for AI execution. Only reviewed display is permitted.",
        };
    }
}

