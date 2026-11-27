<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\TransactionIntentDecision;

/**
 * Shared deterministic transaction intent resolver across text, voice, and media companion text.
 *
 * Enforces explicit phrase precedence over generic terms, handles negation and multi-action
 * ambiguity, ignores read-only queries and false-positive product names, and evaluates
 * compatibility between requested action, source document direction, and active task.
 */
final class TransactionIntentResolver
{
    public const SUPPORTED_ACTIONS = [
        'purchase_order.create.v1',
        'purchase_bill.create.v1',
        'sales_order.create.v1',
        'sales_invoice.create.v1',
        'delivery_challan.create.v1',
        'quotation.create.v1',
    ];

    /**
     * Canonical aliases grouped by action key, ordered from specific to general.
     *
     * @return array<string, list<string>>
     */
    public function aliasesByAction(): array
    {
        return [
            'purchase_order.create.v1' => [
                'purchase order',
                'purchase orders',
                'p.o.',
                'po',
            ],
            'sales_order.create.v1' => [
                'sales order',
                'sales orders',
                'sale order',
                's.o.',
                'so',
            ],
            'purchase_bill.create.v1' => [
                'purchase bill',
                'purchase bills',
                'purchase invoice',
                'purchase invoices',
                'vendor bill',
                'vendor bills',
                'vendor invoice',
                'vendor invoices',
                'supplier bill',
                'supplier bills',
                'supplier invoice',
                'supplier invoices',
            ],
            'sales_invoice.create.v1' => [
                'sales bill',
                'sales bills',
                'sale bill',
                'sale bills',
                'sales invoice',
                'sales invoices',
                'sale invoice',
                'sale invoices',
                'customer invoice',
                'customer invoices',
                'customer bill',
                'dealer invoice',
                'dealer bill',
            ],
            'delivery_challan.create.v1' => [
                'delivery challan',
                'dispatch challan',
            ],
            'quotation.create.v1' => [
                'quotation',
                'price quote',
                'estimate',
            ],
        ];
    }

    /**
     * Resolve an explicit requested action from natural text without invoking any LLM.
     * Returns null for ambiguous/neutral/read-only/conflicting/negated-only inputs.
     */
    public function resolveExplicitAction(string $text): ?string
    {
        $analysis = $this->analyzeText($text);
        if ($analysis['status'] !== 'resolved') {
            return null;
        }

        return $analysis['action'];
    }

