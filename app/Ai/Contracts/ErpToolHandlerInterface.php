<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;

/**
 * Contract for authoritative ERP domain tools.
 */
interface ErpToolHandlerInterface
{
    /**
     * Unique tool identifier (e.g. 'receivables.outstanding.v1').
     */
    public function key(): string;

    /**
     * Concise human description for LLM system prompt.
     */
    public function description(): string;

    /**
     * Risk classification: 'read_only', 'draft', 'controlled_write'.
     */
    public function risk(): string;

    /**
     * JSON Schema array defining input validation rules.
     */
    public function schema(): array;

    /**
     * Array of required capability/permission strings.
     */
    public function requiredPermissions(): array;

    /**
     * Business authorization check prior to executing domain queries.
     */
    public function authorize(AiPrincipal $principal, array $input): bool;

    /**
     * Execute the deterministic ERP service query.
     */
    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult;
}

