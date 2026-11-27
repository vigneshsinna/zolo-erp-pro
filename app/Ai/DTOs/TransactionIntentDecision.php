<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Typed R01 transaction intent and source-role compatibility decision contract.
 * Converges text, voice, media, and workflow bridge routing on one authoritative structure.
 */
final readonly class TransactionIntentDecision
{
    /**
     * @param list<string> $allowedNextActions
     * @param list<string> $detectedActions
     * @param list<array<string, mixed>> $conflicts
     * @param list<string> $warnings
     */
    public function __construct(
        public ?string $requestedAction,
        public string $documentKind,
        public string $documentDirection,
        public string $companyRole,
        public ?string $activeTaskAction,
        public string $decisionStatus,
        public ?string $reasonCode,
        public ?string $message,
        public array $allowedNextActions = [],
        public ?int $sourceExtractionRevision = null,
        public array $detectedActions = [],
        public array $conflicts = [],
        public array $warnings = [],
        public bool $isResaleReferenceOnly = false,
    ) {}

    public function isResolvedForStaging(): bool
    {
        return $this->decisionStatus === 'ready'
            && $this->requestedAction !== null
            && $this->conflicts === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested_action' => $this->requestedAction,
            'document_kind' => $this->documentKind,
            'document_direction' => $this->documentDirection,
            'company_role' => $this->companyRole,
            'active_task_action' => $this->activeTaskAction,
            'decision_status' => $this->decisionStatus,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'allowed_next_actions' => $this->allowedNextActions,
            'source_extraction_revision' => $this->sourceExtractionRevision,
            'detected_actions' => $this->detectedActions,
            'is_resale_reference_only' => $this->isResaleReferenceOnly,
        ];
    }
}