    /**
     * Detailed deterministic text analysis including negation, neutral terms, and multi-action conflicts.
     *
     * @return array{
     *     status: 'none'|'readonly'|'resolved'|'ambiguous_neutral'|'multiple_conflicting'|'negated_ambiguous',
     *     action: ?string,
     *     detected_actions: list<string>,
     *     neutral_kind: ?string,
     *     negated_actions: list<string>,
     *     reason_code: ?string,
     *     message: ?string
     * }
     */
    public function analyzeText(string $text): array
    {
        $clean = mb_strtolower(trim($text));
        if ($clean === '') {
            return $this->emptyAnalysis('none');
        }

        // Pure setup conversations are handled by their dedicated setup flows.
        if (app(SupplierSetupConversation::class)->start($clean) !== null
            || app(CustomerSetupConversation::class)->start($clean) !== null) {
            return $this->emptyAnalysis('none');
        }

        // Read-only analytical / lookup phrasing must never be promoted into a write draft.
        if ($this->isReadOnlyQuery($clean)) {
            return $this->emptyAnalysis('readonly');
        }

        $actionVerb = (bool) preg_match(
            '/\b(?:create|make|prepare|draft|raise|convert|book|enter|post|generate|issue|start|place|need|want|record|bill|sell)\b/iu',
            $clean
        );
        $barePhrase = (bool) preg_match(
            '/^(?:a |an |the )?(?:purchase\s+orders?|p\.?\s*o\.?|purchase\s+bills?|purchase\s+invoices?|vendor\s+bills?|vendor\s+invoices?|supplier\s+bills?|supplier\s+invoices?|sales\s+orders?|s\.?\s*o\.?|sales\s+bills?|sale\s+bills?|sales\s+invoices?|sale\s+invoices?|customer\s+invoices?|dealer\s+invoices?|delivery\s+challans?|quotations?)\s*[.!?]*$/iu',
            $clean
        );

        // Detect negated clauses first so "not purchase, create sales bill" removes negated purchase intent.
        $negatedActions = [];
        $positiveText = $clean;
        $negationPatterns = [
            '/\b(?:not|no|don\'t|do\s+not|never|instead\s+of|rather\s+than)\s+(?:a\s+|an\s+|the\s+|creating\s+|making\s+|preparing\s+|booking\s+|posting\s+)?(purchase\s+bills?|purchase\s+invoices?|vendor\s+bills?|supplier\s+invoices?|purchase\s+orders?|sales\s+bills?|sale\s+bills?|sales\s+invoices?|sales\s+orders?|purchase|sales|sale)\b/iu',
        ];
        foreach ($negationPatterns as $negPattern) {
            if (preg_match_all($negPattern, $positiveText, $negMatches, PREG_SET_ORDER)) {
                foreach ($negMatches as $match) {
                    $negPhrase = mb_strtolower(trim($match[1]));
                    foreach ($this->matchActionsInClause($negPhrase, includeBareSide: true) as $negAct) {
                        $negatedActions[] = $negAct;
                    }
                    $positiveText = str_replace($match[0], ' ', $positiveText);
                }
            }
        }
        $negatedActions = array_values(array_unique($negatedActions));

        // Remove product/item references like "for Billabong frame" or "product Bill Ledger" so
        // product names containing "bill" or "order" are not mistaken for transaction types.
        $sanitizedForFalsePositives = $this->stripProductFalsePositives($positiveText);

        $positiveActions = ($actionVerb || $barePhrase)
            ? $this->matchActionsInClause($sanitizedForFalsePositives, includeBareSide: false)
            : [];

        // If user explicitly negated an action and left an explicit positive action, filter out negated only if
        // positive text didn't independently re-request it.
        if ($negatedActions !== [] && $positiveActions === []) {
            return [
                'status' => 'negated_ambiguous',
                'action' => null,
                'detected_actions' => [],
                'neutral_kind' => null,
                'negated_actions' => $negatedActions,
                'reason_code' => 'NEGATED_INTENT_AMBIGUOUS',
                'message' => 'Please clarify which transaction you want to create.',
            ];
        }

        if (count($positiveActions) > 1) {
            return [
                'status' => 'multiple_conflicting',
                'action' => null,
                'detected_actions' => $positiveActions,
                'neutral_kind' => null,
                'negated_actions' => $negatedActions,
                'reason_code' => 'MULTIPLE_CONFLICTING_ACTIONS',
                'message' => 'Your request mentions multiple transaction types. Confirm whether you want a sales or purchase transaction, or start them separately.',
            ];
        }

        if (count($positiveActions) === 1) {
            return [
                'status' => 'resolved',
                'action' => $positiveActions[0],
                'detected_actions' => $positiveActions,
                'neutral_kind' => null,
                'negated_actions' => $negatedActions,
                'reason_code' => null,
                'message' => null,
            ];
        }

        // Direction-neutral invoice/bill requests ("create a bill", "prepare tax invoice")
        if ($actionVerb && preg_match('/\b(tax\s+invoices?|invoices?|bills?)\b/iu', $sanitizedForFalsePositives, $neutralMatch)) {
            $neutralKind = str_contains($neutralMatch[1], 'tax') ? 'tax_invoice' : 'bill';
            return [
                'status' => 'ambiguous_neutral',
                'action' => null,
                'detected_actions' => [],
                'neutral_kind' => $neutralKind,
                'negated_actions' => $negatedActions,
                'reason_code' => 'DIRECTION_NEUTRAL_DOCUMENT_TERM',
                'message' => 'Specify whether this ' . ($neutralKind === 'tax_invoice' ? 'tax invoice' : 'bill') . ' is a Sales Invoice (outgoing to customer) or a Purchase Bill (incoming from supplier).',
            ];
        }

        return $this->emptyAnalysis('none');
    }

