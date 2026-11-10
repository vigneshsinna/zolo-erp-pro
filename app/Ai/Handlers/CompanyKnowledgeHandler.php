<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadCompanyKnowledge;

/**
 * Authoritative handler for 'knowledge.query.v1'.
 */
class CompanyKnowledgeHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadCompanyKnowledge $readFacade
    ) {}

    public function key(): string
    {
        return 'knowledge.query.v1';
    }

    public function description(): string
    {
        return 'Query company terms, credit policies, return rules, and statutory compliance settings.';
    }

    public function risk(): string
    {
        return 'read_only';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'enum' => ['company_profile', 'payment_terms', 'credit_policy', 'tax_policy', 'return_policy', 'all'],
                    'default' => 'all',
                    'description' => 'Topic to look up: company_profile, payment_terms, credit_policy, tax_policy, return_policy, all.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional specific query term.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function requiredPermissions(): array
    {
        return ['ai.use'];
    }

    public function authorize(AiPrincipal $principal, array $input): bool
    {
        return $principal->isActive && $principal->hasTool($this->key());
    }

    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult
    {
        $topic = $validatedInput['topic'] ?? 'all';
        $query = $validatedInput['query'] ?? null;

        return $this->readFacade->execute($principal, $topic, $query);
    }
}

