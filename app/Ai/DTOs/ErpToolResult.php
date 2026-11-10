<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Immutable typed result returned by authoritative ERP tool handlers.
 */
final readonly class ErpToolResult implements \JsonSerializable
{
    /**
     * @param string $toolKey The registered identifier of the tool
     * @param string $status Execution status: 'ok', 'denied', 'error'
     * @param array $scope The verified tenant/branch/fiscal year scope
     * @param array $data The bounded fact payload returned by the domain service
     * @param array<string> $sourceRefs URLs or identifiers pointing to authoritative ERP reports/documents
     * @param array<string> $warnings Reconciliation flags or operational warnings
     * @param string|null $asOf The effective reporting date (YYYY-MM-DD)
     * @param bool $reconciled Whether ledger/balance figures reconciled with control accounts
     * @param int $rowCount Number of detail items in data
     * @param string|null $errorCode Machine-readable error taxonomy code
     * @param string|null $errorMessage Human-readable message
     */
    public ?string $message;

    public function __construct(
        public string $toolKey,
        public string $status,
        public array $scope,
        public array $data,
        public array $sourceRefs = [],
        public array $warnings = [],
        public ?string $asOf = null,
        public bool $reconciled = true,
        public int $rowCount = 0,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        ?string $message = null,
    ) {
        $this->message = $message ?? $this->errorMessage;
    }

    /**
     * Create a successful execution result.
     */
    public static function ok(
        string $toolKey,
        array $scope,
        array $data,
        array $sourceRefs = [],
        array $warnings = [],
        ?string $asOf = null,
        bool $reconciled = true,
        ?int $rowCount = null
    ): self {
        return new self(
            toolKey: $toolKey,
            status: 'ok',
            scope: $scope,
            data: $data,
            sourceRefs: $sourceRefs,
            warnings: $warnings,
            asOf: $asOf,
            reconciled: $reconciled,
            rowCount: $rowCount ?? count($data),
        );
    }

    /**
     * Create an authorization denial result.
     */
    public static function denied(
        string $toolKey,
        string $reason,
        string $errorCode = 'AI_TOOL_FORBIDDEN',
        array $scope = []
    ): self {
        return new self(
            toolKey: $toolKey,
            status: 'denied',
            scope: $scope,
            data: [],
            sourceRefs: [],
            warnings: [],
            errorCode: $errorCode,
            errorMessage: $reason,
            message: $reason,
        );
    }

    /**
     * Create an operational or domain error result.
     */
    public static function error(
        string $toolKey,
        string $message,
        string $errorCode = 'AI_DOMAIN_ERROR',
        array $scope = []
    ): self {
        return new self(
            toolKey: $toolKey,
            status: 'error',
            scope: $scope,
            data: [],
            sourceRefs: [],
            warnings: [],
            errorCode: $errorCode,
            errorMessage: $message,
            message: $message,
        );
    }

    /**
     * Array representation for JSON serialization and tool responses.
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'tool' => $this->toolKey,
            'scope' => $this->scope,
            'as_of' => $this->asOf,
            'data' => $this->data,
            'source_refs' => $this->sourceRefs,
            'warnings' => $this->warnings,
            'reconciled' => $this->reconciled,
            'row_count' => $this->rowCount,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'message' => $this->message,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