    /**
     * Evaluate full R01 transaction intent + source document compatibility + active task conflict.
     *
     * @param array<string, mixed> $extracted
     */
    public function decideForMedia(
        string $sourceKind,
        array $extracted,
        ?string $companionText,
        ?string $overrideActionKey,
        ?string $activeTaskAction = null,
        ?int $extractionRevision = null,
        bool $resaleReferenceConfirmed = false,
        array $allowedOverrideKeys = self::SUPPORTED_ACTIONS,
    ): TransactionIntentDecision {
        $docKind = (string) ($extracted['document_kind'] ?? $extracted['document_type'] ?? 'unknown');
        $companyRole = (string) ($extracted['erp_checks']['company_role'] ?? 'unknown');
        $docDirection = (string) ($extracted['erp_checks']['document_direction'] ?? 'unknown');

        // Fallback compatibility for extractions produced before company_role was populated.
        if ($companyRole === 'unknown' && $docDirection === 'unknown') {
            $legacyType = (string) ($extracted['document_type'] ?? 'unknown');
            if (in_array($legacyType, ['purchase_bill', 'supplier_invoice'], true)) {
                $docDirection = 'incoming';
            } elseif ($legacyType === 'sales_invoice') {
                $docDirection = 'outgoing';
            }
        }

        // Audio handling: compare spoken transcript and typed companion text strictly.
        if ($sourceKind === 'audio') {
            $spokenAnalysis = $this->analyzeText((string) ($extracted['transcript_text'] ?? ''));
            $typedAnalysis = $this->analyzeText((string) ($companionText ?? ''));

            if ($spokenAnalysis['status'] === 'multiple_conflicting' || $typedAnalysis['status'] === 'multiple_conflicting') {
                return new TransactionIntentDecision(
                    requestedAction: null,
                    documentKind: 'audio_transcript',
                    documentDirection: 'unknown',
                    companyRole: 'unknown',
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'needs_intent_confirmation',
                    reasonCode: 'MULTIPLE_CONFLICTING_ACTIONS',
                    message: 'Multiple conflicting transactions were mentioned. Confirm one intended transaction before continuing.',
                    allowedNextActions: ['choose_sales_invoice', 'choose_purchase_bill', 'choose_purchase_order', 'choose_sales_order'],
                    sourceExtractionRevision: $extractionRevision,
                    detectedActions: array_values(array_unique([...$spokenAnalysis['detected_actions'], ...$typedAnalysis['detected_actions']])),
                );
            }

            $spoken = $spokenAnalysis['action'];
            $typed = $typedAnalysis['action'];

            if ($spoken !== null && $typed !== null && $spoken !== $typed) {
                return new TransactionIntentDecision(
                    requestedAction: null,
                    documentKind: 'audio_transcript',
                    documentDirection: 'unknown',
                    companyRole: 'unknown',
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'needs_intent_confirmation',
                    reasonCode: 'AUDIO_TEXT_INTENT_CONFLICT',
                    message: 'Spoken audio and typed instruction request different transactions. Clarify which transaction to prepare.',
                    allowedNextActions: ['confirm_spoken_action', 'confirm_typed_action'],
                    sourceExtractionRevision: $extractionRevision,
                    detectedActions: [$spoken, $typed],
                    conflicts: [[
                        'code' => 'audio_text_intent_conflict',
                        'message' => 'Spoken audio and typed instruction conflict.',
                    ]],
                );
            }

            $resolved = $typed ?? $spoken;
            if ($overrideActionKey !== null && trim($overrideActionKey) !== '') {
                $trimmedOverride = trim($overrideActionKey);
                if (! in_array($trimmedOverride, $allowedOverrideKeys, true)) {
                    return new TransactionIntentDecision(
                        requestedAction: null,
                        documentKind: 'audio_transcript',
                        documentDirection: 'unknown',
                        companyRole: 'unknown',
                        activeTaskAction: $activeTaskAction,
                        decisionStatus: 'blocked',
                        reasonCode: 'INVALID_ACTION_OVERRIDE',
                        message: 'Requested transaction override is not permitted.',
                        allowedNextActions: [],
                        sourceExtractionRevision: $extractionRevision,
                    );
                }
                if ($resolved !== null && $trimmedOverride !== $resolved) {
                    return new TransactionIntentDecision(
                        requestedAction: null,
                        documentKind: 'audio_transcript',
                        documentDirection: 'unknown',
                        companyRole: 'unknown',
                        activeTaskAction: $activeTaskAction,
                        decisionStatus: 'needs_intent_confirmation',
                        reasonCode: 'OVERRIDE_INTENT_CONFLICT',
                        message: 'Explicit transaction override conflicts with spoken or typed instruction.',
                        allowedNextActions: ['confirm_intent'],
                        sourceExtractionRevision: $extractionRevision,
                    );
                }
                $resolved = $trimmedOverride;
            }

            if ($resolved === null) {
                return new TransactionIntentDecision(
                    requestedAction: null,
                    documentKind: 'audio_transcript',
                    documentDirection: 'unknown',
                    companyRole: 'unknown',
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'needs_intent_confirmation',
                    reasonCode: $spokenAnalysis['reason_code'] ?? $typedAnalysis['reason_code'] ?? 'AMBIGUOUS_INTENT',
                    message: $typedAnalysis['message'] ?? $spokenAnalysis['message'] ?? 'Specify the intended transaction before creating any draft.',
                    allowedNextActions: ['choose_sales_invoice', 'choose_purchase_bill', 'choose_purchase_order', 'choose_sales_order'],
                    sourceExtractionRevision: $extractionRevision,
                );
            }

            if ($activeTaskAction !== null && $activeTaskAction !== $resolved) {
                return new TransactionIntentDecision(
                    requestedAction: $resolved,
                    documentKind: 'audio_transcript',
                    documentDirection: 'unknown',
                    companyRole: 'unknown',
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'active_task_conflict',
                    reasonCode: 'ACTIVE_TASK_ACTION_CONFLICT',
                    message: "An active [{$activeTaskAction}] draft exists. Choose whether to start a new [{$resolved}] task or resume the current draft.",
                    allowedNextActions: ['start_new_task', 'resume_active_task'],
                    sourceExtractionRevision: $extractionRevision,
                );
            }

            return new TransactionIntentDecision(
                requestedAction: $resolved,
                documentKind: 'audio_transcript',
                documentDirection: 'unknown',
                companyRole: 'unknown',
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'ready',
                reasonCode: null,
                message: null,
                allowedNextActions: ['stage_draft'],
                sourceExtractionRevision: $extractionRevision,
                detectedActions: [$resolved],
            );
        }

        // Visual document (image / PDF) handling:
        $textAnalysis = $this->analyzeText((string) ($companionText ?? ''));

        if ($overrideActionKey !== null && trim($overrideActionKey) !== '') {
            $trimmedOverride = trim($overrideActionKey);
            if (! in_array($trimmedOverride, $allowedOverrideKeys, true)) {
                return new TransactionIntentDecision(
                    requestedAction: null,
                    documentKind: $docKind,
                    documentDirection: $docDirection,
                    companyRole: $companyRole,
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'blocked',
                    reasonCode: 'INVALID_ACTION_OVERRIDE',
                    message: 'Requested transaction override is not in the authorized capability allowlist.',
                    allowedNextActions: [],
                    sourceExtractionRevision: $extractionRevision,
                    conflicts: [[
                        'code' => 'invalid_action_override',
                        'message' => 'Requested transaction override is not permitted.',
                    ]],
                );
            }
            $requestedAction = $trimmedOverride;
            $explicitUserRequest = true;
        } elseif ($textAnalysis['status'] === 'multiple_conflicting' || $textAnalysis['status'] === 'negated_ambiguous') {
            return new TransactionIntentDecision(
                requestedAction: null,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'needs_intent_confirmation',
                reasonCode: $textAnalysis['reason_code'],
                message: $textAnalysis['message'],
                allowedNextActions: ['choose_sales_invoice', 'choose_purchase_bill', 'choose_purchase_order', 'choose_sales_order', 'review_source'],
                sourceExtractionRevision: $extractionRevision,
                detectedActions: $textAnalysis['detected_actions'],
            );
        } elseif ($textAnalysis['status'] === 'resolved') {
            $requestedAction = $textAnalysis['action'];
            $explicitUserRequest = true;
        } elseif ($textAnalysis['status'] === 'ambiguous_neutral') {
            // Direction-neutral prompt like "tax invoice from image" or "create a bill"
            if ($docDirection === 'incoming' && in_array($companyRole, ['own_buyer'], true)) {
                $requestedAction = 'purchase_bill.create.v1';
                $explicitUserRequest = false;
            } elseif ($docDirection === 'outgoing' && in_array($companyRole, ['own_issuer'], true)) {
                $requestedAction = 'sales_invoice.create.v1';
                $explicitUserRequest = false;
            } else {
                return new TransactionIntentDecision(
                    requestedAction: null,
                    documentKind: $docKind,
                    documentDirection: $docDirection,
                    companyRole: $companyRole,
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'needs_source_confirmation',
                    reasonCode: 'DOCUMENT_PARTY_ROLE_UNVERIFIED',
                    message: 'Could not verify whether this document is a Purchase Bill (incoming from supplier) or Sales Invoice (outgoing to customer). Confirm the transaction type and party roles.',
                    allowedNextActions: ['confirm_source_roles', 'choose_purchase_bill', 'choose_sales_invoice', 'review_source'],
                    sourceExtractionRevision: $extractionRevision,
                );
            }
        } else {
            // No companion text directing the action: derive cautiously from document metadata.
            $explicitUserRequest = false;
            $requestedAction = match ((string) ($extracted['document_type'] ?? 'unknown')) {
                'purchase_order' => 'purchase_order.create.v1',
                'sales_order' => 'sales_order.create.v1',
                'purchase_bill', 'supplier_invoice' => 'purchase_bill.create.v1',
                'sales_invoice' => 'sales_invoice.create.v1',
                default => null,
            };

            if ($requestedAction === null && in_array($docKind, ['rx_sheet', 'lab_slip', 'quotation'], true)) {
                return new TransactionIntentDecision(
                    requestedAction: null,
                    documentKind: $docKind,
                    documentDirection: $docDirection,
                    companyRole: $companyRole,
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'needs_intent_confirmation',
                    reasonCode: 'SOURCE_NOT_POSTABLE_INVOICE',
                    message: "This document is classified as [{$docKind}]. Choose the intended order or quote workflow and provide missing commercial terms.",
                    allowedNextActions: ['choose_sales_order', 'choose_sales_invoice', 'choose_quotation', 'review_source'],
                    sourceExtractionRevision: $extractionRevision,
                );
            }
        }

        if ($requestedAction === null) {
            return new TransactionIntentDecision(
                requestedAction: null,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'needs_intent_confirmation',
                reasonCode: 'UNRESOLVED_DOCUMENT_INTENT',
                message: 'Confirm which ERP transaction to prepare from this document.',
                allowedNextActions: ['choose_sales_invoice', 'choose_purchase_bill', 'choose_purchase_order', 'choose_sales_order', 'review_source'],
                sourceExtractionRevision: $extractionRevision,
            );
        }

        // Active task conflict check
        if ($activeTaskAction !== null && $activeTaskAction !== $requestedAction) {
            return new TransactionIntentDecision(
                requestedAction: $requestedAction,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'active_task_conflict',
                reasonCode: 'ACTIVE_TASK_ACTION_CONFLICT',
                message: "An active [{$activeTaskAction}] draft is preserved. Confirm whether to start a new [{$requestedAction}] draft or continue the existing one.",
                allowedNextActions: ['start_new_task', 'resume_active_task'],
                sourceExtractionRevision: $extractionRevision,
            );
        }

        // Source-role compatibility check: never silently flip user's requested action!
        $isSalesAction = in_array($requestedAction, ['sales_invoice.create.v1', 'sales_order.create.v1', 'delivery_challan.create.v1'], true);
        $isPurchaseAction = in_array($requestedAction, ['purchase_bill.create.v1', 'purchase_order.create.v1'], true);

        if ($isSalesAction && $docDirection === 'incoming' && $companyRole === 'own_buyer') {
            if ($resaleReferenceConfirmed && $requestedAction === 'sales_invoice.create.v1') {
                return new TransactionIntentDecision(
                    requestedAction: $requestedAction,
                    documentKind: $docKind,
                    documentDirection: $docDirection,
                    companyRole: $companyRole,
                    activeTaskAction: $activeTaskAction,
                    decisionStatus: 'ready',
                    reasonCode: null,
                    message: 'Using incoming supplier document as reference-only source for a new sales invoice draft. Confirm customer, selling rates, and dispatch warehouse.',
                    allowedNextActions: ['stage_draft'],
                    sourceExtractionRevision: $extractionRevision,
                    detectedActions: [$requestedAction],
                    isResaleReferenceOnly: true,
                );
            }

            $msg = 'You requested a sales transaction, but this document appears to be an incoming supplier invoice billed to your company. Do you want to record the purchase instead, or use it as a reference for a new sale?';
            return new TransactionIntentDecision(
                requestedAction: $requestedAction,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'source_role_conflict',
                reasonCode: 'SALES_REQUEST_ON_INCOMING_SUPPLIER_INVOICE',
                message: $msg,
                allowedNextActions: ['record_purchase_instead', 'use_as_resale_reference', 'review_source'],
                sourceExtractionRevision: $extractionRevision,
                detectedActions: [$requestedAction],
                conflicts: [[
                    'code' => 'source_role_conflict',
                    'message' => $msg,
                ]],
            );
        }

        if ($isPurchaseAction && $docDirection === 'outgoing' && $companyRole === 'own_issuer') {
            $msg = 'You requested a purchase transaction, but this document is issued by your own company as an outgoing sales invoice.';
            return new TransactionIntentDecision(
                requestedAction: $requestedAction,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'source_role_conflict',
                reasonCode: 'PURCHASE_REQUEST_ON_OUTGOING_SALES_INVOICE',
                message: $msg,
                allowedNextActions: ['record_sales_instead', 'review_source'],
                sourceExtractionRevision: $extractionRevision,
                detectedActions: [$requestedAction],
                conflicts: [[
                    'code' => 'source_role_conflict',
                    'message' => $msg,
                ]],
            );
        }

        if ($companyRole === 'both_ambiguous') {
            $msg = 'Both issuer and buyer match your active company registration. Confirm party roles before staging any voucher.';
            return new TransactionIntentDecision(
                requestedAction: $requestedAction,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'needs_source_confirmation',
                reasonCode: 'BOTH_PARTIES_MATCH_ACTIVE_COMPANY',
                message: $msg,
                allowedNextActions: ['confirm_source_roles', 'review_source'],
                sourceExtractionRevision: $extractionRevision,
                conflicts: [[
                    'code' => 'both_parties_match_company',
                    'message' => $msg,
                ]],
            );
        }

        // Foreign company mismatch: neither party matches active company when parties are present
        if ($companyRole === 'neither_foreign' && ! ($resaleReferenceConfirmed && $requestedAction === 'sales_invoice.create.v1')) {
            $msg = 'Company Mismatch: Document parties do not match your active company workspace. Direct transaction staging is blocked unless used explicitly as a resale reference.';
            return new TransactionIntentDecision(
                requestedAction: $requestedAction,
                documentKind: $docKind,
                documentDirection: $docDirection,
                companyRole: $companyRole,
                activeTaskAction: $activeTaskAction,
                decisionStatus: 'source_role_conflict',
                reasonCode: 'DOCUMENT_FOREIGN_COMPANY_MISMATCH',
                message: $msg,
                allowedNextActions: $isSalesAction
                    ? ['use_as_resale_reference', 'review_source']
                    : ['review_source'],
                sourceExtractionRevision: $extractionRevision,
                conflicts: [[
                    'code' => 'company_mismatch',
                    'message' => $msg,
                ]],
            );
        }

        return new TransactionIntentDecision(
            requestedAction: $requestedAction,
            documentKind: $docKind,
            documentDirection: $docDirection,
            companyRole: $companyRole,
            activeTaskAction: $activeTaskAction,
            decisionStatus: 'ready',
            reasonCode: null,
            message: null,
            allowedNextActions: ['stage_draft'],
            sourceExtractionRevision: $extractionRevision,
            detectedActions: [$requestedAction],
            isResaleReferenceOnly: $resaleReferenceConfirmed && $explicitUserRequest && $requestedAction === 'sales_invoice.create.v1',
        );
    }

