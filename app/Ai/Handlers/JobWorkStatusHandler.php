<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadJobWorkStatus;

/**
 * Authoritative handler for 'jobwork.status.v1'.
 */
class JobWorkStatusHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadJobWorkStatus $readFacade
    ) {}

    public function key(): string
    {
        return 'jobwork.status.v1';
    }

    public function description(): string
    {
        return 'Check Job Work and external processing status, pending material lying with processors, and return deadlines.';
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
                'job_worker_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional processor / supplier party ID to filter job work for.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['all', 'ok', 'due_soon', 'overdue'],
                    'default' => 'all',
                    'description' => 'Status filter: all, ok, due_soon (<=60 days), overdue.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 20,
                    'description' => 'Maximum number of job work records to return (default 20).',
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
        $workerId = isset($validatedInput['job_worker_id']) ? (int) $validatedInput['job_worker_id'] : null;
        $status = $validatedInput['status'] ?? 'all';
        $limit = isset($validatedInput['limit']) ? (int) $validatedInput['limit'] : 20;

        return $this->readFacade->execute($principal, $workerId, $status, $limit);
    }
}

