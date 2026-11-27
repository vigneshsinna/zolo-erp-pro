<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use App\Ai\Conversation\TransactionIntentResolver;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\DTOs\NormalizedInputV1;
use App\Ai\DTOs\TransactionIntentDecision;
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
    ) {
    }

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
        ?string $activeTaskAction = null,
        bool $resaleReferenceConfirmed = false,
        bool $coverageConfirmed = false,
    ): NormalizedInputV1 {
        $warnings = array_values((array) ($extracted['warnings'] ?? []));
        $conflicts = [];

        $allowedOverrides = array_values(array_filter(
            TransactionIntentResolver::SUPPORTED_ACTIONS,
            fn(string $key): bool => $this->capabilityRegistry->has($key)
                && ($principal->permittedToolNames === [] || $principal->hasTool($key))
        ));

        $decision = app(TransactionIntentResolver::class)->decideForMedia(
            sourceKind: (string) $asset->source_kind,
            extracted: $extracted,
            companionText: $companionText,
            overrideActionKey: $overrideIntentKey,
            activeTaskAction: $activeTaskAction,
            extractionRevision: $extractionRevision,
            resaleReferenceConfirmed: $resaleReferenceConfirmed,
            allowedOverrideKeys: $allowedOverrides !== [] ? $allowedOverrides : TransactionIntentResolver::SUPPORTED_ACTIONS,
        );

        $intentKey = $decision->requestedAction;
        foreach ($decision->conflicts as $decisionConflict) {
            $conflicts[] = $decisionConflict;
        }
        if ($decision->message !== null && $decision->decisionStatus !== 'ready') {
            $warnings[] = $decision->message;
        }

        $slots = $this->mapSlots($asset, $intentKey, $extracted, $companionText, $decision);
        $fieldSources = $this->buildFieldSources($asset, $extracted, $slots, $companionText, $decision);

        if ($companionExtracted !== null) {
            $this->detectCrossModalConflicts($extracted, $companionExtracted, $conflicts, $warnings);
        }

        $this->detectPostedImmutableConflict($principal, $extracted, $companionText, $conflicts, $warnings);

        if (isset($extracted['coverage']['complete']) && $extracted['coverage']['complete'] === false && !$coverageConfirmed && $asset->state !== 'confirmed') {
            $processed = (int) ($extracted['coverage']['pages_processed'] ?? 0);
            $expected = (int) ($extracted['coverage']['pages_expected'] ?? 1);
            $missing = implode(', ', (array) ($extracted['coverage']['missing_pages'] ?? []));
            $msg = "Incomplete document coverage ({$processed} of {$expected} pages extracted; missing page(s): {$missing}). Complete extraction or manually confirm reviewed facts before staging.";
            $conflicts[] = [
                'code' => 'incomplete_document_coverage',
                'message' => $msg,
                'pages_expected' => $expected,
                'pages_processed' => $processed,
                'missing_pages' => (array) ($extracted['coverage']['missing_pages'] ?? []),
            ];
            $warnings[] = $msg;
        }

        if (
            (bool) ($extracted['erp_checks']['company_mismatch'] ?? false)
            && !$decision->isResaleReferenceOnly
            && !collect($conflicts)->contains('code', 'company_mismatch')
        ) {
            $msg = 'Company Mismatch: Document parties do not match active company workspace. Direct transaction staging is blocked.';
            $conflicts[] = [
                'code' => 'company_mismatch',
                'message' => $msg,
            ];
            $warnings[] = $msg;
        }

        if (
            $intentKey === 'sales_invoice.create.v1'
            && !$decision->isResaleReferenceOnly
            && !empty($extracted['erp_checks']['duplicate_sale']['is_posted'])
        ) {
            $dupRef = (string) ($extracted['erp_checks']['duplicate_sale']['reference_no'] ?? '');
            $msg = "Source sales invoice [{$dupRef}] is already posted. Review the existing invoice or create a Sales Return / Credit Note instead of duplicating.";
            $conflicts[] = [
                'code' => 'already_posted_source',
                'message' => $msg,
                'existing_sale_id' => $extracted['erp_checks']['duplicate_sale']['id'] ?? null,
                'reference_no' => $dupRef,
            ];
            $warnings[] = $msg;
        }

        $capabilityReason = null;
        $isActionable = false;

        if ($decision->decisionStatus === 'ready' && $intentKey !== null && $intentKey !== 'query_readonly') {
            $allowDirectPurchaseBill = (bool) config('ai_multimodal.allow_direct_purchase_bill', false);
            $allowDirectSalesInvoice = (bool) config('ai_multimodal.allow_direct_sales_invoice', false);
            $disallowed = [];
            if (!$allowDirectPurchaseBill) {
                $disallowed[] = 'purchase_bill.create.v1';
            }
            if (!$allowDirectSalesInvoice) {
                $disallowed[] = 'sales_invoice.create.v1';
            }
            $disallowedDirectHandoff = in_array($intentKey, $disallowed, true);
            if ($disallowedDirectHandoff || !$this->capabilityRegistry->has($intentKey)) {
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
                'resale_reference_only' => $decision->isResaleReferenceOnly,
                'field_sources' => $fieldSources,
            ],
            conflicts: $conflicts,
            warnings: array_values(array_unique($warnings)),
            capabilityUnavailableReason: $capabilityReason,
            intentDecision: $decision->toArray(),
        );
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
        TransactionIntentDecision $decision,
    ): array {
        $isSales = in_array($intentKey, ['sales_order.create.v1', 'sales_invoice.create.v1', 'delivery_challan.create.v1', 'quotation.create.v1'], true);
        $invoiceNo = $extracted['supplier_invoice_no'] ?? $extracted['invoice_number'] ?? null;

        if ($asset->source_kind === 'audio') {
            $party = $extracted['party_name'] ?? null;
            $slots = [
                $isSales ? 'customer_query' : 'supplier_query' => $party,
                'warehouse_query' => $extracted['warehouse_name'] ?? $this->extractWarehouseFromText($companionText),
                'date' => $extracted['expected_date'] ?? null,
                'note' => $extracted['notes'] ?? null,
                'items' => [],
            ];
            if (!$isSales && $invoiceNo !== null && trim((string) $invoiceNo) !== '') {
                $slots['supplier_invoice_no'] = trim((string) $invoiceNo);
            }

            foreach ((array) ($extracted['items'] ?? []) as $idx => $item) {
                if (!is_array($item)) {
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

            return array_filter($slots, static fn($v) => $v !== null);
        }

        // Image / PDF extracted commercial fields -> draft workflow slots
        // R02: Never fall back from missing buyer to supplier as a customer.
        $buyerQuery = $extracted['buyer_name'] ?? $extracted['buyer_gstin'] ?? null;
        $party = $isSales
            ? ($decision->isResaleReferenceOnly ? null : $buyerQuery)
            : ($extracted['supplier_name'] ?? $extracted['supplier_gstin'] ?? null);

        $warehouseQuery = $this->extractWarehouseFromText($companionText) ?? ($extracted['warehouse_name'] ?? null);

        $slots = [
            $isSales ? 'customer_query' : 'supplier_query' => $party,
            'warehouse_query' => $warehouseQuery,
            'date' => $extracted['invoice_date'] ?? null,
            'note' => isset($extracted['invoice_number']) ? 'Source Ref: ' . $extracted['invoice_number'] : null,
            'items' => [],
        ];

        if (!$isSales && $invoiceNo !== null && trim((string) $invoiceNo) !== '') {
            $slots['supplier_invoice_no'] = trim((string) $invoiceNo);
        }

        $isOpticalProfile = (bool) ($extracted['optical_profile'] ?? false);
        foreach ((array) ($extracted['lines'] ?? []) as $idx => $line) {
            if (!is_array($line)) {
                continue;
            }
            $rawLineId = (string) ($line['client_line_id'] ?? '');
            $clientLineId = ($rawLineId !== '' && !preg_match('/^line_\d+$/', $rawLineId))
                ? $rawLineId
                : ('line-' . $idx);
            $desc = isset($line['description']) ? trim((string) $line['description']) : null;
            $productName = isset($line['product_name']) && trim((string) $line['product_name']) !== ''
                ? trim((string) $line['product_name'])
                : null;
            $isEyeOnlyDesc = $isOpticalProfile && $desc !== null && (bool) preg_match('/^(?:R|RE|OD|RIGHT|L|LE|OS|LEFT)$/iD', $desc);
            $productQuery = $productName ?? ($isEyeOnlyDesc ? null : ($desc !== '' ? $desc : null));

            $row = [
                'client_line_id' => $clientLineId,
                'product_query' => $productQuery,
                'qty' => is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : null,
                'uom' => $line['uom'] ?? null,
            ];
            if (!empty($line['row_role'])) {
                $row['row_role'] = (string) $line['row_role'];
            }
            if (!empty($line['supplier_sku'])) {
                $row['supplier_sku'] = (string) $line['supplier_sku'];
            }
            // R02: On reference-only resale from an incoming supplier bill, do not inherit vendor cost as selling price.
            if (!($isSales && $decision->isResaleReferenceOnly) && isset($line['unit_price']) && is_numeric($line['unit_price'])) {
                $row[$isSales ? 'net_unit_price' : 'net_unit_cost'] = (float) $line['unit_price'];
            }
            if (isset($line['tax_rate']) && is_numeric($line['tax_rate'])) {
                $row['tax_rate'] = (float) $line['tax_rate'];
            }
            if (!empty($line['hsn_code'])) {
                $row['hsn_code'] = (string) $line['hsn_code'];
                $row['note'] = 'Ref/HSN: ' . $line['hsn_code'];
            } elseif (!empty($line['sku_or_hsn'])) {
                $row['note'] = 'Ref/HSN: ' . $line['sku_or_hsn'];
                $row['hsn_code'] = (string) $line['sku_or_hsn'];
            }
            if ($isOpticalProfile && !empty($line['optical_spec']) && is_array($line['optical_spec'])) {
                $opt = $line['optical_spec'];
                $row['optical_spec'] = $opt;
                foreach (['lr', 'sph', 'cyl', 'axis', 'addition', 'dia'] as $optField) {
                    if (isset($opt[$optField]) && $opt[$optField] !== null && $opt[$optField] !== '') {
                        $row[$optField] = $opt[$optField];
                    }
                }
                if (isset($opt['base_curve']) && $opt['base_curve'] !== null && $opt['base_curve'] !== '') {
                    $row['base'] = $opt['base_curve'];
                }
            }
            $slots['items'][] = $row;
        }

        return array_filter($slots, static fn($v) => $v !== null);
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
        TransactionIntentDecision $decision,
    ): array {
        $sources = [];
        $baseSource = $asset->source_kind === 'audio' ? 'audio_transcript_unverified' : 'document_ocr_unverified';
        if ($decision->isResaleReferenceOnly) {
            $baseSource = 'document_reference_only';
        }

        foreach ($slots as $key => $val) {
            if ($key === 'warehouse_query' && $companionText !== null && $this->extractWarehouseFromText($companionText) !== null) {
                $sources[$key] = 'user_companion_text';
                continue;
            }
            if ($key === 'items' && is_array($val)) {
                $rows = $asset->source_kind === 'audio' ? ($extracted['items'] ?? []) : ($extracted['lines'] ?? []);
                $anyConfirmed = false;
                foreach ($rows as $rIdx => $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $cid = (string) ($val[$rIdx]['client_line_id'] ?? ($r['client_line_id'] ?? "line-{$rIdx}"));
                    $lineConfirmed = !empty($r['user_confirmed']);
                    $sources["items.{$cid}"] = $lineConfirmed ? 'user_confirmed_extraction' : $baseSource;
                    if ($lineConfirmed) {
                        $anyConfirmed = true;
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

        if ($primaryParty !== '' && $secondaryParty !== '' && $primaryParty !== $secondaryParty && !str_contains($primaryParty, $secondaryParty) && !str_contains($secondaryParty, $primaryParty)) {
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
        if ($text === '' || !preg_match('/\b(edit|modify|update|alter|overwrite|delete|change)\b/', $text)) {
            return;
        }

        $invoiceNo = $extracted['invoice_number'] ?? null;
        if (!is_string($invoiceNo) || trim($invoiceNo) === '') {
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