    public function isReadOnlyQuery(string $text): bool
    {
        if (preg_match('/^(?:show|list|find|search|check|how\s+many|what\s+is|what\s+are|which|who|get|view|fetch|summarize|explain)\b/iu', $text)
            && ! preg_match('/\b(?:create|make|prepare|draft|raise|convert|book|enter|post|generate|issue)\b/iu', $text)) {
            return true;
        }

        if (preg_match('/\b(?:outstanding|receivables|payables|stock|balance|report|trial\s+balance|profit\s+and\s+loss|gst\s+summary)\b/iu', $text)
            && ! preg_match('/\b(?:create|make|prepare|draft|raise|convert|book|enter|post|generate|issue|order|bill|invoice)\b/iu', $text)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function matchActionsInClause(string $text, bool $includeBareSide): array
    {
        $matched = [];

        if (preg_match('/\b(?:purchase\s+orders?|p\.?\s*o\.?)\b/iu', $text)) {
            $matched[] = 'purchase_order.create.v1';
        }
        if (preg_match('/\b(?:sales\s+orders?|sale\s+orders?|s\.?\s*o\.?)\b/iu', $text)) {
            $matched[] = 'sales_order.create.v1';
        }
        if (preg_match('/\b(?:purchase\s+bills?|purchase\s+invoices?|vendor\s+bills?|vendor\s+invoices?|supplier\s+bills?|supplier\s+invoices?)\b/iu', $text)) {
            $matched[] = 'purchase_bill.create.v1';
        }
        if (preg_match('/\b(?:sales\s+bills?|sale\s+bills?|sales\s+invoices?|sale\s+invoices?|customer\s+invoices?|customer\s+bills?|dealer\s+invoices?|dealer\s+bills?)\b/iu', $text)) {
            $matched[] = 'sales_invoice.create.v1';
        }
        if (preg_match('/\b(?:delivery\s+challans?|dispatch\s+challans?)\b/iu', $text)) {
            $matched[] = 'delivery_challan.create.v1';
        }
        if (preg_match('/\b(?:quotations?|price\s+quotes?)\b/iu', $text)) {
            $matched[] = 'quotation.create.v1';
        }

        // Combined shorthand like "create sales and purchase bills"
        if (preg_match('/\b(?:sales?|customer|dealer)\s+and\s+(?:purchase|vendor|supplier)\s+(?:bills?|invoices?)\b/iu', $text)
            || preg_match('/\b(?:purchase|vendor|supplier)\s+and\s+(?:sales?|customer|dealer)\s+(?:bills?|invoices?)\b/iu', $text)) {
            $matched[] = 'sales_invoice.create.v1';
            $matched[] = 'purchase_bill.create.v1';
        }

        // Bare side words are only interpreted inside negated fragments (e.g. "not purchase, create sales bill").
        if ($includeBareSide) {
            if (preg_match('/\b(?:purchase|vendor|supplier)\b/iu', $text)) {
                $matched[] = 'purchase_bill.create.v1';
            }
            if (preg_match('/\b(?:sales|sale|customer|dealer)\b/iu', $text)) {
                $matched[] = 'sales_invoice.create.v1';
            }
        }

        return array_values(array_unique($matched));
    }

    private function stripProductFalsePositives(string $text): string
    {
        // Strip quoted strings that may contain product names like "Billabong" or "Bill Ledger"
        $stripped = preg_replace('/"[^"]*"|\'[^\']*\'/u', ' ', $text) ?? $text;

        // Strip trailing product descriptor phrases after "for <qty> <uom> of ..." or "item/product <name>"
        $stripped = preg_replace('/\b(?:product|item|sku)\s+[a-z0-9\-_]+\b/iu', ' ', $stripped) ?? $stripped;

        return $stripped;
    }

    /**
     * @return array{
     *     status: 'none'|'readonly',
     *     action: null,
     *     detected_actions: list<string>,
     *     neutral_kind: null,
     *     negated_actions: list<string>,
     *     reason_code: null,
     *     message: null
     * }
     */
    private function emptyAnalysis(string $status): array
    {
        return [
            'status' => $status,
            'action' => null,
            'detected_actions' => [],
            'neutral_kind' => null,
            'negated_actions' => [],
            'reason_code' => null,
            'message' => null,
        ];
    }
}
